<?php

use App\Actions\DecideKnowledgeImprovementCandidate;
use App\Actions\PromoteGlobalKnowledgePattern;
use App\AgentRole;
use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\Agent;
use App\Models\AuditEvent;
use App\Models\GlobalKnowledgePattern;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Str;
use LogicException;

/**
 * Create one isolated project used by reusable-pattern feature coverage.
 */
function globalPatternProject(
    string $name = 'Reusable Pattern Source',
): Project {
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir()
            .'/aios-global-pattern-'
            .Str::uuid(),
    ]);
}

/**
 * Create an already approved candidate with attributable operator evidence.
 *
 * @param  array<string, mixed>  $overrides
 */
function approvedGlobalPatternCandidate(
    Project $project,
    User $decider,
    array $overrides = [],
): KnowledgeImprovementCandidate {
    return KnowledgeImprovementCandidate::factory()
        ->for($project)
        ->create(array_merge([
            'status' => KnowledgeImprovementCandidateStatus::Approved,
            'decided_by_user_id' => $decider->id,
            'decided_at' => now(),
            'target_type' => KnowledgeImprovementTarget::Documentation,
            'affected_role' => 'coder',
            'affected_area' => 'app/Services',
            'proposed_change' => 'Preserve deterministic application-owned state transitions and verify them with focused regression coverage.',
        ], $overrides));
}

