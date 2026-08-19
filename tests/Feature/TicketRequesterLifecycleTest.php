<?php

use App\Actions\AutoCloseInactiveTickets;
use App\Actions\ClaimTicketForTriage;
use App\Actions\RecordTicketMessage;
use App\Actions\RunTicketTriage;
use App\Actions\TransitionTicket;
use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentHarness as AgentHarnessContract;
use App\Services\AgentHarnessResolver;
use App\Services\HarnessCapabilities;
use App\Services\NormalizedExecutionResult;
use App\Services\TicketConversation;
use App\TicketDecision;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketStatus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class P3RequesterLifecycleHarness implements AgentHarnessContract
{
    /** @var list<string> */
    public array $prompts = [];

    public int $executions = 0;

    public function __construct(
        public NormalizedExecutionResult $result,
    ) {}

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
    ): NormalizedExecutionResult {
        $this->executions++;
        $this->prompts[] = $prompt;

        $onOutput?->__invoke(
            'stdout',
            $this->result->output,
        );
        $onHeartbeat?->__invoke();

        return $this->result;
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    Date::setTestNow('2026-08-19 10:00:00');
    config()->set(
        'aios.obsidian_vault_path',
        storage_path(
            'framework/testing/obsidian-p3-011-'.fake()->uuid(),
        ),
    );
});

afterEach(function (): void {
    Date::setTestNow();
});

/** @return array{project: Project, requester: User, ticket: Ticket, agent: Agent} */
function p3RequesterLifecycleFixture(
    string $ticketKey = 'TICKET-000001',
): array {
    $path = sys_get_temp_dir()
        .'/ageax-p3-requester-lifecycle-'.fake()->uuid();

    File::ensureDirectoryExists($path);
    File::put(
        $path.'/MASTER-PROMPT.md',
        "# Test Governance\nTicket requester lifecycle remains AIOS-owned.\n",
    );
    File::put(
        $path.'/AGENTS.md',
        "# Test Agents\nThe Project Manager returns structured output only.\n",
    );

    $project = Project::create([
        'name' => 'Ticket Requester Lifecycle',
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
    $agent = Agent::factory()
        ->for($project)
        ->create([
            'name' => 'Requester Lifecycle PM',
            'role' => AgentRole::ProjectManager,
            'harness' => AgentHarnessIdentifier::Codex,
            'model' => null,
            'reasoning_setting' => null,
            'default_context' => 'Use the P3 requester lifecycle contract.',
            'enabled' => true,
        ]);

    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'agent_id' => $agent->id,
        'status' => 'idle',
    ]);

    $requester = User::factory()->create();
    $ticket = Ticket::factory()
        ->for($project)
        ->create([
            'submitted_by_user_id' => $requester->id,
            'key' => $ticketKey,
            'title' => 'Requester lifecycle ticket',
            'description' => 'The PM needs to continue this ticket conversation safely.',
            'status' => TicketStatus::Open,
        ]);

    return compact('project', 'requester', 'ticket', 'agent');
}

/** @return array<string, mixed> */
function p3NeedsInformationDecision(): array
{
    return [
        'category' => 'bug',
        'decision' => 'needs_information',
        'confidence' => 0.95,
        'summary' => 'One requester detail is required before implementation can be decided.',
        'documentation_alignment' => [
            'No approved documentation conflict was found.',
        ],
        'affected_areas' => [
            'app/Actions',
        ],
        'complexity' => 'low',
        'requester_reply' => 'I need one more detail before I can continue.',
        'internal_reason_summary' => 'The missing version evidence is bounded and requester-resolvable.',
        'questions' => [
            'Which application version reproduces the issue?',
        ],
        'blockers' => [],
        'duplicate_ticket_id' => null,
        'suggested_priority' => 'normal',
        'implementation_required' => false,
        'proposed_task' => null,
        'escalation_flags' => [],
    ];
}

/** @return array<string, mixed> */
function p3SelfServiceDecision(): array
{
    return [
        'category' => 'enhancement',
        'decision' => 'self_service',
        'confidence' => 0.96,
        'summary' => 'The requester can resolve this without implementation work.',
        'documentation_alignment' => [
            'The existing supported workflow already covers the request.',
        ],
        'affected_areas' => [
            'documentation',
        ],
        'complexity' => 'low',
        'requester_reply' => "1. Open the project settings.\n2. Update the existing supported option.\n3. Save and verify the result.",
        'internal_reason_summary' => 'Existing supported configuration is sufficient.',
        'questions' => [],
        'blockers' => [],
        'duplicate_ticket_id' => null,
        'suggested_priority' => 'normal',
        'implementation_required' => false,
        'proposed_task' => null,
        'escalation_flags' => [],
    ];
}

