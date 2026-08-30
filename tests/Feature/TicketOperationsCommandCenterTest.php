<?php

use App\Actions\ConvertTicketToTask;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketEscalationDecision;
use App\Models\TicketTriageAttempt;
use App\Models\User;
use App\Services\TicketConversation;
use App\TaskStatus;
use App\TicketDecision;
use App\TicketEscalationReason;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketOperatorAction;
use App\TicketStatus;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function p3CommandCenterProject(string $name = 'P3-012 Project'): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/ageax-p3-012-'.Str::uuid(),
        'git_status' => 'clean',
    ]);
}

/** @return array<string, mixed> */
function p3CommandCenterDecision(array $overrides = []): array
{
    return array_replace([
        'category' => 'enhancement',
        'decision' => 'approved',
        'confidence' => 0.94,
        'summary' => 'The PM identified a bounded request with an operator-owned risk decision.',
        'documentation_alignment' => [
            'Approved architecture requires explicit operator approval before roadmap interruption.',
        ],
        'affected_areas' => ['app/Actions', 'resources/js'],
        'complexity' => 'low',
        'requester_reply' => 'The request is understood and is awaiting an internal risk decision.',
        'internal_reason_summary' => 'Concise durable decision evidence.',
        'questions' => [],
        'blockers' => [],
        'duplicate_ticket_id' => null,
        'suggested_priority' => 'normal',
        'implementation_required' => true,
        'proposed_task' => [
            'title' => 'Implement the approved ticket change',
            'objective' => 'Implement one bounded change without bypassing AIOS ordering.',
            'acceptance_criteria' => ['The approved behavior is covered.'],
            'scope' => ['app/Actions'],
            'constraints' => ['Preserve deterministic ordering.'],
            'relevant_paths' => ['app/Actions'],
            'verification_commands' => [
                'php artisan test --compact tests/Feature/TicketOperationsCommandCenterTest.php',
            ],
            'implementation_prompt' => 'Implement the smallest correct change.',
            'depends_on_task_ids' => [],
            'preferred_phase_id' => null,
        ],
        'escalation_flags' => [],
        'aios_validation' => [
            'schema_version' => 1,
            'confidence_threshold' => 0.80,
            'requires_operator_decision' => false,
            'automatic_task_conversion_eligible' => true,
            'escalation_reasons' => [],
        ],
    ], $overrides);
}

