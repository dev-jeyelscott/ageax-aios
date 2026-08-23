<?php

use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\AgentRunRecorder;
use App\Services\ContextCostEstimator;
use App\Services\ObsidianProjectNotes;
use App\Services\ProjectRuntimeCapabilityDetector;
use App\Services\TaskContextCapsuleFactory;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;

use function Pest\Laravel\mock;

function reviewerRiskMapFactory(): TaskContextCapsuleFactory
{
    $notes = mock(ObsidianProjectNotes::class);
    $notes->shouldReceive('taskRetrieval')->andReturnUsing(
        fn (Task $task, AgentRole $role): array => [
            'notes' => [],
            'approved_patterns' => [],
            'manifest' => [
                'role' => $role->value,
                'task_id' => $task->id,
                'selected_note_paths' => [],
            ],
        ],
    );

    $runtime = mock(ProjectRuntimeCapabilityDetector::class);
    $runtime->shouldReceive('detect')->andReturn([
        'php' => ['available' => true],
    ]);

    return new TaskContextCapsuleFactory($notes, $runtime);
}

/** @return array{0: Project, 1: Task} */
function reviewerRiskMapTask(
    string $name,
    ?TaskWorkType $workType = TaskWorkType::Feature,
    ?TaskComplexity $complexity = TaskComplexity::Medium,
): array {
    $project = Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/'.strtolower(str_replace(' ', '-', $name)),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);

    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Review risk map task',
        'objective' => 'Provide deterministic Reviewer attention guidance.',
        'work_type' => $workType,
        'complexity' => $complexity,
        'acceptance_criteria' => ['Reviewer authority remains unchanged.'],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => [],
        'verification_commands' => [],
        'implementation_prompt' => 'Implement the bounded change.',
        'context_capsule' => [],
        'status' => TaskStatus::Reviewing,
    ]);

    return [$project, $task];
}

/**
 * @param  list<string>  $changedFiles
 * @param  array<string, bool>  $checks
 */
function reviewerRiskMapAttempt(Task $task, array $changedFiles, array $checks = []): TaskAttempt
{
    $checks = $checks === [] ? [
        'git_diff_check' => true,
        'secret_scan' => true,
        'forbidden_file_check' => true,
        'task_verification' => true,
    ] : $checks;

    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => 'base-sha',
        'head_sha' => 'head-sha',
        'commit_sha' => 'head-sha',
        'status' => 'completed',
        'validation_results' => [
            'passed' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'evidence' => [],
        ],
        'changed_files' => $changedFiles,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
}

