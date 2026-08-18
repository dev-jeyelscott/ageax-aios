<?php

use App\Actions\RecordTicketMessage;
use App\Actions\StoreTicketAttachment;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\ProjectStatus;
use App\Services\TicketAttachmentStorage;
use App\Services\TicketConversation;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Storage::fake('local');
});

test('safe ticket attachments use generated private storage paths and expose safe metadata only', function () {
    $ticket = Ticket::factory()->create();
    $user = User::factory()->create();

    $file = UploadedFile::fake()
        ->createWithContent(
            'diagnostics.final.txt',
            "requester supplied text\nsecond line",
        );

    $attachment = app(
        StoreTicketAttachment::class,
    )->handle(
        $ticket,
        $file,
        $user,
    );

    $payload = app(TicketConversation::class)
        ->attachmentPayload($attachment);

    Storage::disk('local')
        ->assertExists(
            $attachment->storage_path,
        );

    expect($attachment->ticket_id)
        ->toBe($ticket->id)
        ->and($attachment->uploaded_by_user_id)
        ->toBe($user->id)
        ->and($attachment->storage_disk)
        ->toBe('local')
        ->and($attachment->storage_path)
        ->toStartWith(
            'ticket-attachments/'
                .$ticket->id
                .'/',
        )
        ->and(
            basename(
                $attachment->storage_path,
            ),
        )
        ->not->toBe(
            $file->getClientOriginalName(),
        )
        ->and(
            strlen(
                $attachment->content_hash,
            ),
        )
        ->toBe(64)
        ->and($payload['original_name'])
        ->toBe('diagnostics.final.txt')
        ->and(
            $payload['text_context_supported'],
        )
        ->toBeTrue()
        ->and(
            array_key_exists(
                'storage_disk',
                $payload,
            ),
        )
        ->toBeFalse()
        ->and(
            array_key_exists(
                'storage_path',
                $payload,
            ),
        )
        ->toBeFalse()
        ->and(
            array_key_exists(
                'content_hash',
                $payload,
            ),
        )
        ->toBeFalse();

    $storage = app(
        TicketAttachmentStorage::class,
    );

    $text = $storage
        ->boundedTextContent(
            $attachment,
        );

    $triageEvidence = $storage
        ->triageEvidence(
            $attachment,
        );

    expect($text)
        ->toBe(
            "requester supplied text\nsecond line",
        )
        ->and(
            $triageEvidence[
                'content_is_untrusted'
            ],
        )
        ->toBeTrue()
        ->and(
            $triageEvidence[
                'text_content'
            ],
        )
        ->toBe($text);

    $audit = $ticket->project
        ->auditEvents()
        ->where(
            'event_type',
            'ticket.attachment_stored',
        )
        ->firstOrFail();

    expect(
        $audit->payload[
            'attachment_id'
        ],
    )
        ->toBe($attachment->id)
        ->and(
            json_encode(
                $audit->payload,
            ),
        )
        ->not->toContain(
            'ticket-attachments/',
        )
        ->and(
            json_encode(
                $audit->payload,
            ),
        )
        ->not->toContain(
            'requester supplied text',
        );
});

test('supported ticket attachment text is bounded and binary content is never injected as text', function () {
    $ticket = Ticket::factory()->create();

    $storage = app(
        TicketAttachmentStorage::class,
    );

    $store = app(
        StoreTicketAttachment::class,
    );

    $largeText = str_repeat(
        'a',
        TicketAttachmentStorage::MAX_CONTEXT_TEXT_CHARACTERS
            + 500,
    );

    $textAttachment = $store->handle(
        $ticket,
        UploadedFile::fake()
            ->createWithContent(
                'trace.log',
                $largeText,
            ),
    );

    $pdfAttachment = $store->handle(
        $ticket,
        UploadedFile::fake()->create(
            'evidence.pdf',
            10,
            'application/pdf',
        ),
    );

    $boundedText = $storage
        ->boundedTextContent(
            $textAttachment,
        );

    expect($boundedText)
        ->not->toBeNull()
        ->and(
            mb_strlen(
                $boundedText ?? '',
            ),
        )
        ->toBe(
            TicketAttachmentStorage::MAX_CONTEXT_TEXT_CHARACTERS,
        )
        ->and(
            $storage->supportsContextText(
                $pdfAttachment,
            ),
        )
        ->toBeFalse()
        ->and(
            $storage->boundedTextContent(
                $pdfAttachment,
            ),
        )
        ->toBeNull()
        ->and(
            $storage->triageEvidence(
                $pdfAttachment,
            )['content_is_untrusted'],
        )
        ->toBeTrue()
        ->and(
            $storage->triageEvidence(
                $pdfAttachment,
            )['text_content'],
        )
        ->toBeNull();
});

