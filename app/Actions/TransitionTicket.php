<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Services\TicketWorkflow;
use App\TicketStatus;

class TransitionTicket
{
    public function __construct(private TicketWorkflow $workflow) {}

    public function handle(Ticket $ticket, TicketStatus $status): Ticket
    {
        return $this->workflow->transition($ticket, $status);
    }
}
