<?php

use App\Actions\ClaimTicketForTriage;
use App\Actions\ConvertTicketToTask;
use App\Actions\DecideTicketEscalation;
use App\Actions\RunTicketTriage;
use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketEscalationDecision;
use App\Models\TicketTriageAttempt;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentHarness as AgentHarnessContract;
use App\Services\AgentHarnessResolver;
use App\Services\HarnessCapabilities;
use App\Services\NormalizedExecutionResult;
use App\Services\TicketTriagePolicy;
use App\TaskStatus;
use App\TicketEscalationReason;
use App\TicketMessageType;
use App\TicketOperatorAction;
use App\TicketStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class P3TicketEscalationHarness implements AgentHarnessContract
{
    /** @var list<string> */
    public array $prompts = [];

    public function __construct(public NormalizedExecutionResult $result) {}

    public function identifier(): AgentHarnessIdentifier
    {
        return AgentHarnessIdentifier::Codex;
    }

    public function capabilities(): HarnessCapabilities
    {
        return new HarnessCapabilities;
    }

    public function execute(
        Project $project,
        Agent $agent,
        string $prompt,
        ?Closure $onOutput = null,
        ?Closure $onHeartbeat = null,
        array $executionSettings = [],
        ?string $executionPath = null,
    ): NormalizedExecutionResult {
        $this->prompts[] = $prompt;
        $onOutput?->__invoke('stdout', $this->result->output);
        $onHeartbeat?->__invoke();

        return $this->result;
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set(
        'aios.obsidian_vault_path',
        storage_path('framework/testing/obsidian-p3-009-'.fake()->uuid()),
    );
});

function p3EscalationProject(string $name = 'Ticket Escalation'): Project
{
    $path = sys_get_temp_dir().'/ageax-p3-009-'.fake()->uuid();

    File::ensureDirectoryExists($path);
    File::put(
        $path.'/MASTER-PROMPT.md',
        "# Test Governance\nRisky ticket handling requires deterministic AIOS escalation.\n",
    );
    File::put(
        $path.'/AGENTS.md',
        "# Test Agents\nProject Manager output is proposal-only.\n",
    );

    return Project::create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function p3EscalationBindPm(Project $project): Agent
{
    $agent = Agent::factory()
        ->for($project)
        ->create([
            'name' => 'P3-009 PM',
            'role' => AgentRole::ProjectManager,
            'harness' => AgentHarnessIdentifier::Codex,
            'model' => null,
            'reasoning_setting' => null,
            'default_context' => 'Enforce the P3-009 ticket triage contract.',
            'enabled' => true,
        ]);

    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'agent_id' => $agent->id,
        'status' => 'idle',
    ]);

    return $agent;
}

function p3EscalationTicket(
    Project $project,
    string $key = 'TICKET-000001',
): Ticket {
    return Ticket::factory()
        ->for($project)
        ->create([
            'key' => $key,
            'title' => 'Evaluate deterministic ticket escalation',
            'description' => 'A bounded ticket used by P3-009 regression coverage.',
            'status' => TicketStatus::Open,
        ]);
}

/** @return array<string, mixed> */
function p3EscalationDecision(array $overrides = []): array
{
    return array_replace([
        'category' => 'enhancement',
        'decision' => 'approved',
        'confidence' => 0.94,
        'summary' => 'The request is clear and bounded.',
        'documentation_alignment' => [
            'No approved-documentation conflict was identified.',
        ],
        'affected_areas' => ['app/Actions'],
        'complexity' => 'low',
        'requester_reply' => 'The request is clear and ready for deterministic handling.',
        'internal_reason_summary' => 'Bounded decision evidence without chain-of-thought.',
        'questions' => [],
        'blockers' => [],
        'duplicate_ticket_id' => null,
        'suggested_priority' => 'normal',
        'implementation_required' => true,
        'proposed_task' => [
            'title' => 'Implement the bounded ticket change',
            'objective' => 'Implement exactly one safe change.',
            'acceptance_criteria' => ['The requested behavior is covered.'],
            'scope' => ['app/Actions'],
            'constraints' => ['Preserve deterministic AIOS state ownership.'],
            'relevant_paths' => ['app/Actions'],
            'verification_commands' => [
                'php artisan test --compact tests/Feature/TicketTriageEscalationTest.php',
            ],
            'implementation_prompt' => 'Implement the smallest correct change.',
            'depends_on_task_ids' => [],
            'preferred_phase_id' => null,
        ],
        'escalation_flags' => [],
    ], $overrides);
}