function p3CommandCenterAttempt(
    Ticket $ticket,
    array $decision,
    ?AgentRun $run = null,
    int $number = 1,
): TicketTriageAttempt {
    return TicketTriageAttempt::create([
        'ticket_id' => $ticket->id,
        'agent_run_id' => $run?->id,
        'number' => $number,
        'status' => 'completed',
        'structured_decision' => $decision,
        'claimed_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
}

function p3CommandCenterQueuedTask(Project $project): Task
{
    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'Active roadmap phase',
        'objective' => 'Existing roadmap work.',
    ]);

    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Existing roadmap task',
        'objective' => 'Preserve the existing serial roadmap order.',
        'acceptance_criteria' => ['Existing work remains ordered.'],
        'implementation_prompt' => 'Preserve ordering.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

test('ticket operations routes require authentication', function (): void {
    $project = p3CommandCenterProject();
    $ticket = Ticket::factory()->for($project)->create();

    $this->get(route('ticket-operations.index'))
        ->assertRedirect(route('login'));
    $this->get(route('ticket-operations.show', $ticket))
        ->assertRedirect(route('login'));
});

test('command center counters and queues derive from durable ticket and latest triage evidence', function (): void {
    $user = User::factory()->create();
    $project = p3CommandCenterProject();

    $escalated = Ticket::factory()->for($project)->create([
        'status' => TicketStatus::Escalated,
    ]);
    p3CommandCenterAttempt($escalated, p3CommandCenterDecision([
        'escalation_flags' => [
            TicketEscalationReason::ArchitecturalDecisionRequired->value,
        ],
        'aios_validation' => [
            'schema_version' => 1,
            'confidence_threshold' => 0.80,
            'requires_operator_decision' => true,
            'automatic_task_conversion_eligible' => false,
            'escalation_reasons' => [
                TicketEscalationReason::ArchitecturalDecisionRequired->value,
            ],
        ],
    ]));

    Ticket::factory()->for($project)->create([
        'status' => TicketStatus::AwaitingRequester,
        'awaiting_response_until' => now()->addHours(72),
    ]);

    $convertedTask = p3CommandCenterQueuedTask($project);
    Ticket::factory()->for($project)->create([
        'status' => TicketStatus::Converted,
        'converted_task_id' => $convertedTask->id,
        'triaged_at' => now()->subDay(),
    ]);

    $failed = Ticket::factory()->for($project)->create([
        'status' => TicketStatus::Failed,
    ]);

    $blocked = Ticket::factory()->for($project)->create([
        'status' => TicketStatus::Triaging,
    ]);
    p3CommandCenterAttempt($blocked, p3CommandCenterDecision([
        'decision' => TicketDecision::Blocked->value,
        'implementation_required' => false,
        'proposed_task' => null,
        'aios_validation' => [
            'schema_version' => 1,
            'confidence_threshold' => 0.80,
            'requires_operator_decision' => false,
            'automatic_task_conversion_eligible' => false,
            'escalation_reasons' => [],
        ],
    ]));

    Ticket::factory()->for($project)->create([
        'status' => TicketStatus::Closed,
        'closed_at' => now()->subDay(),
        'inactivity_closed_at' => now()->subDay(),
    ]);

    Ticket::factory()->for($project)->create([
        'status' => TicketStatus::Open,
    ]);

    $this->actingAs($user)
        ->get(route('ticket-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ticket-operations/index')
            ->where('active_view', 'needs_operator_decision')
            ->where('views.0.count', 1)
            ->where('views.1.count', 1)
            ->where('views.2.count', 1)
            ->where('views.3.count', 2)
            ->where('views.4.count', 1)
            ->has('tickets', 1)
            ->where('tickets.0.id', $escalated->id));

    $this->actingAs($user)
        ->get(route('ticket-operations.index', [
            'view' => 'blocked_failed_triage',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('active_view', 'blocked_failed_triage')
            ->has('tickets', 2)
            ->where(
                'tickets',
                fn ($tickets): bool => collect($tickets)
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all() === collect([$failed->id, $blocked->id])
                    ->sort()
                    ->values()
                    ->all(),
            ));
});

test('command center escalation detail exposes exact durable pm evidence and agent run attribution', function (): void {
    $user = User::factory()->create();
    $project = p3CommandCenterProject();
    $ticket = Ticket::factory()->for($project)->create([
        'status' => TicketStatus::Escalated,
        'title' => 'Emergency production fix',
        'description' => 'Production is blocked and the request would preempt queued roadmap work.',
    ]);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'p3-012-command-center'),
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $decision = p3CommandCenterDecision([
        'suggested_priority' => 'emergency',
        'escalation_flags' => [
            TicketEscalationReason::CriticalOrEmergencyPreemptionRequested->value,
        ],
        'aios_validation' => [
            'schema_version' => 1,
            'confidence_threshold' => 0.80,
            'requires_operator_decision' => true,
            'automatic_task_conversion_eligible' => false,
            'escalation_reasons' => [
                TicketEscalationReason::CriticalOrEmergencyPreemptionRequested->value,
            ],
        ],
    ]);
    $attempt = p3CommandCenterAttempt($ticket, $decision, $run);

    $this->actingAs($user)
        ->get(route('ticket-operations.show', $ticket))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ticket-operations/show')
            ->where('ticket.id', $ticket->id)
            ->where('ticket.description', $ticket->description)
            ->where('triage.attempt_id', $attempt->id)
            ->where('triage.agent_run_id', $run->id)
            ->where(
                'triage.agent_run_url',
                route('projects.agent-runs.show', [$project, $run]),
            )
            ->where('triage.summary', $decision['summary'])
            ->where(
                'triage.documentation_alignment.0',
                $decision['documentation_alignment'][0],
            )
            ->where(
                'triage.escalation_flags.0',
                TicketEscalationReason::CriticalOrEmergencyPreemptionRequested->value,
            )
            ->where('triage.proposed_task.title', $decision['proposed_task']['title'])
            ->where('triage.critical_roadmap_interruption', true)
            ->where(
                'operator_action.approval_action',
                TicketOperatorAction::ApproveCriticalRoadmapInterruption->value,
            )
            ->where('operator_action.active', true));
});

test('critical roadmap interruption cannot use generic approval and requires the dedicated audited action', function (): void {
    $operator = User::factory()->create();
    $project = p3CommandCenterProject();
    $existingTask = p3CommandCenterQueuedTask($project);
    $ticket = Ticket::factory()->for($project)->create([
        'status' => TicketStatus::Escalated,
    ]);
    $decision = p3CommandCenterDecision([
        'suggested_priority' => 'critical',
        'aios_validation' => [
            'schema_version' => 1,
            'confidence_threshold' => 0.80,
            'requires_operator_decision' => true,
            'automatic_task_conversion_eligible' => false,
            'escalation_reasons' => [
                TicketEscalationReason::CriticalOrEmergencyPreemptionRequested->value,
            ],
        ],
    ]);
    $attempt = p3CommandCenterAttempt($ticket, $decision);

    $decisionUrl = route(
        'projects.tickets.escalation-decisions.store',
        [$project, $ticket, $attempt],
    );

    $this->actingAs($operator)
        ->post($decisionUrl, [
            'action' => TicketOperatorAction::ApproveProposedHandling->value,
        ])
        ->assertStatus(409);

    expect(TicketEscalationDecision::query()->count())
        ->toBe(0)
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($existingTask->refresh()->position)
        ->toBe(1);

    $this->actingAs($operator)
        ->post($decisionUrl, [
            'action' => TicketOperatorAction::ApproveCriticalRoadmapInterruption->value,
        ])
        ->assertRedirect(route('projects.tickets.show', [$project, $ticket]));

    $operatorDecision = TicketEscalationDecision::query()->sole();

    // The approval leaves the Ticket escalated (deferred) rather than reopening it for a
    // brand-new, stateless PM triage attempt that would discard the reviewed proposal and
    // very likely re-trip the very same deterministic escalation reason it just resolved.
    expect($operatorDecision->action)
        ->toBe(TicketOperatorAction::ApproveCriticalRoadmapInterruption)
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($ticket->converted_task_id)
        ->toBeNull()
        ->and($project->tasks()->count())
        ->toBe(1)
        ->and($existingTask->refresh()->position)
        ->toBe(1)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.operator_decision')
            ->where('payload->ticket_triage_attempt_id', $attempt->id)
            ->where('payload->critical_roadmap_interruption_approved', true)
            ->where('payload->conversion_deferred', true)
            ->exists())
        ->toBeTrue();

    // AIOS (the durable worker loop, via ConvertTicketToTask) re-validates the operator's
    // exact approved risk against current state before converting — the approval does not
    // bypass escalation, it resolves the specific reason the operator already reviewed.
    $task = app(ConvertTicketToTask::class)->handle($attempt->refresh());

    expect($task)->not->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Converted)
        ->and($ticket->converted_task_id)
        ->toBe($task->id)
        ->and($project->tasks()->count())
        ->toBe(2);
});

