<?php

namespace App\Services;

use App\Models\Phase;
use App\Models\Task;
use App\Models\Ticket;
use App\TaskStatus;
use App\TicketDecision;
use App\TicketEscalationReason;
use App\TicketPriority;

class TicketTriagePolicy
{
    public const int SchemaVersion = 1;

    public const float ConfidenceThreshold = 0.80;

    /**
     * Evaluate a structured Ticket triage decision against deterministic policy.
     *
     * @param  array<string, mixed>  $decision
     * @return array{
     *     schema_version: int,
     *     confidence_threshold: float,
     *     requires_operator_decision: bool,
     *     automatic_task_conversion_eligible: bool,
     *     escalation_reasons: list<string>
     * }
     */
    public function evaluate(Ticket $ticket, array $decision): array
    {
        $reasons = $this->reportedReasons($decision);
        $confidence = (float) $decision['confidence'];

        if ($confidence < self::ConfidenceThreshold) {
            $reasons[] = TicketEscalationReason::LowConfidence->value;
        }

        if ($decision['complexity'] === 'high') {
            $reasons[] = TicketEscalationReason::HighComplexity->value;
        }

        if ($this->criticalPriorityWouldPreemptExistingWork($ticket, $decision)) {
            $reasons[] = TicketEscalationReason::CriticalOrEmergencyPreemptionRequested->value;
        }

        $proposal = is_array($decision['proposed_task'] ?? null)
            ? $decision['proposed_task']
            : null;

        $proposalSet = is_array($decision['proposed_tasks'] ?? null)
            ? array_values(array_filter(
                $decision['proposed_tasks'],
                is_array(...),
            ))
            : [];

        $allProposals = $proposal !== null ? [$proposal] : $proposalSet;

        foreach ($allProposals as $candidateProposal) {
            if ($this->preferredPhaseWouldReorderRoadmap($ticket, $candidateProposal)) {
                $reasons[] = TicketEscalationReason::RoadmapOrPhaseReorderingOrInterruptionRequested->value;
            }

            if ($this->dependencyPlacementIsUnsafe($ticket, $candidateProposal)) {
                $reasons[] = TicketEscalationReason::UnsafeOrUnresolvedDependencyPlacement->value;
            }
        }

        // AIOS independently derives multi-Task scope from the actual proposal shape rather
        // than trusting the Project Manager's self-reported escalation flag, matching how
        // low_confidence and high_complexity are derived above.
        if (count($proposalSet) > 1) {
            $reasons[] = TicketEscalationReason::MultipleTasksOrPhasesRequired->value;
        }

        $reasons = $this->normalizeReasons($reasons);
        $requiresOperator = $reasons !== [];

        $automaticTaskConversionEligible = ! $requiresOperator
            && $decision['decision'] === TicketDecision::Approved->value
            && $decision['implementation_required'] === true
            && $proposal !== null
            && $confidence >= self::ConfidenceThreshold
            && $decision['complexity'] !== 'high';

        return [
            'schema_version' => self::SchemaVersion,
            'confidence_threshold' => self::ConfidenceThreshold,
            'requires_operator_decision' => $requiresOperator,
            'automatic_task_conversion_eligible' => $automaticTaskConversionEligible,
            'escalation_reasons' => $reasons,
        ];
    }

    /**
     * Return valid escalation reasons reported by structured triage output.
     *
     * @param  array<string, mixed>  $decision
     * @return list<string>
     */
    private function reportedReasons(array $decision): array
    {
        $flags = $decision['escalation_flags'] ?? [];

        if (! is_array($flags)) {
            return [];
        }

        $reasons = [];

        foreach ($flags as $flag) {
            if (! is_string($flag)) {
                continue;
            }

            $reason = TicketEscalationReason::tryFrom($flag);

            if ($reason !== null) {
                $reasons[] = $reason->value;
            }
        }

        return $reasons;
    }

    /**
     * Determine whether critical Ticket work would preempt existing non-cleared work.
     *
     * @param  array<string, mixed>  $decision
     */
    private function criticalPriorityWouldPreemptExistingWork(
        Ticket $ticket,
        array $decision,
    ): bool {
        if ($decision['implementation_required'] !== true) {
            return false;
        }

        $priority = TicketPriority::from((string) $decision['suggested_priority']);

        if (! in_array($priority, [TicketPriority::Critical, TicketPriority::Emergency], true)) {
            return false;
        }

        return Task::query()
            ->where('project_id', $ticket->project_id)
            ->notCleared()
            ->whereNotIn('status', [
                TaskStatus::Done->value,
                TaskStatus::Cancelled->value,
            ])
            ->exists();
    }

    /**
     * Determine whether a preferred Phase would reopen earlier completed roadmap work.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function preferredPhaseWouldReorderRoadmap(
        Ticket $ticket,
        array $proposal,
    ): bool {
        $preferredPhaseId = $proposal['preferred_phase_id'] ?? null;

        if (! is_int($preferredPhaseId)) {
            return false;
        }

        $preferred = Phase::query()
            ->whereKey($preferredPhaseId)
            ->where('project_id', $ticket->project_id)
            ->first();

        if ($preferred === null) {
            return true;
        }

        $current = Phase::query()
            ->where('project_id', $ticket->project_id)
            ->whereHas(
                'tasks',
                fn ($query) => $query
                    ->where('is_cleared', false)
                    ->whereNotIn('status', [
                        TaskStatus::Done->value,
                        TaskStatus::Cancelled->value,
                    ]),
            )
            ->orderBy('position')
            ->first();

        return $current !== null
            && $preferred->position < $current->position;
    }

    /**
     * Determine whether proposed dependency placement would violate workflow safety.
     *
     * @param  array<string, mixed>  $proposal
     */
    private function dependencyPlacementIsUnsafe(
        Ticket $ticket,
        array $proposal,
    ): bool {
        $dependencyIds = $proposal['depends_on_task_ids'] ?? [];

        if (! is_array($dependencyIds) || $dependencyIds === []) {
            return false;
        }

        $dependencies = Task::query()
            ->with('phase')
            ->where('project_id', $ticket->project_id)
            ->whereIn('id', $dependencyIds)
            ->get();

        if ($dependencies->count() !== count(array_unique($dependencyIds))) {
            return true;
        }

        $preferredPhaseId = $proposal['preferred_phase_id'] ?? null;

        $preferred = is_int($preferredPhaseId)
            ? Phase::query()
                ->whereKey($preferredPhaseId)
                ->where('project_id', $ticket->project_id)
                ->first()
            : null;

        foreach ($dependencies as $dependency) {
            $status = TaskStatus::from((string) $dependency->getRawOriginal('status'));

            if ($dependency->is_cleared || $status === TaskStatus::Cancelled) {
                return true;
            }

            if (
                $preferred !== null
                && $dependency->phase !== null
                && $dependency->phase->position > $preferred->position
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deduplicate and deterministically order escalation reasons.
     *
     * @param  list<string>  $reasons
     * @return list<string>
     */
    private function normalizeReasons(array $reasons): array
    {
        $order = [];

        foreach (TicketEscalationReason::cases() as $index => $reason) {
            $order[$reason->value] = $index;
        }

        $reasons = array_values(array_unique($reasons));

        usort(
            $reasons,
            static fn (string $left, string $right): int => $order[$left] <=> $order[$right],
        );

        return $reasons;
    }
}