/**
 * Return one bounded reusable-pattern promotion payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function globalPatternPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Deterministic Workflow Ownership',
        'category' => 'workflow',
        'applicable_roles' => ['coder', 'reviewer'],
        'validated_guidance' => 'Keep durable workflow transitions inside the application-owned domain boundary and verify the transition with focused regression coverage.',
    ], $overrides);
}

test('global patterns require a separate explicit promotion after candidate approval', function (): void {
    $project = globalPatternProject();
    $operator = User::factory()->create();

    $pending = KnowledgeImprovementCandidate::factory()
        ->for($project)
        ->create([
            'status' => KnowledgeImprovementCandidateStatus::Pending,
        ]);

    expect(GlobalKnowledgePattern::query()->count())->toBe(0);

    $this->actingAs($operator)
        ->post(
            route('projects.knowledge-improvements.promote', [
                'project' => $project,
                'candidate' => $pending,
            ]),
            globalPatternPayload(),
        )
        ->assertSessionHasErrors('promotion');

    expect(GlobalKnowledgePattern::query()->count())->toBe(0);

    $candidate = approvedGlobalPatternCandidate(
        $project,
        $operator,
    );

    expect(GlobalKnowledgePattern::query()->count())->toBe(0);

    $this->actingAs($operator)
        ->post(
            route('projects.knowledge-improvements.promote', [
                'project' => $project,
                'candidate' => $candidate,
            ]),
            globalPatternPayload(),
        )
        ->assertRedirect();

    $pattern = GlobalKnowledgePattern::query()->sole();

    expect($pattern->name)
        ->toBe('Deterministic Workflow Ownership')
        ->and($pattern->category)
        ->toBe('workflow')
        ->and($pattern->version)
        ->toBe(1)
        ->and($pattern->applicable_roles)
        ->toBe(['coder', 'reviewer'])
        ->and($pattern->source_project_id)
        ->toBe($project->id)
        ->and($pattern->source_candidate_id)
        ->toBe($candidate->id)
        ->and($pattern->source_evidence_hash)
        ->toBe($candidate->evidence_hash)
        ->and($pattern->approved_by_user_id)
        ->toBe($operator->id)
        ->and($pattern->enabled)
        ->toBeTrue()
        ->and($pattern->superseded_at)
        ->toBeNull();

    expect($pattern->source_evidence)->toBe([
        'candidate_id' => $candidate->id,
        'project_id' => $project->id,
        'fingerprint' => $candidate->fingerprint,
        'source_kind' => $candidate->source_kind,
        'evidence_hash' => $candidate->evidence_hash,
        'knowledge_architect_agent_run_id' => null,
        'knowledge_architect_evidence_hash' => null,
    ]);

    expect(array_keys($pattern->source_evidence))
        ->not->toContain('evidence')
        ->not->toContain('evidence_summary')
        ->not->toContain('proposed_change');

    $audit = AuditEvent::query()
        ->where('project_id', $project->id)
        ->where('event_type', 'knowledge.pattern_promoted')
        ->sole();

    expect($audit->payload)->toMatchArray([
        'global_knowledge_pattern_id' => $pattern->id,
        'candidate_id' => $candidate->id,
        'source_project_id' => $project->id,
        'source_evidence_hash' => $candidate->evidence_hash,
        'version' => 1,
        'approved_by_user_id' => $operator->id,
    ]);

    expect(array_keys($audit->payload))
        ->not->toContain('validated_guidance')
        ->not->toContain('evidence_summary')
        ->not->toContain('evidence');
});

test('rejected and dismissed candidates cannot be promoted globally', function (): void {
    $project = globalPatternProject();
    $operator = User::factory()->create();

    foreach ([
        KnowledgeImprovementCandidateStatus::Rejected,
        KnowledgeImprovementCandidateStatus::Dismissed,
    ] as $status) {
        $candidate = KnowledgeImprovementCandidate::factory()
            ->for($project)
            ->create([
                'status' => $status,
                'decided_by_user_id' => $operator->id,
                'decided_at' => now(),
            ]);

        expect(
            fn () => app(PromoteGlobalKnowledgePattern::class)->handle(
                $candidate,
                $operator,
                globalPatternPayload(),
            ),
        )->toThrow(LogicException::class);
    }

    expect(GlobalKnowledgePattern::query()->count())->toBe(0);
});

test('candidate promotion remains strictly scoped to its route project', function (): void {
    $sourceProject = globalPatternProject('Source Reusable Project');
    $otherProject = globalPatternProject('Other Reusable Project');
    $operator = User::factory()->create();

    $candidate = approvedGlobalPatternCandidate(
        $sourceProject,
        $operator,
    );

    $this->actingAs($operator)
        ->post(
            route('projects.knowledge-improvements.promote', [
                'project' => $otherProject,
                'candidate' => $candidate,
            ]),
            globalPatternPayload(),
        )
        ->assertNotFound();

    expect(GlobalKnowledgePattern::query()->count())->toBe(0);
});

test('repeated promotion of the same candidate evidence is idempotent', function (): void {
    $project = globalPatternProject();
    $operator = User::factory()->create();

    $candidate = approvedGlobalPatternCandidate(
        $project,
        $operator,
    );

    $promoter = app(PromoteGlobalKnowledgePattern::class);

    $first = $promoter->handle(
        $candidate,
        $operator,
        globalPatternPayload(),
    );

    $second = $promoter->handle(
        $candidate,
        $operator,
        globalPatternPayload(),
    );

    expect($second->id)
        ->toBe($first->id)
        ->and(GlobalKnowledgePattern::query()->count())
        ->toBe(1)
        ->and(
            AuditEvent::query()
                ->where('event_type', 'knowledge.pattern_promoted')
                ->count(),
        )
        ->toBe(1);
});

test('invalid roles malformed guidance secrets and project specific material are rejected', function (): void {
    $project = globalPatternProject('Private Atlas Application');
    $operator = User::factory()->create();

    $candidate = approvedGlobalPatternCandidate(
        $project,
        $operator,
    );

    $this->actingAs($operator)
        ->post(
            route('projects.knowledge-improvements.promote', [
                'project' => $project,
                'candidate' => $candidate,
            ]),
            globalPatternPayload([
                'applicable_roles' => ['orchestrator'],
                'validated_guidance' => '',
            ]),
        )
        ->assertSessionHasErrors([
            'applicable_roles.0',
            'validated_guidance',
        ]);

    $this->actingAs($operator)
        ->post(
            route('projects.knowledge-improvements.promote', [
                'project' => $project,
                'candidate' => $candidate,
            ]),
            globalPatternPayload([
                'validated_guidance' => 'Authorization: Bearer ghp_abcdefghijklmnopqrstuvwxyz1234567890',
            ]),
        )
        ->assertSessionHasErrors('promotion');

    $this->actingAs($operator)
        ->post(
            route('projects.knowledge-improvements.promote', [
                'project' => $project,
                'candidate' => $candidate,
            ]),
            globalPatternPayload([
                'validated_guidance' => 'Apply this specifically to Private Atlas Application.',
            ]),
        )
        ->assertSessionHasErrors('promotion');

    $this->actingAs($operator)
        ->post(
            route('projects.knowledge-improvements.promote', [
                'project' => $project,
                'candidate' => $candidate,
            ]),
            globalPatternPayload([
                'validated_guidance' => 'Inspect '.$project->path.' before applying the rule.',
            ]),
        )
        ->assertSessionHasErrors('promotion');

    expect(GlobalKnowledgePattern::query()->count())->toBe(0);
});

test('normal project Skill approval remains unchanged and global promotion never mutates Skills or assignments', function (): void {
    $project = globalPatternProject();
    $operator = User::factory()->create();

    $skill = Skill::factory()
        ->for($project)
        ->create([
            'name' => 'Deterministic Validation',
            'slug' => 'coder-deterministic-validation',
            'instructions' => 'Run deterministic validation.',
            'applicable_roles' => ['coder'],
        ]);

    $agent = Agent::factory()
        ->for($project)
        ->create([
            'role' => AgentRole::Coder,
        ]);

    $agent->skills()->attach($skill->id, [
        'position' => 0,
    ]);

    $candidate = KnowledgeImprovementCandidate::factory()
        ->for($project)
        ->create([
            'target_skill_id' => $skill->id,
            'target_type' => KnowledgeImprovementTarget::Skill,
            'status' => KnowledgeImprovementCandidateStatus::Pending,
            'proposed_change' => 'Before completion, rerun the deterministic validation gate and persist successful evidence.',
        ]);

    app(DecideKnowledgeImprovementCandidate::class)->handle(
        $candidate,
        $operator,
        KnowledgeImprovementCandidateStatus::Approved,
    );

    $candidate->refresh();
    $skill->refresh();

    expect($candidate->status)
        ->toBe(KnowledgeImprovementCandidateStatus::Approved)
        ->and($skill->version)
        ->toBe(2)
        ->and($agent->skills()->count())
        ->toBe(1)
        ->and(GlobalKnowledgePattern::query()->count())
        ->toBe(0);

    $instructionsAfterProjectApproval = $skill->instructions;
    $versionAfterProjectApproval = $skill->version;

    app(PromoteGlobalKnowledgePattern::class)->handle(
        $candidate,
        $operator,
        globalPatternPayload([
            'name' => 'Deterministic Validation Evidence',
            'category' => 'testing',
            'applicable_roles' => ['coder'],
            'validated_guidance' => 'Rerun required deterministic validation after corrective changes and preserve successful validation evidence before review.',
        ]),
    );

    $skill->refresh();

    expect($skill->version)
        ->toBe($versionAfterProjectApproval)
        ->and($skill->instructions)
        ->toBe($instructionsAfterProjectApproval)
        ->and($agent->skills()->count())
        ->toBe(1)
        ->and(GlobalKnowledgePattern::query()->count())
        ->toBe(1);
});

test('later promotion creates a new immutable version and supersedes only lifecycle metadata', function (): void {
    $project = globalPatternProject();
    $operator = User::factory()->create();

    $firstCandidate = approvedGlobalPatternCandidate(
        $project,
        $operator,
    );

    $secondCandidate = approvedGlobalPatternCandidate(
        $project,
        $operator,
    );

    $promoter = app(PromoteGlobalKnowledgePattern::class);

    $first = $promoter->handle(
        $firstCandidate,
        $operator,
        globalPatternPayload([
            'validated_guidance' => 'Keep durable workflow transitions application owned and cover each transition with deterministic regression evidence.',
        ]),
    );

    $firstGuidance = $first->validated_guidance;
    $firstEvidenceHash = $first->source_evidence_hash;

    $second = $promoter->handle(
        $secondCandidate,
        $operator,
        globalPatternPayload([
            'validated_guidance' => 'Keep durable workflow transitions application owned, verify authorization before mutation, and cover each transition with deterministic regression evidence.',
        ]),
    );

    $first->refresh();

    expect($first->version)
        ->toBe(1)
        ->and($first->enabled)
        ->toBeFalse()
        ->and($first->superseded_at)
        ->not->toBeNull()
        ->and($first->validated_guidance)
        ->toBe($firstGuidance)
        ->and($first->source_evidence_hash)
        ->toBe($firstEvidenceHash)
        ->and($second->version)
        ->toBe(2)
        ->and($second->enabled)
        ->toBeTrue()
        ->and($second->superseded_at)
        ->toBeNull();

    expect(
        fn () => $first->update([
            'validated_guidance' => 'Rewrite historical guidance.',
        ]),
    )->toThrow(LogicException::class);

    expect($first->refresh()->validated_guidance)
        ->toBe($firstGuidance);
});
