<?php

namespace App\Actions;

use App\Models\Phase;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketTriageAttempt;
use App\Services\AuditLogger;
use App\Services\ObsidianProjectNotes;
use App\Services\TaskValidator;
use App\Services\TicketTriagePolicy;
use App\Services\TicketWorkflow;
use App\TaskStatus;
use App\TicketCategory;
use App\TicketDecision;
use App\TicketEscalationReason;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketPriority;
use App\TicketStatus;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class ConvertTicketToTask
{
    private const string FutureIntakeSystemKeyPrefix = 'ticket-future-intake-';

    private const string FutureIntakeTitle = 'Future Intake / Backlog';

    private const string FutureIntakeObjective = 'AIOS-managed append-only future intake for safe Ticket-origin implementation work.';

    public function __construct(
        private TicketTriagePolicy $triagePolicy,
        private TicketWorkflow $workflow,
        private TaskValidator $taskValidator,
        private RecordTicketMessage $messages,
        private AuditLogger $audit,
        private ObsidianProjectNotes $notes,
    ) {}

    public function handle(TicketTriageAttempt $attempt): ?Task
    {
        $task = DB::transaction(function () use ($attempt): ?Task {
            $attemptSnapshot = TicketTriageAttempt::query()->findOrFail($attempt->id);
            $ticketSnapshot = Ticket::query()->findOrFail($attemptSnapshot->ticket_id);
            $project = Project::query()
                ->lockForUpdate()
                ->findOrFail($ticketSnapshot->project_id);
            $ticket = Ticket::query()
                ->lockForUpdate()
                ->findOrFail($ticketSnapshot->id);
            $lockedAttempt = TicketTriageAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attemptSnapshot->id);

            if ($lockedAttempt->ticket_id !== $ticket->id) {
                throw new LogicException(
                    'Ticket triage attempt no longer belongs to the expected Ticket.',
                );
            }

            if ($ticket->project_id !== $project->id) {
                throw new LogicException(
                    'Ticket no longer belongs to the expected project.',
                );
            }

            if ($ticket->converted_task_id !== null) {
                return $this->existingConvertedTask($project, $ticket);
            }

            if ($ticket->getRawOriginal('status') === TicketStatus::Converted->value) {
                throw new LogicException(
                    'Converted Ticket is missing its durable Task link.',
                );
            }

            if (
                $lockedAttempt->status !== 'completed'
                || $ticket->getRawOriginal('status') !== TicketStatus::Triaging->value
            ) {
                return null;
            }

            $decision = $lockedAttempt->structured_decision;
            if (! is_array($decision)) {
                return null;
            }

            $storedValidation = $decision['aios_validation'] ?? null;
            if (
                ! is_array($storedValidation)
                || ($storedValidation['automatic_task_conversion_eligible'] ?? false) !== true
            ) {
                return null;
            }

            if (! $this->decisionShapeSupportsRevalidation($decision)) {
                $this->failConversionLocked(
                    $ticket,
                    $lockedAttempt,
                    'Stored triage decision cannot be safely revalidated for conversion.',
                );

                return null;
            }

            /** @var EloquentCollection<int, Phase> $phases */
            $phases = Phase::query()
                ->where('project_id', $project->id)
                ->orderBy('position')
                ->lockForUpdate()
                ->get();

            /** @var EloquentCollection<int, Task> $tasks */
            $tasks = Task::query()
                ->where('project_id', $project->id)
                ->orderBy('position')
                ->lockForUpdate()
                ->get();

            $freshValidation = $this->triagePolicy->evaluate(
                $ticket,
                $decision,
            );

            if ($freshValidation['requires_operator_decision'] === true) {
                $this->escalateLocked(
                    $ticket,
                    $lockedAttempt,
                    $decision,
                    $freshValidation,
                );

                return null;
            }

            if (! $this->decisionContractAllowsConversion($decision, $freshValidation)) {
                $this->failConversionLocked(
                    $ticket,
                    $lockedAttempt,
                    'Commit-time triage validation no longer permits automatic conversion.',
                );

                return null;
            }

            $categoryValue = $decision['category'] ?? null;
            $priorityValue = $decision['suggested_priority'] ?? null;
            $category = is_string($categoryValue)
                ? TicketCategory::tryFrom($categoryValue)
                : null;
            $priority = is_string($priorityValue)
                ? TicketPriority::tryFrom($priorityValue)
                : null;

            if ($category === null || $priority === null) {
                $this->failConversionLocked(
                    $ticket,
                    $lockedAttempt,
                    'The validated Ticket category or suggested priority is invalid.',
                );

                return null;
            }

            $proposal = $decision['proposed_task'];
            if (! is_array($proposal)) {
                $this->failConversionLocked(
                    $ticket,
                    $lockedAttempt,
                    'Validated Ticket is missing its single proposed Task.',
                );

                return null;
            }

            try {
                $proposal = $this->validatedProposal($proposal);
            } catch (ValidationException) {
                $this->failConversionLocked(
                    $ticket,
                    $lockedAttempt,
                    'The proposed Task failed commit-time Task contract validation.',
                );

                return null;
            }

            $dependencyIds = $proposal['depends_on_task_ids'];
            $dependencies = $tasks->whereIn('id', $dependencyIds)->values();

            if ($dependencies->count() !== count($dependencyIds)) {
                $this->escalateLocked(
                    $ticket,
                    $lockedAttempt,
                    $decision,
                    $this->validationWithEscalationReason(
                        $freshValidation,
                        TicketEscalationReason::UnsafeOrUnresolvedDependencyPlacement,
                    ),
                );

                return null;
            }

            if (
                $dependencies->contains(
                    fn (Task $dependency): bool => $dependency->getRawOriginal('status')
                        === TaskStatus::Cancelled->value,
                )
            ) {
                $this->escalateLocked(
                    $ticket,
                    $lockedAttempt,
                    $decision,
                    $this->validationWithEscalationReason(
                        $freshValidation,
                        TicketEscalationReason::UnsafeOrUnresolvedDependencyPlacement,
                    ),
                );

                return null;
            }

            $currentPhase = $this->currentPhase($phases, $tasks);
            $preferredPhase = $this->preferredPhase(
                $phases,
                $proposal['preferred_phase_id'],
            );

            if (
                $proposal['preferred_phase_id'] !== null
                && $preferredPhase === null
            ) {
                $this->escalateLocked(
                    $ticket,
                    $lockedAttempt,
                    $decision,
                    $this->validationWithEscalationReason(
                        $freshValidation,
                        TicketEscalationReason::RoadmapOrPhaseReorderingOrInterruptionRequested,
                    ),
                );

                return null;
            }

            if (
                $currentPhase !== null
                && $preferredPhase !== null
                && (int) $preferredPhase->position < (int) $currentPhase->position
            ) {
                $this->escalateLocked(
                    $ticket,
                    $lockedAttempt,
                    $decision,
                    $this->validationWithEscalationReason(
                        $freshValidation,
                        TicketEscalationReason::RoadmapOrPhaseReorderingOrInterruptionRequested,
                    ),
                );

                return null;
            }

            $targetPhase = $this->targetPhase(
                $project,
                $phases,
                $tasks,
                $currentPhase,
                $preferredPhase,
                $dependencies,
            );

            if (! $this->dependenciesFitTargetPhase(
                $dependencies,
                $phases,
                $targetPhase,
            )) {
                $this->escalateLocked(
                    $ticket,
                    $lockedAttempt,
                    $decision,
                    $this->validationWithEscalationReason(
                        $freshValidation,
                        TicketEscalationReason::UnsafeOrUnresolvedDependencyPlacement,
                    ),
                );

                return null;
            }

            [$position, $key] = $this->nextTaskIdentity($tasks);

            $task = Task::create([
                'project_id' => $project->id,
                'phase_id' => $targetPhase->id,
                'key' => $key,
                'position' => $position,
                'title' => $proposal['title'],
                'objective' => $proposal['objective'],
                'acceptance_criteria' => $proposal['acceptance_criteria'],
                'scope' => $proposal['scope'],
                'constraints' => $proposal['constraints'],
                'relevant_paths' => $proposal['relevant_paths'],
                'verification_commands' => $proposal['verification_commands'],
                'implementation_prompt' => $proposal['implementation_prompt'],
                'context_capsule' => [
                    'phase' => $targetPhase->title,
                    'objective' => $proposal['objective'],
                    'acceptance_criteria' => $proposal['acceptance_criteria'],
                    'scope' => $proposal['scope'],
                    'constraints' => $proposal['constraints'],
                    'relevant_paths' => $proposal['relevant_paths'],
                    'verification_commands' => $proposal['verification_commands'],
                    'obsidian_notes' => [],
                    'completion_evidence' => null,
                    'ticket_origin' => [
                        'ticket_id' => $ticket->id,
                        'ticket_key' => $ticket->key,
                        'triage_attempt_id' => $lockedAttempt->id,
                    ],
                ],
                'status' => TaskStatus::Queued,
            ]);

            if ($dependencyIds !== []) {
                $task->dependencies()->attach($dependencyIds);
            }

            $ticket->forceFill([
                'category' => $category,
                'decision' => TicketDecision::Approved,
                'ai_suggested_priority' => $priority,
                'triage_confidence' => (float) $decision['confidence'],
                'triaged_at' => now(),
                'converted_task_id' => $task->id,
            ])->save();

            $this->audit->record(
                'task.created',
                [
                    'phase_id' => $targetPhase->id,
                    'key' => $task->key,
                    'position' => $task->position,
                    'source' => 'ticket',
                    'ticket_id' => $ticket->id,
                    'ticket_key' => $ticket->key,
                    'triage_attempt_id' => $lockedAttempt->id,
                ],
                $project,
                $task,
            );

            $this->workflow->transition(
                $ticket,
                TicketStatus::Converted,
            );

            $this->messages->handle(
                $ticket,
                TicketMessageAuthorType::System,
                TicketMessageType::SystemEvent,
                "Converted to {$task->key}: {$task->title}.",
            );

            $this->audit->record(
                'ticket.converted_to_task',
                [
                    'ticket_id' => $ticket->id,
                    'ticket_key' => $ticket->key,
                    'task_id' => $task->id,
                    'task_key' => $task->key,
                    'phase_id' => $targetPhase->id,
                    'triage_attempt_id' => $lockedAttempt->id,
                ],
                $project,
                $task,
            );

            return $task;
        }, attempts: 3);

        if ($task !== null) {
            $this->notes->writeTaskBrief($task);
        }

        return $task;
    }

    private function existingConvertedTask(
        Project $project,
        Ticket $ticket,
    ): Task {
        if ($ticket->getRawOriginal('status') !== TicketStatus::Converted->value) {
            throw new LogicException(
                'Ticket has a Task link but is not in the converted state.',
            );
        }

        $task = Task::query()->find($ticket->converted_task_id);

        if ($task === null || $task->project_id !== $project->id) {
            throw new LogicException(
                'Ticket converted Task link is missing or crosses project boundaries.',
            );
        }

        return $task;
    }

    /** @param  array<string, mixed>  $decision */
    private function decisionShapeSupportsRevalidation(array $decision): bool
    {
        $confidence = $decision['confidence'] ?? null;
        $complexity = $decision['complexity'] ?? null;
        $proposal = $decision['proposed_task'] ?? null;
        $escalationFlags = $decision['escalation_flags'] ?? null;
        $category = $decision['category'] ?? null;
        $priority = $decision['suggested_priority'] ?? null;
        $decisionValue = $decision['decision'] ?? null;

        return (is_float($confidence) || is_int($confidence))
            && is_string($complexity)
            && in_array($complexity, ['low', 'medium', 'high'], true)
            && is_array($proposal)
            && ! array_is_list($proposal)
            && is_array($escalationFlags)
            && is_string($category)
            && TicketCategory::tryFrom($category) !== null
            && is_string($priority)
            && TicketPriority::tryFrom($priority) !== null
            && is_string($decisionValue)
            && TicketDecision::tryFrom($decisionValue) !== null
            && is_bool($decision['implementation_required'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $validation
     */
    private function decisionContractAllowsConversion(
        array $decision,
        array $validation,
    ): bool {
        $confidence = $decision['confidence'] ?? null;
        $proposal = $decision['proposed_task'] ?? null;
        $escalationFlags = $decision['escalation_flags'] ?? null;
        $escalationReasons = $validation['escalation_reasons'] ?? null;
        $category = $decision['category'] ?? null;
        $priority = $decision['suggested_priority'] ?? null;

        return ($decision['decision'] ?? null) === TicketDecision::Approved->value
            && ($decision['implementation_required'] ?? false) === true
            && (is_float($confidence) || is_int($confidence))
            && (float) $confidence >= TicketTriagePolicy::ConfidenceThreshold
            && in_array($decision['complexity'] ?? null, ['low', 'medium'], true)
            && is_string($category)
            && TicketCategory::tryFrom($category) !== null
            && is_string($priority)
            && TicketPriority::tryFrom($priority) !== null
            && is_array($proposal)
            && ! array_is_list($proposal)
            && is_array($escalationFlags)
            && $escalationFlags === []
            && ($validation['requires_operator_decision'] ?? true) === false
            && is_array($escalationReasons)
            && $escalationReasons === [];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array{
     *     title: string,
     *     objective: string,
     *     acceptance_criteria: list<string>,
     *     scope: list<string>,
     *     constraints: list<string>,
     *     relevant_paths: list<string>,
     *     verification_commands: list<string>,
     *     implementation_prompt: string,
     *     depends_on_task_ids: list<int>,
     *     preferred_phase_id: ?int
     * }
     */
    private function validatedProposal(array $proposal): array
    {
        /** @var array{title: string, objective: string, acceptance_criteria: list<string>, scope?: list<string>|null, constraints?: list<string>|null, relevant_paths?: list<string>|null, verification_commands?: list<string>|null, implementation_prompt: string, depends_on_task_ids: list<int>, preferred_phase_id?: int|null} $validated */
        $validated = Validator::make($proposal, [
            'title' => ['required', 'string', 'max:255'],
            'objective' => ['required', 'string', 'max:4000'],
            'acceptance_criteria' => ['required', 'array', 'min:1', 'max:30'],
            'acceptance_criteria.*' => ['required', 'string', 'max:2000'],
            'scope' => ['nullable', 'array', 'max:50'],
            'scope.*' => ['string', 'max:1000'],
            'constraints' => ['nullable', 'array', 'max:50'],
            'constraints.*' => ['string', 'max:2000'],
            'relevant_paths' => ['nullable', 'array', 'max:50'],
            'relevant_paths.*' => ['string', 'max:1000'],
            'verification_commands' => ['nullable', 'array', 'max:30'],
            'verification_commands.*' => ['string', 'max:1000'],
            'implementation_prompt' => ['required', 'string', 'max:16000'],
            'depends_on_task_ids' => ['required', 'array', 'max:50'],
            'depends_on_task_ids.*' => ['integer', 'distinct'],
            'preferred_phase_id' => ['nullable', 'integer'],
        ])->validate();

        $commands = $validated['verification_commands'] ?? [];

        if (! $this->taskValidator->verificationCommandsAreSafe($commands)) {
            throw ValidationException::withMessages([
                'verification_commands' => 'The proposed Task contains an unsafe verification command.',
            ]);
        }

        return [
            'title' => $validated['title'],
            'objective' => $validated['objective'],
            'acceptance_criteria' => $validated['acceptance_criteria'],
            'scope' => $validated['scope'] ?? [],
            'constraints' => $validated['constraints'] ?? [],
            'relevant_paths' => $validated['relevant_paths'] ?? [],
            'verification_commands' => $commands,
            'implementation_prompt' => $validated['implementation_prompt'],
            'depends_on_task_ids' => $validated['depends_on_task_ids'],
            'preferred_phase_id' => $validated['preferred_phase_id'] ?? null,
        ];
    }

    /**
     * @param  EloquentCollection<int, Phase>  $phases
     * @param  EloquentCollection<int, Task>  $tasks
     */
    private function currentPhase(
        EloquentCollection $phases,
        EloquentCollection $tasks,
    ): ?Phase {
        foreach ($phases as $phase) {
            $hasUnfinishedTask = $tasks->contains(
                fn (Task $task): bool => $task->phase_id === $phase->id
                    && $task->getRawOriginal('status') !== TaskStatus::Done->value,
            );

            if ($hasUnfinishedTask) {
                return $phase;
            }
        }

        return null;
    }

    /**
     * @param  EloquentCollection<int, Phase>  $phases
     */
    private function preferredPhase(
        EloquentCollection $phases,
        ?int $preferredPhaseId,
    ): ?Phase {
        if ($preferredPhaseId === null) {
            return null;
        }

        return $phases->first(
            fn (Phase $phase): bool => $phase->id === $preferredPhaseId,
        );
    }

    /**
     * @param  EloquentCollection<int, Phase>  $phases
     * @param  EloquentCollection<int, Task>  $tasks
     * @param  EloquentCollection<int, Task>  $dependencies
     */
    private function targetPhase(
        Project $project,
        EloquentCollection $phases,
        EloquentCollection $tasks,
        ?Phase $currentPhase,
        ?Phase $preferredPhase,
        EloquentCollection $dependencies,
    ): Phase {
        if (
            $currentPhase !== null
            && ! $this->phaseReviewStarted($currentPhase, $tasks)
            && $this->requestClearlyBelongsToCurrentPhase(
                $currentPhase,
                $preferredPhase,
            )
            && $this->dependenciesFitTargetPhase(
                $dependencies,
                $phases,
                $currentPhase,
            )
        ) {
            return $currentPhase;
        }

        $lastPhase = $phases->last();

        if (
            $currentPhase !== null
            && $lastPhase instanceof Phase
            && $lastPhase->id !== $currentPhase->id
            && (int) $lastPhase->position > (int) $currentPhase->position
            && $this->isManagedFutureIntakePhase($lastPhase)
        ) {
            return $lastPhase;
        }

        $nextPosition = ((int) ($phases->max('position') ?? 0)) + 1;
        $phase = Phase::create([
            'project_id' => $project->id,
            'position' => $nextPosition,
            'title' => self::FutureIntakeTitle,
            'objective' => self::FutureIntakeObjective,
            'system_key' => self::FutureIntakeSystemKeyPrefix.$nextPosition,
        ]);

        $this->audit->record(
            'phase.created',
            [
                'phase_id' => $phase->id,
                'position' => $phase->position,
                'source' => 'ticket_future_intake',
                'system_key' => $phase->system_key,
            ],
            $project,
        );

        return $phase;
    }

    private function requestClearlyBelongsToCurrentPhase(
        Phase $currentPhase,
        ?Phase $preferredPhase,
    ): bool {
        return $preferredPhase?->id === $currentPhase->id
            || ($preferredPhase === null
                && $this->isManagedFutureIntakePhase($currentPhase));
    }

    /**
     * @param  EloquentCollection<int, Task>  $tasks
     */
    private function phaseReviewStarted(
        Phase $phase,
        EloquentCollection $tasks,
    ): bool {
        $phaseTasks = $tasks->where('phase_id', $phase->id)->values();

        if (
            $phaseTasks->contains(
                fn (Task $task): bool => $task->getRawOriginal('status') === TaskStatus::Reviewing->value,
            )
        ) {
            return true;
        }

        $taskIds = $phaseTasks->pluck('id')->all();

        return $taskIds !== []
            && Review::query()->whereIn('task_id', $taskIds)->exists();
    }

    /**
     * @param  EloquentCollection<int, Task>  $dependencies
     * @param  EloquentCollection<int, Phase>  $phases
     */
    private function dependenciesFitTargetPhase(
        EloquentCollection $dependencies,
        EloquentCollection $phases,
        Phase $targetPhase,
    ): bool {
        foreach ($dependencies as $dependency) {
            $dependencyPhase = $phases->first(
                fn (Phase $phase): bool => $phase->id === $dependency->phase_id,
            );

            if ($dependencyPhase === null) {
                return false;
            }

            if ((int) $dependencyPhase->position > (int) $targetPhase->position) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  EloquentCollection<int, Task>  $tasks
     * @return array{0: int, 1: string}
     */
    private function nextTaskIdentity(EloquentCollection $tasks): array
    {
        $position = ((int) ($tasks->max('position') ?? 0)) + 1;
        $keys = $tasks->pluck('key')->all();

        do {
            $key = 'TASK-'.str_pad(
                (string) $position,
                3,
                '0',
                STR_PAD_LEFT,
            );

            if (! in_array($key, $keys, true)) {
                return [$position, $key];
            }

            $position++;
        } while (true);
    }

    private function isManagedFutureIntakePhase(Phase $phase): bool
    {
        $systemKey = $phase->getAttribute('system_key');

        return is_string($systemKey)
            && str_starts_with(
                $systemKey,
                self::FutureIntakeSystemKeyPrefix,
            );
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $validation
     */
    private function escalateLocked(
        Ticket $ticket,
        TicketTriageAttempt $attempt,
        array $decision,
        array $validation,
    ): void {
        $previousValidation = is_array($decision['aios_validation'] ?? null)
            ? $decision['aios_validation']
            : [];
        $decision['aios_validation'] = $validation;

        $attempt->forceFill([
            'structured_decision' => $decision,
        ])->save();

        $this->workflow->transition(
            $ticket,
            TicketStatus::Escalated,
        );

        $this->audit->record(
            'ticket.escalated',
            [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->key,
                'triage_attempt_id' => $attempt->id,
                'reason' => 'ticket_task_conversion_revalidation',
                'escalation_reasons' => $validation['escalation_reasons'] ?? [],
                'previous_aios_validation' => $previousValidation,
            ],
            $ticket->project,
        );
    }

    /**
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    private function validationWithEscalationReason(
        array $validation,
        TicketEscalationReason $reason,
    ): array {
        $reasons = $validation['escalation_reasons'] ?? [];
        $reasons = is_array($reasons) ? $reasons : [];
        $reasons[] = $reason->value;

        $validation['requires_operator_decision'] = true;
        $validation['automatic_task_conversion_eligible'] = false;
        $validation['escalation_reasons'] = array_values(
            array_unique($reasons),
        );

        return $validation;
    }

    private function failConversionLocked(
        Ticket $ticket,
        TicketTriageAttempt $attempt,
        string $reason,
    ): void {
        $this->workflow->transition(
            $ticket,
            TicketStatus::Failed,
        );

        $this->audit->record(
            'ticket.task_conversion_failed',
            [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->key,
                'triage_attempt_id' => $attempt->id,
                'reason' => $reason,
            ],
            $ticket->project,
        );
    }
}
