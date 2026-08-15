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

    /** @return array{exit_code: int, output: string, error_output: string} */
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

    /** @return list<string> */
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
