<?php

use App\KnowledgeImprovementTarget;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Skill;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ReviewStatus;
use App\Services\KnowledgeImprovementScanner;
use App\Services\KnowledgeSourceManifestSynchronizer;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Create one managed test project inside the configured isolated workspace.
 */
function knowledgeGapProject(string $name): Project
{
    $workspace = (string) config('aios.workspace_root');
    $path = $workspace.'/'.Str::slug($name).'-'.Str::uuid();

    File::ensureDirectoryExists($path);

    return Project::factory()->create([
        'name' => $name,
        'path' => $path,
        'git_status' => 'clean',
    ]);
}

/**
 * Create and return one project's isolated Obsidian directory under the configured test vault.
 */
function knowledgeGapProjectDirectory(Project $project): string
{
    $vault = (string) config('aios.obsidian_vault_path');
    $directory = $vault.'/Projects/'.Str::slug($project->name);

    File::ensureDirectoryExists($directory);

    return $directory;
}

/**
 * Synchronize P6-001 source evidence before running the P6-002 scanner exactly like the command path.
 */
function scanKnowledgeGaps(Project $project): int
{
    app(KnowledgeSourceManifestSynchronizer::class)->sync($project);

    return app(KnowledgeImprovementScanner::class)->scan($project);
}

/**
 * Create the minimum deterministic Task contract required to exercise current-task brief detection.
 */