function p3UseEscalationHarness(array $decision): P3TicketEscalationHarness
{
    $harness = new P3TicketEscalationHarness(
        new NormalizedExecutionResult(
            exitCode: 0,
            output: json_encode($decision, JSON_THROW_ON_ERROR),
            errorOutput: '',
            externalRunId: 'p3-009-ticket-triage',
        ),
    );

    app()->instance(
        AgentHarnessResolver::class,
        new AgentHarnessResolver([$harness]),
    );

    return $harness;
}

function p3RunEscalationTriage(
    Project $project,
    Ticket $ticket,
    array $decision,
): TicketTriageAttempt {
    p3UseEscalationHarness($decision);

    $attempt = app(ClaimTicketForTriage::class)->handle($project);

    expect($attempt)->not->toBeNull();

    app(RunTicketTriage::class)->handle($attempt);

    return $attempt->refresh();
}

/** @return array{0: Phase, 1: Task} */
function p3EscalationPendingTask(Project $project, int $phasePosition = 1): array
{
    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => $phasePosition,
        'title' => "Phase {$phasePosition}",
        'objective' => 'Existing roadmap work.',
    ]);
    $task = Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'TASK-'.str_pad((string) $phasePosition, 3, '0', STR_PAD_LEFT),
        'position' => $phasePosition,
        'title' => 'Existing task',
        'objective' => 'Existing queued work must remain ordered.',
        'acceptance_criteria' => ['Preserve ordering.'],
        'implementation_prompt' => 'Preserve existing ordering.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);

    return [$phase, $task];
}

dataset('p3 semantic escalation reasons', [
    TicketEscalationReason::UnclearOrContradictoryRequirements->value,
    TicketEscalationReason::ArchitecturalDecisionRequired->value,
    TicketEscalationReason::BreakingPublicApiOrDataContract->value,
    TicketEscalationReason::MaterialSchemaOrDataMigrationRisk->value,
    TicketEscalationReason::DestructiveOperation->value,
    TicketEscalationReason::SecurityPrivacyOrAuthJudgmentRequired->value,
    TicketEscalationReason::ApprovedDocumentationConflict->value,
    TicketEscalationReason::BusinessPriorityUnclear->value,
    TicketEscalationReason::MultipleTasksOrPhasesRequired->value,
    TicketEscalationReason::RoadmapOrPhaseReorderingOrInterruptionRequested->value,
    TicketEscalationReason::CriticalOrEmergencyPreemptionRequested->value,
    TicketEscalationReason::UnsafeOrUnresolvedDependencyPlacement->value,
]);

test('every canonical semantic risk signal requires operator escalation regardless of high confidence', function (string $reason): void {
    $project = p3EscalationProject();
    $ticket = p3EscalationTicket($project);

    $result = app(TicketTriagePolicy::class)->evaluate(
        $ticket,
        p3EscalationDecision([
            'confidence' => 0.99,
            'escalation_flags' => [$reason],
        ]),
    );

    expect($result['requires_operator_decision'])
        ->toBeTrue()
        ->and($result['automatic_task_conversion_eligible'])
        ->toBeFalse()
        ->and($result['escalation_reasons'])
        ->toContain($reason);
})->with('p3 semantic escalation reasons');

