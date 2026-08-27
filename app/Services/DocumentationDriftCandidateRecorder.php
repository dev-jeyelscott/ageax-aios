<?php

namespace App\Services;

use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/** Persist validated reconciliation findings through the existing operator-reviewed queue. */
class DocumentationDriftCandidateRecorder
{
    public function __construct(private AuditLogger $audit) {}

    /** @param list<array<string, mixed>> $findings */
    public function record(Project $project, array $findings): int
    {
        $changed = 0;

        foreach ($findings as $finding) {
            $fingerprint = $this->fingerprint($finding);
            $evidence = [[
                'target_source' => $finding['target_source'],
                'target_category' => $finding['target_category'],
                'evidence_paths' => $finding['evidence_paths'],
                'evidence_shas' => $finding['evidence_shas'],
                'deterministic' => $finding['deterministic'],
                'requires_knowledge_architect_analysis' => $finding['requires_knowledge_architect_analysis'],
            ]];
            $evidenceHash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $didChange = DB::transaction(function () use ($project, $finding, $fingerprint, $evidence, $evidenceHash): bool {
                $candidate = KnowledgeImprovementCandidate::query()->firstOrCreate(
                    ['project_id' => $project->id, 'fingerprint' => $fingerprint],
                    [
                        'source_kind' => 'documentation_drift',
                        'failure_code' => 'documentation_drift:'.$finding['target_category'],
                        'affected_role' => 'project_manager',
                        'affected_area' => $finding['target_source'],
                        'status' => KnowledgeImprovementCandidateStatus::Pending,
                        'target_type' => $this->target($finding['target_category']),
                        'evidence_summary' => $finding['reason_for_drift'],
                        'proposed_change' => $finding['proposed_alignment'],
                        'evidence' => $evidence,
                        'occurrence_count' => 1,
                        'evidence_hash' => $evidenceHash,
                        'first_seen_at' => now(),
                        'last_seen_at' => now(),
                    ],
                );

                if ($candidate->wasRecentlyCreated) {
                    $this->audit->record('knowledge_improvement_candidate.created', ['candidate_id' => $candidate->id, 'fingerprint' => $fingerprint, 'source_kind' => 'documentation_drift'], $project);

                    return true;
                }

                if (hash_equals((string) $candidate->evidence_hash, $evidenceHash)) {
                    return false;
                }

                // Deliberately preserve every operator decision. New evidence never reopens an approved,
                // rejected, or dismissed proposal without the existing queue's explicit policy.
                $candidate->update([
                    'evidence_summary' => $finding['reason_for_drift'],
                    'proposed_change' => $finding['proposed_alignment'],
                    'evidence' => $evidence,
                    'evidence_hash' => $evidenceHash,
                    'last_seen_at' => now(),
                ]);
                $this->audit->record('knowledge_improvement_candidate.evidence_updated', ['candidate_id' => $candidate->id, 'fingerprint' => $fingerprint], $project);

                return true;
            }, attempts: 3);

            if ($didChange) {
                $changed++;
            }
        }

        return $changed;
    }

    /** @param array<string, mixed> $finding */
    private function fingerprint(array $finding): string
    {
        return hash('sha256', json_encode([
            'source_kind' => 'documentation_drift',
            'target_source' => $finding['target_source'],
            'target_category' => $finding['target_category'],
            'evidence_paths' => $finding['evidence_paths'],
            'evidence_shas' => $finding['evidence_shas'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function target(string $category): KnowledgeImprovementTarget
    {
        return match ($category) {
            'rule' => KnowledgeImprovementTarget::Rule,
            'regression_test' => KnowledgeImprovementTarget::RegressionTest,
            default => KnowledgeImprovementTarget::Documentation,
        };
    }
}
