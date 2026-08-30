<?php

use App\Actions\ClaimTicketForTriage;
use App\Actions\RecordTicketMessage;
use App\Actions\RunProjectManager;
use App\Actions\RunTicketTriage;
use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentHarness as AgentHarnessContract;
use App\Services\AgentHarnessResolver;
use App\Services\CodexCliRunner;
use App\Services\HarnessCapabilities;
use App\Services\NormalizedExecutionResult;
use App\Services\WorkerHeartbeat;
use App\TaskStatus;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\mock;

final class P3TicketTriageTestHarness implements AgentHarnessContract
{
    /** @var list<string> */
    public array $prompts = [];

    /** @var list<int> */
    public array $agentIds = [];

    public int $executions = 0;

    public function __construct(
        private AgentHarnessIdentifier $harness,
        public NormalizedExecutionResult $result,
    ) {}

    public function identifier(): AgentHarnessIdentifier
    {
        return $this->harness;
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
        $this->executions++;
        $this->prompts[] = $prompt;
        $this->agentIds[] = $agent->id;

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
    config()->set(
        'aios.obsidian_vault_path',
        storage_path(
            'framework/testing/obsidian-p3-008-'.fake()->uuid(),
        ),
    );
});

function p3TicketExecutionProject(
    string $name = 'Ticket Triage Execution',
): Project {
    $path = sys_get_temp_dir()
        .'/ageax-p3-ticket-execution-'.fake()->uuid();

    File::ensureDirectoryExists($path);
    File::put(
        $path.'/MASTER-PROMPT.md',
        "# Test Governance\nTicket triage remains proposal-only.\n",
    );
    File::put(
        $path.'/AGENTS.md',
        "# Test Agents\nThe Project Manager returns structured output only.\n",
    );

    return Project::create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/** @return array{0: Agent, 1: AgentWorker} */
function p3BindTicketExecutionPm(
    Project $project,
    AgentHarnessIdentifier $harness,
    bool $enabled = true,
): array {
    $agent = Agent::factory()
        ->for($project)
        ->create([
            'name' => 'Ticket Triage PM',
            'role' => AgentRole::ProjectManager,
            'harness' => $harness,
            'model' => null,
            'reasoning_setting' => null,
            'default_context' => 'Use the dedicated P3 ticket triage contract.',
            'enabled' => $enabled,
        ]);

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'agent_id' => $agent->id,
        'status' => 'idle',
    ]);

    return [$agent, $worker];
}

function p3TicketExecutionTicket(
    Project $project,
    string $key = 'TICKET-000001',
): Ticket {
    return Ticket::factory()
        ->for($project)
        ->create([
            'key' => $key,
            'title' => 'Add a bounded ticket workflow improvement',
            'description' => 'The requester wants one small implementation change.',
            'status' => TicketStatus::Open,
        ]);
}

/** @return array<string, mixed> */
function p3ValidTicketTriageDecision(
    array $overrides = [],
): array {
    return array_replace([
        'category' => 'enhancement',
        'decision' => 'approved',
        'confidence' => 0.94,
        'summary' => 'The request is clear, bounded, and aligned with approved documentation.',
        'documentation_alignment' => [
            'The request does not conflict with the supplied governance.',
        ],
        'affected_areas' => [
            'app/Actions',
            'tests/Feature',
        ],
        'complexity' => 'low',
        'requester_reply' => 'The request is clear and has a bounded implementation proposal.',
        'internal_reason_summary' => 'Clear single-task enhancement with no identified escalation condition.',
        'questions' => [],
        'blockers' => [],
        'duplicate_ticket_id' => null,
        'suggested_priority' => 'normal',
        'implementation_required' => true,
        'proposed_task' => [
            'title' => 'Implement the bounded ticket improvement',
            'objective' => 'Implement the requested behavior without expanding scope.',
            'acceptance_criteria' => [
                'The requested behavior is implemented.',
                'Focused regression coverage exists.',
            ],
            'scope' => [
                'app/Actions',
                'tests/Feature',
            ],
            'constraints' => [
                'Preserve deterministic AIOS state ownership.',
            ],
            'relevant_paths' => [
                'app/Actions',
            ],
            'verification_commands' => [
                'php artisan test --compact tests/Feature/TicketTriageExecutionTest.php',
            ],
            'implementation_prompt' => 'Implement the smallest correct ticket workflow change.',
            'depends_on_task_ids' => [],
            'preferred_phase_id' => null,
        ],
        'escalation_flags' => [],
    ], $overrides);
}

