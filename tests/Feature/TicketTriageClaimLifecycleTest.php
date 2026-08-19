<?php

use App\Actions\ClaimTicketForTriage;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Ticket;
use App\Models\TicketTriageAttempt;
use App\ProjectStatus;
use App\Services\StaleWorkerRecovery;
use App\Services\TicketWorkflow;
use App\Services\WorkerHeartbeat;
use App\TicketStatus;
use Illuminate\Database\QueryException;

function p3TicketTriageProject(
    string $name = 'Ticket Triage Project',
): Project {
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir()
            .'/ageax-p3-ticket-triage-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function p3TicketTriageTicket(
    Project $project,
    string $key,
): Ticket {
    return Ticket::factory()
        ->for($project)
        ->create([
            'key' => $key,
            'status' => TicketStatus::Open,
        ]);
}

dataset('pm roadmap precedence statuses', [
    'uploaded',
    'failed',
    'in_progress',
    'processing',
]);

test('claims one open ticket and creates durable triage attempt evidence', function () {
    $project = p3TicketTriageProject();
    $ticket = p3TicketTriageTicket(
        $project,
        'TICKET-000001',
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($attempt)
        ->toBeInstanceOf(TicketTriageAttempt::class)
        ->and($attempt?->ticket_id)
        ->toBe($ticket->id)
        ->and($attempt?->number)
        ->toBe(1)
        ->and($attempt?->status)
        ->toBe('claimed')
        ->and($attempt?->agent_run_id)
        ->toBeNull()
        ->and($attempt?->structured_decision)
        ->toBeNull()
        ->and($attempt?->claimed_at)
        ->not->toBeNull()
        ->and($attempt?->finished_at)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Triaging)
        ->and(
            $project->auditEvents()
                ->where('event_type', 'ticket.claimed')
                ->where(
                    'payload->ticket_id',
                    $ticket->id,
                )
                ->exists(),
        )
        ->toBeTrue();
});

test('project manager lease and ticket claim are idempotent under competing polls', function () {
    $project = p3TicketTriageProject(
        'Concurrent Ticket Triage',
    );

    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'status' => 'idle',
    ]);

    $firstTicket = p3TicketTriageTicket(
        $project,
        'TICKET-000001',
    );

    $secondTicket = p3TicketTriageTicket(
        $project,
        'TICKET-000002',
    );

    $heartbeat = app(WorkerHeartbeat::class);

    $firstLease = $heartbeat->acquire(
        $project,
        AgentRole::ProjectManager,
        fake()->uuid(),
    );

    $secondLease = $heartbeat->acquire(
        $project,
        AgentRole::ProjectManager,
        fake()->uuid(),
    );

    expect($firstLease)->not->toBeNull()
        ->and($secondLease)->toBeNull();

    $firstAttempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    $secondAttempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    if ($firstLease !== null) {
        $heartbeat->release($firstLease);
    }

    expect($firstAttempt)->not->toBeNull()
        ->and($secondAttempt)->toBeNull()
        ->and(TicketTriageAttempt::query()->count())
        ->toBe(1)
        ->and($firstTicket->refresh()->status)
        ->toBe(TicketStatus::Triaging)
        ->and($secondTicket->refresh()->status)
        ->toBe(TicketStatus::Open);
});

test('roadmap work retains precedence over ticket triage', function (
    string $roadmapStatus,
) {
    $project = p3TicketTriageProject(
        "Roadmap Precedence {$roadmapStatus}",
    );

    $ticket = p3TicketTriageTicket(
        $project,
        'TICKET-000001',
    );

    Roadmap::create([
        'project_id' => $project->id,
        'original_filename' => 'roadmap.md',
        'storage_path' => 'roadmaps/roadmap.md',
        'status' => $roadmapStatus,
        'content' => '# Roadmap',
    ]);

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($attempt)->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Open)
        ->and(TicketTriageAttempt::query()->count())
        ->toBe(0);
})->with('pm roadmap precedence statuses');

