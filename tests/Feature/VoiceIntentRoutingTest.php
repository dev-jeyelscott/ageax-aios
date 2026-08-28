<?php

use App\AgentRole;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\ProjectManagerMessage;
use App\Models\Task;
use App\Models\TaskOperatorMessage;
use App\Models\User;
use App\ProjectStatus;
use App\TaskStatus;
use Illuminate\Routing\Route;

/**
 * Create one deterministic project for P9-004 voice intent routing coverage.
 */
function p9004Project(string $name = 'P9-004 Voice Routing'): Project
{
    return Project::create([
        'name' => $name,
        'path' => sys_get_temp_dir()
            .'/ageax-p9004-'
            .fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one project-scoped Task with a stable key for deterministic voice targeting.
 */
function p9004Task(
    Project $project,
    string $key = 'P9004-001',
    int $position = 1,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => "Voice target {$key}",
        'objective' => 'Verify deterministic confirmed voice routing.',
        'acceptance_criteria' => [
            'Only allowlisted voice intents execute.',
        ],
        'implementation_prompt' => 'Route confirmed text through existing AIOS Actions.',
        'context_capsule' => [],
        'status' => TaskStatus::Queued,
    ]);
}

/**
 * Create one verified authenticated operator for protected P9-004 routes.
 */
function p9004User(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
    ]);
}

test('voice intent route remains behind authenticated and verified middleware', function (): void {
    $route = app('router')
        ->getRoutes()
        ->getByName('projects.voice.intents.store');

    expect($route)
        ->toBeInstanceOf(Route::class)
        ->and($route?->methods())
        ->toContain('POST')
        ->and($route?->gatherMiddleware())
        ->toContain('auth')
        ->toContain('verified');
});