/** @return array{0: P3TicketTriageTestHarness, 1: AgentHarnessResolver} */
function p3UseTicketTriageHarness(
    AgentHarnessIdentifier $identifier,
    array $decision,
): array {
    $harness = new P3TicketTriageTestHarness(
        $identifier,
        new NormalizedExecutionResult(
            exitCode: 0,
            output: json_encode(
                $decision,
                JSON_THROW_ON_ERROR,
            ),
            errorOutput: '',
            externalRunId: 'p3-ticket-triage-run',
        ),
    );
    $resolver = new AgentHarnessResolver([$harness]);

    app()->instance(
        AgentHarnessResolver::class,
        $resolver,
    );

    return [$harness, $resolver];
}

dataset('p3 ticket triage harness identifiers', [
    'Codex' => [AgentHarnessIdentifier::Codex],
    'Claude Code' => [AgentHarnessIdentifier::ClaudeCode],
]);

test('ticket triage uses the bound pm harness and immutable snapshot without mutating durable ticket work', function (
    AgentHarnessIdentifier $harnessIdentifier,
) {
    $project = p3TicketExecutionProject();
    [$agent] = p3BindTicketExecutionPm(
        $project,
        $harnessIdentifier,
    );
    $ticket = p3TicketExecutionTicket($project);
    [$harness] = p3UseTicketTriageHarness(
        $harnessIdentifier,
        p3ValidTicketTriageDecision(),
    );

    $heartbeat = app(WorkerHeartbeat::class);
    $lease = $heartbeat->acquire(
        $project,
        AgentRole::ProjectManager,
        fake()->uuid(),
    );

    expect($lease)->not->toBeNull();

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($attempt)->not->toBeNull();

    $messageCount = $ticket->messages()->count();
    $taskCount = $project->tasks()->count();

    try {
        app(RunTicketTriage::class)->handle(
            $attempt,
            $lease,
        );
    } finally {
        if ($lease !== null) {
            $heartbeat->release($lease);
        }
    }

    $attempt = $attempt?->refresh();
    $ticket = $ticket->refresh();
    $run = AgentRun::query()
        ->findOrFail($attempt?->agent_run_id);

    expect($attempt?->status)
        ->toBe('completed')
        ->and($attempt?->structured_decision)
        ->toMatchArray([
            'category' => 'enhancement',
            'decision' => 'approved',
            'implementation_required' => true,
        ])
        ->and($ticket->status)
        ->toBe(TicketStatus::Triaging)
        ->and($ticket->category)
        ->toBeNull()
        ->and($ticket->decision)
        ->toBeNull()
        ->and($ticket->triage_confidence)
        ->toBeNull()
        ->and($ticket->converted_task_id)
        ->toBeNull()
        ->and($project->tasks()->count())
        ->toBe($taskCount)
        ->and($ticket->messages()->count())
        ->toBe($messageCount)
        ->and($run->agent_id)
        ->toBe($agent->id)
        ->and($run->harness)
        ->toBe($harnessIdentifier->value)
        ->and($run->worker_lease_id)
        ->toBe($lease?->leaseId)
        ->and($run->configuration_snapshot['agent']['configuration_version'])
        ->toBe($agent->configuration_version)
        ->and($run->configuration_snapshot['agent']['default_context'])
        ->toBe('Use the dedicated P3 ticket triage contract.')
        ->and($harness->executions)
        ->toBe(1)
        ->and($harness->agentIds)
        ->toBe([$agent->id])
        ->and($harness->prompts[0])
        ->toContain('dedicated AIOS ticket_triage mode')
        ->and($harness->prompts[0])
        ->toContain('"ticket_context_schema_version":1')
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.triage_started')
            ->exists())
        ->toBeTrue()
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.triage_completed')
            ->exists())
        ->toBeTrue();
})->with('p3 ticket triage harness identifiers');

