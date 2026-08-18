<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ProjectStatus;
use App\Services\AgentRunRecorder;
use App\TaskStatus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function taskForAgentConsole(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'TASK-900',
        'position' => 900,
        'title' => 'Render normalized agent messages',
        'objective' => 'Render provider-neutral agent messages in the task console.',
        'acceptance_criteria' => ['Codex and Claude Code messages render consistently.'],
        'scope' => ['task detail agent console'],
        'constraints' => ['Preserve raw redacted technical output.'],
        'relevant_paths' => ['app/Http/Controllers/ProjectController.php', 'resources/js/pages/projects/tasks/show.tsx'],
        'verification_commands' => ['php artisan test --compact --filter=TaskDetailAgentConsoleTest'],
        'implementation_prompt' => 'Use AgentRunRecorder as the canonical message normalization boundary.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);
}

test('task detail exposes normalized Codex agent messages while retaining the technical transcript', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Codex Agent Console',
        'path' => '/tmp/codex-agent-console-'.fake()->uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
    $task = taskForAgentConsole($project);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'codex-agent-console'),
        'started_at' => now(),
    ]);
    $output = implode("\n", [
        json_encode([
            'type' => 'item.completed',
            'item' => [
                'type' => 'reasoning',
                'text' => 'Internal reasoning must stay out of normalized agent messages.',
            ],
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'type' => 'item.completed',
            'item' => [
                'type' => 'agent_message',
                'text' => 'Implemented the focused Codex fix.',
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    app(AgentRunRecorder::class)->complete($run, [
        'exit_code' => 0,
        'output' => $output,
        'error_output' => '',
    ]);

    $this->actingAs($user)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where('task.runs.0.id', $run->id)
            ->where('task.runs.0.agent_messages', [
                'Implemented the focused Codex fix.',
            ])
            ->where(
                'task.runs.0.transcript',
                fn ($transcript) => is_string($transcript)
                    && str_contains($transcript, 'Internal reasoning must stay out of normalized agent messages.')
                    && str_contains($transcript, 'Implemented the focused Codex fix.'),
            ));
});

test('task detail exposes normalized Claude Code text while retaining stream-json technical evidence', function () {
    $user = User::factory()->create();
    $project = Project::create([
        'name' => 'Claude Agent Console',
        'path' => '/tmp/claude-agent-console-'.fake()->uuid(),
        'status' => ProjectStatus::Paused,
        'git_status' => 'clean',
    ]);
    $task = taskForAgentConsole($project);
    $run = AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Running,
        'prompt_hash' => hash('sha256', 'claude-agent-console'),
        'started_at' => now(),
    ]);
    $line = json_encode([
        'type' => 'assistant',
        'message' => [
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Reading the failing test before implementing the Claude Code fix.',
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'Read',
                    'input' => ['file_path' => 'tests/Feature/TaskDetailAgentConsoleTest.php'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    app(AgentRunRecorder::class)->appendLiveOutput($run, 'stdout', $line."\n");

    $this->actingAs($user)
        ->get(route('projects.tasks.show', [$project, $task]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/tasks/show')
            ->where('task.runs.0.id', $run->id)
            ->where('task.runs.0.agent_messages', [
                'Reading the failing test before implementing the Claude Code fix.',
            ])
            ->where(
                'task.runs.0.transcript',
                fn ($transcript) => is_string($transcript)
                    && str_contains($transcript, '"type":"assistant"')
                    && str_contains($transcript, '"type":"tool_use"'),
            ));
});

test('task detail console consumes backend normalized messages without adding Claude-specific parsing', function () {
    $source = file_get_contents(resource_path('js/pages/projects/tasks/show.tsx'));

    expect($source)
        ->toBeString()
        ->toContain('agent_messages: string[];')
        ->toContain('formatNormalizedAgentMessages(agentMessages, agentRole)')
        ->toContain('agentMessages={')
        ->toContain('liveRun.agent_messages')
        ->not->toContain("event.type === 'assistant'");
});
