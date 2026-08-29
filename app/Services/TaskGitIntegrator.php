<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Creates durable AIOS-owned Task candidate commits and serializes their integration into the canonical repository.
 *
 * Agents and harnesses never call this service. Every Git mutation here is driven only by persisted Task/attempt
 * identity, exact object IDs, an AIOS-selected worktree, and a database-backed repository integration lock.
 */
final class TaskGitIntegrator
{
    private const int IntegrationLockSeconds = 300;

    private const int IntegrationLockWaitSeconds = 5;

    public function __construct(
        private WorkspacePathResolver $paths,
        private ProjectGitState $git,
        private IsolatedGitWorktreeManager $worktrees,
    ) {}

    /**
     * Create or reuse one durable Task candidate commit from the exact AIOS-owned attempt worktree.
     *
     * @return array{
     *     base_sha: string,
     *     candidate_sha: ?string,
     *     candidate_ref: ?string,
     *     candidate_diff_sha256: string,
     *     changed_files: list<string>,
     *     no_changes: bool
     * }
     */
    public function createCandidate(Task $task, TaskAttempt $attempt, string $worktreePath): array
    {
        $this->assertAttemptOwnership($task, $attempt);
        $task->loadMissing('project');

        $repositoryPath = $this->paths->assertProjectPath((string) $task->project->path);
        $worktreePath = $this->paths->assertProjectPath($worktreePath);
        $baseSha = $this->normalizeExactObjectId((string) $attempt->base_sha);
        $candidateRef = $this->candidateRef($task, $attempt);
        $existingCandidate = $this->candidateFromRef($repositoryPath, $candidateRef, $baseSha, $task, $attempt);

        if ($existingCandidate !== null) {
            return $existingCandidate;
        }

        if (! $this->worktrees->matches($repositoryPath, $worktreePath, $baseSha)) {
            throw new RuntimeException('The Task candidate worktree no longer matches its persisted base or managed repository.');
        }

        $stagedBefore = $this->git->stagedFiles($worktreePath);

        if ($stagedBefore === null || $stagedBefore !== []) {
            throw new RuntimeException('AIOS rejected the Task candidate because the Agent left staged Git state behind.');
        }

        $changedFiles = $this->git->changedFilesFromBase($worktreePath, $baseSha);

        if ($changedFiles === null || ! $this->git->baseMatchesCurrentHead($worktreePath, $baseSha)) {
            throw new RuntimeException('AIOS could not prove the Task candidate change set against its persisted base.');
        }

        if ($changedFiles === []) {
            return [
                'base_sha' => $baseSha,
                'candidate_sha' => null,
                'candidate_ref' => null,
                'candidate_diff_sha256' => hash('sha256', ''),
                'changed_files' => [],
                'no_changes' => true,
            ];
        }

        $add = Process::path($worktreePath)->run([
            'git',
            '--literal-pathspecs',
            'add',
            '--',
            ...$changedFiles,
        ]);

        if ($add->failed()) {
            throw new RuntimeException('AIOS could not stage the verified Task candidate files.');
        }

        $stagedAfter = $this->git->stagedFiles($worktreePath);

        if (
            $stagedAfter === null
            || $stagedAfter !== $changedFiles
            || ! $this->git->matchesExpectedChanges($worktreePath, $baseSha, $changedFiles)
        ) {
            throw new RuntimeException('AIOS rejected the Task candidate because its staged change set no longer matches the verified files.');
        }

        $tree = $this->run($worktreePath, ['git', 'write-tree']);
        $message = $this->candidateMessage($task, $attempt);

        try {
            $commit = Process::path($worktreePath)
                ->input($message)
                ->run([
                    'git',
                    'commit-tree',
                    trim($tree),
                    '-p',
                    $baseSha,
                ]);
        } catch (Throwable $throwable) {
            throw new RuntimeException('AIOS could not create the Task candidate commit.', 0, $throwable);
        }

        if ($commit->failed()) {
            throw new RuntimeException('AIOS could not create the Task candidate commit.');
        }

        $candidateSha = $this->normalizeExactObjectId(trim($commit->output()));
        $updateRef = Process::path($repositoryPath)->run([
            'git',
            'update-ref',
            $candidateRef,
            $candidateSha,
        ]);

        if ($updateRef->failed()) {
            throw new RuntimeException('AIOS could not make the Task candidate commit durably reachable.');
        }

        $candidate = $this->candidateFromRef($repositoryPath, $candidateRef, $baseSha, $task, $attempt);

        if ($candidate === null) {
            throw new RuntimeException('AIOS could not verify the durable Task candidate reference after creation.');
        }

        if ($candidate['changed_files'] !== $changedFiles) {
            throw new RuntimeException('The durable Task candidate diff does not match the verified worktree change set.');
        }

        return $candidate;
    }

