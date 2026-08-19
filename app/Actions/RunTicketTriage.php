<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Agent;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketTriageAttempt;
use App\Services\AgentContextAssembler;
use App\Services\AgentHarness;
use App\Services\AgentHarnessResolver;
use App\Services\AgentResolver;
use App\Services\AgentRunRecorder;
use App\Services\AuditLogger;
use App\Services\DatabaseProtectionGuard;
use App\Services\StructuredResultParser;
use App\Services\TicketContextCapsuleFactory;
use App\Services\TicketWorkflow;
use App\Services\WorkerHeartbeat;
use App\TicketCategory;
use App\TicketDecision;
use App\TicketPriority;
use App\TicketStatus;
use App\WorkerLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use LogicException;
use Throwable;

class RunTicketTriage
{
    public function __construct(
        private AgentResolver $agents,
        private AgentHarnessResolver $harnesses,
        private AgentContextAssembler $contextAssembler,
        private TicketContextCapsuleFactory $ticketContexts,
        private AgentRunRecorder $runs,
        private StructuredResultParser $parser,
        private TicketWorkflow $workflow,
        private WorkerHeartbeat $heartbeat,
        private AuditLogger $audit,
        private DatabaseProtectionGuard $databaseProtection,
    ) {}

    /**
     * @return array{
     *     exit_code: int,
     *     output: string,
     *     error_output: string,
     *     external_run_id?: string|null,
     *     usage?: array<string, mixed>|null,
     *     provider_metadata?: array<string, mixed>
     * }
     */
    public function handle(
        TicketTriageAttempt $attempt,
        ?WorkerLease $lease = null,
    ): array {
        $attempt = TicketTriageAttempt::query()
            ->with('ticket.project')
            ->findOrFail($attempt->id);

        if (! in_array($attempt->status, ['claimed', 'running'], true)) {
            return $this->emptyExecution();
        }

        $ticket = $attempt->ticket;
        $project = $ticket->project;

        $this->renewLease($lease);

        try {
            [$agent, $harness] = $this->resolveAgent($project);
        } catch (LogicException $exception) {
            $this->failAttempt(
                $attempt,
                -1,
                'agent_misconfigured',
                'Resolve the bound Project Manager Agent/harness configuration before retrying ticket triage.',
            );

            return [
                'exit_code' => -1,
                'output' => '',
                'error_output' => $exception->getMessage(),
            ];
        }

        try {
            $ticketContext = $this->ticketContexts->make($ticket);
            $retrievalManifest = is_array(
                $ticketContext['retrieval_manifest'] ?? null,
            ) ? $ticketContext['retrieval_manifest'] : null;
            unset($ticketContext['retrieval_manifest']);

            $assembled = $this->contextAssembler->assemble(
                $agent,
                AgentRole::ProjectManager,
                $ticketContext,
            );
            $prompt = $this->prompt($assembled->toArray());
        } catch (Throwable $throwable) {
            $this->failAttempt(
                $attempt,
                -1,
                'context_assembly_failed',
                'Inspect the Ticket context/retrieval evidence and retry with a fresh Project Manager execution.',
            );

            return [
                'exit_code' => -1,
                'output' => '',
                'error_output' => $throwable->getMessage(),
            ];
        }

        try {
            $run = $this->runs->start(
                $project,
                AgentRole::ProjectManager,
                $prompt,
                lease: $lease,
                retrievalManifest: $retrievalManifest,
                agent: $agent,
                context: $assembled,
            );
        } catch (Throwable $throwable) {
            $this->failAttempt(
                $attempt,
                -1,
                'agent_run_start_failed',
                'Inspect AgentRun persistence/audit health before retrying ticket triage.',
            );

            return [
                'exit_code' => -1,
                'output' => '',
                'error_output' => $throwable->getMessage(),
            ];
        }

        $attempt->update([
            'agent_run_id' => $run->id,
            'status' => 'running',
        ]);

        $this->audit->record('ticket.triage_started', [
            'ticket_id' => $ticket->id,
            'ticket_key' => $ticket->key,
            'ticket_triage_attempt_id' => $attempt->id,
            'attempt_number' => $attempt->number,
            'agent_run_id' => $run->id,
            'agent_id' => $agent->id,
            'agent_configuration_version' => $agent->configuration_version,
            'harness' => $agent->getRawOriginal('harness'),
        ], $project);

        $this->renewLease($lease);

        try {
            $this->databaseProtection->guard($project);

            $onOutput = function (
                string $type,
                string $output,
            ) use ($run, $project, $lease): void {
                $this->runs->appendLiveOutput(
                    $run,
                    $type,
                    $output,
                );

                if ($lease === null) {
                    $this->heartbeat->beat(
                        $project,
                        AgentRole::ProjectManager,
                    );
                }
            };

            $onHeartbeat = $lease === null
                ? null
                : fn (): bool => $this->heartbeat->renew($lease);

            $execution = $harness->execute(
                $project,
                $agent,
                $prompt,
                $onOutput,
                $onHeartbeat,
            )->toArray();
        } catch (Throwable $throwable) {
            $execution = [
                'exit_code' => -1,
                'output' => '',
                'error_output' => $throwable->getMessage(),
            ];
        }

        $this->renewLease($lease);
        $this->runs->complete($run, $execution);

        if ($execution['exit_code'] !== 0) {
            $this->failAttempt(
                $attempt,
                $execution['exit_code'],
                'execution_failed',
                'Inspect the AgentRun/harness evidence before retrying ticket triage.',
            );

            return $execution;
        }

        $decision = $this->parser->parseAgentMessage(
            $execution['output'],
        );

        if ($decision === null) {
            $this->failAttempt(
                $attempt,
                $execution['exit_code'],
                'malformed_triage_result',
                'The Project Manager must return the dedicated ticket_triage JSON contract.',
            );

            return $execution;
        }

        try {
            $validatedDecision = $this->validateDecision(
                $decision,
                $ticket,
            );
        } catch (ValidationException) {
            $this->failAttempt(
                $attempt,
                $execution['exit_code'],
                'invalid_triage_result',
                'The Project Manager returned a parseable result that failed the ticket_triage schema or project-scope checks.',
            );

            return $execution;
        }

        $this->persistDecision(
            $attempt,
            $validatedDecision,
        );
        $this->renewLease($lease);

        return $execution;
    }

