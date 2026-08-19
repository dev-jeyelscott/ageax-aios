<?php

namespace App\Services;

use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class CodexCliRunner
{
    public function __construct(
        private WorkspacePathResolver $paths,
        private SanitizedExecutionEnvironment $environment,
    ) {}

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function run(
        Project $project,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
    ): array {
        $legacyWorkflowRun = AgentRun::query()
            ->whereBelongsTo($project)
            ->whereNull('agent_id')
            ->where('status', AgentRunStatus::Running)
            ->where('prompt_hash', hash('sha256', $prompt))
            ->latest('id')
            ->first();

        if ($legacyWorkflowRun !== null) {
            $legacyWorkflowRun->update([
                'context_budget_schema_version' => ContextBudgetPolicy::SchemaVersion,
                'context_budget_snapshot' => [
                    'schema_version' => ContextBudgetPolicy::SchemaVersion,
                    'policy_version' => ContextBudgetPolicy::PolicyVersion,
                    'decision' => 'blocked',
                    'capacity_source' => null,
                    'capacity_source_version' => null,
                    'resolved_capacity_tokens' => null,
                    'original_prompt_hash' => hash('sha256', $prompt),
                    'final_prompt_hash' => hash('sha256', $prompt),
                    'warning_reason' => null,
                    'block_reason' => 'legacy_execution_has_no_bound_agent_capacity',
                    'action' => 'Bind a supported project Agent so AIOS can resolve attributable harness/model capacity before execution.',
                ],
            ]);

            return [
                'exit_code' => -1,
                'output' => '',
                'error_output' => 'Context Budget blocked the legacy no-Agent execution path. Bind a supported project Agent before retrying.',
            ];
        }

        return $this->runAtPath(
            $this->paths->assertProjectPath($project->path),
            $prompt,
            $onOutput,
            $onHeartbeat,
        );
    }

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function runForAgent(
        Project $project,
        Agent $agent,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
    ): array {
        return $this->runAtPath(
            $this->paths->assertProjectPath($project->path),
            $prompt,
            $onOutput,
            $onHeartbeat,
            $agent,
        );
    }

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function runAtPath(
        string $path,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        ?Agent $agent = null,
    ): array {
        $pending = Process::path($path)
            ->timeout((int) config('aios.execution_timeout'))
            ->input($prompt);

        if ($onHeartbeat === null) {
            return $this->result(
                $pending->run(
                    $this->command($agent),
                    $onOutput,
                ),
            );
        }

        $process = $pending->start(
            $this->command($agent),
            $onOutput,
        );

        $interval = max(
            1,
            (int) config(
                'aios.worker_heartbeat_interval_seconds',
            ),
        );

        $nextHeartbeatAt = now();

        while ($process->running()) {
            if (now()->gte($nextHeartbeatAt)) {
                $onHeartbeat();
                $nextHeartbeatAt = now()->addSeconds(
                    $interval,
                );
            }

            usleep(250000);
        }

        return $this->result($process->wait());
    }

    /**
     * AIOS's own path/workspace boundary (WorkspacePathResolver::assertProjectPath(), enforced by
     * every caller before this command is built) is the authoritative protection against Codex
     * escaping into the AIOS repository, its database, or its backups. `--approve-for-me` is
     * confirmed (`codex exec --help`, codex-cli 0.147.0) to already route every command through the
     * `workspace-write` sandbox automatically — the strongest supported non-destructive mode that
     * still lets the agent implement a task, since `-s/--sandbox` cannot be combined with
     * `--approve-for-me` (the CLI rejects that combination) and there is no interactive TTY here to
     * approve commands one at a time. Never add `-s danger-full-access` or
     * `--dangerously-bypass-approvals-and-sandbox` for normal execution.
     *
     * @return list<string>
     */
    private function command(?Agent $agent = null): array
    {
        $command = [
            (string) config('aios.codex_binary'),
            'exec',
            '--ephemeral',
            '--json',
            '--approve-for-me',
        ];

        if ($agent !== null) {
            $model = $agent->getRawOriginal('model');

            if (is_string($model) && $model !== '') {
                $command[] = '--model';
                $command[] = $model;
            }

            $reasoningSetting = $agent->getRawOriginal(
                'reasoning_setting',
            );

            if (
                is_string($reasoningSetting)
                && $reasoningSetting !== ''
            ) {
                $command[] = '--config';
                $command[] =
                    'model_reasoning_effort="'
                    .$reasoningSetting
                    .'"';
            }
        }

        $command[] = '-';

        return $this->environment->wrap($command);
    }

    /** @return array{exit_code: int, output: string, error_output: string} */
    private function result(ProcessResult $result): array
    {
        return [
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error_output' => $result->errorOutput(),
        ];
    }
}

