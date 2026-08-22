<?php

use App\Actions\CreateOrchestrationRecommendation;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\AuditEvent;
use App\Models\OrchestrationRecommendation;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Skill;
use App\Models\Task;
use App\OrchestrationRecommendationStatus;
use App\OrchestrationRecommendationType;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\Services\AgentContextAssembler;
use App\Services\AgentRunRecorder;
use App\TaskStatus;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Create the minimal persisted Project used by P5-002 recommendation tests.
 */
function p5002Project(string $name): Project
{
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/ageax-p5002-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Resolve the singleton global Orchestrator provisioned by P5-001.
 */
function p5002Orchestrator(): Agent
{
    return Agent::query()
        ->whereNull('project_id')
        ->where('role', AgentRole::Orchestrator)
        ->sole();
}

/**
 * Create a completed Orchestrator AgentRun using the existing immutable AgentRun snapshot path.
 *
 * @param  array<string, mixed>|null  $taskContext
 */
function p5002CompletedOrchestratorRun(
    Project $project,
    ?array $taskContext = null,
): AgentRun {
    $agent = p5002Orchestrator();

    $context = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Orchestrator,
        $taskContext ?? [
            'objective' => 'Evaluate bounded durable evidence.',
            'acceptance_criteria' => [
                'Return advisory recommendation evidence only.',
            ],
        ],
    );

    $prompt = json_encode(
        [
            'contract' => 'Evaluate durable evidence without mutating AIOS state.',
            'context' => $context->toArray(),
        ],
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
    );

    $run = app(AgentRunRecorder::class)->start(
        $project,
        AgentRole::Orchestrator,
        $prompt,
        agent: $agent,
        context: $context,
    );

    $run->update([
        'status' => AgentRunStatus::Completed,
        'exit_code' => 0,
        'finished_at' => now(),
    ]);

    return $run->refresh();
}

/**
 * Create a completed non-Orchestrator run to prove recommendation authority is role-bounded.
 */
function p5002CompletedCoderRun(Project $project): AgentRun
{
    $agent = Agent::factory()
        ->for($project)
        ->create([
            'role' => AgentRole::Coder,
        ]);

    $context = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Coder,
        [
            'objective' => 'Normal Coder execution.',
            'acceptance_criteria' => ['Do not gain Orchestrator authority.'],
        ],
    );

    $prompt = json_encode(
        ['context' => $context->toArray()],
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
    );

    $run = app(AgentRunRecorder::class)->start(
        $project,
        AgentRole::Coder,
        $prompt,
        agent: $agent,
        context: $context,
    );

    $run->update([
        'status' => AgentRunStatus::Completed,
        'exit_code' => 0,
        'finished_at' => now(),
    ]);

    return $run->refresh();
}

/**
 * Create a bounded Task for recommendation scope and immutability tests.
 */
