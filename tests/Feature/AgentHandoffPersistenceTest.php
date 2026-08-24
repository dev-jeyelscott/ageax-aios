<?php

use App\Actions\CreateAgentHandoff;
use App\AgentHandoffStatus;
use App\AgentHandoffType;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\TaskStatus;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Create one persisted project for P8-001 handoff tests.
 */
function p8001Project(string $name): Project
{
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir().'/ageax-p8001-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one bounded Task inside the supplied project.
 */
function p8001Task(
    Project $project,
    int $position = 1,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => 'P8001-'.fake()->unique()->numberBetween(1000, 9999),
        'position' => $position,
        'title' => 'Agent handoff persistence task',
        'objective' => 'Persist typed collaboration evidence without changing workflow authority.',
        'acceptance_criteria' => [
            'Handoff evidence remains project scoped.',
        ],
        'implementation_prompt' => 'Persist evidence only.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

/**
 * Create one completed durable AgentRun suitable as a handoff source.
 */
function p8001CompletedRun(
    Project $project,
    AgentRole $role,
    ?Task $task = null,
): AgentRun {
    return AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task?->id,
        'role' => $role->value,
        'status' => AgentRunStatus::Completed->value,
        'prompt_hash' => hash(
            'sha256',
            fake()->uuid(),
        ),
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);
}

/**
 * Return one valid schema-version-one payload for every approved handoff type.
 *
 * @return array<string, mixed>
 */
function p8001Payload(
    AgentHandoffType $type,
): array {
    return match ($type) {
        AgentHandoffType::ImplementationHandoff => [
            'summary' => 'Implemented the bounded task and preserved AIOS authority.',
            'changed_files' => [
                'app/Models/Example.php',
            ],
            'tests_added_or_updated' => [
                'tests/Feature/ExampleTest.php',
            ],
            'verification_attempts' => [
                'Focused feature verification completed.',
            ],
            'blockers' => [],
        ],

        AgentHandoffType::ReviewRequest => [
            'summary' => 'Implementation evidence is ready for independent review.',
            'focus_areas' => [
                'Project isolation',
                'Deterministic persistence',
            ],
        ],

        AgentHandoffType::ReviewFinding => [
            'summary' => 'One actionable review finding requires correction.',
            'findings' => [
                [
                    'severity' => 'high',
                    'location' => 'app/Actions/ExampleAction.php',
                    'current_implementation' => 'The current branch accepts unvalidated evidence.',
                    'expected_implementation' => 'AIOS validates evidence before persistence.',
                    'why_incorrect' => 'Unvalidated evidence can weaken the authority boundary.',
                    'required_fix' => 'Validate the bounded structured payload first.',
                    'verification_requirement' => 'Add a regression test for malformed payload rejection.',
                    'implementation_fix_context' => 'Preserve the existing Action-owned mutation boundary.',
                ],
            ],
        ],

        AgentHandoffType::ContextRequest => [
            'request' => 'Provide the latest bounded deterministic validation evidence.',
            'requested_evidence' => [
                'validation summary',
                'changed-file evidence',
            ],
            'reason' => 'The next fresh execution needs evidence without conversation history.',
        ],

        AgentHandoffType::RecoveryAdvice => [
            'summary' => 'The failure appears isolated to the affected application path.',
            'root_cause_category' => 'application_logic',
            'recommended_focus' => 'Inspect the bounded failure path before proposing another repair.',
            'changed_files' => [
                'app/Services/ExampleService.php',
            ],
            'escalation_reason' => null,
        ],

        AgentHandoffType::KnowledgeReference => [
            'evidence_summary' => 'An approved project-local knowledge source is relevant to the next execution.',
            'proposed_change' => null,
            'confidence' => 'medium',
            'references' => [
                'Planning/Implementation Plan.md',
            ],
        ],
    };
}

test('all approved Agent handoff types persist through explicit schema version one contracts', function (): void {
    $project = p8001Project(
        'P8-001 handoff types',
    );

    foreach (AgentHandoffType::cases() as $type) {
        $run = p8001CompletedRun(
            $project,
            AgentRole::Coder,
        );

        $handoff = app(
            CreateAgentHandoff::class,
        )->handle(
            $run,
            AgentRole::Reviewer,
            $type,
            1,
            p8001Payload($type),
        );

        expect($handoff->handoff_type)
            ->toBe($type)
            ->and($handoff->schema_version)
            ->toBe(1);
    }

    expect(
        AgentHandoff::query()->count(),
    )->toBe(
        count(AgentHandoffType::cases()),
    );
});

test('a valid task-scoped handoff derives immutable source scope and relationships', function (): void {
    $project = p8001Project(
        'P8-001 relationships',
    );

    $task = p8001Task($project);

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
        $task,
    );

    $handoff = app(
        CreateAgentHandoff::class,
    )->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ImplementationHandoff,
        1,
        p8001Payload(
            AgentHandoffType::ImplementationHandoff,
        ),
    );

    expect($handoff->project->is($project))
        ->toBeTrue()
        ->and($handoff->task->is($task))
        ->toBeTrue()
        ->and($handoff->sourceRun->is($run))
        ->toBeTrue()
        ->and($handoff->from_role)
        ->toBe(AgentRole::Coder)
        ->and($handoff->to_role)
        ->toBe(AgentRole::Reviewer)
        ->and($handoff->status)
        ->toBe(AgentHandoffStatus::Pending)
        ->and($handoff->consumed_at)
        ->toBeNull()
        ->and($handoff->content_hash)
        ->toMatch('/\A[a-f0-9]{64}\z/')
        ->and(
            $run->fresh()
                ->outgoingHandoffs()
                ->whereKey($handoff->id)
                ->exists(),
        )
        ->toBeTrue();
});

