<?php

use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\TaskValidator;
use App\TaskStatus;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function dockerVerificationTask(string $command): Task
{
    $path = sys_get_temp_dir().'/aios-validator-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    $project = Project::create(['name' => 'Docker verification', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);

    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Validate in Docker',
        'objective' => 'Run project verification in the configured runtime.',
        'acceptance_criteria' => ['Verification succeeds.'],
        'implementation_prompt' => 'Implement it.',
        'context_capsule' => [],
        'verification_commands' => [$command],
        'status' => TaskStatus::Reviewing,
    ]);
}

test('the task validator allows bounded Docker Compose exec verification commands', function () {
    $task = dockerVerificationTask('docker compose exec -T app php artisan test --compact');
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result())
        ->push(Process::result()),
    ]);

    $validation = app(TaskValidator::class)->validate($task);

    expect($validation['passed'])->toBeTrue()
        ->and($validation['checks']['task_verification'])->toBeTrue();
    Process::assertRan(function (PendingProcess $process): bool {
        $command = (new ReflectionProperty($process, 'command'))->getValue($process);

        return $command === ['docker', 'compose', 'exec', '-T', 'app', 'php', 'artisan', 'test', '--compact'];
    });
});

test('the task validator rejects destructive Docker Compose commands', function () {
    $task = dockerVerificationTask('docker compose down');
    Process::fake(['*' => Process::sequence()
        ->push(Process::result())
        ->push(Process::result(exitCode: 1))
        ->push(Process::result()),
    ]);

    $validation = app(TaskValidator::class)->validate($task);

    expect($validation['passed'])->toBeFalse()
        ->and($validation['checks']['task_verification'])->toBeFalse();
});
