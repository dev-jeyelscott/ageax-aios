<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\ProjectStatus;
use App\TicketRequesterCategory;
use App\TicketStatus;
use App\TicketUrgency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->state(fn (): array => [
                'name' => fake()->company(),
                'path' => sys_get_temp_dir().'/ageax-ticket-project-'.fake()->uuid(),
                'status' => ProjectStatus::Paused,
                'git_status' => 'clean',
            ]),
            'submitted_by_user_id' => User::factory(),
            'key' => 'TICKET-'.str_pad(
                (string) fake()->unique()->numberBetween(1, 999999),
                6,
                '0',
                STR_PAD_LEFT,
            ),
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'requester_category' => TicketRequesterCategory::NotSure,
            'category' => null,
            'status' => TicketStatus::Open,
            'decision' => null,
            'requester_urgency' => TicketUrgency::Normal,
            'ai_suggested_priority' => null,
            'final_priority' => null,
            'triage_confidence' => null,
            'converted_task_id' => null,
            'awaiting_response_until' => null,
            'triaged_at' => null,
            'closed_at' => null,
        ];
    }
}
