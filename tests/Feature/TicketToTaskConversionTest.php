<?php

use App\Actions\ConvertTicketToTask;
use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketTriageAttempt;
use App\ProjectStatus;
use App\TaskStatus;
use App\TicketCategory;
use App\TicketDecision;
use App\TicketEscalationReason;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketPriority;
use App\TicketStatus;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('aios.obsidian_vault_path', null);
});

function p3ConversionProject(
    string $name = 'Ticket Conversion Project',
    ProjectStatus $status = ProjectStatus::Paused,
): Project {
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/ageax-p3-conversion-'.Str::uuid(),
        'status' => $status,
        'git_status' => 'clean',
    ]);
}

function p3ConversionPhase(
    Project $project,
    int $position,
    string $title,
    ?string $systemKey = null,
): Phase {
    return Phase::create([
        'project_id' => $project->id,
        'position' => $position,
        'title' => $title,
        'objective' => "Deliver {$title}.",
        'system_key' => $systemKey,
    ]);
}

function p3ConversionTask(
    Project $project,
    Phase $phase,
    int $position,
    TaskStatus $status = TaskStatus::Queued,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase->id,
        'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => "Existing Task {$position}",
        'objective' => 'Preserve the existing ordered roadmap work.',
        'acceptance_criteria' => ['Existing work remains authoritative.'],
        'scope' => [],
        'constraints' => [],
        'relevant_paths' => [],
        'verification_commands' => ['php artisan test --compact'],
        'implementation_prompt' => 'Preserve the existing task.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

function p3ConversionTicket(
    Project $project,
): Ticket {
    return Ticket::factory()
        ->for($project)
        ->create([
            'status' => TicketStatus::Triaging,
        ]);
}

/**
 * @param  array<string, mixed>  $decisionOverrides
 * @param  array<string, mixed>  $proposalOverrides
 */
function p3ConversionAttempt(
    Ticket $ticket,
    array $decisionOverrides = [],
    array $proposalOverrides = [],
): TicketTriageAttempt {
    $proposal = array_replace([
        'title' => 'Implement approved Ticket work',
        'objective' => 'Implement the single bounded Ticket request safely.',
        'acceptance_criteria' => [
            'The approved Ticket behavior is implemented.',
            'Focused regression coverage exists.',
        ],
        'scope' => ['app/Actions', 'tests/Feature'],
        'constraints' => [
            'Preserve deterministic AIOS workflow ownership.',
        ],
        'relevant_paths' => ['app/Actions'],
        'verification_commands' => [
            'php artisan test --compact tests/Feature/TicketToTaskConversionTest.php',
        ],
        'implementation_prompt' => 'Implement the smallest correct approved Ticket change.',
        'depends_on_task_ids' => [],
        'preferred_phase_id' => null,
    ], $proposalOverrides);

    $decision = array_replace([
        'category' => TicketCategory::Enhancement->value,
        'decision' => TicketDecision::Approved->value,
        'confidence' => 0.95,
        'summary' => 'The Ticket is clear, bounded, and safe to implement.',
        'documentation_alignment' => [],
        'affected_areas' => ['app/Actions'],
        'complexity' => 'low',
        'requester_reply' => 'The request is approved for implementation.',
        'internal_reason_summary' => 'One bounded low-risk Task is sufficient.',
        'questions' => [],
        'blockers' => [],
        'duplicate_ticket_id' => null,
        'suggested_priority' => TicketPriority::Normal->value,
        'implementation_required' => true,
        'proposed_task' => $proposal,
        'escalation_flags' => [],
        'aios_validation' => [
            'schema_version' => 1,
            'confidence_threshold' => 0.80,
            'requires_operator_decision' => false,
            'automatic_task_conversion_eligible' => true,
            'escalation_reasons' => [],
        ],
    ], $decisionOverrides);

    return TicketTriageAttempt::create([
        'ticket_id' => $ticket->id,
        'number' => ((int) $ticket->triageAttempts()->max('number')) + 1,
        'status' => 'completed',
        'structured_decision' => $decision,
        'claimed_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);
}

test('eligible Ticket converts atomically into one normal queued Task in the current phase', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    $dependency = p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: [
            'depends_on_task_ids' => [$dependency->id],
            'preferred_phase_id' => $phase->id,
        ],
    );

    $task = app(ConvertTicketToTask::class)->handle($attempt);

    expect($task)
        ->not->toBeNull()
        ->and($task?->project_id)
        ->toBe($project->id)
        ->and($task?->phase_id)
        ->toBe($phase->id)
        ->and($task?->key)
        ->toBe('TASK-002')
        ->and($task?->position)
        ->toBe(2)
        ->and($task?->status)
        ->toBe(TaskStatus::Queued)
        ->and($task?->dependencies()->pluck('tasks.id')->all())
        ->toBe([$dependency->id])
        ->and($task?->originTicket()->value('tickets.id'))
        ->toBe($ticket->id)
        ->and($task?->context_capsule['ticket_origin'])
        ->toMatchArray([
            'ticket_id' => $ticket->id,
            'ticket_key' => $ticket->key,
            'triage_attempt_id' => $attempt->id,
        ])
        ->and($project->phases()->count())
        ->toBe(1);

    $ticket->refresh();

    expect($ticket->status)
        ->toBe(TicketStatus::Converted)
        ->and($ticket->category)
        ->toBe(TicketCategory::Enhancement)
        ->and($ticket->decision)
        ->toBe(TicketDecision::Approved)
        ->and($ticket->ai_suggested_priority)
        ->toBe(TicketPriority::Normal)
        ->and((float) $ticket->triage_confidence)
        ->toBe(0.95)
        ->and($ticket->triaged_at)
        ->not->toBeNull()
        ->and($ticket->converted_task_id)
        ->toBe($task?->id)
        ->and($ticket->messages()
            ->where('author_type', TicketMessageAuthorType::System->value)
            ->where('message_type', TicketMessageType::SystemEvent->value)
            ->where('body', "Converted to {$task?->key}: {$task?->title}.")
            ->exists())
        ->toBeTrue()
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.converted_to_task')
            ->exists())
        ->toBeTrue();
});

