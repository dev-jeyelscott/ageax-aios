<?php

use App\Actions\CreateAgentHandoff;
use App\Actions\RunCoderTask;
use App\Actions\RunReviewerTask;
use App\Actions\RunWorkflowRecoveryScan;
use App\AgentHandoffType;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\ReviewStatus;
use App\Services\CodexCliRunner;
use App\Services\RecoveryEngineerRunner;
use App\Services\WorkflowBoundaryHandoffRecorder;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Pest\Laravel\mock;

/**
 * Create one temporary Git repository with a committed baseline for P8-002 Coder/recovery tests.
 */
function p8002GitRepository(): string
{
    $path = sys_get_temp_dir().'/ageax-p8002-'.fake()->uuid();

    File::ensureDirectoryExists($path);
    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run([
        'git',
        'config',
        'user.email',
        'aios@example.test',
    ]);
    Process::path($path)->run([
        'git',
        'config',
        'user.name',
        'AIOS Test',
    ]);
    File::put($path.'/baseline.txt', "baseline\n");
    Process::path($path)->run([
        'git',
        'add',
        'baseline.txt',
    ]);
    Process::path($path)->run([
        'git',
        'commit',
        '-m',
        'Baseline',
    ]);

    return $path;
}

/**
 * Configure one temporary AIOS recovery repository for Recovery Engineer workflow tests.
 */
function p8002RecoveryRepository(): string
{
    $path = p8002GitRepository();

    config()->set('aios.recovery_repository_path', $path);
    config()->set('aios.recovery_validation_commands', []);

    return $path;
}

/**
 * Create one running managed project with a safe local workspace path.
 */
function p8002Project(
    string $name,
    ?string $path = null,
): Project {
    $path ??= sys_get_temp_dir().'/ageax-p8002-project-'.fake()->uuid();

    File::ensureDirectoryExists($path);

    return Project::create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create one workflow Task fixture for the requested durable state.
 */
function p8002Task(
    Project $project,
    TaskStatus $status,
    string $key = 'TASK-001',
    int $position = 1,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => 'Structured collaboration task',
        'objective' => 'Exercise a typed Agent handoff boundary.',
        'acceptance_criteria' => [
            'Workflow authority remains owned by AIOS.',
        ],
        'implementation_prompt' => 'Implement the bounded task safely.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

/**
 * Create one completed TaskAttempt carrying the minimum successful Coder boundary evidence.
 */
function p8002CompletedAttempt(
    Task $task,
    int $number = 1,
    array $changedFiles = [],
    ?string $commitSha = null,
): TaskAttempt {
    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => $number,
        'base_sha' => 'base-sha',
        'head_sha' => $commitSha ?? 'base-sha',
        'commit_sha' => $commitSha,
        'status' => 'completed',
        'validation_results' => [
            'passed' => true,
            'checks' => [
                'task_commit' => true,
            ],
            'evidence' => [
                'task_verification' => [
                    'name' => 'task_verification',
                    'passed' => true,
                    'verification_identifier' => 'task_verification_commands',
                    'exit_code' => 0,
                    'commands' => [],
                    'summary' => null,
                ],
            ],
        ],
        'changed_files' => $changedFiles,
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);
}

/**
 * Create one completed task-scoped AgentRun for a specific workflow role and attempt number.
 */
function p8002CompletedRun(
    Project $project,
    Task $task,
    AgentRole $role,
    ?int $attemptNumber = 1,
    ?RecoveryIncident $incident = null,
): AgentRun {
    return AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'recovery_incident_id' => $incident?->id,
        'role' => $role->value,
        'status' => AgentRunStatus::Completed->value,
        'attempt_number' => $attemptNumber,
        'prompt_hash' => hash('sha256', fake()->uuid()),
        'exit_code' => 0,
        'started_at' => now()->subSecond(),
        'finished_at' => now(),
    ]);
}

/**
 * Return one strict Reviewer changes-required result matching RunReviewerTask's current contract.
 *
 * @return array<string, mixed>
 */
function p8002ChangesRequiredReview(): array
{
    return [
        'outcome' => 'changes_required',
        'summary' => 'A required deterministic check is missing.',
        'findings' => [
            [
                'severity' => 'high',
                'location' => 'app/Example.php',
                'current_implementation' => 'The required check is absent.',
                'expected_implementation' => 'The required check must be present.',
                'why_incorrect' => 'The acceptance criterion is not satisfied.',
                'required_fix' => 'Add the deterministic check.',
                'verification_requirement' => 'Run the focused regression test.',
                'implementation_fix_context' => 'Preserve the existing workflow authority boundary.',
            ],
        ],
    ];
}