function p5002Task(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'P5002-'.fake()->unique()->numberBetween(1000, 9999),
        'position' => 1,
        'title' => 'Recommendation evidence task',
        'objective' => 'Provide durable scope without granting recommendation authority.',
        'acceptance_criteria' => [
            'Task state remains controlled by AIOS.',
        ],
        'implementation_prompt' => 'Do not mutate workflow state.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

/**
 * Return one valid schema-version-1 payload for every approved recommendation type.
 *
 * @return array<string, mixed>
 */
function p5002Payload(OrchestrationRecommendationType $type): array
{
    return match ($type) {
        OrchestrationRecommendationType::AgentConfiguration => [
            'target_role' => AgentRole::Coder->value,
            'changes' => [
                'model' => 'candidate-model',
                'reasoning_setting' => 'high',
            ],
            'reason' => 'Use a configuration better suited to complex implementation work.',
        ],

        OrchestrationRecommendationType::HarnessModel => [
            'target_role' => AgentRole::Coder->value,
            'harness' => 'codex',
            'model' => 'candidate-model',
            'reason' => 'Comparable evidence favors this harness and model combination.',
        ],

        OrchestrationRecommendationType::ReasoningLevel => [
            'target_role' => AgentRole::Coder->value,
            'reasoning_setting' => 'high',
            'reason' => 'The task cohort requires deeper reasoning.',
        ],

        OrchestrationRecommendationType::RetryStrategy => [
            'strategy' => 'fresh_context_retry',
            'max_attempts' => 2,
            'backoff_seconds' => 30,
            'reason' => 'Retry only through a fresh bounded execution.',
        ],

        OrchestrationRecommendationType::ContextStrategy => [
            'strategy' => 'reduce_unrelated_history',
            'include_sources' => [
                'current_task',
                'validation_evidence',
            ],
            'exclude_sources' => [
                'unrelated_history',
            ],
            'reason' => 'Keep only the smallest sufficient evidence set.',
        ],

        OrchestrationRecommendationType::TaskDecomposition => [
            'summary' => 'Separate independent implementation concerns.',
            'tasks' => [
                [
                    'title' => 'First bounded task',
                    'objective' => 'Implement the first independent concern.',
                ],
                [
                    'title' => 'Second bounded task',
                    'objective' => 'Implement the second independent concern.',
                ],
            ],
            'reason' => 'The original work contains separable implementation concerns.',
        ],

        OrchestrationRecommendationType::RecoveryDirection => [
            'direction' => 'Escalate after the current bounded recovery attempt.',
            'reason' => 'Repeated evidence indicates that another automatic repair is unsafe.',
        ],

        OrchestrationRecommendationType::WorkflowImprovement => [
            'summary' => 'Improve deterministic pre-execution validation.',
            'proposed_change' => 'Add a deterministic validation gate before provider execution.',
            'reason' => 'The evidence shows repeated avoidable operational failures.',
        ],
    };
}

test('an Orchestrator recommendation persists immutable scoped evidence and relationships', function (): void {
    $project = p5002Project('P5-002 relationships');
    $task = p5002Task($project);

    $incident = RecoveryIncident::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'failure_type' => 'task_blocked',
        'status' => RecoveryIncidentStatus::Detected,
        'detected_at' => now(),
    ]);

    $run = p5002CompletedOrchestratorRun($project);

    $recommendation = app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::RecoveryDirection,
        1,
        '0.8750',
        p5002Payload(OrchestrationRecommendationType::RecoveryDirection),
        project: $project,
        task: $task,
        recoveryIncident: $incident,
    );

    expect($recommendation->recommendation_type)
        ->toBe(OrchestrationRecommendationType::RecoveryDirection)
        ->and($recommendation->status)
        ->toBe(OrchestrationRecommendationStatus::Active)
        ->and($recommendation->schema_version)
        ->toBe(1)
        ->and($recommendation->confidence)
        ->toBe('0.8750')
        ->and($recommendation->evidence_hash)
        ->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($recommendation->agentRun->is($run))
        ->toBeTrue()
        ->and($recommendation->project->is($project))
        ->toBeTrue()
        ->and($recommendation->task->is($task))
        ->toBeTrue()
        ->and($recommendation->recoveryIncident->is($incident))
        ->toBeTrue();
});

test('all approved recommendation types use their explicit schema version one contract', function (): void {
    $project = p5002Project('P5-002 schemas');

    foreach (OrchestrationRecommendationType::cases() as $type) {
        $run = p5002CompletedOrchestratorRun($project);

        $recommendation = app(CreateOrchestrationRecommendation::class)->handle(
            $run,
            $type,
            1,
            0.8,
            p5002Payload($type),
            project: $project,
        );

        expect($recommendation->recommendation_type)->toBe($type);
    }

    expect(OrchestrationRecommendation::query()->count())
        ->toBe(count(OrchestrationRecommendationType::cases()));
});

test('unsupported schema versions and unrestricted payload keys are rejected', function (): void {
    $project = p5002Project('P5-002 invalid schema');
    $run = p5002CompletedOrchestratorRun($project);

    expect(fn () => app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::HarnessModel,
        99,
        0.8,
        p5002Payload(OrchestrationRecommendationType::HarnessModel),
        project: $project,
    ))->toThrow(ValidationException::class);

    $payload = p5002Payload(OrchestrationRecommendationType::HarnessModel);
    $payload['apply'] = true;

    expect(fn () => app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::HarnessModel,
        1,
        0.8,
        $payload,
        project: $project,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::ReasoningLevel,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::HarnessModel),
        project: $project,
    ))->toThrow(ValidationException::class);
});

