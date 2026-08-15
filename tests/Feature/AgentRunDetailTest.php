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
        'live_output' => implode("\n", [
            '{"type":"item.completed","item":{"type":"command_execution","command":"php artisan test"}}',
            '{"type":"item.completed","item":{"type":"agent_message","text":"Inspecting the roadmap."}}',
        ]),
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.agent-runs.show', [$project, $run]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/agent-runs/show')
            ->where('agent_run.id', $run->id)
            ->where('agent_run.agent_messages', ['Inspecting the roadmap.'])
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

test('the run detail page exposes the immutable configuration snapshot as evidence', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Snapshot Evidence', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'implement'),
        'harness' => 'codex',
        'context_schema_version' => 1,
        'external_run_id' => 'ext-run-123',
        'configuration_snapshot' => [
            'context_schema_version' => 1,
            'context_hash' => hash('sha256', 'snapshot'),
            'agent' => ['id' => 1, 'name' => 'Coder', 'role' => 'coder', 'harness' => 'codex', 'model' => null, 'reasoning_setting' => null, 'configuration_version' => 3],
            'skills' => [['id' => 1, 'slug' => 'style', 'name' => 'Style', 'version' => 2, 'position' => 0]],
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.agent-runs.show', [$project, $run]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/agent-runs/show')
            ->where('agent_run.external_run_id', 'ext-run-123')
            ->where('agent_run.context_schema_version', 1)
            ->where('agent_run.configuration_snapshot.agent.configuration_version', 3)
            ->where('agent_run.configuration_snapshot.skills.0.slug', 'style'));
});

test('a legacy run without a snapshot is rendered without evidence', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Legacy Evidence', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'implement'),
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('projects.agent-runs.show', [$project, $run]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/agent-runs/show')
            ->where('agent_run.configuration_snapshot', null)
            ->where('agent_run.context_schema_version', null));
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
