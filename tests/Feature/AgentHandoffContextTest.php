<?php

use App\Actions\ConsumeAgentHandoffs;
use App\Actions\CreateAgentHandoff;
use App\AgentHandoffStatus;
use App\AgentHandoffType;
use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\ReviewStatus;
use App\Services\AgentContextAssembler;
use App\Services\AgentHandoffContextSelector;
use App\Services\AgentHandoffSchemaValidator;
use App\Services\AgentHarness;
use App\Services\ContextBudgetedAgentHarness;
use App\Services\HarnessCapabilities;
use App\Services\NormalizedExecutionResult;
use App\TaskStatus;

/**
 * Create one project-scoped P8-003 fixture.
 */
function p8003Project(string $name): Project
{
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/ageax-p8003-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one workflow Task in the requested durable state.
 */
function p8003Task(
    Project $project,
    TaskStatus $status,
    string $key = 'P8003-001',
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => 1,
        'title' => 'Fresh handoff context task',
        'objective' => 'Consume only current durable typed handoff evidence.',
        'acceptance_criteria' => [
            'Handoffs remain bounded, fresh, and auditable.',
        ],
        'implementation_prompt' => 'Preserve AIOS authority and fresh execution context.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

/**
 * Create one TaskAttempt with deterministic validation evidence.
 */
function p8003Attempt(
    Task $task,
    int $number,
    string $status = 'completed',
    bool $passed = true,
    ?array $repositoryPreflight = null,
): TaskAttempt {
    $validation = [
        'passed' => $passed,
        'checks' => [
            'task_commit' => $passed,
        ],
    ];

    if ($repositoryPreflight !== null) {
        $validation['repository_preflight'] = $repositoryPreflight;
    }

    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => $number,
        'base_sha' => str_repeat((string) min($number, 9), 40),
        'head_sha' => str_repeat((string) min($number, 9), 40),
        'status' => $status,
        'validation_results' => $validation,
        'changed_files' => [],
        'started_at' => now()->subSecond(),
        'finished_at' => $status === 'running' ? null : now(),
    ]);
}

/**
 * Create one task-scoped AgentRun with the requested durable execution state.
 *
 * @param  array<string, mixed>|null  $configurationSnapshot
 * @param  array<string, mixed>|null  $budgetSnapshot
 */
function p8003Run(
    Project $project,
    Task $task,
    AgentRole $role,
    AgentRunStatus $status,
    int $attemptNumber,
    ?Agent $agent = null,
    ?array $configurationSnapshot = null,
    ?array $budgetSnapshot = null,
    ?RecoveryIncident $incident = null,
    ?string $promptHash = null,
): AgentRun {
    return AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'recovery_incident_id' => $incident?->id,
        'agent_id' => $agent?->id,
        'role' => $role->value,
        'harness' => $agent?->getRawOriginal('harness'),
        'status' => $status->value,
        'attempt_number' => $attemptNumber,
        'prompt_hash' => $promptHash ?? hash('sha256', fake()->uuid()),
        'configuration_snapshot' => $configurationSnapshot,
        'context_budget_snapshot' => $budgetSnapshot,
        'started_at' => now()->subSecond(),
        'finished_at' => $status === AgentRunStatus::Running ? null : now(),
    ]);
}

/**
 * Persist one valid implementation handoff through the authoritative P8-001 Action.
 */
function p8003ImplementationHandoff(
    AgentRun $sourceRun,
): AgentHandoff {
    return app(CreateAgentHandoff::class)->handle(
        $sourceRun,
        AgentRole::Reviewer,
        AgentHandoffType::ImplementationHandoff,
        AgentHandoffSchemaValidator::SchemaVersion,
        [
            'summary' => 'The exact Coder attempt passed deterministic AIOS validation.',
            'changed_files' => [],
            'verification_attempts' => [
                'Focused deterministic verification passed.',
            ],
        ],
    );
}

/**
 * Persist one valid Reviewer finding handoff through the authoritative P8-001 Action.
 */
