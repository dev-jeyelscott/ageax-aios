<?php

namespace App\Actions;

use App\Models\AgentRun;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class RecordTicketMessage
{
    private const MAX_BODY_CHARACTERS = 100_000;

    public function __construct(private AuditLogger $audit) {}

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
        }, attempts: 3);
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
