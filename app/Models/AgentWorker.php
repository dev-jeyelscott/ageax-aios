<?php

namespace App\Models;

use App\AgentRole;
use Carbon\CarbonImmutable;
use Database\Factories\AgentWorkerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['project_id', 'role', 'status', 'worker_instance_id', 'lease_id', 'last_heartbeat_at', 'lease_expires_at', 'process_id', 'started_at', 'stopped_at'])]
/**
 * @property AgentRole $role
 * @property ?CarbonImmutable $last_heartbeat_at
 * @property ?CarbonImmutable $lease_expires_at
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $stopped_at
 */
class AgentWorker extends Model
{
    /** @use HasFactory<AgentWorkerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['role' => AgentRole::class, 'last_heartbeat_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'stopped_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
