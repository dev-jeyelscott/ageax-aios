<?php

namespace Database\Factories;

use App\Models\KnowledgeSourceManifest;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeSourceManifest>
 */
class KnowledgeSourceManifestFactory extends Factory
{
    /**
     * Provide a current metadata-only source manifest for tests.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->state([
                'name' => fake()->unique()->company(),
                'path' => sys_get_temp_dir().'/aios-knowledge-source-'.fake()->uuid(),
            ]),
            'source_type' => 'obsidian',
            'source_reference' => 'STATE.md',
            'content_hash' => hash('sha256', fake()->uuid()),
            'git_sha' => null,
            'discovered_at' => now(),
            'last_verified_at' => now(),
            'superseded_at' => null,
            'superseded_by_id' => null,
        ];
    }
}
