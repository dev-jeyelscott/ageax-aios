<?php

namespace App\Services;

use App\Models\Project;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class CodexCliRunner
{
    public function __construct(private WorkspacePathResolver $paths) {}

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function run(Project $project, string $prompt, ?Closure $onOutput = null, ?Closure $onHeartbeat = null): array
    {
        return $this->runAtPath($this->paths->assertProjectPath($project->path), $prompt, $onOutput, $onHeartbeat);
    }

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function runAtPath(string $path, string $prompt, ?Closure $onOutput = null, ?Closure $onHeartbeat = null): array
    {
        $pending = Process::path($path)
            ->timeout((int) config('aios.execution_timeout'))
            ->input($prompt);

        if ($onHeartbeat === null) {
            return $this->result($pending->run($this->command(), $onOutput));
        }

        $process = $pending->start($this->command(), $onOutput);
        $interval = max(1, (int) config('aios.worker_heartbeat_interval_seconds'));
        $nextHeartbeatAt = now();
        while ($process->running()) {
            if (now()->gte($nextHeartbeatAt)) {
                $onHeartbeat();
                $nextHeartbeatAt = now()->addSeconds($interval);
            }

            usleep(250000);
        }

        return $this->result($process->wait());
    }

    /** @return list<string> */
    private function command(): array
    {
        return [
            '/usr/bin/env',
            '-i',
            ...$this->environment(),
            config('aios.codex_binary'),
            'exec',
            '--ephemeral',
            '--json',
            '--approve-for-me',
            '-',
        ];
    }

    /** @return list<string> */
    private function environment(): array
    {
        return array_values(collect(['HOME', 'PATH', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR', 'CODEX_HOME'])
            ->mapWithKeys(function (string $key): array {
                $value = getenv($key);

                return $value === false || $value === '' ? [] : [$key => $value];
            })
            ->map(fn (string $value, string $key): string => "{$key}={$value}")
            ->values()
            ->all());
    }

    /** @return array{exit_code: int, output: string, error_output: string} */
    private function result(ProcessResult $result): array
    {
        return ['exit_code' => $result->exitCode(), 'output' => $result->output(), 'error_output' => $result->errorOutput()];
    }
}