function p3UseRequesterLifecycleHarness(
    array $decision,
): P3RequesterLifecycleHarness {
    $harness = new P3RequesterLifecycleHarness(
        new NormalizedExecutionResult(
            exitCode: 0,
            output: json_encode(
                $decision,
                JSON_THROW_ON_ERROR,
            ),
            errorOutput: '',
            externalRunId: 'p3-requester-lifecycle-run',
        ),
    );

    app()->instance(
        AgentHarnessResolver::class,
        new AgentHarnessResolver([$harness]),
    );

    return $harness;
}

test('needs information persists an attributed AI public reply and exact 72 hour requester deadline', function () {
    ['project' => $project, 'ticket' => $ticket] =
        p3RequesterLifecycleFixture();
    p3UseRequesterLifecycleHarness(
        p3NeedsInformationDecision(),
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($attempt)->not->toBeNull();

    app(RunTicketTriage::class)->handle($attempt);

    $attempt = $attempt?->refresh();
    $ticket = $ticket->refresh();
    $message = $ticket->messages()
        ->where('author_type', TicketMessageAuthorType::Ai->value)
        ->where('message_type', TicketMessageType::PublicReply->value)
        ->sole();
    $conversation = app(TicketConversation::class)
        ->clientSafePayload($ticket);
    $publicMessage = collect($conversation)
        ->firstWhere('id', $message->id);

    expect($attempt?->status)
        ->toBe('completed')
        ->and($ticket->status)
        ->toBe(TicketStatus::AwaitingRequester)
        ->and($ticket->decision)
        ->toBe(TicketDecision::NeedsInformation)
        ->and((float) $ticket->triage_confidence)
        ->toBe(0.95)
        ->and($ticket->awaiting_response_until?->equalTo(
            now()->addHours(72),
        ))
        ->toBeTrue()
        ->and($message->ai_generated)
        ->toBeTrue()
        ->and($message->agent_run_id)
        ->toBe($attempt?->agent_run_id)
        ->and($message->body)
        ->toContain('Which application version reproduces the issue?')
        ->and($publicMessage['ai_badge'] ?? null)
        ->toBe('AI-generated response')
        ->and($publicMessage['agent_run_id'] ?? null)
        ->toBe($attempt?->agent_run_id)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.reply_ai_generated')
            ->count())
        ->toBe(1)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.awaiting_requester')
            ->count())
        ->toBe(1);
});

test('self service persists bounded AI guidance and waits for the requester', function () {
    ['project' => $project, 'ticket' => $ticket] =
        p3RequesterLifecycleFixture();
    p3UseRequesterLifecycleHarness(
        p3SelfServiceDecision(),
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);
    app(RunTicketTriage::class)->handle($attempt);

    $ticket = $ticket->refresh();
    $message = $ticket->messages()
        ->where('author_type', TicketMessageAuthorType::Ai->value)
        ->where('message_type', TicketMessageType::PublicReply->value)
        ->sole();

    expect($ticket->status)
        ->toBe(TicketStatus::AwaitingRequester)
        ->and($ticket->decision)
        ->toBe(TicketDecision::SelfService)
        ->and($ticket->awaiting_response_until?->equalTo(
            now()->addHours(72),
        ))
        ->toBeTrue()
        ->and($message->body)
        ->toContain('1. Open the project settings.')
        ->and($message->body)
        ->toContain('3. Save and verify the result.')
        ->and($message->ai_generated)
        ->toBeTrue();
});