test('source role is derived from the persisted AgentRun instead of caller payload', function (): void {
    $project = p8001Project(
        'P8-001 derived role',
    );

    $run = p8001CompletedRun(
        $project,
        AgentRole::ProjectManager,
    );

    $payload = p8001Payload(
        AgentHandoffType::ContextRequest,
    );

    $handoff = app(
        CreateAgentHandoff::class,
    )->handle(
        $run,
        AgentRole::Coder,
        AgentHandoffType::ContextRequest,
        1,
        $payload,
    );

    expect($handoff->from_role)
        ->toBe(AgentRole::ProjectManager);
});

test('a source AgentRun cannot attach a Task from another project', function (): void {
    $sourceProject = p8001Project(
        'P8-001 source project',
    );

    $foreignProject = p8001Project(
        'P8-001 foreign project',
    );

    $foreignTask = p8001Task(
        $foreignProject,
    );

    $run = AgentRun::create([
        'project_id' => $sourceProject->id,
        'task_id' => $foreignTask->id,
        'role' => AgentRole::Coder->value,
        'status' => AgentRunStatus::Completed->value,
        'prompt_hash' => hash(
            'sha256',
            fake()->uuid(),
        ),
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);

    expect(
        fn () => app(
            CreateAgentHandoff::class,
        )->handle(
            $run,
            AgentRole::Reviewer,
            AgentHandoffType::ImplementationHandoff,
            1,
            p8001Payload(
                AgentHandoffType::ImplementationHandoff,
            ),
        ),
    )->toThrow(
        LogicException::class,
        'Agent handoff source Task cannot cross the source AgentRun project boundary.',
    );

    expect(
        AgentHandoff::query()->count(),
    )->toBe(0);
});

test('unfinished source runs cannot create handoffs', function (): void {
    $project = p8001Project(
        'P8-001 unfinished source',
    );

    $run = AgentRun::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder->value,
        'status' => AgentRunStatus::Running->value,
        'prompt_hash' => hash(
            'sha256',
            fake()->uuid(),
        ),
        'started_at' => now(),
    ]);

    expect(
        fn () => app(
            CreateAgentHandoff::class,
        )->handle(
            $run,
            AgentRole::Reviewer,
            AgentHandoffType::ReviewRequest,
            1,
            p8001Payload(
                AgentHandoffType::ReviewRequest,
            ),
        ),
    )->toThrow(
        LogicException::class,
        'Agent handoffs require a completed source AgentRun.',
    );
});