    /**
     * Resolve an already-created durable Task candidate without trusting prior in-memory state.
     *
     * @return array{
     *     base_sha: string,
     *     candidate_sha: ?string,
     *     candidate_ref: ?string,
     *     candidate_diff_sha256: string,
     *     changed_files: list<string>,
     *     no_changes: bool
     * }|null
     */
    public function recoverCandidate(Task $task, TaskAttempt $attempt): ?array
    {
        $this->assertAttemptOwnership($task, $attempt);
        $task->loadMissing('project');

        $repositoryPath = $this->paths->assertProjectPath((string) $task->project->path);
        $baseSha = $this->normalizeExactObjectId((string) $attempt->base_sha);

        return $this->candidateFromRef(
            $repositoryPath,
            $this->candidateRef($task, $attempt),
            $baseSha,
            $task,
            $attempt,
        );
    }

    /**
     * Serialize and safely integrate one verified Task candidate into the canonical repository.
     *
     * @param array{
     *     base_sha: string,
     *     candidate_sha: ?string,
     *     candidate_ref: ?string,
     *     candidate_diff_sha256: string,
     *     changed_files: list<string>,
     *     no_changes: bool
     * } $candidate
     * @return array{
     *     passed: bool,
     *     status: string,
     *     base_sha: string,
     *     candidate_sha: ?string,
     *     candidate_ref: ?string,
     *     candidate_diff_sha256: string,
     *     changed_files: list<string>,
     *     canonical_head_before: ?string,
     *     canonical_head_after: ?string,
     *     integrated_sha: ?string,
     *     conflict_paths: list<string>,
     *     summary: string
     * }
     */
    public function integrate(Task $task, TaskAttempt $attempt, array $candidate): array
    {
        $this->assertAttemptOwnership($task, $attempt);
        $task->loadMissing('project');

        $repositoryPath = $this->paths->assertProjectPath((string) $task->project->path);
        $baseSha = $this->normalizeExactObjectId((string) $attempt->base_sha);
        $candidateSha = $candidate['candidate_sha'];
        $candidateRef = $candidate['candidate_ref'];
        $changedFiles = is_array($candidate['changed_files'] ?? null)
            ? $this->normalizeFiles($candidate['changed_files'])
            : null;
        $diffIdentity = $candidate['candidate_diff_sha256'] ?? null;

        if (
            ! is_string($candidate['base_sha'] ?? null)
            || ! hash_equals($baseSha, $candidate['base_sha'])
            || $changedFiles === null
            || ! is_string($diffIdentity)
            || preg_match('/\A[0-9a-f]{64}\z/', $diffIdentity) !== 1
        ) {
            throw new RuntimeException('The Task candidate base does not match the persisted attempt base.');
        }

        if ($candidateSha === null) {
            if (
                ($candidate['no_changes'] ?? null) !== true
                || $candidateRef !== null
                || $changedFiles !== []
                || ! hash_equals(hash('sha256', ''), $diffIdentity)
            ) {
                throw new RuntimeException('The empty Task candidate evidence is inconsistent.');
            }
        } else {
            $candidateSha = $this->normalizeExactObjectId($candidateSha);

            if (
                ($candidate['no_changes'] ?? null) !== false
                || ! is_string($candidateRef)
                || $candidateRef !== $this->candidateRef($task, $attempt)
            ) {
                throw new RuntimeException('The Task candidate reference does not match the durable AIOS attempt identity.');
            }
        }

        /** @var Lock $lock */
        $lock = Cache::store('database')->lock(
            $this->repositoryLockName($task->project),
            self::IntegrationLockSeconds,
        );

        try {
            return $lock->block(self::IntegrationLockWaitSeconds, function () use (
                $lock,
                $repositoryPath,
                $task,
                $attempt,
                $candidate,
                $baseSha,
                $candidateSha,
                $candidateRef,
            ): array {
                if (! $lock->isOwnedByCurrentProcess()) {
                    return $this->failure($candidate, 'lock_lost', null, null, [], 'AIOS lost the repository integration lock before Git mutation.');
                }

                $before = $this->git->inspect($repositoryPath);
                $headBefore = $before['head_sha'];

                if (! $before['inspectable'] || ! $before['clean'] || $headBefore === null) {
                    return $this->failure($candidate, 'canonical_repository_unsafe', $headBefore, $headBefore, [], 'The canonical repository is not clean and inspectable under the integration lock.');
                }

                $headBefore = $this->normalizeExactObjectId($headBefore);

                if ($candidateSha === null) {
                    if (! hash_equals($baseSha, $headBefore)) {
                        return $this->failure($candidate, 'stale_no_change', $headBefore, $headBefore, [], 'The empty Task candidate was produced from an older canonical HEAD and requires a fresh Coder attempt.');
                    }

                    return $this->success($candidate, 'already_satisfied', $headBefore, $headBefore, null, 'The Task produced no candidate changes and the canonical HEAD still matches its persisted base.');
                }

                $recoveredCandidate = $this->candidateFromRef(
                    $repositoryPath,
                    (string) $candidateRef,
                    $baseSha,
                    $task,
                    $attempt,
                );

                if (
                    $recoveredCandidate === null
                    || ! hash_equals((string) $recoveredCandidate['candidate_sha'], $candidateSha)
                    || ! hash_equals($recoveredCandidate['candidate_diff_sha256'], $candidate['candidate_diff_sha256'])
                    || $recoveredCandidate['changed_files'] !== $candidate['changed_files']
                ) {
                    return $this->failure($candidate, 'candidate_mismatch', $headBefore, $headBefore, [], 'The durable Task candidate could not be proven to match the supplied integration evidence.');
                }

                $alreadyIntegrated = $this->findIntegratedCommit($repositoryPath, $task, $attempt, $candidate['changed_files']);

                if ($alreadyIntegrated !== null) {
                    return $this->success($candidate, 'already_integrated', $headBefore, $headBefore, $alreadyIntegrated, 'The exact Task attempt was already integrated into canonical history.');
                }

                if (! $this->isAncestor($repositoryPath, $baseSha, $headBefore)) {
                    return $this->failure($candidate, 'base_not_ancestor', $headBefore, $headBefore, [], 'The Task base is not an ancestor of the current canonical HEAD, so AIOS will not integrate across unrelated history.');
                }

                if (! $lock->isOwnedByCurrentProcess()) {
                    return $this->failure($candidate, 'lock_lost', $headBefore, $headBefore, [], 'AIOS lost the repository integration lock before Git mutation.');
                }

                $cherryPick = Process::path($repositoryPath)->run([
                    'git',
                    'cherry-pick',
                    $candidateSha,
                ]);

                if ($cherryPick->failed()) {
                    $conflictPaths = $this->conflictPaths($repositoryPath);
                    $abort = Process::path($repositoryPath)->run([
                        'git',
                        'cherry-pick',
                        '--abort',
                    ]);
                    $afterAbort = $this->git->inspect($repositoryPath);

                    if (
                        $abort->failed()
                        || ! $afterAbort['inspectable']
                        || ! $afterAbort['clean']
                        || ! is_string($afterAbort['head_sha'])
                        || ! hash_equals($headBefore, $afterAbort['head_sha'])
                    ) {
                        throw new RuntimeException('AIOS could not prove that the canonical repository returned to its exact pre-integration state after a conflict. Manual operator inspection is required.');
                    }

                    return $this->failure($candidate, 'conflict', $headBefore, $headBefore, $conflictPaths, 'Git rejected the stale Task candidate because it conflicts with the current canonical HEAD. A fresh Coder attempt is required.');
                }

                $after = $this->git->inspect($repositoryPath);
                $headAfter = $after['head_sha'];

                if (
                    ! $lock->isOwnedByCurrentProcess()
                    || ! $after['inspectable']
                    || ! $after['clean']
                    || ! is_string($headAfter)
                ) {
                    return $this->failure($candidate, 'integration_uncertain', $headBefore, is_string($headAfter) ? $headAfter : null, [], 'Canonical Git changed but AIOS could not prove lock ownership and a clean final repository state. Recovery must reconcile the durable candidate before further integration.');
                }

                $headAfter = $this->normalizeExactObjectId($headAfter);
                $integratedCommit = $this->findIntegratedCommit($repositoryPath, $task, $attempt, $candidate['changed_files']);

                if ($integratedCommit === null || ! hash_equals($integratedCommit, $headAfter)) {
                    return $this->failure($candidate, 'integration_verification_failed', $headBefore, $headAfter, [], 'AIOS could not prove that canonical HEAD is the verified commit for this exact Task attempt.');
                }

                return $this->success($candidate, 'integrated', $headBefore, $headAfter, $headAfter, 'The Task candidate was serialized and integrated into the canonical repository.');
            });
        } catch (LockTimeoutException) {
            return $this->failure($candidate, 'lock_timeout', null, null, [], 'AIOS could not acquire the canonical repository integration lock within the bounded wait window.');
        }
    }

