<?php

use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\ProjectStatus;
use Illuminate\Support\Str;

function agentUiProject(string $name, ProjectStatus $status = ProjectStatus::Paused): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-agent-ui-'.Str::uuid(),
        'status' => $status,
    ]);
}

test('an authenticated user can create a project agent with a supported harness configuration', function () {
    $user = User::factory()->create();
    $project = agentUiProject('Agent creation project');

    $this->actingAs($user)
        ->post(route('projects.agents.store', $project), [
            'name' => 'Second Coder',
            'role' => 'coder',
            'harness' => 'claude_code',
            'model' => 'claude-sonnet-5',
            'reasoning_setting' => 'high',
            'default_context' => 'Prefer small diffs.',
            'enabled' => true,
        ])
        ->assertRedirect(route('projects.show', $project));

    $agent = Agent::query()->where('project_id', $project->id)->where('name', 'Second Coder')->sole();

    expect($agent->role->value)->toBe('coder')
        ->and($agent->harness->value)->toBe('claude_code')
        ->and($agent->getRawOriginal('model'))->toBe('claude-sonnet-5')
        ->and($agent->getRawOriginal('reasoning_setting'))->toBe('high')
        ->and($agent->configuration_version)->toBe(1);
});

test('an unsupported model/harness combination cannot be submitted', function () {
    $user = User::factory()->create();
    $project = agentUiProject('Unsupported combination project');

    $this->actingAs($user)
        ->post(route('projects.agents.store', $project), [
            'name' => 'Bad Combo',
            'role' => 'coder',
            'harness' => 'claude_code',
            'model' => 'gpt-5.6-sol',
            'enabled' => true,
        ])
        ->assertSessionHasErrors('model');

    expect(Agent::query()->where('project_id', $project->id)->where('name', 'Bad Combo')->exists())->toBeFalse();
});

test('an unsupported reasoning setting for the selected model cannot be submitted', function () {
    $user = User::factory()->create();
    $project = agentUiProject('Unsupported reasoning project');

    $this->actingAs($user)
        ->post(route('projects.agents.store', $project), [
            'name' => 'Bad Reasoning',
            'role' => 'coder',
            'harness' => 'claude_code',
            'model' => 'claude-haiku-4-5-20251001',
            'reasoning_setting' => 'high',
            'enabled' => true,
        ])
        ->assertSessionHasErrors('reasoning_setting');
});

test('an enabled agent bound to a running workflow role cannot be disabled', function () {
    $user = User::factory()->create();
    $project = agentUiProject('Core role protection project', ProjectStatus::Running);
    $agent = Agent::factory()->for($project)->create(['role' => 'coder', 'name' => 'Bound Coder']);
    AgentWorker::create(['project_id' => $project->id, 'role' => 'coder', 'agent_id' => $agent->id, 'status' => 'idle']);

    $this->actingAs($user)
        ->patch(route('projects.agents.update', [$project, $agent]), [
            'name' => $agent->name,
            'role' => 'coder',
            'harness' => 'codex',
            'enabled' => false,
        ])
        ->assertSessionHasErrors('enabled');

    expect($agent->refresh()->enabled)->toBeTrue();
});

test('an agent not bound to a running role can be disabled', function () {
    $user = User::factory()->create();
    $project = agentUiProject('Unbound disable project', ProjectStatus::Running);
    $agent = Agent::factory()->for($project)->create(['role' => 'coder', 'name' => 'Unbound Coder']);

    $this->actingAs($user)
        ->patch(route('projects.agents.update', [$project, $agent]), [
            'name' => $agent->name,
            'role' => 'coder',
            'harness' => 'codex',
            'enabled' => false,
        ])
        ->assertRedirect(route('projects.show', $project));

    expect($agent->refresh()->enabled)->toBeFalse();
});

test('assigning, reordering, and unassigning skills updates deterministic pivot order', function () {
    $user = User::factory()->create();
    $project = agentUiProject('Skill assignment UI project');
    $agent = Agent::factory()->for($project)->create(['role' => 'coder']);
    $skillOne = Skill::factory()->for($project)->create(['name' => 'Skill One', 'slug' => 'skill-one']);
    $skillTwo = Skill::factory()->for($project)->create(['name' => 'Skill Two', 'slug' => 'skill-two']);

    $this->actingAs($user)
        ->post(route('projects.agents.skills.store', [$project, $agent]), ['skill_id' => $skillOne->id])
        ->assertRedirect();
    $this->post(route('projects.agents.skills.store', [$project, $agent]), ['skill_id' => $skillTwo->id])
        ->assertRedirect();

    expect($agent->refresh()->skills()->orderByPivot('position')->pluck('slug')->all())
        ->toBe(['skill-one', 'skill-two']);

    $this->patch(route('projects.agents.skills.reorder', [$project, $agent]), [
        'skill_ids' => [$skillTwo->id, $skillOne->id],
    ])->assertRedirect();

    expect($agent->skills()->orderByPivot('position')->pluck('slug')->all())
        ->toBe(['skill-two', 'skill-one']);

    $this->delete(route('projects.agents.skills.destroy', [$project, $agent, $skillOne]))
        ->assertRedirect();

    expect($agent->skills()->pluck('slug')->all())->toBe(['skill-two']);
});

test('binding a worker to an agent from another project is rejected', function () {
    $user = User::factory()->create();
    $project = agentUiProject('Worker binding project');
    $otherProject = agentUiProject('Other worker project');
    $agent = Agent::factory()->for($project)->create(['role' => 'coder']);
    $worker = AgentWorker::create(['project_id' => $otherProject->id, 'role' => 'coder', 'status' => 'idle']);

    $this->actingAs($user)
        ->patch(route('projects.agents.worker.update', [$project, $agent]), ['agent_worker_id' => $worker->id])
        ->assertSessionHasErrors('agent_worker_id');
});

test('binding a worker to a compatible agent succeeds', function () {
    $user = User::factory()->create();
    $project = agentUiProject('Worker binding success project');
    $agent = Agent::factory()->for($project)->create(['role' => 'coder']);
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => 'coder', 'status' => 'idle']);

    $this->actingAs($user)
        ->patch(route('projects.agents.worker.update', [$project, $agent]), ['agent_worker_id' => $worker->id])
        ->assertRedirect(route('projects.show', $project));

    expect($worker->refresh()->agent_id)->toBe($agent->id);
});
