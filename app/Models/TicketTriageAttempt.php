<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'ticket_id',
    'agent_run_id',
    'number',
    'status',
    'structured_decision',
    'claimed_at',
    'finished_at',
])]
/**
 * @property int $ticket_id
 * @property int|null $agent_run_id
 * @property int $number
 * @property string $status
 * @property array<string, mixed>|null $structured_decision
 * @property CarbonImmutable $claimed_at
 * @property CarbonImmutable|null $finished_at
 */
class TicketTriageAttempt extends Model
{
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'structured_decision' => 'array',
            'claimed_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /** @return HasOne<TicketEscalationDecision, $this> */
    public function escalationDecision(): HasOne
    {
        return $this->hasOne(TicketEscalationDecision::class, 'ticket_triage_attempt_id');
    }
}