function p8003ReviewFindingHandoff(
    AgentRun $sourceRun,
): AgentHandoff {
    return app(CreateAgentHandoff::class)->handle(
        $sourceRun,
        AgentRole::Coder,
        AgentHandoffType::ReviewFinding,
        AgentHandoffSchemaValidator::SchemaVersion,
        [
            'summary' => 'One deterministic correction is required.',
            'findings' => [
                [
                    'severity' => 'high',
                    'location' => 'app/Example.php',
                    'current_implementation' => 'The required guard is missing.',
                    'expected_implementation' => 'The required guard must be present.',
                    'why_incorrect' => 'The acceptance criterion is not satisfied.',
                    'required_fix' => 'Add the deterministic guard.',
                    'verification_requirement' => 'Run the focused regression test.',
                    'implementation_fix_context' => 'Preserve the existing AIOS-owned workflow boundary.',
                ],
            ],
        ],
    );
}

/**
 * Persist one valid Recovery Engineer advice handoff through the authoritative P8-001 Action.
 */
function p8003RecoveryAdviceHandoff(
    AgentRun $sourceRun,
): AgentHandoff {
    return app(CreateAgentHandoff::class)->handle(
        $sourceRun,
        AgentRole::Coder,
        AgentHandoffType::RecoveryAdvice,
        AgentHandoffSchemaValidator::SchemaVersion,
        [
            'summary' => 'AIOS accepted a bounded recovery diagnosis.',
            'root_cause_category' => 'managed_project_defect',
            'recommended_focus' => 'Resume through the normal Coder workflow.',
            'changed_files' => [],
            'escalation_reason' => null,
        ],
    );
}

/**
 * Build a provider-neutral harness with deterministic test capacity.
 */
function p8003Harness(int $capacityTokens): AgentHarness
{
    return new class($capacityTokens) implements AgentHarness
    {
        /**
         * Store the deterministic capacity exposed to Context Budget.
         */
        public function __construct(
            private int $capacityTokens,
        ) {}

        /**
         * Identify this test harness using the existing Codex identifier.
         */
        public function identifier(): AgentHarnessIdentifier
        {
            return AgentHarnessIdentifier::Codex;
        }

        /**
         * Expose deterministic null-model capacity without introducing another capability registry.
         */
        public function capabilities(): HarnessCapabilities
        {
            return new HarnessCapabilities(
                defaultContextWindowTokens: $this->capacityTokens,
                defaultMaxOutputTokens: max(
                    1,
                    (int) floor($this->capacityTokens * 0.2),
                ),
                capacityMetadataSource: 'p8003-test',
                capacityMetadataVersion: 1,
            );
        }

        /**
         * Return a successful normalized result when the compatibility method is invoked directly.
         */
        public function execute(
            Project $project,
            Agent $agent,
            string $prompt,
            ?Closure $onOutput = null,
            ?Closure $onHeartbeat = null,
        ): NormalizedExecutionResult {
            return new NormalizedExecutionResult(
                exitCode: 0,
                output: '{"ok":true}',
                errorOutput: '',
            );
        }
    };
}

/**
 * Build one assembled Reviewer prompt and its matching running AgentRun.
 *
 * @return array{0: Agent, 1: string, 2: AgentRun}
 */
function p8003ReviewerExecution(
    Project $project,
    Task $task,
    TaskAttempt $attempt,
): array {
    $agent = Agent::factory()
        ->for($project)
        ->create([
            'role' => AgentRole::Reviewer,
            'model' => null,
            'default_context' => null,
        ]);

    $assembled = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Reviewer,
        [
            'task_key' => $task->key,
            'objective' => $task->objective,
            'acceptance_criteria' => $task->acceptance_criteria,
        ],
    );

    $prompt = "Reviewer contract.\n\n".json_encode(
        $assembled->toArray(),
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
    );

    $run = p8003Run(
        $project,
        $task,
        AgentRole::Reviewer,
        AgentRunStatus::Running,
        $attempt->number,
        $agent,
        $assembled->configurationSnapshot(),
        promptHash: hash('sha256', $prompt),
    );

    return [$agent, $prompt, $run];
}

