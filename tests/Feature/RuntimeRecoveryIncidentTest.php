<?php

use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\RuntimeRecoveryIncidentFamily;
use App\Services\RuntimeRecoveryIncidentRecorder;
use App\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

function runtimeRecoveryProject(string $name = 'Runtime recovery'): Project
{
    return Project::create([
        'name' => $name,
        'path' => '/tmp/runtime-recovery-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

function runtimeRecoveryTask(Project $project, string $key): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => ((int) Task::query()->where('project_id', $project->id)->max('position')) + 1,
        'title' => 'Runtime recovery scope',
        'objective' => 'Provide a durable runtime recovery scope.',
        'acceptance_criteria' => ['Runtime recovery remains deterministic.'],
        'implementation_prompt' => 'No implementation run is required for this fixture.',
        'context_capsule' => [],
        'status' => TaskStatus::Blocked,
    ]);
}

test('all approved runtime incident families can be recorded', function (RuntimeRecoveryIncidentFamily $family) {
    $project = runtimeRecoveryProject($family->value);

    $incident = app(RuntimeRecoveryIncidentRecorder::class)->record(
        family: $family,
        source: 'system:test-source',
        exceptionClass: null,
        failureSummary: 'Runtime failure code 500.',
        project: $project,
        occurredAt: CarbonImmutable::parse('2026-08-23T14:00:00+08:00'),
    );

    expect($incident->failure_type)->toBe($family->value)
        ->and($incident->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($incident->occurrence_count)->toBe(1)
        ->and($incident->fingerprint)->toHaveLength(64)
        ->and($incident->first_seen_at)->not->toBeNull()
        ->and($incident->last_seen_at)->not->toBeNull();
})->with([
    'application exception' => [RuntimeRecoveryIncidentFamily::ApplicationException],
    'scheduled command failure' => [RuntimeRecoveryIncidentFamily::ScheduledCommandFailure],
    'system worker failure' => [RuntimeRecoveryIncidentFamily::SystemWorkerFailure],
]);

test('legacy workflow recovery rows remain valid with additive runtime metadata', function () {
    $project = runtimeRecoveryProject();

    $incident = RecoveryIncident::create([
        'project_id' => $project->id,
        'failure_type' => 'expired_worker_lease',
        'status' => RecoveryIncidentStatus::Recovered,
        'detected_at' => now(),
        'resolved_at' => now(),
    ])->refresh();

    expect($incident->fingerprint)->toBeNull()
        ->and($incident->source)->toBeNull()
        ->and($incident->exception_class)->toBeNull()
        ->and($incident->occurrence_count)->toBe(1)
        ->and($incident->first_seen_at)->toBeNull()
        ->and($incident->last_seen_at)->toBeNull();
});

test('timestamps and occurrence-random identifiers do not change the fingerprint', function () {
    $firstProject = runtimeRecoveryProject('First random identity');
    $secondProject = runtimeRecoveryProject('Second random identity');
    $recorder = app(RuntimeRecoveryIncidentRecorder::class);

    $first = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:projects.show',
        RuntimeException::class,
        'Failure code 500 at 2026-08-23T14:00:00+08:00 request_id=550e8400-e29b-41d4-a716-446655440000 trace_id=01ARZ3NDEKTSV4RRFFQ69G5FAV.',
        project: $firstProject,
    );
    $second = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:projects.show',
        RuntimeException::class,
        'Failure code 500 at 2026-08-24T09:30:15Z request_id=123e4567-e89b-42d3-a456-426614174000 trace_id=01BX5ZZKBKACTAV9WEVGEMMVRZ.',
        project: $secondProject,
    );

    expect($first->fingerprint)->toBe($second->fingerprint);
});

test('secret values are excluded before fingerprinting', function () {
    $firstProject = runtimeRecoveryProject('First secret');
    $secondProject = runtimeRecoveryProject('Second secret');
    $recorder = app(RuntimeRecoveryIncidentRecorder::class);

    $first = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:payments.store',
        RuntimeException::class,
        "Payment failed code 401. password=alpha-secret\nAuthorization: Bearer alpha-token",
        project: $firstProject,
    );
    $second = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:payments.store',
        RuntimeException::class,
        "Payment failed code 401. password=beta-secret\nAuthorization: Bearer beta-token",
        project: $secondProject,
    );

    expect($first->fingerprint)->toBe($second->fingerprint);
});

