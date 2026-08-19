<?php

namespace App\Services;

use App\Exceptions\InvalidTicketTransition;
use App\Models\Ticket;
use App\TicketStatus;
use Illuminate\Support\Facades\DB;

class TicketWorkflow
{
    public function __construct(private AuditLogger $audit) {}

    public function transition(Ticket $ticket, TicketStatus $to): Ticket
    {
        return DB::transaction(function () use ($ticket, $to): Ticket {
            $lockedTicket = Ticket::query()
                ->lockForUpdate()
                ->findOrFail($ticket->id);

            $this->transitionLocked($lockedTicket, $to);

            return $lockedTicket->refresh();
        }, attempts: 3);
    }

    private function transitionLocked(Ticket $ticket, TicketStatus $to): void
    {
        $from = TicketStatus::from($ticket->getRawOriginal('status'));

        if (! in_array($to, $this->allowedTransitions($from), true)) {
            throw new InvalidTicketTransition(
                "Cannot transition Ticket {$ticket->key} from {$from->value} to {$to->value}.",
            );
        }

        $attributes = [
            'status' => $to,
        ];

        if (
            $from === TicketStatus::Triaging
            && in_array($to, [
                TicketStatus::AwaitingRequester,
                TicketStatus::Escalated,
                TicketStatus::Converted,
                TicketStatus::Closed,
            ], true)
        ) {
            $attributes['triaged_at'] = now();
        }

        if ($to === TicketStatus::Closed) {
            $attributes['closed_at'] = now();
            $attributes['inactivity_closed_at'] = null;
        }

        if ($to === TicketStatus::Open && $from === TicketStatus::Closed) {
            $attributes['closed_at'] = null;
        }

        if (
            $to === TicketStatus::Open
            && in_array($from, [
                TicketStatus::Closed,
                TicketStatus::AwaitingRequester,
            ], true)
        ) {
            $attributes['awaiting_response_until'] = null;
            $attributes['inactivity_closed_at'] = null;
        }

        $ticket->forceFill($attributes)->save();

        $this->audit->record('ticket.transitioned', [
            'ticket_id' => $ticket->id,
            'ticket_key' => $ticket->key,
            'from' => $from->value,
            'to' => $to->value,
        ], $ticket->project);
    }

    /**
     * @return array<int, TicketStatus>
     */
    private function allowedTransitions(TicketStatus $from): array
    {
        return match ($from) {
            TicketStatus::Open => [
                TicketStatus::Triaging,
                TicketStatus::Closed,
            ],
            TicketStatus::Triaging => [
                TicketStatus::AwaitingRequester,
                TicketStatus::Escalated,
                TicketStatus::Converted,
                TicketStatus::Closed,
                TicketStatus::Failed,
            ],
            TicketStatus::AwaitingRequester => [
                TicketStatus::Open,
                TicketStatus::Closed,
            ],
            TicketStatus::Escalated => [
                TicketStatus::Open,
                TicketStatus::AwaitingRequester,
                TicketStatus::Converted,
                TicketStatus::Closed,
            ],
            TicketStatus::Converted => [],
            TicketStatus::Closed => [
                TicketStatus::Open,
            ],
            TicketStatus::Failed => [
                TicketStatus::Open,
                TicketStatus::Triaging,
            ],
        };
    }
}
