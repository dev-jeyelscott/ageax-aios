<?php

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\RecoveryIncident;
use App\Models\Task;
use App\ProjectStatus;
use App\RecoveryIncidentStatus;
use App\RuntimeRecoverabilityClassification;
use App\RuntimeRecoveryIncidentFamily;
use App\Services\RecoveryEngineerRunner;
use App\Services\RecoveryWorktreeManager;
use App\Services\RuntimeRecoveryIncidentRecorder;
use App\Services\WorkflowRecoveryEngine;
use App\TaskStatus;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;

/**
 * Create a clean temporary Git repository representing the live AIOS recovery repository.
 */
function runtimeExecutionRepository(): string
{
    $path = sys_get_temp_dir().'/aios-runtime-recovery-'.fake()->uuid();

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

    config()->set('aios.recovery_repository_path', $path);
    config()->set('aios.recovery_validation_commands', []);

    return $path;
}

/**
 * Create the managed-project scope required for candidate AI repair.
 */
function runtimeExecutionProject(): Project
{
    return Project::create([
        'name' => 'Runtime execution project',
        'path' => sys_get_temp_dir().'/runtime-managed-project-'.fake()->uuid(),
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
    ]);
}

/**
 * Create an optional Task reference used to prove runtime repair never mutates Task workflow state.
 */
function runtimeExecutionTask(Project $project): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => 'RUNTIME-001',
        'position' => 1,
        'title' => 'Runtime incident source task',
        'objective' => 'Remain unchanged by runtime recovery.',
        'acceptance_criteria' => [
            'Runtime recovery does not alter Task workflow state.',
        ],
        'implementation_prompt' => 'No workflow transition.',
        'context_capsule' => [],
        'status' => TaskStatus::Failed,
    ]);
}

/**
 * Persist one bounded project-scoped application runtime incident.
 *
 * @param  array<string, mixed>  $evidence
 */
function runtimeExecutionIncident(
    Project $project,
    ?Task $task = null,
    array $evidence = [],
): RecoveryIncident {
    return app(RuntimeRecoveryIncidentRecorder::class)->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:projects.runtime',
        RuntimeException::class,
        'Project runtime operation failed with code 500.',
        project: $project,
        task: $task,
        evidence: [
            'message' => 'Project runtime operation failed with code 500.',
            ...$evidence,
        ],
    );
}

/**
 * Bind a focused runtime execution test double before resolving the recovery engine.
 *
 * @template TClass of object
 *
 * @param  class-string<TClass>  $class
 * @return TClass&MockInterface
 */
function runtimeExecutionMock(string $class): MockInterface
{
    $mock = Mockery::mock($class);

    app()->instance($class, $mock);

    return $mock;
}

