<?php

namespace App\Models;

use App\Concerns\RejectsSecretMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

#[Fillable([
    'pattern_key',
    'name',
    'category',
    'version',
    'applicable_roles',
    'validated_guidance',
    'source_project_id',
    'source_candidate_id',
    'source_evidence_hash',
    'source_evidence',
    'approved_by_user_id',
    'enabled',
    'superseded_at',
])]
/**
 * @property list<string> $applicable_roles
 * @property array<string, mixed> $source_evidence
 * @property bool $enabled
 * @property int $version
 * @property CarbonImmutable|null $superseded_at
 */
class GlobalKnowledgePattern extends Model
{
    use RejectsSecretMaterial;

    private const array Categories = [
        'architecture',
        'security',
        'data_integrity',
        'reliability',
        'testing',
        'workflow',
        'code_quality',
        'performance',
        'operations',
    ];

    private const array ApplicableRoles = [
        'project_manager',
        'coder',
        'reviewer',
    ];

    private const array MutableLifecycleAttributes = [
        'enabled',
        'superseded_at',
        'updated_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
        'version' => 1,
        'applicable_roles' => '[]',
        'source_evidence' => '{}',
    ];

    /**
     * Enforce immutable pattern content and validate every persisted lifecycle state.
     */
    protected static function booted(): void
    {
        static::creating(function (GlobalKnowledgePattern $pattern): void {
            $pattern->assertConfigurationIsValid();
        });

        static::updating(function (GlobalKnowledgePattern $pattern): void {
            foreach (array_keys($pattern->getDirty()) as $attribute) {
                if (! in_array($attribute, self::MutableLifecycleAttributes, true)) {
                    throw new LogicException(
                        'Historical global knowledge pattern content is immutable.',
                    );
                }
            }

            $pattern->assertConfigurationIsValid();
        });

        static::deleting(function (): void {
            throw new LogicException(
                'Historical global knowledge pattern versions cannot be deleted.',
            );
        });
    }

