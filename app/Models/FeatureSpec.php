<?php

namespace App\Models;

use Database\Factories\FeatureSpecFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['project_id', 'uploaded_by_user_id', 'original_filename', 'storage_disk', 'storage_path', 'mime_type', 'size_bytes', 'content_hash', 'content', 'status'])]
class FeatureSpec extends Model
{
    /** @use HasFactory<FeatureSpecFactory> */
    use HasFactory;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** @return HasOne<GoalRun, $this> */
    public function goalRun(): HasOne
    {
        return $this->hasOne(GoalRun::class);
    }
}