test('a fresh Reviewer execution receives and consumes only the current implementation handoff', function (): void {
    $project = p8003Project('P8-003 Reviewer delivery');
    $task = p8003Task($project, TaskStatus::Reviewing);
    $attempt = p8003Attempt($task, 1);

    $sourceRun = p8003Run(
        $project,
        $task,
        AgentRole::Coder,
        AgentRunStatus::Completed,
        1,
    );

    $handoff = p8003ImplementationHandoff($sourceRun);

    [$agent, $prompt, $targetRun] = p8003ReviewerExecution(
        $project,
        $task,
        $attempt,
    );

    $providerCalls = 0;
    $approvedPrompt = null;

    $result = app(ContextBudgetedAgentHarness::class)->execute(
        p8003Harness(100000),
        $project,
        $agent,
        $prompt,
        function (
            string $finalPrompt,
            ?Closure $onOutput,
            ?Closure $onHeartbeat,
        ) use (
            &$providerCalls,
            &$approvedPrompt,
        ): NormalizedExecutionResult {
            $providerCalls++;
            $approvedPrompt = $finalPrompt;

            return new NormalizedExecutionResult(
                exitCode: 0,
                output: '{"ok":true}',
                errorOutput: '',
            );
        },
    );

    $handoff->refresh();
    $targetRun->refresh();

    expect($result->exitCode)
        ->toBe(0)
        ->and($providerCalls)
        ->toBe(1)
        ->and($approvedPrompt)
        ->toBeString()
        ->and($approvedPrompt)
        ->toContain('"agent_handoffs"')
        ->and($handoff->getRawOriginal('status'))
        ->toBe(AgentHandoffStatus::Consumed->value)
        ->and($handoff->consumed_at)
        ->not->toBeNull()
        ->and($task->refresh()->status)
        ->toBe(TaskStatus::Reviewing)
        ->and(
            $targetRun->context_budget_snapshot[
                'agent_handoff_ids'
            ],
        )
        ->toBe([$handoff->id])
        ->and(
            $targetRun->context_budget_snapshot[
                'source_contributions'
            ]['original']['agent_handoffs']['estimated_tokens'],
        )
        ->toBeGreaterThan(0);

    $audit = AuditEvent::query()
        ->where('event_type', 'agent_handoff.consumed')
        ->where('payload->agent_handoff_id', $handoff->id)
        ->firstOrFail();

    expect($audit->payload['source_agent_run_id'])
        ->toBe($sourceRun->id)
        ->and($audit->payload['target_agent_run_id'])
        ->toBe($targetRun->id)
        ->and($audit->payload['context_hash'])
        ->toBe(
            $targetRun->context_budget_snapshot[
                'final_context_hash'
            ],
        );
});

test('a superseded implementation handoff is not silently reused by a fresh Reviewer execution', function (): void {
    $project = p8003Project('P8-003 stale implementation');
    $task = p8003Task($project, TaskStatus::Reviewing);
    $attempt = p8003Attempt($task, 1);

    $staleSource = p8003Run(
        $project,
        $task,
        AgentRole::Coder,
        AgentRunStatus::Completed,
        1,
    );

    $staleHandoff = p8003ImplementationHandoff(
        $staleSource,
    );

    p8003Run(
        $project,
        $task,
        AgentRole::Coder,
        AgentRunStatus::Completed,
        1,
    );

    [, , $targetRun] = p8003ReviewerExecution(
        $project,
        $task,
        $attempt,
    );

    $selection = app(
        AgentHandoffContextSelector::class,
    )->select($targetRun);

    expect($selection['handoff_ids'])
        ->toBe([])
        ->and(
            $staleHandoff
                ->refresh()
                ->getRawOriginal('status'),
        )
        ->toBe(AgentHandoffStatus::Pending->value)
        ->and($staleHandoff->consumed_at)
        ->toBeNull();
});

