<?php

use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('project skills payload exposes metadata required by the skills management console', function () {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'name' => 'Skills management console',
        'path' => sys_get_temp_dir().'/aios-skills-ui-'.Str::uuid(),
    ]);

    $skill = Skill::factory()
        ->for($project)
        ->create([
            'name' => 'Acceptance Criteria Engineering',
            'slug' => 'acceptance-criteria-engineering',
            'description' => 'Initial acceptance criteria guidance.',
            'instructions' => 'Produce independently verifiable acceptance criteria.',
            'constraints' => 'Do not expand approved task scope.',
            'applicable_roles' => ['coder', 'reviewer'],
            'enabled' => true,
        ]);

    $skill->update([
        'description' => 'Produce precise acceptance criteria that Coder and Reviewer can independently verify.',
    ]);

    $skill->refresh();

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->has('project.skills', 1)
            ->where('project.skills.0.id', $skill->id)
            ->where('project.skills.0.name', 'Acceptance Criteria Engineering')
            ->where('project.skills.0.slug', 'acceptance-criteria-engineering')
            ->where(
                'project.skills.0.description',
                'Produce precise acceptance criteria that Coder and Reviewer can independently verify.',
            )
            ->where(
                'project.skills.0.instructions',
                'Produce independently verifiable acceptance criteria.',
            )
            ->where(
                'project.skills.0.constraints',
                'Do not expand approved task scope.',
            )
            ->where(
                'project.skills.0.applicable_roles',
                ['coder', 'reviewer'],
            )
            ->where('project.skills.0.enabled', true)
            ->where('project.skills.0.version', 2)
            ->where(
                'project.skills.0.created_at',
                $skill->created_at->toJSON(),
            )
            ->where(
                'project.skills.0.updated_at',
                $skill->updated_at->toJSON(),
            ));
});