    /**
     * Derive the database-backed lock name from the canonical repository shared Git metadata path.
     */
    public function repositoryLockName(Project $project): string
    {
        $repositoryPath = $this->paths->assertProjectPath((string) $project->path);
        $commonDirectory = $this->commonGitDirectory($repositoryPath);

        if ($commonDirectory === null) {
            throw new RuntimeException('AIOS could not derive a canonical Git repository identity for integration locking.');
        }

        return 'aios:repository-integration:'.hash('sha256', $commonDirectory);
    }

    /**
     * Resolve and validate an existing deterministic candidate ref when one already exists.
     *
     * @return array{
     *     base_sha: string,
     *     candidate_sha: ?string,
     *     candidate_ref: ?string,
     *     candidate_diff_sha256: string,
     *     changed_files: list<string>,
     *     no_changes: bool
     * }|null
     */
    private function candidateFromRef(
        string $repositoryPath,
        string $candidateRef,
        string $baseSha,
        Task $task,
        TaskAttempt $attempt,
    ): ?array {
        $resolved = Process::path($repositoryPath)->run([
            'git',
            'rev-parse',
            '--verify',
            $candidateRef.'^{commit}',
        ]);

        if ($resolved->failed()) {
            return null;
        }

        $candidateSha = $this->normalizeExactObjectId(trim($resolved->output()));
        $parent = $this->run($repositoryPath, ['git', 'rev-parse', $candidateSha.'^']);

        if (! hash_equals($baseSha, trim($parent))) {
            throw new RuntimeException('The durable Task candidate no longer has the persisted attempt base as its parent.');
        }

        $message = $this->run($repositoryPath, ['git', 'show', '-s', '--format=%B', $candidateSha]);

        if (! str_contains($message, $this->attemptTrailer($task, $attempt))) {
            throw new RuntimeException('The durable Task candidate does not carry the expected AIOS attempt identity.');
        }

        $changedFiles = $this->commitChangedFiles($repositoryPath, $baseSha, $candidateSha);
        $diff = $this->run($repositoryPath, ['git', 'diff', '--binary', '--no-ext-diff', '--no-renames', $baseSha, $candidateSha, '--']);

        return [
            'base_sha' => $baseSha,
            'candidate_sha' => $candidateSha,
            'candidate_ref' => $candidateRef,
            'candidate_diff_sha256' => hash('sha256', $diff),
            'changed_files' => $changedFiles,
            'no_changes' => false,
        ];
    }

