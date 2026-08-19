<?php

namespace Database\Factories;

use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeImprovementCandidate>
 */
class KnowledgeImprovementCandidateFactory extends Factory
{
    public function definition(): array
    {
        $fingerprint = hash('sha256', fake()->uuid());

        return [
            'project_id' => Project::factory()->state([
                'name' => fake()->unique()->company(),
                'path' => sys_get_temp_dir().'/aios-knowledge-candidate-'.fake()->uuid(),
            ]),
            'fingerprint' => $fingerprint,
            'source_kind' => 'review_finding',
            'failure_code' => 'review_finding:architecture_consistency',
            'affected_role' => 'coder',
            'affected_area' => 'app/Services',
            'status' => KnowledgeImprovementCandidateStatus::Pending,
            'target_type' => KnowledgeImprovementTarget::Documentation,
            'evidence_summary' => 'Three recurring structured review findings were detected in app/Services.',
            'proposed_change' => 'Document the recurring implementation guardrail before the same defect reaches review again.',
            'evidence' => [
                [
                    'source_type' => 'review_finding',
                    'source_id' => fake()->numberBetween(1, 1000),
                ],
            ],
            'occurrence_count' => 3,
            'evidence_hash' => hash('sha256', fake()->uuid()),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
