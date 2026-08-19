<?php

namespace App\Models;

use App\KnowledgeImprovementCandidateStatus;
use App\KnowledgeImprovementTarget;
use Carbon\CarbonImmutable;
use Database\Factories\KnowledgeImprovementCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'project_id',
    'target_skill_id',
    'decided_by_user_id',
    'fingerprint',
    'source_kind',
    'failure_code',
    'affected_role',
    'affected_area',
    'status',
    'target_type',
    'evidence_summary',
    'proposed_change',
    'evidence',
    'occurrence_count',
    'reopen_after_occurrence',
    'evidence_hash',
    'first_seen_at',
    'last_seen_at',
    'decided_at',
    'applied_at',
    'applied_skill_version',
])]
/**
 * @property KnowledgeImprovementCandidateStatus $status
 * @property KnowledgeImprovementTarget $target_type
 * @property array<int, array<string, mixed>> $evidence
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $applied_at
 */
class KnowledgeImprovementCandidate extends Model
{
    /** @use HasFactory<KnowledgeImprovementCandidateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => KnowledgeImprovementCandidateStatus::class,
            'target_type' => KnowledgeImprovementTarget::class,
            'evidence' => 'array',
            'occurrence_count' => 'integer',
            'reopen_after_occurrence' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'applied_skill_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function targetSkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'target_skill_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