test('an eligible runtime incident uses a fresh isolated Recovery Engineer run and AIOS commits the exact validated fix', function () {
    $repositoryPath = runtimeExecutionRepository();
    $project = runtimeExecutionProject();
    $task = runtimeExecutionTask($project);

    $secret = 'runtime-secret-value-123456789';

    $incident = runtimeExecutionIncident(
        $project,
        $task,
        [
            'debug_context' => 'api_key='.$secret,
        ],
    );

    $baseSha = trim(
        Process::path($repositoryPath)
            ->run(['git', 'rev-parse', 'HEAD'])
            ->output(),
    );

    $capturedPath = null;
    $capturedPrompt = null;

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (
            Agent $agent,
            string $prompt,
            string $worktreePath,
        ) use (
            &$capturedPath,
            &$capturedPrompt,
            $repositoryPath,
        ): array {
            $capturedPath = $worktreePath;
            $capturedPrompt = $prompt;

            expect($worktreePath)
                ->not->toBe($repositoryPath)
                ->and(is_dir($worktreePath))
                ->toBeTrue();

            File::ensureDirectoryExists($worktreePath.'/app');

            File::put(
                $worktreePath.'/app/RuntimeRepair.php',
                "<?php\n\nreturn true;\n",
            );

            return [
                'execution' => [
                    'exit_code' => 0,
                    'output' => '{}',
                    'error_output' => '',
                ],
                'decision' => [
                    'root_cause_category' => 'application_defect',
                    'root_cause_summary' => 'A bounded AIOS runtime defect caused the failure.',
                    'recoverable' => true,
                    'fix_applied' => true,
                    'changed_files' => [
                        'app/RuntimeRepair.php',
                    ],
                    'fix_summary' => 'Fixed the bounded AIOS runtime defect.',
                    'escalation_reason' => null,
                ],
            ];
        });

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    $run = AgentRun::query()
        ->where('recovery_incident_id', $incident->id)
        ->where('role', AgentRole::RecoveryEngineer)
        ->sole();

    $headSha = trim(
        Process::path($repositoryPath)
            ->run(['git', 'rev-parse', 'HEAD'])
            ->output(),
    );

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Recovered)
        ->and($processed->root_cause_category)
        ->toBe(RuntimeRecoverabilityClassification::CandidateAiRepair->value)
        ->and($processed->attempt_count)
        ->toBe(1)
        ->and($processed->base_sha)
        ->toBe($baseSha)
        ->and($processed->commit_sha)
        ->toBe($headSha)
        ->and($processed->commit_sha)
        ->not->toBe($baseSha)
        ->and($processed->changed_files)
        ->toBe([
            'app/RuntimeRepair.php',
        ])
        ->and(File::exists($repositoryPath.'/app/RuntimeRepair.php'))
        ->toBeTrue()
        ->and(trim(Process::path($repositoryPath)->run([
            'git',
            'status',
            '--porcelain',
        ])->output()))
        ->toBe('')
        ->and($task->refresh()->status)
        ->toBe(TaskStatus::Failed)
        ->and($run->status)
        ->toBe(AgentRunStatus::Completed)
        ->and($run->configuration_snapshot)
        ->toBeArray()
        ->and($run->context_schema_version)
        ->not->toBeNull()
        ->and($capturedPrompt)
        ->toContain((string) $incident->fingerprint)
        ->not->toContain($secret)
        ->and($capturedPath)
        ->not->toBeNull()
        ->and(is_dir($capturedPath))
        ->toBeFalse();
});

test('malformed Recovery Engineer output cannot alter the live repository', function () {
    config()->set('aios.recovery_max_attempts', 3);

    $repositoryPath = runtimeExecutionRepository();
    $project = runtimeExecutionProject();
    $incident = runtimeExecutionIncident($project);

    $capturedPath = null;

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (
            Agent $agent,
            string $prompt,
            string $worktreePath,
        ) use (&$capturedPath): array {
            $capturedPath = $worktreePath;

            File::ensureDirectoryExists($worktreePath.'/app');

            File::put(
                $worktreePath.'/app/Malformed.php',
                "<?php\n\nreturn true;\n",
            );

            return [
                'execution' => [
                    'exit_code' => 0,
                    'output' => '{}',
                    'error_output' => '',
                ],
                'decision' => [
                    'fix_applied' => true,
                ],
            ];
        });

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Detected)
        ->and($processed->attempt_count)
        ->toBe(1)
        ->and(File::exists($repositoryPath.'/app/Malformed.php'))
        ->toBeFalse()
        ->and(is_dir($capturedPath))
        ->toBeFalse();
});

