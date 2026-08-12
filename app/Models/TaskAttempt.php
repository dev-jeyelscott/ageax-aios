<?php

namespace App\Models;

use Database\Factories\TaskAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['task_id', 'number', 'base_sha', 'head_sha', 'commit_sha', 'status', 'validation_results', 'changed_files', 'log_path', 'started_at', 'finished_at'])]
class TaskAttempt extends Model
{
    /** @use HasFactory<TaskAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['validation_results' => 'array', 'changed_files' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
