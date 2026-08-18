<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\TicketMessageAuthorType;
use App\TicketMessageType;

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
        return $ticket->messages()
            ->clientVisible()
            ->with('attachments')
            ->oldest('id')
            ->get()
            ->map(
                fn (TicketMessage $message): array => [
                    'id' => $message->id,
                    'message_type' => $message->message_type->value,
                    'author_type' => $message->author_type->value,
                    'body' => $message->body,
                    'ai_generated' => $message->ai_generated,
                    'ai_badge' => (
                        $message->author_type
                            === TicketMessageAuthorType::Ai
                        && $message->message_type
                            === TicketMessageType::PublicReply
                        && $message->ai_generated
                    )
                        ? 'AI-generated response'
                        : null,
                    'agent_run_id' => $message->agent_run_id,
                    'created_at' => $message
                        ->created_at
                        ?->toISOString(),
                    'attachments' => $message
                        ->attachments
                        ->map(
                            fn (
                                TicketAttachment $attachment,
                            ): array => $this
                                ->attachmentPayload(
                                    $attachment,
                                ),
                        )
                        ->values()
                        ->all(),
                ],
            )
            ->values()
            ->all();
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
