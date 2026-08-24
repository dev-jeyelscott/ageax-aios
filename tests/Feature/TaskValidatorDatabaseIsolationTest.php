<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\ManagedValidationProcessCleanup;
use App\Services\TaskValidator;
use App\TaskStatus;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function taskValidatorSecurityTask(array $verificationCommands): Task
{
    $path = sys_get_temp_dir().'/aios-task-validator-'.fake()->uuid();
    File::ensureDirectoryExists($path);

    $project = Project::create([
        'name' => 'Task Validator Security',
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Validate safely',
        'objective' => 'Verify the managed project without leaking AIOS environment state.',
        'acceptance_criteria' => ['Validation is isolated.'],
        'verification_commands' => $verificationCommands,
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'status' => TaskStatus::Reviewing,
    ]);
}

/** @return list<string> */
function taskValidatorRecordedCommand(PendingProcess $process): array
{
    $command = (new ReflectionProperty($process, 'command'))->getValue($process);

    return is_array($command) ? array_values($command) : [];
}

function taskValidatorCommandIsSanitized(PendingProcess $process): bool
{
    $command = taskValidatorRecordedCommand($process);
    $path = getenv('PATH');

    return ($command[0] ?? null) === '/usr/bin/env'
        && ($command[1] ?? null) === '-i'
        && ($path === false || $path === '' || in_array('PATH='.$path, $command, true))
        && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'DB_'))
        && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'DATABASE_URL='))
        && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'APP_KEY='))
        && ! collect($command)->contains(fn (string $argument): bool => str_starts_with($argument, 'AIOS_TEST_API_TOKEN='));
}

test('task validator isolates every managed-project subprocess from AIOS database and application secrets', function () {
    $task = taskValidatorSecurityTask(['php artisan test --compact']);
    $sensitiveEnvironment = [
        'DB_DATABASE' => 'ageax_aios',
        'DB_HOST' => '127.0.0.1',
        'DB_USERNAME' => 'aios',
        'DB_PASSWORD' => 'must-not-reach-managed-project',
        'DATABASE_URL' => 'pgsql://aios:secret@127.0.0.1/ageax_aios',
        'APP_KEY' => 'base64:must-not-reach-managed-project',
        'AIOS_TEST_API_TOKEN' => 'must-not-reach-managed-project',
    ];
    $originalEnvironment = [];
    $originalPath = getenv('PATH');
    putenv('PATH=/usr/bin:/bin');

    foreach ($sensitiveEnvironment as $key => $value) {
        $originalEnvironment[$key] = getenv($key);
        putenv("{$key}={$value}");
    }

    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result())
        ->push(Process::result()),
    ]);

    try {
        $validation = app(TaskValidator::class)->validate($task);

        expect($validation['passed'])->toBeTrue();
        Process::assertRanTimes(fn (PendingProcess $process): bool => taskValidatorCommandIsSanitized($process), 4);
    } finally {
        foreach ($originalEnvironment as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }

        $originalPath === false ? putenv('PATH') : putenv('PATH='.$originalPath);
    }
});

test('task validator reports each managed validation process when it starts', function () {
    $task = taskValidatorSecurityTask(['php artisan test --compact']);
    $processes = [];
    Process::fake(['*' => Process::describe()->id(4_321)]);

    app(TaskValidator::class)->validate(
        $task,
        null,
        function (int $pid, array $command) use (&$processes): void {
            $processes[] = ['pid' => $pid, 'command' => $command];
        },
    );

    expect($processes)
        ->toHaveCount(4)
        ->each->toMatchArray(['pid' => 4_321])
        ->and($processes[3]['command'])
        ->toBe(['php', 'artisan', 'test', '--compact']);
});

test('recorded stale validation processes are stopped before a fresh validation run', function () {
    $task = taskValidatorSecurityTask([]);
    $process = Process::path($task->project->path)->start(['sleep', '30']);
    $pid = $process->id();

    expect($pid)->toBeInt();

    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'interrupted',
        'validation_results' => [
            'managed_processes' => [
                ['pid' => $pid, 'command' => ['sleep', '30']],
            ],
        ],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    try {
        $terminated = app(ManagedValidationProcessCleanup::class)
            ->terminateStaleProcesses($task);

        expect($terminated)->toContain($pid)
            ->and($process->running())->toBeFalse();
    } finally {
        $process->stop();
    }
});

test('task validator rejects destructive artisan verification commands without executing them', function (string $verificationCommand, string $forbiddenToken) {
    $task = taskValidatorSecurityTask([$verificationCommand]);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result()),
    ]);

    $validation = app(TaskValidator::class)->validate($task);

    expect($validation['passed'])->toBeFalse()
        ->and($validation['checks']['task_verification'])->toBeFalse()
        ->and($validation['evidence']['task_verification']['summary'])->toBe('A configured verification command is not allowed.');

    Process::assertRanTimes(fn (PendingProcess $process): bool => taskValidatorCommandIsSanitized($process), 3);
    Process::assertNotRan(fn (PendingProcess $process): bool => in_array($forbiddenToken, taskValidatorRecordedCommand($process), true));
})->with([
    'direct migrate' => ['php artisan migrate', 'migrate'],
    'direct migrate fresh' => ['php artisan migrate:fresh --seed', 'migrate:fresh'],
    'direct migrate install' => ['php artisan migrate:install', 'migrate:install'],
    'direct migrate refresh' => ['php artisan migrate:refresh', 'migrate:refresh'],
    'direct migrate reset' => ['php artisan migrate:reset', 'migrate:reset'],
    'direct migrate rollback' => ['php artisan migrate:rollback', 'migrate:rollback'],
    'direct db wipe' => ['php artisan db:wipe', 'db:wipe'],
    'direct database seed' => ['php artisan db:seed', 'db:seed'],
    'direct model prune' => ['php artisan model:prune', 'model:prune'],
    'direct schema dump' => ['php artisan schema:dump', 'schema:dump'],
    'docker migrate fresh' => ['docker compose exec -T app php artisan migrate:fresh', 'migrate:fresh'],
    'docker db wipe' => ['docker compose exec -T app php artisan db:wipe', 'db:wipe'],
]);

test('task validator still executes approved non-destructive verification commands', function (string $verificationCommand, array $expectedTail) {
    $task = taskValidatorSecurityTask([$verificationCommand]);
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result())
        ->push(Process::result()),
    ]);

    $validation = app(TaskValidator::class)->validate($task);

    expect($validation['passed'])->toBeTrue()
        ->and($validation['checks']['task_verification'])->toBeTrue();

    Process::assertRan(function (PendingProcess $process) use ($expectedTail): bool {
        $command = taskValidatorRecordedCommand($process);

        return taskValidatorCommandIsSanitized($process)
            && array_slice($command, -count($expectedTail)) === $expectedTail;
    });
})->with([
    'artisan tests' => ['php artisan test --compact', ['php', 'artisan', 'test', '--compact']],
    'artisan migration status' => ['php artisan migrate:status', ['php', 'artisan', 'migrate:status']],
    'pest' => ['vendor/bin/pest', ['vendor/bin/pest']],
    'phpstan' => ['vendor/bin/phpstan', ['vendor/bin/phpstan']],
    'docker artisan tests' => ['docker compose exec -T app php artisan test --compact', ['docker', 'compose', 'exec', '-T', 'app', 'php', 'artisan', 'test', '--compact']],
]);
