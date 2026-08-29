<?php

use App\AgentHarness as AgentHarnessIdentifier;
use App\AgentRole;
use App\Models\Agent;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\Services\ClaudeCodeHarness;
use App\Services\CodexHarness;
use App\Services\TaskContextCapsuleFactory;
use App\Services\TaskGitIntegrator;
use App\Services\TaskWorktreeManager;
use App\TaskStatus;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Create one clean Git-backed managed project for P10-005 integration tests.
 *
 * Every repository created during one test remains inside the single authoritative
 * AIOS workspace root established by the feature-test bootstrap. Creating another
 * repository must never replace that root because previously created Projects still
 * need to pass WorkspacePathResolver validation during integration and cleanup.
 *
 * @return array{0: Project, 1: string}
 */
function parallelGitProject(): array
{
    $workspaceRoot = (string) config('aios.workspace_root');
    File::ensureDirectoryExists($workspaceRoot);

    $path = $workspaceRoot
        .'/aios-parallel-git-project-'
        .fake()->uuid();

    File::ensureDirectoryExists($path);

    Process::path($path)->run(['git', 'init']);
    Process::path($path)->run(['git', 'config', 'user.email', 'aios@example.test']);
    Process::path($path)->run(['git', 'config', 'user.name', 'AIOS Test']);
    File::put($path.'/feature.txt', "baseline\n");
    Process::path($path)->run(['git', 'add', 'feature.txt']);
    Process::path($path)->run(['git', 'commit', '-m', 'Baseline']);

    $baseSha = trim(Process::path($path)->run(['git', 'rev-parse', 'HEAD'])->output());
    $project = Project::create([
        'name' => 'Parallel Git '.fake()->uuid(),
        'path' => $path,
        'status' => ProjectStatus::Running,
        'git_status' => 'clean',
        'git_head_sha' => $baseSha,
    ]);

    return [$project, $baseSha];
}

/**
 * Create one durable Coder Task for direct candidate and integration testing.
 */
function parallelGitTask(Project $project, string $key, int $position): Task
{
    return Task::create([
        'project_id' => $project->id,
        'key' => $key,
        'position' => $position,
        'title' => "Parallel Git task {$position}",
        'objective' => 'Integrate exactly one isolated Task candidate.',
        'acceptance_criteria' => ['The candidate is integrated safely.'],
        'implementation_prompt' => 'Modify only the assigned test file.',
        'context_capsule' => [],
        'status' => TaskStatus::Coding,
    ]);
}

/**
 * Create one running TaskAttempt pinned to the supplied exact repository base.
 */
function parallelGitAttempt(Task $task, string $baseSha, int $number = 1): TaskAttempt
{
    return TaskAttempt::create([
        'task_id' => $task->id,
        'number' => $number,
        'base_sha' => $baseSha,
        'status' => 'running',
        'started_at' => now(),
    ]);
}

/**
 * Create one durable candidate by writing the supplied file only inside the Task worktree.
 *
 * @return array<string, mixed>
 */
function parallelGitCandidate(
    Task $task,
    TaskAttempt $attempt,
    string $file,
    string $contents,
): array {
    $manager = app(TaskWorktreeManager::class);
    $path = $manager->acquire($task, $attempt);
    File::put($path.'/'.$file, $contents);

    return app(TaskGitIntegrator::class)->createCandidate($task, $attempt, $path);
}

/**
 * Read the current canonical repository HEAD.
 */
function parallelGitHead(Project $project): string
{
    return trim(Process::path($project->path)->run(['git', 'rev-parse', 'HEAD'])->output());
}

/**
 * Read the canonical repository porcelain status without mutating Git state.
 */
function parallelGitStatus(Project $project): string
{
    return trim(Process::path($project->path)->run(['git', 'status', '--porcelain'])->output());
}