test('dedicated critical approval is rejected when fresh durable roadmap state does not require it', function (): void {
    $operator = User::factory()->create();
    $project = p3CommandCenterProject();
    $ticket = Ticket::factory()->for($project)->create([
        'status' => TicketStatus::Escalated,
    ]);
    $attempt = p3CommandCenterAttempt($ticket, p3CommandCenterDecision([
        'escalation_flags' => [
            TicketEscalationReason::ArchitecturalDecisionRequired->value,
        ],
        'aios_validation' => [
            'schema_version' => 1,
            'confidence_threshold' => 0.80,
            'requires_operator_decision' => true,
            'automatic_task_conversion_eligible' => false,
            'escalation_reasons' => [
                TicketEscalationReason::ArchitecturalDecisionRequired->value,
            ],
        ],
    ]));

    $this->actingAs($operator)
        ->post(route(
            'projects.tickets.escalation-decisions.store',
            [$project, $ticket, $attempt],
        ), [
            'action' => TicketOperatorAction::ApproveCriticalRoadmapInterruption->value,
        ])
        ->assertStatus(409);

    expect(TicketEscalationDecision::query()->count())
        ->toBe(0)
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated);
});

test('future client safe ticket conversation remains isolated from internal operator notes', function (): void {
    $user = User::factory()->create();
    $project = p3CommandCenterProject();
    $ticket = Ticket::factory()->for($project)->create();

    $ticket->messages()->create([
        'user_id' => $user->id,
        'author_type' => TicketMessageAuthorType::User,
        'message_type' => TicketMessageType::PublicReply,
        'body' => 'Requester-visible evidence.',
        'ai_generated' => false,
    ]);
    $ticket->messages()->create([
        'user_id' => $user->id,
        'author_type' => TicketMessageAuthorType::User,
        'message_type' => TicketMessageType::InternalNote,
        'body' => 'Internal escalation evidence must stay private.',
        'ai_generated' => false,
    ]);

    $clientSafe = app(TicketConversation::class)->clientSafePayload($ticket);

    expect(collect($clientSafe)->pluck('body'))
        ->toContain('Requester-visible evidence.')
        ->not->toContain('Internal escalation evidence must stay private.');
});
