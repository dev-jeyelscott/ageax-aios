<?php

namespace App\Models;

use App\TicketOperatorAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'ticket_id',
    'ticket_triage_attempt_id',
    'decided_by_user_id',
    'action',
    'direction',
])]
/**
 * @property int $ticket_id
 * @property int $ticket_triage_attempt_id
 * @property int $decided_by_user_id
 * @property TicketOperatorAction $action
 * @property string|null $direction
 */
class TicketEscalationDecision extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Ticket escalation decisions are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Ticket escalation decisions are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'action' => TicketOperatorAction::class,
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<TicketTriageAttempt, $this> */
    public function triageAttempt(): BelongsTo
    {
        return $this->belongsTo(TicketTriageAttempt::class, 'ticket_triage_attempt_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
