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

test('a planned verification command cannot reference a missing test file', function () {
    $path = sys_get_temp_dir().'/aios-missing-verification-file-'.fake()->uuid();
    File::ensureDirectoryExists($path.'/tests/Feature');
    $project = Project::create(['name' => 'Missing verification file project', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Repair verification command',
        'objective' => 'Use a test file that exists.', 'acceptance_criteria' => ['Verification can run.'],
        'verification_commands' => ['php artisan test --compact tests/Feature/MissingTest.php'],
        'implementation_prompt' => 'Do not begin implementation.', 'context_capsule' => [], 'status' => TaskStatus::Queued,
    ]);

    expect(app(TaskPlanningDefectPreflight::class)->evaluate($task))
        ->toMatchArray([
            'type' => 'missing_verification_file',
            'evidence' => ['command' => 'php artisan test --compact tests/Feature/MissingTest.php', 'path' => 'tests/Feature/MissingTest.php'],
            'allowed_fields' => ['verification_commands'],
        ]);
});

test('a planned verification command may reference an existing test file', function () {
    $path = sys_get_temp_dir().'/aios-existing-verification-file-'.fake()->uuid();
    File::ensureDirectoryExists($path.'/tests/Feature');
    File::put($path.'/tests/Feature/ExistingTest.php', '<?php');
    $project = Project::create(['name' => 'Existing verification file project', 'path' => $path, 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Keep verification command',
        'objective' => 'Use a test file that exists.', 'acceptance_criteria' => ['Verification can run.'],
        'verification_commands' => ['php artisan test --compact tests/Feature/ExistingTest.php'],
        'implementation_prompt' => 'Do not begin implementation.', 'context_capsule' => [], 'status' => TaskStatus::Queued,
    ]);

    expect(app(TaskPlanningDefectPreflight::class)->evaluate($task))->toBeNull();
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

test('claiming a pending planning revision repairs a manually requeued task before Project Manager execution', function () {
    $project = Project::create(['name' => 'Planning revision repair project', 'path' => sys_get_temp_dir(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $task = Task::create([
        'project_id' => $project->id, 'key' => 'TASK-001', 'position' => 1, 'title' => 'Repair task state',
        'objective' => 'Restore the planning revision state.', 'acceptance_criteria' => ['The revision can be claimed.'],
        'implementation_prompt' => 'Do not begin implementation.', 'context_capsule' => [], 'status' => TaskStatus::ChangesRequired,
    ]);
    $escalation = TaskPlanningEscalation::create([
        'task_id' => $task->id, 'defect_type' => 'missing_verification_file', 'fingerprint' => hash('sha256', 'repair-planning-revision'),
        'failure_evidence' => [], 'allowed_fields' => ['verification_commands'], 'status' => 'pending',
    ]);
    $revision = $escalation->revisionAttempts()->create(['number' => 1, 'status' => 'queued', 'claimed_at' => now()]);

    $claimed = app(TaskPlanningEscalationWorkflow::class)->claim($project);

    expect($claimed?->id)->toBe($revision->id)
        ->and($claimed?->status)->toBe('claimed')
        ->and($task->refresh()->status)->toBe(TaskStatus::Blocked)
        ->and($escalation->refresh()->status)->toBe('running')
        ->and($project->auditEvents()->where('task_id', $task->id)->where('event_type', 'task.planning_escalation_state_repaired')->exists())->toBeTrue();
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

test('planning preflight uses the current phase instead of a stale loaded relationship', function () {
    $project = Project::create(['name' => 'Fresh phase placement project', 'path' => sys_get_temp_dir(), 'status' => ProjectStatus::Running, 'git_status' => 'clean']);
    $dependencyPhase = Phase::create(['project_id' => $project->id, 'position' => 1, 'title' => 'Dependency', 'objective' => 'Provide a prerequisite.']);
    $taskPhase = Phase::create(['project_id' => $project->id, 'position' => 0, 'title' => 'Target', 'objective' => 'Initially misplaced.']);
    $dependency = Task::create([
        'project_id' => $project->id, 'phase_id' => $dependencyPhase->id, 'key' => 'TASK-046', 'position' => 46, 'title' => 'Dependency',
        'objective' => 'Provide a prerequisite.', 'acceptance_criteria' => ['The prerequisite exists.'], 'implementation_prompt' => 'Implement it.', 'context_capsule' => [], 'status' => TaskStatus::Queued,
    ]);
    $task = Task::create([
        'project_id' => $project->id, 'phase_id' => $taskPhase->id, 'key' => 'TASK-058', 'position' => 58, 'title' => 'Target',
        'objective' => 'Use the prerequisite.', 'acceptance_criteria' => ['The dependent behavior works.'], 'implementation_prompt' => 'Implement it.', 'context_capsule' => [], 'status' => TaskStatus::Queued,
    ]);
    $task->dependencies()->attach($dependency);
    $task->load('phase');
    $taskPhase->update(['position' => 2]);

    expect(app(TaskPlanningDefectPreflight::class)->evaluate($task))->toBeNull();
});

test('the planning revision prompt states the verification command allowlist', function () {
    $source = file_get_contents(app_path('Actions/RunTaskPlanningRevision.php'));

    expect($source)
        ->toContain('Never use ls, rg, find, shell pipelines')
        ->toContain('vendor/bin/pest')
        ->toContain('database migration/destructive Artisan commands');
});
