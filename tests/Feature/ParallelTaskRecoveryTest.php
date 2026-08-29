<?php

use App\Actions\ClaimTask;
use App\Actions\RunCoderTask;
use App\AgentHandoffType;
use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\StaleWorkerRecovery;
use App\Services\TaskGitIntegrator;
use App\Services\TaskWorkflow;
use App\Services\TaskWorktreeManager;
use App\TaskStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set(
        'aios.obsidian_vault_path',
        storage_path(
            'framework/testing/obsidian-p10-006-'.fake()->uuid(),
        ),
    );
});

/**
 * Create one clean Git-backed managed Project for P10-006 recovery tests.
 *
 * @return array{0: Project, 1: string}
 */
function p10006Project(string $name): array
{
    $workspaceRoot = (string) config('aios.workspace_root');

    File::ensureDirectoryExists($workspaceRoot);

    $path = $workspaceRoot
        .'/p10-006-project-'
        .fake()->uuid();

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

    File::put($path.'/feature.txt', "baseline\n");

    Process::path($path)->run([
        'git',
        'add',
        'feature.txt',
    ]);

    Process::path($path)->run([
        'git',
        'commit',
        '-m',
        'Baseline',
    ]);

    $baseSha = trim(
        Process::path($path)
            ->run(['git', 'rev-parse', 'HEAD'])
            ->output(),
    );

    $project = Project::create([
        'name' => $name,
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
        'git_head_sha' => $baseSha,
    ]);

    return [$project, $baseSha];
}

/**
 * Create one Coder Task with an optional phase and explicit durable status.
 */
function p10006Task(
    Project $project,
    string $key,
    int $position,
    TaskStatus $status,
    ?Phase $phase = null,
): Task {
    return Task::create([
        'project_id' => $project->id,
        'phase_id' => $phase?->id,
        'key' => $key,
        'position' => $position,
        'title' => "P10-006 task {$position}",
        'objective' => 'Preserve deterministic parallel recovery.',
        'acceptance_criteria' => [
            'Recovery preserves durable candidate evidence.',
        ],
        'relevant_paths' => ['feature.txt'],
        'verification_commands' => [],
        'implementation_prompt' => 'Change only feature.txt.',
        'context_capsule' => [],
        'status' => $status,
    ]);
}

/**
 * Create one expired Coder slot with a durable stale lease.
 *
 * @return array{0: AgentWorker, 1: string}
 */
function p10006ExpiredCoderWorker(
    Project $project,
    int $slot,
): array {
    $leaseId = (string) Str::uuid();

    $worker = AgentWorker::create([
        'project_id' => $project->id,
        'role' => AgentRole::Coder,
        'slot' => $slot,
        'status' => 'working',
        'worker_instance_id' => (string) Str::uuid(),
        'lease_id' => $leaseId,
        'last_heartbeat_at' => now()->subMinutes(5),
        'lease_expires_at' => now()->subMinute(),
        'started_at' => now()->subMinutes(10),
    ]);

    return [$worker, $leaseId];
}

/**
 * Create one running P10 Coder attempt pinned to an exact canonical base.
 */
function p10006Attempt(
    Task $task,
    string $baseSha,
): TaskAttempt {
    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => 1,
        'base_sha' => $baseSha,
        'status' => 'running',
        'validation_results' => [
            'repository_preflight' => [
                'mode' => 'normal',
                'base_sha' => $baseSha,
                'recovery_attempt_number' => null,
            ],
            'task_contract' => [],
        ],
        'started_at' => now()->subMinutes(5),
    ]);
}

/**
 * Persist one completed successful Coder AgentRun owned by the supplied worker lease.
 */
function p10006CompletedCoderRun(
    Project $project,
    Task $task,
    TaskAttempt $attempt,
    AgentWorker $worker,
    string $leaseId,
): AgentRun {
    return AgentRun::create([
        'project_id' => $project->id,
        'task_id' => $task->id,
        'agent_worker_id' => $worker->id,
        'worker_instance_id' => $worker->worker_instance_id,
        'worker_lease_id' => $leaseId,
        'role' => AgentRole::Coder,
        'status' => AgentRunStatus::Completed,
        'attempt_number' => $attempt->number,
        'prompt_hash' => hash(
            'sha256',
            implode(':', [
                $project->id,
                $task->id,
                $attempt->number,
                fake()->uuid(),
            ]),
        ),
        'exit_code' => 0,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now()->subMinutes(4),
    ]);
}

