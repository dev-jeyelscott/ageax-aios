<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Models\TicketEscalationDecision;
use App\Models\TicketTriageAttempt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TicketWorkflow;
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

            $validation = $this->validationEvidence($lockedAttempt);

            if (($validation['requires_operator_decision'] ?? null) !== true) {
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

            $resultingStatus = $this->resultingStatus($action);
            $this->workflow->transition(
                $lockedTicket,
                $resultingStatus,
            );

            $this->audit->record('ticket.operator_decision', [
                'ticket_id' => $lockedTicket->id,
                'ticket_key' => $lockedTicket->key,
                'ticket_triage_attempt_id' => $lockedAttempt->id,
                'ticket_escalation_decision_id' => $decision->id,
                'operator_user_id' => $operator->id,
                'action' => $action->value,
                'direction_provided' => $direction !== null,
                'escalation_reasons' => $validation['escalation_reasons'] ?? [],
                'resulting_status' => $resultingStatus->value,
            ], $lockedTicket->project);

            return $decision;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function validationEvidence(TicketTriageAttempt $attempt): array
    {
        $structuredDecision = $attempt->structured_decision;
        $validation = is_array($structuredDecision)
            ? ($structuredDecision['aios_validation'] ?? null)
            : null;

        if (! is_array($validation)) {
            throw new HttpException(
                409,
                'The Ticket triage attempt has no durable AIOS escalation evidence.',
            );
        }

        return $validation;
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

    private function resultingStatus(TicketOperatorAction $action): TicketStatus
    {
        return match ($action) {
            TicketOperatorAction::ApproveProposedHandling,
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
