<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Services\AuditLogger;
use App\Services\TicketWorkflow;
use App\TicketDecision;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AutoCloseInactiveTickets
{
    public function __construct(
        private TicketWorkflow $workflow,
        private RecordTicketMessage $messages,
        private AuditLogger $audit,
    ) {}

    public function handle(): int
    {
        $closed = 0;

        foreach (
            Ticket::query()
                ->select('id')
                ->where('status', TicketStatus::AwaitingRequester->value)
                ->whereIn('decision', [
                    TicketDecision::NeedsInformation->value,
                    TicketDecision::SelfService->value,
                ])
                ->whereNotNull('awaiting_response_until')
                ->where('awaiting_response_until', '<=', now())
                ->lazyById(100) as $candidate
        ) {
            if ($this->closeOne((int) $candidate->id)) {
                $closed++;
            }
        }

        return $closed;
    }

    private function closeOne(int $ticketId): bool
    {
        return DB::transaction(function () use ($ticketId): bool {
            $ticket = Ticket::query()
                ->lockForUpdate()
                ->find($ticketId);

            if ($ticket === null) {
                return false;
            }

            $status = TicketStatus::from(
                (string) $ticket->getRawOriginal('status'),
            );
            $decision = $ticket->getRawOriginal('decision');

            if (
                $status !== TicketStatus::AwaitingRequester
                || ! in_array($decision, [
                    TicketDecision::NeedsInformation->value,
                    TicketDecision::SelfService->value,
                ], true)
            ) {
                return false;
            }

            $deadline = $ticket->getAttribute('awaiting_response_until');

            if (
                ! $deadline instanceof CarbonImmutable
                || $deadline->isFuture()
            ) {
                return false;
            }

            $lateRequesterMessage = $ticket->messages()
                ->where('author_type', TicketMessageAuthorType::User->value)
                ->where('message_type', TicketMessageType::PublicReply->value)
                ->where('user_id', $ticket->submitted_by_user_id)
                ->where('created_at', '>', $deadline)
                ->oldest('id')
                ->first();

            $closedTicket = $this->workflow->transition(
                $ticket,
                TicketStatus::Closed,
            );

            $closedAt = $closedTicket->getAttribute('closed_at');
            $inactivityClosedAt = $closedAt instanceof CarbonImmutable
                ? $closedAt
                : CarbonImmutable::now();

            $closedTicket->forceFill([
                'inactivity_closed_at' => $inactivityClosedAt,
            ])->save();

            $closeMessage = $this->messages->handle(
                $closedTicket,
                TicketMessageAuthorType::System,
                TicketMessageType::SystemEvent,
                'Closed automatically after 72 hours without a requester response.',
            );

            $this->audit->record('ticket.auto_closed', [
                'ticket_id' => $closedTicket->id,
                'ticket_key' => $closedTicket->key,
                'decision' => $decision,
                'awaiting_response_until' => $deadline->toISOString(),
                'closed_at' => $inactivityClosedAt->toISOString(),
                'system_message_id' => $closeMessage->id,
            ], $closedTicket->project);

            if ($lateRequesterMessage === null) {
                return true;
            }

            $reopened = $this->workflow->transition(
                $closedTicket,
                TicketStatus::Open,
            );

            $reopenMessage = $this->messages->handle(
                $reopened,
                TicketMessageAuthorType::System,
                TicketMessageType::SystemEvent,
                'Ticket reopened automatically because a requester response arrived after the inactivity deadline.',
            );

            $this->audit->record('ticket.reopened', [
                'ticket_id' => $reopened->id,
                'ticket_key' => $reopened->key,
                'requester_message_id' => $lateRequesterMessage->id,
                'system_message_id' => $reopenMessage->id,
                'reason' => 'late_requester_response_after_inactivity_deadline',
                'inactivity_closed_at' => $inactivityClosedAt->toISOString(),
            ], $reopened->project);

            return true;
        }, attempts: 3);
    }
}
