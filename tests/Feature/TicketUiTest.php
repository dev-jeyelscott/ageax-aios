<?php

use App\Actions\RecordTicketMessage;
use App\Actions\StoreTicketAttachment;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\ProjectStatus;
use App\Services\TicketConversation;
use App\TaskStatus;
use App\TicketCategory;
use App\TicketDecision;
use App\TicketMessageAuthorType;
use App\TicketMessageType;
use App\TicketPriority;
use App\TicketRequesterCategory;
use App\TicketStatus;
use App\TicketUrgency;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('local');
});

function ticketUiProject(string $name = 'Ticket UI Project'): Project
{
    return Project::factory()->create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/aios-ticket-ui-'.Str::uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
}

test('ticket routes require authentication', function () {
    $project = ticketUiProject();
    $ticket = Ticket::factory()->create([
        'project_id' => $project->id,
    ]);

    $this->get(route('projects.tickets.index', $project))
        ->assertRedirect(route('login'));

    $this->get(route('projects.tickets.show', [$project, $ticket]))
        ->assertRedirect(route('login'));
});

test('an authenticated user can submit a project ticket with safe attachments', function () {
    $user = User::factory()->create();
    $project = ticketUiProject();

    $response = $this->actingAs($user)->post(
        route('projects.tickets.store', $project),
        [
            'title' => 'Checkout total is incorrect',
            'description' => 'The displayed total differs from the line items.',
            'requester_category' => TicketRequesterCategory::Bug->value,
            'expected_behavior' => 'The total should equal the line item sum.',
            'actual_behavior' => 'The total is higher by 10.00.',
            'reproduction_steps' => "1. Open checkout\n2. Add two items",
            'environment_version' => 'Chrome 151 / staging',
            'requester_urgency' => TicketUrgency::High->value,
            'attachments' => [
                UploadedFile::fake()->createWithContent(
                    'checkout-evidence.txt',
                    'requester supplied evidence',
                ),
            ],
        ],
    );

    $ticket = Ticket::query()->sole();

    $response->assertRedirect(
        route('projects.tickets.show', [$project, $ticket]),
    );

    expect($ticket->project_id)
        ->toBe($project->id)
        ->and($ticket->submitted_by_user_id)
        ->toBe($user->id)
        ->and($ticket->requester_category)
        ->toBe(TicketRequesterCategory::Bug)
        ->and($ticket->requester_urgency)
        ->toBe(TicketUrgency::High)
        ->and($ticket->description)
        ->toContain('Description:')
        ->and($ticket->description)
        ->toContain('Expected behavior:')
        ->and($ticket->description)
        ->toContain('Actual behavior:')
        ->and($ticket->description)
        ->toContain('Reproduction steps:')
        ->and($ticket->description)
        ->toContain('Environment / version:')
        ->and($ticket->messages()->count())
        ->toBe(1)
        ->and($ticket->messages()->firstOrFail()->message_type)
        ->toBe(TicketMessageType::PublicReply)
        ->and($ticket->attachments()->count())
        ->toBe(1)
        ->and($ticket->attachments()->firstOrFail()->ticket_message_id)
        ->toBe($ticket->messages()->firstOrFail()->id);
});

test('ticket submission validation is server authoritative and rejects unsafe uploads before persistence', function () {
    $user = User::factory()->create();
    $project = ticketUiProject();

    $this->actingAs($user)
        ->post(route('projects.tickets.store', $project), [
            'title' => '',
            'description' => 'Request body',
            'requester_category' => 'not-a-category',
            'attachments' => [
                UploadedFile::fake()->create(
                    'payload.php.txt',
                    1,
                    'text/plain',
                ),
            ],
        ])
        ->assertSessionHasErrors([
            'title',
            'requester_category',
            'attachments.0',
        ]);

    expect(Ticket::query()->count())->toBe(0);
});