test('recoverable failed triage creates a fresh numbered attempt', function () {
    $project = p3TicketTriageProject();
    $ticket = p3TicketTriageTicket(
        $project,
        'TICKET-000001',
    );

    $firstAttempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($firstAttempt)->not->toBeNull();

    $firstAttempt?->update([
        'status' => 'failed',
        'finished_at' => now(),
    ]);

    app(TicketWorkflow::class)->transition(
        $ticket->refresh(),
        TicketStatus::Failed,
    );

    $secondAttempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($secondAttempt)->not->toBeNull()
        ->and($secondAttempt?->id)
        ->not->toBe($firstAttempt?->id)
        ->and($secondAttempt?->number)
        ->toBe(2)
        ->and($secondAttempt?->agent_run_id)
        ->toBeNull()
        ->and($secondAttempt?->status)
        ->toBe('claimed')
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Triaging);
});

test('attempt number uniqueness is enforced by the database', function () {
    $project = p3TicketTriageProject();
    $ticket = p3TicketTriageTicket(
        $project,
        'TICKET-000001',
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($attempt)->not->toBeNull()
        ->and(
            fn () => TicketTriageAttempt::create([
                'ticket_id' => $ticket->id,
                'agent_run_id' => null,
                'number' => 1,
                'status' => 'claimed',
                'claimed_at' => now(),
            ]),
        )
        ->toThrow(QueryException::class);
});

test('stale ticket triage interrupts durable run evidence and becomes retryable', function () {
    $project = p3TicketTriageProject(
        'Stale Ticket Triage',
    );

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'status' => 'idle',
    ]);

    $ticket = p3TicketTriageTicket(
        $project,
        'TICKET-000001',
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($attempt)->not->toBeNull();

    $deadLeaseId = fake()->uuid();

    $worker->update([
        'status' => 'working',
        'worker_instance_id' => fake()->uuid(),
        'lease_id' => $deadLeaseId,
        'lease_expires_at' => now()->subMinute(),
        'last_heartbeat_at' => now()->subMinutes(5),
    ]);

    $run = AgentRun::create([
        'project_id' => $project->id,
        'agent_worker_id' => $worker->id,
        'worker_lease_id' => $deadLeaseId,
        'role' => AgentRole::ProjectManager,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash(
            'sha256',
            'ticket-triage',
        ),
        'started_at' => now()->subMinutes(5),
    ]);

    $attempt?->update([
        'agent_run_id' => $run->id,
        'status' => 'running',
        'claimed_at' => now()->subMinutes(5),
    ]);

    $recovered = app(StaleWorkerRecovery::class)
        ->recover($project, 60);

    expect($recovered)
        ->toBeGreaterThan(0)
        ->and($attempt?->refresh()->status)
        ->toBe('interrupted')
        ->and($attempt?->finished_at)
        ->not->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Failed)
        ->and($run->refresh()->status)
        ->toBe(AgentRunStatus::Interrupted)
        ->and($worker->refresh()->status)
        ->toBe('interrupted')
        ->and(
            $project->auditEvents()
                ->where(
                    'event_type',
                    'ticket.triage_interrupted',
                )
                ->exists(),
        )
        ->toBeTrue()
        ->and(
            $project->auditEvents()
                ->where(
                    'event_type',
                    'ticket.triage_retry_scheduled',
                )
                ->exists(),
        )
        ->toBeTrue();

    $retry = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($retry)->not->toBeNull()
        ->and($retry?->number)
        ->toBe(2)
        ->and($retry?->agent_run_id)
        ->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Triaging);
});

test('stale claimed triage remains recoverable after the pm lease has already been released', function () {
    $project = p3TicketTriageProject(
        'Orphaned Ticket Triage',
    );

    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::ProjectManager,
        'status' => 'idle',
        'last_heartbeat_at' => now(),
    ]);

    $ticket = p3TicketTriageTicket(
        $project,
        'TICKET-000001',
    );

    $attempt = app(ClaimTicketForTriage::class)
        ->handle($project);

    expect($attempt)->not->toBeNull();

    $attempt?->update([
        'claimed_at' => now()->subMinutes(5),
    ]);

    $recovered = app(StaleWorkerRecovery::class)
        ->recover($project, 60);

    expect($recovered)
        ->toBeGreaterThan(0)
        ->and($attempt?->refresh()->status)
        ->toBe('interrupted')
        ->and($attempt?->finished_at)
        ->not->toBeNull()
        ->and($ticket->refresh()->status)
        ->toBe(TicketStatus::Failed);
});

test('ticket triage does not introduce a ticket reviewer workflow role', function () {
    expect(
        AgentRole::tryFrom('ticket_reviewer'),
    )->toBeNull();
});
