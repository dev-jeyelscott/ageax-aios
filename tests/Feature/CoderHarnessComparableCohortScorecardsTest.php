<?php

use App\AgentHarness;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Services\CoderHarnessComparableCohortScorecards;
use App\Services\CoderHarnessOutcomeMetrics;
use App\TaskComplexity;
use App\TaskStatus;
use App\TaskWorkType;
use Illuminate\Support\Str;

function p3020Project(string $name = 'P3-020 scorecard project'): Project
{
    return Project::factory()->create([
        'name' => $name.' '.Str::uuid(),
        'path' => sys_get_temp_dir().'/ageax-p3-020-'.Str::uuid(),
        'git_status' => 'clean',
    ]);
}

/**
 * @return array<string, mixed>
 */
function p3020Outcome(
    int $taskId,
    int $projectId,
    ?TaskWorkType $workType,
    ?TaskComplexity $complexity,
    string $harness,
    string $model,
    ?string $reasoningSetting,
    TaskStatus $status = TaskStatus::Done,
    bool $reviewerApproved = true,
    bool $validationPassed = true,
    bool $operationalRetryOrBlock = false,
    bool $noProgressRetryCondition = false,
    int $tokenUsage = 100,
    int $durationSeconds = 10,
): array {
    $configuration = [
        'harness' => $harness,
        'model' => $model,
        'reasoning_setting' => $reasoningSetting,
    ];

    return [
        'task_id' => $taskId,
        'task_key' => 'TASK-'.str_pad((string) $taskId, 3, '0', STR_PAD_LEFT),
        'project_id' => $projectId,
        'work_type' => $workType?->value,
        'complexity' => $complexity?->value,
        'task_status' => $status->value,
        'configuration_status' => 'attributed',
        'configuration_key' => hash(
            'sha256',
            json_encode($configuration, JSON_THROW_ON_ERROR),
        ),
        'configuration' => $configuration,
        'observed_configurations' => [$configuration],
        'first_pass_reviewer_approved' => $reviewerApproved,
        'first_pass_validation_passed' => $validationPassed,
        'operational_retry_or_block' => $operationalRetryOrBlock,
        'no_progress_retry_condition' => $noProgressRetryCondition,
        'coder_run_count' => 1,
        'attempt_count' => 1,
        'review_count' => 1,
        'known_token_usage' => $tokenUsage,
        'token_usage_complete' => true,
        'total_token_usage' => $tokenUsage,
        'known_execution_duration_seconds' => $durationSeconds,
        'duration_complete' => true,
        'total_execution_duration_seconds' => $durationSeconds,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function p3020ComparableOutcomes(
    int $count,
    int $projectId,
    TaskWorkType $workType = TaskWorkType::Feature,
    TaskComplexity $complexity = TaskComplexity::High,
    int $startingTaskId = 1,
): array {
    $outcomes = [];

    for ($index = 0; $index < $count; $index++) {
        $isCodex = $index % 2 === 0;

        $outcomes[] = p3020Outcome(
            taskId: $startingTaskId + $index,
            projectId: $projectId,
            workType: $workType,
            complexity: $complexity,
            harness: $isCodex
                ? AgentHarness::Codex->value
                : AgentHarness::ClaudeCode->value,
            model: $isCodex ? 'gpt-5' : 'claude-sonnet',
            reasoningSetting: 'high',
            reviewerApproved: $isCodex,
        );
    }

    return $outcomes;
}

/**
 * @param  list<array<string, mixed>>  $outcomes
 */
function p3020Metrics(array $outcomes): CoderHarnessOutcomeMetrics
{
    return new class($outcomes) extends CoderHarnessOutcomeMetrics
    {
        /** @param list<array<string, mixed>> $outcomes */
        public function __construct(
            private readonly array $outcomes,
        ) {}

        /**
         * @param  iterable<int, Task>  $tasks
         * @return array<string, mixed>
         */
        public function calculate(iterable $tasks): array
        {
            return $this->scoreOutcomes($this->outcomes);
        }
    };
}

/**
 * @param  list<array<string, mixed>>  $outcomes
 * @return array<string, mixed>
 */
function p3020Score(
    Project $project,
    array $outcomes,
    TaskWorkType $workType = TaskWorkType::Feature,
    TaskComplexity $complexity = TaskComplexity::High,
): array {
    return (new CoderHarnessComparableCohortScorecards(
        p3020Metrics($outcomes),
    ))->calculate(
        project: $project,
        tasks: [],
        workType: $workType,
        complexity: $complexity,
    );
}

test('an exact project work type and complexity cohort is preferred when it is recommendation eligible', function () {
    $project = p3020Project();

    $outcomes = [
        ...p3020ComparableOutcomes(20, $project->id),
        ...p3020ComparableOutcomes(
            count: 20,
            projectId: $project->id,
            complexity: TaskComplexity::Low,
            startingTaskId: 100,
        ),
    ];

    $result = p3020Score($project, $outcomes);

    expect($result['selected_cohort']['fallback_level'])
        ->toBe(0)
        ->and($result['selected_cohort']['fallback_key'])
        ->toBe('exact_project_work_type_complexity')
        ->and($result['selected_cohort']['filters']['work_type'])
        ->toBe(TaskWorkType::Feature->value)
        ->and($result['selected_cohort']['filters']['complexity'])
        ->toBe(TaskComplexity::High->value)
        ->and($result['selected_cohort']['broadened_dimensions'])
        ->toBe([])
        ->and($result['sample']['comparable_completed_task_count'])
        ->toBe(20)
        ->and($result['confidence']['level'])
        ->toBe(CoderHarnessComparableCohortScorecards::ConfidenceRecommendationEligible);
});

test('fallback broadens work type before complexity and labels high versus low mixing explicitly', function () {
    $project = p3020Project();

    $outcomes = [
        ...p3020ComparableOutcomes(4, $project->id),
        ...p3020ComparableOutcomes(
            count: 3,
            projectId: $project->id,
            workType: TaskWorkType::Bug,
            startingTaskId: 10,
        ),
        ...p3020ComparableOutcomes(
            count: 20,
            projectId: $project->id,
            complexity: TaskComplexity::Low,
            startingTaskId: 100,
        ),
    ];

    $result = p3020Score($project, $outcomes);
    $evaluations = $result['fallback_evaluations'];

    expect(array_column($evaluations, 'fallback_key'))
        ->toBe([
            'exact_project_work_type_complexity',
            'same_project_same_complexity_all_work_types',
            'same_project_all_work_types_and_complexities',
        ])
        ->and(array_map(
            fn (array $evaluation): int => $evaluation['sample']['comparable_completed_task_count'],
            $evaluations,
        ))
        ->toBe([4, 7, 27])
        ->and($result['selected_cohort']['fallback_level'])
        ->toBe(2)
        ->and($result['selected_cohort']['broadened_dimensions'])
        ->toBe(['work_type', 'complexity'])
        ->and($result['selected_cohort']['includes_unknown_metadata']['complexity'])
        ->toBeTrue()
        ->and($result['selected_cohort']['human_label'])
        ->toContain('complexity=all (broadened; includes unknown)');
});

test('the most specific preliminary fallback is retained when broader evidence does not improve confidence', function () {
    $project = p3020Project();

    $outcomes = [
        ...p3020ComparableOutcomes(4, $project->id),
        ...p3020ComparableOutcomes(
            count: 2,
            projectId: $project->id,
            workType: TaskWorkType::Bug,
            startingTaskId: 10,
        ),
    ];

    $result = p3020Score($project, $outcomes);

    expect($result['selected_cohort']['fallback_level'])
        ->toBe(1)
        ->and($result['selected_cohort']['broadened_dimensions'])
        ->toBe(['work_type'])
        ->and($result['selected_cohort']['filters']['complexity'])
        ->toBe(TaskComplexity::High->value)
        ->and($result['confidence']['level'])
        ->toBe(CoderHarnessComparableCohortScorecards::ConfidencePreliminary)
        ->and($result['sample']['comparable_completed_task_count'])
        ->toBe(6);
});

test('historical null metadata participates only after the corresponding dimension is explicitly broadened', function () {
    $project = p3020Project();

    $outcomes = [
        ...p3020ComparableOutcomes(4, $project->id),
        p3020Outcome(
            taskId: 10,
            projectId: $project->id,
            workType: null,
            complexity: TaskComplexity::High,
            harness: AgentHarness::Codex->value,
            model: 'gpt-5',
            reasoningSetting: 'high',
        ),
        p3020Outcome(
            taskId: 11,
            projectId: $project->id,
            workType: null,
            complexity: TaskComplexity::High,
            harness: AgentHarness::ClaudeCode->value,
            model: 'claude-sonnet',
            reasoningSetting: 'high',
        ),
        p3020Outcome(
            taskId: 12,
            projectId: $project->id,
            workType: TaskWorkType::Feature,
            complexity: null,
            harness: AgentHarness::Codex->value,
            model: 'gpt-5',
            reasoningSetting: 'high',
        ),
    ];

    $result = p3020Score($project, $outcomes);
    $evaluations = $result['fallback_evaluations'];

    expect($evaluations[0]['sample']['comparable_completed_task_count'])
        ->toBe(4)
        ->and($evaluations[0]['cohort']['includes_unknown_metadata'])
        ->toBe(['work_type' => false, 'complexity' => false])
        ->and($evaluations[1]['sample']['comparable_completed_task_count'])
        ->toBe(6)
        ->and($evaluations[1]['cohort']['includes_unknown_metadata'])
        ->toBe(['work_type' => true, 'complexity' => false])
        ->and($evaluations[2]['sample']['comparable_completed_task_count'])
        ->toBe(7)
        ->and($evaluations[2]['cohort']['includes_unknown_metadata'])
        ->toBe(['work_type' => true, 'complexity' => true])
        ->and($result['selected_cohort']['fallback_level'])
        ->toBe(1);
});

test('project repository scope never broadens into another project', function () {
    $project = p3020Project('Target project');
    $otherProject = p3020Project('Other project');

    $outcomes = [
        ...p3020ComparableOutcomes(4, $project->id),
        ...p3020ComparableOutcomes(
            count: 30,
            projectId: $otherProject->id,
            startingTaskId: 100,
        ),
    ];

    $result = p3020Score($project, $outcomes);

    expect($result['sample']['comparable_completed_task_count'])
        ->toBe(4)
        ->and($result['confidence']['level'])
        ->toBe(CoderHarnessComparableCohortScorecards::ConfidenceInsufficientData)
        ->and($result['selected_cohort']['filters']['project_repository']['project_id'])
        ->toBe($project->id)
        ->and($result['fallback_policy']['preserved_dimensions'])
        ->toContain('project_repository');
});

test('harness model and reasoning settings remain distinct configuration identities', function () {
    $project = p3020Project();
    $outcomes = [];

    for ($index = 1; $index <= 7; $index++) {
        $outcomes[] = p3020Outcome(
            taskId: $index,
            projectId: $project->id,
            workType: TaskWorkType::Feature,
            complexity: TaskComplexity::High,
            harness: AgentHarness::Codex->value,
            model: 'gpt-5',
            reasoningSetting: 'high',
        );
    }

    for ($index = 8; $index <= 14; $index++) {
        $outcomes[] = p3020Outcome(
            taskId: $index,
            projectId: $project->id,
            workType: TaskWorkType::Feature,
            complexity: TaskComplexity::High,
            harness: AgentHarness::Codex->value,
            model: 'gpt-5',
            reasoningSetting: 'medium',
        );
    }

    for ($index = 15; $index <= 21; $index++) {
        $outcomes[] = p3020Outcome(
            taskId: $index,
            projectId: $project->id,
            workType: TaskWorkType::Feature,
            complexity: TaskComplexity::High,
            harness: AgentHarness::ClaudeCode->value,
            model: 'claude-sonnet',
            reasoningSetting: 'high',
        );
    }

    $result = p3020Score($project, $outcomes);

    expect($result['sample']['configuration_count'])
        ->toBe(3)
        ->and($result['configuration_scores'])
        ->toHaveCount(3)
        ->and(collect($result['configuration_scores'])
            ->pluck('configuration.reasoning_setting')
            ->sort()
            ->values()
            ->all())
        ->toBe(['high', 'high', 'medium']);
});

test('recommendation confidence follows the exact completed task thresholds', function (
    int $count,
    string $expectedConfidence,
    bool $expectedEligible,
) {
    $project = p3020Project();

    $result = p3020Score(
        $project,
        p3020ComparableOutcomes($count, $project->id),
    );

    expect($result['sample']['comparable_completed_task_count'])
        ->toBe($count)
        ->and($result['confidence']['level'])
        ->toBe($expectedConfidence)
        ->and($result['recommendation']['eligible'])
        ->toBe($expectedEligible);
})->with([
    '4 is insufficient' => [
        4,
        CoderHarnessComparableCohortScorecards::ConfidenceInsufficientData,
        false,
    ],
    '5 is preliminary' => [
        5,
        CoderHarnessComparableCohortScorecards::ConfidencePreliminary,
        false,
    ],
    '19 is preliminary' => [
        19,
        CoderHarnessComparableCohortScorecards::ConfidencePreliminary,
        false,
    ],
    '20 is recommendation eligible' => [
        20,
        CoderHarnessComparableCohortScorecards::ConfidenceRecommendationEligible,
        true,
    ],
]);

test('duplicate task outcomes cannot inflate cohort or confidence sample sizes', function () {
    $project = p3020Project();

    $outcomes = p3020ComparableOutcomes(5, $project->id);
    $outcomes[] = $outcomes[0];
    $outcomes[] = $outcomes[1];

    $result = p3020Score($project, $outcomes);

    expect($result['sample']['terminal_task_count'])
        ->toBe(5)
        ->and($result['sample']['comparable_completed_task_count'])
        ->toBe(5)
        ->and($result['confidence']['level'])
        ->toBe(CoderHarnessComparableCohortScorecards::ConfidencePreliminary);
});

test('equal top scores produce no arbitrary winner and ordering remains deterministic', function () {
    $project = p3020Project();
    $outcomes = [];

    for ($index = 1; $index <= 10; $index++) {
        $outcomes[] = p3020Outcome(
            taskId: $index,
            projectId: $project->id,
            workType: TaskWorkType::Feature,
            complexity: TaskComplexity::High,
            harness: AgentHarness::Codex->value,
            model: 'gpt-5',
            reasoningSetting: 'high',
        );
    }

    for ($index = 11; $index <= 20; $index++) {
        $outcomes[] = p3020Outcome(
            taskId: $index,
            projectId: $project->id,
            workType: TaskWorkType::Feature,
            complexity: TaskComplexity::High,
            harness: AgentHarness::ClaudeCode->value,
            model: 'claude-sonnet',
            reasoningSetting: 'high',
        );
    }

    $forward = p3020Score($project, $outcomes);
    $reverse = p3020Score($project, array_reverse($outcomes));

    $forwardOrder = collect($forward['configuration_scores'])
        ->pluck('configuration.harness')
        ->all();

    $reverseOrder = collect($reverse['configuration_scores'])
        ->pluck('configuration.harness')
        ->all();

    expect($forward['recommendation']['eligible'])
        ->toBeFalse()
        ->and($forward['recommendation']['leading_configuration'])
        ->toBeNull()
        ->and($forward['recommendation']['reason'])
        ->toContain('tied')
        ->and($forwardOrder)
        ->toBe($reverseOrder)
        ->and($forwardOrder)
        ->toBe([
            AgentHarness::ClaudeCode->value,
            AgentHarness::Codex->value,
        ]);
});

test('eligible recommendations expose score version component scores samples and deterministic lead explanation', function () {
    $project = p3020Project();
    $outcomes = [];

    for ($index = 1; $index <= 10; $index++) {
        $outcomes[] = p3020Outcome(
            taskId: $index,
            projectId: $project->id,
            workType: TaskWorkType::Feature,
            complexity: TaskComplexity::High,
            harness: AgentHarness::Codex->value,
            model: 'gpt-5',
            reasoningSetting: 'high',
            reviewerApproved: true,
        );
    }

    for ($index = 11; $index <= 20; $index++) {
        $outcomes[] = p3020Outcome(
            taskId: $index,
            projectId: $project->id,
            workType: TaskWorkType::Feature,
            complexity: TaskComplexity::High,
            harness: AgentHarness::ClaudeCode->value,
            model: 'claude-sonnet',
            reasoningSetting: 'high',
            reviewerApproved: false,
        );
    }

    $result = p3020Score($project, $outcomes);
    $leader = $result['recommendation']['leading_configuration'];

    expect($result['score_version'])
        ->toBe(CoderHarnessOutcomeMetrics::SchemaVersion)
        ->and($result['methodology']['weights']['quality']['total'])
        ->toBe(55.0)
        ->and($result['methodology']['weights']['reliability']['total'])
        ->toBe(25.0)
        ->and($result['sample']['configuration_count'])
        ->toBe(2)
        ->and($result['recommendation']['eligible'])
        ->toBeTrue()
        ->and($leader['harness'])
        ->toBe(AgentHarness::Codex->value)
        ->and($leader['sample_count'])
        ->toBe(10)
        ->and($leader['component_points']['quality']['total'])
        ->toBe(55.0)
        ->and($result['recommendation']['reason'])
        ->toContain('quality');
});

test('failed and blocked outcomes stay in score denominators while confidence counts only comparable completed tasks', function () {
    $project = p3020Project();
    $outcomes = p3020ComparableOutcomes(5, $project->id);

    $outcomes[] = p3020Outcome(
        taskId: 100,
        projectId: $project->id,
        workType: TaskWorkType::Feature,
        complexity: TaskComplexity::High,
        harness: AgentHarness::ClaudeCode->value,
        model: 'claude-sonnet',
        reasoningSetting: 'high',
        status: TaskStatus::Failed,
        reviewerApproved: false,
        validationPassed: false,
        operationalRetryOrBlock: true,
    );

    $outcomes[] = p3020Outcome(
        taskId: 101,
        projectId: $project->id,
        workType: TaskWorkType::Feature,
        complexity: TaskComplexity::High,
        harness: AgentHarness::ClaudeCode->value,
        model: 'claude-sonnet',
        reasoningSetting: 'high',
        status: TaskStatus::Blocked,
        reviewerApproved: false,
        validationPassed: false,
        operationalRetryOrBlock: true,
        noProgressRetryCondition: true,
    );

    $result = p3020Score($project, $outcomes);

    $claudeScore = collect($result['configuration_scores'])
        ->first(
            fn (array $score): bool => $score['configuration']['harness']
                === AgentHarness::ClaudeCode->value,
        );

    expect($result['sample']['terminal_task_count'])
        ->toBe(7)
        ->and($result['sample']['comparable_completed_task_count'])
        ->toBe(5)
        ->and($result['confidence']['level'])
        ->toBe(CoderHarnessComparableCohortScorecards::ConfidencePreliminary)
        ->and($claudeScore['sample_count'])
        ->toBe(4)
        ->and($claudeScore['successful_task_count'])
        ->toBe(2)
        ->and($claudeScore['failed_task_count'])
        ->toBe(1)
        ->and($claudeScore['blocked_task_count'])
        ->toBe(1);
});

test('scorecard calculation does not mutate Agent configuration or worker binding state', function () {
    $project = p3020Project();

    $agent = Agent::factory()->for($project)->create([
        'name' => 'Bound Coder',
        'role' => AgentRole::Coder,
        'harness' => AgentHarness::Codex,
        'model' => 'gpt-5',
        'reasoning_setting' => 'high',
        'enabled' => true,
    ]);

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'agent_id' => $agent->id,
        'status' => 'idle',
    ]);

    $agentBefore = $agent->fresh()->only([
        'name',
        'role',
        'harness',
        'model',
        'reasoning_setting',
        'enabled',
        'configuration_version',
    ]);

    $workerBefore = $worker->fresh()->only([
        'agent_id',
        'role',
        'status',
        'worker_instance_id',
        'lease_id',
    ]);

    $result = p3020Score(
        $project,
        p3020ComparableOutcomes(20, $project->id),
    );

    expect($result['confidence']['level'])
        ->toBe(CoderHarnessComparableCohortScorecards::ConfidenceRecommendationEligible)
        ->and($agent->fresh()->only(array_keys($agentBefore)))
        ->toBe($agentBefore)
        ->and($worker->fresh()->only(array_keys($workerBefore)))
        ->toBe($workerBefore);
});
