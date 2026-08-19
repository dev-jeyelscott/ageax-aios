<?php

namespace App\Actions;

use App\Models\AgentRun;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TicketWorkflow;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class RecordTicketMessage
{
    private const MAX_BODY_CHARACTERS = 100_000;

    public function __construct(
        private AuditLogger $audit,
        private TicketWorkflow $workflow,
    ) {}

    public function handle(
        Ticket $ticket,
        TicketMessageAuthorType $authorType,
        TicketMessageType $messageType,
        string $body,
        ?User $user = null,
        ?AgentRun $agentRun = null,
    ): TicketMessage {
        $body = trim($body);

        $this->validateMessage(
            $ticket,
            $authorType,
            $messageType,
            $body,
            $user,
            $agentRun,
        );

        return DB::transaction(function () use (
            $ticket,
            $authorType,
            $messageType,
            $body,
            $user,
            $agentRun,
        ): TicketMessage {
            $lockedTicket = Ticket::query()
                ->lockForUpdate()
                ->findOrFail($ticket->id);

            $message = $this->recordMessage(
                $lockedTicket,
                $authorType,
                $messageType,
                $body,
                $user,
                $agentRun,
            );

            $this->continueRequesterConversation(
                $lockedTicket,
                $message,
                $authorType,
                $messageType,
                $user,
            );

            return $message;
        }, attempts: 3);
    }

    private function recordMessage(
        Ticket $ticket,
        TicketMessageAuthorType $authorType,
        TicketMessageType $messageType,
        string $body,
        ?User $user = null,
        ?AgentRun $agentRun = null,
    ): TicketMessage {
        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user?->id,
            'agent_run_id' => $agentRun?->id,
            'author_type' => $authorType,
            'message_type' => $messageType,
            'body' => $body,
            'ai_generated' => $authorType === TicketMessageAuthorType::Ai,
        ]);

        $payload = [
            'ticket_id' => $ticket->id,
            'message_id' => $message->id,
            'message_type' => $messageType->value,
            'author_type' => $authorType->value,
            'ai_generated' => $message->ai_generated,
            'agent_run_id' => $agentRun?->id,
        ];

        $this->audit->record(
            'ticket.message_recorded',
            $payload,
            $ticket->project,
        );

        if (
            $authorType === TicketMessageAuthorType::Ai
            && $messageType === TicketMessageType::PublicReply
        ) {
            $this->audit->record(
                'ticket.reply_ai_generated',
                $payload,
                $ticket->project,
            );
        }

        return $message;
    }

    private function continueRequesterConversation(
        Ticket $ticket,
        TicketMessage $message,
        TicketMessageAuthorType $authorType,
        TicketMessageType $messageType,
        ?User $user,
    ): void {
        if (
            $authorType !== TicketMessageAuthorType::User
            || $messageType !== TicketMessageType::PublicReply
            || $user?->id !== $ticket->submitted_by_user_id
        ) {
            return;
        }

        $status = TicketStatus::from(
            (string) $ticket->getRawOriginal('status'),
        );

        if ($status === TicketStatus::AwaitingRequester) {
            $deadline = $ticket->awaiting_response_until;

            if ($deadline === null || $deadline->lessThanOrEqualTo(now())) {
                return;
            }

            $reopened = $this->workflow->transition(
                $ticket,
                TicketStatus::Open,
            );

            $this->audit->record('ticket.requester_response_received', [
                'ticket_id' => $reopened->id,
                'ticket_key' => $reopened->key,
                'requester_message_id' => $message->id,
                'previous_status' => TicketStatus::AwaitingRequester->value,
                'response_deadline' => $deadline->toISOString(),
            ], $reopened->project);

            return;
        }

        if (
            $status !== TicketStatus::Closed
            || $ticket->inactivity_closed_at === null
        ) {
            return;
        }

        $inactivityClosedAt = $ticket->inactivity_closed_at;
        $reopened = $this->workflow->transition(
            $ticket,
            TicketStatus::Open,
        );
        $systemMessage = $this->recordMessage(
            $reopened,
            TicketMessageAuthorType::System,
            TicketMessageType::SystemEvent,
            'Ticket reopened automatically after a requester response to an inactivity-closed Ticket.',
        );

        $this->audit->record('ticket.reopened', [
            'ticket_id' => $reopened->id,
            'ticket_key' => $reopened->key,
            'requester_message_id' => $message->id,
            'system_message_id' => $systemMessage->id,
            'reason' => 'requester_response_after_inactivity_close',
            'inactivity_closed_at' => $inactivityClosedAt->toISOString(),
        ], $reopened->project);
    }

    private function validateMessage(
        Ticket $ticket,
        TicketMessageAuthorType $authorType,
        TicketMessageType $messageType,
        string $body,
        ?User $user,
        ?AgentRun $agentRun,
    ): void {
        if ($body === '') {
            $this->reject($ticket, 'empty_body');
        }

        if (Str::length($body) > self::MAX_BODY_CHARACTERS) {
            $this->reject($ticket, 'body_too_large');
        }

        if (($authorType === TicketMessageAuthorType::User) !== ($user !== null)) {
            $this->reject($ticket, 'invalid_user_attribution');
        }

        if (
            $authorType !== TicketMessageAuthorType::Ai
            && $agentRun !== null
        ) {
            $this->reject($ticket, 'invalid_agent_run_attribution');
        }

        if (
            ($authorType === TicketMessageAuthorType::System)
            !== ($messageType === TicketMessageType::SystemEvent)
        ) {
            $this->reject($ticket, 'invalid_system_message_type');
        }

        if (
            $agentRun !== null
            && $agentRun->project_id !== $ticket->project_id
        ) {
            $this->reject($ticket, 'agent_run_project_mismatch');
        }
    }

    private function reject(Ticket $ticket, string $reason): never
    {
        $this->audit->record('ticket.message_rejected', [
            'ticket_id' => $ticket->id,
            'reason' => $reason,
        ], $ticket->project);

        throw new LogicException("Ticket message rejected: {$reason}.");
    }
}
