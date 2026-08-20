<?php

use App\Exceptions\UnsafeProjectPath;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use ReflectionProperty;

test('runs Codex with an explicitly isolated environment', function () {
    $originalDbPassword = getenv('DB_PASSWORD');
    $originalApiToken = getenv('AIOS_TEST_API_TOKEN');
    putenv('DB_PASSWORD=must-not-reach-codex');
    putenv('AIOS_TEST_API_TOKEN=must-not-reach-codex');
    Process::fake(['*' => Process::result(output: 'completed')]);

    try {
        app(CodexCliRunner::class)->runAtPath(base_path(), 'Implement the task.');

        Process::assertRan(function (PendingProcess $process): bool {
            $command = (new ReflectionProperty($process, 'command'))->getValue($process);

            return $command[0] === '/usr/bin/env'
                && $command[1] === '-i'
                && in_array('HOME='.getenv('HOME'), $command, true)
                && in_array('PATH='.getenv('PATH'), $command, true)
                && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'DB_'))
                && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'AIOS_TEST_API_TOKEN='))
                && in_array(config('aios.codex_binary'), $command, true);
        });
    } finally {
        $originalDbPassword === false ? putenv('DB_PASSWORD') : putenv('DB_PASSWORD='.$originalDbPassword);
        $originalApiToken === false ? putenv('AIOS_TEST_API_TOKEN') : putenv('AIOS_TEST_API_TOKEN='.$originalApiToken);
    }
});

test('refuses to run Codex for a persisted project path outside the workspace', function () {
    config()->set('aios.workspace_root', sys_get_temp_dir());
    $project = Project::create(['name' => 'Unsafe', 'path' => base_path(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    Process::fake();

    expect(fn () => app(CodexCliRunner::class)->run($project, 'Implement the task.'))->toThrow(UnsafeProjectPath::class);
    Process::assertNotRan(fn (): bool => true);
});

test('converts an execution timeout into a normalized failure result instead of throwing', function () {
    $binary = tempnam(sys_get_temp_dir(), 'aios-codex-timeout-');
    expect($binary)->not->toBeFalse();
    file_put_contents($binary, "#!/bin/sh\nsleep 2\n");
    chmod($binary, 0700);

    try {
        config()->set('aios.codex_binary', $binary);
        config()->set('aios.execution_timeout', 1);

        $result = app(CodexCliRunner::class)->runAtPath(base_path(), 'Implement the task.');

        expect($result['exit_code'])->toBe(124)
            ->and($result['error_output'])->toContain('execution timeout')
            ->and($result['output'])->toBe('');
    } finally {
        @unlink($binary);
    }
});
