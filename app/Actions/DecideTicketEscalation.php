<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Models\TicketEscalationDecision;
use App\Models\TicketTriageAttempt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TicketTriagePolicy;
use App\Services\TicketWorkflow;
use App\TicketEscalationReason;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketOperatorAction;
use App\TicketStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DecideTicketEscalation
{
    public function __construct(
        private TicketWorkflow $workflow,
        private TicketTriagePolicy $triagePolicy,
        private RecordTicketMessage $messages,
        private AuditLogger $audit,
    ) {}

    public function handle(
        Ticket $ticket,
        TicketTriageAttempt $triageAttempt,
        User $operator,
        TicketOperatorAction $action,
        ?string $direction = null,
    ): TicketEscalationDecision {
        $direction = $this->normalizeDirection($direction);
        $this->validateDirection($action, $direction);

        return DB::transaction(function () use (
            $ticket,
            $triageAttempt,
            $operator,
            $action,
            $direction,
        ): TicketEscalationDecision {
            $lockedTicket = Ticket::query()
                ->lockForUpdate()
                ->findOrFail($ticket->id);
            $lockedAttempt = TicketTriageAttempt::query()
                ->lockForUpdate()
                ->findOrFail($triageAttempt->id);

            if ($lockedAttempt->ticket_id !== $lockedTicket->id) {
                abort(404);
            }

            if ($lockedAttempt->status !== 'completed') {
                throw new HttpException(
                    409,
                    'Only a completed Ticket triage attempt may receive an operator decision.',
                );
            }

            $structuredDecision = $this->structuredDecision($lockedAttempt);
            $storedValidation = $this->storedValidation($structuredDecision);

            if (($storedValidation['requires_operator_decision'] ?? null) !== true) {
                throw new HttpException(
                    409,
                    'This Ticket triage attempt does not require an operator decision.',
                );
            }

            $existing = TicketEscalationDecision::query()
                ->where('ticket_triage_attempt_id', $lockedAttempt->id)
                ->first();

            if ($existing !== null) {
                if (
                    $existing->getRawOriginal('action') === $action->value
                    && $existing->direction === $direction
                ) {
                    return $existing;
                }

                throw new HttpException(
                    409,
                    'This Ticket triage escalation already has an operator decision.',
                );
            }

            if (
                TicketStatus::from(
                    (string) $lockedTicket->getRawOriginal('status'),
                ) !== TicketStatus::Escalated
            ) {
                throw new HttpException(
                    409,
                    'Only an escalated Ticket may receive an operator decision.',
                );
            }

            $freshValidation = $this->triagePolicy->evaluate(
                $lockedTicket,
                $structuredDecision,
            );
            $criticalRoadmapApprovalRequired = $this->criticalRoadmapApprovalRequired(
                $storedValidation,
            ) || $this->criticalRoadmapApprovalRequired(
                $freshValidation,
            );

            $this->validateApprovalAction(
                $action,
                $criticalRoadmapApprovalRequired,
            );

            $decision = TicketEscalationDecision::create([
                'ticket_id' => $lockedTicket->id,
                'ticket_triage_attempt_id' => $lockedAttempt->id,
                'decided_by_user_id' => $operator->id,
                'action' => $action,
                'direction' => $direction,
            ]);

            $this->recordOperatorContext(
                $lockedTicket,
                $lockedAttempt,
                $operator,
                $action,
                $direction,
            );

            $deferForConversion = $this->deferForConversion(
                $action,
                $structuredDecision,
            );
            $resultingStatus = $this->resultingStatus(
                $action,
                $deferForConversion,
            );

            if (! $deferForConversion) {
                $this->workflow->transition(
                    $lockedTicket,
                    $resultingStatus,
                );
            }

            $this->audit->record('ticket.operator_decision', [
                'ticket_id' => $lockedTicket->id,
                'ticket_key' => $lockedTicket->key,
                'ticket_triage_attempt_id' => $lockedAttempt->id,
                'ticket_escalation_decision_id' => $decision->id,
                'operator_user_id' => $operator->id,
                'action' => $action->value,
                'direction_provided' => $direction !== null,
                'escalation_reasons' => $storedValidation['escalation_reasons'] ?? [],
                'fresh_escalation_reasons' => $freshValidation['escalation_reasons'],
                'critical_roadmap_interruption_approved' => $action === TicketOperatorAction::ApproveCriticalRoadmapInterruption,
                'resulting_status' => $resultingStatus->value,
                'conversion_deferred' => $deferForConversion,
            ], $lockedTicket->project);

            return $decision;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function structuredDecision(TicketTriageAttempt $attempt): array
    {
        $structuredDecision = $attempt->getAttribute('structured_decision');

        if (! is_array($structuredDecision)) {
            throw new HttpException(
                409,
                'The Ticket triage attempt has no durable structured decision.',
            );
        }

        return $structuredDecision;
    }

    /**
     * @param  array<string, mixed>  $structuredDecision
     * @return array<string, mixed>
     */
    private function storedValidation(array $structuredDecision): array
    {
        $validation = $structuredDecision['aios_validation'] ?? null;

        if (! is_array($validation)) {
            throw new HttpException(
                409,
                'The Ticket triage attempt has no durable AIOS escalation evidence.',
            );
        }

        return $validation;
    }

    /** @param array<string, mixed> $validation */
    private function criticalRoadmapApprovalRequired(array $validation): bool
    {
        $reasons = $validation['escalation_reasons'] ?? [];

        if (! is_array($reasons)) {
            return false;
        }

        return in_array(
            TicketEscalationReason::RoadmapOrPhaseReorderingOrInterruptionRequested->value,
            $reasons,
            true,
        ) || in_array(
            TicketEscalationReason::CriticalOrEmergencyPreemptionRequested->value,
            $reasons,
            true,
        );
    }

    private function validateApprovalAction(
        TicketOperatorAction $action,
        bool $criticalRoadmapApprovalRequired,
    ): void {
        if (
            $criticalRoadmapApprovalRequired
            && $action === TicketOperatorAction::ApproveProposedHandling
        ) {
            throw new HttpException(
                409,
                'Critical roadmap interruption requires the dedicated explicit approval action.',
            );
        }

        if (
            ! $criticalRoadmapApprovalRequired
            && $action === TicketOperatorAction::ApproveCriticalRoadmapInterruption
        ) {
            throw new HttpException(
                409,
                'The dedicated critical-roadmap approval action is not applicable to the current durable roadmap state.',
            );
        }
    }

    private function recordOperatorContext(
        Ticket $ticket,
        TicketTriageAttempt $attempt,
        User $operator,
        TicketOperatorAction $action,
        ?string $direction,
    ): void {
        $body = "Operator decision for Ticket triage attempt #{$attempt->number}: {$action->value}.";

        if ($direction !== null) {
            $body .= "\n\nOperator direction:\n{$direction}";
        }

        $this->messages->handle(
            $ticket,
            TicketMessageAuthorType::User,
            TicketMessageType::InternalNote,
            $body,
            $operator,
        );

        if (
            $direction !== null
            && in_array($action, [
                TicketOperatorAction::RequestRequesterInformation,
                TicketOperatorAction::Reject,
            ], true)
        ) {
            $this->messages->handle(
                $ticket,
                TicketMessageAuthorType::User,
                TicketMessageType::PublicReply,
                $direction,
                $operator,
            );
        }
    }

    /**
     * Determine whether an operator approval already carries exactly one bounded
     * proposed Task, so AIOS should leave the Ticket escalated and let the durable
     * worker loop (ConvertTicketToTask) re-validate and convert it, instead of
     * discarding the reviewed proposal into a brand-new triage attempt.
     *
     * @param  array<string, mixed>  $structuredDecision
     */
    private function deferForConversion(
        TicketOperatorAction $action,
        array $structuredDecision,
    ): bool {
        if (
            ! in_array($action, [
                TicketOperatorAction::ApproveProposedHandling,
                TicketOperatorAction::ApproveCriticalRoadmapInterruption,
            ], true)
        ) {
            return false;
        }

        if (($structuredDecision['implementation_required'] ?? null) !== true) {
            return false;
        }

        if (is_array($structuredDecision['proposed_task'] ?? null)) {
            return true;
        }

        $proposedTasks = $structuredDecision['proposed_tasks'] ?? null;

        return is_array($proposedTasks) && count($proposedTasks) >= 2;
    }

    private function resultingStatus(
        TicketOperatorAction $action,
        bool $deferForConversion,
    ): TicketStatus {
        if ($deferForConversion) {
            return TicketStatus::Escalated;
        }

        return match ($action) {
            TicketOperatorAction::ApproveProposedHandling,
            TicketOperatorAction::ApproveCriticalRoadmapInterruption,
            TicketOperatorAction::ProvideDirection => TicketStatus::Open,
            TicketOperatorAction::RequestRequesterInformation => TicketStatus::AwaitingRequester,
            TicketOperatorAction::Reject => TicketStatus::Closed,
        };
    }

    private function normalizeDirection(?string $direction): ?string
    {
        if ($direction === null) {
            return null;
        }

        $direction = trim($direction);

        return $direction === '' ? null : $direction;
    }

    private function validateDirection(
        TicketOperatorAction $action,
        ?string $direction,
    ): void {
        if (
            in_array($action, [
                TicketOperatorAction::RequestRequesterInformation,
                TicketOperatorAction::ProvideDirection,
            ], true)
            && $direction === null
        ) {
            throw new LogicException(
                "Operator action {$action->value} requires bounded direction text.",
            );
        }

        if ($direction !== null && Str::length($direction) > 8000) {
            throw new LogicException(
                'Operator direction exceeds the 8000-character limit.',
            );
        }
    }
}
