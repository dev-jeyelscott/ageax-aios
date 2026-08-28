<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\ParallelTaskSafety;
use App\ProjectStatus;
use App\Services\CoderRepositoryGuard;
use App\Services\ParallelTaskSafetyEvaluator;
use App\TaskStatus;

use function Pest\Laravel\mock;

/**
 * Create one running project and current phase for deterministic parallel-safety tests.
 *
 * @return array{0: Project, 1: Phase}
 */
function createParallelSafetyProject(): array
{
    $project = Project::create([
        'name' => 'Parallel Safety '.fake()->uuid(),
        'path' => '/tmp/parallel-safety-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'Parallel Safety',
        'objective' => 'Exercise deterministic concurrency classification.',
    ]);

    return [$project, $phase];
}

/**
 * Create one Task with a bounded relevant-path contract for parallel-safety evaluation.
 *
 * @param  list<string>|null  $relevantPaths
 */
function createParallelSafetyTask(
    Project $project,
    Phase $phase,
    int $position,
    ?array $relevantPaths,
    TaskStatus $status = TaskStatus::Queued,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'P10-SAFE-'.str_pad(
            (string) $position,
            3,
            '0',
            STR_PAD_LEFT,
        ),
        'position' => $position,
        'title' => "Parallel safety task {$position}",
        'objective' => "Evaluate parallel safety task {$position}.",
        'acceptance_criteria' => [
            'Safety is deterministic.',
        ],
        'relevant_paths' => $relevantPaths,
        'verification_commands' => [],
        'implementation_prompt' => 'Implement only the declared task scope.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

test('disjoint same phase tasks are safe and the decision is audited', function () {
    [$project, $phase] = createParallelSafetyProject();

    $candidate = createParallelSafetyTask(
        $project,
        $phase,
        1,
        ['app/Services/InventoryReader.php'],
    );

    $concurrentTask = createParallelSafetyTask(
        $project,
        $phase,
        2,
        ['app/Http/Controllers/ReportController.php'],
    );

    $assessment = app(ParallelTaskSafetyEvaluator::class)
        ->evaluate($candidate, $concurrentTask);

    $repeatedAssessment = app(ParallelTaskSafetyEvaluator::class)
        ->evaluate($candidate, $concurrentTask);

    $event = $candidate->auditEvents()
        ->where('event_type', 'task.parallel_safety_evaluated')
        ->latest('id')
        ->firstOrFail();

    expect($assessment['decision'])
        ->toBe(ParallelTaskSafety::Safe)
        ->and($assessment['decision']->allowsConcurrency())
        ->toBeTrue()
        ->and($assessment['reasons'])
        ->toBe(['independent_task_scope'])
        ->and($repeatedAssessment)
        ->toEqual($assessment)
        ->and($event->payload['decision'])
        ->toBe('safe')
        ->and($event->payload['candidate']['task_id'])
        ->toBe($candidate->id)
        ->and($event->payload['concurrent_task']['task_id'])
        ->toBe($concurrentTask->id);
});

test('dependency related tasks are unsafe', function () {
    [$project, $phase] = createParallelSafetyProject();

    $dependency = createParallelSafetyTask(
        $project,
        $phase,
        1,
        ['app/Services/FoundationService.php'],
    );

    $dependent = createParallelSafetyTask(
        $project,
        $phase,
        2,
        ['app/Services/FeatureService.php'],
    );

    $dependent->dependencies()->attach($dependency);

    $assessment = app(ParallelTaskSafetyEvaluator::class)
        ->evaluate($dependency, $dependent);

    expect($assessment['decision'])
        ->toBe(ParallelTaskSafety::Unsafe)
        ->and($assessment['decision']->allowsConcurrency())
        ->toBeFalse()
        ->and($assessment['reasons'])
        ->toContain('dependency_related_tasks');
});

test('unknown relevant path impact defaults to serial', function () {
    [$project, $phase] = createParallelSafetyProject();

    $candidate = createParallelSafetyTask(
        $project,
        $phase,
        1,
        null,
    );

    $concurrentTask = createParallelSafetyTask(
        $project,
        $phase,
        2,
        ['app/Services/FeatureService.php'],
    );

    $assessment = app(ParallelTaskSafetyEvaluator::class)
        ->evaluate($candidate, $concurrentTask);

    expect($assessment['decision'])
        ->toBe(ParallelTaskSafety::Unknown)
        ->and($assessment['decision']->allowsConcurrency())
        ->toBeFalse()
        ->and($assessment['reasons'])
        ->toContain('relevant_paths_unknown');
});

test('overlapping relevant paths are unsafe', function () {
    [$project, $phase] = createParallelSafetyProject();

    $candidate = createParallelSafetyTask(
        $project,
        $phase,
        1,
        ['app/Services/SharedService.php'],
    );

    $concurrentTask = createParallelSafetyTask(
        $project,
        $phase,
        2,
        ['app/Services/SharedService.php'],
    );

    $assessment = app(ParallelTaskSafetyEvaluator::class)
        ->evaluate($candidate, $concurrentTask);

    expect($assessment['decision'])
        ->toBe(ParallelTaskSafety::Unsafe)
        ->and($assessment['reasons'])
        ->toContain('relevant_path_overlap');
});

test('shared repository resource classes are unsafe when both tasks target them', function () {
    [$project, $phase] = createParallelSafetyProject();

    $candidate = createParallelSafetyTask(
        $project,
        $phase,
        1,
        ['routes/web.php'],
    );

    $concurrentTask = createParallelSafetyTask(
        $project,
        $phase,
        2,
        ['routes/api.php'],
    );

    $assessment = app(ParallelTaskSafetyEvaluator::class)
        ->evaluate($candidate, $concurrentTask);

    expect($assessment['decision'])
        ->toBe(ParallelTaskSafety::Unsafe)
        ->and($assessment['reasons'])
        ->toContain('shared_resource_class_overlap');
});

test(
    'schema configuration package and global build paths are unsafe',
    function (string $path) {
        [$project, $phase] = createParallelSafetyProject();

        $candidate = createParallelSafetyTask(
            $project,
            $phase,
            1,
            [$path],
        );

        $concurrentTask = createParallelSafetyTask(
            $project,
            $phase,
            2,
            ['app/Services/FeatureService.php'],
        );

        $assessment = app(ParallelTaskSafetyEvaluator::class)
            ->evaluate($candidate, $concurrentTask);

        expect($assessment['decision'])
            ->toBe(ParallelTaskSafety::Unsafe)
            ->and($assessment['reasons'])
            ->toContain('high_risk_global_change');
    },
)->with([
    'migration' => 'database/migrations/2026_08_28_120000_add_parallel_state.php',
    'configuration' => 'config/aios.php',
    'package manifest' => 'composer.json',
    'global build configuration' => 'vite.config.ts',
    'generated artifact' => 'resources/js/routes/projects/index.ts',
]);

test('an active coder execution participates in the safety decision', function () {
    [$project, $phase] = createParallelSafetyProject();

    $activeTask = createParallelSafetyTask(
        $project,
        $phase,
        1,
        ['app/Services/ActiveWorkerService.php'],
        TaskStatus::Coding,
    );

    $candidate = createParallelSafetyTask(
        $project,
        $phase,
        2,
        ['app/Services/IndependentService.php'],
    );

    AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $activeTask->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash(
            'sha256',
            'parallel-active-run',
        ),
        'started_at' => now(),
    ]);

    $assessment = app(ParallelTaskSafetyEvaluator::class)
        ->evaluate($candidate, $activeTask);

    $event = $candidate->auditEvents()
        ->where('event_type', 'task.parallel_safety_evaluated')
        ->latest('id')
        ->firstOrFail();

    expect($assessment['decision'])
        ->toBe(ParallelTaskSafety::Safe)
        ->and(
            $event->payload['concurrent_task']['active_execution']['task_claimed_active'],
        )
        ->toBeTrue()
        ->and(
            $event->payload['concurrent_task']['active_execution']['running_agent_runs'],
        )
        ->toBe(1);
});

