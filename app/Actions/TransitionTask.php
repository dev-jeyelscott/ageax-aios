<?php

namespace App\Actions;

use App\Models\Task;
use App\Services\TaskWorkflow;
use App\TaskStatus;

class TransitionTask
{
    public function __construct(private TaskWorkflow $workflow) {}

    public function handle(Task $task, TaskStatus $status): Task
    {
        return $this->workflow->transition($task, $status);
    }
}
