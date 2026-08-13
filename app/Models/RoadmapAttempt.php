<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['roadmap_id', 'agent_run_id', 'number', 'status', 'structured_output', 'claimed_at', 'finished_at'])]
/**
 * @property ?CarbonImmutable $claimed_at
 * @property ?CarbonImmutable $finished_at
 */
class RoadmapAttempt extends Model
{
    protected function casts(): array
    {
        return ['structured_output' => 'array', 'claimed_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Roadmap, $this> */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