test('confidence threshold is inclusive at 0.80 and escalates only below it', function (): void {
    $project = p3EscalationProject();
    $ticket = p3EscalationTicket($project);
    $policy = app(TicketTriagePolicy::class);

    $atThreshold = $policy->evaluate(
        $ticket,
        p3EscalationDecision(['confidence' => 0.80]),
    );
    $belowThreshold = $policy->evaluate(
        $ticket,
        p3EscalationDecision(['confidence' => 0.799]),
    );

    expect($atThreshold['requires_operator_decision'])
        ->toBeFalse()
        ->and($atThreshold['automatic_task_conversion_eligible'])
        ->toBeTrue()
        ->and($belowThreshold['requires_operator_decision'])
        ->toBeTrue()
        ->and($belowThreshold['escalation_reasons'])
        ->toContain(TicketEscalationReason::LowConfidence->value);
});

test('high complexity escalates even when pm flags are empty', function (): void {
    $project = p3EscalationProject();
    $ticket = p3EscalationTicket($project);

    $result = app(TicketTriagePolicy::class)->evaluate(
        $ticket,
        p3EscalationDecision([
            'confidence' => 0.99,
            'complexity' => 'high',
            'escalation_flags' => [],
        ]),
    );

    expect($result['escalation_reasons'])
        ->toContain(TicketEscalationReason::HighComplexity->value)
        ->and($result['automatic_task_conversion_eligible'])
        ->toBeFalse();
});

test('critical or emergency work with existing roadmap work requires explicit operator approval even when pm flags are empty', function (string $priority): void {
    $project = p3EscalationProject();
    $ticket = p3EscalationTicket($project);
    p3EscalationPendingTask($project);

    $result = app(TicketTriagePolicy::class)->evaluate(
        $ticket,
        p3EscalationDecision([
            'confidence' => 0.99,
            'suggested_priority' => $priority,
            'escalation_flags' => [],
        ]),
    );

    expect($result['escalation_reasons'])
        ->toContain(TicketEscalationReason::CriticalOrEmergencyPreemptionRequested->value)
        ->and($result['requires_operator_decision'])
        ->toBeTrue();
})->with(['critical', 'emergency']);

test('unsafe dependency placement is derived without trusting pm escalation flags', function (): void {
    $project = p3EscalationProject();
    $ticket = p3EscalationTicket($project);
    [$preferredPhase] = p3EscalationPendingTask($project, 1);
    [$futurePhase, $futureDependency] = p3EscalationPendingTask($project, 2);

    expect($futurePhase->position)->toBeGreaterThan($preferredPhase->position);

    $decision = p3EscalationDecision();
    $decision['proposed_task']['preferred_phase_id'] = $preferredPhase->id;
    $decision['proposed_task']['depends_on_task_ids'] = [$futureDependency->id];

    $result = app(TicketTriagePolicy::class)->evaluate($ticket, $decision);

    expect($result['escalation_reasons'])
        ->toContain(TicketEscalationReason::UnsafeOrUnresolvedDependencyPlacement->value);
});

test('preferred placement before the current phase is treated as roadmap reordering', function (): void {
    $project = p3EscalationProject();
    $ticket = p3EscalationTicket($project);
    $pastPhase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'Past phase',
        'objective' => 'Already completed phase.',
    ]);
    $pastTask = Task::create([
        'project_id' => $project->id,
        'phase_id' => $pastPhase->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Completed task',
        'objective' => 'Completed work.',
        'acceptance_criteria' => ['Done.'],
        'implementation_prompt' => 'Already complete.',
        'context_capsule' => [],
        'status' => TaskStatus::Done,
    ]);
    [$currentPhase] = p3EscalationPendingTask($project, 2);

    expect($pastTask->status)->toBe(TaskStatus::Done)
        ->and($currentPhase->position)->toBe(2);

    $decision = p3EscalationDecision();
    $decision['proposed_task']['preferred_phase_id'] = $pastPhase->id;

    $result = app(TicketTriagePolicy::class)->evaluate($ticket, $decision);

    expect($result['escalation_reasons'])
        ->toContain(TicketEscalationReason::RoadmapOrPhaseReorderingOrInterruptionRequested->value);
});

test('routine low risk approved triage remains eligible for downstream automatic conversion without operator approval', function (): void {
    $project = p3EscalationProject();
    $ticket = p3EscalationTicket($project);

    $result = app(TicketTriagePolicy::class)->evaluate(
        $ticket,
        p3EscalationDecision(),
    );

    expect($result)->toMatchArray([
        'requires_operator_decision' => false,
        'automatic_task_conversion_eligible' => true,
        'escalation_reasons' => [],
    ]);
});