test('two same-base independent candidates integrate serially and the second rechecks the new canonical head', function () {
    [$project, $baseSha] = parallelGitProject();
    $taskA = parallelGitTask($project, 'P10-005-A', 1);
    $taskB = parallelGitTask($project, 'P10-005-B', 2);
    $attemptA = parallelGitAttempt($taskA, $baseSha);
    $attemptB = parallelGitAttempt($taskB, $baseSha);
    $candidateA = parallelGitCandidate($taskA, $attemptA, 'task-a.txt', "A\n");
    $candidateB = parallelGitCandidate($taskB, $attemptB, 'task-b.txt', "B\n");
    $integrator = app(TaskGitIntegrator::class);

    try {
        $resultA = $integrator->integrate($taskA, $attemptA, $candidateA);
        $resultB = $integrator->integrate($taskB, $attemptB, $candidateB);

        expect($resultA['passed'])->toBeTrue()
            ->and($resultA['status'])->toBe('integrated')
            ->and($resultA['canonical_head_before'])->toBe($baseSha)
            ->and($resultB['passed'])->toBeTrue()
            ->and($resultB['status'])->toBe('integrated')
            ->and($resultB['canonical_head_before'])->toBe($resultA['integrated_sha'])
            ->and($resultB['canonical_head_after'])->toBe(parallelGitHead($project))
            ->and(File::get($project->path.'/task-a.txt'))->toBe("A\n")
            ->and(File::get($project->path.'/task-b.txt'))->toBe("B\n")
            ->and(parallelGitStatus($project))->toBe('');
    } finally {
        app(TaskWorktreeManager::class)->release($taskA, $attemptA);
        app(TaskWorktreeManager::class)->release($taskB, $attemptB);
    }
});

test('a genuinely conflicting stale-base candidate aborts only its cherry-pick and restores the exact canonical state', function () {
    [$project, $baseSha] = parallelGitProject();
    $taskA = parallelGitTask($project, 'P10-005-CONFLICT-A', 1);
    $taskB = parallelGitTask($project, 'P10-005-CONFLICT-B', 2);
    $attemptA = parallelGitAttempt($taskA, $baseSha);
    $attemptB = parallelGitAttempt($taskB, $baseSha);
    $candidateA = parallelGitCandidate($taskA, $attemptA, 'feature.txt', "from A\n");
    $candidateB = parallelGitCandidate($taskB, $attemptB, 'feature.txt', "from B\n");
    $integrator = app(TaskGitIntegrator::class);

    try {
        $resultA = $integrator->integrate($taskA, $attemptA, $candidateA);
        $headAfterA = parallelGitHead($project);
        $resultB = $integrator->integrate($taskB, $attemptB, $candidateB);

        expect($resultA['passed'])->toBeTrue()
            ->and($resultB['passed'])->toBeFalse()
            ->and($resultB['status'])->toBe('conflict')
            ->and($resultB['conflict_paths'])->toBe(['feature.txt'])
            ->and(parallelGitHead($project))->toBe($headAfterA)
            ->and(parallelGitStatus($project))->toBe('')
            ->and(File::get($project->path.'/feature.txt'))->toBe("from A\n")
            ->and(File::exists($project->path.'/.git/CHERRY_PICK_HEAD'))->toBeFalse();
    } finally {
        app(TaskWorktreeManager::class)->release($taskA, $attemptA);
        app(TaskWorktreeManager::class)->release($taskB, $attemptB);
    }
});

test('conflict evidence is bounded durable retry context and never reviewer rejection evidence', function () {
    [$project, $baseSha] = parallelGitProject();
    $taskA = parallelGitTask($project, 'P10-005-EVIDENCE-A', 1);
    $taskB = parallelGitTask($project, 'P10-005-EVIDENCE-B', 2);
    $attemptA = parallelGitAttempt($taskA, $baseSha);
    $attemptB = parallelGitAttempt($taskB, $baseSha);
    $candidateA = parallelGitCandidate($taskA, $attemptA, 'feature.txt', "A\n");
    $candidateB = parallelGitCandidate($taskB, $attemptB, 'feature.txt', "B\n");
    $integrator = app(TaskGitIntegrator::class);

    try {
        $integrator->integrate($taskA, $attemptA, $candidateA);
        $conflict = $integrator->integrate($taskB, $attemptB, $candidateB);
        $attemptB->update([
            'status' => 'failed',
            'validation_results' => [
                'passed' => false,
                'evidence' => [
                    'git_integration' => [
                        'name' => 'git_integration',
                        'passed' => false,
                        'status' => $conflict['status'],
                        'base_sha' => $conflict['base_sha'],
                        'candidate_sha' => $conflict['candidate_sha'],
                        'canonical_head_before' => $conflict['canonical_head_before'],
                        'conflict_paths' => $conflict['conflict_paths'],
                        'summary' => $conflict['summary'],
                    ],
                ],
                'git_integration' => $conflict,
            ],
            'changed_files' => $candidateB['changed_files'],
            'finished_at' => now(),
        ]);
        $taskB->update(['status' => TaskStatus::Failed]);

        $context = app(TaskContextCapsuleFactory::class)->make($taskB, AgentRole::Coder);
        $retryEvidence = $context['previous_attempt']['failed_validation_evidence']['git_integration'] ?? null;

        expect($retryEvidence)->toBeArray()
            ->and($retryEvidence['status'])->toBe('conflict')
            ->and($retryEvidence['conflict_paths'])->toBe(['feature.txt'])
            ->and($taskB->reviews()->count())->toBe(0)
            ->and($taskB->getRawOriginal('status'))->toBe(TaskStatus::Failed->value);
    } finally {
        app(TaskWorktreeManager::class)->release($taskA, $attemptA);
        app(TaskWorktreeManager::class)->release($taskB, $attemptB);
    }
});