test('Agent-declared changed files must exactly match the AIOS-derived worktree diff', function () {
    $repositoryPath = runtimeExecutionRepository();
    $project = runtimeExecutionProject();
    $incident = runtimeExecutionIncident($project);

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (
            Agent $agent,
            string $prompt,
            string $worktreePath,
        ): array {
            File::ensureDirectoryExists($worktreePath.'/app');

            File::put(
                $worktreePath.'/app/Actual.php',
                "<?php\n\nreturn true;\n",
            );

            return [
                'execution' => [
                    'exit_code' => 0,
                    'output' => '{}',
                    'error_output' => '',
                ],
                'decision' => [
                    'root_cause_category' => 'application_defect',
                    'root_cause_summary' => 'A bounded defect was found.',
                    'recoverable' => true,
                    'fix_applied' => true,
                    'changed_files' => [
                        'app/Declared.php',
                    ],
                    'fix_summary' => 'Attempted repair.',
                    'escalation_reason' => null,
                ],
            ];
        });

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Detected)
        ->and(
            $processed->validation_evidence['checks']['declared_change_set_matches_actual']
                ?? null,
        )
        ->toBeFalse()
        ->and(File::exists($repositoryPath.'/app/Actual.php'))
        ->toBeFalse()
        ->and(File::exists($repositoryPath.'/app/Declared.php'))
        ->toBeFalse();
});

test('fix_applied false cannot hide actual worktree edits', function () {
    $repositoryPath = runtimeExecutionRepository();
    $project = runtimeExecutionProject();
    $incident = runtimeExecutionIncident($project);

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (
            Agent $agent,
            string $prompt,
            string $worktreePath,
        ): array {
            File::ensureDirectoryExists($worktreePath.'/app');

            File::put(
                $worktreePath.'/app/Hidden.php',
                "<?php\n\nreturn true;\n",
            );

            return [
                'execution' => [
                    'exit_code' => 0,
                    'output' => '{}',
                    'error_output' => '',
                ],
                'decision' => [
                    'root_cause_category' => 'application_defect',
                    'root_cause_summary' => 'A diagnosis was produced.',
                    'recoverable' => true,
                    'fix_applied' => false,
                    'changed_files' => [],
                    'fix_summary' => null,
                    'escalation_reason' => null,
                ],
            ];
        });

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Detected)
        ->and(
            $processed->validation_evidence['checks']['fix_applied_matches_actual_changes']
                ?? null,
        )
        ->toBeFalse()
        ->and(File::exists($repositoryPath.'/app/Hidden.php'))
        ->toBeFalse();
});

test('fix_applied true without actual worktree changes is rejected', function () {
    $repositoryPath = runtimeExecutionRepository();
    $project = runtimeExecutionProject();
    $incident = runtimeExecutionIncident($project);

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn([
            'execution' => [
                'exit_code' => 0,
                'output' => '{}',
                'error_output' => '',
            ],
            'decision' => [
                'root_cause_category' => 'application_defect',
                'root_cause_summary' => 'A diagnosis was produced.',
                'recoverable' => true,
                'fix_applied' => true,
                'changed_files' => [],
                'fix_summary' => 'Claimed repair without edits.',
                'escalation_reason' => null,
            ],
        ]);

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Detected)
        ->and(
            $processed->validation_evidence['checks']['fix_applied_matches_actual_changes']
                ?? null,
        )
        ->toBeFalse()
        ->and(trim(Process::path($repositoryPath)->run([
            'git',
            'status',
            '--porcelain',
        ])->output()))
        ->toBe('');
});

