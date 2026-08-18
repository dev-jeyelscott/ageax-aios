<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketMessage> */
class TicketMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'agent_run_id' => null,
            'author_type' => TicketMessageAuthorType::User,
            'message_type' => TicketMessageType::PublicReply,
            'body' => fake()->paragraph(),
            'ai_generated' => false,
        ];
    }
}
