<?php

use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Str;

function skillUiProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-skill-ui-'.Str::uuid(),
    ]);
}

test('an authenticated user can create a project skill with a deterministic slug', function () {
    $user = User::factory()->create();
    $project = skillUiProject('Skill creation project');

    $this->actingAs($user)
        ->post(route('projects.skills.store', $project), [
            'name' => 'Style Guide',
            'description' => 'House coding style.',
            'instructions' => 'Prefer explicit return types.',
            'applicable_roles' => ['coder'],
            'enabled' => true,
        ])
        ->assertRedirect(route('projects.show', $project));

    $skill = Skill::query()->where('project_id', $project->id)->where('name', 'Style Guide')->sole();

    expect($skill->slug)->toBe('style-guide')
        ->and($skill->applicable_roles)->toBe(['coder'])
        ->and($skill->version)->toBe(1);
});

test('creating a skill without instructions is rejected', function () {
    $user = User::factory()->create();
    $project = skillUiProject('Skill validation project');

    $this->actingAs($user)
        ->post(route('projects.skills.store', $project), [
            'name' => 'Missing Instructions',
            'applicable_roles' => [],
        ])
        ->assertSessionHasErrors('instructions');
});

test('updating a skill increments its version', function () {
    $user = User::factory()->create();
    $project = skillUiProject('Skill version project');
    $skill = Skill::factory()->for($project)->create(['name' => 'Original', 'slug' => 'original']);

    $this->actingAs($user)
        ->patch(route('projects.skills.update', [$project, $skill]), [
            'name' => 'Original',
            'instructions' => 'Updated instructions.',
            'applicable_roles' => [],
            'enabled' => true,
        ])
        ->assertRedirect(route('projects.show', $project));

    expect($skill->refresh()->version)->toBe(2)
        ->and($skill->instructions)->toBe('Updated instructions.');
});

test('deleting a skill does not destroy historical run configuration snapshots', function () {
    $user = User::factory()->create();
    $project = skillUiProject('Skill deletion project');
    $skill = Skill::factory()->for($project)->create(['name' => 'Removable', 'slug' => 'removable']);

    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => 'coder',
        'status' => 'completed',
        'prompt_hash' => hash('sha256', 'evidence'),
        'context_schema_version' => 1,
        'configuration_snapshot' => [
            'context_schema_version' => 1,
            'context_hash' => hash('sha256', 'snapshot'),
            'agent' => ['id' => 1, 'name' => 'Coder', 'role' => 'coder', 'configuration_version' => 1],
            'skills' => [['id' => $skill->id, 'slug' => $skill->slug, 'version' => $skill->version, 'position' => 0]],
        ],
        'started_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete(route('projects.skills.destroy', [$project, $skill]))
        ->assertRedirect(route('projects.show', $project));

    expect(Skill::query()->find($skill->id))->toBeNull()
        ->and($run->refresh()->configuration_snapshot['skills'][0]['slug'])->toBe('removable');
});

test('a skill from another project cannot be updated', function () {
    $user = User::factory()->create();
    $project = skillUiProject('Skill isolation project');
    $otherProject = skillUiProject('Skill isolation other project');
    $skill = Skill::factory()->for($otherProject)->create();

    $this->actingAs($user)
        ->patch(route('projects.skills.update', [$project, $skill]), [
            'name' => $skill->name,
            'instructions' => 'Attempted cross project edit.',
            'applicable_roles' => [],
            'enabled' => true,
        ])
        ->assertNotFound();
});