function knowledgeGapTask(Project $project, string $key = 'TASK-001'): Task
{
    return Task::query()->create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => 1,
        'title' => 'Knowledge gap task',
        'objective' => 'Exercise deterministic knowledge gap detection.',
        'acceptance_criteria' => ['Knowledge findings remain deterministic.'],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => ['app/Services/Example.php'],
        'verification_commands' => ['php artisan test --compact'],
        'implementation_prompt' => 'Detect only objective knowledge gaps.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

/**
 * Record one recurring Reviewer failure in the existing normalized architecture-consistency family.
 */
function knowledgeGapReviewFinding(Project $project, int $position): ReviewFinding
{
    $task = Task::query()->create([
        'project_id' => $project->id,
        'key' => 'REVIEW-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => 'Recurring architecture finding '.$position,
        'objective' => 'Exercise existing recurring failure normalization.',
        'acceptance_criteria' => ['Architecture remains repository-consistent.'],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => ['app/Services/Example.php'],
        'verification_commands' => ['php artisan test --compact'],
        'implementation_prompt' => 'Preserve the existing architecture.',
        'context_capsule' => [],
        'status' => TaskStatus::ChangesRequired,
    ]);
    $attempt = TaskAttempt::query()->create([
        'task_id' => $task->id,
        'number' => 1,
        'status' => 'completed',
        'validation_results' => ['passed' => true],
        'changed_files' => ['app/Services/Example.php'],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $review = Review::query()->create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::ChangesRequired,
        'summary' => 'Repository architecture drifted.',
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    return ReviewFinding::query()->create([
        'review_id' => $review->id,
        'severity' => 'major',
        'location' => 'app/Services/Example.php:'.(20 + $position),
        'current_implementation' => 'A duplicate service bypasses the existing pattern.',
        'expected_implementation' => 'Reuse the existing architecture and service boundary.',
        'why_incorrect' => 'The implementation introduces a parallel system.',
        'required_fix' => 'Use the existing repository-consistent service.',
        'verification_requirement' => 'Run focused architecture regression coverage.',
        'implementation_fix_context' => 'Preserve existing architecture consistency.',
    ]);
}

beforeEach(function (): void {
    $workspace = storage_path(
        'framework/testing/knowledge-gap-workspace-'.Str::uuid(),
    );
    $vault = storage_path(
        'framework/testing/knowledge-gap-vault-'.Str::uuid(),
    );

    File::ensureDirectoryExists($workspace);
    File::ensureDirectoryExists($vault);

    config()->set('aios.workspace_root', $workspace);
    config()->set('aios.obsidian_vault_path', $vault);
    config()->set('aios.knowledge_improvement_occurrence_threshold', 3);
    config()->set('aios.knowledge_improvement_reopen_threshold', 3);
    config()->set('aios.knowledge_improvement_scan_limit', 500);
    config()->set('aios.knowledge_improvement_lookback_days', 180);
});

test('broken project local wiki links create one immediate idempotent candidate while valid links are ignored', function (): void {
    $project = knowledgeGapProject('Broken Wiki Links');
    $directory = knowledgeGapProjectDirectory($project);

    File::ensureDirectoryExists($directory.'/Specifications');
    File::put($directory.'/Specifications/Current.md', '# Current');
    File::put(
        $directory.'/STATE.md',
        "[[Specifications/Current#Contract|Current contract]]\n[[Specifications/Missing|Missing contract]]\n",
    );

    expect(scanKnowledgeGaps($project))->toBe(1)
        ->and(scanKnowledgeGaps($project))->toBe(0);

    $candidate = KnowledgeImprovementCandidate::query()
        ->whereBelongsTo($project)
        ->sole();

    expect($candidate->source_kind)->toBe('knowledge_gap')
        ->and($candidate->failure_code)->toBe('knowledge_gap:broken_obsidian_link')
        ->and($candidate->target_type)->toBe(KnowledgeImprovementTarget::Documentation)
        ->and($candidate->occurrence_count)->toBe(1)
        ->and($candidate->evidence)->toHaveCount(1)
        ->and($candidate->evidence[0]['source_reference'])->toBe('STATE.md')
        ->and($candidate->evidence[0]['target_reference'])->toBe('Specifications/Missing.md');
});

test('missing state and current task brief create deterministic point findings', function (): void {
    $project = knowledgeGapProject('Missing Required Knowledge');
    knowledgeGapProjectDirectory($project);
    $task = knowledgeGapTask($project);

    expect(scanKnowledgeGaps($project))->toBe(2);

    $candidates = KnowledgeImprovementCandidate::query()
        ->whereBelongsTo($project)
        ->orderBy('failure_code')
        ->get();

    expect($candidates)->toHaveCount(2)
        ->and($candidates->pluck('failure_code')->all())->toBe([
            'knowledge_gap:missing_state',
            'knowledge_gap:missing_task_brief',
        ])
        ->and($candidates->last()->evidence[0]['task_key'])->toBe($task->key);
});

test('removed repository paths are detected only from allowlisted path shaped references and cannot escape the project', function (): void {
    $project = knowledgeGapProject('Removed Repository Paths');
    $directory = knowledgeGapProjectDirectory($project);

    File::ensureDirectoryExists($project->path.'/app/Services');
    File::put($project->path.'/app/Services/Existing.php', '<?php');
    File::ensureDirectoryExists($directory.'/Specifications');
    File::put($directory.'/STATE.md', '# State');
    File::put(
        $directory.'/Specifications/Paths.md',
        <<<'MD'
# Paths

Existing: `app/Services/Existing.php`
Removed: `app/Services/Missing.php`
Traversal is not a repository reference: `../outside.php`
URL is not a repository reference: `https://example.com/app/Services/Missing.php`
MD,
    );

    expect(scanKnowledgeGaps($project))->toBe(1);

    $candidate = KnowledgeImprovementCandidate::query()
        ->whereBelongsTo($project)
        ->sole();

    expect($candidate->failure_code)->toBe('knowledge_gap:removed_repository_path')
        ->and($candidate->evidence[0]['target_reference'])->toBe('app/Services/Missing.php')
        ->and(json_encode($candidate->evidence, JSON_THROW_ON_ERROR))->not->toContain('../outside.php');
});

test('implementation notes referencing removed repository paths are classified as objectively obsolete', function (): void {
    $project = knowledgeGapProject('Obsolete Implementation Note');
    $directory = knowledgeGapProjectDirectory($project);

    File::ensureDirectoryExists($directory.'/Implementation');
    File::put($directory.'/STATE.md', '# State');
    File::put(
        $directory.'/Implementation/Legacy.md',
        'Legacy implementation lives at `app/Legacy/RemovedService.php`.',
    );

    expect(scanKnowledgeGaps($project))->toBe(1);

    $candidate = KnowledgeImprovementCandidate::query()
        ->whereBelongsTo($project)
        ->sole();

    expect($candidate->failure_code)->toBe('knowledge_gap:obsolete_implementation_note')
        ->and($candidate->evidence[0]['source_reference'])->toBe('Implementation/Legacy.md');
});

test('stable knowledge hash transitions become one idempotent drift candidate while unchanged content does not', function (): void {
    $project = knowledgeGapProject('Knowledge Hash Drift');
    $directory = knowledgeGapProjectDirectory($project);

    File::ensureDirectoryExists($directory.'/Architecture');
    File::put($directory.'/STATE.md', '# State');
    File::put($directory.'/Architecture/System.md', 'VERSION ONE');

    expect(scanKnowledgeGaps($project))->toBe(0);

    File::put($directory.'/Architecture/System.md', 'VERSION TWO');

    expect(scanKnowledgeGaps($project))->toBe(1)
        ->and(scanKnowledgeGaps($project))->toBe(0);

    $candidate = KnowledgeImprovementCandidate::query()
        ->whereBelongsTo($project)
        ->sole();

    expect($candidate->failure_code)->toBe('knowledge_gap:content_hash_drift')
        ->and($candidate->occurrence_count)->toBe(1)
        ->and($candidate->evidence[0]['previous_content_hash'])->toBe(hash('sha256', 'VERSION ONE'))
        ->and($candidate->evidence[0]['current_content_hash'])->toBe(hash('sha256', 'VERSION TWO'));
});

test('only explicitly approved decisions with explicit supersedes metadata create supersession findings', function (): void {
    $project = knowledgeGapProject('Decision Supersession');
    $directory = knowledgeGapProjectDirectory($project);

    File::ensureDirectoryExists($directory.'/Decisions');
    File::put($directory.'/STATE.md', '# State');
    File::put(
        $directory.'/Decisions/Old Decision.md',
        "# Old Decision\n\nStatus: approved\n",
    );
    File::put(
        $directory.'/Decisions/New Decision.md',
        "# New Decision\n\nStatus: approved\nSupersedes: [[Decisions/Old Decision]]\n",
    );
    File::put(
        $directory.'/Decisions/Unapproved Decision.md',
        "# Unapproved\n\nStatus: proposed\nSupersedes: [[Decisions/Old Decision]]\n",
    );

    expect(scanKnowledgeGaps($project))->toBe(1);

    $candidate = KnowledgeImprovementCandidate::query()
        ->whereBelongsTo($project)
        ->sole();

    expect($candidate->failure_code)->toBe('knowledge_gap:superseded_approved_decision')
        ->and($candidate->evidence[0]['source_reference'])->toBe('Decisions/New Decision.md')
        ->and($candidate->evidence[0]['target_reference'])->toBe('Decisions/Old Decision.md');
});

test('recurring failures keep the occurrence threshold and only enabled applicable same project skills count as approved guidance', function (): void {
    config()->set('aios.obsidian_vault_path', null);

    $unguidedProject = knowledgeGapProject('Unguided Failure Family');

    knowledgeGapReviewFinding($unguidedProject, 1);
    knowledgeGapReviewFinding($unguidedProject, 2);

    expect(app(KnowledgeImprovementScanner::class)->scan($unguidedProject))->toBe(0);

    knowledgeGapReviewFinding($unguidedProject, 3);

    expect(app(KnowledgeImprovementScanner::class)->scan($unguidedProject))->toBe(1);

    $unguided = KnowledgeImprovementCandidate::query()
        ->whereBelongsTo($unguidedProject)
        ->sole();

    expect($unguided->target_type)->toBe(KnowledgeImprovementTarget::Documentation)
        ->and($unguided->target_skill_id)->toBeNull()
        ->and($unguided->occurrence_count)->toBe(3);

    $guidedProject = knowledgeGapProject('Guided Failure Family');
    $skill = Skill::factory()->for($guidedProject)->create([
        'name' => 'Minimal Production Ready Implementation',
        'slug' => 'coder-minimal-production-ready-implementation',
        'applicable_roles' => ['coder'],
        'enabled' => true,
    ]);

    foreach (range(1, 3) as $position) {
        knowledgeGapReviewFinding($guidedProject, $position);
    }

    expect(app(KnowledgeImprovementScanner::class)->scan($guidedProject))->toBe(1);

    $guided = KnowledgeImprovementCandidate::query()
        ->whereBelongsTo($guidedProject)
        ->sole();

    expect($guided->target_type)->toBe(KnowledgeImprovementTarget::Skill)
        ->and($guided->target_skill_id)->toBe($skill->id);
});

test('unavailable knowledge sources fail safely instead of fabricating stale findings', function (): void {
    $project = knowledgeGapProject('Unavailable Knowledge');

    config()->set('aios.obsidian_vault_path', null);

    expect(app(KnowledgeImprovementScanner::class)->scan($project))->toBe(0)
        ->and(KnowledgeImprovementCandidate::query()->whereBelongsTo($project)->exists())->toBeFalse();
});

test('knowledge gap scans remain project isolated', function (): void {
    $firstProject = knowledgeGapProject('First Isolated Knowledge Project');
    $secondProject = knowledgeGapProject('Second Isolated Knowledge Project');
    $firstDirectory = knowledgeGapProjectDirectory($firstProject);
    $secondDirectory = knowledgeGapProjectDirectory($secondProject);

    File::put($firstDirectory.'/STATE.md', '[[Specifications/Missing]]');
    File::ensureDirectoryExists($secondDirectory.'/Specifications');
    File::put($secondDirectory.'/STATE.md', '[[Specifications/Present]]');
    File::put($secondDirectory.'/Specifications/Present.md', '# Present');

    expect(scanKnowledgeGaps($firstProject))->toBe(1)
        ->and(scanKnowledgeGaps($secondProject))->toBe(0)
        ->and(KnowledgeImprovementCandidate::query()->whereBelongsTo($firstProject)->count())->toBe(1)
        ->and(KnowledgeImprovementCandidate::query()->whereBelongsTo($secondProject)->count())->toBe(0);
});

test('detection never mutates knowledge documents or skill configuration', function (): void {
    $project = knowledgeGapProject('No Automatic Knowledge Mutation');
    $directory = knowledgeGapProjectDirectory($project);
    $skill = Skill::factory()->for($project)->create([
        'name' => 'Existing Guidance',
        'slug' => 'existing-guidance',
        'instructions' => 'Preserve this exact approved guidance.',
        'enabled' => true,
    ]);
    $content = "# State\n\n[[Specifications/Missing]]\n";

    File::put($directory.'/STATE.md', $content);

    expect(scanKnowledgeGaps($project))->toBe(1)
        ->and(File::get($directory.'/STATE.md'))->toBe($content)
        ->and($skill->refresh()->version)->toBe(1)
        ->and($skill->instructions)->toBe('Preserve this exact approved guidance.');
});
