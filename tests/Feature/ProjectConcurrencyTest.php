<?php

use App\Actions\CreateProject;
use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;

/**
 * Create a running Project fixture with its default provisioned Coder worker slot.
 */
function coderConcurrencyProject(string $name): Project
{
    $project = Project::create([
        'name' => $name,
        'path' => '/tmp/coder-concurrency-'.fake()->uuid(),
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

test('a new project defaults to Coder concurrency one', function () {
    $project = app(CreateProject::class)->handle('Default concurrency', 'default-concurrency-'.fake()->uuid());

    expect($project->refresh()->coder_concurrency)->toBe(1)
        ->and($project->coderConcurrency())->toBe(1);
});

test('an authenticated user can raise Coder concurrency to two and it provisions the second slot', function () {
    $project = coderConcurrencyProject('Raise concurrency');

    $this->actingAs(User::factory()->create())
        ->patch(route('projects.coder-concurrency.update', $project), ['coder_concurrency' => 2])
        ->assertRedirect(route('projects.show', $project));

    expect($project->refresh()->coder_concurrency)->toBe(2)
        ->and(
            $project->workers()
                ->where('role', AgentRole::Coder)
                ->where('slot', 2)
                ->exists(),
        )->toBeTrue()
        ->and($project->auditEvents()->where('event_type', 'project.coder_concurrency_updated')->exists())->toBeTrue();
});

test('lowering Coder concurrency back to one does not delete or interrupt the second slot worker', function () {
    $project = coderConcurrencyProject('Lower concurrency');
    $project->update(['coder_concurrency' => 2]);
    $secondSlot = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'slot' => 2,
        'status' => 'working',
    ]);

    $this->actingAs(User::factory()->create())
        ->patch(route('projects.coder-concurrency.update', $project), ['coder_concurrency' => 1])
        ->assertRedirect(route('projects.show', $project));

    expect($project->refresh()->coder_concurrency)->toBe(1)
        ->and($secondSlot->refresh()->exists)->toBeTrue()
        ->and($secondSlot->status)->toBe('working');
});
