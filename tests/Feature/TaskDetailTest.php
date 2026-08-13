<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\TaskOperatorMessage;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentRunRecorder;
use App\Services\TaskContextCapsuleFactory;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function taskForDetail(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-001',
        'position' => 1,
        'title' => 'Implement the task detail page',
        'objective' => 'Show all task evidence in one view.',
        'acceptance_criteria' => ['The task has a details page.'],
        'scope' => ['dashboard'],
        'constraints' => ['Use the existing stack.'],
        'relevant_paths' => ['app', 'resources/js'],
        'verification_commands' => ['php artisan test --compact'],
        'implementation_prompt' => 'Implement the requested task view.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);
}

test('an agent run records its worker and task attempt', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $worker = AgentWorker::create(['project_id' => $project->id, 'role' => AgentRole::Coder, 'status' => 'idle']);
    $attempt = TaskAttempt::create(['task_id' => $task->id, 'number' => 2, 'status' => 'running', 'started_at' => now()]);

    $run = app(AgentRunRecorder::class)->start($project, AgentRole::Coder, 'Implement the task.', $task, $attempt);

    expect($run->agent_worker_id)->toBe($worker->id)
        ->and($run->attempt_number)->toBe(2)
        ->and($task->auditEvents()->where('event_type', 'agent.execution_started')->firstOrFail()->payload['agent_run_id'])->toBe($run->id);
});

test('an authenticated user can view a task and send an instruction to its coder', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);

    $this->actingAs($user)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where('project.id', $project->id)
            ->where('task.key', 'TASK-001')
            ->has('task.runs', 0)
            ->has('task.operator_messages', 0));

    $this->post(route('projects.tasks.operator-messages.store', [$project, $task]), [
        'recipient_role' => AgentRole::Coder->value,
        'body' => 'Please keep this change within the existing component system.',
    ])->assertRedirect(route('projects.tasks.show', [$project, $task]));

    $message = TaskOperatorMessage::query()->sole();

    expect($message->task_id)->toBe($task->id)
        ->and($message->recipient_role)->toBe(AgentRole::Coder)
        ->and($message->delivered_at)->toBeNull()
        ->and($task->auditEvents()->where('event_type', 'task.operator_message_recorded')->exists())->toBeTrue();
});

test('a task context capsule includes only pending messages for the selected role', function () {
    $user = User::factory()->create();
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    TaskOperatorMessage::create(['task_id' => $task->id, 'user_id' => $user->id, 'recipient_role' => AgentRole::Coder, 'body' => 'Coder instruction']);
    TaskOperatorMessage::create(['task_id' => $task->id, 'user_id' => $user->id, 'recipient_role' => AgentRole::Reviewer, 'body' => 'Reviewer instruction']);
    TaskOperatorMessage::create(['task_id' => $task->id, 'user_id' => $user->id, 'recipient_role' => AgentRole::Coder, 'body' => 'Already delivered', 'delivered_at' => now()]);

    $capsule = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Coder);

    expect($capsule['operator_messages'])->toHaveCount(1)
        ->and($capsule['operator_messages'][0]['body'])->toBe('Coder instruction');
});

test('a task context capsule includes the prior attempt evidence for a fresh retry', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => 'base-sha',
        'head_sha' => 'head-sha',
        'status' => 'failed',
        'validation_results' => ['secret_scan' => false],
        'changed_files' => ['app/Example.php'],
        'log_path' => 'agent-runs/attempt.jsonl',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $capsule = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Coder);

    expect($capsule['previous_attempt'])->toMatchArray([
        'number' => 1,
        'base_sha' => 'base-sha',
        'head_sha' => 'head-sha',
        'status' => 'failed',
        'changed_files' => ['app/Example.php'],
        'failed_validation_evidence' => [],
    ]);
});