    /** @return array{0: Agent, 1: AgentHarness} */
    private function resolveAgent(Project $project): array
    {
        $agent = $this->agents->forRole(
            $project,
            AgentRole::ProjectManager,
        );

        return [
            $agent,
            $this->harnesses->resolve($agent),
        ];
    }

    /** @param array<string, mixed> $assembledContext */
    private function prompt(array $assembledContext): string
    {
        $contract = <<<'PROMPT'
You are the Project Manager executing the dedicated AIOS ticket_triage mode. Use only the supplied bounded, project-scoped context plus the repository/documentation you are explicitly allowed to inspect. Classify and propose handling for exactly this Ticket. Return one JSON object only, with no Markdown fence, prose outside the JSON, chain-of-thought, hidden reasoning, or step-by-step deliberation.

Required JSON contract:
{
  "category": "bug|enhancement|feature",
  "decision": "approved|needs_information|blocked|self_service|duplicate|rejected",
  "confidence": 0.0,
  "summary": "concise triage summary",
  "documentation_alignment": ["concise alignment/conflict evidence"],
  "affected_areas": ["bounded affected area"],
  "complexity": "low|medium|high",
  "requester_reply": "safe proposed public reply text",
  "internal_reason_summary": "concise decision evidence only; never chain-of-thought",
  "questions": ["question for requester when needed"],
  "blockers": ["blocking fact when present"],
  "duplicate_ticket_id": null,
  "suggested_priority": "low|normal|high|critical|emergency",
  "implementation_required": false,
  "proposed_task": null,
  "escalation_flags": ["concise deterministic-risk signal"]
}

When implementation_required is true, proposed_task must be exactly one bounded Task proposal with:
{
  "title": "...",
  "objective": "...",
  "acceptance_criteria": ["..."],
  "scope": ["..."],
  "constraints": ["..."],
  "relevant_paths": ["..."],
  "verification_commands": ["..."],
  "implementation_prompt": "...",
  "depends_on_task_ids": [],
  "preferred_phase_id": null
}

Rules:
- Ticket triage is advisory structured reasoning only. Do not mutate Ticket state, create/update Tasks, reorder phases, close Tickets, send replies, or decide durable workflow state.
- AIOS performs all persistence, escalation validation, Ticket-to-Task conversion, phase placement, ordering, replies, and transitions after validating your proposal.
- implementation_required may be true only with decision=approved and one proposed_task. Otherwise it must be false and proposed_task must be null.
- duplicate_ticket_id is required only for decision=duplicate and must reference a different Ticket from this project; otherwise it must be null.
- preferred_phase_id and depends_on_task_ids may reference only existing records from this project that are present in the supplied context/evidence.
- Surface documentation conflicts in documentation_alignment and escalation_flags; never silently override approved documentation.
- requester_reply must contain only safe text intended for possible public use. Never copy internal notes verbatim merely because they appear in context.
- internal_reason_summary is concise durable decision evidence only. Never include hidden reasoning, chain-of-thought, private deliberation, or a reasoning transcript.
- Verification commands are proposals only and must remain safe, non-destructive commands from the approved project toolchain, with no shell operators, redirects, destructive database operations, or credential access.
PROMPT;

        return $contract."\n\nAIOS assembled context:\n"
            .json_encode(
                $assembledContext,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
    }

    /**
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function validateDecision(
        array $decision,
        Ticket $ticket,
    ): array {
        $proposalRequired = ($decision['implementation_required'] ?? null) === true;

        // Schema-required list fields may intentionally be empty. Laravel's required rule
        // treats an empty array as missing, so use present for lists whose empty state is valid.
        $validator = validator($decision, [
            'category' => [
                'required',
                'string',
                Rule::enum(TicketCategory::class),
            ],
            'decision' => [
                'required',
                'string',
                Rule::enum(TicketDecision::class),
            ],
            'confidence' => [
                'required',
                'numeric',
                'between:0,1',
            ],
            'summary' => [
                'required',
                'string',
                'max:4000',
            ],
            'documentation_alignment' => [
                'present',
                'array',
                'max:20',
            ],
            'documentation_alignment.*' => [
                'string',
                'max:2000',
            ],
            'affected_areas' => [
                'present',
                'array',
                'max:50',
            ],
            'affected_areas.*' => [
                'string',
                'max:1000',
            ],
            'complexity' => [
                'required',
                'string',
                Rule::in(['low', 'medium', 'high']),
            ],
            'requester_reply' => [
                'required',
                'string',
                'max:8000',
            ],
            'internal_reason_summary' => [
                'required',
                'string',
                'max:2000',
            ],
            'questions' => [
                'present',
                'array',
                'max:20',
            ],
            'questions.*' => [
                'string',
                'max:2000',
            ],
            'blockers' => [
                'present',
                'array',
                'max:20',
            ],
            'blockers.*' => [
                'string',
                'max:2000',
            ],
            'duplicate_ticket_id' => [
                'present',
                'nullable',
                'integer',
            ],
            'suggested_priority' => [
                'required',
                'string',
                Rule::enum(TicketPriority::class),
            ],
            'implementation_required' => [
                'required',
                'boolean',
            ],
            'proposed_task' => [
                'present',
                'nullable',
                'array',
            ],
            'proposed_task.title' => [
                $proposalRequired ? 'required' : 'nullable',
                'string',
                'max:255',
            ],
            'proposed_task.objective' => [
                $proposalRequired ? 'required' : 'nullable',
                'string',
                'max:4000',
            ],
            'proposed_task.acceptance_criteria' => [
                $proposalRequired ? 'required' : 'nullable',
                'array',
                'min:1',
                'max:30',
            ],
            'proposed_task.acceptance_criteria.*' => [
                'required',
                'string',
                'max:2000',
            ],
            'proposed_task.scope' => [
                $proposalRequired ? 'present' : 'nullable',
                'array',
                'max:50',
            ],
            'proposed_task.scope.*' => [
                'string',
                'max:1000',
            ],
            'proposed_task.constraints' => [
                $proposalRequired ? 'present' : 'nullable',
                'array',
                'max:50',
            ],
            'proposed_task.constraints.*' => [
                'string',
                'max:2000',
            ],
            'proposed_task.relevant_paths' => [
                $proposalRequired ? 'present' : 'nullable',
                'array',
                'max:50',
            ],
            'proposed_task.relevant_paths.*' => [
                'string',
                'max:1000',
            ],
            'proposed_task.verification_commands' => [
                $proposalRequired ? 'present' : 'nullable',
                'array',
                'max:30',
            ],
            'proposed_task.verification_commands.*' => [
                'string',
                'max:1000',
            ],
            'proposed_task.implementation_prompt' => [
                $proposalRequired ? 'required' : 'nullable',
                'string',
                'max:16000',
            ],
            'proposed_task.depends_on_task_ids' => [
                $proposalRequired ? 'present' : 'nullable',
                'array',
                'max:50',
            ],
            'proposed_task.depends_on_task_ids.*' => [
                'integer',
                'distinct',
            ],
            'proposed_task.preferred_phase_id' => [
                $proposalRequired ? 'present' : 'nullable',
                'nullable',
                'integer',
            ],
            'escalation_flags' => [
                'present',
                'array',
                'max:20',
            ],
            'escalation_flags.*' => [
                'string',
                'max:1000',
            ],
        ]);

        $validator->after(function (Validator $validator) use (
            $decision,
            $ticket,
        ): void {
            $confidence = $decision['confidence'] ?? null;
            if (! is_int($confidence) && ! is_float($confidence)) {
                $validator->errors()->add(
                    'confidence',
                    'Ticket triage confidence must be a JSON number.',
                );
            }

            $implementationRequired =
                $decision['implementation_required'] ?? null;
            if (! is_bool($implementationRequired)) {
                $validator->errors()->add(
                    'implementation_required',
                    'implementation_required must be a JSON boolean.',
                );
            }

            $decisionValue = $decision['decision'] ?? null;
            $proposedTask = $decision['proposed_task'] ?? null;

            if (
                $implementationRequired === true
                && $decisionValue !== TicketDecision::Approved->value
            ) {
                $validator->errors()->add(
                    'implementation_required',
                    'Only an approved Ticket may propose implementation.',
                );
            }

            if (
                $implementationRequired === true
                && ! is_array($proposedTask)
            ) {
                $validator->errors()->add(
                    'proposed_task',
                    'An implementation-required decision must contain exactly one proposed Task.',
                );
            }

            if (
                $implementationRequired === false
                && $proposedTask !== null
            ) {
                $validator->errors()->add(
                    'proposed_task',
                    'proposed_task must be null when implementation is not required.',
                );
            }

            $duplicateTicketId = $decision['duplicate_ticket_id'] ?? null;

            if (
                $duplicateTicketId !== null
                && ! is_int($duplicateTicketId)
            ) {
                $validator->errors()->add(
                    'duplicate_ticket_id',
                    'duplicate_ticket_id must be a JSON integer or null.',
                );
            }

            if (
                $decisionValue === TicketDecision::Duplicate->value
                && ! is_int($duplicateTicketId)
            ) {
                $validator->errors()->add(
                    'duplicate_ticket_id',
                    'A duplicate decision must identify the duplicate Ticket.',
                );
            }

            if (
                $decisionValue !== TicketDecision::Duplicate->value
                && $duplicateTicketId !== null
            ) {
                $validator->errors()->add(
                    'duplicate_ticket_id',
                    'duplicate_ticket_id is allowed only for a duplicate decision.',
                );
            }

            if (is_int($duplicateTicketId)) {
                if ($duplicateTicketId === $ticket->id) {
                    $validator->errors()->add(
                        'duplicate_ticket_id',
                        'A Ticket cannot be a duplicate of itself.',
                    );
                } elseif (! Ticket::query()
                    ->whereKey($duplicateTicketId)
                    ->where('project_id', $ticket->project_id)
                    ->exists()) {
                    $validator->errors()->add(
                        'duplicate_ticket_id',
                        'The duplicate Ticket must belong to the same project.',
                    );
                }
            }

            if (! is_array($proposedTask)) {
                return;
            }

            $dependencyIds =
                $proposedTask['depends_on_task_ids'] ?? [];

            if (is_array($dependencyIds)) {
                foreach ($dependencyIds as $dependencyId) {
                    if (! is_int($dependencyId)) {
                        $validator->errors()->add(
                            'proposed_task.depends_on_task_ids',
                            'Task dependency IDs must be JSON integers.',
                        );
                        break;
                    }
                }

                $integerDependencyIds = array_values(array_filter(
                    $dependencyIds,
                    is_int(...),
                ));

                if (
                    count($integerDependencyIds) > 0
                    && Task::query()
                        ->where('project_id', $ticket->project_id)
                        ->whereIn('id', $integerDependencyIds)
                        ->count() !== count(array_unique($integerDependencyIds))
                ) {
                    $validator->errors()->add(
                        'proposed_task.depends_on_task_ids',
                        'Every proposed Task dependency must belong to the same project.',
                    );
                }
            }

            $preferredPhaseId =
                $proposedTask['preferred_phase_id'] ?? null;

            if (
                $preferredPhaseId !== null
                && ! is_int($preferredPhaseId)
            ) {
                $validator->errors()->add(
                    'proposed_task.preferred_phase_id',
                    'preferred_phase_id must be a JSON integer or null.',
                );
            }

            if (
                is_int($preferredPhaseId)
                && ! Phase::query()
                    ->whereKey($preferredPhaseId)
                    ->where('project_id', $ticket->project_id)
                    ->exists()
            ) {
                $validator->errors()->add(
                    'proposed_task.preferred_phase_id',
                    'The preferred phase must belong to the same project.',
                );
            }
        });

        return $validator->validate();
    }

    /** @param array<string, mixed> $decision */
    private function persistDecision(
        TicketTriageAttempt $attempt,
        array $decision,
    ): void {
        DB::transaction(function () use ($attempt, $decision): void {
            $lockedAttempt = TicketTriageAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->id);
            $ticket = Ticket::query()
                ->lockForUpdate()
                ->findOrFail($lockedAttempt->ticket_id);

            if ($lockedAttempt->status === 'completed') {
                return;
            }

            if ($lockedAttempt->status !== 'running') {
                throw new LogicException(
                    'Only a running Ticket triage attempt may persist a structured decision.',
                );
            }

            if (
                TicketStatus::from(
                    (string) $ticket->getRawOriginal('status'),
                ) !== TicketStatus::Triaging
            ) {
                throw new LogicException(
                    'Ticket triage output cannot be persisted after the Ticket leaves triaging.',
                );
            }

            $lockedAttempt->update([
                'structured_decision' => $decision,
                'status' => 'completed',
                'finished_at' => now(),
            ]);

            $this->audit->record('ticket.triage_completed', [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->key,
                'ticket_triage_attempt_id' => $lockedAttempt->id,
                'attempt_number' => $lockedAttempt->number,
                'agent_run_id' => $lockedAttempt->agent_run_id,
                'category' => $decision['category'],
                'decision' => $decision['decision'],
                'confidence' => $decision['confidence'],
                'complexity' => $decision['complexity'],
                'suggested_priority' => $decision['suggested_priority'],
                'implementation_required' => $decision['implementation_required'],
                'has_proposed_task' => $decision['proposed_task'] !== null,
                'escalation_flags' => $decision['escalation_flags'],
            ], $ticket->project);
        }, attempts: 3);
    }