/**
 * Encode one Reviewer decision in the Codex JSONL event shape consumed by the existing runner/parser.
 */
function p8002ReviewerProcessOutput(array $review): string
{
    return json_encode([
        'type' => 'item.completed',
        'item' => [
            'type' => 'agent_message',
            'text' => json_encode($review, JSON_THROW_ON_ERROR),
        ],
    ], JSON_THROW_ON_ERROR);
}

test('a successfully validated Coder boundary creates exactly one implementation handoff for Reviewer', function (): void {
    $path = p8002GitRepository();
    $project = p8002Project('P8-002 Coder handoff', $path);
    $task = p8002Task($project, TaskStatus::Coding);

    mock(CodexCliRunner::class)
        ->shouldReceive('runAtPath')
        ->once()
        ->andReturnUsing(function (string $executionPath): array {
            File::ensureDirectoryExists($executionPath.'/app');
            File::put(
                $executionPath.'/app/Example.php',
                "<?php\n\nreturn true;\n",
            );

            return [
                'exit_code' => 0,
                'output' => '{"summary":"Implemented the task."}',
                'error_output' => '',
            ];
        });

    $attempt = app(RunCoderTask::class)->handle($task);
    $sourceRun = AgentRun::query()
        ->whereBelongsTo($task)
        ->where('role', AgentRole::Coder->value)
        ->latest('id')
        ->firstOrFail();
    $handoff = AgentHandoff::query()
        ->where('handoff_type', AgentHandoffType::ImplementationHandoff->value)
        ->firstOrFail();

    expect($task->refresh()->status)
        ->toBe(TaskStatus::ReadyForReview)
        ->and($attempt->refresh()->status)
        ->toBe('completed')
        ->and($attempt->commit_sha)
        ->not->toBeNull()
        ->and($sourceRun->status)
        ->toBe(AgentRunStatus::Completed)
        ->and($handoff->from_agent_run_id)
        ->toBe($sourceRun->id)
        ->and($handoff->from_role)
        ->toBe(AgentRole::Coder)
        ->and($handoff->to_role)
        ->toBe(AgentRole::Reviewer)
        ->and($handoff->task_id)
        ->toBe($task->id)
        ->and($handoff->payload['changed_files'])
        ->toBe(['app/Example.php']);

    $replayed = app(WorkflowBoundaryHandoffRecorder::class)
        ->recordImplementationReady(
            $task->refresh(),
            $attempt->refresh(),
            $sourceRun->refresh(),
        );

    expect($replayed?->id)
        ->toBe($handoff->id)
        ->and(
            AgentHandoff::query()
                ->where('handoff_type', AgentHandoffType::ImplementationHandoff->value)
                ->count(),
        )
        ->toBe(1);
});

test('a valid already-implemented Coder outcome still creates one implementation handoff', function (): void {
    $path = p8002GitRepository();
    $project = p8002Project('P8-002 already implemented', $path);
    $task = p8002Task($project, TaskStatus::Coding);

    mock(CodexCliRunner::class)
        ->shouldReceive('runAtPath')
        ->once()
        ->andReturn([
            'exit_code' => 0,
            'output' => '{"summary":"The repository already satisfies the task."}',
            'error_output' => '',
        ]);

    $attempt = app(RunCoderTask::class)->handle($task);
    $handoff = AgentHandoff::query()
        ->where('handoff_type', AgentHandoffType::ImplementationHandoff->value)
        ->firstOrFail();

    expect($task->refresh()->status)
        ->toBe(TaskStatus::ReadyForReview)
        ->and($attempt->refresh()->status)
        ->toBe('completed')
        ->and($attempt->commit_sha)
        ->toBeNull()
        ->and($attempt->changed_files)
        ->toBe([])
        ->and($handoff->payload['changed_files'])
        ->toBe([])
        ->and($handoff->payload['summary'])
        ->toContain('required no repository changes');
});