test('malformed triage output fails safely without applying a ticket decision or creating work', function () {
    $project = p3TicketExecutionProject();
    p3BindTicketExecutionPm(
        $project,
        AgentHarnessIdentifier::Codex,
    );
    $ticket = p3TicketExecutionTicket($project);

    $harness = new P3TicketTriageTestHarness(
        AgentHarnessIdentifier::Codex,
        new NormalizedExecutionResult(
            exitCode: 0,
            output: 'not valid structured JSON',
            errorOutput: '',
        ),
    );
    app()->instance(
        AgentHarnessResolver::class,
        new AgentHarnessResolver([$harness]),
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);
    $messageCount = $ticket->messages()->count();

    app(RunTicketTriage::class)->handle($attempt);

    expect($attempt?->refresh()->status)
        ->toBe('failed')
        ->and($attempt?->structured_decision)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Failed)
        ->and($ticket->category)
        ->toBeNull()
        ->and($ticket->decision)
        ->toBeNull()
        ->and($ticket->converted_task_id)
        ->toBeNull()
        ->and($project->tasks()->count())
        ->toBe(0)
        ->and($ticket->messages()->count())
        ->toBe($messageCount)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.triage_failed')
            ->where('payload->reason', 'malformed_triage_result')
            ->exists())
        ->toBeTrue();
});

dataset('p3 internal note leak candidates', [
    'requester reply' => ['requester_reply'],
    'question' => ['questions'],
    'blocker' => ['blockers'],
]);

test('ticket triage deterministically blocks internal note reuse before public persistence', function (
    string $candidateField,
) {
    $project = p3TicketExecutionProject();
    p3BindTicketExecutionPm(
        $project,
        AgentHarnessIdentifier::Codex,
    );
    $ticket = p3TicketExecutionTicket($project);
    $internalAuthor = User::factory()->create();
    $confidential = 'Internal only acquisition evidence says the supplier termination is caused by an undisclosed payment dispute that must remain private from the requester.';

    $internalMessage = app(RecordTicketMessage::class)->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::InternalNote,
        $confidential,
        $internalAuthor,
    );

    $decision = p3ValidTicketTriageDecision([
        'decision' => 'needs_information',
        'requester_reply' => 'We need a few additional details before proceeding.',
        'questions' => ['Which environment reproduces the reported behavior?'],
        'blockers' => ['Awaiting the requested diagnostic details.'],
        'implementation_required' => false,
        'proposed_task' => null,
    ]);

    if ($candidateField === 'requester_reply') {
        $decision['requester_reply'] = $confidential;
    } else {
        $decision[$candidateField] = [$confidential];
    }

    p3UseTicketTriageHarness(
        AgentHarnessIdentifier::Codex,
        $decision,
    );

    $attempt = app(ClaimTicketForTriage::class)->handle($project);

    expect($attempt)->not->toBeNull();

    app(RunTicketTriage::class)->handle($attempt);

    $attempt = $attempt?->refresh();
    $ticket = $ticket->refresh();
    $failedAudit = $project->auditEvents()
        ->where('event_type', 'ticket.triage_failed')
        ->latest('id')
        ->firstOrFail();
    $auditPayload = json_encode(
        $failedAudit->payload,
        JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE,
    );

    expect($attempt?->status)
        ->toBe('failed')
        ->and($attempt?->structured_decision)
        ->toBeNull()
        ->and($ticket->status)
        ->toBe(TicketStatus::Failed)
        ->and(TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('author_type', TicketMessageAuthorType::Ai->value)
            ->where('message_type', TicketMessageType::PublicReply->value)
            ->exists())
        ->toBeFalse()
        ->and($failedAudit->payload['reason'])
        ->toBe('invalid_triage_result')
        ->and(data_get(
            $failedAudit->payload,
            'validation_evidence.public_reply_safety.reason',
        ))
        ->toBe('internal_only_verbatim_overlap')
        ->and(data_get(
            $failedAudit->payload,
            'validation_evidence.public_reply_safety.candidate_field',
        ))
        ->toBe($candidateField)
        ->and(data_get(
            $failedAudit->payload,
            'validation_evidence.public_reply_safety.source_message_id',
        ))
        ->toBe($internalMessage->id)
        ->and($auditPayload)
        ->not->toContain($confidential);
})->with('p3 internal note leak candidates');

