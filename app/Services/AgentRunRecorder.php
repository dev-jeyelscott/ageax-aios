<?php

namespace App\Services;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
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

    public function __construct(
        private AuditLogger $audit,
        private TokenUsageObservability $tokens,
        private TokenUsageNormalizer $usage,
    ) {}

    /**
     * @param  array<string, mixed>|null  $retrievalManifest
     *
     * $agent and $context, when supplied, capture an immutable configuration snapshot on the
     * run at creation time (P2-012). Editing the Agent or its Skills afterward never changes
     * this persisted snapshot; a future run resolves and snapshots the then-current configuration.
     */
    public function start(Project $project, AgentRole $role, string $prompt, ?Task $task = null, ?TaskAttempt $attempt = null, ?WorkerLease $lease = null, ?array $retrievalManifest = null, ?Agent $agent = null, ?AssembledAgentContext $context = null): AgentRun
    {
        $run = AgentRun::create([
            'project_id' => $project->id,
            'task_id' => $task?->id,
            'agent_worker_id' => $lease === null ? AgentWorker::query()->whereBelongsTo($project)->where('role', $role)->value('id') : $lease->workerId,
            'agent_id' => $agent?->id,
            'worker_instance_id' => $lease?->workerInstanceId,
            'worker_lease_id' => $lease?->leaseId,
            'role' => $role,
            'harness' => $agent?->getRawOriginal('harness'),
            'status' => AgentRunStatus::Running,
            'attempt_number' => $attempt?->number,
            'prompt_hash' => hash('sha256', $prompt),
            'result' => $retrievalManifest === null ? null : ['retrieval_manifest' => $retrievalManifest],
            'configuration_snapshot' => $context?->configurationSnapshot(),
            'context_schema_version' => $context?->contextSchemaVersion,
            'context_cost_estimate' => $context?->contextCostEstimate,
            'context_cost_schema_version' => $context?->contextCostSchemaVersion,
            'started_at' => now(),
        ]);

        if ($agent !== null) {
            $selectionPayload = [
                'project_id' => $project->id,
                'agent_run_id' => $run->id,
                'agent_id' => $agent->id,
                'agent_configuration_version' => $agent->configuration_version,
                'role' => $role->value,
                'harness' => $run->harness,
            ];

            $this->audit->record(
                'agent_run.harness_selected',
                $selectionPayload,
                $project,
                $task,
            );

            if ($context !== null) {
                $skills = [];

                foreach ($context->skillsSnapshot as $skill) {
                    $skills[] = [
                        'skill_id' => $skill['id'] ?? null,
                        'skill_version' => $skill['version'] ?? null,
                        'position' => $skill['position'] ?? null,
                    ];
                }

                $this->audit->record('agent_run.configuration_snapshotted', [
                    ...$selectionPayload,
                    'context_schema_version' => $context->contextSchemaVersion,
                    'context_hash' => $context->hash,
                    'skills' => $skills,
                ], $project, $task);

                $this->audit->record('agent_run.context_cost_estimated', [
                    ...$selectionPayload,
                    'context_cost_schema_version' => $context->contextCostSchemaVersion,
                    'breakdown' => $context->contextCostEstimate,
                ], $project, $task);

                $disproportionateSections = $context->contextCostEstimate['disproportionate_sections'] ?? [];

                if ($disproportionateSections !== []) {
                    $this->audit->record('agent_run.context_cost_warning', [
                        'agent_run_id' => $run->id,
                        'disproportionate_sections' => $disproportionateSections,
                        'total' => $context->contextCostEstimate['total'] ?? null,
                    ], $project, $task);
                }
            }
        }

        $this->audit->record('agent.execution_started', [
            'agent_run_id' => $run->id,
            'role' => $role->value,
            'attempt_number' => $attempt?->number,
            'prompt_hash' => $run->prompt_hash,
            'worker_instance_id' => $run->worker_instance_id,
            'worker_lease_id' => $run->worker_lease_id,
            'retrieval_manifest' => $retrievalManifest,
            'agent_id' => $agent?->id,
            'agent_configuration_version' => $agent?->configuration_version,
            'harness' => $run->harness,
            'context_hash' => $context?->hash,
        ], $project, $task);

        return $run;
    }

    /**
     * @param  array{
     *     exit_code: int,
     *     output: string,
     *     error_output: string,
     *     external_run_id?: string|null,
     *     usage?: array<string, mixed>|null,
     *     provider_metadata?: array<string, mixed>
     * }  $execution
     */
    public function complete(AgentRun $run, array $execution): AgentRun
    {
        $logPath = 'agent-runs/'.Str::uuid().'.jsonl';
        $output = $this->redactSensitiveOutput($execution['output']);
        $errorOutput = $this->redactSensitiveOutput($execution['error_output']);
        Storage::disk('local')->put($logPath, $output."\n".$errorOutput);
        $metadata = $this->metadata($output);
        $normalizedUsage = is_array($execution['usage'] ?? null)
            ? $execution['usage']
            : null;
        $providerUsage = $normalizedUsage ?? $metadata['usage'];
        $harness = $run->getRawOriginal('harness');

        if (! is_string($harness) || $harness === '') {
            $harness = $metadata['codex_run_id'] === null ? null : 'codex';
        }

        $usageEvidence = $this->usage->normalize($harness, $providerUsage);
        $tokenUsage = $usageEvidence['canonical_total_tokens'];
        $liveOutput = $run->fresh()->live_output;

        if (blank($liveOutput)) {
            $liveOutput = $this->boundedOutput($output."\n".$errorOutput);
        }

        $existingResult = $this->result($run->fresh());
        $externalRunId = is_string($execution['external_run_id'] ?? null) ? $execution['external_run_id'] : $metadata['codex_run_id'];

        $run->update([
            'status' => $execution['exit_code'] === 0 ? AgentRunStatus::Completed : AgentRunStatus::Failed,
            'exit_code' => $execution['exit_code'],
            'codex_run_id' => $metadata['codex_run_id'],
            'external_run_id' => $externalRunId,
            'result' => [
                ...$existingResult,
                ...$metadata['result'],
                ...($providerUsage === null ? [] : ['usage' => $providerUsage]),
                'token_usage' => $usageEvidence,
            ],
            'commands' => $metadata['commands'],
            'file_modifications' => $metadata['file_modifications'],
            'token_usage' => $tokenUsage,
            'log_path' => $logPath,
            'live_output' => $liveOutput,
            'finished_at' => now(),
        ]);

        $completedRun = $run->refresh();
        $completedRun->loadMissing('project', 'task');
        $observability = $this->tokens->forProject($completedRun->project);

        $this->audit->record('agent.execution_completed', [
            'agent_run_id' => $completedRun->id,
            'role' => AgentRole::from($completedRun->getRawOriginal('role'))->value,
            'attempt_number' => $completedRun->attempt_number,
            'exit_code' => $completedRun->exit_code,
            'codex_run_id' => $completedRun->codex_run_id,
            'external_run_id' => $completedRun->external_run_id,
            'agent_id' => $completedRun->agent_id,
            'harness' => $completedRun->harness,
            'token_usage' => $completedRun->token_usage,
            'commands' => $completedRun->commands ?? [],
            'file_modifications' => $completedRun->file_modifications ?? [],
            'retrieval_manifest' => $this->result($completedRun)['retrieval_manifest'] ?? null,
            'token_observability' => $observability,
        ], $completedRun->project, $completedRun->task);

        $role = AgentRole::from($completedRun->getRawOriginal('role'));
        $threshold = $observability[$role->value]['warning_threshold'] ?? null;

        if ($threshold !== null && $completedRun->token_usage !== null && $completedRun->token_usage >= $threshold) {
            $this->audit->record('agent.token_warning', [
                'agent_run_id' => $completedRun->id,
                'role' => $role->value,
                'token_usage' => $completedRun->token_usage,
                'warning_threshold' => $threshold,
                'token_observability' => $observability[$role->value],
            ], $completedRun->project, $completedRun->task);
        }

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

            if (! is_array($event)) {
                continue;
            }

            array_push($messages, ...$this->extractAgentMessageTexts($event));
        }

        return array_values(array_unique($messages));
    }

    /**
     * Recognizes the "agent message" shape from both supported harnesses: Codex CLI's
     * `item.completed` items and Claude Code CLI's `stream-json` assistant text blocks.
     *
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function extractAgentMessageTexts(array $event): array
    {
        $texts = [];

        $item = $event['item'] ?? null;

        if (is_array($item) && ($item['type'] ?? null) === 'agent_message' && is_string($item['text'] ?? null)) {
            $texts[] = $item['text'];
        }

        $content = ($event['type'] ?? null) === 'assistant' ? $event['message']['content'] ?? null : null;

        if (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $texts[] = $block['text'];
                }
            }
        }

        return $texts;
    }

    public function redact(string $output): string
    {
        return $this->redactSensitiveOutput($output);
    }

    public function failureReason(AgentRun $run): ?string
    {
        $output = $this->transcript($run);

        if ($output === null) {
            return null;
        }

        $reason = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $event = json_decode($line, true);

            if (! is_array($event)) {
                continue;
            }

            $message = match (true) {
                is_string($event['message'] ?? null) && in_array($event['type'] ?? null, ['error', 'turn.failed'], true) => $event['message'],
                is_array($event['error'] ?? null) && is_string($event['error']['message'] ?? null) => $event['error']['message'],
                default => null,
            };

            if ($message !== null) {
                $reason = $message;
            }
        }

        return $reason;
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

    /** @return array<string, mixed> */
    private function result(AgentRun $run): array
    {
        $decoded = json_decode((string) $run->getRawOriginal('result'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function redactSensitiveOutput(string $output): string
    {
        return implode("\n", array_map(
            fn (string $line): string => $this->redactOutputLine($line),
            explode("\n", $output),
        ));
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

        $redacted = preg_replace(
            '/(?i)(authorization\s*:\s*bearer\s+)[^\s"\']+/',
            '$1[REDACTED]',
            $redacted,
        ) ?? $redacted;

        $redacted = preg_replace(
            '/\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16})\b/',
            '[REDACTED]',
            $redacted,
        ) ?? $redacted;

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
        return $field !== null
            && preg_match('/(?:token|secret|password|api_key|app_key|private_key|credential)/i', $field) === 1;
    }

    /**
     * @return array{
     *     codex_run_id: ?string,
     *     result: array<string, mixed>,
     *     commands: array<int, array{command: string, exit_code: int|null}>,
     *     file_modifications: array<int, array{path: string, kind: string}>,
     *     usage: array<string, mixed>|null
     * }
     */
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

            foreach ($this->extractAgentMessageTexts($event) as $text) {
                $agentMessages[] = Str::limit($text, 4000, '…');
            }

            $item = $event['item'] ?? null;

            if (! is_array($item) || ($event['type'] ?? null) !== 'item.completed') {
                continue;
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

                    $fileModifications[] = [
                        'path' => $change['path'],
                        'kind' => $change['kind'],
                    ];
                }
            }
        }

        return [
            'codex_run_id' => $codexRunId,
            'result' => array_filter([
                'agent_messages' => array_slice($agentMessages, -self::MetadataItemLimit),
                'usage' => $usage,
            ]),
            'commands' => array_slice($commands, -self::MetadataItemLimit),
            'file_modifications' => array_slice($fileModifications, -self::MetadataItemLimit),
            'usage' => $usage,
        ];
    }
}