test('failed Coder execution creates no implementation handoff', function (): void {
    $path = p8002GitRepository();
    $project = p8002Project('P8-002 failed Coder', $path);
    $task = p8002Task($project, TaskStatus::Coding);

    mock(CodexCliRunner::class)
        ->shouldReceive('runAtPath')
        ->once()
        ->andReturn([
            'exit_code' => 1,
            'output' => '',
            'error_output' => 'Coder execution failed.',
        ]);

    app(RunCoderTask::class)->handle($task);

    expect($task->refresh()->status)
        ->not->toBe(TaskStatus::ReadyForReview)
        ->and(
            AgentHandoff::query()
                ->where('handoff_type', AgentHandoffType::ImplementationHandoff->value)
                ->count(),
        )
        ->toBe(0);
});

test('a failed Coder execution creates no implementation handoff', function (): void {
    $path = p8002GitRepository();
    $project = p8002Project('P8-002 failed execution', $path);
    $task = p8002Task($project, TaskStatus::Coding);

    mock(CodexCliRunner::class)
        ->shouldReceive('runAtPath')
        ->once()
        ->andReturn([
            'exit_code' => 1,
            'output' => '',
            'error_output' => 'Codex execution failed.',
        ]);

    $attempt = app(RunCoderTask::class)->handle($task);

    expect($attempt->refresh()->status)
        ->toBe('failed')
        ->and($attempt->validation_results['passed'])
        ->toBeFalse()
        ->and(
            AgentHandoff::query()
                ->where('handoff_type', AgentHandoffType::ImplementationHandoff->value)
                ->count(),
        )
        ->toBe(0);
});

test('a valid Reviewer changes-required decision creates one review finding handoff for Coder', function (): void {
    $project = p8002Project('P8-002 review finding');
    $task = p8002Task($project, TaskStatus::Reviewing);
    $attempt = p8002CompletedAttempt($task);
    $reviewResult = p8002ChangesRequiredReview();

    Process::fake([
        '*' => Process::result(
            output: p8002ReviewerProcessOutput($reviewResult),
        ),
    ]);

    app(RunReviewerTask::class)->run($task, $attempt);

    $review = $task->reviews()->with('findings')->firstOrFail();
    $handoff = AgentHandoff::query()
        ->where('handoff_type', AgentHandoffType::ReviewFinding->value)
        ->firstOrFail();

    expect($task->refresh()->status)
        ->toBe(TaskStatus::ChangesRequired)
        ->and($review->status)
        ->toBe(ReviewStatus::ChangesRequired)
        ->and($review->findings)
        ->toHaveCount(1)
        ->and($handoff->from_role)
        ->toBe(AgentRole::Reviewer)
        ->and($handoff->to_role)
        ->toBe(AgentRole::Coder)
        ->and($handoff->payload['findings'][0]['required_fix'])
        ->toBe('Add the deterministic check.')
        ->and($handoff->payload['findings'][0]['verification_requirement'])
        ->toBe('Run the focused regression test.');
});

test('Reviewer approval creates no corrective handoff', function (): void {
    $project = p8002Project('P8-002 review approval');
    $task = p8002Task($project, TaskStatus::Reviewing);
    $attempt = p8002CompletedAttempt($task);
    $reviewResult = [
        'outcome' => 'approved',
        'summary' => 'The implementation satisfies every acceptance criterion.',
        'findings' => [],
    ];

    Process::fake([
        '*' => Process::result(
            output: p8002ReviewerProcessOutput($reviewResult),
        ),
    ]);

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($task->refresh()->status)
        ->toBe(TaskStatus::Done)
        ->and(
            AgentHandoff::query()
                ->where('handoff_type', AgentHandoffType::ReviewFinding->value)
                ->count(),
        )
        ->toBe(0);
});

test('malformed Reviewer output creates no review finding handoff', function (): void {
    $project = p8002Project('P8-002 malformed review');
    $task = p8002Task($project, TaskStatus::Reviewing);
    $attempt = p8002CompletedAttempt($task);

    Process::fake([
        '*' => Process::result(
            output: 'Reviewer did not return a structured decision.',
        ),
    ]);

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($task->refresh()->status)
        ->toBe(TaskStatus::ReadyForReview)
        ->and($task->reviews()->count())
        ->toBe(0)
        ->and(
            AgentHandoff::query()
                ->where('handoff_type', AgentHandoffType::ReviewFinding->value)
                ->count(),
        )
        ->toBe(0);
});

