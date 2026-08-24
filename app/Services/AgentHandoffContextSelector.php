<?php

namespace App\Services;

use App\AgentHandoffStatus;
use App\AgentHandoffType;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\RecoveryIncident;
use App\Models\Review;
use App\Models\TaskAttempt;
use App\RecoveryIncidentStatus;
use App\ReviewStatus;
use App\TaskStatus;
use Illuminate\Support\Collection;
use LogicException;

final class AgentHandoffContextSelector
{
    public const string ContextKey = 'agent_handoffs';

    private const int CandidateLimit = 25;

    /**
     * Inject the existing schema validator so every delivered payload is revalidated before use.
     */
    public function __construct(
        private AgentHandoffSchemaValidator $schemas,
    ) {}

    /**
     * Select deterministic handoff evidence for one managed execution or replay the exact evidence
     * approved for the same interrupted execution when a recovery source is supplied.
     *
     * @return array{
     *     entries: list<array<string, mixed>>,
     *     handoff_ids: list<int>,
     *     pending_handoff_ids: list<int>,
     *     content_hashes: array<int, string>,
     *     replay_source_agent_run_id: int|null
     * }
     */
    public function select(
        AgentRun $targetRun,
        ?AgentRun $recoverySource = null,
    ): array {
        $targetRun->loadMissing('task');

        if (! $this->targetRunAcceptsHandoffs($targetRun)) {
            return $this->emptySelection();
        }

        if ($recoverySource !== null) {
            return $this->replaySelection(
                $targetRun,
                $recoverySource,
            );
        }

        return $this->freshSelection($targetRun);
    }

    /**
     * Revalidate one pending handoff at the transactional consumption boundary.
     */
    public function isConsumableFor(
        AgentHandoff $handoff,
        AgentRun $targetRun,
        ?AgentRun $recoverySource = null,
    ): bool {
        $targetRun->loadMissing('task');
        $handoff->loadMissing('sourceRun');

        if (! $this->targetRunAcceptsHandoffs($targetRun)) {
            return false;
        }

        if ($recoverySource !== null) {
            return $this->pendingReplayIsValid(
                $handoff,
                $targetRun,
                $recoverySource,
            );
        }

        return $this->freshHandoffIsRelevant(
            $handoff,
            $targetRun,
        );
    }

    /**
     * Extract the ordered handoff IDs from a final assembled context for exact post-budget comparison.
     *
     * @return list<int>
     */
    public function idsFromContext(
        AssembledAgentContext $context,
    ): array {
        $entries = $context->taskContext[self::ContextKey] ?? null;

        if ($entries === null) {
            return [];
        }

        if (! is_array($entries)) {
            throw new LogicException(
                'Agent handoff context must be an ordered list.',
            );
        }

        $ids = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new LogicException(
                    'Agent handoff context contains a malformed entry.',
                );
            }

            $id = $entry['id'] ?? null;

            if (
                ! is_int($id)
                || $id < 1
                || in_array($id, $ids, true)
            ) {
                throw new LogicException(
                    'Agent handoff context contains an invalid or duplicate handoff id.',
                );
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Extract content hashes from the final context so budget reduction cannot alter required handoff evidence.
     *
     * @return array<int, string>
     */
    public function contentHashesFromContext(
        AssembledAgentContext $context,
    ): array {
        $entries = $context->taskContext[self::ContextKey] ?? null;

        if ($entries === null) {
            return [];
        }

        if (! is_array($entries)) {
            throw new LogicException(
                'Agent handoff context must be an ordered list.',
            );
        }

        $hashes = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new LogicException(
                    'Agent handoff context contains a malformed entry.',
                );
            }

            $id = $entry['id'] ?? null;
            $hash = $entry['content_hash'] ?? null;

            if (
                ! is_int($id)
                || $id < 1
                || ! is_string($hash)
                || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1
            ) {
                throw new LogicException(
                    'Agent handoff context contains invalid integrity evidence.',
                );
            }

            if (array_key_exists($id, $hashes)) {
                throw new LogicException(
                    'Agent handoff context contains a duplicate handoff id.',
                );
            }

            $hashes[$id] = $hash;
        }