test('materially different stable failure identity produces different fingerprints', function () {
    $project = runtimeRecoveryProject();
    $recorder = app(RuntimeRecoveryIncidentRecorder::class);

    $baseline = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:orders.store',
        RuntimeException::class,
        'Order persistence failed with SQLSTATE 23505.',
        project: $project,
    );
    $differentSource = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:orders.update',
        RuntimeException::class,
        'Order persistence failed with SQLSTATE 23505.',
        project: $project,
    );
    $differentClass = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:orders.store',
        LogicException::class,
        'Order persistence failed with SQLSTATE 23505.',
        project: $project,
    );
    $differentFailure = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:orders.store',
        RuntimeException::class,
        'Order persistence failed with SQLSTATE 23503.',
        project: $project,
    );

    expect([
        $baseline->fingerprint,
        $differentSource->fingerprint,
        $differentClass->fingerprint,
        $differentFailure->fingerprint,
    ])->toHaveCount(4)
        ->and(array_unique([
            $baseline->fingerprint,
            $differentSource->fingerprint,
            $differentClass->fingerprint,
            $differentFailure->fingerprint,
        ]))->toHaveCount(4);
});

test('runtime incident deduplication locks are stable within a scope and isolated across scopes', function () {
    $project = runtimeRecoveryProject('Atomic lock scope');
    $task = runtimeRecoveryTask($project, 'LOCK-001');
    $recorder = app(RuntimeRecoveryIncidentRecorder::class);
    $lockKeys = [];

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')
        ->times(4)
        ->with(1, Mockery::type(Closure::class))
        ->andReturnUsing(
            static fn (int $seconds, callable $callback): mixed => $callback(),
        );

    Cache::shouldReceive('lock')
        ->times(4)
        ->withArgs(function (string $key, int $seconds) use (&$lockKeys): bool {
            $lockKeys[] = $key;

            return str_starts_with($key, 'runtime-recovery:')
                && $seconds === 10;
        })
        ->andReturn($lock);

    $firstUnscoped = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:health',
        RuntimeException::class,
        'Health check failed code 503.',
    );
    $secondUnscoped = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:health',
        RuntimeException::class,
        'Health check failed code 503.',
    );
    $projectScoped = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:health',
        RuntimeException::class,
        'Health check failed code 503.',
        project: $project,
    );
    $taskScoped = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:health',
        RuntimeException::class,
        'Health check failed code 503.',
        task: $task,
    );

    expect($lockKeys)->toHaveCount(4)
        ->and($lockKeys[0])->toBe($lockKeys[1])
        ->and($lockKeys[2])->not->toBe($lockKeys[0])
        ->and($lockKeys[3])->not->toBe($lockKeys[0])
        ->and($lockKeys[3])->not->toBe($lockKeys[2])
        ->and($secondUnscoped->id)->toBe($firstUnscoped->id)
        ->and($secondUnscoped->occurrence_count)->toBe(2)
        ->and($projectScoped->id)->not->toBe($firstUnscoped->id)
        ->and($taskScoped->id)->not->toBe($projectScoped->id)
        ->and(RecoveryIncident::query()->count())->toBe(3);
});

