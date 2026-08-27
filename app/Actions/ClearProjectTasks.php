<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Task;
use App\Services\AuditLogger;
use App\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClearProjectTasks
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Project $project): int
    {
        return DB::transaction(function () use ($project): int {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $tasks = Task::query()
                ->whereBelongsTo($lockedProject)
                ->notCleared()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $activeTask = $tasks->first(fn (Task $task): bool => in_array(
                TaskStatus::from($task->getRawOriginal('status')),
                [TaskStatus::Coding, TaskStatus::Validating, TaskStatus::Reviewing],
                true,
            ));

            if ($activeTask !== null) {
                throw ValidationException::withMessages([
                    'tasks' => "Tasks cannot be cleared while {$activeTask->key} is {$activeTask->status->value}. Wait for active execution to finish before clearing the project queue.",
                ]);
            }

            $taskIds = $tasks->pluck('id')->all();
            if ($taskIds !== []) {
                Task::query()->whereKey($taskIds)->update(['is_cleared' => true]);
            }

            $this->audit->record('project.tasks_cleared', [
                'affected_task_count' => count($taskIds),
                'affected_task_ids_sha256' => hash('sha256', json_encode($taskIds, JSON_THROW_ON_ERROR)),
            ], $lockedProject);

            return count($taskIds);
        }, attempts: 3);
    }
}
