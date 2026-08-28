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

#[Fillable(['project_id', 'role', 'slot', 'agent_id', 'status', 'worker_instance_id', 'lease_id', 'last_heartbeat_at', 'lease_expires_at', 'process_id', 'started_at', 'stopped_at', 'task_completed_at'])]
/**
 * @property AgentRole $role
 * @property int $slot
 * @property ?int $agent_id
 * @property ?CarbonImmutable $last_heartbeat_at
 * @property ?CarbonImmutable $lease_expires_at
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $stopped_at
 * @property ?CarbonImmutable $task_completed_at
 */
class AgentWorker extends Model
{
    /** @use HasFactory<AgentWorkerFactory> */
    use HasFactory;

    /**
     * Cast durable worker role, slot, and runtime timestamps to their domain types.
     */
    protected function casts(): array
    {
        return [
            'role' => AgentRole::class,
            'slot' => 'integer',
            'last_heartbeat_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
            'task_completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Return the project that owns this durable worker slot.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the execution Agent bound to this exact worker slot.
     *
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Return every durable AgentRun executed through this exact worker slot.
     *
     * @return HasMany<AgentRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
