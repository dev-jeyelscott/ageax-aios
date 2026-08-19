<?php

use App\Actions\DecideKnowledgeImprovementCandidate;
use App\AgentRole;
use App\AgentRunStatus;
use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Skill;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\User;
use App\RecoveryIncidentStatus;
use App\ReviewStatus;
use App\Services\KnowledgeImprovementScanner;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function knowledgeQueueProject(string $name): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-knowledge-queue-'.Str::uuid(),
    ]);
}

function knowledgeQueueTask(Project $project, int $position, string $key): Task
{
    return Task::query()->create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => "Task {$key}",
        'objective' => 'Exercise recurring knowledge evidence.',
        'acceptance_criteria' => ['The behavior is deterministic.'],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => ['app/Services/Example.php'],
        'verification_commands' => ['php artisan test --compact'],
        'implementation_prompt' => 'Implement only the requested behavior.',
        'context_capsule' => [],
        'status' => 'changes_required',
    ]);
}

function knowledgeQueueAttempt(Task $task, int $number, array $validation = ['passed' => true]): TaskAttempt
{
    return TaskAttempt::query()->create([
        'task_id' => $task->id,
        'number' => $number,
        'status' => ($validation['passed'] ?? false) === true ? 'completed' : 'failed',
        'validation_results' => $validation,
        'changed_files' => ['app/Services/Example.php'],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
}

function recordKnowledgeReviewFinding(Project $project, int $position, string $whyIncorrect): ReviewFinding
{
    $task = knowledgeQueueTask($project, $position, 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT));
    $attempt = knowledgeQueueAttempt($task, 1);
    $review = Review::query()->create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::ChangesRequired,
        'summary' => 'Material finding.',
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    return ReviewFinding::query()->create([
        'review_id' => $review->id,
        'severity' => 'major',
        'location' => 'app/Services/Example.php:'.(20 + $position),
        'current_implementation' => 'The workflow transition is performed in the wrong layer.',
        'expected_implementation' => 'AIOS must keep workflow transition ownership.',
        'why_incorrect' => $whyIncorrect,
        'required_fix' => 'Move the state transition back to the AIOS-owned workflow service.',
        'verification_requirement' => 'Run the focused workflow regression test.',
        'implementation_fix_context' => 'Preserve existing workflow ownership.',
    ]);
}

beforeEach(function (): void {
    config()->set('aios.knowledge_improvement_occurrence_threshold', 3);
    config()->set('aios.knowledge_improvement_reopen_threshold', 3);
    config()->set('aios.knowledge_improvement_scan_limit', 500);
    config()->set('aios.knowledge_improvement_lookback_days', 180);
});

test('three materially equivalent reviewer findings create one deterministic project-scoped candidate', function () {
    $project = knowledgeQueueProject('Review learning project');
    $otherProject = knowledgeQueueProject('Other review learning project');
    $skill = Skill::factory()->for($project)->create([
        'name' => 'Minimal Production Ready Implementation',
        'slug' => 'coder-minimal-production-ready-implementation',
    ]);

    foreach (range(1, 3) as $position) {
        recordKnowledgeReviewFinding(
            $project,
            $position,
            'A workflow state transition is escaping AIOS orchestration ownership.',
        );
    }

    foreach (range(1, 3) as $position) {
        recordKnowledgeReviewFinding(
            $otherProject,
            $position,
            'A workflow state transition is escaping AIOS orchestration ownership.',
        );
    }

    $scanner = app(KnowledgeImprovementScanner::class);

    expect($scanner->scan($project))->toBe(1)
        ->and($scanner->scan($project))->toBe(0)
        ->and($scanner->scan($otherProject))->toBe(1);

    $candidate = KnowledgeImprovementCandidate::query()
        ->where('project_id', $project->id)
        ->sole();

    expect($candidate->status)->toBe(KnowledgeImprovementCandidateStatus::Pending)
        ->and($candidate->occurrence_count)->toBe(3)
        ->and($candidate->target_type)->toBe(KnowledgeImprovementTarget::Skill)
        ->and($candidate->target_skill_id)->toBe($skill->id)
        ->and($candidate->affected_role)->toBe('coder')
        ->and($candidate->affected_area)->toBe('app/Services')
        ->and($candidate->evidence)->toHaveCount(3)
        ->and(KnowledgeImprovementCandidate::query()->count())->toBe(2);
});

test('operational execution failures are excluded while deterministic validation failures can be promoted', function () {
    $project = knowledgeQueueProject('Validation learning project');
    Skill::factory()->for($project)->create([
        'name' => 'Deterministic Validation',
        'slug' => 'coder-deterministic-validation',
    ]);

    foreach (range(1, 3) as $position) {
        $task = knowledgeQueueTask($project, $position, 'OPS-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT));
        knowledgeQueueAttempt($task, 1, [
            'passed' => false,
            'checks' => ['execution_exception' => false],
            'evidence' => [
                'execution_exception' => [
                    'passed' => false,
                    'summary' => 'Provider process failed.',
                ],
            ],
        ]);
    }

    $scanner = app(KnowledgeImprovementScanner::class);
    expect($scanner->scan($project))->toBe(0)
        ->and(KnowledgeImprovementCandidate::query()->whereBelongsTo($project)->exists())->toBeFalse();

    foreach (range(4, 6) as $position) {
        $task = knowledgeQueueTask($project, $position, 'VAL-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT));
        knowledgeQueueAttempt($task, 1, [
            'passed' => false,
            'checks' => ['phpstan' => false],
            'evidence' => [
                'phpstan' => [
                    'passed' => false,
                    'name' => 'phpstan',
                    'files' => ['app/Services/Example.php'],
                ],
            ],
        ]);
    }

    expect($scanner->scan($project))->toBe(1);

    $candidate = KnowledgeImprovementCandidate::query()->whereBelongsTo($project)->sole();
    expect($candidate->source_kind)->toBe('validation_failure')
        ->and($candidate->failure_code)->toBe('validation:phpstan')
        ->and($candidate->targetSkill?->slug)->toBe('coder-deterministic-validation');
});

test('repository blocks are deduplicated and dismissed candidates reopen only after materially new evidence', function () {
    $project = knowledgeQueueProject('Repository learning project');
    Skill::factory()->for($project)->create([
        'name' => 'Git Change Isolation',
        'slug' => 'coder-git-change-isolation',
    ]);

    foreach (range(1, 3) as $index) {
        AuditEvent::query()->create([
            'project_id' => $project->id,
            'event_type' => 'task.blocked_dirty_repository',
            'payload' => ['sequence' => $index],
            'occurred_at' => now()->addSeconds($index),
        ]);
    }

    $scanner = app(KnowledgeImprovementScanner::class);
    expect($scanner->scan($project))->toBe(1);

    $candidate = KnowledgeImprovementCandidate::query()->whereBelongsTo($project)->sole();
    $user = User::factory()->create();
    app(DecideKnowledgeImprovementCandidate::class)->handle(
        $candidate,
        $user,
        KnowledgeImprovementCandidateStatus::Dismissed,
    );

    expect($candidate->refresh()->status)->toBe(KnowledgeImprovementCandidateStatus::Dismissed)
        ->and($candidate->reopen_after_occurrence)->toBe(6);

    foreach (range(4, 5) as $index) {
        AuditEvent::query()->create([
            'project_id' => $project->id,
            'event_type' => 'task.blocked_dirty_repository',
            'payload' => ['sequence' => $index],
            'occurred_at' => now()->addSeconds($index),
        ]);
    }

    expect($scanner->scan($project))->toBe(1)
        ->and($candidate->refresh()->status)->toBe(KnowledgeImprovementCandidateStatus::Dismissed)
        ->and($candidate->occurrence_count)->toBe(5);

    AuditEvent::query()->create([
        'project_id' => $project->id,
        'event_type' => 'task.blocked_dirty_repository',
        'payload' => ['sequence' => 6],
        'occurred_at' => now()->addSeconds(6),
    ]);

    expect($scanner->scan($project))->toBe(1)
        ->and($candidate->refresh()->status)->toBe(KnowledgeImprovementCandidateStatus::Pending)
        ->and($candidate->occurrence_count)->toBe(6)
        ->and($candidate->decided_by_user_id)->toBeNull();
});

test('transient recovery failures are ignored while recurring implementation defects become proposals', function () {
    $project = knowledgeQueueProject('Recovery learning project');
    Skill::factory()->for($project)->create([
        'name' => 'Minimal Production Ready Implementation',
        'slug' => 'coder-minimal-production-ready-implementation',
    ]);

    foreach (range(1, 3) as $index) {
        RecoveryIncident::query()->create([
            'project_id' => $project->id,
            'failure_type' => 'provider_process_failure',
            'status' => RecoveryIncidentStatus::Failed,
            'detected_at' => now()->subMinute(),
            'root_cause_category' => 'transient_harness_failure',
            'recoverable' => true,
            'resolved_at' => now(),
        ]);
    }

    $scanner = app(KnowledgeImprovementScanner::class);
    expect($scanner->scan($project))->toBe(0);

    foreach (range(1, 3) as $index) {
        RecoveryIncident::query()->create([
            'project_id' => $project->id,
            'failure_type' => 'implementation_regression',
            'status' => RecoveryIncidentStatus::Recovered,
            'detected_at' => now()->subMinute(),
            'root_cause_category' => 'application_defect',
            'recoverable' => true,
            'changed_files' => ['app/Services/Example.php'],
            'resolved_at' => now(),
        ]);
    }

    expect($scanner->scan($project))->toBe(1);

    $candidate = KnowledgeImprovementCandidate::query()->whereBelongsTo($project)->sole();
    expect($candidate->source_kind)->toBe('recovery_incident')
        ->and($candidate->target_type)->toBe(KnowledgeImprovementTarget::Skill)
        ->and($candidate->affected_area)->toBe('app/Services');
});

test('approved Skill candidates increment only the future Skill version and preserve historical run snapshots', function () {
    $project = knowledgeQueueProject('Skill approval project');
    $user = User::factory()->create();
    $skill = Skill::factory()->for($project)->create([
        'name' => 'Deterministic Validation',
        'slug' => 'coder-deterministic-validation',
        'instructions' => 'Run deterministic validation.',
    ]);
    $historicalSnapshot = [
        'context_schema_version' => 1,
        'context_hash' => hash('sha256', 'historical-context'),
        'agent' => ['role' => 'coder'],
        'skills' => [
            [
                'id' => $skill->id,
                'slug' => $skill->slug,
                'version' => 1,
                'position' => 0,
                'instructions' => 'Run deterministic validation.',
            ],
        ],
    ];
    $run = AgentRun::query()->create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'historical-prompt'),
        'configuration_snapshot' => $historicalSnapshot,
        'context_schema_version' => 1,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $candidate = KnowledgeImprovementCandidate::factory()->for($project)->create([
        'target_skill_id' => $skill->id,
        'target_type' => KnowledgeImprovementTarget::Skill,
        'status' => KnowledgeImprovementCandidateStatus::Pending,
        'proposed_change' => 'Before review, rerun the recurring deterministic validation gate and persist its successful evidence.',
        'occurrence_count' => 3,
    ]);

    $decider = app(DecideKnowledgeImprovementCandidate::class);
    $decider->handle($candidate, $user, KnowledgeImprovementCandidateStatus::Approved);

    expect($candidate->refresh()->status)->toBe(KnowledgeImprovementCandidateStatus::Approved)
        ->and($candidate->applied_skill_version)->toBe(2)
        ->and($skill->refresh()->version)->toBe(2)
        ->and($skill->instructions)->toContain($candidate->proposed_change)
        ->and($skill->agents()->count())->toBe(0)
        ->and($run->refresh()->configuration_snapshot)->toBe($historicalSnapshot);

    $decider->handle($candidate, $user, KnowledgeImprovementCandidateStatus::Approved);
    expect($skill->refresh()->version)->toBe(2);
});

test('approving rule documentation or regression proposals never edits a Skill directly', function () {
    $project = knowledgeQueueProject('Repository knowledge approval project');
    $user = User::factory()->create();
    $skill = Skill::factory()->for($project)->create([
        'name' => 'Existing Skill',
        'slug' => 'existing-skill',
        'instructions' => 'Original guidance.',
    ]);
    $candidate = KnowledgeImprovementCandidate::factory()->for($project)->create([
        'target_skill_id' => null,
        'target_type' => KnowledgeImprovementTarget::Rule,
        'status' => KnowledgeImprovementCandidateStatus::Pending,
        'proposed_change' => 'Add a path-scoped rule through the normal task workflow.',
    ]);

    app(DecideKnowledgeImprovementCandidate::class)->handle(
        $candidate,
        $user,
        KnowledgeImprovementCandidateStatus::Approved,
    );

    expect($candidate->refresh()->status)->toBe(KnowledgeImprovementCandidateStatus::Approved)
        ->and($candidate->applied_at)->toBeNull()
        ->and($candidate->applied_skill_version)->toBeNull()
        ->and($skill->refresh()->version)->toBe(1)
        ->and($skill->instructions)->toBe('Original guidance.');
});

test('the operator queue is authenticated and nested candidates stay project scoped', function () {
    $user = User::factory()->create();
    $project = knowledgeQueueProject('Knowledge UI project');
    $otherProject = knowledgeQueueProject('Other knowledge UI project');
    $candidate = KnowledgeImprovementCandidate::factory()->for($project)->create();

    $this->get(route('projects.knowledge-improvements.index', $project))
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('projects.knowledge-improvements.index', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/knowledge-improvements/index')
            ->where('project.id', $project->id)
            ->where('candidates.0.id', $candidate->id));

    $this->patch(
        route('projects.knowledge-improvements.decide', [$otherProject, $candidate]),
        ['decision' => 'approved'],
    )->assertNotFound();

    expect($candidate->refresh()->status)->toBe(KnowledgeImprovementCandidateStatus::Pending);
});