test('ticket triage public reply safety allows unrelated responses and short common overlap', function () {
    $project = p3TicketExecutionProject();
    p3BindTicketExecutionPm(
        $project,
        AgentHarnessIdentifier::Codex,
    );
    $ticket = p3TicketExecutionTicket($project);
    $internalAuthor = User::factory()->create();

    app(RecordTicketMessage::class)->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::InternalNote,
        'Please provide internal deployment evidence only to engineering because the confidential vendor migration sequence must remain restricted.',
        $internalAuthor,
    );

    p3UseTicketTriageHarness(
        AgentHarnessIdentifier::Codex,
        p3ValidTicketTriageDecision([
            'decision' => 'needs_information',
            'requester_reply' => 'Please provide the affected environment and approximate time of the failure.',
            'questions' => ['Which environment reproduces the reported behavior?'],
            'blockers' => ['Awaiting requester diagnostics.'],
            'implementation_required' => false,
            'proposed_task' => null,
        ]),
    );

    $attempt = app(ClaimTicketForTriage::class)->handle($project);

    expect($attempt)->not->toBeNull();

    app(RunTicketTriage::class)->handle($attempt);

    expect($attempt?->refresh()->status)
        ->toBe('completed')
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::AwaitingRequester)
        ->and(TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('author_type', TicketMessageAuthorType::Ai->value)
            ->where('message_type', TicketMessageType::PublicReply->value)
            ->exists())
        ->toBeTrue();
});

test('triage schema validation rejects cross-project task and phase references', function () {
    $project = p3TicketExecutionProject();
    p3BindTicketExecutionPm(
        $project,
        AgentHarnessIdentifier::Codex,
    );
    $ticket = p3TicketExecutionTicket($project);

    $foreignProject = p3TicketExecutionProject(
        'Foreign Ticket Triage Project',
    );
    $foreignPhase = Phase::create([
        'project_id' => $foreignProject->id,
        'position' => 1,
        'title' => 'Foreign Phase',
        'objective' => 'Must not be referenced.',
    ]);
    $foreignTask = Task::create([
        'project_id' => $foreignProject->id,
        'phase_id' => $foreignPhase->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Foreign Task',
        'objective' => 'Must not be referenced.',
        'acceptance_criteria' => ['Remain isolated.'],
        'implementation_prompt' => 'Do not reference this Task.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);

    $decision = p3ValidTicketTriageDecision();
    $decision['proposed_task']['depends_on_task_ids'] = [
        $foreignTask->id,
    ];
    $decision['proposed_task']['preferred_phase_id'] =
        $foreignPhase->id;

    p3UseTicketTriageHarness(
        AgentHarnessIdentifier::Codex,
        $decision,
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    app(RunTicketTriage::class)->handle($attempt);

    expect($attempt?->refresh()->status)
        ->toBe('failed')
        ->and($attempt?->structured_decision)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Failed)
        ->and($project->tasks()->count())
        ->toBe(0)
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.triage_failed')
            ->where('payload->reason', 'invalid_triage_result')
            ->exists())
        ->toBeTrue();
});

test('triage schema validation rejects cross-project duplicate ticket references', function () {
    $project = p3TicketExecutionProject();
    p3BindTicketExecutionPm(
        $project,
        AgentHarnessIdentifier::Codex,
    );
    $ticket = p3TicketExecutionTicket($project);

    $foreignProject = p3TicketExecutionProject(
        'Foreign Duplicate Project',
    );
    $foreignTicket = p3TicketExecutionTicket(
        $foreignProject,
        'TICKET-000099',
    );

    p3UseTicketTriageHarness(
        AgentHarnessIdentifier::Codex,
        p3ValidTicketTriageDecision([
            'decision' => 'duplicate',
            'implementation_required' => false,
            'proposed_task' => null,
            'duplicate_ticket_id' => $foreignTicket->id,
        ]),
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    app(RunTicketTriage::class)->handle($attempt);

    expect($attempt?->refresh()->status)
        ->toBe('failed')
        ->and($attempt?->structured_decision)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Failed);
});

test('an unbound project manager fails ticket triage instead of using the legacy codex fallback', function () {
    $project = p3TicketExecutionProject();
    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'status' => 'idle',
    ]);
    $ticket = p3TicketExecutionTicket($project);
    $harness = new P3TicketTriageTestHarness(
        AgentHarnessIdentifier::Codex,
        new NormalizedExecutionResult(
            exitCode: 0,
            output: json_encode(
                p3ValidTicketTriageDecision(),
                JSON_THROW_ON_ERROR,
            ),
            errorOutput: '',
        ),
    );
    app()->instance(
        AgentHarnessResolver::class,
        new AgentHarnessResolver([$harness]),
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    app(RunTicketTriage::class)->handle($attempt);

    expect($attempt?->refresh()->status)
        ->toBe('failed')
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Failed)
        ->and($harness->executions)
        ->toBe(0)
        ->and(AgentRun::query()
            ->where('project_id', $project->id)
            ->exists())
        ->toBeFalse()
        ->and($project->auditEvents()
            ->where('event_type', 'ticket.triage_failed')
            ->where('payload->reason', 'agent_misconfigured')
            ->exists())
        ->toBeTrue();
});