test('operational Reviewer failure creates no review finding handoff', function (): void {
    $project = p8002Project('P8-002 operational review failure');
    $task = p8002Task($project, TaskStatus::Reviewing);
    $attempt = p8002CompletedAttempt($task);

    mock(CodexCliRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andThrow(new RuntimeException('Reviewer process failed.'));

    app(RunReviewerTask::class)->run($task, $attempt);

    expect($task->refresh()->status)
        ->toBe(TaskStatus::ReadyForReview)
        ->and($task->reviews()->count())
        ->toBe(0)
        ->and(
            AgentHandoff::query()
                ->where('handoff_type', AgentHandoffType::ReviewFinding->value)
                ->count(),
        )
        ->toBe(0);
});

test('Reviewer decision reconciliation creates the missing handoff from the original completed run without rerunning Reviewer', function (): void {
    $project = p8002Project('P8-002 review reconciliation');
    $task = p8002Task($project, TaskStatus::Reviewing);
    $attempt = p8002CompletedAttempt($task);
    $sourceRun = p8002CompletedRun(
        $project,
        $task,
        AgentRole::Reviewer,
        $attempt->number,
    );
    $review = Review::create([
        'task_id' => $task->id,
        'task_attempt_id' => $attempt->id,
        'status' => ReviewStatus::ChangesRequired,
        'summary' => 'Persisted before an interrupted finalization path.',
        'started_at' => now()->subSecond(),
        'completed_at' => now(),
    ]);
    ReviewFinding::create([
        'review_id' => $review->id,
        'severity' => 'high',
        'location' => 'app/Example.php',
        'current_implementation' => 'The guard is missing.',
        'expected_implementation' => 'The guard is required.',
        'why_incorrect' => 'The acceptance criterion remains unmet.',
        'required_fix' => 'Add the guard.',
        'verification_requirement' => 'Run the focused test.',
        'implementation_fix_context' => 'Do not change workflow ownership.',
    ]);

    Process::fake();

    app(RunReviewerTask::class)->run($task, $attempt);

    $handoff = AgentHandoff::query()
        ->where('handoff_type', AgentHandoffType::ReviewFinding->value)
        ->firstOrFail();

    expect($task->refresh()->status)
        ->toBe(TaskStatus::ChangesRequired)
        ->and($handoff->from_agent_run_id)
        ->toBe($sourceRun->id)
        ->and(AgentRun::query()->whereBelongsTo($task)->count())
        ->toBe(1)
        ->and(
            AgentHandoff::query()
                ->where('handoff_type', AgentHandoffType::ReviewFinding->value)
                ->count(),
        )
        ->toBe(1);

    Process::assertNotRan(fn (): bool => true);
});

test('an accepted task-scoped Recovery Engineer diagnosis creates one recovery advice handoff for the next Coder execution', function (): void {
    p8002RecoveryRepository();

    $project = p8002Project('P8-002 recovery advice');
    $task = p8002Task($project, TaskStatus::Blocked);
    $incident = RecoveryIncident::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'failure_type' => 'task_blocked',
        'status' => RecoveryIncidentStatus::Detected,
        'detected_at' => now(),
    ]);

    mock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn([
            'execution' => [
                'exit_code' => 0,
                'output' => '{}',
                'error_output' => '',
            ],
            'decision' => [
                'root_cause_category' => 'managed_project_defect',
                'root_cause_summary' => 'The managed project implementation should retry in a fresh Coder attempt.',
                'recoverable' => true,
                'fix_applied' => false,
                'changed_files' => [],
                'fix_summary' => null,
                'escalation_reason' => null,
            ],
        ]);

    app(RunWorkflowRecoveryScan::class)->handle($project);

    $processed = $incident->refresh();
    $sourceRun = $processed->recoveryRuns()
        ->where('role', AgentRole::RecoveryEngineer->value)
        ->firstOrFail();
    $handoff = AgentHandoff::query()
        ->where('handoff_type', AgentHandoffType::RecoveryAdvice->value)
        ->firstOrFail();

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Recovered)
        ->and($task->refresh()->status)
        ->toBe(TaskStatus::Queued)
        ->and($sourceRun->status)
        ->toBe(AgentRunStatus::Completed)
        ->and($handoff->from_agent_run_id)
        ->toBe($sourceRun->id)
        ->and($handoff->from_role)
        ->toBe(AgentRole::RecoveryEngineer)
        ->and($handoff->to_role)
        ->toBe(AgentRole::Coder)
        ->and($handoff->payload['root_cause_category'])
        ->toBe('managed_project_defect')
        ->and($handoff->payload['changed_files'])
        ->toBe([]);

    $replayed = app(WorkflowBoundaryHandoffRecorder::class)
        ->recordRecoveryAdvice($processed);

    expect($replayed?->id)
        ->toBe($handoff->id)
        ->and(
            AgentHandoff::query()
                ->where('handoff_type', AgentHandoffType::RecoveryAdvice->value)
                ->count(),
        )
        ->toBe(1);
});

