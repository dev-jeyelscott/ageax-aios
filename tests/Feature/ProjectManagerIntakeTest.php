<?php

use App\Jobs\ProcessRoadmap;
use App\Models\Project;
use App\ProjectStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

test('a due running project captures its vault implementation roadmap once', function () {
    Queue::fake();
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $source = $vault.'/Projects/example/Roadmaps/Implementation Roadmap.md';
    File::ensureDirectoryExists(dirname($source));
    File::put($source, '# Implementation roadmap\n\nBuild the intake workflow.');

    $this->artisan('aios:project-manager --once')
        ->expectsOutput('Captured 1 roadmap(s).')
        ->assertExitCode(0);

    $roadmap = $project->roadmaps()->sole();

    expect($roadmap->source)->toBe('vault')
        ->and($roadmap->content_hash)->toBe(hash('sha256', '# Implementation roadmap\n\nBuild the intake workflow.'))
        ->and(File::files($vault.'/inbox'))->toHaveCount(1)
        ->and(File::files($vault.'/raw/sources'))->toHaveCount(1)
        ->and(File::get($vault.'/index.md'))->toContain('AIOS roadmap for Example')
        ->and(File::get($vault.'/log.md'))->toContain('Example roadmap')
        ->and($project->refresh()->roadmap_scanned_at)->not->toBeNull();
    Queue::assertPushed(ProcessRoadmap::class, fn (ProcessRoadmap $job): bool => $job->roadmapId === $roadmap->id);

    $this->artisan('aios:project-manager --once')
        ->expectsOutput('Captured 0 roadmap(s).')
        ->assertExitCode(0);

    expect($project->roadmaps()->count())->toBe(1);
});

test('a paused project is not scanned', function () {
    Queue::fake();
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    File::ensureDirectoryExists($vault.'/Projects/example/Roadmaps');
    File::put($vault.'/Projects/example/Roadmaps/Implementation Roadmap.md', '# Roadmap');

    $this->artisan('aios:project-manager --once')->assertExitCode(0);

    expect($project->roadmaps()->doesntExist())->toBeTrue();
    Queue::assertNothingPushed();
});