test('isolated validation failures never materialize or commit runtime repair files', function (
    string $mode,
    string $relativePath,
    string $contents,
) {
    $repositoryPath = runtimeExecutionRepository();
    $project = runtimeExecutionProject();
    $incident = runtimeExecutionIncident($project);

    if ($mode === 'configured_command') {
        config()->set(
            'aios.recovery_validation_commands',
            [
                'php artisan command:that-does-not-exist',
            ],
        );
    }

    $capturedPath = null;

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (
            Agent $agent,
            string $prompt,
            string $worktreePath,
        ) use (
            &$capturedPath,
            $relativePath,
            $contents,
        ): array {
            $capturedPath = $worktreePath;

            File::ensureDirectoryExists(
                dirname($worktreePath.'/'.$relativePath),
            );

            File::put(
                $worktreePath.'/'.$relativePath,
                $contents,
            );

            return [
                'execution' => [
                    'exit_code' => 0,
                    'output' => '{}',
                    'error_output' => '',
                ],
                'decision' => [
                    'root_cause_category' => 'application_defect',
                    'root_cause_summary' => 'A bounded defect was found.',
                    'recoverable' => true,
                    'fix_applied' => true,
                    'changed_files' => [
                        $relativePath,
                    ],
                    'fix_summary' => 'Attempted repair.',
                    'escalation_reason' => null,
                ],
            ];
        });

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Detected)
        ->and(
            $processed->validation_evidence['checks']['isolated_validation']
                ?? null,
        )
        ->toBeFalse()
        ->and(File::exists($repositoryPath.'/'.$relativePath))
        ->toBeFalse()
        ->and(is_dir($capturedPath))
        ->toBeFalse();
})->with([
    'forbidden file' => [
        'forbidden_file',
        'private.pem',
        "not-a-real-private-key\n",
    ],
    'secret scan' => [
        'secret_scan',
        'app/SecretLeak.php',
        "<?php\n\n// ghp_".str_repeat('A', 36)."\n",
    ],
    'git diff check' => [
        'git_diff_check',
        'app/Whitespace.php',
        "<?php\n\nreturn true;    \n",
    ],
    'configured validation command' => [
        'configured_command',
        'app/CommandCheck.php',
        "<?php\n\nreturn true;\n",
    ],
]);

test('live repository HEAD drift after isolated validation prevents repair materialization', function () {
    $repositoryPath = runtimeExecutionRepository();
    $project = runtimeExecutionProject();
    $incident = runtimeExecutionIncident($project);

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (
            Agent $agent,
            string $prompt,
            string $worktreePath,
        ) use ($repositoryPath): array {
            File::ensureDirectoryExists($worktreePath.'/app');

            File::put(
                $worktreePath.'/app/Proposed.php',
                "<?php\n\nreturn true;\n",
            );

            File::put(
                $repositoryPath.'/concurrent.txt',
                "concurrent change\n",
            );

            Process::path($repositoryPath)->run([
                'git',
                'add',
                'concurrent.txt',
            ]);

            $commit = Process::path($repositoryPath)->run([
                'git',
                'commit',
                '-m',
                'Concurrent change',
            ]);

            expect($commit->successful())
                ->toBeTrue();

            return [
                'execution' => [
                    'exit_code' => 0,
                    'output' => '{}',
                    'error_output' => '',
                ],
                'decision' => [
                    'root_cause_category' => 'application_defect',
                    'root_cause_summary' => 'A bounded defect was found.',
                    'recoverable' => true,
                    'fix_applied' => true,
                    'changed_files' => [
                        'app/Proposed.php',
                    ],
                    'fix_summary' => 'Attempted repair.',
                    'escalation_reason' => null,
                ],
            ];
        });

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Detected)
        ->and(
            $processed->validation_evidence['checks']['live_repository_unchanged']
                ?? null,
        )
        ->toBeFalse()
        ->and(File::exists($repositoryPath.'/app/Proposed.php'))
        ->toBeFalse()
        ->and(File::exists($repositoryPath.'/concurrent.txt'))
        ->toBeTrue();
});

