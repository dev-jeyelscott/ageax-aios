<?php

use App\Actions\ClaimTask;
use App\Actions\CreateTicket;
use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\ProjectStatus;
use App\TicketRequesterCategory;
use App\TicketStatus;
use App\TicketUrgency;
use Illuminate\Database\QueryException;
use LogicException;

function createTicketDomainProject(ProjectStatus $status = ProjectStatus::Paused): Project
{
    return Project::create([
        'name' => 'Ticket Project '.fake()->uuid(),
        'path' => sys_get_temp_dir().'/ageax-ticket-'.fake()->uuid(),
        'status' => $status,
        'git_status' => 'clean',
    ]);
}

function createTicketDomainTask(Project $project, int $position = 1): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-'.str_pad((string) $position, 3, '0', STR_PAD_LEFT),
        'position' => $position,
        'title' => "Task {$position}",
        'objective' => "Implement task {$position}.",
        'acceptance_criteria' => ['It works.'],
        'implementation_prompt' => 'Implement the task.',
        'context_capsule' => [],
        'status' => 'queued',
    ]);
}

test('ticket factory persists project scoped intake separately from tasks', function () {
    $ticket = Ticket::factory()->create();

    expect($ticket->project)->toBeInstanceOf(Project::class)
        ->and($ticket->submittedBy)->toBeInstanceOf(User::class)
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->requester_category)->toBe(TicketRequesterCategory::NotSure)
        ->and($ticket->requester_urgency)->toBe(TicketUrgency::Normal)
        ->and($ticket->project->tickets()->whereKey($ticket->id)->exists())->toBeTrue()
        ->and($ticket->project->tasks()->count())->toBe(0);
});

test('ticket creation assigns deterministic project scoped keys', function () {
    $project = createTicketDomainProject();
    $otherProject = createTicketDomainProject();
    $user = User::factory()->create();
    $createTicket = app(CreateTicket::class);

    $first = $createTicket->handle(
        $project,
        $user,
        'First ticket',
        'First ticket description.',
    );

    $second = $createTicket->handle(
        $project,
        $user,
        'Second ticket',
        'Second ticket description.',
    );

    $otherProjectFirst = $createTicket->handle(
        $otherProject,
        $user,
        'Other project ticket',
        'Other project ticket description.',
    );

    expect($first->key)->toBe('TICKET-001')
        ->and($second->key)->toBe('TICKET-002')
        ->and($otherProjectFirst->key)->toBe('TICKET-001')
        ->and($first->status)->toBe(TicketStatus::Open)
        ->and($first->decision)->toBeNull()
        ->and($first->converted_task_id)->toBeNull();
});

test('ticket keys are unique inside their project', function () {
    $project = createTicketDomainProject();
    $user = User::factory()->create();

    Ticket::factory()->create([
        'project_id' => $project->id,
        'submitted_by_user_id' => $user->id,
        'key' => 'TICKET-001',
    ]);

    expect(fn () => Ticket::factory()->create([
        'project_id' => $project->id,
        'submitted_by_user_id' => $user->id,
        'key' => 'TICKET-001',
    ]))->toThrow(QueryException::class);
});

test('converted task must belong to the ticket project', function () {
    $project = createTicketDomainProject();
    $otherProject = createTicketDomainProject();
    $user = User::factory()->create();

    $ticket = Ticket::factory()->create([
        'project_id' => $project->id,
        'submitted_by_user_id' => $user->id,
    ]);

    $ownTask = createTicketDomainTask($project);
    $otherTask = createTicketDomainTask($otherProject);

    $ticket->forceFill([
        'converted_task_id' => $ownTask->id,
    ])->save();

    expect($ticket->refresh()->convertedTask?->id)->toBe($ownTask->id);

    expect(function () use ($ticket, $otherTask): void {
        $ticket->forceFill([
            'converted_task_id' => $otherTask->id,
        ])->save();
    })->toThrow(LogicException::class);

    expect($ticket->refresh()->converted_task_id)->toBe($ownTask->id);
});

test('ticket project ownership and key are immutable', function () {
    $project = createTicketDomainProject();
    $otherProject = createTicketDomainProject();

    $ticket = Ticket::factory()->create([
        'project_id' => $project->id,
    ]);

    expect(function () use ($ticket, $otherProject): void {
        $ticket->project_id = $otherProject->id;
        $ticket->save();
    })->toThrow(LogicException::class);

    $ticket->refresh();

    expect(function () use ($ticket): void {
        $ticket->key = 'TICKET-999';
        $ticket->save();
    })->toThrow(LogicException::class);

    expect($ticket->refresh()->project_id)->toBe($project->id);
});

test('triage confidence is bounded to zero through one', function () {
    expect(fn () => Ticket::factory()->create([
        'triage_confidence' => 1.001,
    ]))->toThrow(LogicException::class);

    expect(fn () => Ticket::factory()->create([
        'triage_confidence' => -0.001,
    ]))->toThrow(LogicException::class);
});

test('deleting a project cascades tickets including converted task linkage', function () {
    $project = createTicketDomainProject();
    $task = createTicketDomainTask($project);

    $ticket = Ticket::factory()->create([
        'project_id' => $project->id,
    ]);

    $ticket->forceFill([
        'converted_task_id' => $task->id,
    ])->save();

    $project->delete();

    expect(Ticket::query()->find($ticket->id))->toBeNull()
        ->and(Task::query()->find($task->id))->toBeNull();
});

test('unconverted tickets cannot enter coder or reviewer task workflow', function () {
    $project = createTicketDomainProject(ProjectStatus::Running);
    $user = User::factory()->create();

    $ticket = app(CreateTicket::class)->handle(
        $project,
        $user,
        'Ticket only',
        'This remains intake until an explicit future conversion.',
    );

    expect($project->tasks()->count())->toBe(0)
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Coder))->toBeNull()
        ->and(app(ClaimTask::class)->handle($project, AgentRole::Reviewer))->toBeNull()
        ->and($ticket->refresh()->status)->toBe(TicketStatus::Open);
});
