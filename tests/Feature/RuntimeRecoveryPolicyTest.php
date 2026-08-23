<?php

use App\AgentRole;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\RuntimeRecoverabilityClassification;
use App\RuntimeRecoveryIncidentFamily;
use App\Services\RecoveryEngineerRunner;
use App\Services\RuntimeRecoveryIncidentRecorder;
use App\Services\StaleWorkerRecovery;
use App\Services\WorkflowRecoveryEngine;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;

/**
 * Create the minimum managed-project fixture required for deterministic runtime recovery policy.
 */
function runtimeRecoveryPolicyProject(string $name = 'Runtime recovery policy'): Project
{
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/runtime-recovery-policy-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Bind a focused test double into the application before WorkflowRecoveryEngine is resolved.
 *
 * @template TClass of object
 *
 * @param  class-string<TClass>  $class
 * @return TClass&MockInterface
 */
function runtimeRecoveryPolicyMock(string $class): MockInterface
{
    $mock = Mockery::mock($class);
    app()->instance($class, $mock);

    return $mock;
}

/**
 * Persist one project-scoped application runtime incident through the real P7-001 recorder.
 */
function runtimeRecoveryApplicationIncident(Project $project, string $source = 'route:projects.runtime'): RecoveryIncident
{
    return app(RuntimeRecoveryIncidentRecorder::class)->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        $source,
        RuntimeException::class,
        'Project runtime operation failed with code 500.',
        project: $project,
        evidence: ['message' => 'Project runtime operation failed with code 500.'],
    );
}

/**
 * Persist the exact allowlisted worker failure shape backed by existing stale-worker recovery.
 */
function runtimeRecoveryExpiredWorkerIncident(Project $project): RecoveryIncident
{
    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'working',
        'worker_instance_id' => fake()->uuid(),
        'lease_id' => fake()->uuid(),
        'last_heartbeat_at' => now()->subMinutes(5),
        'lease_expires_at' => now()->subMinute(),
    ]);

    return app(RuntimeRecoveryIncidentRecorder::class)->record(
        RuntimeRecoveryIncidentFamily::SystemWorkerFailure,
        'worker:expired_lease',
        null,
        'The worker lease expired before execution state was reconciled.',
        project: $project,
        agentWorker: $worker,
        evidence: ['message' => 'The worker lease expired before execution state was reconciled.'],
    );
}

test('a bounded project application exception is classified as candidate ai repair and parked for P7-004 without an agent run', function () {
    $project = runtimeRecoveryPolicyProject();
    $incident = runtimeRecoveryApplicationIncident($project);
    runtimeRecoveryPolicyMock(RecoveryEngineerRunner::class)->shouldNotReceive('run');

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($processed->root_cause_category)->toBe(RuntimeRecoverabilityClassification::CandidateAiRepair->value)
        ->and($processed->recoverable)->toBeTrue()
        ->and($processed->attempt_count)->toBe(0)
        ->and($processed->claim_token)->toBeNull()
        ->and($processed->claimed_at)->toBeNull()
        ->and(AgentRun::query()->where('recovery_incident_id', $incident->id)->count())->toBe(0)
        ->and($project->auditEvents()->where('event_type', 'recovery.runtime_ai_repair_deferred')->exists())->toBeTrue();
});

test('an allowlisted expired worker lease uses the existing deterministic stale-worker recovery path', function () {
    $project = runtimeRecoveryPolicyProject();
    $incident = runtimeRecoveryExpiredWorkerIncident($project);
    runtimeRecoveryPolicyMock(RecoveryEngineerRunner::class)->shouldNotReceive('run');
    runtimeRecoveryPolicyMock(StaleWorkerRecovery::class)
        ->shouldReceive('recover')
        ->once()
        ->withArgs(fn (Project $candidate, int $staleAfterSeconds): bool => $candidate->is($project) && $staleAfterSeconds > 0)
        ->andReturn(1);

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Recovered)
        ->and($processed->root_cause_category)->toBe(RuntimeRecoverabilityClassification::KnownDeterministicRepair->value)
        ->and($processed->attempt_count)->toBe(1)
        ->and($processed->fix_summary)->toContain('Deterministic stale-worker recovery')
        ->and($project->auditEvents()->where('event_type', 'recovery.runtime_deterministic_repair_completed')->exists())->toBeTrue();
});

test('security database and authentication runtime evidence defaults to operator-only escalation', function (string $source, string $exceptionClass, string $message) {
    $project = runtimeRecoveryPolicyProject();
    $incident = app(RuntimeRecoveryIncidentRecorder::class)->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        $source,
        $exceptionClass,
        $message,
        project: $project,
        evidence: ['message' => $message],
    );
    runtimeRecoveryPolicyMock(RecoveryEngineerRunner::class)->shouldNotReceive('run');

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->root_cause_category)->toBe(RuntimeRecoverabilityClassification::OperatorOnly->value)
        ->and($processed->recoverable)->toBeFalse()
        ->and($processed->attempt_count)->toBe(0)
        ->and($processed->escalation_reason)->not->toBeNull()
        ->and(AgentRun::query()->where('recovery_incident_id', $incident->id)->count())->toBe(0);
})->with([
    'security credential boundary' => ['route:projects.runtime', 'App\\Security\\CredentialException', 'Credential validation failed.'],
    'database boundary' => ['route:projects.runtime', QueryException::class, 'Persistence failed.'],
    'authentication boundary' => ['route:auth.session', AuthenticationException::class, 'Authentication state failed.'],
]);

