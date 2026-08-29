<?php

use App\Contracts\Context\ProjectResolutionMethod;
use App\Exceptions\ProjectResolutionFailed;
use App\Exceptions\UnsafeProjectPath;
use App\Models\Project;
use App\Services\ContextGatewayProjectResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Create one Git-backed repository directory under the configured workspace root, optionally
 * configuring a remote and/or committing a baseline file.
 */
function gatewayRepository(?string $remoteUrl = null, bool $withCommit = true): string
{
    $path = sys_get_temp_dir().'/aios-context-gateway-repo-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);

    if ($remoteUrl !== null) {
        Process::path($path)->run(['git', 'remote', 'add', 'origin', $remoteUrl]);
    }

    if ($withCommit) {
        File::put($path.'/baseline.txt', 'baseline');
        Process::path($path)->run(['git', 'add', 'baseline.txt']);
        Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);
    }

    return $path;
}

function gatewayProject(string $path, string $name = 'Context Gateway Project'): Project
{
    return Project::factory()->create(['name' => $name, 'path' => $path]);
}

test('ContextGatewayProjectResolution resolves the explicit AIOS Project ID first, ahead of any path', function () {
    $project = gatewayProject(gatewayRepository());
    $unrelated = gatewayRepository();

    $resolution = app(ContextGatewayProjectResolver::class)->resolve($project->id, $unrelated);

    expect($resolution->projectId)->toBe($project->id)
        ->and($resolution->method)->toBe(ProjectResolutionMethod::ExplicitProjectId);
});

test('ContextGatewayProjectResolution fails closed for an unregistered explicit Project ID', function () {
    expect(fn () => app(ContextGatewayProjectResolver::class)->resolve(999999, null))
        ->toThrow(ProjectResolutionFailed::class);
});

test('ContextGatewayProjectResolution resolves by canonical Git remote ahead of the workspace-path fallback', function () {
    $remote = 'https://git.example.test/team/service.git';
    $registeredPath = gatewayRepository($remote);
    $project = gatewayProject($registeredPath);

    $otherCheckout = gatewayRepository($remote);

    $resolution = app(ContextGatewayProjectResolver::class)->resolve(null, $otherCheckout);

    expect($resolution->projectId)->toBe($project->id)
        ->and($resolution->method)->toBe(ProjectResolutionMethod::CanonicalGitRemote);
});

test('ContextGatewayProjectResolution treats scp-like and https remotes to the same repository as equivalent', function () {
    $registeredPath = gatewayRepository('git@git.example.test:team/service.git');
    $project = gatewayProject($registeredPath);

    $otherCheckout = gatewayRepository('https://git.example.test/team/service');

    $resolution = app(ContextGatewayProjectResolver::class)->resolve(null, $otherCheckout);

    expect($resolution->projectId)->toBe($project->id)
        ->and($resolution->method)->toBe(ProjectResolutionMethod::CanonicalGitRemote);
});

test('ContextGatewayProjectResolution resolves a Git worktree to the same registered Project as its main checkout', function () {
    $mainPath = gatewayRepository();
    $project = gatewayProject($mainPath);

    $worktreePath = sys_get_temp_dir().'/aios-context-gateway-worktree-'.fake()->uuid();
    Process::path($mainPath)->run(['git', 'worktree', 'add', $worktreePath]);

    $resolution = app(ContextGatewayProjectResolver::class)->resolve(null, $worktreePath);

    expect($resolution->projectId)->toBe($project->id)
        ->and($resolution->method)->toBe(ProjectResolutionMethod::RegisteredRepositoryIdentity);
});

test('ContextGatewayProjectResolution resolves an exact registered path via the workspace-path fallback', function () {
    $path = sys_get_temp_dir().'/aios-context-gateway-plain-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    $project = gatewayProject($path);

    $resolution = app(ContextGatewayProjectResolver::class)->resolve(null, $path);

    expect($resolution->projectId)->toBe($project->id)
        ->and($resolution->method)->toBe(ProjectResolutionMethod::WorkspacePathFallback);
});

