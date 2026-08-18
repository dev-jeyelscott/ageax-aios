<?php

use App\Actions\RecordTicketMessage;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketConversation;
use App\TicketMessageAuthorType;
use App\TicketMessageType;

function createTicketConversationAgentRun(
    Ticket $ticket,
): AgentRun {
    return AgentRun::create([
        'project_id' => $ticket->project_id,
        'role' => AgentRole::ProjectManager,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash(
            'sha256',
            'ticket-conversation-'.$ticket->id,
        ),
        'started_at' => now(),
        'finished_at' => now(),
    ]);
}

test('ticket messages persist explicit public internal and system classifications', function () {
    $ticket = Ticket::factory()->create();
    $user = User::factory()->create();
    $messages = app(RecordTicketMessage::class);

    $public = $messages->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::PublicReply,
        'Requester-visible reply.',
        $user,
    );

    $internal = $messages->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::InternalNote,
        'Operator-only note.',
        $user,
    );

    $system = $messages->handle(
        $ticket,
        TicketMessageAuthorType::System,
        TicketMessageType::SystemEvent,
        'Ticket status changed.',
    );

    expect($public->message_type)
        ->toBe(TicketMessageType::PublicReply)
        ->and($internal->message_type)
        ->toBe(TicketMessageType::InternalNote)
        ->and($system->message_type)
        ->toBe(TicketMessageType::SystemEvent)
        ->and($public->author_type)
        ->toBe(TicketMessageAuthorType::User)
        ->and($system->author_type)
        ->toBe(TicketMessageAuthorType::System)
        ->and($public->ai_generated)
        ->toBeFalse()
        ->and($ticket->messages()->count())
        ->toBe(3)
        ->and(
            $ticket->project
                ->auditEvents()
                ->where(
                    'event_type',
                    'ticket.message_recorded',
                )
                ->count(),
        )
        ->toBe(3);
});

test('client safe conversation excludes internal notes and exposes durable ai attribution', function () {
    $ticket = Ticket::factory()->create();
    $user = User::factory()->create();
    $run = createTicketConversationAgentRun(
        $ticket,
    );
    $messages = app(RecordTicketMessage::class);

    $messages->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::PublicReply,
        'Visible requester message.',
        $user,
    );

    $messages->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::InternalNote,
        'Never expose this internal note.',
        $user,
    );

    $aiReply = $messages->handle(
        $ticket,
        TicketMessageAuthorType::Ai,
        TicketMessageType::PublicReply,
        'AI response for the requester.',
        agentRun: $run,
    );

    $messages->handle(
        $ticket,
        TicketMessageAuthorType::System,
        TicketMessageType::SystemEvent,
        'Visible lifecycle event.',
    );

    $payload = app(TicketConversation::class)
        ->clientSafePayload($ticket);

    $bodies = collect($payload)
        ->pluck('body');

    $aiPayload = collect($payload)
        ->firstWhere('id', $aiReply->id);

    expect($bodies)
        ->toContain('Visible requester message.')
        ->and($bodies)
        ->toContain('AI response for the requester.')
        ->and($bodies)
        ->toContain('Visible lifecycle event.')
        ->and($bodies)
        ->not->toContain(
            'Never expose this internal note.',
        )
        ->and(
            collect($payload)
                ->pluck('message_type')
                ->unique()
                ->all(),
        )
        ->not->toContain(
            TicketMessageType::InternalNote->value,
        )
        ->and($aiReply->ai_generated)
        ->toBeTrue()
        ->and($aiReply->agent_run_id)
        ->toBe($run->id)
        ->and($aiReply->agentRun?->id)
        ->toBe($run->id)
        ->and($aiPayload)
        ->toBeArray()
        ->and($aiPayload['ai_badge'])
        ->toBe('AI-generated response')
        ->and($aiPayload['agent_run_id'])
        ->toBe($run->id);

    $audit = $ticket->project
        ->auditEvents()
        ->where(
            'event_type',
            'ticket.reply_ai_generated',
        )
        ->firstOrFail();

    expect($audit->payload['ticket_id'])
        ->toBe($ticket->id)
        ->and($audit->payload['message_id'])
        ->toBe($aiReply->id)
        ->and($audit->payload['agent_run_id'])
        ->toBe($run->id)
        ->and(json_encode($audit->payload))
        ->not->toContain(
            'AI response for the requester.',
        );
});

test('ai attribution rejects an agent run from another project and audits the rejection', function () {
    $ticket = Ticket::factory()->create();
    $otherTicket = Ticket::factory()->create();

    $otherRun = createTicketConversationAgentRun(
        $otherTicket,
    );

    expect(
        fn () => app(
            RecordTicketMessage::class,
        )->handle(
            $ticket,
            TicketMessageAuthorType::Ai,
            TicketMessageType::PublicReply,
            'This must not persist.',
            agentRun: $otherRun,
        ),
    )->toThrow(
        LogicException::class,
        'agent_run_project_mismatch',
    );

    expect($ticket->messages()->count())
        ->toBe(0)
        ->and(
            $ticket->project
                ->auditEvents()
                ->where(
                    'event_type',
                    'ticket.message_rejected',
                )
                ->where(
                    'payload->reason',
                    'agent_run_project_mismatch',
                )
                ->exists(),
        )
        ->toBeTrue();
});

test('system message classification cannot be confused with a public or internal message', function () {
    $ticket = Ticket::factory()->create();

    expect(
        fn () => app(
            RecordTicketMessage::class,
        )->handle(
            $ticket,
            TicketMessageAuthorType::System,
            TicketMessageType::PublicReply,
            'Invalid system reply.',
        ),
    )->toThrow(
        LogicException::class,
        'invalid_system_message_type',
    );

    expect(
        fn () => TicketMessage::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'author_type' => TicketMessageAuthorType::System,
            'message_type' => TicketMessageType::InternalNote,
            'ai_generated' => false,
        ]),
    )->toThrow(
        LogicException::class,
        'System Ticket messages and system events must match exactly.',
    );
});

test('ticket message visibility and attribution evidence is append only', function () {
    $ticket = Ticket::factory()->create();
    $user = User::factory()->create();

    $message = app(
        RecordTicketMessage::class,
    )->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::InternalNote,
        'Internal evidence.',
        $user,
    );

    expect(
        fn () => $message->update([
            'message_type' => TicketMessageType::PublicReply,
        ]),
    )
        ->toThrow(
            LogicException::class,
            'Ticket messages are append-only.',
        )
        ->and(fn () => $message->delete())
        ->toThrow(
            LogicException::class,
            'Ticket messages are append-only.',
        );
});
