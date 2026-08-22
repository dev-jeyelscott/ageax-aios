<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Jobs\RunWorkflowRecoveryEngineerNow;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\User;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Resolve a specific global Agent without relying on row ordering.
 */
function p5001ControllerAgent(AgentRole $role): Agent
{
    return Agent::query()
        ->whereNull('project_id')
        ->where('role', $role)
        ->sole();
}

test('the agents index exposes both singleton global Agents by role', function () {
    $recoveryAgent = p5001ControllerAgent(AgentRole::RecoveryEngineer);
    $orchestrator = p5001ControllerAgent(AgentRole::Orchestrator);

    $project = Project::create([
        'name' => 'Example',
        'path' => '/tmp/example-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $incident = RecoveryIncident::create([
        'project_id' => $project->id,
        'failure_type' => 'task.blocked_dirty_repository',
        'status' => RecoveryIncidentStatus::Diagnosing,
        'detected_at' => now(),
    ]);

    $run = AgentRun::create([
        'project_id' => $project->id,
        'agent_id' => $recoveryAgent->id,
        'recovery_incident_id' => $incident->id,
        'role' => AgentRole::RecoveryEngineer,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'diagnose'),
        'started_at' => now()->subSeconds(4),
        'finished_at' => now(),
    ]);

    $auditEvent = AuditEvent::create([
        'project_id' => $project->id,
        'event_type' => 'recovery.scan.completed',
        'payload' => [
            'agent_id' => $recoveryAgent->id,
        ],
        'occurred_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('agents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/index')
            ->where('agents', function ($agents) use (
                $orchestrator,
                $recoveryAgent,
                $run,
            ): bool {
                $byRole = collect($agents)->keyBy('role');
                $orchestratorPayload = $byRole->get('orchestrator');
                $recoveryPayload = $byRole->get('recovery_engineer');

                return is_array($orchestratorPayload)
                    && is_array($recoveryPayload)
                    && $byRole->count() === 2
                    && ($orchestratorPayload['id'] ?? null) === $orchestrator->id
                    && ($orchestratorPayload['name'] ?? null) === 'AIOS Global Orchestrator'
                    && ($orchestratorPayload['runtime_status'] ?? null) === 'idle'
                    && ($orchestratorPayload['open_incident_count'] ?? null) === 0
                    && ($recoveryPayload['id'] ?? null) === $recoveryAgent->id
                    && ($recoveryPayload['name'] ?? null) === 'AIOS Workflow Recovery Engineer'
                    && ($recoveryPayload['open_incident_count'] ?? null) === 1
                    && ($recoveryPayload['runtime_status'] ?? null) === 'working'
                    && data_get($recoveryPayload, 'latest_run.id') === $run->id
                    && data_get($recoveryPayload, 'latest_run.status') === 'completed'
                    && data_get($recoveryPayload, 'recent_activity.0.id') === $run->id
                    && data_get($recoveryPayload, 'recent_activity.0.duration_seconds') === 4;
            })
            ->where('system.total_agents', 2)
            ->where('system.enabled_agents', 2)
            ->where('system.open_incidents', 1)
            ->where('system.active_recoveries', 1)
            ->where('active_incidents.0.id', $incident->id)
            ->where('active_incidents.0.status', 'diagnosing')
            ->where('recent_events.0.id', $auditEvent->id)
            ->where('recent_events.0.event_type', 'recovery.scan.completed')
            ->has('generated_at'));
});

test('a project agent is never resolved through the global agent show route', function () {
    $agent = Agent::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('agents.show', $agent))
        ->assertNotFound();
});

test('the Orchestrator uses the shared global Agent detail flow without recovery runtime state', function () {
    $agent = p5001ControllerAgent(AgentRole::Orchestrator);

    $this->actingAs(User::factory()->create())
        ->get(route('agents.show', $agent))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/show')
            ->where('agent.id', $agent->id)
            ->where('agent.role', AgentRole::Orchestrator->value)
            ->where('agent.invoke_in_progress', false)
            ->where('incidents.data', []));
});

test('updating the Orchestrator bumps its configuration version and preserves global identity', function () {
    $agent = p5001ControllerAgent(AgentRole::Orchestrator);

    $this->actingAs(User::factory()->create())
        ->patch(route('agents.update', $agent), [
            'name' => $agent->name,
            'harness' => $agent->getRawOriginal('harness'),
            'model' => $agent->model,
            'reasoning_setting' => $agent->reasoning_setting,
            'default_context' => 'Advisory Orchestrator context.',
            'enabled' => true,
        ])
        ->assertRedirect(route('agents.show', $agent));

    $agent->refresh();

    expect($agent->default_context)->toBe('Advisory Orchestrator context.')
        ->and($agent->configuration_version)->toBe(2)
        ->and($agent->role)->toBe(AgentRole::Orchestrator)
        ->and($agent->project_id)->toBeNull();

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'agent.updated',
    ]);
});

test('disabling the Orchestrator records an agent.disabled audit event', function () {
    $agent = p5001ControllerAgent(AgentRole::Orchestrator);

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

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'agent.disabled',
    ]);

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

test('a global Recovery Engineer run console renders for its own run', function () {
    $agent = p5001ControllerAgent(AgentRole::RecoveryEngineer);

    $project = Project::create([
        'name' => 'Example',
        'path' => '/tmp/example-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

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
            ->where('agent_run.agent_messages', [
                'Diagnosed the incident.',
            ]));
});

test('invoking the Recovery Engineer dispatches the existing manual recovery job', function () {
    Queue::fake();

    $agent = p5001ControllerAgent(AgentRole::RecoveryEngineer);

    $this->actingAs(User::factory()->create())
        ->post(route('agents.invoke', $agent))
        ->assertRedirect(route('agents.show', $agent));

    Queue::assertPushed(
        RunWorkflowRecoveryEngineerNow::class,
        fn (RunWorkflowRecoveryEngineerNow $job): bool => $job->agentId === $agent->id,
    );

    $this->assertDatabaseHas('audit_events', [
        'event_type' => 'agent.invoke_requested',
    ]);
});

test('the Orchestrator cannot be invoked through the Recovery Engineer route', function () {
    Queue::fake();

    $agent = p5001ControllerAgent(AgentRole::Orchestrator);

    $this->actingAs(User::factory()->create())
        ->post(route('agents.invoke', $agent))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('a disabled Recovery Engineer cannot be invoked', function () {
    Queue::fake();

    $agent = p5001ControllerAgent(AgentRole::RecoveryEngineer);
    $agent->update(['enabled' => false]);

    $this->actingAs(User::factory()->create())
        ->post(route('agents.invoke', $agent))
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

test('a Recovery Engineer already diagnosing an incident cannot be invoked again', function () {
    Queue::fake();

    $agent = p5001ControllerAgent(AgentRole::RecoveryEngineer);

    $project = Project::create([
        'name' => 'Example',
        'path' => '/tmp/example-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    RecoveryIncident::create([
        'project_id' => $project->id,
        'failure_type' => 'task_blocked',
        'status' => RecoveryIncidentStatus::Diagnosing,
        'detected_at' => now(),
    ]);

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
    $agent = p5001ControllerAgent(AgentRole::RecoveryEngineer);
    $otherAgent = Agent::factory()->create();

    $project = Project::create([
        'name' => 'Example',
        'path' => '/tmp/example-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $run = AgentRun::create([
        'project_id' => $project->id,
        'agent_id' => $otherAgent->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'implement'),
        'started_at' => now(),
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('agents.runs.show', [$agent, $run]))
        ->assertNotFound();
});
