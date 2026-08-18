<?php

namespace App\Models;

use App\TicketMessageAuthorType;
use App\TicketMessageType;
use Carbon\CarbonInterface;
use Database\Factories\TicketMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'ticket_id',
    'user_id',
    'agent_run_id',
    'author_type',
    'message_type',
    'body',
    'ai_generated',
])]
/**
 * @property int $ticket_id
 * @property int|null $user_id
 * @property int|null $agent_run_id
 * @property TicketMessageAuthorType $author_type
 * @property TicketMessageType $message_type
 * @property string $body
 * @property bool $ai_generated
 * @property CarbonInterface $created_at
 */
class TicketMessage extends Model
{
    /** @use HasFactory<TicketMessageFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (TicketMessage $message): void {
            $authorType = $message->getAttribute('author_type');
            $messageType = $message->getAttribute('message_type');

            if (! $authorType instanceof TicketMessageAuthorType) {
                throw new LogicException('Ticket message author type is invalid.');
            }

            if (! $messageType instanceof TicketMessageType) {
                throw new LogicException('Ticket message type is invalid.');
            }

            if (($authorType === TicketMessageAuthorType::User) !== ($message->user_id !== null)) {
                throw new LogicException('User-authored Ticket messages require exactly one user attribution.');
            }

            if (($authorType === TicketMessageAuthorType::Ai) !== $message->ai_generated) {
                throw new LogicException('AI-generated Ticket message attribution must match the AI author type.');
            }

            if ($authorType !== TicketMessageAuthorType::Ai && $message->agent_run_id !== null) {
                throw new LogicException('Only AI-authored Ticket messages may reference an AgentRun.');
            }

            if (($authorType === TicketMessageAuthorType::System) !== ($messageType === TicketMessageType::SystemEvent)) {
                throw new LogicException('System Ticket messages and system events must match exactly.');
            }

            if ($message->agent_run_id === null || ! $message->isDirty(['ticket_id', 'agent_run_id'])) {
                return;
            }

            $ticketProjectId = Ticket::query()
                ->whereKey($message->ticket_id)
                ->value('project_id');

            $runProjectId = AgentRun::query()
                ->whereKey($message->agent_run_id)
                ->value('project_id');

            if (
                $ticketProjectId === null
                || $runProjectId === null
                || (int) $ticketProjectId !== (int) $runProjectId
            ) {
                throw new LogicException('Ticket message AgentRun attribution must belong to the same project.');
            }
        });

        static::updating(function (): void {
            throw new LogicException('Ticket messages are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Ticket messages are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'author_type' => TicketMessageAuthorType::class,
            'message_type' => TicketMessageType::class,
            'ai_generated' => 'boolean',
        ];
    }

    /**
     * @param  Builder<TicketMessage>  $query
     * @return Builder<TicketMessage>
     */
    public function scopeClientVisible(Builder $query): Builder
    {
        return $query->whereIn('message_type', [
            TicketMessageType::PublicReply->value,
            TicketMessageType::SystemEvent->value,
        ]);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /** @return HasMany<TicketAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }
}
