<?php

namespace Database\Factories;

use App\AgentRole;
use App\Models\Task;
use App\Models\TaskOperatorMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskOperatorMessage>
 */
class TaskOperatorMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'recipient_role' => AgentRole::Coder,
            'body' => fake()->paragraph(),
        ];
    }
}
