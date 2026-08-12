<?php

namespace App\Services;

use App\Models\VaultOrganizationRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VaultOrganizationAgent
{
    private const int LiveOutputLimit = 120000;

    public function __construct(private CodexCliRunner $runner, private AgentRunRecorder $redactor, private StructuredResultParser $parser, private VaultOrganizationPrompt $prompt, private AuditLogger $audit) {}

    public function run(): ?VaultOrganizationRun
    {
        if (! config('aios.vault_organization_enabled')) {
            return null;
        }

        $vaultPath = config('aios.obsidian_vault_path');
        if (! is_string($vaultPath) || ! is_dir($vaultPath) || ! is_readable($vaultPath)) {
            throw new RuntimeException('The configured Obsidian vault directory is unavailable.');
        }

        $lock = Cache::lock('aios:vault-organization', (int) config('aios.execution_timeout'));
        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->execute($vaultPath);
        } finally {
            $lock->release();
        }
    }

    private function execute(string $vaultPath): VaultOrganizationRun
    {
        $prompt = $this->prompt->text();
        $run = VaultOrganizationRun::create([
            'status' => 'running',
            'prompt_hash' => hash('sha256', $prompt),
            'started_at' => now(),
        ]);
        $this->audit->record('vault_organization.started', ['vault_organization_run_id' => $run->id, 'role' => 'knowledge_architect', 'prompt_hash' => $run->prompt_hash]);

        try {
            $execution = $this->runner->runAtPath($vaultPath, $prompt, function (string $type, string $output) use ($run): void {
                $prefix = $type === 'stderr' ? '[stderr] ' : '';
                $freshRun = $run->fresh();
                $freshRun?->update(['live_output' => $this->boundedOutput(($freshRun->live_output ?? '').$prefix.$this->redactor->redact($output))]);
            });
        } catch (Throwable $exception) {
            $execution = ['exit_code' => -1, 'output' => '', 'error_output' => $exception->getMessage()];
        }

        $output = $this->redactor->redact($execution['output']);
        $errorOutput = $this->redactor->redact($execution['error_output']);
        $logPath = 'vault-organization-runs/'.Str::uuid().'.jsonl';
        Storage::disk('local')->put($logPath, $output."\n".$errorOutput);
        $report = $this->parser->parseAgentMessage($output);
        $completed = $execution['exit_code'] === 0 && is_array($report);
        $run->update([
            'status' => $completed ? 'completed' : 'failed',
            'report' => $report,
            'token_usage' => $this->tokenUsage($output),
            'log_path' => $logPath,
            'live_output' => $this->boundedOutput($run->fresh()->live_output ?: $output."\n".$errorOutput),
            'exit_code' => $execution['exit_code'],
            'finished_at' => now(),
        ]);

        $completedRun = $run->refresh();
        $this->audit->record('vault_organization.completed', [
            'vault_organization_run_id' => $completedRun->id,
            'role' => 'knowledge_architect',
            'status' => $completedRun->status,
            'exit_code' => $completedRun->exit_code,
            'token_usage' => $completedRun->token_usage,
        ]);

        return $completedRun;
    }

    private function boundedOutput(string $output): string
    {
        return mb_strlen($output) > self::LiveOutputLimit ? mb_substr($output, -self::LiveOutputLimit) : $output;
    }

    private function tokenUsage(string $output): ?int
    {
        $usage = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && ($event['type'] ?? null) === 'turn.completed' && is_array($event['usage'] ?? null)) {
                $usage = $event['usage'];
            }
        }

        $input = is_array($usage) && is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : 0;
        $outputTokens = is_array($usage) && is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : 0;

        return $input + $outputTokens > 0 ? $input + $outputTokens : null;
    }
}