test('run ticket triage persists deterministic escalation evidence and blocks automatic work creation', function (): void {
    $project = p3EscalationProject();
    p3EscalationBindPm($project);
    $ticket = p3EscalationTicket($project);

    $attempt = p3RunEscalationTriage(
        $project,
        $ticket,
        p3EscalationDecision([
            'confidence' => 0.99,
            'escalation_flags' => [
                TicketEscalationReason::ArchitecturalDecisionRequired->value,
            ],
        ]),
    );

    $validation = $attempt->structured_decision['aios_validation'] ?? null;

    expect($attempt->status)
        ->toBe('completed')
        ->and($validation)
        ->toMatchArray([
            'requires_operator_decision' => true,
            'automatic_task_conversion_eligible' => false,
            'escalation_reasons' => [
                TicketEscalationReason::ArchitecturalDecisionRequired->value,
            ],
        ])
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($project->tasks()->count())
        ->toBe(0)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.escalated')
            ->where('payload->ticket_triage_attempt_id', $attempt->id)
            ->exists())
        ->toBeTrue()
        ->and(app(ClaimTicketForTriage::class)->handle($project))
        ->toBeNull();
});

test('routine run records downstream eligibility without forcing operator escalation', function (): void {
    $project = p3EscalationProject();
    p3EscalationBindPm($project);
    $ticket = p3EscalationTicket($project);

    $attempt = p3RunEscalationTriage(
        $project,
        $ticket,
        p3EscalationDecision(),
    );

    expect($attempt->structured_decision['aios_validation'])
        ->toMatchArray([
            'requires_operator_decision' => false,
            'automatic_task_conversion_eligible' => true,
            'escalation_reasons' => [],
        ])
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Triaging)
        ->and($project->tasks()->count())
        ->toBe(0);
});

test('operator direction is project scoped idempotent auditable and becomes fresh retriage context', function (): void {
    $operator = User::factory()->create();
    $project = p3EscalationProject();
    p3EscalationBindPm($project);
    $ticket = p3EscalationTicket($project);
    $harness = p3UseEscalationHarness(
        p3EscalationDecision([
            'escalation_flags' => [
                TicketEscalationReason::BusinessPriorityUnclear->value,
            ],
        ]),
    );

    $firstAttempt = app(ClaimTicketForTriage::class)->handle($project);
    expect($firstAttempt)->not->toBeNull();
    app(RunTicketTriage::class)->handle($firstAttempt);
    $firstAttempt = $firstAttempt->refresh();

    $direction = 'Keep the existing roadmap order and treat this as normal priority.';
    $action = app(DecideTicketEscalation::class);
    $firstDecision = $action->handle(
        $ticket->refresh(),
        $firstAttempt,
        $operator,
        TicketOperatorAction::ProvideDirection,
        $direction,
    );
    $secondDecision = $action->handle(
        $ticket->refresh(),
        $firstAttempt,
        $operator,
        TicketOperatorAction::ProvideDirection,
        $direction,
    );

    expect($secondDecision->id)
        ->toBe($firstDecision->id)
        ->and(TicketEscalationDecision::query()->count())
        ->toBe(1)
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Open)
        ->and($ticket->messages()
            ->where('message_type', TicketMessageType::InternalNote->value)
            ->where('body', 'like', "%{$direction}%")
            ->exists())
        ->toBeTrue()
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.operator_decision')
            ->where('payload->ticket_triage_attempt_id', $firstAttempt->id)
            ->count())
        ->toBe(1);

    $harness->result = new NormalizedExecutionResult(
        exitCode: 0,
        output: json_encode(p3EscalationDecision(), JSON_THROW_ON_ERROR),
        errorOutput: '',
        externalRunId: 'p3-009-retriage',
    );

    $secondAttempt = app(ClaimTicketForTriage::class)->handle($project);

    expect($secondAttempt)
        ->not->toBeNull()
        ->and($secondAttempt?->number)
        ->toBe(2);

    app(RunTicketTriage::class)->handle($secondAttempt);

    expect($secondAttempt?->refresh()->agent_run_id)
        ->not->toBe($firstAttempt->agent_run_id)
        ->and($harness->prompts)
        ->toHaveCount(2)
        ->and($harness->prompts[1])
        ->toContain($direction);
});

