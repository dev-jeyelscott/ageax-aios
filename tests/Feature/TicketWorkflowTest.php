<?php

use App\Actions\TransitionTicket;
use App\Exceptions\InvalidTicketTransition;
use App\Models\Ticket;
use App\TicketStatus;

dataset('valid ticket transitions', [
    'open to triaging' => [TicketStatus::Open, TicketStatus::Triaging],
    'open to closed' => [TicketStatus::Open, TicketStatus::Closed],

    'triaging to awaiting requester' => [TicketStatus::Triaging, TicketStatus::AwaitingRequester],
    'triaging to escalated' => [TicketStatus::Triaging, TicketStatus::Escalated],
    'triaging to converted' => [TicketStatus::Triaging, TicketStatus::Converted],
    'triaging to closed' => [TicketStatus::Triaging, TicketStatus::Closed],
    'triaging to failed' => [TicketStatus::Triaging, TicketStatus::Failed],

    'awaiting requester to open' => [TicketStatus::AwaitingRequester, TicketStatus::Open],
    'awaiting requester to closed' => [TicketStatus::AwaitingRequester, TicketStatus::Closed],

    'escalated to open' => [TicketStatus::Escalated, TicketStatus::Open],
    'escalated to awaiting requester' => [TicketStatus::Escalated, TicketStatus::AwaitingRequester],
    'escalated to converted' => [TicketStatus::Escalated, TicketStatus::Converted],
    'escalated to closed' => [TicketStatus::Escalated, TicketStatus::Closed],

    'closed to open' => [TicketStatus::Closed, TicketStatus::Open],

    'failed to open' => [TicketStatus::Failed, TicketStatus::Open],
    'failed to triaging' => [TicketStatus::Failed, TicketStatus::Triaging],
]);

test('ticket workflow allows only explicit state transitions', function (
    TicketStatus $from,
    TicketStatus $to,
) {
    $ticket = Ticket::factory()->create([
        'status' => $from,
        'closed_at' => $from === TicketStatus::Closed ? now() : null,
        'awaiting_response_until' => $from === TicketStatus::AwaitingRequester
            ? now()->addHours(72)
            : null,
    ]);

    $transitioned = app(TransitionTicket::class)->handle($ticket, $to);

    expect($transitioned->status)->toBe($to)
        ->and(
            $transitioned->project
                ->auditEvents()
                ->where('event_type', 'ticket.transitioned')
                ->exists(),
        )->toBeTrue();
})->with('valid ticket transitions');

test('ticket workflow rejects illegal state transitions without mutating durable state', function () {
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Open,
    ]);

    expect(
        fn () => app(TransitionTicket::class)
            ->handle($ticket, TicketStatus::Converted),
    )->toThrow(InvalidTicketTransition::class);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
});

test('completed triage records triaged timestamp', function () {
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Triaging,
    ]);

    $ticket = app(TransitionTicket::class)
        ->handle($ticket, TicketStatus::AwaitingRequester);

    expect($ticket->triaged_at)->not->toBeNull();
});

test('failed triage does not record a completed triage timestamp', function () {
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Triaging,
    ]);

    $ticket = app(TransitionTicket::class)
        ->handle($ticket, TicketStatus::Failed);

    expect($ticket->triaged_at)->toBeNull();
});

test('closing records closure time and reopening clears requester waiting state', function () {
    $deadline = now()->addHours(72);

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::AwaitingRequester,
        'awaiting_response_until' => $deadline,
    ]);

    $closed = app(TransitionTicket::class)
        ->handle($ticket, TicketStatus::Closed);

    expect($closed->closed_at)->not->toBeNull()
        ->and($closed->awaiting_response_until)->not->toBeNull();

    $reopened = app(TransitionTicket::class)
        ->handle($closed, TicketStatus::Open);

    expect($reopened->closed_at)->toBeNull()
        ->and($reopened->awaiting_response_until)->toBeNull();
});

test('converted tickets are terminal at the ticket state machine level', function () {
    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Converted,
    ]);

    expect(
        fn () => app(TransitionTicket::class)
            ->handle($ticket, TicketStatus::Closed),
    )->toThrow(InvalidTicketTransition::class);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Converted);
});
