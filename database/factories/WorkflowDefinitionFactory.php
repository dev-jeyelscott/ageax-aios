<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\WorkflowDefinitionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowDefinition>
 */
class WorkflowDefinitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(3),
            'version' => 1,
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'status' => WorkflowDefinitionStatus::Draft,
            'created_by_user_id' => User::factory(),
        ];
    }
}