test('approving an escalated implementation-required proposal converts it instead of restarting triage', function (): void {
    $operator = User::factory()->create();
    $project = p3EscalationProject();
    p3EscalationBindPm($project);
    $ticket = p3EscalationTicket($project);

    $attempt = p3RunEscalationTriage(
        $project,
        $ticket,
        p3EscalationDecision([
            'confidence' => 0.99,
            'escalation_flags' => [
                TicketEscalationReason::ArchitecturalDecisionRequired->value,
            ],
        ]),
    );

    expect($ticket->refresh()->status)->toBe(TicketStatus::Escalated);

    $decision = app(DecideTicketEscalation::class)->handle(
        $ticket->refresh(),
        $attempt,
        $operator,
        TicketOperatorAction::ApproveProposedHandling,
    );

    expect($decision->action)->toBe(TicketOperatorAction::ApproveProposedHandling)
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and(app(ClaimTicketForTriage::class)->handle($project))
        ->toBeNull();

    $task = app(ConvertTicketToTask::class)->handle($attempt->refresh());

    expect($task)->not->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Converted)
        ->and($ticket->converted_task_id)
        ->toBe($task->id)
        ->and($project->tasks()->count())
        ->toBe(1);
});

test('newly surfaced escalation risk after approval blocks conversion and re-escalates for fresh operator review', function (): void {
    $operator = User::factory()->create();
    $project = p3EscalationProject();
    p3EscalationBindPm($project);
    $ticket = p3EscalationTicket($project);
    [$phase, $dependency] = p3EscalationPendingTask($project, 1);

    $decision = p3EscalationDecision([
        'confidence' => 0.99,
        'escalation_flags' => [
            TicketEscalationReason::ArchitecturalDecisionRequired->value,
        ],
    ]);
    $decision['proposed_task']['preferred_phase_id'] = $phase->id;
    $decision['proposed_task']['depends_on_task_ids'] = [$dependency->id];

    $attempt = p3RunEscalationTriage($project, $ticket, $decision);

    expect($attempt->structured_decision['aios_validation']['escalation_reasons'])
        ->toBe([TicketEscalationReason::ArchitecturalDecisionRequired->value]);

    app(DecideTicketEscalation::class)->handle(
        $ticket->refresh(),
        $attempt,
        $operator,
        TicketOperatorAction::ApproveProposedHandling,
    );

    $dependency->update(['status' => TaskStatus::Cancelled]);

    $task = app(ConvertTicketToTask::class)->handle($attempt->refresh());

    expect($task)->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($project->tasks()->count())
        ->toBe(1)
        ->and($attempt->refresh()->structured_decision['aios_validation']['escalation_reasons'])
        ->toContain(TicketEscalationReason::UnsafeOrUnresolvedDependencyPlacement->value)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.escalated')
            ->where('payload->reason', 'ticket_task_conversion_revalidation')
            ->exists())
        ->toBeTrue();
});

test('proposed_tasks always requires operator escalation and is never automatically convertible', function (): void {
    $project = p3EscalationProject();
    $ticket = p3EscalationTicket($project);

    $decision = p3EscalationDecision([
        'confidence' => 0.99,
        'complexity' => 'low',
        'proposed_task' => null,
        'proposed_tasks' => [
            p3EscalationDecision()['proposed_task'],
            p3EscalationDecision()['proposed_task'],
        ],
        'escalation_flags' => [
            TicketEscalationReason::MultipleTasksOrPhasesRequired->value,
        ],
    ]);

    $result = app(TicketTriagePolicy::class)->evaluate($ticket, $decision);

    expect($result['requires_operator_decision'])->toBeTrue()
        ->and($result['automatic_task_conversion_eligible'])->toBeFalse()
        ->and($result['escalation_reasons'])
        ->toContain(TicketEscalationReason::MultipleTasksOrPhasesRequired->value);
});