test('ticket list filtering is project scoped and supports status category and final priority', function () {
    $user = User::factory()->create();
    $project = ticketUiProject('Filtered Project');
    $otherProject = ticketUiProject('Other Project');

    $matching = Ticket::factory()->create([
        'project_id' => $project->id,
        'status' => TicketStatus::Closed,
        'category' => TicketCategory::Bug,
        'final_priority' => TicketPriority::High,
    ]);

    Ticket::factory()->create([
        'project_id' => $project->id,
        'status' => TicketStatus::Open,
        'category' => TicketCategory::Feature,
        'final_priority' => TicketPriority::Low,
    ]);

    Ticket::factory()->create([
        'project_id' => $otherProject->id,
        'status' => TicketStatus::Closed,
        'category' => TicketCategory::Bug,
        'final_priority' => TicketPriority::High,
    ]);

    $this->actingAs($user)
        ->get(route('projects.tickets.index', [
            'project' => $project,
            'status' => TicketStatus::Closed->value,
            'category' => TicketCategory::Bug->value,
            'priority' => TicketPriority::High->value,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tickets/index')
            ->where('project.id', $project->id)
            ->has('tickets', 1)
            ->where('tickets.0.id', $matching->id)
            ->where('filters.status', TicketStatus::Closed->value)
            ->where('filters.category', TicketCategory::Bug->value)
            ->where('filters.priority', TicketPriority::High->value));
});

test('ticket detail renders typed conversation ai attribution attachments linked task and requester deadline', function () {
    $user = User::factory()->create();
    $project = ticketUiProject();
    $ticket = Ticket::factory()->create([
        'project_id' => $project->id,
        'status' => TicketStatus::AwaitingRequester,
        'category' => TicketCategory::Enhancement,
        'decision' => TicketDecision::NeedsInformation,
        'ai_suggested_priority' => TicketPriority::Normal,
        'final_priority' => TicketPriority::High,
        'awaiting_response_until' => now()->addHours(72),
    ]);

    $task = Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-900',
        'position' => 900,
        'title' => 'Implement converted ticket work',
        'objective' => 'Implement the approved ticket.',
        'acceptance_criteria' => ['Approved ticket work is complete.'],
        'scope' => ['ticket'],
        'constraints' => [],
        'relevant_paths' => [],
        'verification_commands' => ['php artisan test --compact'],
        'implementation_prompt' => 'Implement the approved ticket.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);

    $ticket->forceFill([
        'converted_task_id' => $task->id,
    ])->save();

    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'status' => AgentRunStatus::Completed,
        'prompt_hash' => hash('sha256', 'ticket-ui-ai-reply'),
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $messages = app(RecordTicketMessage::class);

    $public = $messages->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::PublicReply,
        'Requester-visible update.',
        $user,
    );

    $messages->handle(
        $ticket,
        TicketMessageAuthorType::User,
        TicketMessageType::InternalNote,
        'Operator-only investigation note.',
        $user,
    );

    $messages->handle(
        $ticket,
        TicketMessageAuthorType::Ai,
        TicketMessageType::PublicReply,
        'AI asks for one more detail.',
        agentRun: $run,
    );

    app(StoreTicketAttachment::class)->handle(
        $ticket,
        UploadedFile::fake()->createWithContent(
            'request.log',
            'safe text evidence',
        ),
        $user,
        $public,
    );

    $this->actingAs($user)
        ->get(route('projects.tickets.show', [$project, $ticket]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tickets/show')
            ->where('project.id', $project->id)
            ->where('ticket.id', $ticket->id)
            ->where('ticket.category', TicketCategory::Enhancement->value)
            ->where('ticket.decision', 'needs_information')
            ->where('ticket.final_priority', TicketPriority::High->value)
            ->where('ticket.converted_task.id', $task->id)
            ->where('ticket.converted_task.key', 'TASK-900')
            ->where(
                'conversation.1.message_type',
                TicketMessageType::InternalNote->value,
            )
            ->where(
                'conversation.1.body',
                'Operator-only investigation note.',
            )
            ->where('conversation.2.ai_badge', 'AI-generated response')
            ->where('conversation.2.agent_run_id', $run->id)
            ->has('attachments', 1)
            ->where('attachments.0.original_name', 'request.log')
            ->missing('attachments.0.storage_path')
            ->missing('attachments.0.storage_disk')
            ->missing('attachments.0.content_hash'));

    $clientSafe = app(TicketConversation::class)
        ->clientSafePayload($ticket);

    expect(collect($clientSafe)->pluck('body'))
        ->toContain('Requester-visible update.')
        ->toContain('AI asks for one more detail.')
        ->not->toContain('Operator-only investigation note.');
});

test('public replies and internal notes are persisted through separate allowed message types', function () {
    $user = User::factory()->create();
    $project = ticketUiProject();
    $ticket = Ticket::factory()->create([
        'project_id' => $project->id,
    ]);

    $this->actingAs($user)
        ->post(
            route('projects.tickets.messages.store', [$project, $ticket]),
            [
                'message_type' => TicketMessageType::PublicReply->value,
                'body' => 'Public operator reply.',
            ],
        )
        ->assertRedirect(
            route('projects.tickets.show', [$project, $ticket]),
        );

    $this->post(
        route('projects.tickets.messages.store', [$project, $ticket]),
        [
            'message_type' => TicketMessageType::InternalNote->value,
            'body' => 'Private operator note.',
        ],
    )->assertRedirect(
        route('projects.tickets.show', [$project, $ticket]),
    );

    $types = $ticket->messages()
        ->orderBy('id')
        ->pluck('message_type')
        ->all();

    expect($types)->toBe([
        TicketMessageType::PublicReply->value,
        TicketMessageType::InternalNote->value,
    ]);

    $this->post(
        route('projects.tickets.messages.store', [$project, $ticket]),
        [
            'message_type' => TicketMessageType::SystemEvent->value,
            'body' => 'User must not create system events.',
        ],
    )->assertSessionHasErrors('message_type');
});

test('cross project ticket detail and message operations return not found', function () {
    $user = User::factory()->create();
    $project = ticketUiProject('First Project');
    $otherProject = ticketUiProject('Second Project');
    $ticket = Ticket::factory()->create([
        'project_id' => $otherProject->id,
    ]);

    $this->actingAs($user)
        ->get(route('projects.tickets.show', [$project, $ticket]))
        ->assertNotFound();

    $this->post(
        route('projects.tickets.messages.store', [$project, $ticket]),
        [
            'message_type' => TicketMessageType::InternalNote->value,
            'body' => 'Must not cross project boundaries.',
        ],
    )->assertNotFound();

    expect($ticket->messages()->count())->toBe(0);
});