test('a disabled bound project manager fails ticket triage without harness execution', function () {
    $project = p3TicketExecutionProject();
    p3BindTicketExecutionPm(
        $project,
        AgentHarnessIdentifier::Codex,
        enabled: false,
    );
    $ticket = p3TicketExecutionTicket($project);
    $harness = new P3TicketTriageTestHarness(
        AgentHarnessIdentifier::Codex,
        new NormalizedExecutionResult(
            exitCode: 0,
            output: '{}',
            errorOutput: '',
        ),
    );
    app()->instance(
        AgentHarnessResolver::class,
        new AgentHarnessResolver([$harness]),
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    app(RunTicketTriage::class)->handle($attempt);

    expect($attempt?->refresh()->status)
        ->toBe('failed')
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Failed)
        ->and($harness->executions)
        ->toBe(0)
        ->and(AgentRun::query()
            ->where('project_id', $project->id)
            ->exists())
        ->toBeFalse();
});

test('a configured broken harness never falls back to another provider', function () {
    $project = p3TicketExecutionProject();
    p3BindTicketExecutionPm(
        $project,
        AgentHarnessIdentifier::Codex,
    );
    $ticket = p3TicketExecutionTicket($project);

    $claudeHarness = new P3TicketTriageTestHarness(
        AgentHarnessIdentifier::ClaudeCode,
        new NormalizedExecutionResult(
            exitCode: 0,
            output: json_encode(
                p3ValidTicketTriageDecision(),
                JSON_THROW_ON_ERROR,
            ),
            errorOutput: '',
        ),
    );
    app()->instance(
        AgentHarnessResolver::class,
        new AgentHarnessResolver([$claudeHarness]),
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    app(RunTicketTriage::class)->handle($attempt);

    expect($attempt?->refresh()->status)
        ->toBe('failed')
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Failed)
        ->and($claudeHarness->executions)
        ->toBe(0)
        ->and(AgentRun::query()
            ->where('project_id', $project->id)
            ->exists())
        ->toBeFalse();
});

