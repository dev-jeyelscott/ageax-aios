<?php

namespace Database\Factories;

use App\AgentHarness;
use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->state([
                'name' => fake()->unique()->company(),
                'path' => sys_get_temp_dir().'/aios-agent-project-'.fake()->uuid(),
            ]),
            'name' => fake()->unique()->words(2, true),
            'role' => AgentRole::Coder,
            'harness' => AgentHarness::Codex,
            'model' => null,
            'reasoning_setting' => null,
            'default_context' => null,
            'enabled' => true,
        ];
    }
}