test('requester response before the deadline opens the ticket and next triage uses a fresh context containing new evidence', function () {
    [
        'project' => $project,
        'requester' => $requester,
        'ticket' => $ticket,
    ] = p3RequesterLifecycleFixture();
    $harness = p3UseRequesterLifecycleHarness(
        p3NeedsInformationDecision(),
    );

    $firstAttempt = app(ClaimTicketForTriage::class)
        ->handle($project);
    app(RunTicketTriage::class)->handle($firstAttempt);

    $firstRun = AgentRun::query()
        ->findOrFail($firstAttempt?->refresh()->agent_run_id);
    $requesterEvidence = 'The issue reproduces on version 2.4.1.';

    app(RecordTicketMessage::class)->handle(
        $ticket->refresh(),
        TicketMessageAuthorType::User,
        TicketMessageType::PublicReply,
        $requesterEvidence,
        $requester,
    );

    expect($ticket->refresh()->status)
        ->toBe(TicketStatus::Open)
        ->and($ticket->awaiting_response_until)
        ->toBeNull();

    $harness->result = new NormalizedExecutionResult(
        exitCode: 0,
        output: json_encode(
            p3SelfServiceDecision(),
            JSON_THROW_ON_ERROR,
        ),
        errorOutput: '',
        externalRunId: 'p3-requester-lifecycle-run-2',
    );

    $secondAttempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($secondAttempt)->not->toBeNull();

    app(RunTicketTriage::class)->handle($secondAttempt);

    $secondAttempt = $secondAttempt?->refresh();
    $secondRun = AgentRun::query()
        ->findOrFail($secondAttempt?->agent_run_id);

    expect($secondAttempt?->number)
        ->toBe(2)
        ->and($secondRun->id)
        ->not->toBe($firstRun->id)
        ->and($harness->executions)
        ->toBe(2)
        ->and($harness->prompts[1])
        ->toContain($requesterEvidence)
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::AwaitingRequester);
});

dataset('requester dependent inactivity decisions', [
    'needs information' => [TicketDecision::NeedsInformation],
    'self service' => [TicketDecision::SelfService],
]);

test('expired requester dependent tickets close once with durable inactivity evidence', function (TicketDecision $decision) {
    ['project' => $project, 'ticket' => $ticket] =
        p3RequesterLifecycleFixture();

    $ticket->forceFill([
        'status' => TicketStatus::AwaitingRequester,
        'decision' => $decision,
        'awaiting_response_until' => now()->subMinute(),
    ])->save();

    $firstRun = app(AutoCloseInactiveTickets::class)->handle();
    $secondRun = app(AutoCloseInactiveTickets::class)->handle();
    $ticket = $ticket->refresh();

    expect($firstRun)
        ->toBe(1)
        ->and($secondRun)
        ->toBe(0)
        ->and($ticket->status)
        ->toBe(TicketStatus::Closed)
        ->and($ticket->closed_at)
        ->not->toBeNull()
        ->and($ticket->inactivity_closed_at)
        ->not->toBeNull()
        ->and($ticket->inactivity_closed_at?->equalTo($ticket->closed_at))
        ->toBeTrue()
        ->and($ticket->messages()
            ->where('author_type', TicketMessageAuthorType::System->value)
            ->where('message_type', TicketMessageType::SystemEvent->value)
            ->where('body', 'Closed automatically after 72 hours without a requester response.')
            ->count())
        ->toBe(1)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.auto_closed')
            ->count())
        ->toBe(1);
})->with('requester dependent inactivity decisions');

test('auto close ignores states and decisions that are not requester dependent waiting', function () {
    ['project' => $project, 'requester' => $requester, 'ticket' => $ticket] =
        p3RequesterLifecycleFixture();

    $ticket->forceFill([
        'status' => TicketStatus::AwaitingRequester,
        'decision' => TicketDecision::Approved,
        'awaiting_response_until' => now()->subMinute(),
    ])->save();

    $openTicket = Ticket::factory()
        ->for($project)
        ->create([
            'submitted_by_user_id' => $requester->id,
            'key' => 'TICKET-000002',
            'status' => TicketStatus::Open,
            'decision' => TicketDecision::NeedsInformation,
            'awaiting_response_until' => now()->subMinute(),
        ]);
    $escalatedTicket = Ticket::factory()
        ->for($project)
        ->create([
            'submitted_by_user_id' => $requester->id,
            'key' => 'TICKET-000003',
            'status' => TicketStatus::Escalated,
            'decision' => TicketDecision::SelfService,
            'awaiting_response_until' => now()->subMinute(),
        ]);

    expect(app(AutoCloseInactiveTickets::class)->handle())
        ->toBe(0)
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::AwaitingRequester)
        ->and($openTicket->refresh()->status)
        ->toBe(TicketStatus::Open)
        ->and($escalatedTicket->refresh()->status)
        ->toBe(TicketStatus::Escalated)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.auto_closed')
            ->exists())
        ->toBeFalse();
});

