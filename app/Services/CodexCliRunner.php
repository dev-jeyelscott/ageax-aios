<?php

namespace App\Services;

use App\Models\Agent;
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

    /**
     * Established no-bound-Agent compatibility path.
     *
     * New bound-Agent execution is budgeted through AgentHarnessResolver ->
     * ContextBudgetedAgentHarness before provider dispatch. This direct runner path is retained
     * only for existing legacy workflow semantics that do not carry AssembledAgentContext or an
     * attributable Agent/harness/model configuration. P3-016 permits such a scoped, tested
     * exception rather than introducing a second authoritative budgeting/capability system here.
     *
     * @return array{exit_code: int, output: string, error_output: string}
     */
    public function run(
        Project $project,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
    ): array {
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