test('unconfirmed transcripts cannot execute state changing voice intents', function (): void {
    $project = p9004Project();

    $this
        ->actingAs(p9004User())
        ->postJson(
            route('projects.voice.intents.store', $project),
            [
                'transcript' => 'message project manager: Please inspect the current roadmap.',
                'confirmed' => false,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirmed');

    expect(ProjectManagerMessage::query()->count())->toBe(0)
        ->and(
            AuditEvent::query()
                ->where('event_type', 'voice.action_confirmed')
                ->count(),
        )
        ->toBe(0);
});

test('confirmed project manager intent reuses the existing domain action and records privacy safe audit evidence', function (): void {
    $project = p9004Project();
    $user = p9004User();

    $this
        ->actingAs($user)
        ->postJson(
            route('projects.voice.intents.store', $project),
            [
                'transcript' => 'message project manager: Please inspect the current roadmap.',
                'confirmed' => true,
            ],
        )
        ->assertOk()
        ->assertJsonPath('status', 'executed')
        ->assertJsonPath('intent', 'project_manager_message');

    $message = ProjectManagerMessage::query()->firstOrFail();

    expect($message->project_id)->toBe($project->id)
        ->and($message->user_id)->toBe($user->id)
        ->and($message->body)
        ->toBe('Please inspect the current roadmap.');

    expect(
        AuditEvent::query()
            ->where('event_type', 'project_manager.message_recorded')
            ->where('project_id', $project->id)
            ->exists(),
    )->toBeTrue();

    $voiceAudit = AuditEvent::query()
        ->where('event_type', 'voice.action_confirmed')
        ->where('project_id', $project->id)
        ->firstOrFail();

    expect($voiceAudit->payload['intent'] ?? null)
        ->toBe('project_manager_message')
        ->and($voiceAudit->payload['confirmation'] ?? null)
        ->toBe('explicit')
        ->and(array_key_exists('transcript', $voiceAudit->payload))
        ->toBeFalse();
});

test('confirmed task operator intent resolves only a task inside the selected project', function (): void {
    $project = p9004Project();
    $task = p9004Task($project);
    $user = p9004User();

    $this
        ->actingAs($user)
        ->postJson(
            route('projects.voice.intents.store', $project),
            [
                'transcript' => 'message task P9004-001 reviewer: Please verify the acceptance criteria.',
                'confirmed' => true,
            ],
        )
        ->assertOk()
        ->assertJsonPath('status', 'executed')
        ->assertJsonPath('intent', 'task_operator_message')
        ->assertJsonPath('task.id', $task->id)
        ->assertJsonPath('task.key', $task->key);

    $message = TaskOperatorMessage::query()->firstOrFail();

    expect($message->task_id)->toBe($task->id)
        ->and($message->user_id)->toBe($user->id)
        ->and($message->recipient_role)->toBe(AgentRole::Reviewer)
        ->and($message->body)
        ->toBe('Please verify the acceptance criteria.');

    expect(
        AuditEvent::query()
            ->where('event_type', 'task.operator_message_recorded')
            ->where('task_id', $task->id)
            ->exists(),
    )->toBeTrue();

    expect(
        AuditEvent::query()
            ->where('event_type', 'voice.action_confirmed')
            ->where('task_id', $task->id)
            ->exists(),
    )->toBeTrue();
});

test('cross project task targets fail closed and return the transcript for correction', function (): void {
    $project = p9004Project('Selected project');
    $otherProject = p9004Project('Other project');

    p9004Task(
        $otherProject,
        'OTHER-001',
    );

    $transcript = 'message task OTHER-001 coder: Change this task.';

    $this
        ->actingAs(p9004User())
        ->postJson(
            route('projects.voice.intents.store', $project),
            [
                'transcript' => $transcript,
                'confirmed' => true,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonPath('status', 'needs_correction')
        ->assertJsonPath('editable_transcript', $transcript);

    expect(TaskOperatorMessage::query()->count())->toBe(0)
        ->and(
            AuditEvent::query()
                ->where('event_type', 'voice.action_confirmed')
                ->count(),
        )
        ->toBe(0);
});

test('read only navigation and query intents return only project scoped targets', function (): void {
    $project = p9004Project('Selected project');

    $task = p9004Task(
        $project,
        'P9004-OPEN',
        1,
    );

    $otherProject = p9004Project('Other project');

    p9004Task(
        $otherProject,
        'OTHER-HIDDEN',
        1,
    );

    $user = p9004User();

    $this
        ->actingAs($user)
        ->postJson(
            route('projects.voice.intents.store', $project),
            [
                'transcript' => 'open project',
                'confirmed' => true,
            ],
        )
        ->assertOk()
        ->assertJsonPath('intent', 'open_project')
        ->assertJsonPath(
            'navigation_url',
            route('projects.show', $project),
        );

    $this
        ->actingAs($user)
        ->postJson(
            route('projects.voice.intents.store', $project),
            [
                'transcript' => 'open task P9004-OPEN',
                'confirmed' => true,
            ],
        )
        ->assertOk()
        ->assertJsonPath('intent', 'open_task')
        ->assertJsonPath('task.id', $task->id)
        ->assertJsonPath(
            'navigation_url',
            route(
                'projects.tasks.show',
                [$project, $task],
            ),
        );

    $response = $this
        ->actingAs($user)
        ->postJson(
            route('projects.voice.intents.store', $project),
            [
                'transcript' => 'show tasks',
                'confirmed' => true,
            ],
        )
        ->assertOk()
        ->assertJsonPath('intent', 'list_tasks');

    $taskKeys = collect(
        $response->json('tasks'),
    )
        ->pluck('key')
        ->all();

    expect($taskKeys)
        ->toContain('P9004-OPEN')
        ->not->toContain('OTHER-HIDDEN');
});

test('arbitrary shell agent and workflow commands are rejected without side effects', function (string $transcript): void {
    $project = p9004Project();

    p9004Task($project);

    $this
        ->actingAs(p9004User())
        ->postJson(
            route('projects.voice.intents.store', $project),
            [
                'transcript' => $transcript,
                'confirmed' => true,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonPath('status', 'needs_correction')
        ->assertJsonPath('editable_transcript', $transcript);

    expect(ProjectManagerMessage::query()->count())->toBe(0)
        ->and(TaskOperatorMessage::query()->count())->toBe(0)
        ->and(
            AuditEvent::query()
                ->where('event_type', 'voice.action_confirmed')
                ->count(),
        )
        ->toBe(0);
})->with([
    'shell command' => 'run shell rm -rf /tmp/example',
    'agent execution' => 'run coder on task P9004-001',
    'workflow transition' => 'mark task P9004-001 done',
    'free form tool dispatch' => 'call git status tool',
]);
