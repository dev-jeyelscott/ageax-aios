<?php

use App\Exceptions\UnsafeProjectPath;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\CodexCliRunner;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use ReflectionProperty;

test('runs Codex with an explicitly isolated environment', function () {
    Process::fake(['*' => Process::result(output: 'completed')]);

    app(CodexCliRunner::class)->runAtPath(base_path(), 'Implement the task.');

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (new ReflectionProperty($process, 'command'))->getValue($process);

        return $command[0] === '/usr/bin/env'
            && $command[1] === '-i'
            && in_array('HOME='.getenv('HOME'), $command, true)
            && in_array('PATH='.getenv('PATH'), $command, true)
            && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'DB_'))
            && in_array(config('aios.codex_binary'), $command, true);
    });
});

test('refuses to run Codex for a persisted project path outside the workspace', function () {
    config()->set('aios.workspace_root', sys_get_temp_dir());
    $project = Project::create(['name' => 'Unsafe', 'path' => base_path(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    Process::fake();

    expect(fn () => app(CodexCliRunner::class)->run($project, 'Implement the task.'))->toThrow(UnsafeProjectPath::class);
    Process::assertNotRan(fn (): bool => true);
});
