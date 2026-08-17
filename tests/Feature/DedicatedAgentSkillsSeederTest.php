<?php

use App\Actions\AssignSkillToAgent;
use App\Actions\ProvisionDefaultProjectAgents;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentSkill;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Skill;
use Database\Seeders\DedicatedAgentSkillsSeeder;
use Illuminate\Support\Str;

function createDedicatedAgentSkillsSeederProject(string $name): Project
{
    $project = Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-dedicated-skills-'.Str::uuid(),
    ]);

    app(ProvisionDefaultProjectAgents::class)->handle($project);

    foreach ([AgentRole::ProjectManager, AgentRole::Coder, AgentRole::Reviewer] as $role) {
        $agent = $project->agents()
            ->where('role', $role)
            ->where('enabled', true)
            ->orderBy('id')
            ->firstOrFail();

        AgentWorker::query()->create([
            'project_id' => $project->id,
            'role' => $role,
            'agent_id' => $agent->id,
            'status' => 'idle',
        ]);
    }

    return $project;
}

test('dedicated agent skills seeder creates all role skills and assigns only the core daily set', function () {
    $project = createDedicatedAgentSkillsSeederProject('Dedicated Skills Project');

    $this->seed(DedicatedAgentSkillsSeeder::class);

    expect($project->skills()->count())->toBe(24)
        ->and($project->auditEvents()->where('event_type', 'skill.created')->count())->toBe(24);

    $expectedCoreSkills = [
        AgentRole::ProjectManager->value => [
            'pm-roadmap-decomposition',
            'pm-acceptance-criteria-engineering',
            'pm-dependency-scope-control',
            'pm-documentation-alignment',
            'pm-context-capsule-design',
        ],
        AgentRole::Coder->value => [
            'coder-repository-reconnaissance',
            'coder-minimal-production-ready-implementation',
            'coder-regression-test-engineering',
            'coder-deterministic-validation',
            'coder-git-change-isolation',
        ],
        AgentRole::Reviewer->value => [
            'reviewer-acceptance-criteria-verification',
            'reviewer-git-diff-review',
            'reviewer-architecture-consistency-review',
            'reviewer-actionable-finding-authoring',
            'reviewer-scope-discipline',
        ],
    ];

    foreach ($expectedCoreSkills as $roleValue => $expectedSlugs) {
        $agent = Agent::query()
            ->where('project_id', $project->id)
            ->where('role', $roleValue)
            ->firstOrFail();

        expect($agent->skills()->pluck('skills.slug')->all())
            ->toBe($expectedSlugs);
    }

    expect(
        AgentSkill::query()
            ->whereIn('agent_id', $project->agents()->pluck('id'))
            ->count(),
    )->toBe(15)
        ->and($project->auditEvents()->where('event_type', 'skill.assigned')->count())
        ->toBe(15);
});

test('rerunning dedicated agent skills seeder preserves operator edits and intentional unassignment', function () {
    $project = createDedicatedAgentSkillsSeederProject('Non Destructive Skills Project');

    $this->seed(DedicatedAgentSkillsSeeder::class);

    $projectManager = Agent::query()
        ->where('project_id', $project->id)
        ->where('role', AgentRole::ProjectManager)
        ->firstOrFail();

    $seededSkill = Skill::query()
        ->where('project_id', $project->id)
        ->where('slug', 'pm-roadmap-decomposition')
        ->firstOrFail();

    $seededSkill->update([
        'instructions' => 'Operator customized instructions.',
        'enabled' => false,
    ]);
    $seededSkill->refresh();

    $projectManager->skills()->detach($seededSkill->id);

    $customSkill = Skill::factory()->for($project)->create([
        'name' => 'Operator Custom Skill',
        'slug' => 'operator-custom-skill',
        'applicable_roles' => [AgentRole::ProjectManager->value],
    ]);

    app(AssignSkillToAgent::class)->handle($projectManager, $customSkill);

    $positionsBeforeRerun = AgentSkill::query()
        ->where('agent_id', $projectManager->id)
        ->orderBy('position')
        ->pluck('skill_id', 'position')
        ->all();

    $createdAuditCount = $project->auditEvents()
        ->where('event_type', 'skill.created')
        ->count();

    $assignedAuditCount = $project->auditEvents()
        ->where('event_type', 'skill.assigned')
        ->count();

    $this->seed(DedicatedAgentSkillsSeeder::class);

    $seededSkill->refresh();

    expect($project->skills()->count())->toBe(25)
        ->and($seededSkill->instructions)->toBe('Operator customized instructions.')
        ->and($seededSkill->enabled)->toBeFalse()
        ->and($seededSkill->version)->toBe(2)
        ->and($projectManager->skills()->whereKey($seededSkill->id)->exists())->toBeFalse()
        ->and($projectManager->skills()->whereKey($customSkill->id)->exists())->toBeTrue()
        ->and(
            AgentSkill::query()
                ->where('agent_id', $projectManager->id)
                ->orderBy('position')
                ->pluck('skill_id', 'position')
                ->all(),
        )->toBe($positionsBeforeRerun)
        ->and(
            $project->auditEvents()
                ->where('event_type', 'skill.created')
                ->count(),
        )->toBe($createdAuditCount)
        ->and(
            $project->auditEvents()
                ->where('event_type', 'skill.assigned')
                ->count(),
        )->toBe($assignedAuditCount);
});
