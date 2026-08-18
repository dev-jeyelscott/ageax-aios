<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use LogicException;

class TicketConversation
{
    public function __construct(
        private TicketAttachmentStorage $attachments,
    ) {}

    /**
     * @return list<array{
     *     id: int,
     *     message_type: string,
     *     author_type: string,
     *     body: string,
     *     ai_generated: bool,
     *     ai_badge: ?string,
     *     agent_run_id: ?int,
     *     created_at: ?string,
     *     attachments: list<array{
     *         id: int,
     *         original_name: string,
     *         mime_type: string,
     *         extension: string,
     *         size_bytes: int,
     *         text_context_supported: bool
     *     }>
     * }>
     */
    public function clientSafePayload(
        Ticket $ticket,
    ): array {
        $messages = $ticket->messages()
            ->clientVisible()
            ->with('attachments')
            ->oldest('id')
            ->get();

        $payload = [];

        foreach ($messages as $message) {
            $authorType = $message->getAttribute('author_type');
            $messageType = $message->getAttribute('message_type');

            if (! $authorType instanceof TicketMessageAuthorType) {
                throw new LogicException('Ticket message author type is invalid.');
            }

            if (! $messageType instanceof TicketMessageType) {
                throw new LogicException('Ticket message type is invalid.');
            }

            $attachmentPayloads = [];

            foreach ($message->attachments as $attachment) {
                $attachmentPayloads[] = $this->attachmentPayload(
                    $attachment,
                );
            }

            $payload[] = [
                'id' => $message->id,
                'message_type' => $messageType->value,
                'author_type' => $authorType->value,
                'body' => $message->body,
                'ai_generated' => $message->ai_generated,
                'ai_badge' => (
                    $authorType === TicketMessageAuthorType::Ai
                    && $messageType === TicketMessageType::PublicReply
                    && $message->ai_generated
                )
                    ? 'AI-generated response'
                    : null,
                'agent_run_id' => $message->agent_run_id,
                'created_at' => $message->created_at?->toISOString(),
                'attachments' => $attachmentPayloads,
            ];
        }

        return $payload;
    }

    /**
     * @return array{
     *     id: int,
     *     original_name: string,
     *     mime_type: string,
     *     extension: string,
     *     size_bytes: int,
     *     text_context_supported: bool
     * }
     */
    public function attachmentPayload(
        TicketAttachment $attachment,
    ): array {
        return [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'extension' => $attachment->extension,
            'size_bytes' => $attachment->size_bytes,
            'text_context_supported' => $this
                ->attachments
                ->supportsContextText($attachment),
        ];
    }
}