/**
 * Create one durable candidate inside the exact AIOS-owned attempt worktree.
 *
 * @return array{
 *     worktree: string,
 *     candidate: array{
 *         base_sha: string,
 *         candidate_sha: ?string,
 *         candidate_ref: ?string,
 *         candidate_diff_sha256: string,
 *         changed_files: list<string>,
 *         no_changes: bool
 *     }
 * }
 */
function p10006Candidate(
    Task $task,
    TaskAttempt $attempt,
    string $contents,
): array {
    $worktrees = app(TaskWorktreeManager::class);
    $worktree = $worktrees->acquire(
        $task,
        $attempt,
    );

    File::put(
        $worktree.'/feature.txt',
        $contents,
    );

    $candidate = app(TaskGitIntegrator::class)
        ->createCandidate(
            $task,
            $attempt,
            $worktree,
        );

    return [
        'worktree' => $worktree,
        'candidate' => $candidate,
    ];
}

/**
 * Count exact canonical commits carrying the durable AIOS Task-attempt trailer.
 */
function p10006IntegratedCommitCount(
    Project $project,
    Task $task,
    TaskAttempt $attempt,
): int {
    $output = trim(
        Process::path($project->path)
            ->run([
                'git',
                'log',
                '--format=%H',
                '--fixed-strings',
                '--grep=AIOS-Task-Attempt: '
                    .$project->id
                    .'/'
                    .$task->id
                    .'/'
                    .$attempt->id,
            ])
            ->output(),
    );

    if ($output === '') {
        return 0;
    }

    $lines = preg_split('/\R/', $output);

    return is_array($lines)
        ? count(array_filter($lines))
        : 0;
}

test('expired parallel worker preserves a durable candidate and the same attempt resumes serialized git finalization', function (): void {
    [$project, $baseSha] = p10006Project(
        'P10-006 Durable Candidate',
    );

    [$worker, $expiredLeaseId] = p10006ExpiredCoderWorker(
        $project,
        2,
    );

    $task = p10006Task(
        $project,
        'P10-006-001',
        1,
        TaskStatus::Validating,
    );

    $task->update([
        'coder_worker_id' => $worker->id,
        'coder_worker_lease_id' => $expiredLeaseId,
    ]);

    $attempt = p10006Attempt($task, $baseSha);

    $candidateState = p10006Candidate(
        $task,
        $attempt,
        "recovered candidate\n",
    );

    $candidate = $candidateState['candidate'];
    $worktree = $candidateState['worktree'];

    p10006CompletedCoderRun(
        $project,
        $task,
        $attempt,
        $worker,
        $expiredLeaseId,
    );

    expect(File::exists($worktree))->toBeTrue();

    $recovered = app(StaleWorkerRecovery::class)
        ->recover($project, 60);

    $attempt->refresh();
    $task->refresh();

    $parallelRecovery = $attempt
        ->getAttribute('validation_results')['parallel_recovery']
        ?? null;

    expect($recovered)->toBe(1)
        ->and($task->status)->toBe(TaskStatus::Failed)
        ->and($task->coder_worker_id)->toBeNull()
        ->and($task->coder_worker_lease_id)->toBeNull()
        ->and($attempt->getRawOriginal('status'))->toBe('failed')
        ->and($attempt->changed_files)->toBe(['feature.txt'])
        ->and($parallelRecovery)->toBeArray()
        ->and($parallelRecovery['state'])
        ->toBe('durable_candidate_pending_integration')
        ->and($parallelRecovery['durable_candidate_preserved'])
        ->toBeTrue()
        ->and($parallelRecovery['review_eligible'])
        ->toBeFalse()
        ->and($parallelRecovery['worker']['agent_worker_id'])
        ->toBe($worker->id)
        ->and($parallelRecovery['worker']['worker_slot'])
        ->toBe(2)
        ->and($parallelRecovery['worker']['worker_lease_sha256'])
        ->toBe(hash('sha256', $expiredLeaseId))
        ->and($parallelRecovery['candidate']['candidate_sha'])
        ->toBe($candidate['candidate_sha'])
        ->and($parallelRecovery['candidate']['candidate_ref'])
        ->toBe($candidate['candidate_ref'])
        ->and(File::exists($worktree))->toBeFalse()
        ->and(File::get($project->path.'/feature.txt'))
        ->toBe("baseline\n");

    $candidateResolution = Process::path($project->path)
        ->run([
            'git',
            'rev-parse',
            '--verify',
            $candidate['candidate_ref'].'^{commit}',
        ]);

    expect($candidateResolution->successful())->toBeTrue();

    app(TaskWorkflow::class)->transition(
        $task,
        TaskStatus::Coding,
    );

    $finalizedAttempt = app(RunCoderTask::class)
        ->handle($task->fresh());

    $finalizedAttempt->refresh();
    $task->refresh();

    $validation = $finalizedAttempt->getAttribute(
        'validation_results',
    );

    expect($finalizedAttempt->id)->toBe($attempt->id)
        ->and($finalizedAttempt->getRawOriginal('status'))
        ->toBe('completed')
        ->and($task->status)
        ->toBe(TaskStatus::ReadyForReview)
        ->and($validation['parallel_recovery']['state'])
        ->toBe('durable_candidate_pending_integration')
        ->and($validation['checks']['git_candidate'])
        ->toBeTrue()
        ->and($validation['checks']['git_integration'])
        ->toBeTrue()
        ->and($validation['git_integration']['passed'])
        ->toBeTrue()
        ->and($validation['git_integration']['status'])
        ->toBe('integrated')
        ->and(File::get($project->path.'/feature.txt'))
        ->toBe("recovered candidate\n")
        ->and(
            $task->handoffs()
                ->where(
                    'handoff_type',
                    AgentHandoffType::ImplementationHandoff,
                )
                ->count(),
        )
        ->toBe(1)
        ->and(
            $task->auditEvents()
                ->where(
                    'event_type',
                    'task.git_integration_recovered',
                )
                ->exists(),
        )
        ->toBeTrue();
});