test('multiple_tasks_or_phases_required is derived even when the PM omits the flag', function (): void {
    $project = p3EscalationProject();
    $ticket = p3EscalationTicket($project);

    $decision = p3EscalationDecision([
        'confidence' => 0.99,
        'proposed_task' => null,
        'proposed_tasks' => [
            p3EscalationDecision()['proposed_task'],
            p3EscalationDecision()['proposed_task'],
        ],
        'escalation_flags' => [],
    ]);

    $result = app(TicketTriagePolicy::class)->evaluate($ticket, $decision);

    expect($result['escalation_reasons'])
        ->toContain(TicketEscalationReason::MultipleTasksOrPhasesRequired->value);
});

test('operator approving a proposed_tasks set converts the whole bounded ordered set with in-set dependencies', function (): void {
    $operator = User::factory()->create();
    $project = p3EscalationProject();
    p3EscalationBindPm($project);
    $ticket = p3EscalationTicket($project);

    $firstTask = p3EscalationDecision()['proposed_task'];
    $firstTask['title'] = 'P10-006: Recovery hardening';
    $firstTask['depends_on_proposed_task_index'] = [];

    $secondTask = p3EscalationDecision()['proposed_task'];
    $secondTask['title'] = 'P10-007: Bounded concurrency controls';
    $secondTask['depends_on_proposed_task_index'] = [0];

    $decision = p3EscalationDecision([
        'confidence' => 0.99,
        'proposed_task' => null,
        'proposed_tasks' => [$firstTask, $secondTask],
        'escalation_flags' => [
            TicketEscalationReason::MultipleTasksOrPhasesRequired->value,
        ],
    ]);

    $attempt = p3RunEscalationTriage($project, $ticket, $decision);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Escalated)
        ->and($attempt->structured_decision['aios_validation']['escalation_reasons'])
        ->toBe([TicketEscalationReason::MultipleTasksOrPhasesRequired->value]);

    app(DecideTicketEscalation::class)->handle(
        $ticket->refresh(),
        $attempt,
        $operator,
        TicketOperatorAction::ApproveProposedHandling,
    );

    expect($ticket->refresh()->status)->toBe(TicketStatus::Escalated);

    $firstCreatedTask = app(ConvertTicketToTask::class)->handle($attempt->refresh());

    expect($firstCreatedTask)->not->toBeNull()
        ->and($project->tasks()->count())->toBe(2)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Converted)
        ->and($ticket->converted_task_id)->not->toBeNull();

    $created = $project->tasks()->orderBy('position')->get();

    expect($created[0]->title)->toBe('P10-006: Recovery hardening')
        ->and($created[1]->title)->toBe('P10-007: Bounded concurrency controls')
        ->and($created[1]->dependencies()->pluck('tasks.id'))
        ->toEqual(collect([$created[0]->id]));
});

test('operator decision endpoint requires authentication and project scoped bindings', function (): void {
    $project = p3EscalationProject();
    p3EscalationBindPm($project);
    $ticket = p3EscalationTicket($project);
    $attempt = p3RunEscalationTriage(
        $project,
        $ticket,
        p3EscalationDecision([
            'escalation_flags' => [
                TicketEscalationReason::ArchitecturalDecisionRequired->value,
            ],
        ]),
    );

    $this->post(route(
        'projects.tickets.escalation-decisions.store',
        [$project, $ticket, $attempt],
    ), [
        'action' => TicketOperatorAction::Reject->value,
    ])->assertRedirect(route('login'));

    $foreignProject = p3EscalationProject('Foreign project');
    $foreignTicket = p3EscalationTicket($foreignProject, 'TICKET-000099');
    $operator = User::factory()->create();

    $this->actingAs($operator)
        ->post(route(
            'projects.tickets.escalation-decisions.store',
            [$project, $foreignTicket, $attempt],
        ), [
            'action' => TicketOperatorAction::Reject->value,
        ])
        ->assertNotFound();
});
