<?php

namespace App\Services;

use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult as ConcreteProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessSignaledException;

class ClaudeCodeCliRunner
{
    private const int TimeoutExitCode = 124;

    private const int AuthenticationTimeoutSeconds = 10;

    private const string StdinPrompt = 'Follow the AIOS task instructions supplied on stdin. Return only the requested final result.';

    private const array InspectionTools = ['Bash', 'Read', 'Glob', 'Grep'];

    private const array InspectionAllowedTools = ['Read', 'Glob', 'Grep'];

    private const array CoderTools = ['Bash', 'Edit', 'Write', 'Read', 'Glob', 'Grep'];

    private const array DeniedGitMutations = [
        'Bash(git add *)', 'Bash(git am *)', 'Bash(git apply *)', 'Bash(git branch *)',
        'Bash(git checkout *)', 'Bash(git cherry-pick *)', 'Bash(git clean *)', 'Bash(git clone *)',
        'Bash(git commit *)', 'Bash(git config *)', 'Bash(git fetch *)', 'Bash(git merge *)',
        'Bash(git mv *)', 'Bash(git pull *)', 'Bash(git push *)', 'Bash(git rebase *)',
        'Bash(git remote *)', 'Bash(git reset *)', 'Bash(git restore *)', 'Bash(git revert *)',
        'Bash(git rm *)', 'Bash(git stash *)', 'Bash(git switch *)', 'Bash(git symbolic-ref *)',
        'Bash(git tag *)', 'Bash(git update-index *)', 'Bash(git update-ref *)', 'Bash(git worktree *)',
    ];

    /**
     * Defense-in-depth only: AIOS path/workspace and database protection remain authoritative.
     */
    private const array DeniedDestructiveCommands = [
        'Bash(php artisan migrate:fresh*)', 'Bash(php artisan migrate:reset*)', 'Bash(php artisan db:wipe*)',
        'Bash(artisan migrate:fresh*)', 'Bash(artisan migrate:reset*)', 'Bash(artisan db:wipe*)',
        'Bash(dropdb*)', 'Bash(mysqladmin drop*)', 'Bash(mysql *DROP DATABASE*)',
        'Bash(mysql *drop database*)', 'Bash(psql *DROP DATABASE*)', 'Bash(psql *drop database*)',
        'Bash(rm -rf *)', 'Bash(rm -fr *)', 'Bash(rm --recursive --force *)', 'Bash(rm *.sqlite*)',
        'Bash(shred *)', 'Bash(truncate *)',
    ];

    public function __construct(
        private WorkspacePathResolver $paths,
        private SanitizedExecutionEnvironment $environment,
    ) {}

    /**
     * Execute Claude Code for one bound Agent in the canonical project path.
     *
     * @param  (Closure(string, string): void)|null  $onOutput
     * @param  (Closure(): mixed)|null  $onHeartbeat
     * @param  array<string, mixed>  $executionSettings
     * @return array{exit_code: int, output: string, error_output: string, failure_type: ?string}
     */
    public function run(Project $project, Agent $agent, string $prompt, ?Closure $onOutput = null, ?Closure $onHeartbeat = null, array $executionSettings = []): array
    {
        return $this->runAtPath($this->paths->assertProjectPath($project->path), $agent, $prompt, $onOutput, $onHeartbeat, $executionSettings);
    }

    /**
     * Execute Claude Code in the exact AIOS-selected workspace path.
     *
     * @param  (Closure(string, string): void)|null  $onOutput
     * @param  (Closure(): mixed)|null  $onHeartbeat
     * @param  array<string, mixed>  $executionSettings
     * @return array{exit_code: int, output: string, error_output: string, failure_type: ?string}
     */
    public function runAtPath(string $path, Agent $agent, string $prompt, ?Closure $onOutput = null, ?Closure $onHeartbeat = null, array $executionSettings = []): array
    {
        $authenticationFailure = $this->authenticationFailure($path);
        if ($authenticationFailure !== null) {
            return $authenticationFailure;
        }

        $pending = Process::path($path)->timeout($this->executionTimeout($executionSettings))->input($prompt);

        try {
            if ($onHeartbeat === null) {
                return $this->result($pending->run($this->command($agent), $onOutput));
            }

            $process = $pending->start($this->command($agent), $onOutput);
            $interval = max(1, (int) config('aios.worker_heartbeat_interval_seconds'));
            $nextHeartbeatAt = now();

            while ($process->running()) {
                if (now()->gte($nextHeartbeatAt)) {
                    if ($onHeartbeat() === false) {
                        $process->stop(1);

                        return $this->failure(125, 'Claude Code execution stopped because its AIOS worker lease was lost.', 'worker_lease_lost');
                    }
                    $nextHeartbeatAt = now()->addSeconds($interval);
                }
                usleep(250000);
            }

            if ($onHeartbeat() === false) {
                return $this->failure(125, 'Claude Code execution completed after its AIOS worker lease was lost.', 'worker_lease_lost');
            }

            return $this->result($process->wait());
        } catch (ProcessTimedOutException) {
            return $this->failure(self::TimeoutExitCode, 'Claude Code execution exceeded the configured AIOS execution timeout.', 'timeout');
        } catch (ProcessSignaledException $exception) {
            return $this->result(new ConcreteProcessResult($exception->getProcess()));
        }
    }