test('repeated identical Recovery Engineer crashes create fresh runs and open the existing no-progress circuit breaker', function () {
    config()->set('aios.recovery_max_attempts', 5);
    config()->set('aios.no_progress_repeat_threshold', 1);

    runtimeExecutionRepository();

    $project = runtimeExecutionProject();
    $incident = runtimeExecutionIncident($project);

    $worktreePaths = [];

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->twice()
        ->andReturnUsing(function (
            Agent $agent,
            string $prompt,
            string $worktreePath,
        ) use (&$worktreePaths): array {
            $worktreePaths[] = $worktreePath;

            throw new RuntimeException('provider process crashed');
        });

    $first = app(WorkflowRecoveryEngine::class)->process($incident);
    $second = app(WorkflowRecoveryEngine::class)->process($first);

    $runs = AgentRun::query()
        ->where('recovery_incident_id', $incident->id)
        ->where('role', AgentRole::RecoveryEngineer)
        ->orderBy('id')
        ->get();

    expect($first->status)
        ->toBe(RecoveryIncidentStatus::Detected)
        ->and($first->attempt_count)
        ->toBe(1)
        ->and($second->status)
        ->toBe(RecoveryIncidentStatus::Escalated)
        ->and($second->attempt_count)
        ->toBe(2)
        ->and($runs)
        ->toHaveCount(2)
        ->and($runs[0]->status)
        ->toBe(AgentRunStatus::Failed)
        ->and($runs[1]->status)
        ->toBe(AgentRunStatus::Failed)
        ->and($runs[0]->configuration_snapshot)
        ->toBeArray()
        ->and($runs[1]->configuration_snapshot)
        ->toBeArray()
        ->and($runs[0]->prompt_hash)
        ->not->toBe($runs[1]->prompt_hash)
        ->and(
            $project->auditEvents()
                ->where('event_type', 'recovery.runtime_circuit_breaker_opened')
                ->exists(),
        )
        ->toBeTrue();

    foreach ($worktreePaths as $worktreePath) {
        expect(is_dir($worktreePath))
            ->toBeFalse();
    }
});

test('runtime AI repair respects the absolute attempt ceiling', function () {
    config()->set('aios.recovery_max_attempts', 1);
    config()->set('aios.no_progress_repeat_threshold', 10);

    runtimeExecutionRepository();

    $project = runtimeExecutionProject();
    $incident = runtimeExecutionIncident($project);

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldReceive('run')
        ->once()
        ->andReturn([
            'execution' => [
                'exit_code' => 1,
                'output' => '',
                'error_output' => 'provider failed',
            ],
            'decision' => null,
        ]);

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    app(WorkflowRecoveryEngine::class)->process($processed);

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->attempt_count)
        ->toBe(1)
        ->and(
            AgentRun::query()
                ->where('recovery_incident_id', $incident->id)
                ->count(),
        )
        ->toBe(1);
});

test('worktree creation failure never starts or counts a runtime AI repair attempt', function () {
    runtimeExecutionRepository();

    $project = runtimeExecutionProject();
    $incident = runtimeExecutionIncident($project);

    runtimeExecutionMock(RecoveryWorktreeManager::class)
        ->shouldReceive('create')
        ->once()
        ->andThrow(
            new RuntimeException('worktree creation failed'),
        );

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldNotReceive('run');

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->attempt_count)
        ->toBe(0)
        ->and(
            AgentRun::query()
                ->where('recovery_incident_id', $incident->id)
                ->count(),
        )
        ->toBe(0);
});

test('operator-only protected runtime incidents never invoke the Recovery Engineer', function () {
    $project = runtimeExecutionProject();

    $incident = app(RuntimeRecoveryIncidentRecorder::class)->record(
        RuntimeRecoveryIncidentFamily::ApplicationException,
        'route:auth.session',
        AuthenticationException::class,
        'Authentication state failed.',
        project: $project,
        evidence: [
            'message' => 'Authentication state failed.',
        ],
    );

    runtimeExecutionMock(RecoveryEngineerRunner::class)
        ->shouldNotReceive('run');

    $processed = app(WorkflowRecoveryEngine::class)->process($incident);

    expect($processed->status)
        ->toBe(RecoveryIncidentStatus::Escalated)
        ->and($processed->root_cause_category)
        ->toBe(RuntimeRecoverabilityClassification::OperatorOnly->value)
        ->and($processed->attempt_count)
        ->toBe(0)
        ->and(
            AgentRun::query()
                ->where('recovery_incident_id', $incident->id)
                ->count(),
        )
        ->toBe(0);
});
