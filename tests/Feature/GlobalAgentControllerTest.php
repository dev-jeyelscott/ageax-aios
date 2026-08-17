<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Jobs\RunWorkflowRecoveryEngineerNow;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\User;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('the agents index lists global agents with their open incident count', function () {
    $agent = Agent::query()->whereNull('project_id')->sole();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $incident = RecoveryIncident::create(['project_id' => $project->id, 'failure_type' => 'task.blocked_dirty_repository', 'status' => RecoveryIncidentStatus::Diagnosing, 'detected_at' => now()]);
    AgentRun::create(['project_id' => $project->id, 'agent_id' => $agent->id, 'recovery_incident_id' => $incident->id, 'role' => AgentRole::RecoveryEngineer, 'status' => AgentRunStatus::Completed, 'prompt_hash' => hash('sha256', 'diagnose'), 'started_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->get(route('agents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/index')
            ->where('agents.0.id', $agent->id)
            ->where('agents.0.open_incident_count', 1));
});

test('a project agent is never resolved through the global agent show route', function () {
    $agent = Agent::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('agents.show', $agent))
        ->assertNotFound();
});

test('updating a global agent bumps its configuration version and records an audit event', function () {
    $agent = Agent::query()->whereNull('project_id')->sole();

    $this->actingAs(User::factory()->create())
        ->patch(route('agents.update', $agent), [
            'name' => $agent->name,
            'harness' => $agent->getRawOriginal('harness'),
            'model' => 'claude-opus-5',
            'reasoning_setting' => null,
            'default_context' => null,
            'enabled' => true,
        ])
        ->assertRedirect(route('agents.show', $agent));

    $agent->refresh();

    expect($agent->model)->toBe('claude-opus-5')
        ->and($agent->configuration_version)->toBe(2);

    $this->assertDatabaseHas('audit_events', ['event_type' => 'agent.updated']);
});

test('disabling a global agent records an agent.disabled audit event', function () {
    $agent = Agent::query()->whereNull('project_id')->sole();

    $this->actingAs(User::factory()->create())
        ->patch(route('agents.update', $agent), [
            'name' => $agent->name,
            'harness' => $agent->getRawOriginal('harness'),
            'model' => $agent->model,
            'reasoning_setting' => $agent->reasoning_setting,
            'default_context' => $agent->default_context,
            'enabled' => false,
        ])
        ->assertRedirect(route('agents.show', $agent));

    $this->assertDatabaseHas('audit_events', ['event_type' => 'agent.disabled']);
    expect($agent->refresh()->enabled)->toBeFalse();
});

test('a project agent cannot be edited through the global agent update route', function () {
    $agent = Agent::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('agents.update', $agent), [
            'name' => $agent->name,
            'harness' => $agent->getRawOriginal('harness'),
            'model' => null,
            'reasoning_setting' => null,
            'default_context' => null,
            'enabled' => true,
        ])
        ->assertNotFound();
});

test('a global agent run console renders for its own run', function () {
    $agent = Agent::query()->whereNull('project_id')->sole();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'agent_id' => $agent->id,
        'role' => AgentRole::RecoveryEngineer,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'diagnose'),
        'live_output' => '{"type":"item.completed","item":{"type":"agent_message","text":"Diagnosed the incident."}}',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('agents.runs.show', [$agent, $run]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/run-show')
            ->where('agent.id', $agent->id)
            ->where('agent_run.id', $run->id)
            ->where('agent_run.agent_messages', ['Diagnosed the incident.']));
});

test('invoking a global agent dispatches a manual recovery scan and records an audit event', function () {
    Queue::fake();

    $agent = Agent::query()->whereNull('project_id')->sole();

    $this->actingAs(User::factory()->create())
        ->post(route('agents.invoke', $agent))
        ->assertRedirect(route('agents.show', $agent));

    Queue::assertPushed(RunWorkflowRecoveryEngineerNow::class, fn (RunWorkflowRecoveryEngineerNow $job): bool => $job->agentId === $agent->id);
    $this->assertDatabaseHas('audit_events', ['event_type' => 'agent.invoke_requested']);
});

test('a disabled global agent cannot be invoked', function () {
    Queue::fake();

    $agent = Agent::query()->whereNull('project_id')->sole();
    $agent->update(['enabled' => false]);

    $this->actingAs(User::factory()->create())
        ->post(route('agents.invoke', $agent))
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

test('a global agent already diagnosing an incident cannot be invoked again', function () {
    Queue::fake();

    $agent = Agent::query()->whereNull('project_id')->sole();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    RecoveryIncident::create(['project_id' => $project->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Diagnosing, 'detected_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->post(route('agents.invoke', $agent))
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

test('a project agent cannot be invoked through the global agent invoke route', function () {
    Queue::fake();

    $agent = Agent::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(route('agents.invoke', $agent))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('a run belonging to another agent cannot be viewed through this agent', function () {
    $agent = Agent::query()->whereNull('project_id')->sole();
    $otherAgent = Agent::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $run = AgentRun::create(['project_id' => $project->id, 'agent_id' => $otherAgent->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Completed, 'prompt_hash' => hash('sha256', 'implement'), 'started_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->get(route('agents.runs.show', [$agent, $run]))
        ->assertNotFound();
});
