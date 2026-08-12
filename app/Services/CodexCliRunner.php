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
    public function run(Project $project, string $prompt, ?Closure $onOutput = null): array
    {
        return $this->runAtPath($this->paths->assertProjectPath($project->path), $prompt, $onOutput);
    }

    /** @return array{exit_code: int, output: string, error_output: string} */
    public function runAtPath(string $path, string $prompt, ?Closure $onOutput = null): array
    {
        $result = Process::path($path)
            ->timeout((int) config('aios.execution_timeout'))
            ->input($prompt)
            ->run($this->command(), $onOutput);

        return $this->result($result);
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