test('a runtime incident without stable fingerprint identity closes as non-actionable without recovery execution', function () {
    $project = runtimeRecoveryPolicyProject();
    $incident = RecoveryIncident::create([
        'project_id' => $project->id,
        'failure_type' => RuntimeRecoveryIncidentFamily::ApplicationException->value,
        'status' => RecoveryIncidentStatus::Detected,
        'detected_at' => now(),
        'evidence' => ['message' => 'Legacy runtime evidence without a stable fingerprint.'],
    ]);
    runtimeRecoveryPolicyMock(RecoveryEngineerRunner::class)->shouldNotReceive('run');

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Recovered)
        ->and($processed->root_cause_category)->toBe(RuntimeRecoverabilityClassification::NonActionable->value)
        ->and($processed->recoverable)->toBeFalse()
        ->and($processed->attempt_count)->toBe(0)
        ->and(AgentRun::query()->where('recovery_incident_id', $incident->id)->count())->toBe(0)
        ->and($project->auditEvents()->where('event_type', 'recovery.runtime_non_actionable')->exists())->toBeTrue();
});

test('repeated deterministic runtime repair failures with unchanged evidence open the circuit breaker before the absolute retry ceiling', function () {
    config()->set('aios.recovery_max_attempts', 5);
    config()->set('aios.no_progress_repeat_threshold', 1);
    $project = runtimeRecoveryPolicyProject();
    $incident = runtimeRecoveryExpiredWorkerIncident($project);
    runtimeRecoveryPolicyMock(StaleWorkerRecovery::class)
        ->shouldReceive('recover')
        ->twice()
        ->andReturn(0);

    $first = app(WorkflowRecoveryEngine::class)->process($incident);
    $second = app(WorkflowRecoveryEngine::class)->process($first);
    $failures = $project->auditEvents()
        ->where('event_type', 'recovery.runtime_attempt_failed')
        ->orderBy('id')
        ->get();

    expect($first->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($first->attempt_count)->toBe(1)
        ->and($second->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($second->attempt_count)->toBe(2)
        ->and($failures)->toHaveCount(2)
        ->and($failures[0]->payload['no_progress']['consecutive_repeat_count'])->toBe(0)
        ->and($failures[1]->payload['no_progress']['consecutive_repeat_count'])->toBe(1)
        ->and($failures[1]->payload['no_progress']['failure_fingerprint'])->toBe($failures[0]->payload['no_progress']['failure_fingerprint'])
        ->and($project->auditEvents()->where('event_type', 'recovery.runtime_circuit_breaker_opened')->exists())->toBeTrue();
});

test('materially changed durable recovery evidence resets the runtime no-progress repeat window', function () {
    config()->set('aios.recovery_max_attempts', 5);
    config()->set('aios.no_progress_repeat_threshold', 1);
    $project = runtimeRecoveryPolicyProject();
    $incident = runtimeRecoveryExpiredWorkerIncident($project);
    runtimeRecoveryPolicyMock(StaleWorkerRecovery::class)
        ->shouldReceive('recover')
        ->twice()
        ->andReturn(0);

    $first = app(WorkflowRecoveryEngine::class)->process($incident);
    $first->update([
        'head_sha' => str_repeat('a', 40),
        'validation_evidence' => [
            'checks' => ['worker_state' => false],
            'evidence' => ['generation' => 2],
        ],
    ]);
    $second = app(WorkflowRecoveryEngine::class)->process($first->fresh());
    $failures = $project->auditEvents()
        ->where('event_type', 'recovery.runtime_attempt_failed')
        ->orderBy('id')
        ->get();

    expect($second->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($second->attempt_count)->toBe(2)
        ->and($failures)->toHaveCount(2)
        ->and($failures[1]->payload['no_progress']['consecutive_repeat_count'])->toBe(0)
        ->and($failures[1]->payload['no_progress']['failure_fingerprint'])->not->toBe($failures[0]->payload['no_progress']['failure_fingerprint'])
        ->and($project->auditEvents()->where('event_type', 'recovery.runtime_circuit_breaker_opened')->exists())->toBeFalse();
});

test('the existing recovery maximum attempt ceiling remains authoritative for runtime repair', function () {
    config()->set('aios.recovery_max_attempts', 1);
    config()->set('aios.no_progress_repeat_threshold', 10);
    $project = runtimeRecoveryPolicyProject();
    $incident = runtimeRecoveryExpiredWorkerIncident($project);
    runtimeRecoveryPolicyMock(StaleWorkerRecovery::class)
        ->shouldReceive('recover')
        ->once()
        ->andReturn(0);

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->attempt_count)->toBe(1)
        ->and($processed->escalation_reason)->toBe('Bounded recovery attempt limit reached.')
        ->and($project->auditEvents()->where('event_type', 'recovery.runtime_circuit_breaker_opened')->exists())->toBeFalse();
});

test('the scheduled recovery pass escalates unscoped runtime incidents instead of leaving them invisible', function () {
    $incident = app(RuntimeRecoveryIncidentRecorder::class)->record(
        RuntimeRecoveryIncidentFamily::ScheduledCommandFailure,
        'command:aios:recover-workflows',
        RuntimeException::class,
        'The scheduled runtime recovery command failed.',
        evidence: ['message' => 'The scheduled runtime recovery command failed.'],
    );

    $exitCode = Artisan::call('aios:recover-workflows');
    $processed = $incident->fresh();

    expect($exitCode)->toBe(0)
        ->and($processed->status)->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->root_cause_category)->toBe(RuntimeRecoverabilityClassification::OperatorOnly->value)
        ->and($processed->attempt_count)->toBe(0);
});