test('repository integration uses one database-backed critical-section owner even when the default cache store is not distributed', function () {
    [$project] = parallelGitProject();
    config()->set('cache.default', 'array');
    $name = app(TaskGitIntegrator::class)->repositoryLockName($project);
    $first = Cache::store('database')->lock($name, 30);
    $second = Cache::store('database')->lock($name, 30);

    try {
        expect($first->get())->toBeTrue()
            ->and($second->get())->toBeFalse();
    } finally {
        $first->release();
        $second->release();
    }
});

test('invalid or cross-repository candidate evidence is rejected before canonical mutation', function () {
    [$projectA, $baseA] = parallelGitProject();
    $taskA = parallelGitTask($projectA, 'P10-005-INVALID-A', 1);
    $attemptA = parallelGitAttempt($taskA, $baseA);
    $candidateA = parallelGitCandidate($taskA, $attemptA, 'task-a.txt', "A\n");
    $headA = parallelGitHead($projectA);

    $invalid = $candidateA;
    $invalid['candidate_sha'] = 'not-a-sha';

    expect(fn () => app(TaskGitIntegrator::class)->integrate($taskA, $attemptA, $invalid))
        ->toThrow(RuntimeException::class, 'exact commit SHA');

    [$projectB, $baseB] = parallelGitProject();
    $taskB = parallelGitTask($projectB, 'P10-005-INVALID-B', 1);
    $attemptB = parallelGitAttempt($taskB, $baseB);

    expect(fn () => app(TaskGitIntegrator::class)->integrate($taskB, $attemptB, $candidateA))
        ->toThrow(RuntimeException::class);

    expect(parallelGitHead($projectA))->toBe($headA)
        ->and(parallelGitStatus($projectA))->toBe('')
        ->and(parallelGitStatus($projectB))->toBe('');

    app(TaskWorktreeManager::class)->release($taskA, $attemptA);
});

test('a dirty canonical repository fails closed without altering operator work', function () {
    [$project, $baseSha] = parallelGitProject();
    $task = parallelGitTask($project, 'P10-005-DIRTY', 1);
    $attempt = parallelGitAttempt($task, $baseSha);
    $candidate = parallelGitCandidate($task, $attempt, 'task.txt', "task\n");
    File::put($project->path.'/operator.txt', "operator work\n");
    $beforeStatus = Process::path($project->path)->run(['git', 'status', '--porcelain'])->output();

    try {
        $result = app(TaskGitIntegrator::class)->integrate($task, $attempt, $candidate);

        expect($result['passed'])->toBeFalse()
            ->and($result['status'])->toBe('canonical_repository_unsafe')
            ->and(parallelGitHead($project))->toBe($baseSha)
            ->and(File::get($project->path.'/operator.txt'))->toBe("operator work\n")
            ->and(Process::path($project->path)->run(['git', 'status', '--porcelain'])->output())->toBe($beforeStatus);
    } finally {
        app(TaskWorktreeManager::class)->release($task, $attempt);
    }
});

test('repeating integration after success is idempotent and does not duplicate the task patch', function () {
    [$project, $baseSha] = parallelGitProject();
    $task = parallelGitTask($project, 'P10-005-IDEMPOTENT', 1);
    $attempt = parallelGitAttempt($task, $baseSha);
    $candidate = parallelGitCandidate($task, $attempt, 'task.txt', "once\n");
    $integrator = app(TaskGitIntegrator::class);

    try {
        $first = $integrator->integrate($task, $attempt, $candidate);
        $head = parallelGitHead($project);
        $second = $integrator->integrate($task, $attempt, $candidate);
        $matchingCommits = trim(Process::path($project->path)->run([
            'git',
            'log',
            '--format=%H',
            '--fixed-strings',
            '--grep=AIOS-Task-Attempt: '.$project->id.'/'.$task->id.'/'.$attempt->id,
        ])->output());

        expect($first['status'])->toBe('integrated')
            ->and($second['passed'])->toBeTrue()
            ->and($second['status'])->toBe('already_integrated')
            ->and($second['integrated_sha'])->toBe($head)
            ->and(parallelGitHead($project))->toBe($head)
            ->and(substr_count($matchingCommits, "\n"))->toBe(0);
    } finally {
        app(TaskWorktreeManager::class)->release($task, $attempt);
    }
});

