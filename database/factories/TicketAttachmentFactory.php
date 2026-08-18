<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketAttachment> */
class TicketAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'ticket_message_id' => null,
            'uploaded_by_user_id' => User::factory(),
            'original_name' => 'evidence.txt',
            'storage_disk' => 'local',
            'storage_path' => 'ticket-attachments/'
                .fake()->randomNumber()
                .'/'
                .fake()->uuid()
                .'.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => 128,
            'content_hash' => hash(
                'sha256',
                fake()->sentence(),
            ),
        ];
    }
}
