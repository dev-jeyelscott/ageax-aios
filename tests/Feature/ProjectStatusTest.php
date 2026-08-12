<?php

use App\Models\Project;
use App\Models\User;
use App\Models\VaultOrganizationRun;
use App\ProjectStatus;

test('an authenticated user can pause and resume a project', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);

    $this->actingAs(User::factory()->create())
        ->patch(route('projects.status.update', $project), ['status' => ProjectStatus::Running->value])
        ->assertRedirect(route('projects.show', $project));

    expect($project->refresh()->status)->toBe(ProjectStatus::Running)
        ->and($project->auditEvents()->where('event_type', 'project.status_changed')->exists())->toBeTrue();
});

test('a pause request completes through the graceful stopping state before workers claim new work', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);

    $this->actingAs(User::factory()->create())
        ->patch(route('projects.status.update', $project), ['status' => ProjectStatus::Paused->value])
        ->assertRedirect(route('projects.show', $project));

    expect($project->refresh()->status)->toBe(ProjectStatus::Stopping);

    $this->artisan('aios:work --once')->assertExitCode(0);

    expect($project->refresh()->status)->toBe(ProjectStatus::Paused)
        ->and($project->auditEvents()->where('event_type', 'project.status_changed')->count())->toBe(2);
});

test('a project dashboard exposes the global knowledge architect status', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    VaultOrganizationRun::create(['status' => 'completed', 'prompt_hash' => hash('sha256', 'organize'), 'token_usage' => 120, 'exit_code' => 0, 'started_at' => now(), 'finished_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->get(route('projects.show', $project))
        ->assertInertia(fn ($page) => $page
            ->component('projects/show')
            ->where('vault_organization.status', 'completed')
            ->where('vault_organization.token_usage', 120));
});

test('opening a different project records one durable project selection event', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('projects.show', $project))->assertSuccessful();
    $this->actingAs($user)->get(route('projects.show', $project))->assertSuccessful();

    expect($project->auditEvents()->where('event_type', 'project.selected')->count())->toBe(1);
});