    /**
     * Check Claude Code authentication before provider execution.
     *
     * @return array{exit_code: int, output: string, error_output: string, failure_type: ?string}|null
     */
    private function authenticationFailure(string $path): ?array
    {
        try {
            $result = Process::path($path)
                ->timeout(min(self::AuthenticationTimeoutSeconds, max(1, (int) config('aios.execution_timeout'))))
                ->run($this->environment->wrap([(string) config('aios.claude_code_binary'), 'auth', 'status']));
        } catch (ProcessTimedOutException) {
            return $this->failure(self::TimeoutExitCode, 'Claude Code authentication status check timed out.', 'authentication_check_timeout');
        }

        if ($result->exitCode() === 0) {
            return null;
        }
        if (in_array($result->exitCode(), [126, 127], true)) {
            return $this->failure((int) $result->exitCode(), 'Claude Code is not installed or is not executable. Install Claude Code or configure AIOS_CLAUDE_CODE_BINARY.', 'missing_binary');
        }

        return $this->failure(max(1, (int) $result->exitCode()), 'Claude Code authentication is unavailable. Run "claude auth login" outside AIOS using the same OS user, then retry.', 'authentication_unavailable');
    }

    /**
     * Build the AIOS-constrained Claude Code command for one Agent role.
     *
     * @return list<string>
     */
    private function command(Agent $agent): array
    {
        $role = AgentRole::from((string) $agent->getRawOriginal('role'));
        $canEdit = in_array($role, [AgentRole::Coder, AgentRole::RecoveryEngineer], true);
        $tools = $canEdit ? self::CoderTools : self::InspectionTools;
        $allowedTools = $canEdit ? self::CoderTools : self::InspectionAllowedTools;

        $command = [
            (string) config('aios.claude_code_binary'), '--safe-mode', '-p', '--no-session-persistence', '--no-chrome',
            '--strict-mcp-config', '--output-format', 'stream-json', '--verbose', '--include-partial-messages',
            '--permission-mode', 'dontAsk', '--tools', implode(',', $tools), '--allowedTools', implode(',', $allowedTools),
            '--disallowedTools', implode(',', [...self::DeniedGitMutations, ...self::DeniedDestructiveCommands]),
        ];

        if (filled($agent->model)) {
            $command[] = '--model';
            $command[] = (string) $agent->model;
        }
        if (filled($agent->reasoning_setting)) {
            $command[] = '--effort';
            $command[] = (string) $agent->reasoning_setting;
        }
        $command[] = self::StdinPrompt;

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

        return is_int($configured) && $configured >= 60 ? min($configured, $globalMaximum) : $globalMaximum;
    }

    /**
     * Normalize one completed Claude Code process result.
     *
     * @return array{exit_code: int, output: string, error_output: string, failure_type: ?string}
     */
    private function result(ProcessResult $result): array
    {
        return [
            'exit_code' => (int) $result->exitCode(),
            'output' => $result->output(),
            'error_output' => $result->errorOutput(),
            'failure_type' => $result->exitCode() === 0 ? null : 'process_failure',
        ];
    }

    /**
     * Build one deterministic failed Claude Code result.
     *
     * @return array{exit_code: int, output: string, error_output: string, failure_type: string}
     */
    private function failure(int $exitCode, string $message, string $failureType): array
    {
        return ['exit_code' => $exitCode, 'output' => '', 'error_output' => $message, 'failure_type' => $failureType];
    }
}