test('executable script and unsafe double extension uploads are rejected and audited', function () {
    $ticket = Ticket::factory()->create();

    $store = app(
        StoreTicketAttachment::class,
    );

    $unsafeFiles = [
        UploadedFile::fake()->create(
            'payload.php',
            1,
            'text/plain',
        ),
        UploadedFile::fake()->create(
            'payload.php.txt',
            1,
            'text/plain',
        ),
        UploadedFile::fake()->create(
            'run.sh',
            1,
            'text/plain',
        ),
        UploadedFile::fake()->create(
            'vector.svg',
            1,
            'image/svg+xml',
        ),
    ];

    foreach ($unsafeFiles as $file) {
        expect(
            fn () => $store->handle(
                $ticket,
                $file,
            ),
        )->toThrow(
            ValidationException::class,
        );
    }

    expect(
        $ticket->attachments()->count(),
    )
        ->toBe(0)
        ->and(
            $ticket->project
                ->auditEvents()
                ->where(
                    'event_type',
                    'ticket.attachment_rejected',
                )
                ->count(),
        )
        ->toBe(
            count($unsafeFiles),
        );
});

test('ticket attachments cannot resolve inside any managed project repository', function () {
    Storage::disk('local')
        ->makeDirectory('bootstrap');

    $storageRoot = realpath(
        Storage::disk('local')
            ->path(''),
    );

    expect($storageRoot)
        ->not->toBeFalse();

    $project = Project::create([
        'name' => 'Storage Collision Project',
        'path' => $storageRoot,
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);

    $ticket = Ticket::factory()->create([
        'project_id' => $project->id,
    ]);

    expect(
        fn () => app(
            StoreTicketAttachment::class,
        )->handle(
            $ticket,
            UploadedFile::fake()
                ->createWithContent(
                    'safe.txt',
                    'safe text',
                ),
        ),
    )->toThrow(
        RuntimeException::class,
        'managed project repository',
    );

    expect(
        Storage::disk('local')
            ->allFiles(
                'ticket-attachments/'
                    .$ticket->id,
            ),
    )
        ->toBeEmpty()
        ->and(
            $project->auditEvents()
                ->where(
                    'event_type',
                    'ticket.attachment_failed',
                )
                ->where(
                    'payload->reason',
                    'storage_failed',
                )
                ->exists(),
        )
        ->toBeTrue();
});

test('an attachment cannot be associated with a message from another ticket', function () {
    $firstTicket = Ticket::factory()
        ->create();

    $secondTicket = Ticket::factory()
        ->create();

    $user = User::factory()
        ->create();

    $message = app(
        RecordTicketMessage::class,
    )->handle(
        $firstTicket,
        TicketMessageAuthorType::User,
        TicketMessageType::PublicReply,
        'First ticket message.',
        $user,
    );

    expect(
        fn () => app(
            StoreTicketAttachment::class,
        )->handle(
            $secondTicket,
            UploadedFile::fake()
                ->createWithContent(
                    'notes.txt',
                    'notes',
                ),
            $user,
            $message,
        ),
    )->toThrow(
        LogicException::class,
        'same Ticket',
    );

    expect(
        $secondTicket
            ->attachments()
            ->count(),
    )
        ->toBe(0)
        ->and(
            $secondTicket->project
                ->auditEvents()
                ->where(
                    'event_type',
                    'ticket.attachment_rejected',
                )
                ->where(
                    'payload->reason',
                    'message_ticket_mismatch',
                )
                ->exists(),
        )
        ->toBeTrue();
});