test('confidence must remain inside the normalized zero to one range', function (): void {
    $project = p5002Project('P5-002 confidence');
    $run = p5002CompletedOrchestratorRun($project);

    foreach ([-0.01, 1.01] as $confidence) {
        expect(fn () => app(CreateOrchestrationRecommendation::class)->handle(
            $run,
            OrchestrationRecommendationType::HarnessModel,
            1,
            $confidence,
            p5002Payload(OrchestrationRecommendationType::HarnessModel),
            project: $project,
        ))->toThrow(ValidationException::class);
    }
});

test('malformed source evidence hashes block recommendation persistence', function (): void {
    $project = p5002Project('P5-002 malformed hash');
    $run = p5002CompletedOrchestratorRun($project);

    $snapshot = $run->configuration_snapshot;
    $snapshot['context_hash'] = 'not-a-sha256-hash';

    $run->update([
        'configuration_snapshot' => $snapshot,
    ]);

    expect(fn () => app(CreateOrchestrationRecommendation::class)->handle(
        $run->refresh(),
        OrchestrationRecommendationType::ContextStrategy,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::ContextStrategy),
        project: $project,
    ))->toThrow(
        LogicException::class,
        'The Orchestrator AgentRun is missing valid immutable evidence hashes.',
    );

    expect(OrchestrationRecommendation::query()->count())->toBe(0);
});

test('non Orchestrator AgentRuns cannot create orchestration recommendations', function (): void {
    $project = p5002Project('P5-002 role boundary');
    $run = p5002CompletedCoderRun($project);

    expect(fn () => app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::RetryStrategy,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::RetryStrategy),
        project: $project,
    ))->toThrow(
        LogicException::class,
        'Only an Orchestrator AgentRun may produce an orchestration recommendation.',
    );

    expect(OrchestrationRecommendation::query()->count())->toBe(0);
});

test('recommendation scope cannot cross the source AgentRun project boundary', function (): void {
    $sourceProject = p5002Project('P5-002 source project');
    $foreignProject = p5002Project('P5-002 foreign project');
    $foreignTask = p5002Task($foreignProject);

    $foreignIncident = RecoveryIncident::create([
        'project_id' => $foreignProject->id,
        'task_id' => $foreignTask->id,
        'failure_type' => 'task_blocked',
        'status' => RecoveryIncidentStatus::Detected,
        'detected_at' => now(),
    ]);

    $run = p5002CompletedOrchestratorRun($sourceProject);

    expect(fn () => app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::TaskDecomposition,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::TaskDecomposition),
        project: $sourceProject,
        task: $foreignTask,
    ))->toThrow(LogicException::class);

    expect(fn () => app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::RecoveryDirection,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::RecoveryDirection),
        project: $sourceProject,
        recoveryIncident: $foreignIncident,
    ))->toThrow(LogicException::class);
});

test('the database prevents duplicate recommendation types from one AgentRun', function (): void {
    $project = p5002Project('P5-002 duplicate guard');
    $run = p5002CompletedOrchestratorRun($project);
    $action = app(CreateOrchestrationRecommendation::class);

    $action->handle(
        $run,
        OrchestrationRecommendationType::HarnessModel,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::HarnessModel),
        project: $project,
    );

    expect(fn () => $action->handle(
        $run,
        OrchestrationRecommendationType::HarnessModel,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::HarnessModel),
        project: $project,
    ))->toThrow(QueryException::class);

    $action->handle(
        $run,
        OrchestrationRecommendationType::ReasoningLevel,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::ReasoningLevel),
        project: $project,
    );

    expect(OrchestrationRecommendation::query()->count())->toBe(2);
});

