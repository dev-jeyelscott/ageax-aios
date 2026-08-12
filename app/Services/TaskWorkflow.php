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
    public function __construct(private AuditLogger $audit, private ObsidianProjectNotes $notes, private GitRepositoryInspector $git) {}

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

    private function repositoryAllowsCoderClaim(Project $project, Task $task): bool
    {
        $state = $this->git->inspect($project->path);
        $this->persistProjectGitState($project, $state);
        $previousAttempt = $task->attempts()->latest('number')->first();
        $isInterruptedRecovery = $previousAttempt?->status === 'interrupted';

        if ($isInterruptedRecovery) {
            $baseSha = $previousAttempt->base_sha;
            if ($state['inspectable'] && $baseSha !== null && $state['head_sha'] === $baseSha) {
                $this->audit->record('task.recovery_git_state_accepted', [
                    'base_sha' => $baseSha,
                    'repository' => $state,
                ], $project, $task);

                return true;
            }

            $this->blockForGitState(
                $project,
                $task,
                'interrupted_recovery',
                'Interrupted recovery requires repository HEAD to match the recorded clean base SHA.',
                'Restore the repository to the recorded task base without discarding user work, then requeue the task.',
                $state,
                $baseSha,
            );

            return false;
        }

        if ($state['clean']) {
            return true;
        }

        $this->blockForGitState(
            $project,
            $task,
            'normal',
            'A new Coder implementation attempt requires a clean repository with a valid HEAD.',
            'Commit, move, or otherwise resolve the existing staged and working-tree changes outside AIOS, then requeue the task.',
            $state,
        );

        return false;
    }

    /** @param array<string, mixed> $state */
    private function persistProjectGitState(Project $project, array $state): void
    {
        $project->update([
            'git_status' => ! $state['inspectable'] ? 'unknown' : ($state['clean'] ? 'clean' : 'dirty'),
            'git_head_sha' => $state['head_sha'],
        ]);
    }

    /** @param array<string, mixed> $state */
    private function blockForGitState(Project $project, Task $task, string $mode, string $reason, string $action, array $state, ?string $baseSha = null): void
    {
        $this->transitionLocked($task, TaskStatus::Blocked);
        $this->audit->record('task.blocked_git_preflight', [
            'mode' => $mode,
            'reason' => $reason,
            'action' => $action,
            'base_sha' => $baseSha,
            'repository' => $state,
        ], $project, $task);
    }
}