test('repeated active failures update one incident and audit every occurrence', function () {
    $project = runtimeRecoveryProject();
    $recorder = app(RuntimeRecoveryIncidentRecorder::class);
    $firstSeenAt = CarbonImmutable::parse('2026-08-23T10:00:00+08:00');
    $lastSeenAt = CarbonImmutable::parse('2026-08-23T10:05:00+08:00');

    $first = $recorder->record(
        RuntimeRecoveryIncidentFamily::ScheduledCommandFailure,
        'command:aios:recover-workflows',
        RuntimeException::class,
        'Command failed with exit code 1.',
        project: $project,
        occurredAt: $firstSeenAt,
    );
    $second = $recorder->record(
        RuntimeRecoveryIncidentFamily::ScheduledCommandFailure,
        'command:aios:recover-workflows',
        RuntimeException::class,
        'Command failed with exit code 1.',
        project: $project,
        occurredAt: $lastSeenAt,
    );

    expect($second->id)->toBe($first->id)
        ->and(RecoveryIncident::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and($second->occurrence_count)->toBe(2)
        ->and($second->detected_at?->equalTo($firstSeenAt))->toBeTrue()
        ->and($second->first_seen_at?->equalTo($firstSeenAt))->toBeTrue()
        ->and($second->last_seen_at?->equalTo($lastSeenAt))->toBeTrue()
        ->and($project->auditEvents()->where('event_type', 'recovery.runtime_occurrence_recorded')->count())->toBe(2);
});

test('a matching terminal incident is never reopened or rewritten', function () {
    $project = runtimeRecoveryProject();
    $recorder = app(RuntimeRecoveryIncidentRecorder::class);

    $first = $recorder->record(
        RuntimeRecoveryIncidentFamily::SystemWorkerFailure,
        'worker:coder',
        RuntimeException::class,
        'Worker exited with code 137.',
        project: $project,
    );
    $first->update([
        'status' => RecoveryIncidentStatus::Recovered,
        'resolved_at' => now(),
    ]);

    $second = $recorder->record(
        RuntimeRecoveryIncidentFamily::SystemWorkerFailure,
        'worker:coder',
        RuntimeException::class,
        'Worker exited with code 137.',
        project: $project,
    );

    expect($second->id)->not->toBe($first->id)
        ->and(RecoveryIncident::query()->where('project_id', $project->id)->count())->toBe(2)
        ->and($first->refresh()->status)->toBe(RecoveryIncidentStatus::Recovered)
        ->and($first->occurrence_count)->toBe(1)
        ->and($second->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($second->occurrence_count)->toBe(1);
});

test('project and task scope prevent unrelated failures from being merged', function () {
    $firstProject = runtimeRecoveryProject('First scope');
    $secondProject = runtimeRecoveryProject('Second scope');
    $firstTask = runtimeRecoveryTask($firstProject, 'TASK-001');
    $secondTask = runtimeRecoveryTask($firstProject, 'TASK-002');
    $recorder = app(RuntimeRecoveryIncidentRecorder::class);

    $firstProjectIncident = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:health',
        RuntimeException::class,
        'Health check failed code 503.',
        project: $firstProject,
    );
    $secondProjectIncident = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:health',
        RuntimeException::class,
        'Health check failed code 503.',
        project: $secondProject,
    );
    $firstTaskIncident = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:health',
        RuntimeException::class,
        'Health check failed code 503.',
        task: $firstTask,
    );
    $secondTaskIncident = $recorder->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:health',
        RuntimeException::class,
        'Health check failed code 503.',
        task: $secondTask,
    );

    expect(array_unique([
        $firstProjectIncident->id,
        $secondProjectIncident->id,
        $firstTaskIncident->id,
        $secondTaskIncident->id,
    ]))->toHaveCount(4)
        ->and($firstProjectIncident->fingerprint)->toBe($secondProjectIncident->fingerprint)
        ->and($firstTaskIncident->fingerprint)->toBe($secondTaskIncident->fingerprint);
});

test('raw secrets are absent from persisted incident and audit evidence', function () {
    $project = runtimeRecoveryProject();
    $secret = 'super-sensitive-runtime-secret';

    $incident = app(RuntimeRecoveryIncidentRecorder::class)->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:payments.store',
        RuntimeException::class,
        "Payment failed code 401. api_key={$secret}",
        project: $project,
    );

    $persisted = json_encode([
        'incident' => $incident->toArray(),
        'audit' => $project->auditEvents()
            ->where('event_type', 'recovery.runtime_occurrence_recorded')
            ->get()
            ->map(fn ($event): array => $event->toArray())
            ->all(),
    ], JSON_THROW_ON_ERROR);

    expect($persisted)->not->toContain($secret)
        ->and($incident->evidence)->toBeNull();
});

test('recording a runtime incident remains passive until recovery processing is invoked', function () {
    $project = runtimeRecoveryProject();

    $incident = app(RuntimeRecoveryIncidentRecorder::class)->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:projects.index',
        RuntimeException::class,
        'Application exception code 500.',
        project: $project,
    );

    expect($incident->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($incident->attempt_count)->toBe(0)
        ->and($incident->claim_token)->toBeNull()
        ->and($incident->claimed_at)->toBeNull()
        ->and($incident->recoveryRuns()->exists())->toBeFalse()
        ->and($project->auditEvents()->where('event_type', 'recovery.fix_committed')->exists())->toBeFalse()
        ->and($project->auditEvents()->where('event_type', 'recovery.recovered')->exists())->toBeFalse();
});