test('invalid target roles handoff types schema versions and authority fields are rejected', function (): void {
    $project = p8001Project(
        'P8-001 validation',
    );

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
    );

    $action = app(
        CreateAgentHandoff::class,
    );

    expect(
        fn () => $action->handle(
            $run,
            'root',
            AgentHandoffType::ReviewRequest,
            1,
            p8001Payload(
                AgentHandoffType::ReviewRequest,
            ),
        ),
    )->toThrow(
        ValidationException::class,
    );

    expect(
        fn () => $action->handle(
            $run,
            AgentRole::Reviewer,
            'free_form_chat',
            1,
            p8001Payload(
                AgentHandoffType::ReviewRequest,
            ),
        ),
    )->toThrow(
        ValidationException::class,
    );

    expect(
        fn () => $action->handle(
            $run,
            AgentRole::Reviewer,
            AgentHandoffType::ReviewRequest,
            99,
            p8001Payload(
                AgentHandoffType::ReviewRequest,
            ),
        ),
    )->toThrow(
        ValidationException::class,
    );

    $payload = p8001Payload(
        AgentHandoffType::ReviewRequest,
    );

    $payload['transition'] = 'done';

    expect(
        fn () => $action->handle(
            $run,
            AgentRole::Reviewer,
            AgentHandoffType::ReviewRequest,
            1,
            $payload,
        ),
    )->toThrow(
        ValidationException::class,
    );

    expect(
        AgentHandoff::query()->count(),
    )->toBe(0);
});

test('sensitive text is sanitized before the handoff becomes durable evidence', function (): void {
    $project = p8001Project(
        'P8-001 sanitization',
    );

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
    );

    $payload = p8001Payload(
        AgentHandoffType::ReviewRequest,
    );

    $payload['summary']
        = 'Authorization: Bearer super-secret-token-value';

    $handoff = app(
        CreateAgentHandoff::class,
    )->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ReviewRequest,
        1,
        $payload,
    );

    expect(
        json_encode(
            $handoff->payload,
            JSON_THROW_ON_ERROR,
        ),
    )
        ->not->toContain(
            'super-secret-token-value',
        )
        ->toContain(
            '[REDACTED]',
        );
});

test('associative key order cannot evade deterministic duplicate detection', function (): void {
    $project = p8001Project(
        'P8-001 canonical duplicate',
    );

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
    );

    $firstPayload = [
        'summary' => 'Review this deterministic evidence.',
        'focus_areas' => [
            'authorization',
            'project isolation',
        ],
    ];

    $secondPayload = [
        'focus_areas' => [
            'authorization',
            'project isolation',
        ],
        'summary' => 'Review this deterministic evidence.',
    ];

    $action = app(
        CreateAgentHandoff::class,
    );

    $first = $action->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ReviewRequest,
        1,
        $firstPayload,
    );

    $second = $action->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ReviewRequest,
        1,
        $secondPayload,
    );

    expect($second->id)
        ->toBe($first->id)
        ->and($second->content_hash)
        ->toBe($first->content_hash)
        ->and(
            AgentHandoff::query()->count(),
        )
        ->toBe(1);
});

test('meaningfully different evidence produces another fingerprint', function (): void {
    $project = p8001Project(
        'P8-001 meaningful difference',
    );

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
    );

    $action = app(
        CreateAgentHandoff::class,
    );

    $first = $action->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ReviewRequest,
        1,
        [
            'summary' => 'Review evidence version one.',
            'focus_areas' => [
                'project isolation',
            ],
        ],
    );

    $second = $action->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ReviewRequest,
        1,
        [
            'summary' => 'Review evidence version two.',
            'focus_areas' => [
                'project isolation',
            ],
        ],
    );

    expect($second->id)
        ->not->toBe($first->id)
        ->and($second->content_hash)
        ->not->toBe($first->content_hash)
        ->and(
            AgentHandoff::query()->count(),
        )
        ->toBe(2);
});