test('conversion is idempotent across repeated retries', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );

    $first = app(ConvertTicketToTask::class)->handle($attempt);
    $second = app(ConvertTicketToTask::class)->handle($attempt);

    expect($first)
        ->not->toBeNull()
        ->and($second?->id)
        ->toBe($first?->id)
        ->and($project->tasks()->count())
        ->toBe(2)
        ->and($ticket->refresh()->converted_task_id)
        ->toBe($first?->id)
        ->and($ticket->messages()
            ->where('message_type', TicketMessageType::SystemEvent->value)
            ->where('body', "Converted to {$first?->key}: {$first?->title}.")
            ->count())
        ->toBe(1)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.converted_to_task')
            ->count())
        ->toBe(1);
});

test('review start sends new Ticket work to an append-only future intake phase', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask(
        $project,
        $phase,
        1,
        TaskStatus::Reviewing,
    );
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );

    $task = app(ConvertTicketToTask::class)->handle($attempt);
    $future = $project->phases()->orderByDesc('position')->firstOrFail();

    expect($task)
        ->not->toBeNull()
        ->and($task?->phase_id)
        ->toBe($future->id)
        ->and($future->position)
        ->toBe(2)
        ->and($future->title)
        ->toBe('Future Intake / Backlog')
        ->and($future->system_key)
        ->toBe('ticket-future-intake-2')
        ->and($phase->refresh()->position)
        ->toBe(1);
});

test('future intake phase is reused while it remains future', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask(
        $project,
        $phase,
        1,
        TaskStatus::Reviewing,
    );

    $firstTicket = p3ConversionTicket($project);
    $firstAttempt = p3ConversionAttempt(
        $firstTicket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );
    $firstTask = app(ConvertTicketToTask::class)->handle($firstAttempt);

    $secondTicket = p3ConversionTicket($project);
    $secondAttempt = p3ConversionAttempt($secondTicket);
    $secondTask = app(ConvertTicketToTask::class)->handle($secondAttempt);

    expect($project->phases()->count())
        ->toBe(2)
        ->and($secondTask?->phase_id)
        ->toBe($firstTask?->phase_id)
        ->and($project->phases()
            ->whereNotNull('system_key')
            ->count())
        ->toBe(1);
});

