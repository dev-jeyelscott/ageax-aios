<?php

namespace App\Models;

use App\ProjectStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'path', 'status', 'git_status', 'git_head_sha', 'obsidian_path', 'paused_at', 'roadmap_scanned_at'])]
/**
 * @property ProjectStatus $status
 * @property CarbonImmutable|null $roadmap_scanned_at
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => ProjectStatus::class, 'paused_at' => 'immutable_datetime', 'roadmap_scanned_at' => 'immutable_datetime'];
    }

    /** @return HasMany<Roadmap, $this> */
    public function roadmaps(): HasMany
    {
        return $this->hasMany(Roadmap::class);
    }

    /** @return HasMany<Phase, $this> */
    public function phases(): HasMany
    {
        return $this->hasMany(Phase::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Agent, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /** @return HasMany<Skill, $this> */
    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class);
    }

    /** @return HasMany<AgentWorker, $this> */
    public function workers(): HasMany
    {
        return $this->hasMany(AgentWorker::class);
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /** @return HasMany<ProjectManagerMessage, $this> */
    public function projectManagerMessages(): HasMany
    {
        return $this->hasMany(ProjectManagerMessage::class);
    }
}