test('persisted recommendation evidence cannot be updated or deleted directly', function (): void {
    $project = p5002Project('P5-002 immutable recommendation');
    $run = p5002CompletedOrchestratorRun($project);

    $recommendation = app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::WorkflowImprovement,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::WorkflowImprovement),
        project: $project,
    );

    expect(fn () => $recommendation->update([
        'confidence' => '0.1000',
    ]))->toThrow(
        LogicException::class,
        'Orchestration recommendation evidence is immutable.',
    );

    expect(fn () => $recommendation->delete())->toThrow(
        LogicException::class,
        'Orchestration recommendation evidence cannot be deleted directly.',
    );

    expect($recommendation->fresh())
        ->not->toBeNull()
        ->and($recommendation->fresh()?->confidence)
        ->toBe('0.8000');
});

test('later Agent configuration changes cannot change recommendation evidence', function (): void {
    $project = p5002Project('P5-002 historical evidence');
    $run = p5002CompletedOrchestratorRun($project);
    $agent = p5002Orchestrator();

    $recommendation = app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::AgentConfiguration,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::AgentConfiguration),
        project: $project,
    );

    $originalEvidenceHash = $recommendation->evidence_hash;
    $originalSnapshot = $run->configuration_snapshot;
    $originalVersion = $agent->configuration_version;

    $agent->update([
        'default_context' => 'Changed after the recommendation source run completed.',
    ]);

    expect($agent->refresh()->configuration_version)
        ->toBeGreaterThan($originalVersion)
        ->and($run->refresh()->configuration_snapshot)
        ->toEqual($originalSnapshot)
        ->and($recommendation->refresh()->evidence_hash)
        ->toBe($originalEvidenceHash);
});

test('recommendation creation records bounded audit evidence without copying the payload', function (): void {
    $project = p5002Project('P5-002 audit');
    $task = p5002Task($project);
    $run = p5002CompletedOrchestratorRun($project);

    $recommendation = app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::ContextStrategy,
        1,
        0.91,
        p5002Payload(OrchestrationRecommendationType::ContextStrategy),
        project: $project,
        task: $task,
    );

    $audit = AuditEvent::query()
        ->where('event_type', 'orchestrator.recommendation_created')
        ->where('project_id', $project->id)
        ->where('task_id', $task->id)
        ->sole();

    expect($audit->payload['recommendation_id'])
        ->toBe($recommendation->id)
        ->and($audit->payload['agent_run_id'])
        ->toBe($run->id)
        ->and($audit->payload['recommendation_type'])
        ->toBe(OrchestrationRecommendationType::ContextStrategy->value)
        ->and($audit->payload['schema_version'])
        ->toBe(1)
        ->and($audit->payload['evidence_hash'])
        ->toBe($recommendation->evidence_hash)
        ->and($audit->payload)
        ->not->toHaveKey('structured_recommendation');
});

test('recommendation persistence does not mutate operational AIOS state', function (): void {
    $project = p5002Project('P5-002 advisory only');
    $task = p5002Task($project);
    $run = p5002CompletedOrchestratorRun($project);
    $orchestrator = p5002Orchestrator();

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]);

    $before = [
        'task_status' => $task->getRawOriginal('status'),
        'agent_configuration_version' => $orchestrator->configuration_version,
        'worker_status' => $worker->status,
        'project_git_status' => $project->git_status,
        'skill_count' => Skill::query()
            ->where('project_id', $project->id)
            ->count(),
    ];

    app(CreateOrchestrationRecommendation::class)->handle(
        $run,
        OrchestrationRecommendationType::WorkflowImprovement,
        1,
        0.8,
        p5002Payload(OrchestrationRecommendationType::WorkflowImprovement),
        project: $project,
        task: $task,
    );

    expect($task->refresh()->getRawOriginal('status'))
        ->toBe($before['task_status'])
        ->and($orchestrator->refresh()->configuration_version)
        ->toBe($before['agent_configuration_version'])
        ->and($worker->refresh()->status)
        ->toBe($before['worker_status'])
        ->and($project->refresh()->git_status)
        ->toBe($before['project_git_status'])
        ->and(
            Skill::query()
                ->where('project_id', $project->id)
                ->count(),
        )
        ->toBe($before['skill_count']);
});
