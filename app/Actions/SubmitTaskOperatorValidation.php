<?php

namespace App\Actions;

use App\Models\Task;
use App\Models\TaskOperatorValidation;
use App\Models\User;
use App\Services\TaskWorkflow;

class SubmitTaskOperatorValidation
{
    public function __construct(private TaskWorkflow $workflow) {}

    /**
     * @param  array{build_sha: string, build_completed_at: string, results: array<int, array<string, mixed>>, notes?: string|null}  $attributes
     */
    public function handle(Task $task, User $user, array $attributes): TaskOperatorValidation
    {
        return $this->workflow->submitOperatorValidation($task, $user, $attributes);
    }
}
