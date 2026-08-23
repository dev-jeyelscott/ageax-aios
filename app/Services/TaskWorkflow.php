<?php

namespace App\Services;

use App\AgentRole;
use App\Exceptions\InvalidTaskTransition;
use App\Models\AuditEvent;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\TaskOperatorValidation;
use App\Models\User;
use App\ProjectStatus;
use App\ReviewStatus;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;

class TaskWorkflow
{
    public function __construct(private AuditLogger $audit, private ObsidianProjectNotes $notes, private CoderRepositoryGuard $repositoryGuard, private NoProgressRetryGuard $noProgress) {}

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

    public function claimedTask(Project $project, AgentRole $role): ?Task
    {
        $statuses = $role === AgentRole::Coder
            ? [TaskStatus::Coding, TaskStatus::Validating]
            : [TaskStatus::Reviewing];

        return $project->tasks()
            ->whereIn('status', $statuses)
            ->orderBy('position')
            ->first();
    }

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
     * @param  array{build_sha: string, build_completed_at: string, results: array<int, array<string, mixed>>, notes?: string|null}  $attributes
     */
    public function submitOperatorValidation(Task $task, User $user, array $attributes): TaskOperatorValidation
    {
        $validation = DB::transaction(function () use ($task, $user, $attributes): TaskOperatorValidation {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $status = TaskStatus::from($lockedTask->getRawOriginal('status'));

            abort_unless(in_array($status, [TaskStatus::ChangesRequired, TaskStatus::Blocked], true), 409, 'Operator validation may only resolve a task awaiting correction or an external prerequisite.');
            abort_unless(TaskOperatorValidation::isApplicableTo($lockedTask), 409, 'This task does not declare a manual browser, device, or hardware validation requirement.');

            $validation = $lockedTask->operatorValidations()->create([
                'user_id' => $user->id,
                'build_sha' => $attributes['build_sha'],
                'build_completed_at' => $attributes['build_completed_at'],
                'results' => $attributes['results'],
                'notes' => $attributes['notes'] ?? null,
            ]);

            $failedTargets = collect($attributes['results'])
                ->filter(fn (array $result): bool => collect(TaskOperatorValidation::Checks)
                    ->keys()
                    ->contains(fn (string $check): bool => ($result[$check] ?? null) === 'fail'))
                ->pluck('target')
                ->values()
                ->all();

            $this->audit->record('task.operator_validation_recorded', [
                'validation_id' => $validation->id,
                'submitted_by_user_id' => $user->id,
                'build_sha' => $validation->build_sha,
                'target_count' => count($attributes['results']),
                'failed_targets' => $failedTargets,
            ], $lockedTask->project, $lockedTask);
            $this->transitionLocked($lockedTask, TaskStatus::ReadyForReview);
            $this->audit->record('task.operator_validation_ready_for_review', [
                'validation_id' => $validation->id,
                'source_status' => $status->value,
            ], $lockedTask->project, $lockedTask);

            return $validation;
        }, attempts: 3);

        $this->notes->writeState($task->project);

        return $validation;
    }

    private function taskContractFingerprint(TaskAttempt $attempt): ?string
    {
        $validationResults = $attempt->getAttribute('validation_results');
        $taskContract = is_array($validationResults) ? $validationResults['task_contract'] ?? null : null;

        return is_array($taskContract) && is_string($taskContract['fingerprint'] ?? null)
            ? $taskContract['fingerprint']
            : null;
    }

    private function attemptHasChangedFiles(TaskAttempt $attempt): bool
    {
        $changedFiles = $attempt->getAttribute('changed_files');

        return is_array($changedFiles) && $changedFiles !== [];
    }

    /** @param array<int, array<string, string>> $findings */
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