    /**
     * Find a previously integrated canonical commit for the exact durable Task attempt.
     */
    private function findIntegratedCommit(
        string $repositoryPath,
        Task $task,
        TaskAttempt $attempt,
        array $expectedFiles,
    ): ?string {
        $result = Process::path($repositoryPath)->run([
            'git',
            'log',
            '--format=%H',
            '--fixed-strings',
            '--grep='.$this->attemptTrailer($task, $attempt),
            '--max-count=1',
            'HEAD',
        ]);

        if ($result->failed() || trim($result->output()) === '') {
            return null;
        }

        $commitSha = $this->normalizeExactObjectId(trim($result->output()));
        $parent = $this->run($repositoryPath, ['git', 'rev-parse', $commitSha.'^']);
        $actualFiles = $this->commitChangedFiles($repositoryPath, trim($parent), $commitSha);

        return $actualFiles === $this->normalizeFiles($expectedFiles)
            ? $commitSha
            : null;
    }

    /**
     * Return the exact file set changed between two commit objects.
     *
     * @return list<string>
     */
    private function commitChangedFiles(string $repositoryPath, string $baseSha, string $commitSha): array
    {
        $output = $this->run($repositoryPath, [
            'git',
            'diff',
            '--name-only',
            '--no-renames',
            '-z',
            $baseSha,
            $commitSha,
            '--',
        ]);

        return $this->normalizeFiles(explode("\0", $output));
    }

