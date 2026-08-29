<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult as ConcreteProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessSignaledException;

class CodexCliRunner
{
    private const int TimeoutExitCode = 124;

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
     * @param  array<string, mixed>  $executionSettings
     * @return array{exit_code: int, output: string, error_output: string}
     */
    public function run(
        Project $project,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        array $executionSettings = [],
    ): array {
        return $this->runAtPath(
            $this->paths->assertProjectPath($project->path),
            $prompt,
            $onOutput,
            $onHeartbeat,
            executionSettings: $executionSettings,
        );
    }

    /**
     * Execute Codex for one bound Agent in the canonical project path.
     *
     * @param  array<string, mixed>  $executionSettings
     * @return array{exit_code: int, output: string, error_output: string}
     */
    public function runForAgent(
        Project $project,
        Agent $agent,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        array $executionSettings = [],
    ): array {
        return $this->runAtPath(
            $this->paths->assertProjectPath($project->path),
            $prompt,
            $onOutput,
            $onHeartbeat,
            $agent,
            $executionSettings,
        );
    }

    /**
     * Execute Codex in the exact AIOS-selected and already-validated workspace path.
     *
     * @param  array<string, mixed>  $executionSettings
     * @return array{exit_code: int, output: string, error_output: string}
     */
    public function runAtPath(
        string $path,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        ?Agent $agent = null,
        array $executionSettings = [],
    ): array {
        $pending = Process::path($path)
            ->timeout($this->executionTimeout($executionSettings))
            ->input($prompt);

        try {
            if ($onHeartbeat === null) {
                return $this->result(
                    $pending->run(
                        $this->command($agent, $path),
                        $onOutput,
                    ),
                );
            }

            $process = $pending->start(
                $this->command($agent, $path),
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
                    if ($onHeartbeat() === false) {
                        $process->stop(1);

                        return $this->failure(
                            125,
                            'Codex execution stopped because its AIOS worker lease was lost.',
                        );
                    }

                    $nextHeartbeatAt = now()->addSeconds(
                        $interval,
                    );
                }

                usleep(250000);
            }

            if ($onHeartbeat() === false) {
                return $this->failure(
                    125,
                    'Codex execution completed after its AIOS worker lease was lost.',
                );
            }

            return $this->result($process->wait());
        } catch (ProcessTimedOutException) {
            return $this->failure(
                self::TimeoutExitCode,
                'Codex execution exceeded the configured AIOS execution timeout.',
            );
        } catch (ProcessSignaledException $exception) {
            return $this->result(new ConcreteProcessResult($exception->getProcess()));
        }
    }

    /**
     * Build the constrained Codex CLI command for the supplied Agent and execution path.
     *
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
    private function command(?Agent $agent = null, ?string $path = null): array
    {
        $command = [
            (string) config('aios.codex_binary'),
            'exec',
            '--ephemeral',
            '--json',
            '--approve-for-me',
        ];

        // Advisory-only execution (e.g. Knowledge Architect advisories, Project Manager
        // reconciliation) runs in an AIOS-created disposable directory so the provider cannot
        // inspect or mutate a managed project repository. Codex otherwise rejects that
        // intentional non-Git workspace before it can process the advisory. Deriving this from
        // the actual directory rather than a fixed Agent role means every present and future
        // isolated-sandbox execution is covered, not just one role.
        if ($path !== null && ! is_dir($path.'/.git')) {
            $command[] = '--skip-git-repo-check';
        }

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

    /**
     * Resolve the bounded provider timeout from AIOS-owned execution settings.
     *
     * @param  array<string, mixed>  $executionSettings
     */
    private function executionTimeout(array $executionSettings): int
    {
        $configured = $executionSettings['max_execution_seconds'] ?? null;

        $globalMaximum = max(1, (int) config('aios.execution_timeout'));

        return is_int($configured) && $configured >= 60
            ? min($configured, $globalMaximum)
            : $globalMaximum;
    }

    /**
     * Normalize one completed process result into the legacy runner result shape.
     *
     * @return array{exit_code: int, output: string, error_output: string}
     */
    private function result(ProcessResult $result): array
    {
        return [
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error_output' => $result->errorOutput(),
        ];
    }

    /**
     * Build one deterministic failed runner result without provider output.
     *
     * @return array{exit_code: int, output: string, error_output: string}
     */
    private function failure(int $exitCode, string $message): array
    {
        return [
            'exit_code' => $exitCode,
            'output' => '',
            'error_output' => $message,
        ];
    }
}