test('a changing repository snapshot defaults to serial', function () {
    [$project, $phase] = createParallelSafetyProject();

    $candidate = createParallelSafetyTask(
        $project,
        $phase,
        1,
        ['app/Services/InventoryReader.php'],
    );

    $concurrentTask = createParallelSafetyTask(
        $project,
        $phase,
        2,
        ['app/Services/ReportWriter.php'],
    );

    $guard = mock(CoderRepositoryGuard::class);

    $guard->shouldReceive('inspect')
        ->once()
        ->andReturn([
            'allowed' => true,
            'mode' => 'normal',
            'base_sha' => 'head-a',
            'recovery_attempt' => null,
            'state' => [
                'inspectable' => true,
                'clean' => true,
                'head_sha' => 'head-a',
                'base_sha' => 'head-a',
                'staged_files' => [],
                'unstaged_files' => [],
                'untracked_files' => [],
                'errors' => [],
            ],
        ]);

    $guard->shouldReceive('inspect')
        ->once()
        ->andReturn([
            'allowed' => true,
            'mode' => 'normal',
            'base_sha' => 'head-b',
            'recovery_attempt' => null,
            'state' => [
                'inspectable' => true,
                'clean' => true,
                'head_sha' => 'head-b',
                'base_sha' => 'head-b',
                'staged_files' => [],
                'unstaged_files' => [],
                'untracked_files' => [],
                'errors' => [],
            ],
        ]);

    app()->instance(
        CoderRepositoryGuard::class,
        $guard,
    );

    $assessment = app(ParallelTaskSafetyEvaluator::class)
        ->evaluate($candidate, $concurrentTask);

    expect($assessment['decision'])
        ->toBe(ParallelTaskSafety::Unknown)
        ->and($assessment['decision']->allowsConcurrency())
        ->toBeFalse()
        ->and($assessment['reasons'])
        ->toContain('repository_snapshot_changed');
});

test('active git recovery state is unsafe', function () {
    [$project, $phase] = createParallelSafetyProject();

    $candidate = createParallelSafetyTask(
        $project,
        $phase,
        1,
        ['app/Services/InventoryReader.php'],
    );

    $concurrentTask = createParallelSafetyTask(
        $project,
        $phase,
        2,
        ['app/Services/ReportWriter.php'],
    );

    $guard = mock(CoderRepositoryGuard::class);

    $guard->shouldReceive('inspect')
        ->twice()
        ->andReturn([
            'allowed' => true,
            'mode' => 'recovery',
            'base_sha' => 'test-base-sha',
            'recovery_attempt' => null,
            'state' => [
                'inspectable' => true,
                'clean' => false,
                'head_sha' => 'test-base-sha',
                'base_sha' => 'test-base-sha',
                'staged_files' => [],
                'unstaged_files' => [
                    'app/Services/RecoveryOwned.php',
                ],
                'untracked_files' => [],
                'errors' => [],
            ],
        ]);

    app()->instance(
        CoderRepositoryGuard::class,
        $guard,
    );

    $assessment = app(ParallelTaskSafetyEvaluator::class)
        ->evaluate($candidate, $concurrentTask);

    expect($assessment['decision'])
        ->toBe(ParallelTaskSafety::Unsafe)
        ->and($assessment['reasons'])
        ->toContain('active_git_recovery_state');
});
