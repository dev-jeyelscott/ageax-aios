<?php

namespace App\Services;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\WorkerLease;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;

class AgentRunRecorder
{
    private const int LiveOutputLimit = 120000;

    private const int MetadataItemLimit = 200;

    public function __construct(private AuditLogger $audit) {}

    public function start(Project $project, AgentRole $role, string $prompt, ?Task $task = null, ?TaskAttempt $attempt = null, ?WorkerLease $lease = null): AgentRun
    {
        $run = AgentRun::create([
            'project_id' => $project->id,
            'task_id' => $task?->id,
            'agent_worker_id' => $lease === null ? AgentWorker::query()->whereBelongsTo($project)->where('role', $role)->value('id') : $lease->workerId,
            'worker_instance_id' => $lease?->workerInstanceId,
            'worker_lease_id' => $lease?->leaseId,
            'role' => $role,
            'status' => AgentRunStatus::Running,
            'attempt_number' => $attempt?->number,
            'prompt_hash' => hash('sha256', $prompt),
            'started_at' => now(),
        ]);

        $this->audit->record('agent.execution_started', [
            'agent_run_id' => $run->id,
            'role' => $role->value,
            'attempt_number' => $attempt?->number,
            'prompt_hash' => $run->prompt_hash,
            'worker_instance_id' => $run->worker_instance_id,
            'worker_lease_id' => $run->worker_lease_id,
        ], $project, $task);

        return $run;
    }

    /** @param array{exit_code: int, output: string, error_output: string} $execution */
    public function complete(AgentRun $run, array $execution): AgentRun
    {
        $logPath = 'agent-runs/'.Str::uuid().'.jsonl';
        $output = $this->redactSensitiveOutput($execution['output']);
        $errorOutput = $this->redactSensitiveOutput($execution['error_output']);
        Storage::disk('local')->put($logPath, $output."\n".$errorOutput);
        $metadata = $this->metadata($output);
        $liveOutput = $run->fresh()->live_output;
        if (blank($liveOutput)) {
            $liveOutput = $this->boundedOutput($output."\n".$errorOutput);
        }

        $run->update([
            'status' => $execution['exit_code'] === 0 ? AgentRunStatus::Completed : AgentRunStatus::Failed,
            'exit_code' => $execution['exit_code'],
            'codex_run_id' => $metadata['codex_run_id'],
            'result' => $metadata['result'],
            'commands' => $metadata['commands'],
            'file_modifications' => $metadata['file_modifications'],
            'token_usage' => $metadata['token_usage'],
            'log_path' => $logPath,
            'live_output' => $liveOutput,
            'finished_at' => now(),
        ]);

        $completedRun = $run->refresh();
        $completedRun->loadMissing('project', 'task');
        $this->audit->record('agent.execution_completed', [
            'agent_run_id' => $completedRun->id,
            'role' => AgentRole::from($completedRun->getRawOriginal('role'))->value,
            'attempt_number' => $completedRun->attempt_number,
            'exit_code' => $completedRun->exit_code,
            'codex_run_id' => $completedRun->codex_run_id,
            'token_usage' => $completedRun->token_usage,
            'commands' => $completedRun->commands ?? [],
            'file_modifications' => $completedRun->file_modifications ?? [],
        ], $completedRun->project, $completedRun->task);

        return $completedRun;
    }

    public function appendLiveOutput(AgentRun $run, string $type, string $output): void
    {
        if ($output === '') {
            return;
        }

        $prefix = $type === 'stderr' ? '[stderr] ' : '';
        $liveOutput = $run->fresh()->live_output ?? '';
        $liveOutput .= $prefix.$this->redactSensitiveOutput($output);

        $run->update(['live_output' => $this->boundedOutput($liveOutput)]);
    }

    public function transcript(AgentRun $run): ?string
    {
        if (filled($run->live_output)) {
            return $this->redact($this->boundedOutput($run->live_output));
        }

        if (blank($run->log_path) || ! Storage::disk('local')->exists($run->log_path)) {
            return null;
        }

        return $this->redact($this->boundedOutput(Storage::disk('local')->get($run->log_path)));
    }

    /** @return list<string> */
    public function agentMessages(AgentRun $run): array
    {
        $output = $this->transcript($run);
        if ($output === null) {
            return [];
        }

        $messages = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $event = json_decode($line, true);
            $item = is_array($event) ? $event['item'] ?? null : null;
            if (! is_array($item) || ($item['type'] ?? null) !== 'agent_message' || ! is_string($item['text'] ?? null)) {
                continue;
            }

            $messages[] = $item['text'];
        }

