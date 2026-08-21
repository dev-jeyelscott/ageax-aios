<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAttachmentStorage;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketRequesterCategory;
use App\TicketUrgency;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitTicket
{
    public function __construct(
        private CreateTicket $createTicket,
        private RecordTicketMessage $recordMessage,
        private StoreTicketAttachment $storeAttachment,
        private TicketAttachmentStorage $attachmentStorage,
    ) {}

    /**
     * @param  list<UploadedFile>  $attachments
     */
    public function handle(
        Project $project,
        User $submitter,
        string $title,
        string $description,
        ?TicketRequesterCategory $requesterCategory = null,
        ?TicketUrgency $requesterUrgency = null,
        array $attachments = [],
    ): Ticket {
        $ticket = null;
        $storedPaths = [];

        try {
            return DB::transaction(function () use (
                $project,
                $submitter,
                $title,
                $description,
                $requesterCategory,
                $requesterUrgency,
                $attachments,
                &$ticket,
                &$storedPaths,
            ): Ticket {
                $ticket = $this->createTicket->handle(
                    $project,
                    $submitter,
                    $title,
                    $description,
                    $requesterCategory,
                    $requesterUrgency,
                );

                $initialMessage = $this->recordMessage->handle(
                    $ticket,
                    TicketMessageAuthorType::User,
                    TicketMessageType::PublicReply,
                    $description,
                    $submitter,
                );

                foreach ($attachments as $index => $file) {
                    try {
                        $attachment = $this->storeAttachment->handle(
                            $ticket,
                            $file,
                            $submitter,
                            $initialMessage,
                        );
                    } catch (ValidationException $exception) {
                        throw $this->attachmentValidationException(
                            $exception,
                            $index,
                        );
                    }

                    $storedPaths[] = $attachment->storage_path;
                }

                return $ticket;
            }, attempts: 1);
        } catch (Throwable $exception) {
            if ($ticket !== null) {
                $this->cleanupStoredFiles(
                    $ticket,
                    $storedPaths,
                );
            }

            throw $exception;
        }
    }

    private function attachmentValidationException(
        ValidationException $exception,
        int $index,
    ): ValidationException {
        $messages = [];

        foreach ($exception->errors() as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $messages[] = $message;
            }
        }

        if ($messages === []) {
            $messages[] = 'The attachment is invalid.';
        }

        return ValidationException::withMessages([
            "attachments.{$index}" => $messages,
        ]);
    }

    /**
     * @param  list<string>  $storagePaths
     */
    private function cleanupStoredFiles(
        Ticket $ticket,
        array $storagePaths,
    ): void {
        foreach (array_reverse($storagePaths) as $storagePath) {
            try {
                $this->attachmentStorage->deleteStored(
                    $ticket,
                    $storagePath,
                );
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
        }
    }
}