test('stale cross-project dependency evidence escalates instead of creating a Task', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask($project, $phase, 1);

    $foreignProject = p3ConversionProject('Foreign Project');
    $foreignPhase = p3ConversionPhase(
        $foreignProject,
        1,
        'Foreign Phase',
    );
    $foreignTask = p3ConversionTask(
        $foreignProject,
        $foreignPhase,
        1,
    );

    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: [
            'depends_on_task_ids' => [$foreignTask->id],
            'preferred_phase_id' => $phase->id,
        ],
    );

    $task = app(ConvertTicketToTask::class)->handle($attempt);
    $validation = $attempt->refresh()->structured_decision['aios_validation'];

    expect($task)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($project->tasks()->count())
        ->toBe(1)
        ->and($validation['requires_operator_decision'])
        ->toBeTrue()
        ->and($validation['automatic_task_conversion_eligible'])
        ->toBeFalse()
        ->and($validation['escalation_reasons'])
        ->toContain(
            TicketEscalationReason::UnsafeOrUnresolvedDependencyPlacement->value,
        );
});

test('high-complexity Ticket cannot silently convert into implementation work', function () {
    $project = p3ConversionProject();
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        decisionOverrides: ['complexity' => 'high'],
    );

    $task = app(ConvertTicketToTask::class)->handle($attempt);

    expect($task)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($project->tasks()->count())
        ->toBe(0)
        ->and($attempt->refresh()->structured_decision['aios_validation']['escalation_reasons'])
        ->toContain(TicketEscalationReason::HighComplexity->value);
});

test('unsafe verification commands cannot enter the normal Task lifecycle', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: [
            'preferred_phase_id' => $phase->id,
            'verification_commands' => ['php artisan migrate:fresh'],
        ],
    );

    $task = app(ConvertTicketToTask::class)->handle($attempt);

    expect($task)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Failed)
        ->and($project->tasks()->count())
        ->toBe(1)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.task_conversion_failed')
            ->exists())
        ->toBeTrue();
});

test('worker cycle recovers a completed conversion-eligible triage after a crash boundary', function () {
    config()->set('aios.worker_task_cooldown_seconds', 3600);

    $project = p3ConversionProject(
        'Crash Recovery Project',
        ProjectStatus::Running,
    );
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    p3ConversionAttempt(
        $ticket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );

    foreach ([AgentRole::Coder, AgentRole::Reviewer] as $role) {
        AgentWorker::create([
            'project_id' => $project->id,
            'role' => $role,
            'status' => 'idle',
            'task_completed_at' => now(),
        ]);
    }

    $this->artisan('aios:work --once')
        ->assertSuccessful();

    expect($ticket->refresh()->status)
        ->toBe(TicketStatus::Converted)
        ->and($ticket->triaged_at)
        ->not->toBeNull()
        ->and($ticket->converted_task_id)
        ->not->toBeNull()
        ->and($project->tasks()->count())
        ->toBe(2);
});

test('Ticket detail resolves the exact generated Task after conversion', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );
    $task = app(ConvertTicketToTask::class)->handle($attempt);
    $user = $ticket->submittedBy()->firstOrFail();

    $this->actingAs($user)
        ->get(route('projects.tickets.show', [$project, $ticket]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tickets/show')
            ->where('ticket.converted_task.id', $task?->id)
            ->where('ticket.converted_task.key', $task?->key));
});

test('Task detail payload identifies the originating Ticket', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );
    $task = app(ConvertTicketToTask::class)->handle($attempt);
    $user = $ticket->submittedBy()->firstOrFail();

    $this->actingAs($user)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where(
                'task.context_capsule.ticket_origin.ticket_id',
                $ticket->id,
            )
            ->where(
                'task.context_capsule.ticket_origin.ticket_key',
                $ticket->key,
            ));
});