    private function failAttempt(
        TicketTriageAttempt $attempt,
        int $exitCode,
        string $reason,
        string $action,
    ): void {
        DB::transaction(function () use (
            $attempt,
            $exitCode,
            $reason,
            $action,
        ): void {
            $lockedAttempt = TicketTriageAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->id);
            $ticket = Ticket::query()
                ->lockForUpdate()
                ->findOrFail($lockedAttempt->ticket_id);

            if ($lockedAttempt->status === 'completed') {
                return;
            }

            $lockedAttempt->update([
                'status' => 'failed',
                'structured_decision' => null,
                'finished_at' => now(),
            ]);

            if (
                TicketStatus::from(
                    (string) $ticket->getRawOriginal('status'),
                ) === TicketStatus::Triaging
            ) {
                $this->workflow->transition(
                    $ticket,
                    TicketStatus::Failed,
                );
            }

            $this->audit->record('ticket.triage_failed', [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->key,
                'ticket_triage_attempt_id' => $lockedAttempt->id,
                'attempt_number' => $lockedAttempt->number,
                'agent_run_id' => $lockedAttempt->agent_run_id,
                'exit_code' => $exitCode,
                'reason' => $reason,
                'action' => $action,
            ], $ticket->project);
        }, attempts: 3);
    }

    private function renewLease(?WorkerLease $lease): void
    {
        if ($lease !== null) {
            $this->heartbeat->renew($lease);
        }
    }

    /** @return array{exit_code: int, output: string, error_output: string} */
    private function emptyExecution(): array
    {
        return [
            'exit_code' => 0,
            'output' => '',
            'error_output' => '',
        ];
    }
}
