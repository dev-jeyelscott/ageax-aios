<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = rtrim(fake()->unique()->sentence(3), '.');

        return [
            'project_id' => Project::factory()->state([
                'name' => fake()->unique()->company(),
                'path' => sys_get_temp_dir().'/aios-skill-project-'.fake()->uuid(),
            ]),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'instructions' => fake()->paragraph(),
            'constraints' => null,
            'applicable_roles' => [],
            'enabled' => true,
        ];
    }
}