test('competing completed conversion attempts converge on the same durable Task', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    $firstAttempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );
    $secondAttempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );

    $firstTask = app(ConvertTicketToTask::class)->handle($firstAttempt);
    $secondTask = app(ConvertTicketToTask::class)->handle($secondAttempt);

    expect($firstTask)
        ->not->toBeNull()
        ->and($secondTask?->id)
        ->toBe($firstTask?->id)
        ->and($project->tasks()->count())
        ->toBe(2)
        ->and($ticket->refresh()->converted_task_id)
        ->toBe($firstTask?->id);
});

test('conversion is deferred while a fresh sibling triage attempt for the same Ticket is actively in flight', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );

    TicketTriageAttempt::create([
        'ticket_id' => $ticket->id,
        'number' => $attempt->number + 1,
        'status' => 'running',
        'structured_decision' => null,
        'claimed_at' => now(),
        'finished_at' => null,
    ]);

    $task = app(ConvertTicketToTask::class)->handle($attempt);

    expect($task)->toBeNull()
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Triaging)
        ->and($ticket->converted_task_id)->toBeNull()
        ->and($project->tasks()->count())->toBe(1);
});

test('an abandoned stale sibling triage attempt past the staleness window no longer blocks conversion', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: ['preferred_phase_id' => $phase->id],
    );

    TicketTriageAttempt::create([
        'ticket_id' => $ticket->id,
        'number' => $attempt->number + 1,
        'status' => 'running',
        'structured_decision' => null,
        'claimed_at' => now()->subSeconds(
            (int) config('aios.stale_worker_after_seconds') + 60,
        ),
        'finished_at' => null,
    ]);

    $task = app(ConvertTicketToTask::class)->handle($attempt);

    expect($task)->not->toBeNull()
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Converted)
        ->and($project->tasks()->count())->toBe(2);
});

test('commit-time dependency state is revalidated before Task creation', function () {
    $project = p3ConversionProject();
    $phase = p3ConversionPhase($project, 1, 'Current Phase');
    $dependency = p3ConversionTask($project, $phase, 1);
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: [
            'depends_on_task_ids' => [$dependency->id],
            'preferred_phase_id' => $phase->id,
        ],
    );

    $dependency->update(['status' => TaskStatus::Cancelled]);

    $task = app(ConvertTicketToTask::class)->handle($attempt);

    expect($task)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($project->tasks()->count())
        ->toBe(1)
        ->and($attempt->refresh()->structured_decision['aios_validation']['escalation_reasons'])
        ->toContain(
            TicketEscalationReason::UnsafeOrUnresolvedDependencyPlacement->value,
        );
});

test('roadmap reordering request escalates instead of mutating phase order', function () {
    $project = p3ConversionProject();
    $completedPhase = p3ConversionPhase($project, 1, 'Completed Phase');
    p3ConversionTask(
        $project,
        $completedPhase,
        1,
        TaskStatus::Done,
    );
    $currentPhase = p3ConversionPhase($project, 2, 'Current Phase');
    p3ConversionTask($project, $currentPhase, 2);
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        proposalOverrides: [
            'preferred_phase_id' => $completedPhase->id,
        ],
    );

    $task = app(ConvertTicketToTask::class)->handle($attempt);

    expect($task)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($project->phases()->orderBy('position')->pluck('position')->all())
        ->toBe([1, 2])
        ->and($project->tasks()->count())
        ->toBe(2)
        ->and($attempt->refresh()->structured_decision['aios_validation']['escalation_reasons'])
        ->toContain(
            TicketEscalationReason::RoadmapOrPhaseReorderingOrInterruptionRequested->value,
        );
});

test('multi-task scope escalation cannot create hidden extra Tasks', function () {
    $project = p3ConversionProject();
    $ticket = p3ConversionTicket($project);
    $attempt = p3ConversionAttempt(
        $ticket,
        decisionOverrides: [
            'escalation_flags' => [
                TicketEscalationReason::MultipleTasksOrPhasesRequired->value,
            ],
        ],
    );

    $task = app(ConvertTicketToTask::class)->handle($attempt);

    expect($task)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($project->tasks()->count())
        ->toBe(0)
        ->and($attempt->refresh()->structured_decision['aios_validation']['escalation_reasons'])
        ->toContain(
            TicketEscalationReason::MultipleTasksOrPhasesRequired->value,
        );
});
