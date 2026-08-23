<?php

namespace App\Actions;

use App\Concerns\RejectsSecretMaterial;
use App\KnowledgeImprovementCandidateStatus;
use App\Models\GlobalKnowledgePattern;
use App\Models\KnowledgeImprovementCandidate;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class PromoteGlobalKnowledgePattern
{
    use RejectsSecretMaterial;

    /**
     * Inject AIOS-owned audit persistence for successful global promotions.
     */
    public function __construct(
        private AuditLogger $audit,
    ) {}

    /**
     * Explicitly promote one approved project candidate into immutable global guidance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        KnowledgeImprovementCandidate $candidate,
        User $operator,
        array $attributes,
    ): GlobalKnowledgePattern {
        $normalized = $this->normalizeAttributes($attributes);

        return DB::transaction(function () use (
            $candidate,
            $operator,
            $normalized,
        ): GlobalKnowledgePattern {
            $lockedCandidate = KnowledgeImprovementCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCandidateIsEligible($lockedCandidate);
            $this->assertGloballyReusableContent(
                $lockedCandidate->project,
                $normalized['name'],
                $normalized['validated_guidance'],
            );

            $sourceEvidenceHash = (string) $lockedCandidate->evidence_hash;

            $existing = GlobalKnowledgePattern::query()
                ->where('source_candidate_id', $lockedCandidate->id)
                ->where('source_evidence_hash', $sourceEvidenceHash)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof GlobalKnowledgePattern) {
                return $existing;
            }

            $patternKey = $this->patternKey(
                $normalized['category'],
                $normalized['name'],
            );

            $latest = GlobalKnowledgePattern::query()
                ->where('pattern_key', $patternKey)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $version = $latest instanceof GlobalKnowledgePattern
                ? $latest->version + 1
                : 1;

            if (
                $latest instanceof GlobalKnowledgePattern
                && $latest->superseded_at === null
            ) {
                $latest->update([
                    'enabled' => false,
                    'superseded_at' => now(),
                ]);
            }

            $pattern = GlobalKnowledgePattern::query()->create([
                'pattern_key' => $patternKey,
                'name' => $normalized['name'],
                'category' => $normalized['category'],
                'version' => $version,
                'applicable_roles' => $normalized['applicable_roles'],
                'validated_guidance' => $normalized['validated_guidance'],
                'source_project_id' => $lockedCandidate->project_id,
                'source_candidate_id' => $lockedCandidate->id,
                'source_evidence_hash' => $sourceEvidenceHash,
                'source_evidence' => $this->sourceEvidence($lockedCandidate),
                'approved_by_user_id' => $operator->id,
                'enabled' => true,
            ]);

            $this->audit->record(
                'knowledge.pattern_promoted',
                [
                    'global_knowledge_pattern_id' => $pattern->id,
                    'candidate_id' => $lockedCandidate->id,
                    'source_project_id' => $lockedCandidate->project_id,
                    'source_evidence_hash' => $sourceEvidenceHash,
                    'pattern_key' => $patternKey,
                    'category' => $pattern->category,
                    'version' => $pattern->version,
                    'approved_by_user_id' => $operator->id,
                ],
                $lockedCandidate->project,
            );

            return $pattern;
        }, attempts: 3);
    }

    /**
     * Normalize operator-supplied reusable guidance into deterministic storage values.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     name: string,
     *     category: string,
     *     applicable_roles: non-empty-list<string>,
     *     validated_guidance: string
     * }
     */
    private function normalizeAttributes(array $attributes): array
    {
        $name = Str::squish(
            is_string($attributes['name'] ?? null)
                ? $attributes['name']
                : '',
        );

        $category = Str::snake(Str::lower(Str::squish(
            is_string($attributes['category'] ?? null)
                ? $attributes['category']
                : '',
        )));

        $guidance = trim(
            is_string($attributes['validated_guidance'] ?? null)
                ? $attributes['validated_guidance']
                : '',
        );

        if ($name === '' || Str::length($name) > 160) {
            throw new LogicException(
                'Global pattern name must contain between 1 and 160 characters.',
            );
        }

        if (! in_array(
            $category,
            GlobalKnowledgePattern::allowedCategories(),
            true,
        )) {
            throw new LogicException(
                'Global pattern category is unsupported.',
            );
        }

        if ($guidance === '' || Str::length($guidance) > 4000) {
            throw new LogicException(
                'Validated global guidance must contain between 1 and 4000 characters.',
            );
        }

        $roles = $this->normalizeRoles($attributes['applicable_roles'] ?? null);

        return [
            'name' => $name,
            'category' => $category,
            'applicable_roles' => $roles,
            'validated_guidance' => $guidance,
        ];
    }

    /**
     * Normalize and deterministically order the selected project workflow roles.
     *
     * @return non-empty-list<string>
     */
    private function normalizeRoles(mixed $roles): array
    {
        if (! is_array($roles) || $roles === []) {
            throw new LogicException(
                'At least one applicable project workflow role is required.',
            );
        }

        $normalized = [];

        foreach ($roles as $role) {
            if (! is_string($role)) {
                throw new LogicException(
                    'Global pattern applicable roles are invalid.',
                );
            }

            $value = Str::snake(Str::lower(Str::squish($role)));

            if (! in_array(
                $value,
                GlobalKnowledgePattern::allowedRoles(),
                true,
            )) {
                throw new LogicException(
                    'Global pattern applicable roles must reference supported project workflow roles.',
                );
            }

            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * Verify that normal project-level review already approved this candidate.
     */
    private function assertCandidateIsEligible(
        KnowledgeImprovementCandidate $candidate,
    ): void {
        if (
            $candidate->getRawOriginal('status')
                !== KnowledgeImprovementCandidateStatus::Approved->value
        ) {
            throw new LogicException(
                'Only an approved knowledge-improvement candidate may be promoted globally.',
            );
        }

        if (
            $candidate->decided_by_user_id === null
            || $candidate->decided_at === null
        ) {
            throw new LogicException(
                'Global promotion requires an attributable project-level operator approval.',
            );
        }

        if (! $this->isSha256((string) $candidate->fingerprint)) {
            throw new LogicException(
                'The knowledge-improvement candidate fingerprint is invalid.',
            );
        }

        if (! $this->isSha256((string) $candidate->evidence_hash)) {
            throw new LogicException(
                'The knowledge-improvement candidate evidence hash is invalid.',
            );
        }

        $sourceKind = Str::squish((string) $candidate->source_kind);

        if ($sourceKind === '' || Str::length($sourceKind) > 64) {
            throw new LogicException(
                'The knowledge-improvement candidate source kind is invalid.',
            );
        }
    }

    /**
     * Fail closed when reusable text contains secrets or identifiable source-project material.
     */
    private function assertGloballyReusableContent(
        Project $project,
        string $name,
        string $guidance,
    ): void {
        foreach ([
            'name' => $name,
            'validated guidance' => $guidance,
        ] as $field => $value) {
            if ($this->containsSecretMaterial($value)) {
                throw new LogicException(
                    "Global pattern {$field} contains secret material.",
                );
            }

            $normalizedValue = str_replace('\\', '/', $value);
            $normalizedProjectPath = rtrim(
                str_replace('\\', '/', trim((string) $project->path)),
                '/',
            );

            if (
                $normalizedProjectPath !== ''
                && Str::contains(
                    Str::lower($normalizedValue),
                    Str::lower($normalizedProjectPath),
                )
            ) {
                throw new LogicException(
                    "Global pattern {$field} contains source-project path information.",
                );
            }

            $projectName = Str::squish((string) $project->name);

            if (
                Str::length($projectName) >= 6
                && Str::contains(
                    Str::lower($value),
                    Str::lower($projectName),
                )
            ) {
                throw new LogicException(
                    "Global pattern {$field} contains source-project-specific naming.",
                );
            }

            if (
                preg_match(
                    "#(?:^|[\\s=:()\"'])(?:~/|/(?:home|Users|private|var/www|srv|opt|tmp)/|[A-Za-z]:/)[^\\s,;)]*#u",
                    $normalizedValue,
                ) === 1
            ) {
                throw new LogicException(
                    "Global pattern {$field} contains an absolute local path.",
                );
            }
        }
    }

    /**
     * Build one stable identity for a logical category/name pattern lineage.
     */
    private function patternKey(string $category, string $name): string
    {
        return hash(
            'sha256',
            Str::lower($category).'|'.Str::lower(Str::squish($name)),
        );
    }

    /**
     * Snapshot only bounded source identities and hashes, never raw candidate evidence.
     *
     * @return array<string, mixed>
     */
    private function sourceEvidence(
        KnowledgeImprovementCandidate $candidate,
    ): array {
        return [
            'candidate_id' => $candidate->id,
            'project_id' => $candidate->project_id,
            'fingerprint' => $candidate->fingerprint,
            'source_kind' => $candidate->source_kind,
            'evidence_hash' => $candidate->evidence_hash,
            'knowledge_architect_agent_run_id' => $candidate->knowledge_architect_agent_run_id === null
                    ? null
                    : (int) $candidate->knowledge_architect_agent_run_id,
            'knowledge_architect_evidence_hash' => $candidate->knowledge_architect_evidence_hash,
        ];
    }

    /**
     * Determine whether a value is a canonical SHA-256 hexadecimal digest.
     */
    private function isSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