        return array_values(array_unique($messages));
    }

    public function redact(string $output): string
    {
        return $this->redactSensitiveOutput($output);
    }

    /** @param array{exit_code: int, output: string, error_output: string} $execution */
    public function record(Project $project, AgentRole $role, string $prompt, array $execution, ?Task $task = null): AgentRun
    {
        return $this->complete($this->start($project, $role, $prompt, $task), $execution);
    }

    private function boundedOutput(string $output): string
    {
        return mb_strlen($output) > self::LiveOutputLimit
            ? mb_substr($output, -self::LiveOutputLimit)
            : $output;
    }

    private function redactSensitiveOutput(string $output): string
    {
        return implode("\n", array_map(fn (string $line): string => $this->redactOutputLine($line), explode("\n", $output)));
    }

    private function redactOutputLine(string $line): string
    {
        $event = json_decode($line, true);
        if (is_array($event)) {
            try {
                return json_encode($this->redactValue($event), JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $this->redactText($line);
            }
        }

        return $this->redactText($line);
    }

    private function redactText(string $text): string
    {
        $redacted = preg_replace(
            '/-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----.*?-----END (?:[A-Z ]+ )?PRIVATE KEY-----/s',
            '[REDACTED PRIVATE KEY]',
            $text,
        ) ?? $text;
        $redacted = preg_replace('/(?i)(authorization\s*:\s*bearer\s+)[^\s"\']+/', '$1[REDACTED]', $redacted) ?? $redacted;
        $redacted = preg_replace('/\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16})\b/', '[REDACTED]', $redacted) ?? $redacted;

        return preg_replace(
            '/(?i)\b((?=[a-z0-9_]*(?:token|secret|password|api_key|app_key|private_key|credential))[a-z][a-z0-9_]*)\s*=\s*[^\r\n]*/',
            '$1=[REDACTED]',
            $redacted,
        ) ?? $redacted;
    }

    private function redactValue(mixed $value, ?string $field = null): mixed
    {
        if (is_string($value)) {
            return $this->isSensitiveField($field) ? '[REDACTED]' : $this->redactText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->redactValue($nestedValue, is_string($key) ? $key : null);
        }

        return $value;
    }

    private function isSensitiveField(?string $field): bool
    {
        return $field !== null && preg_match('/(?:token|secret|password|api_key|app_key|private_key|credential)/i', $field) === 1;
    }

    /** @return array{codex_run_id: ?string, result: array<string, mixed>, commands: array<int, array{command: string, exit_code: int|null}>, file_modifications: array<int, array{path: string, kind: string}>, token_usage: int|null} */
    private function metadata(string $output): array
    {
        $codexRunId = null;
        $agentMessages = [];
        $commands = [];
        $fileModifications = [];
        $usage = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }

            if (($event['type'] ?? null) === 'thread.started' && is_string($event['thread_id'] ?? null)) {
                $codexRunId = $event['thread_id'];
            }

            if (($event['type'] ?? null) === 'turn.completed' && is_array($event['usage'] ?? null)) {
                $usage = $event['usage'];
            }

            $item = $event['item'] ?? null;
            if (! is_array($item) || ($event['type'] ?? null) !== 'item.completed') {
                continue;
            }

            if (($item['type'] ?? null) === 'agent_message' && is_string($item['text'] ?? null)) {
                $agentMessages[] = Str::limit($item['text'], 4000, '…');
            }

            if (($item['type'] ?? null) === 'command_execution' && is_string($item['command'] ?? null)) {
                $commands[] = [
                    'command' => $item['command'],
                    'exit_code' => is_int($item['exit_code'] ?? null) ? $item['exit_code'] : null,
                ];
            }

            if (($item['type'] ?? null) === 'file_change' && is_array($item['changes'] ?? null)) {
                foreach ($item['changes'] as $change) {
                    if (! is_array($change) || ! is_string($change['path'] ?? null) || ! is_string($change['kind'] ?? null)) {
                        continue;
                    }

                    $fileModifications[] = ['path' => $change['path'], 'kind' => $change['kind']];
                }
            }
        }

        $inputTokens = is_array($usage) && is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0;
        $outputTokens = is_array($usage) && is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0;

        return [
            'codex_run_id' => $codexRunId,
            'result' => array_filter(['agent_messages' => array_slice($agentMessages, -self::MetadataItemLimit), 'usage' => $usage]),
            'commands' => array_slice($commands, -self::MetadataItemLimit),
            'file_modifications' => array_slice($fileModifications, -self::MetadataItemLimit),
            'token_usage' => $inputTokens + $outputTokens > 0 ? $inputTokens + $outputTokens : null,
        ];
    }
}
