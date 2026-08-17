<?php

use App\Models\Agent;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the global agent show page renders without error', function () {
    $agent = Agent::query()->whereNull('project_id')->first();

    $this->actingAs(User::factory()->create())
        ->get(route('agents.show', $agent))
        ->assertOk();
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
