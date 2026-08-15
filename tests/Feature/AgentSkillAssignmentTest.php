<?php

use App\Actions\AssignSkillToAgent;
use App\Actions\ReorderAgentSkills;
use App\Models\Agent;
use App\Models\AgentSkill;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

function createAgentSkillProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-agent-skill-test-'.Str::uuid(),
    ]);
}

test('a skill can be assigned to an agent in the same project with an auto-incremented position', function () {
    $project = createAgentSkillProject('Same project assignment');
    $agent = Agent::factory()->for($project)->create();
    $firstSkill = Skill::factory()->for($project)->create();
    $secondSkill = Skill::factory()->for($project)->create();

    $firstAssignment = app(AssignSkillToAgent::class)->handle($agent, $firstSkill);
    $secondAssignment = app(AssignSkillToAgent::class)->handle($agent, $secondSkill);

    expect($firstAssignment->position)->toBe(1)
        ->and($secondAssignment->position)->toBe(2)
        ->and($agent->skills()->whereKey($firstSkill->id)->exists())->toBeTrue()
        ->and($agent->skills()->whereKey($secondSkill->id)->exists())->toBeTrue();
});

test('assigning a skill from a different project is rejected', function () {
    $project = createAgentSkillProject('Owning project');
    $otherProject = createAgentSkillProject('Other project');
    $agent = Agent::factory()->for($project)->create();
    $skill = Skill::factory()->for($otherProject)->create();

    expect(fn () => app(AssignSkillToAgent::class)->handle($agent, $skill))
        ->toThrow(LogicException::class, 'Skill must belong to the same project as the agent.');

    expect(AgentSkill::query()->count())->toBe(0);
});

test('duplicate skill assignment is rejected by the action and the database', function () {
    $project = createAgentSkillProject('Duplicate assignment project');
    $agent = Agent::factory()->for($project)->create();
    $skill = Skill::factory()->for($project)->create();

    app(AssignSkillToAgent::class)->handle($agent, $skill);

    expect(fn () => app(AssignSkillToAgent::class)->handle($agent, $skill))
        ->toThrow(LogicException::class, 'This skill is already assigned to the agent.');

    expect(fn () => AgentSkill::query()->create([
        'agent_id' => $agent->id,
        'skill_id' => $skill->id,
        'position' => 99,
    ]))->toThrow(QueryException::class);
});

test('a skill may be assigned to multiple agents within the same project', function () {
    $project = createAgentSkillProject('Multi agent assignment');
    $firstAgent = Agent::factory()->for($project)->create(['name' => 'Agent One']);
    $secondAgent = Agent::factory()->for($project)->create(['name' => 'Agent Two']);
    $skill = Skill::factory()->for($project)->create();

    app(AssignSkillToAgent::class)->handle($firstAgent, $skill);
    app(AssignSkillToAgent::class)->handle($secondAgent, $skill);

    expect($skill->agents()->whereKey($firstAgent->id)->exists())->toBeTrue()
        ->and($skill->agents()->whereKey($secondAgent->id)->exists())->toBeTrue();
});

test('agent skills are retrieved in deterministic position order', function () {
    $project = createAgentSkillProject('Deterministic order project');
    $agent = Agent::factory()->for($project)->create();
    $first = Skill::factory()->for($project)->create(['name' => 'First Skill', 'slug' => 'first-skill']);
    $second = Skill::factory()->for($project)->create(['name' => 'Second Skill', 'slug' => 'second-skill']);
    $third = Skill::factory()->for($project)->create(['name' => 'Third Skill', 'slug' => 'third-skill']);

    app(AssignSkillToAgent::class)->handle($agent, $second);
    app(AssignSkillToAgent::class)->handle($agent, $third);
    app(AssignSkillToAgent::class)->handle($agent, $first);

    expect($agent->skills()->pluck('skills.id')->all())->toBe([$second->id, $third->id, $first->id]);
});

test('reordering agent skills produces a new deterministic order', function () {
    $project = createAgentSkillProject('Reorder project');
    $agent = Agent::factory()->for($project)->create();
    $first = Skill::factory()->for($project)->create(['name' => 'First Skill', 'slug' => 'first-skill']);
    $second = Skill::factory()->for($project)->create(['name' => 'Second Skill', 'slug' => 'second-skill']);
    $third = Skill::factory()->for($project)->create(['name' => 'Third Skill', 'slug' => 'third-skill']);

    app(AssignSkillToAgent::class)->handle($agent, $first);
    app(AssignSkillToAgent::class)->handle($agent, $second);
    app(AssignSkillToAgent::class)->handle($agent, $third);

    app(ReorderAgentSkills::class)->handle($agent, [$third->id, $first->id, $second->id]);

    expect($agent->skills()->pluck('skills.id')->all())->toBe([$third->id, $first->id, $second->id]);
});

