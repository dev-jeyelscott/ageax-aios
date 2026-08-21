<?php

use App\Actions\RunCoderTask;
use App\AgentRole;
use App\Models\AgentRun;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskPlanningEscalation;
use App\ProjectStatus;
use App\Services\TaskPlanningDefectPreflight;
use App\Services\TaskPlanningEscalationWorkflow;
use App\Services\TaskWorkflow;
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

test('an active planning revision prevents the Coder from claiming the task', function () {
    $project = Project::create(['name' => 'Planning revision project', 'path' => sys_get_temp_dir(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Await PM revision',
        'objective' => 'Wait for the PM.', 'acceptance_criteria' => ['The Task remains blocked during revision.'],
        'implementation_prompt' => 'Do not begin implementation.', 'context_capsule' => [], 'status' => TaskStatus::ChangesRequired,
    ]);
    TaskPlanningEscalation::create([
        'task_id' => $task->id, 'defect_type' => 'unsafe_verification_commands', 'fingerprint' => hash('sha256', 'planning-revision'),
        'failure_evidence' => [], 'allowed_fields' => ['verification_commands'], 'status' => 'pending',
    ]);

    $claimed = app(TaskWorkflow::class)->claim($project, AgentRole::Coder);

    expect($claimed)->toBeNull()
        ->and($task->refresh()->status)->toBe(TaskStatus::ChangesRequired);
});

test('an earlier-phase dependency is valid planning input', function () {
    $project = Project::create(['name' => 'Cross-phase dependency project', 'path' => sys_get_temp_dir(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $firstPhase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Foundation', 'objective' => 'Establish prerequisites.']);
    $secondPhase = Phase::create(['project_id' => $project->id, 'position' => 2, 'title' => 'Redemption', 'objective' => 'Deliver redemption behavior.']);
    $dependency = Task::create([
        'project_id' => $project->id, 'phase_id' => $firstPhase->id, 'key' => 'TASK-046', 'position' => 46, 'title' => 'Foundation prerequisite',
        'objective' => 'Complete the prerequisite.', 'acceptance_criteria' => ['The prerequisite is complete.'], 'implementation_prompt' => 'Implement the prerequisite.', 'context_capsule' => [], 'status' => TaskStatus::Queued,
    ]);
    $task = Task::create([
        'project_id' => $project->id, 'phase_id' => $secondPhase->id, 'key' => 'TASK-058', 'position' => 58, 'title' => 'Dependent task',
        'objective' => 'Implement the dependent behavior.', 'acceptance_criteria' => ['The dependent behavior works.'], 'implementation_prompt' => 'Implement the dependent behavior.', 'context_capsule' => [], 'status' => TaskStatus::Queued,
    ]);
    $task->dependencies()->attach($dependency);

    expect(app(TaskPlanningDefectPreflight::class)->evaluate($task->refresh()))->toBeNull();
});

test('the planning revision prompt states the verification command allowlist', function () {
    $source = file_get_contents(app_path('Actions/RunTaskPlanningRevision.php'));

    expect($source)
        ->toContain('Never use ls, rg, find, shell pipelines')
        ->toContain('vendor/bin/pest')
        ->toContain('database migration/destructive Artisan commands');
});