test('a task context capsule prioritizes state and excludes unrelated Obsidian notes', function () {
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $projectDirectory = $vault.'/Projects/example';
    File::ensureDirectoryExists($projectDirectory.'/Task Briefs');
    File::ensureDirectoryExists($projectDirectory.'/Specifications');
    File::ensureDirectoryExists($projectDirectory.'/Decisions');
    File::ensureDirectoryExists($projectDirectory.'/Notes');
    File::put($projectDirectory.'/STATE.md', 'The repository is paused until TASK-001 is reviewed.');
    File::put($projectDirectory.'/Task Briefs/TASK-001 - implement-the-task-detail-page.md', 'Use [[Specifications/Task Detail]] and [[Decisions/No New Dependencies]].');
    File::put($projectDirectory.'/Specifications/Task Detail.md', 'The task detail page must retain audit evidence.');
    File::put($projectDirectory.'/Decisions/No New Dependencies.md', 'Keep the current stack.');
    File::put($projectDirectory.'/Notes/unrelated.md', 'UNRELATED NOTE MUST NOT REACH CODEX.');
    File::put($projectDirectory.'/Task Briefs/TASK-000 - prior-work.md', 'PRIOR WORK MUST NOT REACH CODEX.');

    $capsule = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Coder);

    expect(array_keys($capsule['obsidian_project_knowledge']))->toBe([
        'STATE.md',
        'Task Briefs/TASK-001 - implement-the-task-detail-page.md',
        'Specifications/Task Detail.md',
        'Decisions/No New Dependencies.md',
    ])
        ->and($capsule['obsidian_project_knowledge']['STATE.md'])->toBe('The repository is paused until TASK-001 is reviewed.')
        ->and(implode("\n", $capsule['obsidian_project_knowledge']))->not->toContain('UNRELATED NOTE MUST NOT REACH CODEX')
        ->and(implode("\n", $capsule['obsidian_project_knowledge']))->not->toContain('PRIOR WORK MUST NOT REACH CODEX');
});

test('a task context capsule enforces per-note and overall Obsidian budgets', function () {
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    config()->set('aios.obsidian_context_max_note_characters', 10);
    config()->set('aios.obsidian_context_max_characters', 13);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $directory = $vault.'/Projects/example';
    File::ensureDirectoryExists($directory.'/Task Briefs');
    File::put($directory.'/STATE.md', '0123456789-state');
    File::put($directory.'/Task Briefs/TASK-001 - implement-the-task-detail-page.md', 'abcdefghij-task');

    $knowledge = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Coder)['obsidian_project_knowledge'];

    expect($knowledge)->toBe([
        'STATE.md' => '0123456789',
        'Task Briefs/TASK-001 - implement-the-task-detail-page.md' => 'abc',
    ]);
});

test('a task detail includes bounded live agent output', function () {
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'test'), 'started_at' => now()]);

    app(AgentRunRecorder::class)->appendLiveOutput($run, 'stdout', "{\"type\":\"item.started\"}\n");
    app(AgentRunRecorder::class)->appendLiveOutput($run, 'stderr', 'A warning');

    expect($run->refresh()->live_output)->toBe("{\"type\":\"item.started\"}\n[stderr] A warning");
});

test('a completed agent run retains its execution transcript for the task console', function () {
    Storage::fake('local');
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'test'), 'started_at' => now()]);

    app(AgentRunRecorder::class)->complete($run, ['exit_code' => 0, 'output' => '{"type":"item.completed","item":{"type":"agent_message","text":"Implemented the focused fix."}}', 'error_output' => '']);

    expect($run->refresh()->live_output)->toContain('Implemented the focused fix.');
});

test('agent output redacts dotenv values, credentials, and private keys before storage', function () {
    Storage::fake('local');
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'test'), 'started_at' => now()]);
    $message = "APP_KEY=base64:super-secret\nAuthorization: Bearer token-value\n-----BEGIN PRIVATE KEY-----\nprivate-material\n-----END PRIVATE KEY-----";
    $output = json_encode(['type' => 'item.completed', 'item' => ['type' => 'agent_message', 'text' => $message]], JSON_THROW_ON_ERROR);

    app(AgentRunRecorder::class)->complete($run, ['exit_code' => 0, 'output' => $output, 'error_output' => 'GITHUB_TOKEN=another-secret']);

    $storedOutput = Storage::disk('local')->get($run->refresh()->log_path);

    expect($storedOutput)->not->toContain('super-secret')
        ->and($storedOutput)->not->toContain('token-value')
        ->and($storedOutput)->not->toContain('private-material')
        ->and($storedOutput)->not->toContain('another-secret')
        ->and($storedOutput)->toContain('[REDACTED]')
        ->and($run->live_output)->toContain('[REDACTED]')
        ->and($run->result['agent_messages'][0])->toContain('[REDACTED]');
});

