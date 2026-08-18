<?php

namespace App\Actions;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TicketAttachmentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class StoreTicketAttachment
{
    public function __construct(
        private TicketAttachmentStorage $storage,
        private AuditLogger $audit,
    ) {}

    public function handle(
        Ticket $ticket,
        UploadedFile $file,
        ?User $uploadedBy = null,
        ?TicketMessage $message = null,
    ): TicketAttachment {
        if (
            $message !== null
            && $message->ticket_id !== $ticket->id
        ) {
            $this->audit->record(
                'ticket.attachment_rejected',
                [
                    'ticket_id' => $ticket->id,
                    'reason' => 'message_ticket_mismatch',
                ],
                $ticket->project,
            );

            throw new LogicException(
                'Ticket attachment message must belong to the same Ticket.',
            );
        }

        try {
            $stored = $this->storage->store(
                $ticket,
                $file,
            );
        } catch (ValidationException $exception) {
            $this->audit->record(
                'ticket.attachment_rejected',
                [
                    'ticket_id' => $ticket->id,
                    'reason' => 'attachment_policy_rejected',
                ],
                $ticket->project,
            );

            throw $exception;
        } catch (Throwable $exception) {
            $this->audit->record(
                'ticket.attachment_failed',
                [
                    'ticket_id' => $ticket->id,
                    'reason' => 'storage_failed',
                ],
                $ticket->project,
            );

            throw $exception;
        }

        try {
            return DB::transaction(function () use (
                $ticket,
                $uploadedBy,
                $message,
                $stored,
            ): TicketAttachment {
                $attachment = TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'ticket_message_id' => $message?->id,
                    'uploaded_by_user_id' => $uploadedBy?->id,
                    'original_name' => $stored['original_name'],
                    'storage_disk' => $stored['storage_disk'],
                    'storage_path' => $stored['storage_path'],
                    'mime_type' => $stored['mime_type'],
                    'extension' => $stored['extension'],
                    'size_bytes' => $stored['size_bytes'],
                    'content_hash' => $stored['content_hash'],
                ]);

                $this->audit->record(
                    'ticket.attachment_stored',
                    [
                        'ticket_id' => $ticket->id,
                        'attachment_id' => $attachment->id,
                        'message_id' => $message?->id,
                        'mime_type' => $attachment->mime_type,
                        'extension' => $attachment->extension,
                        'size_bytes' => $attachment->size_bytes,
                    ],
                    $ticket->project,
                );

                return $attachment;
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->storage->deleteStored(
                $ticket,
                $stored['storage_path'],
            );

            $this->audit->record(
                'ticket.attachment_failed',
                [
                    'ticket_id' => $ticket->id,
                    'reason' => 'metadata_persistence_failed',
                ],
                $ticket->project,
            );

            throw $exception;
        }
    }
}