    /**
     * Cast durable pattern fields to their domain representations.
     */
    protected function casts(): array
    {
        return [
            'applicable_roles' => 'array',
            'source_evidence' => 'array',
            'enabled' => 'boolean',
            'version' => 'integer',
            'source_project_id' => 'integer',
            'source_candidate_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Return the bounded categories operators may assign during global promotion.
     *
     * @return list<string>
     */
    public static function allowedCategories(): array
    {
        return self::Categories;
    }

    /**
     * Return only project workflow roles eligible to consume reusable patterns.
     *
     * @return list<string>
     */
    public static function allowedRoles(): array
    {
        return self::ApplicableRoles;
    }

    /**
     * Resolve the approving operator when that user still exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Validate immutable global-pattern content and bounded provenance.
     */
    private function assertConfigurationIsValid(): void
    {
        $patternKey = (string) $this->getAttribute('pattern_key');
        $sourceEvidenceHash = (string) $this->getAttribute('source_evidence_hash');

        if (! $this->isSha256($patternKey)) {
            throw new LogicException(
                'Global knowledge pattern key must be a canonical SHA-256 digest.',
            );
        }

        if (! $this->isSha256($sourceEvidenceHash)) {
            throw new LogicException(
                'Global knowledge pattern source evidence hash must be a canonical SHA-256 digest.',
            );
        }

        $name = Str::squish((string) $this->getAttribute('name'));

        if ($name === '' || Str::length($name) > 160) {
            throw new LogicException(
                'Global knowledge pattern name must contain between 1 and 160 characters.',
            );
        }

        $category = (string) $this->getAttribute('category');

        if (! in_array($category, self::Categories, true)) {
            throw new LogicException(
                'Global knowledge pattern category is unsupported.',
            );
        }

        $version = (int) $this->getAttribute('version');

        if ($version < 1) {
            throw new LogicException(
                'Global knowledge pattern version must be at least 1.',
            );
        }

        $roles = $this->getAttribute('applicable_roles');

        if (
            ! is_array($roles)
            || $roles === []
            || count($roles) > count(self::ApplicableRoles)
            || count($roles) !== count(array_unique($roles))
        ) {
            throw new LogicException(
                'Global knowledge pattern applicable roles must be a non-empty unique role list.',
            );
        }

        foreach ($roles as $role) {
            if (! is_string($role) || ! in_array($role, self::ApplicableRoles, true)) {
                throw new LogicException(
                    'Global knowledge pattern applicable roles must reference supported project workflow roles.',
                );
            }
        }

        $guidance = trim((string) $this->getAttribute('validated_guidance'));

        if ($guidance === '' || Str::length($guidance) > 4000) {
            throw new LogicException(
                'Global knowledge pattern guidance must contain between 1 and 4000 characters.',
            );
        }

        foreach ([$name, $guidance] as $value) {
            if ($this->containsSecretMaterial($value)) {
                throw new LogicException(
                    'Global knowledge patterns cannot contain secret material.',
                );
            }
        }

        if ((int) $this->getAttribute('source_project_id') < 1) {
            throw new LogicException(
                'Global knowledge pattern requires source project provenance.',
            );
        }

        if ((int) $this->getAttribute('source_candidate_id') < 1) {
            throw new LogicException(
                'Global knowledge pattern requires source candidate provenance.',
            );
        }

        if ((int) $this->getAttribute('approved_by_user_id') < 1) {
            throw new LogicException(
                'Global knowledge pattern requires an approving operator.',
            );
        }

        $this->assertSourceEvidenceIsBounded();

        if (
            $this->getAttribute('superseded_at') !== null
            && (bool) $this->getAttribute('enabled')
        ) {
            throw new LogicException(
                'A superseded global knowledge pattern cannot remain enabled.',
            );
        }
    }

    /**
     * Ensure provenance contains only bounded identifiers and hashes, never raw project evidence.
     */
    private function assertSourceEvidenceIsBounded(): void
    {
        $evidence = $this->getAttribute('source_evidence');

        if (! is_array($evidence)) {
            throw new LogicException(
                'Global knowledge pattern source evidence must be structured metadata.',
            );
        }

        $allowedFields = [
            'candidate_id',
            'project_id',
            'fingerprint',
            'source_kind',
            'evidence_hash',
            'knowledge_architect_agent_run_id',
            'knowledge_architect_evidence_hash',
        ];

        $unexpectedFields = array_values(
            array_diff(array_keys($evidence), $allowedFields),
        );

        if ($unexpectedFields !== []) {
            throw new LogicException(
                'Global knowledge pattern source evidence contains unsupported fields.',
            );
        }

        foreach ([
            'candidate_id',
            'project_id',
            'fingerprint',
            'source_kind',
            'evidence_hash',
        ] as $requiredField) {
            if (! array_key_exists($requiredField, $evidence)) {
                throw new LogicException(
                    'Global knowledge pattern source evidence is incomplete.',
                );
            }
        }

        if (
            (int) $evidence['candidate_id']
                !== (int) $this->getAttribute('source_candidate_id')
            || (int) $evidence['project_id']
                !== (int) $this->getAttribute('source_project_id')
        ) {
            throw new LogicException(
                'Global knowledge pattern provenance does not match its source identifiers.',
            );
        }

        if (
            ! is_string($evidence['fingerprint'])
            || ! $this->isSha256($evidence['fingerprint'])
            || ! is_string($evidence['evidence_hash'])
            || ! $this->isSha256($evidence['evidence_hash'])
            || ! hash_equals(
                (string) $this->getAttribute('source_evidence_hash'),
                $evidence['evidence_hash'],
            )
        ) {
            throw new LogicException(
                'Global knowledge pattern source evidence contains invalid hashes.',
            );
        }

        $sourceKind = $evidence['source_kind'];

        if (
            ! is_string($sourceKind)
            || $sourceKind === ''
            || Str::length($sourceKind) > 64
            || $this->containsSecretMaterial($sourceKind)
        ) {
            throw new LogicException(
                'Global knowledge pattern source kind is invalid.',
            );
        }

        $knowledgeArchitectRunId =
            $evidence['knowledge_architect_agent_run_id'] ?? null;

        if (
            $knowledgeArchitectRunId !== null
            && (! is_int($knowledgeArchitectRunId) || $knowledgeArchitectRunId < 1)
        ) {
            throw new LogicException(
                'Global knowledge pattern Knowledge Architect run provenance is invalid.',
            );
        }

        $knowledgeArchitectEvidenceHash =
            $evidence['knowledge_architect_evidence_hash'] ?? null;

        if (
            $knowledgeArchitectEvidenceHash !== null
            && (
                ! is_string($knowledgeArchitectEvidenceHash)
                || ! $this->isSha256($knowledgeArchitectEvidenceHash)
            )
        ) {
            throw new LogicException(
                'Global knowledge pattern Knowledge Architect evidence hash is invalid.',
            );
        }
    }

    /**
     * Determine whether a value is a canonical SHA-256 hexadecimal digest.
     */
    private function isSha256(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