test('a retry uses fresh ticket triage context and a new immutable agent snapshot', function () {
    $project = p3TicketExecutionProject();
    [$agent] = p3BindTicketExecutionPm(
        $project,
        AgentHarnessIdentifier::Codex,
    );
    $ticket = p3TicketExecutionTicket($project);

    $harness = new P3TicketTriageTestHarness(
        AgentHarnessIdentifier::Codex,
        new NormalizedExecutionResult(
            exitCode: 0,
            output: 'malformed',
            errorOutput: '',
        ),
    );
    app()->instance(
        AgentHarnessResolver::class,
        new AgentHarnessResolver([$harness]),
    );

    $firstAttempt = app(ClaimTicketForTriage::class)
        ->handle($project);
    app(RunTicketTriage::class)->handle($firstAttempt);

    $firstRun = AgentRun::query()
        ->findOrFail($firstAttempt?->refresh()->agent_run_id);
    $firstVersion =
        $firstRun->configuration_snapshot['agent']['configuration_version'];
    $firstContextHash =
        $firstRun->configuration_snapshot['context_hash'];

    $agent->update([
        'default_context' => 'Use the updated fresh ticket triage context.',
    ]);
    $harness->result = new NormalizedExecutionResult(
        exitCode: 0,
        output: json_encode(
            p3ValidTicketTriageDecision(),
            JSON_THROW_ON_ERROR,
        ),
        errorOutput: '',
    );

    $secondAttempt = app(ClaimTicketForTriage::class)
        ->handle($project);
    app(RunTicketTriage::class)->handle($secondAttempt);

    $secondRun = AgentRun::query()
        ->findOrFail($secondAttempt?->refresh()->agent_run_id);

    expect($firstAttempt?->refresh()->status)
        ->toBe('failed')
        ->and($secondAttempt?->status)
        ->toBe('completed')
        ->and($secondAttempt?->number)
        ->toBe(2)
        ->and($secondRun->id)
        ->not->toBe($firstRun->id)
        ->and($secondRun->configuration_snapshot['agent']['configuration_version'])
        ->toBeGreaterThan($firstVersion)
        ->and($secondRun->configuration_snapshot['context_hash'])
        ->not->toBe($firstContextHash)
        ->and($secondRun->configuration_snapshot['agent']['default_context'])
        ->toBe('Use the updated fresh ticket triage context.')
        ->and($harness->executions)
        ->toBe(2);
});

test('existing roadmap project manager decomposition remains unchanged', function () {
    $project = p3TicketExecutionProject(
        'Roadmap Regression Project',
    );
    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'status' => 'idle',
    ]);

    $roadmap = Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/p3-008-regression.md',
        'status' => 'uploaded',
        'content' => 'Implement one focused roadmap task.',
    ]);

    $plan = [
        'project_knowledge' => [
            'overview' => 'Roadmap behavior remains unchanged.',
        ],
        'phases' => [[
            'title' => 'Regression',
            'objective' => 'Prove roadmap decomposition still works.',
            'tasks' => [[
                'title' => 'Keep roadmap PM behavior intact',
                'objective' => 'Preserve the existing roadmap output contract.',
                'acceptance_criteria' => [
                    'The roadmap creates the expected Task.',
                ],
                'scope' => [],
                'constraints' => [],
                'relevant_paths' => [],
                'verification_commands' => [],
                'implementation_prompt' => 'Preserve roadmap behavior.',
                'depends_on' => [],
                'completion_status' => 'queued',
            ]],
        ]],
    ];

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn([
            'exit_code' => 0,
            'output' => json_encode([
                'type' => 'item.completed',
                'item' => [
                    'type' => 'agent_message',
                    'text' => json_encode(
                        $plan,
                        JSON_THROW_ON_ERROR,
                    ),
                ],
            ], JSON_THROW_ON_ERROR),
            'error_output' => '',
        ]);

    app(RunProjectManager::class)->handle($roadmap);

    expect($roadmap->refresh()->status)
        ->toBe('processed')
        ->and($project->tasks()->count())
        ->toBe(1)
        ->and($project->tasks()->sole()->title)
        ->toBe('Keep roadmap PM behavior intact');
});