test('ContextGatewayProjectResolution never collides two registered repositories that share a folder name', function () {
    $basename = 'shared-name-'.fake()->uuid();
    $firstParent = sys_get_temp_dir().'/aios-context-gateway-a-'.fake()->uuid();
    $secondParent = sys_get_temp_dir().'/aios-context-gateway-b-'.fake()->uuid();
    File::ensureDirectoryExists($firstParent);
    File::ensureDirectoryExists($secondParent);

    $firstPath = $firstParent.'/'.$basename;
    File::moveDirectory(gatewayRepository(), $firstPath);
    $secondPath = $secondParent.'/'.$basename;
    File::moveDirectory(gatewayRepository(), $secondPath);

    $first = gatewayProject($firstPath, 'First shared-name project');
    $second = gatewayProject($secondPath, 'Second shared-name project');

    $resolver = app(ContextGatewayProjectResolver::class);

    expect($resolver->resolve(null, $firstPath)->projectId)->toBe($first->id)
        ->and($resolver->resolve(null, $secondPath)->projectId)->toBe($second->id);
});

test('ContextGatewayProjectResolution fails closed when two registered Projects share the same canonical Git remote', function () {
    $remote = 'https://git.example.test/team/ambiguous.git';
    gatewayProject(gatewayRepository($remote), 'Ambiguous remote A');
    gatewayProject(gatewayRepository($remote), 'Ambiguous remote B');

    $inbound = gatewayRepository($remote);

    expect(fn () => app(ContextGatewayProjectResolver::class)->resolve(null, $inbound))
        ->toThrow(ProjectResolutionFailed::class);
});

test('ContextGatewayProjectResolution fails closed for an unregistered repository directory', function () {
    $path = gatewayRepository();

    expect(fn () => app(ContextGatewayProjectResolver::class)->resolve(null, $path))
        ->toThrow(ProjectResolutionFailed::class);
});

test('ContextGatewayProjectResolution fails closed for a path traversal attempt', function () {
    expect(fn () => app(ContextGatewayProjectResolver::class)->resolve(null, sys_get_temp_dir().'/../etc'))
        ->toThrow(UnsafeProjectPath::class);
});

test('ContextGatewayProjectResolution fails closed for a symlink escaping the workspace root', function () {
    $outside = sys_get_temp_dir().'/aios-outside-'.fake()->uuid();
    File::ensureDirectoryExists($outside);
    config()->set('aios.workspace_root', sys_get_temp_dir().'/aios-workspace-'.fake()->uuid());
    File::ensureDirectoryExists(config('aios.workspace_root'));
    $symlink = config('aios.workspace_root').'/escape-link';
    symlink($outside, $symlink);

    try {
        expect(fn () => app(ContextGatewayProjectResolver::class)->resolve(null, $symlink))
            ->toThrow(UnsafeProjectPath::class);
    } finally {
        unlink($symlink);
    }
});

test('ContextGatewayProjectResolution fails closed for the AIOS installation path itself', function () {
    config()->set('aios.workspace_root', dirname(base_path()));

    expect(fn () => app(ContextGatewayProjectResolver::class)->resolve(null, base_path()))
        ->toThrow(UnsafeProjectPath::class);
});

test('ContextGatewayProjectResolution requires either an explicit Project ID or a repository path', function () {
    expect(fn () => app(ContextGatewayProjectResolver::class)->resolve(null, null))
        ->toThrow(ProjectResolutionFailed::class);
});

test('ContextGatewayProjectResolution fails closed for an explicit Project ID tied to an unsafe persisted path', function () {
    // Create a project with an unsafe path (AIOS installation itself).
    config()->set('aios.workspace_root', dirname(base_path()));
    $project = Project::factory()->create(['name' => 'Unsafe Project', 'path' => base_path()]);

    expect(fn () => app(ContextGatewayProjectResolver::class)->resolve($project->id, null))
        ->toThrow(UnsafeProjectPath::class);
});
