<?php

namespace App\Models;

use Database\Factories\RoadmapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'original_filename', 'storage_path', 'status', 'content', 'content_hash', 'source', 'source_path', 'structured_output', 'processed_at'])]
class Roadmap extends Model
{
    /** @use HasFactory<RoadmapFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['structured_output' => 'array', 'processed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<RoadmapAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(RoadmapAttempt::class);
    }
}