test('an already integrated durable candidate is reconciled without creating a duplicate canonical commit', function (): void {
    [$project, $baseSha] = p10006Project(
        'P10-006 Already Integrated',
    );

    [$worker, $expiredLeaseId] = p10006ExpiredCoderWorker(
        $project,
        2,
    );

    $task = p10006Task(
        $project,
        'P10-006-002',
        1,
        TaskStatus::Validating,
    );

    $task->update([
        'coder_worker_id' => $worker->id,
        'coder_worker_lease_id' => $expiredLeaseId,
    ]);

    $attempt = p10006Attempt($task, $baseSha);

    $candidateState = p10006Candidate(
        $task,
        $attempt,
        "integrated once\n",
    );

    $candidate = $candidateState['candidate'];

    p10006CompletedCoderRun(
        $project,
        $task,
        $attempt,
        $worker,
        $expiredLeaseId,
    );

    $initialIntegration = app(TaskGitIntegrator::class)
        ->integrate(
            $task,
            $attempt,
            $candidate,
        );

    $integratedHead = trim(
        Process::path($project->path)
            ->run(['git', 'rev-parse', 'HEAD'])
            ->output(),
    );

    expect($initialIntegration['passed'])->toBeTrue()
        ->and($initialIntegration['status'])->toBe('integrated')
        ->and(p10006IntegratedCommitCount(
            $project,
            $task,
            $attempt,
        ))->toBe(1);

    app(StaleWorkerRecovery::class)
        ->recover($project, 60);

    expect($task->refresh()->status)
        ->toBe(TaskStatus::Failed)
        ->and($attempt->refresh()->getRawOriginal('status'))
        ->toBe('failed');

    app(TaskWorkflow::class)->transition(
        $task,
        TaskStatus::Coding,
    );

    $recoveredAttempt = app(RunCoderTask::class)
        ->handle($task->fresh());

    $validation = $recoveredAttempt
        ->getAttribute('validation_results');

    expect($recoveredAttempt->getRawOriginal('status'))
        ->toBe('completed')
        ->and($task->refresh()->status)
        ->toBe(TaskStatus::ReadyForReview)
        ->and($validation['git_integration']['status'])
        ->toBe('already_integrated')
        ->and($validation['git_integration']['integrated_sha'])
        ->toBe($integratedHead)
        ->and(
            trim(
                Process::path($project->path)
                    ->run(['git', 'rev-parse', 'HEAD'])
                    ->output(),
            ),
        )
        ->toBe($integratedHead)
        ->and(p10006IntegratedCommitCount(
            $project,
            $task,
            $attempt,
        ))
        ->toBe(1)
        ->and(
            $task->handoffs()
                ->where(
                    'handoff_type',
                    AgentHandoffType::ImplementationHandoff,
                )
                ->count(),
        )
        ->toBe(1);
});

