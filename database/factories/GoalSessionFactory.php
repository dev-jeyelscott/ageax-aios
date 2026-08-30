<?php

namespace Database\Factories;

use App\Models\GoalSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoalSession>
 */
class GoalSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['role' => 'backend_engineer', 'harness' => 'codex', 'status' => 'active'];
    }
}