    /**
     * Return the current Git conflict paths before AIOS aborts its own cherry-pick operation.
     *
     * @return list<string>
     */
    private function conflictPaths(string $repositoryPath): array
    {
        $result = Process::path($repositoryPath)->run([
            'git',
            'diff',
            '--name-only',
            '--diff-filter=U',
            '-z',
            '--',
        ]);

        return $result->successful()
            ? $this->normalizeFiles(explode("\0", $result->output()))
            : [];
    }

    /**
     * Prove one exact base commit remains an ancestor of the canonical HEAD before stale-base integration.
     */
    private function isAncestor(string $repositoryPath, string $baseSha, string $headSha): bool
    {
        return Process::path($repositoryPath)->run([
            'git',
            'merge-base',
            '--is-ancestor',
            $baseSha,
            $headSha,
        ])->successful();
    }

    /**
     * Resolve the shared Git metadata directory used as the canonical repository lock identity.
     */
    private function commonGitDirectory(string $repositoryPath): ?string
    {
        $result = Process::path($repositoryPath)->run([
            'git',
            'rev-parse',
            '--git-common-dir',
        ]);

        if ($result->failed()) {
            return null;
        }

        $directory = trim($result->output());
        $candidate = str_starts_with($directory, DIRECTORY_SEPARATOR)
            ? $directory
            : $repositoryPath.DIRECTORY_SEPARATOR.$directory;
        $resolved = realpath($candidate);

        return $resolved === false
            ? null
            : rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * Build the deterministic AIOS-owned ref that keeps a detached Task candidate reachable.
     */
    private function candidateRef(Task $task, TaskAttempt $attempt): string
    {
        return sprintf(
            'refs/aios/projects/%d/tasks/%d/attempts/%d',
            (int) $task->project_id,
            (int) $task->getKey(),
            (int) $attempt->getKey(),
        );
    }

    /**
     * Build the exact commit message used both for candidate reviewability and crash reconciliation.
     */
    private function candidateMessage(Task $task, TaskAttempt $attempt): string
    {
        return "{$task->key}: {$task->title}\n\n{$this->attemptTrailer($task, $attempt)}\n";
    }

    /**
     * Build a stable commit trailer identifying this exact durable AIOS Task attempt.
     */
    private function attemptTrailer(Task $task, TaskAttempt $attempt): string
    {
        return sprintf(
            'AIOS-Task-Attempt: %d/%d/%d',
            (int) $task->project_id,
            (int) $task->getKey(),
            (int) $attempt->getKey(),
        );
    }

    /**
     * Return a normalized success integration result.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function success(
        array $candidate,
        string $status,
        string $headBefore,
        string $headAfter,
        ?string $integratedSha,
        string $summary,
    ): array {
        return [
            'passed' => true,
            'status' => $status,
            'base_sha' => $candidate['base_sha'],
            'candidate_sha' => $candidate['candidate_sha'],
            'candidate_ref' => $candidate['candidate_ref'],
            'candidate_diff_sha256' => $candidate['candidate_diff_sha256'],
            'changed_files' => $candidate['changed_files'],
            'canonical_head_before' => $headBefore,
            'canonical_head_after' => $headAfter,
            'integrated_sha' => $integratedSha,
            'conflict_paths' => [],
            'summary' => $summary,
        ];
    }

    /**
     * Return a normalized failed integration result without mutating durable workflow state.
     *
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $conflictPaths
     * @return array<string, mixed>
     */
    private function failure(
        array $candidate,
        string $status,
        ?string $headBefore,
        ?string $headAfter,
        array $conflictPaths,
        string $summary,
    ): array {
        return [
            'passed' => false,
            'status' => $status,
            'base_sha' => $candidate['base_sha'],
            'candidate_sha' => $candidate['candidate_sha'],
            'candidate_ref' => $candidate['candidate_ref'],
            'candidate_diff_sha256' => $candidate['candidate_diff_sha256'],
            'changed_files' => $candidate['changed_files'],
            'canonical_head_before' => $headBefore,
            'canonical_head_after' => $headAfter,
            'integrated_sha' => null,
            'conflict_paths' => $conflictPaths,
            'summary' => $summary,
        ];
    }

    /**
     * Execute one Git inspection command and return its stdout or fail closed.
     *
     * @param  list<string>  $command
     */
    private function run(string $path, array $command): string
    {
        try {
            $result = Process::path($path)->run($command);
        } catch (Throwable $throwable) {
            throw new RuntimeException('AIOS could not inspect the managed Git repository.', 0, $throwable);
        }

        if ($result->failed()) {
            throw new RuntimeException('AIOS could not inspect the managed Git repository.');
        }

        return $result->output();
    }

    /**
     * Require a literal SHA-1 or SHA-256 object ID instead of a ref or revision expression.
     */
    private function normalizeExactObjectId(string $sha): string
    {
        $sha = strtolower(trim($sha));

        if (preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $sha) !== 1) {
            throw new RuntimeException('AIOS Git integration requires an exact commit SHA.');
        }

        return $sha;
    }

    /**
     * Normalize a Git file list into deterministic sorted repository-relative paths.
     *
     * @param  array<int, string>  $files
     * @return list<string>
     */
    private function normalizeFiles(array $files): array
    {
        $files = array_values(array_unique(array_filter($files, fn (string $file): bool => $file !== '')));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Reject cross-Task attempt usage before any Git inspection or mutation is attempted.
     */
    private function assertAttemptOwnership(Task $task, TaskAttempt $attempt): void
    {
        if (
            ! $task->exists
            || ! $attempt->exists
            || (int) $attempt->task_id !== (int) $task->getKey()
        ) {
            throw new LogicException('Task Git integration requires a persisted attempt owned by the same Task.');
        }
    }
}