test('a completed agent run records Codex execution metadata', function () {
    Storage::fake('local');
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Running, 'prompt_hash' => hash('sha256', 'test'), 'started_at' => now()]);
    $output = implode("\n", [
        '{"type":"thread.started","thread_id":"thread-123"}',
        '{"type":"item.completed","item":{"type":"command_execution","command":"php artisan test","exit_code":0}}',
        '{"type":"item.completed","item":{"type":"file_change","changes":[{"path":"app/Example.php","kind":"update"}]}}',
        '{"type":"item.completed","item":{"type":"agent_message","text":"Implemented the focused fix."}}',
        '{"type":"turn.completed","usage":{"input_tokens":100,"output_tokens":25}}',
    ]);

    app(AgentRunRecorder::class)->complete($run, ['exit_code' => 0, 'output' => $output, 'error_output' => '']);

    expect($run->refresh()->codex_run_id)->toBe('thread-123')
        ->and($run->token_usage)->toBe(125)
        ->and($run->commands)->toBe([['command' => 'php artisan test', 'exit_code' => 0]])
        ->and($run->file_modifications)->toBe([['path' => 'app/Example.php', 'kind' => 'update']])
        ->and($run->result['agent_messages'])->toBe(['Implemented the focused fix.']);
});

test('a completed agent run writes its durable evidence to the audit log', function () {
    Storage::fake('local');
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $run = app(AgentRunRecorder::class)->start($project, AgentRole::Coder, 'Implement the task.', $task);
    $output = implode("\n", [
        '{"type":"thread.started","thread_id":"thread-123"}',
        '{"type":"item.completed","item":{"type":"command_execution","command":"php artisan test","exit_code":0}}',
        '{"type":"item.completed","item":{"type":"file_change","changes":[{"path":"app/Example.php","kind":"update"}]}}',
        '{"type":"turn.completed","usage":{"input_tokens":100,"output_tokens":25}}',
    ]);

    app(AgentRunRecorder::class)->complete($run, ['exit_code' => 0, 'output' => $output, 'error_output' => '']);

    $payload = $task->auditEvents()->where('event_type', 'agent.execution_completed')->value('payload');

    expect($payload)->toMatchArray([
        'agent_run_id' => $run->id,
        'role' => AgentRole::Coder->value,
        'exit_code' => 0,
        'codex_run_id' => 'thread-123',
        'token_usage' => 125,
        'commands' => [['command' => 'php artisan test', 'exit_code' => 0]],
        'file_modifications' => [['path' => 'app/Example.php', 'kind' => 'update']],
    ]);
});

test('an older run can load its transcript from private log storage', function () {
    Storage::fake('local');
    Storage::disk('local')->put('agent-runs/older.jsonl', '{"type":"item.completed","item":{"type":"agent_message","text":"An older result."}}');
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Completed, 'prompt_hash' => hash('sha256', 'test'), 'log_path' => 'agent-runs/older.jsonl', 'started_at' => now(), 'finished_at' => now()]);

    expect(app(AgentRunRecorder::class)->transcript($run))->toContain('An older result.');
});

test('historical task console output is redacted when read', function () {
    Storage::fake('local');
    Storage::disk('local')->put('agent-runs/legacy.jsonl', 'APP_KEY=legacy-secret');
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Paused, 'git_status' => 'clean']);
    $task = taskForDetail($project);
    $run = AgentRun::create(['project_id' => $project->id, 'task_id' => $task->id, 'role' => AgentRole::Coder, 'status' => AgentRunStatus::Completed, 'prompt_hash' => hash('sha256', 'test'), 'live_output' => 'GITHUB_TOKEN=legacy-token', 'log_path' => 'agent-runs/legacy.jsonl', 'started_at' => now(), 'finished_at' => now()]);

    expect(app(AgentRunRecorder::class)->transcript($run))->toBe('GITHUB_TOKEN=[REDACTED]');

    $run->update(['live_output' => null]);

    expect(app(AgentRunRecorder::class)->transcript($run->refresh()))->toBe('APP_KEY=[REDACTED]');
});