            $existing = Review::query()->where('task_attempt_id', $lockedAttempt->id)->lockForUpdate()->first();
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
                $this->audit->record('review.finding_recorded', ['review_id' => $review->id, 'review_finding_id' => $reviewFinding->id, 'severity' => $reviewFinding->severity, 'location' => $reviewFinding->location], $lockedTask->project, $lockedTask);
            }

            $this->audit->record('review.completed', ['review_id' => $review->id, 'outcome' => $outcome->value, 'finding_count' => count($findings), 'attempt_number' => $lockedAttempt->number], $lockedTask->project, $lockedTask);
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

    public function reconcileExistingReviewerDecision(Task $task, TaskAttempt $attempt): ?Review
    {
        return DB::transaction(function () use ($task, $attempt): ?Review {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $lockedAttempt = TaskAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $this->assertReviewAttemptBelongsToTask($lockedTask, $lockedAttempt);
            $review = Review::query()->where('task_attempt_id', $lockedAttempt->id)->lockForUpdate()->first();

            if ($review === null) {
                return null;
            }

            $this->applyFinalizedReviewLocked($lockedTask, $lockedAttempt, $review);
            $this->audit->record('review.finalization_recovered', ['review_id' => $review->id, 'attempt_number' => $lockedAttempt->number, 'outcome' => $review->getRawOriginal('status'), 'reason' => 'existing_finalized_review_before_reviewer_execution'], $lockedTask->project, $lockedTask);

            return $review;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $failure */
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
            $retryStatus = $retryExhausted || $noProgressDetected ? TaskStatus::Blocked : TaskStatus::ReadyForReview;
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

    private function hasClaimedWork(Project $project, AgentRole $role): bool
    {
        return $this->claimedTask($project, $role) !== null;
    }

    private function assertReviewAttemptBelongsToTask(Task $task, TaskAttempt $attempt): void
    {
        if ((int) $attempt->task_id !== (int) $task->id) {
            throw new InvalidTaskTransition('The Reviewer TaskAttempt does not belong to the claimed Task.');
        }
    }

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

        $target = $outcome === ReviewStatus::Approved ? TaskStatus::Done : TaskStatus::ChangesRequired;
        $current = TaskStatus::from($task->getRawOriginal('status'));

        if ($current === $target) {
            return;
        }

        if ($current !== TaskStatus::Reviewing) {
            throw new InvalidTaskTransition("A finalized {$outcome->value} Review cannot reconcile Task state {$current->value}.");
        }

        $this->transitionLocked($task, $target);
        $this->audit->record($outcome === ReviewStatus::Approved ? 'task.approved' : 'task.rejected', ['review_id' => $review->id, 'attempt_number' => $attempt->number], $task->project, $task);
    }

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

    private function transitionLocked(Task $task, TaskStatus $to): void
    {
        $from = TaskStatus::from($task->getRawOriginal('status'));

        if (! in_array($to, $this->allowedTransitions($from), true)) {
            throw new InvalidTaskTransition("Cannot transition {$from->value} to {$to->value}.");
        }

        $task->update(['status' => $to, 'claimed_at' => $to === TaskStatus::Coding || $to === TaskStatus::Reviewing ? now() : $task->claimed_at, 'completed_at' => $to === TaskStatus::Done ? now() : null]);
        $this->audit->record('task.transitioned', ['from' => $from->value, 'to' => $to->value], $task->project, $task);
    }

    /** @return array<TaskStatus> */
    private function allowedTransitions(TaskStatus $from): array
    {
        return match ($from) {
            TaskStatus::Queued => [TaskStatus::Coding, TaskStatus::Cancelled, TaskStatus::Blocked],
            TaskStatus::Coding => [TaskStatus::Validating, TaskStatus::Interrupted, TaskStatus::Failed, TaskStatus::Blocked],
            TaskStatus::Validating => [TaskStatus::ReadyForReview, TaskStatus::Failed, TaskStatus::Interrupted, TaskStatus::Blocked],
            TaskStatus::ReadyForReview => [TaskStatus::Reviewing, TaskStatus::Done, TaskStatus::Interrupted],
            TaskStatus::Reviewing => [TaskStatus::Done, TaskStatus::ChangesRequired, TaskStatus::ReadyForReview, TaskStatus::Interrupted, TaskStatus::Blocked],
            TaskStatus::ChangesRequired => [TaskStatus::Coding, TaskStatus::ReadyForReview, TaskStatus::Cancelled, TaskStatus::Blocked],
            TaskStatus::Interrupted => [TaskStatus::Coding, TaskStatus::Reviewing, TaskStatus::Failed],
            TaskStatus::Blocked => [TaskStatus::Queued, TaskStatus::ChangesRequired, TaskStatus::ReadyForReview, TaskStatus::Cancelled],
            TaskStatus::Failed => [TaskStatus::Coding, TaskStatus::Blocked, TaskStatus::Cancelled],
            TaskStatus::Done => [],
            TaskStatus::Cancelled => [TaskStatus::ChangesRequired],
        };
    }

    private function nextCoderTask(Project $project): ?Task
    {
        $phase = $this->currentPhase($project);

        $query = Task::query()
            ->whereBelongsTo($project)
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

    private function reviewableTask(Project $project): ?Task
    {
        $phase = $this->currentPhase($project);

        if ($phase === null) {
            return Task::query()
                ->whereBelongsTo($project)
                ->whereIn('status', TaskStatus::reviewerClaimableValues())
                ->orderBy('position')
                ->lockForUpdate()
                ->first();
        }

        $phaseTasks = Task::query()
            ->whereBelongsTo($project)
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

    private function currentPhase(Project $project): ?Phase
    {
        return Phase::query()
            ->whereBelongsTo($project)
            ->whereHas(
                'tasks',
                fn ($tasks) => $tasks->whereNotIn('status', TaskStatus::phaseCompletionSatisfiedValues()),
            )
            ->orderBy('position')
            ->lockForUpdate()
            ->first();
    }

    private function dependenciesAreSatisfied(Task $task): bool
    {
        return $task->dependencies()->get()->every(function (Task $dependency) use ($task): bool {
            $status = TaskStatus::from($dependency->getRawOriginal('status'));
            $samePhase = $task->phase_id !== null && $dependency->phase_id === $task->phase_id;

            return $status->satisfiesDependency($samePhase);
        });
    }
}
