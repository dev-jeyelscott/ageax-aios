<?php

use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Services\CoderRepositoryGuard;
use App\Services\DatabaseProtectionGuard;
use App\Services\ProjectGitState;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskContractGuard;
use App\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        config()->set('aios.workspace_root', sys_get_temp_dir());
        config()->set(
            'aios.obsidian_vault_path',
            sys_get_temp_dir().'/ageax-aios-obsidian-'.fake()->uuid(),
        );

        /**
         * DatabaseProtectionGuard requires a verified, freshly created recovery point before any
         * protected execution, which is real production disaster-recovery behavior that would
         * otherwise force every Coder/Reviewer/Project Manager/Recovery Engineer test to also stand
         * up a real backupable database. Feature tests that are not themselves testing the guard
         * get a permissive no-op stub by default (mirroring the CoderRepositoryGuard override
         * below); tests exercising the guard itself rebind the real service explicitly.
         */
        app()->bind(DatabaseProtectionGuard::class, function (): DatabaseProtectionGuard {
            return new class extends DatabaseProtectionGuard
            {
                public function __construct() {}

                public function guard(?Project $project = null): void {}
            };
        });

        app()->bind(CoderRepositoryGuard::class, function ($app): CoderRepositoryGuard {
            return new class($app->make(ProjectGitState::class)) extends CoderRepositoryGuard
            {
                public function inspect(Task $task): array
                {
                    $task->loadMissing('project');

                    if (is_dir($task->project->path.'/.git')) {
                        return parent::inspect($task);
                    }

                    return [
                        'allowed' => true,
                        'mode' => 'normal',
                        'base_sha' => 'test-base-sha',
                        'recovery_attempt' => null,
                        'state' => [
                            'inspectable' => true,
                            'clean' => true,
                            'head_sha' => null,
                            'base_sha' => 'test-base-sha',
                            'staged_files' => [],
                            'unstaged_files' => [],
                            'untracked_files' => [],
                            'errors' => [],
                        ],
                    ];
                }
            };
        });

        /**
         * Feature tests often create completed review attempts directly instead of running the
         * Coder action. Mirror the production invariant that every reviewable Coder attempt has
         * immutable task-contract evidence, without weakening the runtime missing-baseline gate.
         */
        TaskAttempt::creating(function (TaskAttempt $attempt): void {
            if ($attempt->getAttribute('status') !== 'completed') {
                return;
            }

            $taskId = $attempt->getAttribute('task_id');

            if (! is_int($taskId)) {
                return;
            }

            $task = Task::query()->find($taskId);

            if ($task === null) {
                return;
            }

            $status = TaskStatus::from((string) $task->getRawOriginal('status'));

            if (! in_array($status, [TaskStatus::ReadyForReview, TaskStatus::Reviewing], true)) {
                return;
            }

            $validationResults = $attempt->getAttribute('validation_results');
            $validationResults = is_array($validationResults) ? $validationResults : [];

            if (is_array($validationResults['task_contract'] ?? null)) {
                return;
            }

            $context = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Reviewer);
            $validationResults['task_contract'] = app(TaskContractGuard::class)->evidence($task, $context);
            $attempt->setAttribute('validation_results', $validationResults);
        });
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you may need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use.
| Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