test('reviewer risk map is deterministic role scoped and ordered by risk then area', function () {
    [, $task] = reviewerRiskMapTask('Reviewer Risk Determinism');
    reviewerRiskMapAttempt($task, [
        'resources/js/pages/projects/show.tsx',
        'app/Services/TaskWorkflow.php',
        'database/migrations/2026_08_20_000000_add_example.php',
        'app/Policies/ProjectPolicy.php',
        'tests/Feature/ProjectWorkflowTest.php',
        'app/Services/ContextBudgetGuard.php',
        'app/Services/TaskWorkflow.php',
    ]);
    $factory = reviewerRiskMapFactory();

    $first = $factory->make($task, AgentRole::Reviewer);
    $second = $factory->make($task, AgentRole::Reviewer);
    $map = $first['retrieval_manifest']['review_risk_map'];

    expect($map)
        ->toBe($second['retrieval_manifest']['review_risk_map'])
        ->and($map['schema_version'])->toBe(TaskContextCapsuleFactory::ReviewerRiskMapSchemaVersion)
        ->and($map['policy_version'])->toBe(1)
        ->and($map['advisory_only'])->toBeTrue()
        ->and($map['work_type'])->toBe(TaskWorkType::Feature->value)
        ->and($map['complexity'])->toBe(TaskComplexity::Medium->value)
        ->and(array_column($map['entries'], 'area'))->toBe([
            'authorization_security',
            'context_budget',
            'data_integrity',
            'workflow_orchestration',
            'presentation',
            'tests',
        ])
        ->and(array_column($map['entries'], 'risk'))->toBe([
            'high',
            'high',
            'high',
            'high',
            'low',
            'low',
        ])
        ->and($map['entries'][3]['files'])->toBe(['app/Services/TaskWorkflow.php'])
        ->and($first['retrieval_manifest']['review_risk_map'])->toBe($map);

    $coder = $factory->make($task, AgentRole::Coder);

    expect(array_key_exists('review_risk_map', $coder['retrieval_manifest']))->toBeFalse()
        ->and($task->refresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($task->reviews()->count())->toBe(0);
});

test('high complexity elevates low risk files while missing optional metadata remains safe', function () {
    [, $highTask] = reviewerRiskMapTask(
        'Reviewer Risk High Complexity',
        TaskWorkType::Bug,
        TaskComplexity::High,
    );
    reviewerRiskMapAttempt($highTask, [
        'docs/review-behavior.md',
        'tests/Feature/ReviewBehaviorTest.php',
    ]);
    $factory = reviewerRiskMapFactory();
    $highMap = $factory->make($highTask, AgentRole::Reviewer)['retrieval_manifest']['review_risk_map'];

    expect(array_column($highMap['entries'], 'area'))->toBe([
        'documentation',
        'regression_behavior',
        'tests',
    ])
        ->and(array_column($highMap['entries'], 'risk'))->toBe([
            'medium',
            'medium',
            'medium',
        ]);

    [, $legacyTask] = reviewerRiskMapTask(
        'Reviewer Risk Legacy Metadata',
        null,
        null,
    );
    reviewerRiskMapAttempt($legacyTask, ['docs/legacy-task.md']);
    $legacyMap = $factory->make($legacyTask, AgentRole::Reviewer)['retrieval_manifest']['review_risk_map'];

    expect($legacyMap['work_type'])->toBeNull()
        ->and($legacyMap['complexity'])->toBeNull()
        ->and($legacyMap['entries'][0]['area'])->toBe('documentation')
        ->and($legacyMap['entries'][0]['risk'])->toBe('low');
});

test('failed deterministic validation elevates attention without importing another project evidence', function () {
    [, $task] = reviewerRiskMapTask('Reviewer Risk Validation');
    reviewerRiskMapAttempt($task, ['tests/Feature/SafeTest.php'], [
        'git_diff_check' => true,
        'secret_scan' => false,
        'forbidden_file_check' => true,
        'task_verification' => true,
    ]);

    [, $otherTask] = reviewerRiskMapTask('Reviewer Risk Other Project');
    reviewerRiskMapAttempt($otherTask, [
        'database/migrations/2026_08_20_000001_other_project.php',
    ]);

    $map = reviewerRiskMapFactory()->make($task, AgentRole::Reviewer)['retrieval_manifest']['review_risk_map'];
    $areas = array_column($map['entries'], 'area');

    expect($areas)->toContain('security_validation')
        ->and($areas)->toContain('tests')
        ->and($areas)->not->toContain('data_integrity')
        ->and($map['entries'][0]['area'])->toBe('security_validation')
        ->and($map['entries'][0]['risk'])->toBe('high');
});

test('reviewer risk map is bounded budget accounted and retained in historical run evidence', function () {
    [$project, $task] = reviewerRiskMapTask('Reviewer Risk Evidence');
    $attempt = reviewerRiskMapAttempt($task, array_map(
        fn (int $index): string => "app/Services/WorkflowPart{$index}.php",
        range(1, 20),
    ));
    $factory = reviewerRiskMapFactory();
    $context = $factory->make($task, AgentRole::Reviewer);
    $map = $context['retrieval_manifest']['review_risk_map'];

    expect(count($map['entries']))->toBeLessThanOrEqual($map['bounds']['max_entries'])
        ->and(count($map['entries'][0]['files']))->toBe($map['bounds']['max_files_per_entry']);

    $withoutRiskMap = $context;
    unset($withoutRiskMap['retrieval_manifest']['review_risk_map']);

    $estimator = app(ContextCostEstimator::class);
    $withRiskMapEstimate = $estimator->estimate(
        'system rules',
        ['default_context' => null],
        [],
        $context,
    );
    $withoutRiskMapEstimate = $estimator->estimate(
        'system rules',
        ['default_context' => null],
        [],
        $withoutRiskMap,
    );

    expect($withRiskMapEstimate['task_core']['estimated_tokens'])
        ->toBeGreaterThan($withoutRiskMapEstimate['task_core']['estimated_tokens']);

    $run = app(AgentRunRecorder::class)->start(
        $project,
        AgentRole::Reviewer,
        'review prompt',
        $task,
        $attempt,
        retrievalManifest: $context['retrieval_manifest'],
    );
    $historicalHash = $map['map_hash'];

    $attempt->update([
        'changed_files' => ['docs/future-review.md'],
    ]);
    $futureMap = $factory->make($task, AgentRole::Reviewer)['retrieval_manifest']['review_risk_map'];
    $persistedResult = $run->refresh()->getAttribute('result');

    expect($futureMap['map_hash'])->not->toBe($historicalHash)
        ->and($persistedResult)->toBeArray()
        ->and($persistedResult['retrieval_manifest']['review_risk_map']['map_hash'])->toBe($historicalHash);
});