test('an abandoned isolated worktree with no durable candidate is removed and retried fresh', function (): void {
    [$project, $baseSha] = p10006Project(
        'P10-006 Abandoned Worktree',
    );

    [$worker, $expiredLeaseId] = p10006ExpiredCoderWorker(
        $project,
        2,
    );

    $task = p10006Task(
        $project,
        'P10-006-003',
        1,
        TaskStatus::Coding,
    );

    $task->update([
        'coder_worker_id' => $worker->id,
        'coder_worker_lease_id' => $expiredLeaseId,
    ]);

    $attempt = p10006Attempt($task, $baseSha);

    $worktree = app(TaskWorktreeManager::class)
        ->acquire($task, $attempt);

    File::put(
        $worktree.'/feature.txt',
        "disposable unfinished work\n",
    );

    p10006CompletedCoderRun(
        $project,
        $task,
        $attempt,
        $worker,
        $expiredLeaseId,
    );

    expect(
        app(TaskGitIntegrator::class)
            ->recoverCandidate($task, $attempt),
    )->toBeNull()
        ->and(File::exists($worktree))
        ->toBeTrue();

    app(StaleWorkerRecovery::class)
        ->recover($project, 60);

    $task->refresh();
    $attempt->refresh();

    $recovery = $attempt
        ->getAttribute('validation_results')['recovery']['parallel_recovery']
        ?? null;

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->coder_worker_id)->toBeNull()
        ->and($task->coder_worker_lease_id)->toBeNull()
        ->and($attempt->getRawOriginal('status'))
        ->toBe('interrupted')
        ->and($recovery)->toBeArray()
        ->and($recovery['durable_candidate_preserved'])
        ->toBeFalse()
        ->and($recovery['worktree']['present_before_release'])
        ->toBeTrue()
        ->and($recovery['worktree']['released'])
        ->toBeTrue()
        ->and(File::exists($worktree))->toBeFalse()
        ->and(File::get($project->path.'/feature.txt'))
        ->toBe("baseline\n")
        ->and(
            $task->auditEvents()
                ->where(
                    'event_type',
                    'task.parallel_finalization_preserved',
                )
                ->exists(),
        )
        ->toBeFalse();
});

test('parallel implementation does not open or parallelize the reviewer phase barrier', function (): void {
    [$project] = p10006Project(
        'P10-006 Reviewer Barrier',
    );

    $phase = Phase::create([
        'project_id' => $project->id,
        'position' => 1,
        'title' => 'Parallel implementation',
        'objective' => 'Preserve serial deterministic review.',
    ]);

    $laterTask = p10006Task(
        $project,
        'P10-006-REVIEW-002',
        2,
        TaskStatus::ReadyForReview,
        $phase,
    );

    $firstTask = p10006Task(
        $project,
        'P10-006-REVIEW-001',
        1,
        TaskStatus::Validating,
        $phase,
    );

    $claims = app(ClaimTask::class);

    expect(
        $claims->handle(
            $project,
            AgentRole::Reviewer,
        ),
    )->toBeNull();

    $firstTask->update([
        'status' => TaskStatus::ReadyForReview,
    ]);

    $firstReview = $claims->handle(
        $project,
        AgentRole::Reviewer,
    );

    expect($firstReview?->id)->toBe($firstTask->id)
        ->and(
            $claims->handle(
                $project,
                AgentRole::Reviewer,
            ),
        )
        ->toBeNull();

    app(TaskWorkflow::class)
        ->approveTask($firstReview);

    $secondReview = $claims->handle(
        $project,
        AgentRole::Reviewer,
    );

    expect($firstTask->refresh()->status)
        ->toBe(TaskStatus::Done)
        ->and($secondReview?->id)
        ->toBe($laterTask->id)
        ->and($laterTask->refresh()->status)
        ->toBe(TaskStatus::Reviewing);
});
