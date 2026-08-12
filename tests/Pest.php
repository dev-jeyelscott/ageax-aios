<?php

use App\Services\GitRepositoryInspector;
use App\Services\WorkspacePathResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\LegacyWorkflowGitRepositoryInspector;
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
        app()->bind(
            GitRepositoryInspector::class,
            fn ($app): LegacyWorkflowGitRepositoryInspector => new LegacyWorkflowGitRepositoryInspector($app->make(WorkspacePathResolver::class)),
        );
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods to assert different
| things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function cleanGitRepository(string $prefix = 'aios-test'): string
{
    $path = sys_get_temp_dir().'/'.$prefix.'-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/README.md', '# Test repository');
    Process::path($path)->run(['git', 'add', 'README.md']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);

    return $path;
}