test('a Review Finding is injected only into the immediately following corrective Coder attempt', function (): void {
    $project = p8003Project('P8-003 corrective Coder');
    $task = p8003Task($project, TaskStatus::Coding);
    $rejectedAttempt = p8003Attempt($task, 1);

    Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $rejectedAttempt->id,
        'status' => ReviewStatus::ChangesRequired,
        'summary' => 'The implementation requires one correction.',
        'started_at' => now()->subSecond(),
        'completed_at' => now(),
    ]);

    $reviewerRun = p8003Run(
        $project,
        $task,
        AgentRole::Reviewer,
        AgentRunStatus::Completed,
        1,
    );

    $handoff = p8003ReviewFindingHandoff($reviewerRun);

    p8003Attempt(
        $task,
        2,
        'running',
        false,
    );

    $targetRun = p8003Run(
        $project,
        $task,
        AgentRole::Coder,
        AgentRunStatus::Running,
        2,
    );

    $selection = app(
        AgentHandoffContextSelector::class,
    )->select($targetRun);

    expect($selection['handoff_ids'])
        ->toBe([$handoff->id])
        ->and($selection['entries'][0]['handoff_type'])
        ->toBe(AgentHandoffType::ReviewFinding->value);

    $laterRun = p8003Run(
        $project,
        $task,
        AgentRole::Coder,
        AgentRunStatus::Running,
        3,
    );

    expect(
        app(AgentHandoffContextSelector::class)
            ->select($laterRun)['handoff_ids'],
    )->toBe([]);
});

test('accepted Recovery Advice is bound to the durable recovery lineage of the next Coder attempt', function (): void {
    $project = p8003Project('P8-003 recovery advice');
    $task = p8003Task($project, TaskStatus::Coding);

    p8003Attempt($task, 1);

    $failedRun = p8003Run(
        $project,
        $task,
        AgentRole::Coder,
        AgentRunStatus::Failed,
        1,
    );

    $incident = RecoveryIncident::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'failure_type' => 'task_failed',
        'status' => RecoveryIncidentStatus::Recovered,
        'detected_at' => now()->subMinute(),
        'evidence' => [
            'latest_attempt' => [
                'number' => 1,
            ],
            'latest_run' => [
                'id' => $failedRun->id,
            ],
        ],
        'root_cause' => 'A bounded managed-project failure was diagnosed.',
        'root_cause_category' => 'managed_project_defect',
        'recoverable' => true,
        'fix_summary' => 'Retry through the normal Coder workflow.',
        'resulting_task_transition' => TaskStatus::ChangesRequired->value,
        'resolved_at' => now(),
    ]);

    $recoveryRun = p8003Run(
        $project,
        $task,
        AgentRole::RecoveryEngineer,
        AgentRunStatus::Completed,
        1,
        incident: $incident,
    );

    $handoff = p8003RecoveryAdviceHandoff(
        $recoveryRun,
    );

    p8003Attempt(
        $task,
        2,
        'running',
        false,
    );

    $targetRun = p8003Run(
        $project,
        $task,
        AgentRole::Coder,
        AgentRunStatus::Running,
        2,
    );

    $selection = app(
        AgentHandoffContextSelector::class,
    )->select($targetRun);

    expect($selection['handoff_ids'])
        ->toBe([$handoff->id])
        ->and($selection['entries'][0]['handoff_type'])
        ->toBe(AgentHandoffType::RecoveryAdvice->value);
});

