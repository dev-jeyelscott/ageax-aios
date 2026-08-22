<?php

namespace App\Actions;

use App\AgentRole;
use App\AgentRunStatus;
use App\KnowledgeImprovementCandidateStatus;
use App\Models\AgentRun;
use App\Models\KnowledgeImprovementCandidate;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;

class RecordKnowledgeImprovementAdvisory
{
    private const int SchemaVersion = 1;

    private const array AllowedFields = [
        'schema_version',
        'action',
        'evidence_summary',
        'proposed_change',
        'confidence',
    ];

    /**
     * Inject the AIOS audit authority used after a validated advisory is persisted.
     */
    public function __construct(
        private AuditLogger $audit,
    ) {}

    /**
     * Validate and normalize one Knowledge Architect response before durable proposal mutation.
     *
     * @param  array<string, mixed>  $structuredAdvisory
     * @return array{
     *     schema_version: int,
     *     action: 'enrich'|'no_change',
     *     evidence_summary: string,
     *     proposed_change: string|null,
     *     confidence: 'low'|'medium'|'high'
     * }
     */
    public function validate(array $structuredAdvisory): array
    {
        $unexpected = array_values(array_diff(
            array_keys($structuredAdvisory),
            self::AllowedFields,
        ));

        if ($unexpected !== []) {
            throw new LogicException(
                'Knowledge Architect advisory contains unsupported fields: '
                .implode(', ', $unexpected)
                .'.',
            );
        }

        $validated = Validator::make($structuredAdvisory, [
            'schema_version' => ['required', 'integer', 'in:'.self::SchemaVersion],
            'action' => ['required', 'string', 'in:enrich,no_change'],
            'evidence_summary' => ['required', 'string', 'max:4000'],
            'proposed_change' => ['nullable', 'string', 'max:4000'],
            'confidence' => ['required', 'string', 'in:low,medium,high'],
        ])->validate();

        $action = match ($validated['action']) {
            'enrich' => 'enrich',
            'no_change' => 'no_change',
            default => throw new LogicException(
                'Knowledge Architect advisory action is unsupported.',
            ),
        };

        $confidence = match ($validated['confidence']) {
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            default => throw new LogicException(
                'Knowledge Architect advisory confidence is unsupported.',
            ),
        };

        $evidenceSummary = trim((string) $validated['evidence_summary']);

        $proposedChangeValue = $validated['proposed_change'] ?? null;
        $proposedChange = is_string($proposedChangeValue)
            ? trim($proposedChangeValue)
            : null;

        if ($evidenceSummary === '') {
            throw new LogicException(
                'Knowledge Architect advisory requires a non-empty evidence summary.',
            );
        }

        if (
            $action === 'enrich'
            && ($proposedChange === null || $proposedChange === '')
        ) {
            throw new LogicException(
                'Knowledge Architect enrichment requires a non-empty proposed change.',
            );
        }

        if (
            $action === 'no_change'
            && $proposedChange !== null
            && $proposedChange !== ''
        ) {
            throw new LogicException(
                'Knowledge Architect no-change advice must not include a proposed change.',
            );
        }

        return [
            'schema_version' => self::SchemaVersion,
            'action' => $action,
            'evidence_summary' => $evidenceSummary,
            'proposed_change' => $proposedChange,
            'confidence' => $confidence,
        ];
    }

    /**
     * Persist only validated proposal enrichment and immutable source AgentRun evidence.
     *
     * @param  array<string, mixed>  $structuredAdvisory
     */
    public function handle(
        KnowledgeImprovementCandidate $candidate,
        AgentRun $agentRun,
        string $sourceEvidenceHash,
        array $structuredAdvisory,
    ): KnowledgeImprovementCandidate {
        $validated = $this->validate($structuredAdvisory);

        return DB::transaction(function () use (
            $candidate,
            $agentRun,
            $sourceEvidenceHash,
            $validated,
        ): KnowledgeImprovementCandidate {
            $lockedCandidate = KnowledgeImprovementCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRun = AgentRun::query()
                ->whereKey($agentRun->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEligibleRun(
                $lockedCandidate,
                $lockedRun,
            );

            if (
                ! $this->isSha256($sourceEvidenceHash)
                || ! hash_equals(
                    (string) $lockedCandidate->evidence_hash,
                    $sourceEvidenceHash,
                )
            ) {
                throw new LogicException(
                    'Knowledge Architect advisory evidence became stale before persistence.',
                );
            }

            if (
                is_string($lockedCandidate->knowledge_architect_evidence_hash)
                && hash_equals(
                    $lockedCandidate->knowledge_architect_evidence_hash,
                    $sourceEvidenceHash,
                )
            ) {
                return $lockedCandidate;
            }

            $changes = [
                'knowledge_architect_agent_run_id' => $lockedRun->id,
                'knowledge_architect_evidence_hash' => $sourceEvidenceHash,
            ];

            if ($validated['action'] === 'enrich') {
                $changes['evidence_summary'] = $validated['evidence_summary'];
                $changes['proposed_change'] = $validated['proposed_change'];
            }

            $lockedCandidate->update($changes);

            $this->audit->record(
                'knowledge_architect.advisory_persisted',
                [
                    'candidate_id' => $lockedCandidate->id,
                    'agent_run_id' => $lockedRun->id,
                    'source_evidence_hash' => $sourceEvidenceHash,
                    'action' => $validated['action'],
                    'confidence' => $validated['confidence'],
                ],
                $lockedCandidate->project,
            );

            return $lockedCandidate->refresh();
        }, attempts: 3);
    }

    /**
     * Prove only a completed workerless project-consistent Knowledge Architect run may enrich a proposal.
     */
    private function assertEligibleRun(
        KnowledgeImprovementCandidate $candidate,
        AgentRun $run,
    ): void {
        if (
            $candidate->getRawOriginal('status')
            !== KnowledgeImprovementCandidateStatus::Pending->value
        ) {
            throw new LogicException(
                'Knowledge Architect may enrich only a pending knowledge-improvement candidate.',
            );
        }

        if ((int) $run->project_id !== (int) $candidate->project_id) {
            throw new LogicException(
                'Knowledge Architect AgentRun cannot cross the candidate project boundary.',
            );
        }

        if (
            $run->getRawOriginal('role') !== AgentRole::KnowledgeArchitect->value
            || $run->getRawOriginal('status') !== AgentRunStatus::Completed->value
            || $run->finished_at === null
        ) {
            throw new LogicException(
                'Knowledge Architect advisory requires a completed Knowledge Architect AgentRun.',
            );
        }

        if ($run->agent_worker_id !== null || $run->agent_id === null) {
            throw new LogicException(
                'Knowledge Architect AgentRun must be global, attributable, and workerless.',
            );
        }

        $snapshot = $run->getAttribute('configuration_snapshot');

        $agentSnapshot = is_array($snapshot)
            ? ($snapshot['agent'] ?? null)
            : null;

        if (
            ! is_array($snapshot)
            || ! is_array($agentSnapshot)
            || ($agentSnapshot['id'] ?? null) !== $run->agent_id
            || ($agentSnapshot['role'] ?? null) !== AgentRole::KnowledgeArchitect->value
            || ($snapshot['context_schema_version'] ?? null) !== $run->context_schema_version
        ) {
            throw new LogicException(
                'Knowledge Architect AgentRun is missing valid immutable configuration evidence.',
            );
        }
    }

    /**
     * Determine whether a string is a canonical SHA-256 hexadecimal digest.
     */
    private function isSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
