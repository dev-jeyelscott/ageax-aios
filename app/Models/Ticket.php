<?php

namespace App\Models;

use App\TicketCategory;
use App\TicketDecision;
use App\TicketPriority;
use App\TicketRequesterCategory;
use App\TicketStatus;
use App\TicketUrgency;
use Carbon\CarbonImmutable;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'project_id',
    'submitted_by_user_id',
    'key',
    'title',
    'description',
    'requester_category',
    'requester_urgency',
])]
/**
 * @property int $project_id
 * @property int $submitted_by_user_id
 * @property string $key
 * @property TicketRequesterCategory|null $requester_category
 * @property TicketCategory|null $category
 * @property TicketStatus $status
 * @property TicketDecision|null $decision
 * @property TicketUrgency|null $requester_urgency
 * @property TicketPriority|null $ai_suggested_priority
 * @property TicketPriority|null $final_priority
 * @property string|null $triage_confidence
 * @property int|null $converted_task_id
 * @property CarbonImmutable|null $awaiting_response_until
 * @property CarbonImmutable|null $triaged_at
 * @property CarbonImmutable|null $closed_at
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Ticket $ticket): void {
            if ($ticket->isDirty('project_id')) {
                throw new LogicException('Ticket project ownership cannot be changed.');
            }

            if ($ticket->isDirty('key')) {
                throw new LogicException('Ticket key cannot be changed.');
            }
        });

        static::saving(function (Ticket $ticket): void {
            if ($ticket->triage_confidence !== null) {
                $confidence = (float) $ticket->triage_confidence;

                if ($confidence < 0 || $confidence > 1) {
                    throw new LogicException('Ticket triage confidence must be between 0 and 1.');
                }
            }

            if (
                $ticket->converted_task_id === null
                || ! $ticket->isDirty(['project_id', 'converted_task_id'])
            ) {
                return;
            }

            $taskBelongsToTicketProject = Task::query()
                ->whereKey($ticket->converted_task_id)
                ->where('project_id', $ticket->project_id)
                ->exists();

            if (! $taskBelongsToTicketProject) {
                throw new LogicException('Converted task must belong to the same project as the ticket.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'requester_category' => TicketRequesterCategory::class,
            'category' => TicketCategory::class,
            'status' => TicketStatus::class,
            'decision' => TicketDecision::class,
            'requester_urgency' => TicketUrgency::class,
            'ai_suggested_priority' => TicketPriority::class,
            'final_priority' => TicketPriority::class,
            'triage_confidence' => 'decimal:3',
            'awaiting_response_until' => 'immutable_datetime',
            'triaged_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /** @return BelongsTo<Task, $this> */
    public function convertedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'converted_task_id');
    }

    /** @return HasMany<TicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /** @return HasMany<TicketAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }
}
