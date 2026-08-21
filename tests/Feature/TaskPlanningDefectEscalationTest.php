<?php

use App\Actions\RunCoderTask;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskPlanningEscalation;
use App\ProjectStatus;
use App\Services\TaskPlanningDefectPreflight;
use App\Services\TaskPlanningEscalationWorkflow;
use App\TaskStatus;
use Illuminate\Support\Facades\File;

test('unsafe planned verification is blocked and queued once for PM revision before Coder execution', function () {
    $path = sys_get_temp_dir().'/aios-planning-defect-'.fake()->uuid();
    File::ensureDirectoryExists($path);
    $project = Project::create(['name' => 'Planning defect project', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Keep title immutable',
        'objective' => 'Repair a deterministic planning defect.', 'acceptance_criteria' => ['The contract is valid.'],
        'scope' => ['workflow'], 'constraints' => ['No shell expansion.'], 'relevant_paths' => ['app/Services'],
        'verification_commands' => ['ls database/migrations'], 'implementation_prompt' => 'Do not edit task identity.',
        'context_capsule' => ['preserved' => true], 'status' => TaskStatus::Coding,
    ]);

    $attempt = app(RunCoderTask::class)->handle($task);
    $escalation = TaskPlanningEscalation::query()->sole();
    $sameEscalation = app(TaskPlanningEscalationWorkflow::class)->escalate($task->refresh(), app(TaskPlanningDefectPreflight::class)->evaluate($task->refresh()));

    expect($sameEscalation->id)->toBe($escalation->id)
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($task->attempts)->toHaveCount(1)
        ->and($attempt->status)->toBe('failed')
        ->and(AgentRun::query()->whereBelongsTo($task)->count())->toBe(0)
        ->and($escalation->allowed_fields)->toBe(['verification_commands'])
        ->and($escalation->revisionAttempts)->toHaveCount(1)
        ->and(TaskPlanningEscalation::query()->where('task_id', $task->id)->count())->toBe(1);
});