test('Context Budget blocks provider dispatch before consuming required handoff evidence', function (): void {
    $project = p8003Project('P8-003 handoff budget block');
    $task = p8003Task($project, TaskStatus::Reviewing);
    $attempt = p8003Attempt($task, 1);

    $sourceRun = p8003Run(
        $project,
        $task,
        AgentRole::Coder,
        AgentRunStatus::Completed,
        1,
    );

    $handoff = p8003ImplementationHandoff($sourceRun);

    [$agent, $prompt, $targetRun] = p8003ReviewerExecution(
        $project,
        $task,
        $attempt,
    );

    $providerCalls = 0;

    $result = app(ContextBudgetedAgentHarness::class)->execute(
        p8003Harness(500),
        $project,
        $agent,
        $prompt,
        function (
            string $finalPrompt,
            ?Closure $onOutput,
            ?Closure $onHeartbeat,
        ) use (&$providerCalls): NormalizedExecutionResult {
            $providerCalls++;

            return new NormalizedExecutionResult(
                exitCode: 0,
                output: '{"ok":true}',
                errorOutput: '',
            );
        },
    );

    $handoff->refresh();
    $targetRun->refresh();

    expect($result->exitCode)
        ->toBe(-1)
        ->and($providerCalls)
        ->toBe(0)
        ->and($handoff->getRawOriginal('status'))
        ->toBe(AgentHandoffStatus::Pending->value)
        ->and($handoff->consumed_at)
        ->toBeNull()
        ->and(
            $targetRun->context_budget_snapshot[
                'source_contributions'
            ]['original']['agent_handoffs']['estimated_tokens'],
        )
        ->toBeGreaterThan(0)
        ->and(
            AuditEvent::query()
                ->where(
                    'event_type',
                    'agent_handoff.consumed',
                )
                ->where(
                    'payload->agent_handoff_id',
                    $handoff->id,
                )
                ->exists(),
        )
        ->toBeFalse();
});

test('interrupted execution replays its exact consumed handoff evidence without consuming it twice', function (): void {
    $project = p8003Project('P8-003 interrupted replay');
    $task = p8003Task($project, TaskStatus::Reviewing);
    $attempt = p8003Attempt($task, 1);

    $sourceRun = p8003Run(
        $project,
        $task,
        AgentRole::Coder,
        AgentRunStatus::Completed,
        1,
    );

    $handoff = p8003ImplementationHandoff($sourceRun);

    $agent = Agent::factory()
        ->for($project)
        ->create([
            'role' => AgentRole::Reviewer,
            'model' => null,
        ]);

    $assembled = app(AgentContextAssembler::class)->assemble(
        $agent,
        AgentRole::Reviewer,
        [
            'task_key' => $task->key,
            'objective' => $task->objective,
            'acceptance_criteria' => $task->acceptance_criteria,
        ],
    );

    $configurationSnapshot =
        $assembled->configurationSnapshot();

    $contextHash = hash(
        'sha256',
        'p8003-replay-context',
    );

    $interruptedRun = p8003Run(
        $project,
        $task,
        AgentRole::Reviewer,
        AgentRunStatus::Running,
        1,
        $agent,
        $configurationSnapshot,
    );

    app(ConsumeAgentHandoffs::class)->handle(
        $interruptedRun,
        [$handoff->id],
        $contextHash,
    );

    $interruptedRun->update([
        'status' => AgentRunStatus::Interrupted->value,
        'context_budget_snapshot' => [
            'agent_handoff_ids' => [$handoff->id],
            'agent_handoff_content_hashes' => [
                $handoff->id => $handoff->content_hash,
            ],
            'final_context_hash' => $contextHash,
        ],
        'finished_at' => now(),
    ]);

    $recoveryRun = p8003Run(
        $project,
        $task,
        AgentRole::Reviewer,
        AgentRunStatus::Running,
        1,
        $agent,
        $configurationSnapshot,
    );

    $selection = app(
        AgentHandoffContextSelector::class,
    )->select(
        $recoveryRun,
        $interruptedRun->refresh(),
    );

    expect($selection['handoff_ids'])
        ->toBe([$handoff->id])
        ->and($selection['pending_handoff_ids'])
        ->toBe([])
        ->and($selection['replay_source_agent_run_id'])
        ->toBe($interruptedRun->id)
        ->and(
            AuditEvent::query()
                ->where(
                    'event_type',
                    'agent_handoff.consumed',
                )
                ->where(
                    'payload->agent_handoff_id',
                    $handoff->id,
                )
                ->count(),
        )
        ->toBe(1);
});