test('reordering rejects a set that does not match the agent current assignments', function () {
    $project = createAgentSkillProject('Invalid reorder project');
    $agent = Agent::factory()->for($project)->create();
    $first = Skill::factory()->for($project)->create();
    $second = Skill::factory()->for($project)->create();
    $unassigned = Skill::factory()->for($project)->create();

    app(AssignSkillToAgent::class)->handle($agent, $first);
    app(AssignSkillToAgent::class)->handle($agent, $second);

    expect(fn () => app(ReorderAgentSkills::class)->handle($agent, [$first->id, $unassigned->id]))
        ->toThrow(LogicException::class, "Reordering must include exactly the agent's currently assigned skills.");
});

test('disabled skills are excluded from the effective skill context but remain assigned', function () {
    $project = createAgentSkillProject('Disabled skill exclusion project');
    $agent = Agent::factory()->for($project)->create();
    $enabledSkill = Skill::factory()->for($project)->create();
    $disabledSkill = Skill::factory()->for($project)->create();

    app(AssignSkillToAgent::class)->handle($agent, $enabledSkill);
    app(AssignSkillToAgent::class)->handle($agent, $disabledSkill);

    $disabledSkill->update(['enabled' => false]);

    expect($agent->skills()->pluck('skills.id')->all())->toContain($disabledSkill->id)
        ->and($agent->effectiveSkills()->pluck('id')->all())->toBe([$enabledSkill->id]);
});

test('unassigning a skill removes only the pivot row', function () {
    $project = createAgentSkillProject('Unassign project');
    $agentOne = Agent::factory()->for($project)->create(['name' => 'Agent A']);
    $agentTwo = Agent::factory()->for($project)->create(['name' => 'Agent B']);
    $skill = Skill::factory()->for($project)->create();

    app(AssignSkillToAgent::class)->handle($agentOne, $skill);
    app(AssignSkillToAgent::class)->handle($agentTwo, $skill);

    $agentOne->skills()->detach($skill);

    expect($agentOne->skills()->whereKey($skill->id)->exists())->toBeFalse()
        ->and($agentTwo->skills()->whereKey($skill->id)->exists())->toBeTrue()
        ->and(Skill::query()->whereKey($skill->id)->exists())->toBeTrue();
});

test('deleting a project cascades to its skills and their agent assignments', function () {
    $project = createAgentSkillProject('Cascade delete project');
    $agent = Agent::factory()->for($project)->create();
    $skill = Skill::factory()->for($project)->create();

    app(AssignSkillToAgent::class)->handle($agent, $skill);

    $skillId = $skill->id;
    $assignmentExists = fn () => AgentSkill::query()->where('skill_id', $skillId)->exists();

    expect($assignmentExists())->toBeTrue();

    $project->delete();

    expect(Skill::query()->whereKey($skillId)->exists())->toBeFalse()
        ->and($assignmentExists())->toBeFalse();
});

test('deleting an agent removes only its own assignments, leaving the skill and other agents intact', function () {
    $project = createAgentSkillProject('Agent delete project');
    $agentToDelete = Agent::factory()->for($project)->create(['name' => 'Deletable Agent']);
    $otherAgent = Agent::factory()->for($project)->create(['name' => 'Surviving Agent']);
    $skill = Skill::factory()->for($project)->create();

    app(AssignSkillToAgent::class)->handle($agentToDelete, $skill);
    app(AssignSkillToAgent::class)->handle($otherAgent, $skill);

    $agentToDelete->delete();

    expect(Skill::query()->whereKey($skill->id)->exists())->toBeTrue()
        ->and($otherAgent->skills()->whereKey($skill->id)->exists())->toBeTrue()
        ->and(AgentSkill::query()->where('agent_id', $agentToDelete->id)->exists())->toBeFalse();
});

test('deleting a skill removes only its own assignments, leaving the agents intact', function () {
    $project = createAgentSkillProject('Skill delete project');
    $agent = Agent::factory()->for($project)->create();
    $skillToDelete = Skill::factory()->for($project)->create(['name' => 'Deletable Skill', 'slug' => 'deletable-skill']);
    $otherSkill = Skill::factory()->for($project)->create(['name' => 'Surviving Skill', 'slug' => 'surviving-skill']);

    app(AssignSkillToAgent::class)->handle($agent, $skillToDelete);
    app(AssignSkillToAgent::class)->handle($agent, $otherSkill);

    $skillToDelete->delete();

    expect(Agent::query()->whereKey($agent->id)->exists())->toBeTrue()
        ->and($agent->skills()->whereKey($otherSkill->id)->exists())->toBeTrue()
        ->and(AgentSkill::query()->where('skill_id', $skillToDelete->id)->exists())->toBeFalse();
});
