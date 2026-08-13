<?php

namespace App\Services;

use App\AgentRole;
use App\Exceptions\InvalidTaskTransition;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\ProjectStatus;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;

class TaskWorkflow
{
    public function __construct(private AuditLogger $audit, private ObsidianProjectNotes $notes, private CoderRepositoryGuard $repositoryGuard) {}

    public function claim(Project $project, AgentRole $role): ?Task
    {
        return DB::transaction(function () use ($project, $role): ?Task {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);

            if (ProjectStatus::from($lockedProject->getRawOriginal('status')) !== ProjectStatus::Running || $this->hasClaimedWork($lockedProject, $role)) {
                return null;
            }

            $task = match ($role) {
                AgentRole::Coder => $this->nextCoderTask($lockedProject),
                AgentRole::Reviewer => $this->reviewablePhaseTask($lockedProject),
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

        return $transitionedTask;
    }

    /** @return array<int, Task> */
    public function approvePhase(Task $task, ?TaskAttempt $attempt = null, ?string $reviewSummary = null): array
    {
        $completedTasks = DB::transaction(function () use ($task): array {
            $reviewTask = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (TaskStatus::from($reviewTask->getRawOriginal('status')) !== TaskStatus::Reviewing) {
                throw new InvalidTaskTransition('Only a claimed review task can approve a phase.');
            }

            $phaseTasks = $reviewTask->phase_id === null
                ? collect([$reviewTask])
                : Task::query()
                    ->whereBelongsTo($reviewTask->project)
                    ->where('phase_id', $reviewTask->phase_id)
                    ->orderBy('position')
                    ->lockForUpdate()
                    ->get();

            foreach ($phaseTasks as $phaseTask) {
                $expectedStatus = $phaseTask->is($reviewTask) ? TaskStatus::Reviewing : TaskStatus::ReadyForReview;

                if (TaskStatus::from($phaseTask->getRawOriginal('status')) !== $expectedStatus) {
                    throw new InvalidTaskTransition('A phase can only be approved after every task is ready for review.');
                }
            }

            foreach ($phaseTasks as $phaseTask) {
                $this->transitionLocked($phaseTask, TaskStatus::Done);
            }

            return $phaseTasks->map(fn (Task $phaseTask): Task => $phaseTask->refresh())->all();
        }, attempts: 3);

        foreach ($completedTasks as $completedTask) {
            $summary = $completedTask->is($task)
                ? $reviewSummary ?? "Approved implementation of {$completedTask->title}."
                : "Phase implementation approved after review. {$completedTask->objective}";
            $this->notes->writeTaskCompletion($completedTask, $summary, $completedTask->is($task) ? $attempt : null, $completedTask->is($task) ? $reviewSummary : null);
            $this->audit->record('task.approved', ['review_task_id' => $task->id, 'attempt_number' => $attempt?->number], $completedTask->project, $completedTask);
        }

        return $completedTasks;
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
            TaskStatus::Reviewing => [TaskStatus::Done, TaskStatus::ChangesRequired, TaskStatus::ReadyForReview, TaskStatus::Interrupted],
            TaskStatus::ChangesRequired => [TaskStatus::Coding, TaskStatus::Cancelled, TaskStatus::Blocked],
            TaskStatus::Interrupted => [TaskStatus::Coding, TaskStatus::Reviewing, TaskStatus::Failed],
            TaskStatus::Blocked => [TaskStatus::Queued, TaskStatus::ChangesRequired, TaskStatus::Cancelled],
            TaskStatus::Failed => [TaskStatus::Coding, TaskStatus::Blocked, TaskStatus::Cancelled],
            TaskStatus::Done, TaskStatus::Cancelled => [],
        };
    }

    private function nextCoderTask(Project $project): ?Task
    {
        return Task::query()
            ->whereBelongsTo($project)
            ->whereIn('status', [TaskStatus::Queued, TaskStatus::ChangesRequired, TaskStatus::Failed])
            ->orderBy('position')
            ->lockForUpdate()
            ->get()
            ->first(fn (Task $task): bool => $this->dependenciesAreSatisfied($task));
    }

    private function reviewablePhaseTask(Project $project): ?Task
    {
        $readyTasks = Task::query()
            ->whereBelongsTo($project)
            ->where('status', TaskStatus::ReadyForReview)
            ->orderBy('position')
            ->lockForUpdate()
            ->get();

        foreach ($readyTasks as $task) {
            if ($task->phase_id === null) {
                return $task;
            }

            $phaseTasks = Task::query()
                ->whereBelongsTo($project)
                ->where('phase_id', $task->phase_id)
                ->orderBy('position')
                ->lockForUpdate()
                ->get();

            if ($phaseTasks->last()?->is($task) && $phaseTasks->every(fn (Task $phaseTask): bool => TaskStatus::from($phaseTask->getRawOriginal('status')) === TaskStatus::ReadyForReview)) {
                return $task;
            }
        }

        return null;
    }

    private function dependenciesAreSatisfied(Task $task): bool
    {
        return $task->dependencies()->get()->every(function (Task $dependency) use ($task): bool {
            $status = TaskStatus::from($dependency->getRawOriginal('status'));

            return $status === TaskStatus::Done
                || ($task->phase_id !== null && $dependency->phase_id === $task->phase_id && $status === TaskStatus::ReadyForReview);
        });
    }
}

