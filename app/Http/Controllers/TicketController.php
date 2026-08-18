<?php

namespace App\Http\Controllers;

use App\Actions\CreateTicket;
use App\Actions\RecordTicketMessage;
use App\Actions\StoreTicketAttachment;
use App\Http\Requests\IndexTicketsRequest;
use App\Http\Requests\StoreTicketMessageRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\TicketConversation;
use App\TicketCategory;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketPriority;
use App\TicketRequesterCategory;
use App\TicketStatus;
use App\TicketUrgency;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class TicketController extends Controller
{
    public function index(
        IndexTicketsRequest $request,
        Project $project,
    ): Response {
        $filters = $request->validated();
        $query = $project->tickets();

        $status = $filters['status'] ?? null;

        if (is_string($status)) {
            $query->where('status', $status);
        }

        $category = $filters['category'] ?? null;

        if (is_string($category)) {
            $query->where('category', $category);
        }

        $priority = $filters['priority'] ?? null;

        if (is_string($priority)) {
            $query->where('final_priority', $priority);
        }

        $tickets = $query
            ->latest('id')
            ->get();

        return Inertia::render('projects/tickets/index', [
            'project' => $project->only([
                'id',
                'name',
                'path',
            ]),
            'tickets' => $tickets
                ->map(
                    fn (Ticket $ticket): array => $this
                        ->ticketListPayload($ticket),
                )
                ->values(),
            'filters' => [
                'status' => $status,
                'category' => $category,
                'priority' => $priority,
            ],
            'options' => [
                'statuses' => array_map(
                    static fn (TicketStatus $status): string => $status->value,
                    TicketStatus::cases(),
                ),
                'categories' => array_map(
                    static fn (TicketCategory $category): string => $category->value,
                    TicketCategory::cases(),
                ),
                'priorities' => array_map(
                    static fn (TicketPriority $priority): string => $priority->value,
                    TicketPriority::cases(),
                ),
                'requester_categories' => array_map(
                    static fn (TicketRequesterCategory $category): string => $category->value,
                    TicketRequesterCategory::cases(),
                ),
                'requester_urgencies' => array_map(
                    static fn (TicketUrgency $urgency): string => $urgency->value,
                    TicketUrgency::cases(),
                ),
            ],
        ]);
    }

    public function store(
        StoreTicketRequest $request,
        Project $project,
        CreateTicket $createTicket,
        RecordTicketMessage $recordMessage,
        StoreTicketAttachment $storeAttachment,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $description = $this->submissionDescription($validated);
        $requesterUrgency = $validated['requester_urgency'] ?? null;

        $ticket = $createTicket->handle(
            $project,
            $user,
            $request->string('title')->trim()->toString(),
            $description,
            TicketRequesterCategory::from(
                $request->string('requester_category')->toString(),
            ),
            is_string($requesterUrgency)
                ? TicketUrgency::from($requesterUrgency)
                : null,
        );

        $initialMessage = $recordMessage->handle(
            $ticket,
            TicketMessageAuthorType::User,
            TicketMessageType::PublicReply,
            $description,
            $user,
        );

        $files = $request->file('attachments', []);

        if (is_array($files)) {
            foreach ($files as $file) {
                $storeAttachment->handle(
                    $ticket,
                    $file,
                    $user,
                    $initialMessage,
                );
            }
        }

        return to_route(
            'projects.tickets.show',
            [$project, $ticket],
        );
    }

    public function show(
        Project $project,
        Ticket $ticket,
        TicketConversation $conversation,
    ): Response {
        $this->assertProjectTicket($project, $ticket);

        $ticket->loadMissing(
            'convertedTask:id,project_id,key,title,status',
        );

        $convertedTask = $ticket->convertedTask;

        if (
            $convertedTask !== null
            && $convertedTask->project_id !== $project->id
        ) {
            abort(404);
        }

        return Inertia::render('projects/tickets/show', [
            'project' => $project->only([
                'id',
                'name',
                'path',
            ]),
            'ticket' => [
                'id' => $ticket->id,
                'key' => $ticket->key,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'requester_category' => $this->ticketEnumValue(
                    $ticket,
                    'requester_category',
                ),
                'category' => $this->ticketEnumValue($ticket, 'category'),
                'status' => $this->requiredTicketEnumValue(
                    $ticket,
                    'status',
                ),
                'decision' => $this->ticketEnumValue($ticket, 'decision'),
                'requester_urgency' => $this->ticketEnumValue(
                    $ticket,
                    'requester_urgency',
                ),
                'ai_suggested_priority' => $this->ticketEnumValue(
                    $ticket,
                    'ai_suggested_priority',
                ),
                'final_priority' => $this->ticketEnumValue(
                    $ticket,
                    'final_priority',
                ),
                'triage_confidence' => $ticket->triage_confidence !== null
                    ? (float) $ticket->triage_confidence
                    : null,
                'awaiting_response_until' => $this->serializeDate(
                    $ticket->awaiting_response_until,
                ),
                'triaged_at' => $this->serializeDate(
                    $ticket->triaged_at,
                ),
                'closed_at' => $this->serializeDate(
                    $ticket->closed_at,
                ),
                'created_at' => $this->serializeDate(
                    $ticket->created_at,
                ),
                'converted_task' => $convertedTask === null
                    ? null
                    : [
                        'id' => $convertedTask->id,
                        'key' => $convertedTask->key,
                        'title' => $convertedTask->title,
                        'status' => (string) $convertedTask
                            ->getRawOriginal('status'),
                    ],
            ],
            'conversation' => $this->internalConversationPayload(
                $ticket,
                $conversation,
            ),
            'attachments' => $ticket->attachments()
                ->oldest('id')
                ->get()
                ->map(
                    fn (TicketAttachment $attachment): array => $conversation
                        ->attachmentPayload($attachment),
                )
                ->values(),
        ]);
    }

    public function storeMessage(
        StoreTicketMessageRequest $request,
        Project $project,
        Ticket $ticket,
        RecordTicketMessage $recordMessage,
    ): RedirectResponse {
        $this->assertProjectTicket($project, $ticket);

        /** @var User $user */
        $user = $request->user();

        $recordMessage->handle(
            $ticket,
            TicketMessageAuthorType::User,
            TicketMessageType::from(
                $request->string('message_type')->toString(),
            ),
            $request->string('body')->trim()->toString(),
            $user,
        );

        return to_route(
            'projects.tickets.show',
            [$project, $ticket],
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function submissionDescription(array $validated): string
    {
        $sections = [
            'Description' => $validated['description'],
            'Expected behavior' => $validated['expected_behavior'] ?? null,
            'Actual behavior' => $validated['actual_behavior'] ?? null,
            'Reproduction steps' => $validated['reproduction_steps'] ?? null,
            'Environment / version' => $validated['environment_version'] ?? null,
        ];
        $body = [];

        foreach ($sections as $label => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $body[] = $label.":\n".trim($value);
        }

        return implode("\n\n", $body);
    }

    /**
     * @return array{
     *     id: int,
     *     key: string,
     *     title: string,
     *     status: string,
     *     category: ?string,
     *     requester_category: ?string,
     *     requester_urgency: ?string,
     *     ai_suggested_priority: ?string,
     *     final_priority: ?string,
     *     decision: ?string,
     *     awaiting_response_until: ?string,
     *     created_at: ?string
     * }
     */
    private function ticketListPayload(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'key' => $ticket->key,
            'title' => $ticket->title,
            'status' => $this->requiredTicketEnumValue(
                $ticket,
                'status',
            ),
            'category' => $this->ticketEnumValue(
                $ticket,
                'category',
            ),
            'requester_category' => $this->ticketEnumValue(
                $ticket,
                'requester_category',
            ),
            'requester_urgency' => $this->ticketEnumValue(
                $ticket,
                'requester_urgency',
            ),
            'ai_suggested_priority' => $this->ticketEnumValue(
                $ticket,
                'ai_suggested_priority',
            ),
            'final_priority' => $this->ticketEnumValue(
                $ticket,
                'final_priority',
            ),
            'decision' => $this->ticketEnumValue(
                $ticket,
                'decision',
            ),
            'awaiting_response_until' => $this->serializeDate(
                $ticket->awaiting_response_until,
            ),
            'created_at' => $this->serializeDate(
                $ticket->created_at,
            ),
        ];
    }

    /**
     * @return list<array{
     *     id: int,
     *     message_type: string,
     *     author_type: string,
     *     author_name: ?string,
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
    private function internalConversationPayload(
        Ticket $ticket,
        TicketConversation $conversation,
    ): array {
        $messages = $ticket->messages()
            ->with([
                'attachments',
                'user:id,name',
            ])
            ->oldest('id')
            ->get();

        $payload = [];

        foreach ($messages as $message) {
            $authorType = $message->getAttribute('author_type');
            $messageType = $message->getAttribute('message_type');

            if (! $authorType instanceof TicketMessageAuthorType) {
                throw new LogicException(
                    'Ticket message author type is invalid.',
                );
            }

            if (! $messageType instanceof TicketMessageType) {
                throw new LogicException(
                    'Ticket message type is invalid.',
                );
            }

            $attachmentPayloads = [];

            foreach ($message->attachments as $attachment) {
                $attachmentPayloads[] = $conversation
                    ->attachmentPayload($attachment);
            }

            $payload[] = [
                'id' => $message->id,
                'message_type' => $messageType->value,
                'author_type' => $authorType->value,
                'author_name' => $message->user?->name,
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
                'created_at' => $this->serializeDate(
                    $message->created_at,
                ),
                'attachments' => $attachmentPayloads,
            ];
        }

        return $payload;
    }

    private function ticketEnumValue(
        Ticket $ticket,
        string $attribute,
    ): ?string {
        $value = $ticket->getRawOriginal($attribute);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException(
                "Ticket {$attribute} value is invalid.",
            );
        }

        return $value;
    }

    private function requiredTicketEnumValue(
        Ticket $ticket,
        string $attribute,
    ): string {
        return $this->ticketEnumValue($ticket, $attribute)
            ?? throw new LogicException(
                "Ticket {$attribute} value is required.",
            );
    }

    private function assertProjectTicket(
        Project $project,
        Ticket $ticket,
    ): void {
        abort_unless(
            $ticket->project_id === $project->id,
            404,
        );
    }

    private function serializeDate(mixed $value): ?string
    {
        return $value instanceof CarbonInterface
            ? $value->toISOString()
            : null;
    }
}
