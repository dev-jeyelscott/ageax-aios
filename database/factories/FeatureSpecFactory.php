<?php

namespace Database\Factories;

use App\Models\FeatureSpec;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureSpec>
 */
class FeatureSpecFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['original_filename' => 'feature.md', 'storage_disk' => 'local', 'storage_path' => 'feature-specs/test.md', 'mime_type' => 'text/markdown', 'size_bytes' => 8, 'content_hash' => hash('sha256', fake()->uuid()), 'content' => '# Feature', 'status' => 'uploaded'];
    }
}
