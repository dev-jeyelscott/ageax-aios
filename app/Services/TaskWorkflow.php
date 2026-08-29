<?php

namespace App\Services;

use App\AgentRole;
use App\Exceptions\InvalidTaskTransition;
use App\Models\AgentWorker;
use App\Models\AuditEvent;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ParallelTaskSafety;
use App\ProjectStatus;
use App\ReviewStatus;
use App\TaskStatus;
use App\WorkerLease;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class TaskWorkflow
{
    /**
     * Create the workflow service with its durable collaborators, repository guards, and deterministic parallel-safety evaluator.
     */
    public function __construct(
        private AuditLogger $audit,
        private ObsidianProjectNotes $notes,
        private CoderRepositoryGuard $repositoryGuard,
        private NoProgressRetryGuard $noProgress,
        private ParallelTaskSafetyEvaluator $parallelSafety,
    ) {}

    /**
     * Claim the next eligible Task for the requested Agent role using the existing serial workflow path.
     */
    public function claim(Project $project, AgentRole $role): ?Task
    {
        $task = DB::transaction(function () use ($project, $role): ?Task {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);

            if (ProjectStatus::from($lockedProject->getRawOriginal('status')) !== ProjectStatus::Running || $this->hasClaimedWork($lockedProject, $role)) {
                return null;
            }

            $task = match ($role) {
                AgentRole::Coder => $this->nextCoderTask($lockedProject),
                AgentRole::Reviewer => $this->reviewableTask($lockedProject),
                default => null,
            };

            if ($task === null) {
                return null;
            }

            if ($role === AgentRole::Coder && ! $this->repositoryAllowsCoderClaim($lockedProject, $task)) {
                return null;
            }

            $status = $role === AgentRole::Coder ? TaskStatus::Coding : TaskStatus::Reviewing;
            $this->transitionLocked($task, $status);
            $this->audit->record('task.claimed', ['role' => $role->value], $lockedProject, $task);

            return $task->refresh();
        }, attempts: 3);

        $this->notes->writeState($project);

        return $task;
    }

    /**
     * Claim one independent Coder Task for an exact live worker lease while serializing admission through the Project row lock.
     */
    public function claimConcurrentCoder(Project $project, WorkerLease $lease): ?Task
    {
        $task = DB::transaction(function () use ($project, $lease): ?Task {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);

            if (ProjectStatus::from($lockedProject->getRawOriginal('status')) !== ProjectStatus::Running) {
                return null;
            }

            $worker = $this->lockedCoderWorkerForLease($lockedProject, $lease);

            if ($worker === null || $this->workerHasActiveCoderClaim($lockedProject, $worker)) {
                return null;
            }

            $task = $this->nextCoderTask($lockedProject);

            if ($task === null) {
                return null;
            }

            $activeTasks = $this->activeCoderTasks($lockedProject);

            foreach ($activeTasks as $activeTask) {
                if (! $this->hasDurableLiveCoderClaimOwner($lockedProject, $activeTask)) {
                    return null;
                }
            }

            if (! $this->repositoryAllowsCoderClaim($lockedProject, $task)) {
                return null;
            }

            foreach ($activeTasks as $activeTask) {
                $assessment = $this->parallelSafety->evaluate($task, $activeTask);

                if ($assessment['decision'] !== ParallelTaskSafety::Safe) {
                    return null;
                }
            }

            $leaseExpiresAt = $worker->getAttribute('lease_expires_at');

            if (! $leaseExpiresAt instanceof CarbonImmutable || ! $leaseExpiresAt->isFuture()) {
                return null;
            }

            $task->forceFill([
                'coder_worker_id' => $worker->id,
                'coder_worker_lease_id' => $lease->leaseId,
            ])->save();

            $this->transitionLocked($task, TaskStatus::Coding);
            $this->audit->record('task.claimed', [
                'role' => AgentRole::Coder->value,
                'agent_worker_id' => $worker->id,
                'slot' => (int) $worker->slot,
                'worker_instance_id' => $lease->workerInstanceId,
                'lease_id' => $lease->leaseId,
                'concurrent_claim' => true,
                'active_task_ids' => $activeTasks
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            ], $lockedProject, $task);

            return $task->refresh();
        }, attempts: 3);

        $this->notes->writeState($project);

        return $task;
    }

    /**
     * Transition a Task through the authoritative workflow state machine.
     */
    public function transition(Task $task, TaskStatus $to): Task
    {
        $transitionedTask = DB::transaction(function () use ($task, $to): Task {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $this->transitionLocked($lockedTask, $to);

            return $lockedTask->refresh();
        }, attempts: 3);

        if ($to === TaskStatus::Done) {
            $this->notes->writeTaskCompletion($transitionedTask, "Completed after workflow validation. {$transitionedTask->objective}");
        }

        $this->notes->writeState($transitionedTask->project);

        return $transitionedTask;
    }

    /**
     * Return the currently claimed non-cleared Task for an Agent role.
     */
    public function claimedTask(Project $project, AgentRole $role): ?Task
    {
        $statuses = $role === AgentRole::Coder
            ? [TaskStatus::Coding, TaskStatus::Validating]
            : [TaskStatus::Reviewing];

        return $project->tasks()
            ->notCleared()
            ->whereIn('status', $statuses)
            ->orderBy('position')
            ->first();
    }

    /**
     * Block repeated rejected Reviews that show no implementation progress.
     */
    public function blockRepeatedRejectedReviews(Task $task): bool
    {
        return DB::transaction(function () use ($task): bool {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (TaskStatus::from($lockedTask->getRawOriginal('status')) !== TaskStatus::ChangesRequired) {
                return false;
            }

            $threshold = max(1, (int) config('aios.review_no_progress_block_threshold'));
            $requeueEvent = $lockedTask->auditEvents()
                ->where('event_type', 'task.requeued')
                ->latest('id')
                ->first();

            $reviews = $lockedTask->reviews()
                ->where('status', ReviewStatus::ChangesRequired)
                ->when($requeueEvent !== null, fn ($query) => $query->where('completed_at', '>', $requeueEvent->occurred_at))
                ->latest('completed_at')
                ->limit($threshold)
                ->with('attempt')
                ->get();

            if ($reviews->count() !== $threshold) {
                return false;
            }

            $attempts = $reviews
                ->map(fn (Review $review): ?TaskAttempt => $review->attempt)
                ->filter()
                ->values();

            if ($attempts->count() !== $threshold) {
                return false;
            }

            $headShas = $attempts->pluck('head_sha')->unique();
            $baseShas = $attempts->pluck('base_sha')->unique();
            $fingerprints = $attempts
                ->map(fn (TaskAttempt $attempt): ?string => $this->taskContractFingerprint($attempt))
                ->filter()
                ->unique();

            $hasChangedFiles = $attempts->contains(
                fn (TaskAttempt $attempt): bool => $this->attemptHasChangedFiles($attempt),
            );

            if ($headShas->count() !== 1 || $baseShas->count() !== 1 || $fingerprints->count() !== 1 || $hasChangedFiles) {
                return false;
            }

            $orderedAttempts = $attempts->sortBy('number')->values();

            $this->transitionLocked($lockedTask, TaskStatus::Blocked);

            $this->audit->record('task.review_no_progress_blocked', [
                'threshold' => $threshold,
                'attempt_numbers' => $orderedAttempts->pluck('number')->all(),
                'base_sha' => $baseShas->first(),
                'head_sha' => $headShas->first(),
                'task_contract_fingerprint' => $fingerprints->first(),
            ], $lockedTask->project, $lockedTask);

            return true;
        }, attempts: 3);
    }

    /**
     * Return the deterministic task-contract fingerprint recorded on an attempt.
     */
    private function taskContractFingerprint(TaskAttempt $attempt): ?string
    {
        $validationResults = $attempt->getAttribute('validation_results');
        $taskContract = is_array($validationResults) ? $validationResults['task_contract'] ?? null : null;

        return is_array($taskContract) && is_string($taskContract['fingerprint'] ?? null)
            ? $taskContract['fingerprint']
            : null;
    }

    /**
     * Determine whether an attempt contains any changed repository files.
     */
    private function attemptHasChangedFiles(TaskAttempt $attempt): bool
    {
        $changedFiles = $attempt->getAttribute('changed_files');

        return is_array($changedFiles) && $changedFiles !== [];
    }

    /**
     * Persist and apply a finalized Reviewer decision.
     *
     * @param  array<int, array<string, string>>  $findings
     */
    public function finalizeReviewerDecision(Task $task, TaskAttempt $attempt, ReviewStatus $outcome, ?string $summary = null, array $findings = []): Review
    {
        if (! in_array($outcome, [ReviewStatus::Approved, ReviewStatus::ChangesRequired], true)
            || ($outcome === ReviewStatus::ChangesRequired && $findings === [])) {
            throw new InvalidTaskTransition('A finalized review requires an approved outcome or actionable changes-required findings.');
        }

        $review = DB::transaction(function () use ($task, $attempt, $outcome, $summary, $findings): Review {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $lockedAttempt = TaskAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            $this->assertReviewAttemptBelongsToTask($lockedTask, $lockedAttempt);

            $existing = Review::query()
                ->where('task_attempt_id', $lockedAttempt->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->applyFinalizedReviewLocked($lockedTask, $lockedAttempt, $existing);

                return $existing;
            }

            if (TaskStatus::from($lockedTask->getRawOriginal('status')) !== TaskStatus::Reviewing) {
                throw new InvalidTaskTransition('Only a claimed review task can be decided.');
            }

            $review = Review::create([
                'task_id' => $lockedTask->id,
                'task_attempt_id' => $lockedAttempt->id,
                'status' => $outcome,
                'summary' => $summary,
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            foreach ($findings as $finding) {
                $reviewFinding = ReviewFinding::create(['review_id' => $review->id, ...$finding]);

                $this->audit->record('review.finding_recorded', [
                    'review_id' => $review->id,
                    'review_finding_id' => $reviewFinding->id,
                    'severity' => $reviewFinding->severity,
                    'location' => $reviewFinding->location,
                ], $lockedTask->project, $lockedTask);
            }

            $this->audit->record('review.completed', [
                'review_id' => $review->id,
                'outcome' => $outcome->value,
                'finding_count' => count($findings),
                'attempt_number' => $lockedAttempt->number,
            ], $lockedTask->project, $lockedTask);

            $this->applyFinalizedReviewLocked($lockedTask, $lockedAttempt, $review);

            return $review->fresh('findings') ?? $review;
        }, attempts: 3);

        $finalizedTask = $task->fresh();

        if ($finalizedTask !== null) {
            $this->notes->writeReview($finalizedTask, $review);

            if ($outcome === ReviewStatus::Approved) {
                $this->notes->writeTaskCompletion(
                    $finalizedTask,
                    $summary ?? "Approved implementation of {$finalizedTask->title}.",
                    $attempt,
                    $summary,
                );
            }
        }

        return $review;
    }

    /**
     * Reconcile an already persisted Reviewer decision into Task workflow state.
     */
    public function reconcileExistingReviewerDecision(Task $task, TaskAttempt $attempt): ?Review
    {
        return DB::transaction(function () use ($task, $attempt): ?Review {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $lockedAttempt = TaskAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            $this->assertReviewAttemptBelongsToTask($lockedTask, $lockedAttempt);

            $review = Review::query()
                ->where('task_attempt_id', $lockedAttempt->id)
                ->lockForUpdate()
                ->first();

            if ($review === null) {
                return null;
            }

            $this->applyFinalizedReviewLocked($lockedTask, $lockedAttempt, $review);

            $this->audit->record('review.finalization_recovered', [
                'review_id' => $review->id,
                'attempt_number' => $lockedAttempt->number,
                'outcome' => $review->getRawOriginal('status'),
                'reason' => 'existing_finalized_review_before_reviewer_execution',
            ], $lockedTask->project, $lockedTask);

            return $review;
        }, attempts: 3);
    }

    /**
     * Record an operational Reviewer failure and deterministically schedule recovery.
     *
     * @param  array<string, mixed>  $failure
     */
    public function recordReviewerOperationalFailure(Task $task, ?TaskAttempt $attempt, array $failure): Task
    {
        $transitionedTask = DB::transaction(function () use ($task, $attempt, $failure): Task {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (TaskStatus::from($lockedTask->getRawOriginal('status')) !== TaskStatus::Reviewing) {
                throw new InvalidTaskTransition('Only a claimed review task can record an operational failure.');
            }

            $failureCount = AuditEvent::query()
                ->whereBelongsTo($lockedTask)
                ->where('event_type', 'review.failed')
                ->get()
                ->filter(function (AuditEvent $event) use ($attempt): bool {
                    $payload = json_decode($event->getRawOriginal('payload'), true);

                    return is_array($payload) && ($payload['attempt_number'] ?? null) === $attempt?->number;
                })
                ->count() + 1;

            $retryLimit = max(1, (int) config('aios.max_reviewer_attempts'));
            $noProgress = $this->noProgress->reviewerFailure($lockedTask, $attempt, $failure);
            $retryExhausted = $failureCount >= $retryLimit;
            $noProgressDetected = ! $retryExhausted && $noProgress['detected'];
            $retryStatus = $retryExhausted || $noProgressDetected
                ? TaskStatus::Blocked
                : TaskStatus::ReadyForReview;

            $payload = [
                ...$failure,
                'attempt_number' => $attempt?->number,
                'retry_count' => $failureCount,
                'retry_limit' => $retryLimit,
                'no_progress' => $noProgress,
            ];

            $this->audit->record('review.failed', $payload, $lockedTask->project, $lockedTask);
            $this->transitionLocked($lockedTask, $retryStatus);

            if ($retryExhausted) {
                $this->audit->record('review.retry_exhausted', $payload, $lockedTask->project, $lockedTask);
            } elseif ($noProgressDetected) {
                $this->audit->record('task.no_progress_detected', [
                    'operation' => 'reviewer',
                    'attempt_number' => $attempt?->number,
                    'failure_fingerprint' => $noProgress['failure_fingerprint'],
                    'consecutive_identical_failures' => $noProgress['consecutive_identical_failures'],
                    'consecutive_repeat_count' => $noProgress['consecutive_repeat_count'],
                    'threshold' => $noProgress['threshold'],
                    'reason' => $failure['reason'] ?? null,
                    'base_sha' => $attempt?->base_sha,
                    'head_sha' => $attempt?->head_sha,
                    'commit_sha' => $attempt?->commit_sha,
                    'changed_files' => $attempt === null ? [] : ($attempt->changed_files ?? []),
                    'repository_fingerprint' => $noProgress['repository_fingerprint'],
                ], $lockedTask->project, $lockedTask);
            } else {
                $this->audit->record('review.retry_scheduled', $payload, $lockedTask->project, $lockedTask);
            }

            return $lockedTask->refresh();
        }, attempts: 3);

        $this->notes->writeState($transitionedTask->project);

        return $transitionedTask;
    }

    /**
     * Approve a currently reviewing Task and persist its completion evidence.
     */
    public function approveTask(Task $task, ?TaskAttempt $attempt = null, ?string $reviewSummary = null): Task
    {
        $completedTask = DB::transaction(function () use ($task): Task {
            $reviewTask = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (TaskStatus::from($reviewTask->getRawOriginal('status')) !== TaskStatus::Reviewing) {
                throw new InvalidTaskTransition('Only a claimed review task can be approved.');
            }

            $this->transitionLocked($reviewTask, TaskStatus::Done);

            return $reviewTask->refresh();
        }, attempts: 3);

        $summary = $reviewSummary ?? "Approved implementation of {$completedTask->title}.";

        $this->notes->writeTaskCompletion($completedTask, $summary, $attempt, $reviewSummary);
        $this->audit->record('task.approved', ['attempt_number' => $attempt?->number], $completedTask->project, $completedTask);

        return $completedTask;
    }

    /**
     * Resolve the exact live Coder worker lease that is requesting concurrent admission.
     */
    private function lockedCoderWorkerForLease(Project $project, WorkerLease $lease): ?AgentWorker
    {
        return AgentWorker::query()
            ->whereKey($lease->workerId)
            ->whereBelongsTo($project)
            ->where('role', AgentRole::Coder)
            ->where('worker_instance_id', $lease->workerInstanceId)
            ->where('lease_id', $lease->leaseId)
            ->where('lease_expires_at', '>=', now())
            ->lockForUpdate()
            ->first();
    }

    /**
     * Prevent one Coder slot from owning more than one active implementation Task at a time.
     */
    private function workerHasActiveCoderClaim(Project $project, AgentWorker $worker): bool
    {
        return Task::query()
            ->whereBelongsTo($project)
            ->notCleared()
            ->where('coder_worker_id', $worker->id)
            ->whereIn('status', [
                TaskStatus::Coding,
                TaskStatus::Validating,
            ])
            ->exists();
    }

    /**
     * Return all currently active Coder Tasks while the Project admission lock is held.
     *
     * @return EloquentCollection<int, Task>
     */
    private function activeCoderTasks(Project $project): EloquentCollection
    {
        return Task::query()
            ->whereBelongsTo($project)
            ->notCleared()
            ->whereIn('status', [
                TaskStatus::Coding,
                TaskStatus::Validating,
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Require every active Coder Task to have exact durable ownership tied to a still-live worker lease.
     */
    private function hasDurableLiveCoderClaimOwner(Project $project, Task $task): bool
    {
        $workerId = $task->getAttribute('coder_worker_id');
        $leaseId = $task->getAttribute('coder_worker_lease_id');

        if (! is_int($workerId) || ! is_string($leaseId) || $leaseId === '') {
            return false;
        }

        return AgentWorker::query()
            ->whereKey($workerId)
            ->whereBelongsTo($project)
            ->where('role', AgentRole::Coder)
            ->where('lease_id', $leaseId)
            ->where('lease_expires_at', '>=', now())
            ->exists();
    }

    /**
     * Determine whether a role already owns claimed work for the Project.
     */
    private function hasClaimedWork(Project $project, AgentRole $role): bool
    {
        return $this->claimedTask($project, $role) !== null;
    }

    /**
     * Ensure a Reviewer TaskAttempt belongs to the Task being finalized.
     */
    private function assertReviewAttemptBelongsToTask(Task $task, TaskAttempt $attempt): void
    {
        if ((int) $attempt->task_id !== (int) $task->id) {
            throw new InvalidTaskTransition('The Reviewer TaskAttempt does not belong to the claimed Task.');
        }
    }

    /**
     * Apply an already persisted finalized Review to the locked Task state.
     */
    private function applyFinalizedReviewLocked(Task $task, TaskAttempt $attempt, Review $review): void
    {
        if ((int) $review->task_id !== (int) $task->id
            || (int) $review->task_attempt_id !== (int) $attempt->id
            || $review->getRawOriginal('completed_at') === null) {
            throw new InvalidTaskTransition('The persisted Review is not a finalized decision for this TaskAttempt.');
        }

        $outcome = ReviewStatus::from($review->getRawOriginal('status'));

        if ($outcome === ReviewStatus::ChangesRequired && ! $review->findings()->exists()) {
            throw new InvalidTaskTransition('A finalized changes-required Review must contain actionable findings.');
        }

        $target = $outcome === ReviewStatus::Approved
            ? TaskStatus::Done
            : TaskStatus::ChangesRequired;

        $current = TaskStatus::from($task->getRawOriginal('status'));

        if ($current === $target) {
            return;
        }

        if ($current !== TaskStatus::Reviewing) {
            throw new InvalidTaskTransition("A finalized {$outcome->value} Review cannot reconcile Task state {$current->value}.");
        }

        $this->transitionLocked($task, $target);

        $this->audit->record(
            $outcome === ReviewStatus::Approved ? 'task.approved' : 'task.rejected',
            [
                'review_id' => $review->id,
                'attempt_number' => $attempt->number,
            ],
            $task->project,
            $task,
        );
    }

    /**
     * Verify repository safety before allowing a Coder to claim a Task.
     */
    private function repositoryAllowsCoderClaim(Project $project, Task $task): bool
    {
        $preflight = $this->repositoryGuard->inspect($task);
        $state = $preflight['state'];

        $project->update([
            'git_head_sha' => $state['head_sha'],
            'git_status' => $state['inspectable'] ? ($state['clean'] ? 'clean' : 'dirty') : 'unknown',
        ]);

        if ($preflight['allowed']) {
            if ($preflight['mode'] === 'recovery' && $preflight['recovery_attempt'] !== null) {
                $this->audit->record('task.repository_recovery_allowed', [
                    'attempt_number' => $preflight['recovery_attempt']->number,
                    'attempt_status' => $preflight['recovery_attempt']->status,
                    'base_sha' => $preflight['base_sha'],
                    'changed_files' => $preflight['recovery_attempt']->changed_files ?? [],
                ], $project, $task);
            }

            return true;
        }

        $this->transitionLocked($task, TaskStatus::Blocked);

        $this->audit->record('task.blocked_dirty_repository', [
            'reason' => $state['inspectable'] ? 'repository_not_clean' : 'repository_state_unavailable',
            'head_sha' => $state['head_sha'],
            'base_sha' => $state['base_sha'],
            'staged_files' => $state['staged_files'],
            'unstaged_files' => $state['unstaged_files'],
            'untracked_files' => $state['untracked_files'],
            'errors' => $state['errors'],
            'action' => 'Resolve the repository state manually, then requeue the task. AIOS did not stash, reset, clean, discard, or commit these changes.',
        ], $project, $task);

        return false;
    }

    /**
     * Apply a validated workflow transition while the Task is locked.
     */
    private function transitionLocked(Task $task, TaskStatus $to): void
    {
        $from = TaskStatus::from($task->getRawOriginal('status'));

        if (! in_array($to, $this->allowedTransitions($from), true)) {
            throw new InvalidTaskTransition("Cannot transition {$from->value} to {$to->value}.");
        }

        $task->update([
            'status' => $to,
            'claimed_at' => $to === TaskStatus::Coding || $to === TaskStatus::Reviewing ? now() : $task->claimed_at,
            'completed_at' => $to === TaskStatus::Done ? now() : null,
        ]);

        $this->audit->record('task.transitioned', [
            'from' => $from->value,
            'to' => $to->value,
        ], $task->project, $task);
    }

    /**
     * Return the allowlisted workflow transitions for a Task status.
     *
     * @return array<TaskStatus>
     */
    private function allowedTransitions(TaskStatus $from): array
    {
        return match ($from) {
            TaskStatus::Queued => [TaskStatus::Coding, TaskStatus::Cancelled, TaskStatus::Blocked],
            TaskStatus::Coding => [TaskStatus::ReadyForReview, TaskStatus::Validating, TaskStatus::Interrupted, TaskStatus::Failed, TaskStatus::Blocked],
            TaskStatus::Validating => [TaskStatus::ReadyForReview, TaskStatus::Failed, TaskStatus::Interrupted, TaskStatus::Blocked],
            TaskStatus::ReadyForReview => [TaskStatus::Reviewing, TaskStatus::Done, TaskStatus::Interrupted],
            TaskStatus::Reviewing => [TaskStatus::Done, TaskStatus::ChangesRequired, TaskStatus::ReadyForReview, TaskStatus::Interrupted, TaskStatus::Blocked],
            TaskStatus::ChangesRequired => [TaskStatus::Coding, TaskStatus::Cancelled, TaskStatus::Blocked],
            TaskStatus::Interrupted => [TaskStatus::Coding, TaskStatus::Reviewing, TaskStatus::Failed],
            TaskStatus::Blocked => [TaskStatus::Queued, TaskStatus::ChangesRequired, TaskStatus::ReadyForReview, TaskStatus::Cancelled],
            TaskStatus::Failed => [TaskStatus::Coding, TaskStatus::Blocked, TaskStatus::Cancelled],
            TaskStatus::Done => [],
            TaskStatus::Cancelled => [TaskStatus::ChangesRequired],
        };
    }

    /**
     * Return the next dependency-safe non-cleared Task eligible for Coder execution.
     */
    private function nextCoderTask(Project $project): ?Task
    {
        $phase = $this->currentPhase($project);

        $query = Task::query()
            ->whereBelongsTo($project)
            ->notCleared()
            ->whereIn('status', TaskStatus::coderClaimableValues())
            ->whereDoesntHave(
                'planningEscalations',
                fn ($escalations) => $escalations->whereIn(
                    'status',
                    ['pending', 'running'],
                ),
            );

        if ($phase !== null) {
            $query->where('phase_id', $phase->id);
        }

        return $query
            ->orderBy('position')
            ->lockForUpdate()
            ->get()
            ->first(fn (Task $task): bool => $this->dependenciesAreSatisfied($task));
    }

    /**
     * Return the next non-cleared Task eligible for Reviewer execution.
     */
    private function reviewableTask(Project $project): ?Task
    {
        $phase = $this->currentPhase($project);

        if ($phase === null) {
            return Task::query()
                ->whereBelongsTo($project)
                ->notCleared()
                ->whereIn('status', TaskStatus::reviewerClaimableValues())
                ->orderBy('position')
                ->lockForUpdate()
                ->first();
        }

        $phaseTasks = Task::query()
            ->whereBelongsTo($project)
            ->notCleared()
            ->where('phase_id', $phase->id)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();

        if (! $phaseTasks->every(
            fn (Task $task): bool => TaskStatus::from($task->getRawOriginal('status'))->satisfiesPhaseReviewBarrier(),
        )) {
            return null;
        }

        return $phaseTasks->first(
            fn (Task $task): bool => TaskStatus::from($task->getRawOriginal('status'))->isReviewerClaimable(),
        );
    }

    /**
     * Return the first Phase that still contains a non-cleared unresolved Task.
     */
    private function currentPhase(Project $project): ?Phase
    {
        return Phase::query()
            ->whereBelongsTo($project)
            ->whereHas(
                'tasks',
                fn ($tasks) => $tasks
                    ->where('is_cleared', false)
                    ->whereNotIn(
                        'status',
                        TaskStatus::phaseCompletionSatisfiedValues(),
                    ),
            )
            ->orderBy('position')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Determine whether all durable dependencies permit this Task to execute.
     */
    private function dependenciesAreSatisfied(Task $task): bool
    {
        return $task->dependencies()->get()->every(function (Task $dependency) use ($task): bool {
            $status = TaskStatus::from($dependency->getRawOriginal('status'));
            $samePhase = $task->phase_id !== null && $dependency->phase_id === $task->phase_id;

            return $status->satisfiesDependency($samePhase);
        });
    }
}