test('the database independently rejects a duplicate project fingerprint', function (): void {
    $project = p8001Project(
        'P8-001 database duplicate',
    );

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
    );

    $handoff = app(
        CreateAgentHandoff::class,
    )->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ReviewRequest,
        1,
        p8001Payload(
            AgentHandoffType::ReviewRequest,
        ),
    );

    expect(
        fn () => AgentHandoff::create([
            'project_id' => $project->id,
            'task_id' => null,
            'from_agent_run_id' => $run->id,
            'from_role' => AgentRole::Coder->value,
            'to_role' => AgentRole::Reviewer->value,
            'handoff_type' => AgentHandoffType::ReviewRequest->value,
            'schema_version' => 1,
            'payload' => $handoff->payload,
            'content_hash' => $handoff->content_hash,
            'status' => AgentHandoffStatus::Pending->value,
        ]),
    )->toThrow(
        QueryException::class,
    );
});

test('repeated creation is idempotent through the locked source run', function (): void {
    $project = p8001Project(
        'P8-001 idempotency',
    );

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
    );

    $action = app(
        CreateAgentHandoff::class,
    );

    $first = $action->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ImplementationHandoff,
        1,
        p8001Payload(
            AgentHandoffType::ImplementationHandoff,
        ),
    );

    $second = $action->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ImplementationHandoff,
        1,
        p8001Payload(
            AgentHandoffType::ImplementationHandoff,
        ),
    );

    expect($second->id)
        ->toBe($first->id)
        ->and(
            AgentHandoff::query()->count(),
        )
        ->toBe(1);
});

test('handoff creation emits bounded audit evidence without copying the handoff payload', function (): void {
    $project = p8001Project(
        'P8-001 audit',
    );

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
    );

    $uniquePayloadMarker
        = 'handoff-payload-'.fake()->uuid();

    $payload = p8001Payload(
        AgentHandoffType::ReviewRequest,
    );

    $payload['summary']
        = $uniquePayloadMarker;

    $handoff = app(
        CreateAgentHandoff::class,
    )->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ReviewRequest,
        1,
        $payload,
    );

    $event = AuditEvent::query()
        ->where('event_type', 'agent_handoff.created')
        ->latest('id')
        ->firstOrFail();

    $auditPayload = $event->getAttribute(
        'payload',
    );

    expect($auditPayload)
        ->toBeArray()
        ->and($auditPayload['agent_handoff_id'])
        ->toBe($handoff->id)
        ->and($auditPayload['content_hash'])
        ->toBe($handoff->content_hash)
        ->and(
            json_encode(
                $auditPayload,
                JSON_THROW_ON_ERROR,
            ),
        )
        ->not->toContain(
            $uniquePayloadMarker,
        );
});

test('creating a handoff cannot mutate workflow repository or source execution state', function (): void {
    $project = p8001Project(
        'P8-001 authority isolation',
    );

    $project->update([
        'git_head_sha' => str_repeat('a', 40),
        'git_status' => 'clean',
    ]);

    $task = p8001Task($project);

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
        $task,
    );

    $taskStatusBefore = $task->getRawOriginal(
        'status',
    );

    $gitStatusBefore = $project->git_status;
    $gitHeadBefore = $project->git_head_sha;

    $runCountBefore = AgentRun::query()
        ->count();

    app(
        CreateAgentHandoff::class,
    )->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ImplementationHandoff,
        1,
        p8001Payload(
            AgentHandoffType::ImplementationHandoff,
        ),
    );

    expect(
        $task->fresh()->getRawOriginal('status'),
    )
        ->toBe($taskStatusBefore)
        ->and($project->fresh()->git_status)
        ->toBe($gitStatusBefore)
        ->and($project->fresh()->git_head_sha)
        ->toBe($gitHeadBefore)
        ->and(
            AgentRun::query()->count(),
        )
        ->toBe($runCountBefore);
});

test('durable handoff evidence cannot be rewritten after persistence', function (): void {
    $project = p8001Project(
        'P8-001 immutable evidence',
    );

    $run = p8001CompletedRun(
        $project,
        AgentRole::Coder,
    );

    $handoff = app(
        CreateAgentHandoff::class,
    )->handle(
        $run,
        AgentRole::Reviewer,
        AgentHandoffType::ReviewRequest,
        1,
        p8001Payload(
            AgentHandoffType::ReviewRequest,
        ),
    );

    expect(
        fn () => $handoff->update([
            'payload' => [
                'summary' => 'Rewritten evidence.',
            ],
        ]),
    )->toThrow(
        LogicException::class,
        'Agent handoff evidence field [payload] is immutable.',
    );
});
