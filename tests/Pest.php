<?php

use App\Models\Task;
use App\Services\CoderRepositoryGuard;
use App\Services\ProjectGitState;
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
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you may need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
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