test('candidate and canonical task commits remain exactly reviewable by durable sha ref and changed-file evidence', function () {
    [$project, $baseSha] = parallelGitProject();
    $task = parallelGitTask($project, 'P10-005-REVIEW', 1);
    $attempt = parallelGitAttempt($task, $baseSha);
    $candidate = parallelGitCandidate($task, $attempt, 'reviewable.txt', "review me\n");

    try {
        $result = app(TaskGitIntegrator::class)->integrate($task, $attempt, $candidate);
        $candidateRefSha = trim(Process::path($project->path)->run([
            'git',
            'rev-parse',
            '--verify',
            $candidate['candidate_ref'].'^{commit}',
        ])->output());
        $candidateFiles = trim(Process::path($project->path)->run([
            'git',
            'diff',
            '--name-only',
            $baseSha,
            $candidateRefSha,
            '--',
        ])->output());
        $canonicalFiles = trim(Process::path($project->path)->run([
            'git',
            'show',
            '--format=',
            '--name-only',
            (string) $result['integrated_sha'],
        ])->output());

        expect($candidateRefSha)->toBe($candidate['candidate_sha'])
            ->and($candidate['changed_files'])->toBe(['reviewable.txt'])
            ->and($candidateFiles)->toBe('reviewable.txt')
            ->and($canonicalFiles)->toBe('reviewable.txt')
            ->and($candidate['candidate_diff_sha256'])->toMatch('/^[0-9a-f]{64}$/')
            ->and($result['integrated_sha'])->toBe(parallelGitHead($project));
    } finally {
        app(TaskWorktreeManager::class)->release($task, $attempt);
    }
});

test('codex coder execution honors the exact AIOS-selected isolated worktree path', function () {
    [$project, $baseSha] = parallelGitProject();
    $task = parallelGitTask($project, 'P10-005-CODEX-PATH', 1);
    $attempt = parallelGitAttempt($task, $baseSha);
    $worktreePath = app(TaskWorktreeManager::class)->acquire($task, $attempt);
    $agent = Agent::factory()->for($project)->create([
        'role' => AgentRole::Coder,
        'harness' => AgentHarnessIdentifier::Codex,
    ]);

    Process::fake([
        '*' => Process::result(output: 'completed'),
    ]);

    try {
        app(CodexHarness::class)->execute(
            $project,
            $agent,
            'Implement the task.',
            executionPath: $worktreePath,
        );

        Process::assertRan(function (PendingProcess $process) use ($worktreePath): bool {
            $path = (new ReflectionProperty($process, 'path'))->getValue($process);
            $command = (new ReflectionProperty($process, 'command'))->getValue($process);

            return $path === $worktreePath
                && is_array($command)
                && in_array(config('aios.codex_binary'), $command, true);
        });
    } finally {
        app(TaskWorktreeManager::class)->release($task, $attempt);
    }
});

test('claude code coder execution honors the exact AIOS-selected isolated worktree path', function () {
    [$project, $baseSha] = parallelGitProject();
    $task = parallelGitTask($project, 'P10-005-CLAUDE-PATH', 1);
    $attempt = parallelGitAttempt($task, $baseSha);
    $worktreePath = app(TaskWorktreeManager::class)->acquire($task, $attempt);
    $agent = Agent::factory()->for($project)->create([
        'role' => AgentRole::Coder,
        'harness' => AgentHarnessIdentifier::ClaudeCode,
    ]);
    $stream = json_encode([
        'type' => 'result',
        'subtype' => 'success',
        'session_id' => 'p10-005-claude',
        'is_error' => false,
        'result' => 'completed',
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ], JSON_THROW_ON_ERROR)."\n";

    Process::fake([
        '*' => Process::sequence([
            Process::result(output: '{"loggedIn":true}', exitCode: 0),
            Process::result(output: $stream, exitCode: 0),
        ]),
    ]);

    try {
        $result = app(ClaudeCodeHarness::class)->execute(
            $project,
            $agent,
            'Implement the task.',
            executionPath: $worktreePath,
        );

        expect($result->exitCode)->toBe(0);

        Process::assertRan(function (PendingProcess $process) use ($worktreePath): bool {
            $path = (new ReflectionProperty($process, 'path'))->getValue($process);
            $command = (new ReflectionProperty($process, 'command'))->getValue($process);

            return $path === $worktreePath
                && is_array($command)
                && in_array(config('aios.claude_code_binary'), $command, true)
                && in_array('--safe-mode', $command, true);
        });
    } finally {
        app(TaskWorktreeManager::class)->release($task, $attempt);
    }
});
