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
use App\ProjectStatus;
use App\ReviewStatus;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

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

    /** @param array<int, array<string, string>> $findings */
    public function finalizeReviewerDecision(Task $task, TaskAttempt $attempt, ReviewStatus $outcome, ?string $summary = null, array $findings = []): Review
    {
        if (! in_array($outcome, [ReviewStatus::Approved, ReviewStatus::ChangesRequired], true)) {
            throw new InvalidTaskTransition('A review must explicitly approve or request changes.');
        }

        if ($outcome === ReviewStatus::ChangesRequired && $findings === []) {
            throw new InvalidTaskTransition('A changes-required review must include actionable findings.');
        }

        /** @var array{task: Task, attempt: TaskAttempt, review: Review} $finalization */
        $finalization = DB::transaction(function () use ($task, $attempt, $outcome, $summary, $findings): array {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $lockedAttempt = TaskAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $this->assertAttemptBelongsToTask($lockedTask, $lockedAttempt);

            $existingReview = Review::query()
                ->where('task_attempt_id', $lockedAttempt->id)
                ->lockForUpdate()
                ->first();

            if ($existingReview !== null) {
                $persistedOutcome = ReviewStatus::from($existingReview->getRawOriginal('status'));

                if ($persistedOutcome !== $outcome) {
                    $this->audit->record('review.finalization_conflict_ignored', [
                        'review_id' => $existingReview->id,
                        'attempt_number' => $lockedAttempt->number,
                        'persisted_outcome' => $persistedOutcome->value,
                        'ignored_outcome' => $outcome->value,
                    ], $lockedTask->project, $lockedTask);
                }

                $this->reconcileFinalizedReviewLocked($lockedTask, $lockedAttempt, $existingReview);

                return [
                    'task' => $lockedTask->refresh(),
                    'attempt' => $lockedAttempt,
                    'review' => $existingReview->fresh('findings') ?? $existingReview,
                ];
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
                $reviewFinding = ReviewFinding::create([
                    'review_id' => $review->id,
                    'severity' => $finding['severity'],
                    'location' => $finding['location'] ?? null,
                    'current_implementation' => $finding['current_implementation'],
                    'expected_implementation' => $finding['expected_implementation'],
                    'why_incorrect' => $finding['why_incorrect'],
                    'required_fix' => $finding['required_fix'],
                    'verification_requirement' => $finding['verification_requirement'],
                    'implementation_fix_context' => $finding['implementation_fix_context'],
                ]);

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

            $this->applyReviewDecisionLocked($lockedTask, $lockedAttempt, $review);

            return [
                'task' => $lockedTask->refresh(),
                'attempt' => $lockedAttempt,
                'review' => $review->fresh('findings') ?? $review,
            ];
        }, attempts: 3);

        $this->scheduleReviewerFinalizationNotes(
            $finalization['task'],
            $finalization['attempt'],
            $finalization['review'],
        );

        return $finalization['review'];
    }

    public function reconcileExistingReviewerDecision(Task $task, TaskAttempt $attempt): ?Review
    {
        /** @var array{task: Task, attempt: TaskAttempt, review: ?Review} $reconciliation */
        $reconciliation = DB::transaction(function () use ($task, $attempt): array {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $lockedAttempt = TaskAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            $this->assertAttemptBelongsToTask($lockedTask, $lockedAttempt);

            $review = Review::query()
                ->where('task_attempt_id', $lockedAttempt->id)
                ->lockForUpdate()
                ->first();

            if ($review === null) {
                return [
                    'task' => $lockedTask,
                    'attempt' => $lockedAttempt,
                    'review' => null,
                ];
            }

            $this->reconcileFinalizedReviewLocked($lockedTask, $lockedAttempt, $review);

            $this->audit->record('review.finalization_recovered', [
                'review_id' => $review->id,
                'attempt_number' => $lockedAttempt->number,
                'outcome' => $review->getRawOriginal('status'),
                'reason' => 'existing_finalized_review_before_reviewer_execution',
            ], $lockedTask->project, $lockedTask);

            return [
                'task' => $lockedTask->refresh(),
                'attempt' => $lockedAttempt,
                'review' => $review->fresh('findings') ?? $review,
            ];
        }, attempts: 3);

        if ($reconciliation['review'] === null) {
            return null;
        }

        $this->scheduleReviewerFinalizationNotes(
            $reconciliation['task'],
            $reconciliation['attempt'],
            $reconciliation['review'],
        );

        return $reconciliation['review'];
    }

    /** @param array<string, mixed> $failure */
    public function recordReviewerOperationalFailure(Task $task, ?TaskAttempt $attempt, array $failure): Task
    {
        /** @var array{task: Task, attempt: ?TaskAttempt, review: ?Review} $result */
        $result = DB::transaction(function () use ($task, $attempt, $failure): array {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (TaskStatus::from($lockedTask->getRawOriginal('status')) !== TaskStatus::Reviewing) {
                throw new InvalidTaskTransition('Only a claimed review task can record an operational failure.');
            }

            $lockedAttempt = null;

            if ($attempt !== null) {
                $lockedAttempt = TaskAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                $this->assertAttemptBelongsToTask($lockedTask, $lockedAttempt);

                $review = Review::query()
                    ->where('task_attempt_id', $lockedAttempt->id)
                    ->lockForUpdate()
                    ->first();

                if ($review !== null) {
                    $this->reconcileFinalizedReviewLocked($lockedTask, $lockedAttempt, $review);

                    $this->audit->record('review.finalization_recovered', [
                        'review_id' => $review->id,
                        'attempt_number' => $lockedAttempt->number,
                        'outcome' => $review->getRawOriginal('status'),
                        'reason' => $failure['reason'] ?? 'reviewer_operational_failure_reconciliation',
                    ], $lockedTask->project, $lockedTask);

                    return [
                        'task' => $lockedTask->refresh(),
                        'attempt' => $lockedAttempt,
                        'review' => $review->fresh('findings') ?? $review,
                    ];
                }
            }

            $failureCount = AuditEvent::query()
                ->whereBelongsTo($lockedTask)
                ->where('event_type', 'review.failed')
                ->get()
                ->filter(function (AuditEvent $event) use ($lockedAttempt): bool {
                    $payload = json_decode($event->getRawOriginal('payload'), true);

                    return is_array($payload) && ($payload['attempt_number'] ?? null) === $lockedAttempt?->number;
                })
                ->count() + 1;

            $retryLimit = max(1, (int) config('aios.max_reviewer_attempts'));
            $noProgress = $this->noProgress->reviewerFailure($lockedTask, $lockedAttempt, $failure);
            $retryExhausted = $failureCount >= $retryLimit;
            $noProgressDetected = ! $retryExhausted && $noProgress['detected'];
            $retryStatus = $retryExhausted || $noProgressDetected
                ? TaskStatus::Blocked
                : TaskStatus::ReadyForReview;

            $payload = [
                ...$failure,
                'attempt_number' => $lockedAttempt?->number,
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
                    'attempt_number' => $lockedAttempt?->number,
                    'failure_fingerprint' => $noProgress['failure_fingerprint'],
                    'consecutive_identical_failures' => $noProgress['consecutive_identical_failures'],
                    'consecutive_repeat_count' => $noProgress['consecutive_repeat_count'],
                    'threshold' => $noProgress['threshold'],
                    'reason' => $failure['reason'] ?? null,
                    'base_sha' => $lockedAttempt?->base_sha,
                    'head_sha' => $lockedAttempt?->head_sha,
                    'commit_sha' => $lockedAttempt?->commit_sha,
                    'changed_files' => $lockedAttempt === null ? [] : ($lockedAttempt->changed_files ?? []),
                    'repository_fingerprint' => $noProgress['repository_fingerprint'],
                ], $lockedTask->project, $lockedTask);
            } else {
                $this->audit->record('review.retry_scheduled', $payload, $lockedTask->project, $lockedTask);
            }

            return [
                'task' => $lockedTask->refresh(),
                'attempt' => $lockedAttempt,
                'review' => null,
            ];
        }, attempts: 3);

        if ($result['review'] !== null && $result['attempt'] !== null) {
            $this->scheduleReviewerFinalizationNotes(
                $result['task'],
                $result['attempt'],
                $result['review'],
            );
        } else {
            $this->scheduleStateNote($result['task']);
        }

        return $result['task'];
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

    private function assertAttemptBelongsToTask(Task $task, TaskAttempt $attempt): void
    {
        if ((int) $attempt->task_id !== (int) $task->id) {
            throw new InvalidTaskTransition('The Reviewer TaskAttempt does not belong to the claimed Task.');
        }
    }

    private function reconcileFinalizedReviewLocked(Task $task, TaskAttempt $attempt, Review $review): void
    {
        if ((int) $review->task_id !== (int) $task->id || (int) $review->task_attempt_id !== (int) $attempt->id) {
            throw new InvalidTaskTransition('The persisted Review does not belong to the locked Task and TaskAttempt.');
        }

        if ($review->getRawOriginal('completed_at') === null) {
            throw new InvalidTaskTransition('An incomplete Review cannot be used as an authoritative Reviewer decision.');
        }

        $outcome = ReviewStatus::from($review->getRawOriginal('status'));

        if (! in_array($outcome, [ReviewStatus::Approved, ReviewStatus::ChangesRequired], true)) {
            throw new InvalidTaskTransition('Only a finalized Reviewer decision may be reconciled.');
        }

        if ($outcome === ReviewStatus::ChangesRequired && ! $review->findings()->exists()) {
            throw new InvalidTaskTransition('A finalized changes-required Review must contain actionable findings.');
        }

        $this->applyReviewDecisionLocked($task, $attempt, $review);
    }

    private function applyReviewDecisionLocked(Task $task, TaskAttempt $attempt, Review $review): void
    {
        $outcome = ReviewStatus::from($review->getRawOriginal('status'));

        $target = match ($outcome) {
            ReviewStatus::Approved => TaskStatus::Done,
            ReviewStatus::ChangesRequired => TaskStatus::ChangesRequired,
            default => throw new InvalidTaskTransition('Only a finalized Reviewer decision may transition a Task.'),
        };

        $current = TaskStatus::from($task->getRawOriginal('status'));

        if ($current === $target) {
            return;
        }

        if ($current !== TaskStatus::Reviewing) {
            throw new InvalidTaskTransition(
                "A finalized {$outcome->value} Review cannot reconcile Task state {$current->value}.",
            );
        }

        $this->transitionLocked($task, $target);

        if ($outcome === ReviewStatus::Approved) {
            $this->audit->record('task.approved', [
                'review_id' => $review->id,
                'attempt_number' => $attempt->number,
            ], $task->project, $task);

            return;
        }

        $this->audit->record('task.rejected', [
            'review_id' => $review->id,
            'attempt_number' => $attempt->number,
        ], $task->project, $task);
    }

    private function scheduleReviewerFinalizationNotes(Task $task, TaskAttempt $attempt, Review $review): void
    {
        $taskId = (int) $task->id;
        $attemptId = (int) $attempt->id;
        $reviewId = (int) $review->id;

        $this->afterCommit(function () use ($taskId, $attemptId, $reviewId): void {
            $freshTask = Task::query()->find($taskId);
            $freshAttempt = TaskAttempt::query()->find($attemptId);
            $freshReview = Review::query()->with('findings')->find($reviewId);

            if ($freshTask === null || $freshAttempt === null || $freshReview === null) {
                return;
            }

            try {
                $this->notes->writeReview($freshTask, $freshReview);
            } catch (Throwable $throwable) {
                report($throwable);
            }

            if (ReviewStatus::from($freshReview->getRawOriginal('status')) === ReviewStatus::Approved) {
                $summary = $freshReview->summary ?? "Approved implementation of {$freshTask->title}.";

                try {
                    $this->notes->writeTaskCompletion(
                        $freshTask,
                        $summary,
                        $freshAttempt,
                        $freshReview->summary,
                    );
                } catch (Throwable $throwable) {
                    report($throwable);
                }
            }

            try {
                $freshTask->loadMissing('project');
                $this->notes->writeState($freshTask->project);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        });
    }

    private function scheduleStateNote(Task $task): void
    {
        $taskId = (int) $task->id;

        $this->afterCommit(function () use ($taskId): void {
            $freshTask = Task::query()->find($taskId);

            if ($freshTask === null) {
                return;
            }

            try {
                $freshTask->loadMissing('project');
                $this->notes->writeState($freshTask->project);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        });
    }

    private function afterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }

    private function hasClaimedWork(Project $project, AgentRole $role): bool
    {
        $statuses = $role === AgentRole::Coder
            ? [TaskStatus::Coding, TaskStatus::Validating, TaskStatus::Reviewing]
            : [TaskStatus::Reviewing];

        return $project->tasks()->whereIn('status', $statuses)->exists();
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

        $task->update([
            'status' => $to,
            'claimed_at' => $to === TaskStatus::Coding || $to === TaskStatus::Reviewing
                ? now()
                : $task->claimed_at,
            'completed_at' => $to === TaskStatus::Done ? now() : null,
        ]);

        $this->audit->record(
            'task.transitioned',
            ['from' => $from->value, 'to' => $to->value],
            $task->project,
            $task,
        );
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
            TaskStatus::ChangesRequired => [TaskStatus::Coding, TaskStatus::Cancelled, TaskStatus::Blocked],
            TaskStatus::Interrupted => [TaskStatus::Coding, TaskStatus::Reviewing, TaskStatus::Failed],
            TaskStatus::Blocked => [TaskStatus::Queued, TaskStatus::ChangesRequired, TaskStatus::ReadyForReview, TaskStatus::Cancelled],
            TaskStatus::Failed => [TaskStatus::Coding, TaskStatus::Blocked, TaskStatus::Cancelled],
            TaskStatus::Done, TaskStatus::Cancelled => [],
        };
    }

    private function nextCoderTask(Project $project): ?Task
    {
        $phase = $this->currentPhase($project);

        $query = Task::query()
            ->whereBelongsTo($project)
            ->whereIn('status', [
                TaskStatus::Queued,
                TaskStatus::ChangesRequired,
                TaskStatus::Failed,
            ]);

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
                ->where('status', TaskStatus::ReadyForReview)
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
            fn (Task $task): bool => in_array(
                TaskStatus::from($task->getRawOriginal('status')),
                [TaskStatus::ReadyForReview, TaskStatus::Done, TaskStatus::Cancelled],
                true,
            ),
        )) {
            return null;
        }

        return $phaseTasks->first(
            fn (Task $task): bool => TaskStatus::from($task->getRawOriginal('status')) === TaskStatus::ReadyForReview,
        );
    }

    private function currentPhase(Project $project): ?Phase
    {
        return Phase::query()
            ->whereBelongsTo($project)
            ->whereHas(
                'tasks',
                fn ($tasks) => $tasks->whereNotIn('status', [
                    TaskStatus::Done,
                    TaskStatus::Cancelled,
                ]),
            )
            ->orderBy('position')
            ->lockForUpdate()
            ->first();
    }

    private function dependenciesAreSatisfied(Task $task): bool
    {
        return $task->dependencies()->get()->every(function (Task $dependency) use ($task): bool {
            $status = TaskStatus::from($dependency->getRawOriginal('status'));

            return $status === TaskStatus::Done
                || $status === TaskStatus::Cancelled
                || ($task->phase_id !== null
                    && $dependency->phase_id === $task->phase_id
                    && $status === TaskStatus::ReadyForReview);
        });
    }
}
