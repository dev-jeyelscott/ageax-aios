<?php

namespace App\Services;

use App\AgentRole;
use App\Exceptions\InvalidTaskTransition;
use App\Models\AuditEvent;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\TaskAttempt;
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

    /**
     * Blocks a task after repeated valid review rejections demonstrate that no repository
     * progress is possible. An operator requeue starts a new evidence window.
     */
    public function blockRepeatedRejectedReviews(Task $task): bool
    {
        $threshold = max(2, (int) config('aios.review_no_progress_block_threshold'));

        $blocked = DB::transaction(function () use ($task, $threshold): bool {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (TaskStatus::from($lockedTask->getRawOriginal('status')) !== TaskStatus::ChangesRequired) {
                return false;
            }

            $lastRequeueAt = AuditEvent::query()
                ->whereBelongsTo($lockedTask)
                ->where('event_type', 'task.requeued')
                ->latest('occurred_at')
                ->value('occurred_at');

            $reviews = Review::query()
                ->whereBelongsTo($lockedTask)
                ->where('status', ReviewStatus::ChangesRequired)
                ->when($lastRequeueAt !== null, fn ($query) => $query->where('completed_at', '>=', $lastRequeueAt))
                ->with('attempt')
                ->latest('id')
                ->limit($threshold)
                ->get();

            if ($reviews->count() !== $threshold) {
                return false;
            }

            $attempts = $reviews->pluck('attempt');
            $contractFingerprints = $attempts
                ->map(fn (?TaskAttempt $attempt): ?string => is_array($attempt?->validation_results)
                    ? ($attempt->validation_results['task_contract']['fingerprint'] ?? null)
                    : null)
                ->filter(fn (?string $fingerprint): bool => is_string($fingerprint) && $fingerprint !== '')
                ->unique()
                ->values();
            $headShas = $attempts
                ->map(fn (?TaskAttempt $attempt): ?string => $attempt?->head_sha)
                ->filter(fn (?string $sha): bool => is_string($sha) && $sha !== '')
                ->unique()
                ->values();
            $hasNoRepositoryProgress = $attempts->every(fn (?TaskAttempt $attempt): bool => $attempt instanceof TaskAttempt
                && $attempt->base_sha !== null
                && $attempt->base_sha === $attempt->head_sha
                && ($attempt->changed_files ?? []) === []);

            if (! $hasNoRepositoryProgress || $contractFingerprints->count() !== 1 || $headShas->count() !== 1) {
                return false;
            }

            $this->transitionLocked($lockedTask, TaskStatus::Blocked);
            $this->audit->record('task.review_no_progress_blocked', [
                'threshold' => $threshold,
                'review_ids' => $reviews->pluck('id')->sort()->values()->all(),
                'attempt_numbers' => $attempts->map(fn (TaskAttempt $attempt): int => $attempt->number)->sort()->values()->all(),
                'base_sha' => $attempts->first()?->base_sha,
                'head_sha' => $headShas->first(),
                'task_contract_fingerprint' => $contractFingerprints->first(),
                'reason' => 'Consecutive Reviewer changes_required decisions had the same task contract and no repository progress.',
                'operator_action' => 'Provide the external prerequisite or corrected task contract, then manually requeue. Skip only when an operator intentionally cancels the unmet work.',
            ], $lockedTask->project, $lockedTask);

            return true;
        }, attempts: 3);

        if ($blocked) {
            $this->notes->writeState($task->project);
        }

        return $blocked;
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
            ->whereIn('status', [TaskStatus::Queued, TaskStatus::ChangesRequired, TaskStatus::Failed]);

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
                fn ($tasks) => $tasks->whereNotIn('status', [TaskStatus::Done, TaskStatus::Cancelled]),
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
