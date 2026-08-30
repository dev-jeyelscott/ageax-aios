<?php

namespace Database\Factories;

use App\Models\GoalRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalRun>
 */
class GoalRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['goal_text' => '/goal '.fake()->sentence(), 'contract' => [], 'pm_output' => [], 'approval_mode' => 'required', 'status' => 'awaiting_approval', 'version' => 1];
    }
}
