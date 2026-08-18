<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TicketAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'ticket_id',
    'ticket_message_id',
    'uploaded_by_user_id',
    'original_name',
    'storage_disk',
    'storage_path',
    'mime_type',
    'extension',
    'size_bytes',
    'content_hash',
])]
/**
 * @property int $ticket_id
 * @property int|null $ticket_message_id
 * @property int|null $uploaded_by_user_id
 * @property string $original_name
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $mime_type
 * @property string $extension
 * @property int $size_bytes
 * @property string $content_hash
 * @property CarbonInterface $created_at
 */
class TicketAttachment extends Model
{
    /** @use HasFactory<TicketAttachmentFactory> */
    use HasFactory;

    protected $hidden = [
        'storage_disk',
        'storage_path',
        'content_hash',
    ];

    protected static function booted(): void
    {
        static::saving(function (TicketAttachment $attachment): void {
            if ($attachment->ticket_message_id === null) {
                return;
            }

            $messageTicketId = TicketMessage::query()
                ->whereKey($attachment->ticket_message_id)
                ->value('ticket_id');

            if (
                $messageTicketId === null
                || (int) $messageTicketId !== $attachment->ticket_id
            ) {
                throw new LogicException('Ticket attachment message must belong to the same Ticket.');
            }
        });

        static::updating(function (): void {
            throw new LogicException('Ticket attachment metadata is immutable.');
        });
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<TicketMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