test('unaccepted or unprovable recovery state creates no recovery advice handoff', function (): void {
    $project = p8002Project('P8-002 rejected recovery advice');
    $task = p8002Task($project, TaskStatus::Blocked);
    $incident = RecoveryIncident::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'failure_type' => 'task_blocked',
        'status' => RecoveryIncidentStatus::Escalated,
        'recoverable' => false,
        'root_cause' => 'Operator judgment is required.',
        'root_cause_category' => 'configuration_environment',
        'escalation_reason' => 'Automatic recovery is unsafe.',
        'detected_at' => now()->subMinute(),
        'resolved_at' => now(),
    ]);
    p8002CompletedRun(
        $project,
        $task,
        AgentRole::RecoveryEngineer,
        null,
        $incident,
    );

    $handoff = app(WorkflowBoundaryHandoffRecorder::class)
        ->recordRecoveryAdvice($incident);

    expect($handoff)
        ->toBeNull()
        ->and(
            AgentHandoff::query()
                ->where('handoff_type', AgentHandoffType::RecoveryAdvice->value)
                ->count(),
        )
        ->toBe(0);
});

test('persisting a handoff never transitions tasks claims work schedules Agents or creates a ping pong loop', function (): void {
    $project = p8002Project('P8-002 no authority');
    $readyTask = p8002Task(
        $project,
        TaskStatus::ReadyForReview,
        'TASK-001',
        1,
    );
    $queuedTask = p8002Task(
        $project,
        TaskStatus::Queued,
        'TASK-002',
        2,
    );
    $attempt = p8002CompletedAttempt($readyTask);
    $sourceRun = p8002CompletedRun(
        $project,
        $readyTask,
        AgentRole::Coder,
        $attempt->number,
    );
    AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'status' => 'idle',
    ]);

    $runCount = AgentRun::query()->count();
    $workerCount = AgentWorker::query()->count();

    app(WorkflowBoundaryHandoffRecorder::class)
        ->recordImplementationReady(
            $readyTask,
            $attempt,
            $sourceRun,
        );

    expect($readyTask->refresh()->status)
        ->toBe(TaskStatus::ReadyForReview)
        ->and($queuedTask->refresh()->status)
        ->toBe(TaskStatus::Queued)
        ->and($queuedTask->claimed_at)
        ->toBeNull()
        ->and(AgentRun::query()->count())
        ->toBe($runCount)
        ->and(AgentWorker::query()->count())
        ->toBe($workerCount)
        ->and(AgentHandoff::query()->count())
        ->toBe(1)
        ->and(
            AgentHandoff::query()
                ->where('from_role', AgentRole::Reviewer->value)
                ->where('to_role', AgentRole::Coder->value)
                ->count(),
        )
        ->toBe(0);
});

test('handoff persistence failure cannot replace an already-valid workflow boundary', function (): void {
    $project = p8002Project('P8-002 handoff persistence failure');
    $task = p8002Task($project, TaskStatus::ReadyForReview);
    $attempt = p8002CompletedAttempt($task);
    $sourceRun = p8002CompletedRun(
        $project,
        $task,
        AgentRole::Coder,
        $attempt->number,
    );

    mock(CreateAgentHandoff::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('handoff storage unavailable'));

    $result = app(WorkflowBoundaryHandoffRecorder::class)
        ->recordImplementationReady(
            $task,
            $attempt,
            $sourceRun,
        );

    expect($result)
        ->toBeNull()
        ->and($task->refresh()->status)
        ->toBe(TaskStatus::ReadyForReview)
        ->and($attempt->refresh()->status)
        ->toBe('completed')
        ->and(AgentRun::query()->count())
        ->toBe(1)
        ->and(
            $task->auditEvents()
                ->where('event_type', 'agent_handoff.boundary_recording_failed')
                ->exists(),
        )
        ->toBeTrue();
});
