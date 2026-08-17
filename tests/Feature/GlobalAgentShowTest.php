<?php

use App\Models\Agent;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\User;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('the global agent show page renders without error', function () {
    $agent = Agent::query()->whereNull('project_id')->first();

    $this->actingAs(User::factory()->create())
        ->get(route('agents.show', $agent))
        ->assertOk();
});

test('the global agent show page reports the agent as idle when no incident is being actively worked', function () {
    $agent = Agent::query()->whereNull('project_id')->first();

    $this->actingAs(User::factory()->create())
        ->get(route('agents.show', $agent))
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/show')
            ->where('agent.invoke_in_progress', false)
        );
});

test('the global agent show page reports the agent as in progress while an incident is being diagnosed', function () {
    $agent = Agent::query()->whereNull('project_id')->first();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    RecoveryIncident::create(['project_id' => $project->id, 'failure_type' => 'task_blocked', 'status' => RecoveryIncidentStatus::Diagnosing, 'detected_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->get(route('agents.show', $agent))
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/show')
            ->where('agent.invoke_in_progress', true)
        );
});

test('the global agent show page supplies harness capabilities for the configuration form', function () {
    $agent = Agent::query()->whereNull('project_id')->first();

    $this->actingAs(User::factory()->create())
        ->get(route('agents.show', $agent))
        ->assertInertia(fn (Assert $page) => $page
            ->component('agents/show')
            ->has('harness_capabilities.'.$agent->getRawOriginal('harness'))
        );
});
