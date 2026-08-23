<?php

use App\Actions\ApplyRoadmapPlan;
use App\AgentRole;
use App\Models\Project;
use App\Models\Task;
use App\ProjectStatus;
use App\Services\AgentRunRecorder;
use App\Services\TaskContextCapsuleFactory;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

test('roadmap persistence creates a compact task brief with validated task data', function () {
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);

    app(ApplyRoadmapPlan::class)->handle($project, [[
        'title' => 'Foundation',
        'objective' => 'Establish the workflow.',
        'tasks' => [[
            'title' => 'Add retrieval',
            'objective' => 'Use compact notes.',
            'acceptance_criteria' => ['Only intentional notes are read.'],
            'constraints' => ['Keep retrieval bounded.'],
            'relevant_paths' => ['app/Services'],
            'verification_commands' => ['php artisan test --compact'],
            'implementation_prompt' => 'Implement the focused retrieval change.',
            'obsidian_notes' => ['Specifications/Retrieval.md'],
        ]],
    ]]);

    $brief = File::get($vault.'/Projects/example/Task Briefs/TASK-001 - add-retrieval.md');

    expect($brief)->toContain('Use compact notes.')
        ->toContain('Only intentional notes are read.')
        ->toContain('[[Specifications/Retrieval.md]]');
});

test('coder retrieval is limited to task brief state and intentional notes within its budget', function () {
    $vault = storage_path('framework/testing/obsidian-'.fake()->uuid());
    config()->set('aios.obsidian_vault_path', $vault);
    config()->set('aios.obsidian_context_max_notes', 4);
    config()->set('aios.obsidian_context_max_characters', 2000);
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = Task::create(['project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Retrieve notes', 'objective' => 'Read the task context.', 'acceptance_criteria' => ['The intended note is available.'], 'implementation_prompt' => 'Implement it.', 'context_capsule' => ['obsidian_notes' => ['Specifications/Intent.md', '../outside.md']], 'status' => TaskStatus::Coding]);
    $directory = $vault.'/Projects/example';
    File::ensureDirectoryExists($directory.'/Task Briefs');
    File::ensureDirectoryExists($directory.'/Specifications');
    File::ensureDirectoryExists($directory.'/Notes');
    File::put($directory.'/STATE.md', 'CURRENT STATE');
    File::put($directory.'/Task Briefs/TASK-001 - retrieve-notes.md', 'CURRENT BRIEF');
    File::put($directory.'/Specifications/Intent.md', 'INTENTIONAL NOTE');
    File::put($directory.'/Notes/unrelated.md', 'UNRELATED NOTE');

    $capsule = app(TaskContextCapsuleFactory::class)->make($task, AgentRole::Coder);

    expect($capsule['obsidian_project_knowledge'])->toBe([
        'Task Briefs/TASK-001 - retrieve-notes.md' => 'CURRENT BRIEF',
        'STATE.md' => 'CURRENT STATE',
        'Specifications/Intent.md' => 'INTENTIONAL NOTE',
    ])
        ->and($capsule['approved_documentation'])->toBe([])
        ->and($capsule['retrieval_manifest'])->toMatchArray([
            'role' => 'coder',
            'selected_note_paths' => ['Task Briefs/TASK-001 - retrieve-notes.md', 'STATE.md', 'Specifications/Intent.md'],
        ])
        ->and(array_column($capsule['retrieval_manifest']['selected_sources'], 'ranking_reason'))->toBe([
            'current_task_brief',
            'current_state',
            'explicit_link',
        ]);
});

test('agent runs retain retrieval manifests and record warning-only token observability', function () {
    Storage::fake('local');
    $project = Project::create(['name' => 'Example', 'path' => '/tmp/example-'.fake()->uuid(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = Task::create(['project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Observe tokens', 'objective' => 'Record usage.', 'acceptance_criteria' => ['Usage is observable.'], 'implementation_prompt' => 'Implement it.', 'context_capsule' => [], 'status' => TaskStatus::Coding]);
    $run = app(AgentRunRecorder::class)->start($project, AgentRole::Coder, 'Prompt', $task, retrievalManifest: ['role' => 'coder', 'selected_note_paths' => ['STATE.md'], 'character_count' => 10, 'retrieval_reason' => 'test']);
    $output = '{"type":"turn.completed","usage":{"input_tokens":150000,"output_tokens":0}}';

    app(AgentRunRecorder::class)->complete($run, ['exit_code' => 0, 'output' => $output, 'error_output' => '']);

    expect($run->refresh()->result['retrieval_manifest']['selected_note_paths'])->toBe(['STATE.md'])
        ->and($task->auditEvents()->where('event_type', 'agent.token_warning')->exists())->toBeTrue()
        ->and($task->auditEvents()->where('event_type', 'agent.execution_completed')->value('payload')['token_observability']['coder']['rolling_average'])->toBe(150000);
});
