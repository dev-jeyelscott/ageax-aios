<?php

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\RuntimeRecoveryIncidentFamily;
use App\Services\AuditLogger;
use App\Services\RuntimeExceptionCapture;
use App\Services\RuntimeRecoveryIncidentRecorder;
use App\TaskStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Create a project fixture with the minimum durable fields needed for runtime incident scope.
 */
function runtimeExceptionCaptureProject(string $name = 'Runtime exception capture'): Project
{
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/runtime-exception-capture-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create a task fixture that belongs to the supplied project for trusted scoped-route tests.
 */
function runtimeExceptionCaptureTask(Project $project, string $key = 'P7-002-TEST'): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => ((int) Task::query()->where('project_id', $project->id)->max('position')) + 1,
        'title' => 'Runtime exception capture test task',
        'objective' => 'Exercise trusted runtime exception scope.',
        'acceptance_criteria' => ['Runtime exception evidence is captured safely.'],
        'implementation_prompt' => 'No implementation execution is required for this fixture.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

test('actionable http exceptions are captured with sanitized bounded evidence and stable route identity', function () {
    $messageSecret = 'message-secret-value';
    $requestBodySecret = 'request-body-secret';
    $headerSecret = 'header-secret';
    $cookieSecret = 'cookie-secret';
    $querySecret = 'query-secret';

    Route::post('/_test/runtime-exception', function () use ($messageSecret): never {
        throw new RuntimeException(
            "Runtime persistence failed request_id=550e8400-e29b-41d4-a716-446655440000 api_key={$messageSecret} Authorization: Bearer bearer-secret HOME=/private/home",
        );
    })->name('testing.runtime-exception');

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$headerSecret)
        ->withCookie('runtime_session', $cookieSecret)
        ->postJson(
            '/_test/runtime-exception?token='.$querySecret,
            ['password' => $requestBodySecret],
        );

    $response->assertStatus(500);

    $incident = RecoveryIncident::query()
        ->where('failure_type', RuntimeRecoveryIncidentFamily::ApplicationException->value)
        ->sole();
    $persisted = json_encode([
        'incident' => $incident->toArray(),
        'audit' => AuditEvent::query()
            ->where('event_type', 'recovery.runtime_occurrence_recorded')
            ->get()
            ->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($incident->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($incident->source)->toBe('route:testing.runtime-exception')
        ->and($incident->source)->not->toContain('?')
        ->and($incident->exception_class)->toBe(RuntimeException::class)
        ->and($incident->fingerprint)->toHaveLength(64)
        ->and($incident->occurrence_count)->toBe(1)
        ->and($incident->evidence)->toBeArray()
        ->and($incident->evidence['message'])->toContain('request_id=[ID]')
        ->and($incident->evidence['message'])->toContain('[REDACTED]')
        ->and($incident->evidence['message'])->not->toContain($messageSecret)
        ->and($incident->evidence['message'])->not->toContain('/private/home')
        ->and($incident->evidence['stack'])->toBeArray()
        ->and($incident->evidence['stack'])->not->toBeEmpty();

    expect(count($incident->evidence['stack']))->toBeLessThanOrEqual(8);

    foreach ($incident->evidence['stack'] as $frame) {
        expect($frame)->not->toHaveKey('args')
            ->and($frame)->not->toHaveKey('object')
            ->and($frame['file'])->not->toStartWith('vendor/');
    }

    expect($persisted)->not->toContain($messageSecret)
        ->not->toContain($requestBodySecret)
        ->not->toContain($headerSecret)
        ->not->toContain($cookieSecret)
        ->not->toContain($querySecret)
        ->not->toContain('/private/home');
});

test('expected framework and user caused exceptions are ignored', function () {
    $capture = app(RuntimeExceptionCapture::class);
    $exceptions = [
        new NotFoundHttpException('Missing route.'),
        ValidationException::withMessages(['name' => 'The name field is required.']),
        new AuthorizationException('Forbidden.'),
        new AuthenticationException('Unauthenticated.'),
        new TokenMismatchException('Expired CSRF token.'),
        new BadRequestHttpException('Bad request.'),
        (new ModelNotFoundException)->setModel(Project::class, [999]),
        new class('Expected cancelled operation.') extends RuntimeException implements ShouldntReport {},
    ];

    foreach ($exceptions as $exception) {
        $capture->capture($exception);
    }

    expect(RecoveryIncident::query()->count())->toBe(0);
});

test('trusted route bound project and task models are persisted as runtime incident scope', function () {
    $project = runtimeExceptionCaptureProject();
    $task = runtimeExceptionCaptureTask($project);

    Route::get(
        '/_test/projects/{project}/tasks/{task}/runtime-exception',
        function (Project $project, Task $task): never {
            throw new RuntimeException("Scoped runtime failure for project {$project->id} task {$task->key}.");
        },
    )->middleware(SubstituteBindings::class)->scopeBindings()->name('testing.runtime-scoped');

    $this->get("/_test/projects/{$project->id}/tasks/{$task->id}/runtime-exception")
        ->assertStatus(500);

    $incident = RecoveryIncident::query()->sole();

    expect($incident->source)->toBe('route:testing.runtime-scoped')
        ->and($incident->project_id)->toBe($project->id)
        ->and($incident->task_id)->toBe($task->id);
});

test('raw route identifiers and request values never become project or task scope', function () {
    $project = runtimeExceptionCaptureProject();
    $task = runtimeExceptionCaptureTask($project);

    Route::post(
        '/_test/untrusted/{project}/{task}',
        function (string $project, string $task): never {
            throw new RuntimeException('Untrusted route scope failure code 500.');
        },
    )->name('testing.runtime-untrusted');

    $this->postJson(
        "/_test/untrusted/{$project->id}/{$task->id}",
        ['project_id' => $project->id, 'task_id' => $task->id],
    )->assertStatus(500);

    $incident = RecoveryIncident::query()->sole();

    expect($incident->project_id)->toBeNull()
        ->and($incident->task_id)->toBeNull();
});

test('equivalent exception storms deduplicate while occurrence random identifiers are normalized', function () {
    Route::get('/_test/runtime-storm/{requestId}', function (string $requestId): never {
        throw new RuntimeException("Runtime storm request_id={$requestId} code 500.");
    })->name('testing.runtime-storm');

    $this->get('/_test/runtime-storm/550e8400-e29b-41d4-a716-446655440000')
        ->assertStatus(500);
    $this->get('/_test/runtime-storm/123e4567-e89b-42d3-a456-426614174000')
        ->assertStatus(500);

    $incident = RecoveryIncident::query()->sole();

    expect($incident->occurrence_count)->toBe(2)
        ->and($incident->first_seen_at)->not->toBeNull()
        ->and($incident->last_seen_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('event_type', 'recovery.runtime_occurrence_recorded')->count())->toBe(2);
});

test('materially different failures remain distinct even on the same route and throw site', function () {
    Route::get('/_test/runtime-distinct/{sqlState}', function (string $sqlState): never {
        throw new RuntimeException("Order persistence failed with SQLSTATE {$sqlState}.");
    })->name('testing.runtime-distinct');

    $this->get('/_test/runtime-distinct/23505')->assertStatus(500);
    $this->get('/_test/runtime-distinct/23503')->assertStatus(500);

    expect(RecoveryIncident::query()->count())->toBe(2)
        ->and(RecoveryIncident::query()->pluck('fingerprint')->unique()->count())->toBe(2);
});

test('a terminal matching runtime incident is not reopened when the same exception happens again', function () {
    Route::get('/_test/runtime-terminal', function (): never {
        throw new RuntimeException('Terminal runtime failure code 500.');
    })->name('testing.runtime-terminal');

    $this->get('/_test/runtime-terminal')->assertStatus(500);

    $first = RecoveryIncident::query()->sole();
    $first->update([
        'status' => RecoveryIncidentStatus::Recovered,
        'resolved_at' => now(),
    ]);

    $this->get('/_test/runtime-terminal')->assertStatus(500);

    $incidents = RecoveryIncident::query()->orderBy('id')->get();

    expect($incidents)->toHaveCount(2)
        ->and($incidents[0]->status)->toBe(RecoveryIncidentStatus::Recovered)
        ->and($incidents[0]->occurrence_count)->toBe(1)
        ->and($incidents[1]->status)->toBe(RecoveryIncidentStatus::Detected)
        ->and($incidents[1]->occurrence_count)->toBe(1);
});

test('console exception capture uses only the Laravel command name and never command arguments', function () {
    $input = new ArrayInput([
        'command' => 'aios:recover-workflows',
        '--token' => 'console-secret-token',
    ]);
    $output = new BufferedOutput;

    Event::dispatch(new CommandStarting('aios:recover-workflows', $input, $output));

    try {
        app(RuntimeExceptionCapture::class)->capture(
            new RuntimeException('Scheduled command runtime failure code 500.'),
        );
    } finally {
        Event::dispatch(new CommandFinished('aios:recover-workflows', $input, $output, 1));
    }

    $incident = RecoveryIncident::query()->sole();
    $persisted = json_encode($incident->toArray(), JSON_THROW_ON_ERROR);

    expect($incident->source)->toBe('command:aios:recover-workflows')
        ->and($persisted)->not->toContain('console-secret-token');
});

test('capture service resolution failure cannot replace the original http exception', function () {
    $resolutionAttempts = 0;

    app()->bind(RuntimeExceptionCapture::class, function () use (&$resolutionAttempts): never {
        $resolutionAttempts++;

        throw new RuntimeException('Runtime exception capture resolution failed.');
    });

    Route::get('/_test/runtime-resolution-failure', function (): never {
        throw new HttpException(503, 'Original service unavailable.');
    })->name('testing.runtime-resolution-failure');

    $this->get('/_test/runtime-resolution-failure')
        ->assertStatus(503);

    expect($resolutionAttempts)->toBe(1)
        ->and(RecoveryIncident::query()->count())->toBe(0);
});

test('cache lock failure cannot replace the original http exception', function () {
    Cache::shouldReceive('lock')
        ->once()
        ->andThrow(new RuntimeException('Runtime deduplication cache unavailable.'));

    Route::get('/_test/runtime-cache-lock-failure', function (): never {
        throw new HttpException(503, 'Original service remains unavailable.');
    })->name('testing.runtime-cache-lock-failure');

    $this->get('/_test/runtime-cache-lock-failure')
        ->assertStatus(503);

    expect(RecoveryIncident::query()->count())->toBe(0);
});

test('capture persistence failure cannot replace the original http exception or recursively capture itself', function () {
    $recorder = Mockery::mock(RuntimeRecoveryIncidentRecorder::class);
    $recorder->shouldReceive('record')
        ->once()
        ->andThrow(new RuntimeException('Recovery persistence failed.'));
    app()->instance(RuntimeRecoveryIncidentRecorder::class, $recorder);

    Route::get('/_test/runtime-persistence-failure', function (): never {
        throw new HttpException(503, 'Original service unavailable.');
    })->name('testing.runtime-persistence-failure');

    $this->get('/_test/runtime-persistence-failure')
        ->assertStatus(503);

    expect(RecoveryIncident::query()->count())->toBe(0);
});

test('audit persistence failure rolls back capture and cannot change the original http response', function () {
    $audit = Mockery::mock(AuditLogger::class);
    $audit->shouldReceive('record')
        ->once()
        ->andThrow(new RuntimeException('Audit persistence failed.'));
    app()->instance(AuditLogger::class, $audit);

    Route::get('/_test/runtime-audit-failure', function (): never {
        throw new HttpException(503, 'Original service remains unavailable.');
    })->name('testing.runtime-audit-failure');

    $this->get('/_test/runtime-audit-failure')
        ->assertStatus(503);

    expect(RecoveryIncident::query()->count())->toBe(0);
});
