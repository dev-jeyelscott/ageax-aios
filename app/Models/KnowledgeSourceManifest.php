<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\KnowledgeSourceManifestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'project_id',
    'source_type',
    'source_reference',
    'content_hash',
    'git_sha',
    'discovered_at',
    'last_verified_at',
    'superseded_at',
    'superseded_by_id',
])]
/**
 * Durable metadata for one observed temporal version of a knowledge source.
 *
 * @property CarbonImmutable $discovered_at
 * @property CarbonImmutable $last_verified_at
 * @property CarbonImmutable|null $superseded_at
 */
class KnowledgeSourceManifest extends Model
{
    /** @use HasFactory<KnowledgeSourceManifestFactory> */
    use HasFactory;

    /**
     * Cast temporal evidence as immutable timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discovered_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Return the project that owns this source identity.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the newer version that superseded this historical version.
     *
     * @return BelongsTo<KnowledgeSourceManifest, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * Return the immediately previous version superseded by this version.
     *
     * @return HasOne<KnowledgeSourceManifest, $this>
     */
    public function supersedes(): HasOne
    {
        return $this->hasOne(self::class, 'superseded_by_id');
    }
}
