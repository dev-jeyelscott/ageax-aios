<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskOperatorValidation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskOperatorValidation>
 */
class TaskOperatorValidationFactory extends Factory
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
            'build_sha' => fake()->sha1(),
            'build_completed_at' => now(),
            'results' => [],
            'notes' => fake()->sentence(),
        ];
    }
}