test('late requester reply reopens only a ticket with durable inactivity closure evidence', function () {
    [
        'project' => $project,
        'requester' => $requester,
        'ticket' => $ticket,
    ] = p3RequesterLifecycleFixture();

    $ticket->forceFill([
        'status' => TicketStatus::AwaitingRequester,
        'decision' => TicketDecision::NeedsInformation,
        'awaiting_response_until' => now()->subMinute(),
    ])->save();

    app(AutoCloseInactiveTickets::class)->handle();

    expect($ticket->refresh()->status)
        ->toBe(TicketStatus::Closed)
        ->and($ticket->inactivity_closed_at)
        ->not->toBeNull();

    app(RecordTicketMessage::class)->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::PublicReply,
        'Here is the missing evidence after the inactivity close.',
        $requester,
    );

    $ticket = $ticket->refresh();

    expect($ticket->status)
        ->toBe(TicketStatus::Open)
        ->and($ticket->closed_at)
        ->toBeNull()
        ->and($ticket->awaiting_response_until)
        ->toBeNull()
        ->and($ticket->inactivity_closed_at)
        ->toBeNull()
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.reopened')
            ->where('payload->reason', 'requester_response_after_inactivity_close')
            ->count())
        ->toBe(1)
        ->and($ticket->messages()
            ->where('author_type', TicketMessageAuthorType::System->value)
            ->where('body', 'Ticket reopened automatically after a requester response to an inactivity-closed Ticket.')
            ->count())
        ->toBe(1)
        ->and(app(ClaimTicketForTriage::class)->handle($project))
        ->not->toBeNull();
});

test('requester reply does not reopen rejected duplicate or operator closed tickets', function () {
    ['project' => $project, 'requester' => $requester, 'ticket' => $ticket] =
        p3RequesterLifecycleFixture();

    $ticket->forceFill([
        'status' => TicketStatus::Closed,
        'decision' => TicketDecision::Rejected,
        'closed_at' => now()->subHour(),
        'inactivity_closed_at' => null,
    ])->save();

    $duplicate = Ticket::factory()
        ->for($project)
        ->create([
            'submitted_by_user_id' => $requester->id,
            'key' => 'TICKET-000002',
            'status' => TicketStatus::Closed,
            'decision' => TicketDecision::Duplicate,
            'closed_at' => now()->subHour(),
        ]);
    $operatorClosed = Ticket::factory()
        ->for($project)
        ->create([
            'submitted_by_user_id' => $requester->id,
            'key' => 'TICKET-000003',
            'status' => TicketStatus::AwaitingRequester,
            'decision' => TicketDecision::NeedsInformation,
            'awaiting_response_until' => now()->addHour(),
        ]);
    $operatorClosed = app(TransitionTicket::class)->handle(
        $operatorClosed,
        TicketStatus::Closed,
    );

    foreach ([$ticket, $duplicate, $operatorClosed] as $closedTicket) {
        app(RecordTicketMessage::class)->handle(
            $closedTicket,
            TicketMessageAuthorType::User,
            TicketMessageType::PublicReply,
            'Requester follow-up must not bypass the closure policy.',
            $requester,
        );
    }

    expect($ticket->refresh()->status)
        ->toBe(TicketStatus::Closed)
        ->and($duplicate->refresh()->status)
        ->toBe(TicketStatus::Closed)
        ->and($operatorClosed->refresh()->status)
        ->toBe(TicketStatus::Closed)
        ->and($operatorClosed->inactivity_closed_at)
        ->toBeNull()
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.reopened')
            ->exists())
        ->toBeFalse();
});

test('requester response after the deadline but before the scheduler scan is closed and reopened atomically', function () {
    [
        'project' => $project,
        'requester' => $requester,
        'ticket' => $ticket,
    ] = p3RequesterLifecycleFixture();

    $ticket->forceFill([
        'status' => TicketStatus::AwaitingRequester,
        'decision' => TicketDecision::NeedsInformation,
        'awaiting_response_until' => now()->subMinute(),
    ])->save();

    app(RecordTicketMessage::class)->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::PublicReply,
        'This response arrived after the durable deadline but before the scheduler scan.',
        $requester,
    );

    expect($ticket->refresh()->status)
        ->toBe(TicketStatus::AwaitingRequester);

    expect(app(AutoCloseInactiveTickets::class)->handle())
        ->toBe(1);

    $ticket = $ticket->refresh();

    expect($ticket->status)
        ->toBe(TicketStatus::Open)
        ->and($ticket->closed_at)
        ->toBeNull()
        ->and($ticket->awaiting_response_until)
        ->toBeNull()
        ->and($ticket->inactivity_closed_at)
        ->toBeNull()
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.auto_closed')
            ->count())
        ->toBe(1)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.reopened')
            ->where('payload->reason', 'late_requester_response_after_inactivity_deadline')
            ->count())
        ->toBe(1);
});
