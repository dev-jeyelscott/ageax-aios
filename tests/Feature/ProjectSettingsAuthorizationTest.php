<?php

use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;

/**
 * Create a running Project fixture with its default provisioned Coder worker slot.
 */
function projectSettingsAuthorizationProject(string $name): Project
{
    $project = Project::create([
        'name' => $name,
        'path' => '/tmp/project-settings-authz-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'slot' => 1,
        'status' => 'idle',
    ]);

    return $project;
}

test('an out of range Coder concurrency value is rejected and does not persist', function () {
    $project = projectSettingsAuthorizationProject('Invalid concurrency');

    $this->actingAs(User::factory()->create())
        ->patch(route('projects.coder-concurrency.update', $project), ['coder_concurrency' => 3])
        ->assertSessionHasErrors('coder_concurrency');

    expect($project->refresh()->coder_concurrency)->toBe(1);
});

test('a non integer Coder concurrency value is rejected', function () {
    $project = projectSettingsAuthorizationProject('Non integer concurrency');

    $this->actingAs(User::factory()->create())
        ->patch(route('projects.coder-concurrency.update', $project), ['coder_concurrency' => 'two'])
        ->assertSessionHasErrors('coder_concurrency');

    expect($project->refresh()->coder_concurrency)->toBe(1);
});

test('an unauthenticated request cannot change Coder concurrency', function () {
    $project = projectSettingsAuthorizationProject('Unauthorized concurrency');

    $this->patch(route('projects.coder-concurrency.update', $project), ['coder_concurrency' => 2])
        ->assertRedirect(route('login'));

    expect($project->refresh()->coder_concurrency)->toBe(1);
});

test('an unauthenticated request cannot change the stewardship policy project setting', function () {
    $project = projectSettingsAuthorizationProject('Unauthorized stewardship');

    $this->patch(route('projects.stewardship-policy.update', $project), ['automatic_task_creation' => true])
        ->assertRedirect(route('login'));

    expect($project->refresh()->stewardship_policy)->toBeNull();
});