        return $hashes;
    }

    /**
     * Select only currently pending handoffs whose durable workflow evidence targets this fresh execution.
     *
     * @return array<string, mixed>
     */
    private function freshSelection(AgentRun $targetRun): array
    {
        $types = match ($this->role($targetRun)) {
            AgentRole::Reviewer => [
                AgentHandoffType::ImplementationHandoff->value,
            ],
            AgentRole::Coder => [
                AgentHandoffType::ReviewFinding->value,
                AgentHandoffType::RecoveryAdvice->value,
            ],
            default => [],
        };

        if ($types === []) {
            return $this->emptySelection();
        }

        $candidates = AgentHandoff::query()
            ->where('project_id', $targetRun->project_id)
            ->where('task_id', $targetRun->task_id)
            ->where('to_role', $this->role($targetRun)->value)
            ->where('status', AgentHandoffStatus::Pending->value)
            ->whereNull('consumed_at')
            ->whereIn('handoff_type', $types)
            ->with('sourceRun')
            ->orderByDesc('id')
            ->limit(self::CandidateLimit + 1)
            ->get();

        if ($candidates->count() > self::CandidateLimit) {
            throw new LogicException(
                'Eligible Agent handoff candidate evidence exceeds the bounded selection limit.',
            );
        }

        $selectedByType = [];

        foreach ($candidates as $candidate) {
            if (! $this->freshHandoffIsRelevant($candidate, $targetRun)) {
                continue;
            }

            $type = $candidate->handoff_type->value;

            if (! array_key_exists($type, $selectedByType)) {
                $selectedByType[$type] = $candidate;
            }
        }

        /** @var Collection<int, AgentHandoff> $selected */
        $selected = collect(array_values($selectedByType))
            ->sortBy(fn (AgentHandoff $handoff): string => sprintf(
                '%03d:%020d',
                $this->typePriority($handoff->handoff_type),
                $handoff->id,
            ))
            ->values();

        return $this->selection($selected, null);
    }

    /**
     * Reconstruct exactly the evidence approved for the same interrupted execution.
     *
     * @return array<string, mixed>
     */
    private function replaySelection(
        AgentRun $targetRun,
        AgentRun $recoverySource,
    ): array {
        if (! $this->recoverySourceMatchesTarget($targetRun, $recoverySource)) {
            throw new LogicException(
                'Interrupted Agent handoff replay evidence does not match the current recovery execution.',
            );
        }

        $snapshot = $this->arrayAttribute(
            $recoverySource,
            'context_budget_snapshot',
        );

        if ($snapshot === null) {
            throw new LogicException(
                'Interrupted Agent handoff replay is missing Context Budget evidence.',
            );
        }

        $handoffIds = $this->normalizedIds(
            $snapshot['agent_handoff_ids'] ?? [],
        );

        $expectedHashes = $this->normalizedContentHashes(
            $snapshot['agent_handoff_content_hashes'] ?? [],
        );

        if ($handoffIds === []) {
            if ($expectedHashes !== []) {
                throw new LogicException(
                    'Interrupted Agent handoff replay contains orphaned content-hash evidence.',
                );
            }

            return [
                ...$this->emptySelection(),
                'replay_source_agent_run_id' => $recoverySource->id,
            ];
        }

        if (count($expectedHashes) !== count($handoffIds)) {
            throw new LogicException(
                'Interrupted Agent handoff replay IDs and content hashes do not match.',
            );
        }

        foreach ($handoffIds as $handoffId) {
            if (! array_key_exists($handoffId, $expectedHashes)) {
                throw new LogicException(
                    'Interrupted Agent handoff replay IDs and content hashes do not match.',
                );
            }
        }

        $handoffsById = AgentHandoff::query()
            ->whereIn('id', $handoffIds)
            ->with('sourceRun')
            ->get()
            ->keyBy('id');

        if ($handoffsById->count() !== count($handoffIds)) {
            throw new LogicException(
                'Interrupted Agent handoff replay cannot reconstruct all approved durable evidence.',
            );
        }

        /** @var Collection<int, AgentHandoff> $handoffs */
        $handoffs = collect();

        foreach ($handoffIds as $handoffId) {
            $handoff = $handoffsById->get($handoffId);

            if (! $handoff instanceof AgentHandoff) {
                throw new LogicException(
                    'Interrupted Agent handoff replay cannot reconstruct ordered durable evidence.',
                );
            }

            $handoffs->push($handoff);
        }

        foreach ($handoffs as $handoff) {
            if (! $this->replayEnvelopeIsValid($handoff, $targetRun)) {
                throw new LogicException(
                    "Interrupted Agent handoff [{$handoff->id}] no longer matches the recovered execution scope.",
                );
            }

            if (
                ($expectedHashes[$handoff->id] ?? null)
                !== $handoff->content_hash
            ) {
                throw new LogicException(
                    "Interrupted Agent handoff [{$handoff->id}] content hash no longer matches durable recovery evidence.",
                );
            }

            $this->schemas->validate(
                $handoff->handoff_type,
                $handoff->schema_version,
                $handoff->payload,
            );

            if ($handoff->status === AgentHandoffStatus::Consumed) {
                if (
                    ! $this->consumptionAuditExists(
                        $handoff,
                        $recoverySource,
                        $snapshot,
                    )
                ) {
                    throw new LogicException(
                        "Consumed Agent handoff [{$handoff->id}] lacks matching source-execution delivery evidence.",
                    );
                }

                continue;
            }

            if (
                $handoff->status !== AgentHandoffStatus::Pending
                || $handoff->consumed_at !== null
            ) {
                throw new LogicException(
                    "Interrupted Agent handoff [{$handoff->id}] has an invalid replay status.",
                );
            }
        }

        return $this->selection(
            $handoffs,
            $recoverySource->id,
        );
    }

    /**
     * Determine whether one pending handoff is current for the exact fresh workflow boundary.
     */
    private function freshHandoffIsRelevant(
        AgentHandoff $handoff,
        AgentRun $targetRun,
    ): bool {
        $handoff->loadMissing('sourceRun');

        if (! $this->pendingEnvelopeIsValid($handoff, $targetRun)) {
            return false;
        }

        $this->schemas->validate(
            $handoff->handoff_type,
            $handoff->schema_version,
            $handoff->payload,
        );

        return match ($handoff->handoff_type) {
            AgentHandoffType::ImplementationHandoff => $this->implementationHandoffIsRelevant($handoff, $targetRun),

            AgentHandoffType::ReviewFinding => $this->reviewFindingIsRelevant($handoff, $targetRun),

            AgentHandoffType::RecoveryAdvice => $this->recoveryAdviceIsRelevant($handoff, $targetRun),

            default => false,
        };
    }

    /**
     * Validate the common pending-state envelope for a fresh delivery.
     */
    private function pendingEnvelopeIsValid(
        AgentHandoff $handoff,
        AgentRun $targetRun,
    ): bool {
        return $handoff->status === AgentHandoffStatus::Pending
            && $handoff->consumed_at === null
            && $this->replayEnvelopeIsValid($handoff, $targetRun);
    }

    /**
     * Validate immutable project, Task, target-role, source-run, and supported-type scope.
     */
    private function replayEnvelopeIsValid(
        AgentHandoff $handoff,
        AgentRun $targetRun,
    ): bool {
        $sourceRun = $handoff->sourceRun;
        $targetRole = $this->role($targetRun);

        $supportedType = match ($targetRole) {
            AgentRole::Reviewer => $handoff->handoff_type
                    === AgentHandoffType::ImplementationHandoff,

            AgentRole::Coder => in_array(
                $handoff->handoff_type,
                [
                    AgentHandoffType::ReviewFinding,
                    AgentHandoffType::RecoveryAdvice,
                ],
                true,
            ),

            default => false,
        };

        return $supportedType
            && (int) $handoff->project_id === (int) $targetRun->project_id
            && (int) $handoff->task_id === (int) $targetRun->task_id
            && $handoff->to_role === $targetRole
            && $sourceRun !== null
            && (int) $sourceRun->project_id === (int) $targetRun->project_id
            && (int) $sourceRun->task_id === (int) $targetRun->task_id
            && $sourceRun->getRawOriginal('role')
                === $handoff->from_role->value
            && $sourceRun->getRawOriginal('status')
                === AgentRunStatus::Completed->value
            && $sourceRun->finished_at !== null
            && $sourceRun->id < $targetRun->id;
    }

    /**
     * Bind implementation evidence to the exact successfully validated Coder attempt now under review.
     */
    private function implementationHandoffIsRelevant(
        AgentHandoff $handoff,
        AgentRun $targetRun,
    ): bool {
        $sourceRun = $handoff->sourceRun;
        $task = $targetRun->task;

        if (
            $sourceRun === null
            || $task === null
            || $this->role($targetRun) !== AgentRole::Reviewer
            || $handoff->from_role !== AgentRole::Coder
            || $sourceRun->attempt_number === null
            || $targetRun->attempt_number === null
            || (int) $sourceRun->attempt_number
                !== (int) $targetRun->attempt_number
            || TaskStatus::from(
                (string) $task->getRawOriginal('status'),
            ) !== TaskStatus::Reviewing
        ) {
            return false;
        }

        $attempt = TaskAttempt::query()
            ->where('task_id', $task->id)
            ->where('number', $targetRun->attempt_number)
            ->first();

        $validationResults = $attempt?->validation_results;

        if (
            $attempt === null
            || $attempt->getRawOriginal('status') !== 'completed'
            || ! is_array($validationResults)
            || ($validationResults['passed'] ?? null) !== true
            || ($validationResults['checks']['task_commit'] ?? null) !== true
        ) {
            return false;
        }

        return $this->isLatestCompletedRoleRunForAttempt(
            $sourceRun,
            AgentRole::Coder,
            (int) $targetRun->attempt_number,
            $targetRun,
        );
    }

    /**
     * Bind review findings only to the immediately following corrective Coder attempt.
     */
    private function reviewFindingIsRelevant(
        AgentHandoff $handoff,
        AgentRun $targetRun,
    ): bool {
        $sourceRun = $handoff->sourceRun;
        $task = $targetRun->task;

        if (
            $sourceRun === null
            || $task === null
            || $this->role($targetRun) !== AgentRole::Coder
            || $handoff->from_role !== AgentRole::Reviewer
            || $sourceRun->attempt_number === null
            || $targetRun->attempt_number === null
            || (int) $targetRun->attempt_number < 2
            || TaskStatus::from(
                (string) $task->getRawOriginal('status'),
            ) !== TaskStatus::Coding
        ) {
            return false;
        }

        $rejectedAttemptNumber =
            (int) $targetRun->attempt_number - 1;

        if (
            (int) $sourceRun->attempt_number
            !== $rejectedAttemptNumber
        ) {
            return false;
        }

        $rejectedAttempt = TaskAttempt::query()
            ->where('task_id', $task->id)
            ->where('number', $rejectedAttemptNumber)
            ->first();

        if ($rejectedAttempt === null) {
            return false;
        }

        $review = Review::query()
            ->where('task_id', $task->id)
            ->where('task_attempt_id', $rejectedAttempt->id)
            ->where('status', ReviewStatus::ChangesRequired->value)
            ->whereNotNull('completed_at')
            ->first();

        if ($review === null) {
            return false;
        }

        return $this->isLatestCompletedRoleRunForAttempt(
            $sourceRun,
            AgentRole::Reviewer,
            $rejectedAttemptNumber,
            $targetRun,
        );
    }

    /**
     * Bind recovery advice only when accepted incident evidence proves this is the immediate next Coder attempt.
     */
    private function recoveryAdviceIsRelevant(
        AgentHandoff $handoff,
        AgentRun $targetRun,
    ): bool {
        $sourceRun = $handoff->sourceRun;
        $task = $targetRun->task;

        if (
            $sourceRun === null
            || $task === null
            || $this->role($targetRun) !== AgentRole::Coder
            || $handoff->from_role !== AgentRole::RecoveryEngineer
            || $sourceRun->recovery_incident_id === null
            || $targetRun->attempt_number === null
            || (int) $targetRun->attempt_number < 2
            || TaskStatus::from(
                (string) $task->getRawOriginal('status'),
            ) !== TaskStatus::Coding
        ) {
            return false;
        }

        $incident = RecoveryIncident::query()
            ->find($sourceRun->recovery_incident_id);

        if (
            $incident === null
            || (int) $incident->project_id
                !== (int) $targetRun->project_id
            || (int) $incident->task_id
                !== (int) $targetRun->task_id
            || $incident->status !== RecoveryIncidentStatus::Recovered
            || $incident->recoverable !== true
            || $incident->resolved_at === null
            || ! in_array(
                (string) $incident->resulting_task_transition,
                TaskStatus::coderClaimableValues(),
                true,
            )
        ) {
            return false;
        }

        $evidence = $incident->evidence;

        $latestAttempt = is_array($evidence)
            ? ($evidence['latest_attempt'] ?? null)
            : null;

        $latestRun = is_array($evidence)
            ? ($evidence['latest_run'] ?? null)
            : null;

        $previousAttemptNumber = is_array($latestAttempt)
            ? ($latestAttempt['number'] ?? null)
            : null;

        $latestRunId = is_array($latestRun)
            ? ($latestRun['id'] ?? null)
            : null;

        if (
            ! is_int($previousAttemptNumber)
            || $previousAttemptNumber < 1
            || ! is_int($latestRunId)
            || $latestRunId < 1
            || (int) $targetRun->attempt_number
                !== $previousAttemptNumber + 1
        ) {
            return false;
        }

        $incidentSourceRun = AgentRun::query()
            ->find($latestRunId);

        if (
            $incidentSourceRun === null
            || (int) $incidentSourceRun->project_id
                !== (int) $targetRun->project_id
            || (int) $incidentSourceRun->task_id
                !== (int) $targetRun->task_id
            || (int) $incidentSourceRun->attempt_number
                !== $previousAttemptNumber
            || $incidentSourceRun->id >= $sourceRun->id
        ) {
            return false;
        }

        $latestRecoveryRun = $incident->recoveryRuns()
            ->where('project_id', $targetRun->project_id)
            ->where('task_id', $targetRun->task_id)
            ->where('role', AgentRole::RecoveryEngineer->value)
            ->where('status', AgentRunStatus::Completed->value)
            ->whereNotNull('finished_at')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        return $latestRecoveryRun?->id === $sourceRun->id;
    }

    /**
     * Require the handoff source to be the latest completed run for that exact role and workflow attempt.
     */
    private function isLatestCompletedRoleRunForAttempt(
        AgentRun $sourceRun,
        AgentRole $role,
        int $attemptNumber,
        AgentRun $targetRun,
    ): bool {
        $latest = AgentRun::query()
            ->where('project_id', $targetRun->project_id)
            ->where('task_id', $targetRun->task_id)
            ->where('role', $role->value)
            ->where('attempt_number', $attemptNumber)
            ->where('status', AgentRunStatus::Completed->value)
            ->whereNotNull('finished_at')
            ->where('id', '<', $targetRun->id)
            ->orderByDesc('id')
            ->first();

        return $latest?->id === $sourceRun->id;
    }

    /**
     * Revalidate pending replay evidence against the interrupted source snapshot instead of a new-attempt rule.
     */
    private function pendingReplayIsValid(
        AgentHandoff $handoff,
        AgentRun $targetRun,
        AgentRun $recoverySource,
    ): bool {
        if (
            ! $this->recoverySourceMatchesTarget(
                $targetRun,
                $recoverySource,
            )
        ) {
            return false;
        }

        if (
            $handoff->status !== AgentHandoffStatus::Pending
            || $handoff->consumed_at !== null
            || ! $this->replayEnvelopeIsValid(
                $handoff,
                $targetRun,
            )
        ) {
            return false;
        }

        $snapshot = $this->arrayAttribute(
            $recoverySource,
            'context_budget_snapshot',
        );

        if ($snapshot === null) {
            return false;
        }

        $ids = $this->normalizedIds(
            $snapshot['agent_handoff_ids'] ?? [],
        );

        $hashes = $this->normalizedContentHashes(
            $snapshot['agent_handoff_content_hashes'] ?? [],
        );

        if (
            ! in_array($handoff->id, $ids, true)
            || ($hashes[$handoff->id] ?? null)
                !== $handoff->content_hash
        ) {
            return false;
        }

        $this->schemas->validate(
            $handoff->handoff_type,
            $handoff->schema_version,
            $handoff->payload,
        );

        return true;
    }

    /**
     * Verify the supplied interrupted AgentRun is the exact recovery source for this execution.
     */
    private function recoverySourceMatchesTarget(
        AgentRun $targetRun,
        AgentRun $recoverySource,
    ): bool {
        if (
            $targetRun->getRawOriginal('status')
                !== AgentRunStatus::Running->value
            || $recoverySource->getRawOriginal('status')
                !== AgentRunStatus::Interrupted->value
            || $targetRun->task_id === null
            || $targetRun->attempt_number === null
            || $recoverySource->task_id === null
            || $recoverySource->attempt_number === null
            || (int) $targetRun->project_id
                !== (int) $recoverySource->project_id
            || (int) $targetRun->task_id
                !== (int) $recoverySource->task_id
            || $targetRun->getRawOriginal('role')
                !== $recoverySource->getRawOriginal('role')
            || $recoverySource->id >= $targetRun->id
        ) {
            return false;
        }

        $targetConfiguration = $this->arrayAttribute(
            $targetRun,
            'configuration_snapshot',
        );

        $sourceConfiguration = $this->arrayAttribute(
            $recoverySource,
            'configuration_snapshot',
        );

        if (
            $targetConfiguration === null
            || $sourceConfiguration === null
            || $targetConfiguration !== $sourceConfiguration
        ) {
            return false;
        }

        if ($this->role($targetRun) === AgentRole::Reviewer) {
            return (int) $targetRun->attempt_number
                === (int) $recoverySource->attempt_number;
        }

        if ($this->role($targetRun) !== AgentRole::Coder) {
            return false;
        }

        $attempt = TaskAttempt::query()
            ->where('task_id', $targetRun->task_id)
            ->where('number', $targetRun->attempt_number)
            ->first();

        $validationResults = $attempt?->validation_results;

        $preflight = is_array($validationResults)
            ? ($validationResults['repository_preflight'] ?? null)
            : null;

        return is_array($preflight)
            && ($preflight['mode'] ?? null) === 'recovery'
            && ($preflight['recovery_attempt_number'] ?? null)
                === (int) $recoverySource->attempt_number;
    }

    /**
     * Prove a consumed replay artifact was delivered to the interrupted source execution.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function consumptionAuditExists(
        AgentHandoff $handoff,
        AgentRun $recoverySource,
        array $snapshot,
    ): bool {
        $contextHash = $snapshot['final_context_hash'] ?? null;

        if (! is_string($contextHash) || $contextHash === '') {
            return false;
        }

        return AuditEvent::query()
            ->where('project_id', $recoverySource->project_id)
            ->where('task_id', $recoverySource->task_id)
            ->where('event_type', 'agent_handoff.consumed')
            ->where('payload->agent_handoff_id', $handoff->id)
            ->where(
                'payload->target_agent_run_id',
                $recoverySource->id,
            )
            ->where('payload->context_hash', $contextHash)
            ->exists();
    }

    /**
     * Require a task-scoped running Coder or Reviewer AgentRun with an exact attempt identity.
     */
    private function targetRunAcceptsHandoffs(
        AgentRun $targetRun,
    ): bool {
        if (
            $targetRun->getRawOriginal('status')
                !== AgentRunStatus::Running->value
            || $targetRun->task_id === null
            || $targetRun->attempt_number === null
            || (int) $targetRun->attempt_number < 1
            || $targetRun->task === null
            || (int) $targetRun->task->project_id
                !== (int) $targetRun->project_id
        ) {
            return false;
        }

        return in_array(
            $this->role($targetRun),
            [
                AgentRole::Coder,
                AgentRole::Reviewer,
            ],
            true,
        );
    }

    /**
     * Convert selected durable handoffs to bounded provider evidence and persistence metadata.
     *
     * @param  Collection<int, AgentHandoff>  $handoffs
     * @return array<string, mixed>
     */
    private function selection(
        Collection $handoffs,
        ?int $replaySourceAgentRunId,
    ): array {
        $entries = [];
        $ids = [];
        $pendingIds = [];
        $hashes = [];

        foreach ($handoffs->values() as $handoff) {
            $entries[] = $this->contextEntry($handoff);
            $ids[] = $handoff->id;
            $hashes[$handoff->id] = $handoff->content_hash;

            if ($handoff->status === AgentHandoffStatus::Pending) {
                $pendingIds[] = $handoff->id;
            }
        }

        return [
            'entries' => $entries,
            'handoff_ids' => $ids,
            'pending_handoff_ids' => $pendingIds,
            'content_hashes' => $hashes,
            'replay_source_agent_run_id' => $replaySourceAgentRunId,
        ];
    }

    /**
     * Render one handoff with only the minimum validated durable evidence required by the provider.
     *
     * @return array<string, mixed>
     */
    private function contextEntry(
        AgentHandoff $handoff,
    ): array {
        $validatedPayload = $this->schemas->validate(
            $handoff->handoff_type,
            $handoff->schema_version,
            $handoff->payload,
        );

        return [
            'id' => $handoff->id,
            'handoff_type' => $handoff->handoff_type->value,
            'schema_version' => $handoff->schema_version,
            'from_role' => $handoff->from_role->value,
            'from_agent_run_id' => $handoff->from_agent_run_id,
            'source_attempt_number' => $handoff->sourceRun?->attempt_number,
            'content_hash' => $handoff->content_hash,
            'payload' => $validatedPayload,
        ];
    }

    /**
     * Return the fixed ordering rank for supported handoff types.
     */
    private function typePriority(
        AgentHandoffType $type,
    ): int {
        return match ($type) {
            AgentHandoffType::ImplementationHandoff => 10,
            AgentHandoffType::ReviewFinding => 10,
            AgentHandoffType::RecoveryAdvice => 20,
            default => 100,
        };
    }

    /**
     * Return the persisted role for one AgentRun.
     */
    private function role(AgentRun $run): AgentRole
    {
        return AgentRole::from(
            (string) $run->getRawOriginal('role'),
        );
    }

    /**
     * Decode one durable AgentRun JSON snapshot directly from persisted state.
     *
     * @return array<string, mixed>|null
     */
    private function arrayAttribute(
        AgentRun $run,
        string $attribute,
    ): ?array {
        $raw = $run->getRawOriginal($attribute);

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode(
            (string) $raw,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($decoded)) {
            throw new LogicException(
                "AgentRun {$attribute} must contain JSON evidence.",
            );
        }

        return $decoded;
    }

    /**
     * Normalize persisted IDs while preserving their original deterministic ordering.
     *
     * @return list<int>
     */
    private function normalizedIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            if (! is_int($id) || $id < 1) {
                throw new LogicException(
                    'Persisted Agent handoff ids contain an invalid value.',
                );
            }

            $ids[] = $id;
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new LogicException(
                'Persisted Agent handoff ids contain duplicates.',
            );
        }

        return array_values($ids);
    }

    /**
     * Normalize persisted handoff content hashes by durable handoff ID.
     *
     * @return array<int, string>
     */
    private function normalizedContentHashes(
        mixed $value,
    ): array {
        if (! is_array($value)) {
            return [];
        }

        $hashes = [];

        foreach ($value as $id => $hash) {
            $key = is_int($id) ? (string) $id : $id;

            if (
                ! is_string($key)
                || preg_match('/\A[1-9][0-9]*\z/', $key) !== 1
                || ! is_string($hash)
                || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1
            ) {
                throw new LogicException(
                    'Persisted Agent handoff content hashes contain invalid evidence.',
                );
            }

            $hashes[$id] = $hash;
        }

        return $hashes;
    }

    /**
     * Return the canonical empty selection.
     *
     * @return array<string, mixed>
     */
    private function emptySelection(): array
    {
        return [
            'entries' => [],
            'handoff_ids' => [],
            'pending_handoff_ids' => [],
            'content_hashes' => [],
            'replay_source_agent_run_id' => null,
        ];
    }
}
