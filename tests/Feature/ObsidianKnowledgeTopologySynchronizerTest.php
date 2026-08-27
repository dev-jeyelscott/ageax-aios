<?php

use App\Models\Project;
use App\Services\ObsidianKnowledgeTopologySynchronizer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function topologyProject(string $name): Project
{
    $workspace = sys_get_temp_dir().'/ageax-aios-topology-'.Str::uuid();
    File::ensureDirectoryExists($workspace);
    config()->set('aios.workspace_root', $workspace);

    return Project::factory()->create([
        'name' => $name,
        'path' => $workspace.'/repository',
    ]);
}

function topologyDirectory(Project $project): string
{
    $vault = sys_get_temp_dir().'/ageax-aios-topology-vault-'.Str::uuid();
    File::ensureDirectoryExists($vault);
    config()->set('aios.obsidian_vault_path', $vault);

    return $vault.'/Projects/'.Str::slug($project->name);
}

afterEach(function (): void {
    $workspace = config('aios.workspace_root');
    $vault = config('aios.obsidian_vault_path');

    if (is_string($workspace)) {
        File::deleteDirectory($workspace);
    }

    if (is_string($vault)) {
        File::deleteDirectory($vault);
    }
});

test('it provisions root and nested navigation without changing human-authored content', function (): void {
    $project = topologyProject('Topology Project');
    $directory = topologyDirectory($project);
    File::ensureDirectoryExists($directory.'/Roadmaps');
    File::ensureDirectoryExists($directory.'/Decisions');
    File::put($directory.'/STATE.md', "# State\n\nHuman content remains exactly here.\n");
    File::put($directory.'/Roadmaps/Latest Upload.md', '# Roadmap');
    File::put($directory.'/Decisions/Architecture.md', '# Architecture');

    $result = app(ObsidianKnowledgeTopologySynchronizer::class)->sync($project);

    expect($result['created'])->toContain('index.md', 'AGENTS.md', 'Roadmaps/index.md', 'Roadmaps/AGENTS.md', 'Decisions/index.md', 'Decisions/AGENTS.md')
        ->and(File::get($directory.'/index.md'))->toContain('[[AGENTS.md]]', '[[STATE.md]]', '[[Roadmaps/index.md]]', '[[Decisions/index.md]]')
        ->and(File::get($directory.'/Roadmaps/index.md'))->toContain('[[index.md]]', '[[Roadmaps/AGENTS.md]]', '[[Roadmaps/Latest Upload.md]]')
        ->and(File::get($directory.'/STATE.md'))->toStartWith("# State\n\nHuman content remains exactly here.\n")
        ->and(File::get($directory.'/STATE.md'))->toContain('<!-- AIOS:BEGIN NAVIGATION -->', '[[index.md]]');
});

test('it is idempotent and creates reciprocal backlinks only for explicit human links', function (): void {
    $project = topologyProject('Backlinks Project');
    $directory = topologyDirectory($project);
    File::ensureDirectoryExists($directory.'/Notes');
    File::ensureDirectoryExists($directory.'/Decisions');
    File::put($directory.'/Notes/Source.md', "# Source\n\n[[Decisions/Target.md]]\n");
    File::put($directory.'/Decisions/Target.md', '# Target');

    $synchronizer = app(ObsidianKnowledgeTopologySynchronizer::class);
    $synchronizer->sync($project);
    $target = File::get($directory.'/Decisions/Target.md');
    $second = $synchronizer->sync($project);

    expect($target)->toContain('[[Notes/Source.md]]')
        ->and(File::get($directory.'/Notes/Source.md'))->not->toContain('Linked from: [[Decisions/Target.md]]')
        ->and($second['created'])->toBe([])
        ->and($second['changed'])->toBe([]);
});

test('it ignores hidden and symlink-escaped sources and keeps projects isolated', function (): void {
    $first = topologyProject('First Topology');
    $firstDirectory = topologyDirectory($first);
    File::ensureDirectoryExists($firstDirectory.'/Notes');
    File::ensureDirectoryExists($firstDirectory.'/.private');
    File::put($firstDirectory.'/Notes/Visible.md', '# Visible');
    File::put($firstDirectory.'/.private/Hidden.md', '# Hidden');

    $outside = sys_get_temp_dir().'/ageax-aios-topology-outside-'.Str::uuid();
    File::ensureDirectoryExists($outside);
    File::put($outside.'/Escape.md', '# Escape');
    @symlink($outside.'/Escape.md', $firstDirectory.'/Notes/Escape.md');

    $second = Project::factory()->create(['name' => 'Second Topology', 'path' => dirname($first->path).'/second-repository']);
    $secondDirectory = dirname($firstDirectory).'/'.Str::slug($second->name);
    File::ensureDirectoryExists($secondDirectory.'/Notes');
    File::put($secondDirectory.'/Notes/Second.md', '# Second');

    $synchronizer = app(ObsidianKnowledgeTopologySynchronizer::class);
    $synchronizer->sync($first);
    $synchronizer->sync($second);

    expect(File::exists($firstDirectory.'/.private/index.md'))->toBeFalse()
        ->and(File::get($firstDirectory.'/index.md'))->toContain('[[Notes/index.md]]')
        ->and(File::get($firstDirectory.'/Notes/index.md'))->not->toContain('Escape.md')
        ->and(File::get($secondDirectory.'/index.md'))->toContain('[[Notes/index.md]]')
        ->and(File::get($firstDirectory.'/index.md'))->not->toContain('Second.md');
});
