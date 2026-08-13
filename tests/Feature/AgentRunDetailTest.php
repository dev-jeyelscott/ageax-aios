<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\ProjectManagerMessage;
use App\Models\User;
use App\ProjectStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('an authenticated user can inspect a Project Manager run and send a message', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'plan'),
        'live_output' => 'Inspecting the roadmap.',
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.agent-runs.show', [$project, $run]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/agent-runs/show')
            ->where('agent_run.id', $run->id)
            ->where('agent_run.live_output', 'Inspecting the roadmap.')
            ->has('project_manager_messages', 0));

    $this->post(route('projects.project-manager-messages.store', $project), [
        'body' => 'Keep the roadmap scoped to the existing Laravel architecture.',
    ])->assertRedirect();

    $message = ProjectManagerMessage::query()->sole();

    expect($message->project_id)->toBe($project->id)
        ->and($message->user_id)->toBe($user->id)
        ->and($message->delivered_at)->toBeNull()
        ->and($project->auditEvents()->where('event_type', 'project_manager.message_recorded')->exists())->toBeTrue();
});

test('an agent run cannot be viewed through another project', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $otherProject = Project::create(['name' => 'Other', 'path' => '/tmp/other-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $run = AgentRun::create(['project_id' => $project->id, 'role' => AgentRole::ProjectManager, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'plan'), 'started_at' => now()]);

    $this->actingAs($user)
        ->get(route('projects.agent-runs.show', [$otherProject, $run]))
        ->assertNotFound();
});
