<?php

namespace Database\Factories;

use App\Models\WorkflowDefinition;
use App\Models\WorkflowStep;
use App\WorkflowStepKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStep>
 */
class WorkflowStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_definition_id' => WorkflowDefinition::factory(),
            'key' => fake()->unique()->slug(2),
            'position' => 1,
            'kind' => WorkflowStepKind::Queued,
            'label' => fake()->words(2, true),
        ];
    }
}
