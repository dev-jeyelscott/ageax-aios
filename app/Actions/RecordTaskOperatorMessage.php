<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\Task;
use App\Models\TaskOperatorMessage;
use App\Models\User;
use App\Services\AuditLogger;

class RecordTaskOperatorMessage
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Task $task, User $user, AgentRole $recipientRole, string $body): TaskOperatorMessage
    {
        $message = TaskOperatorMessage::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'recipient_role' => $recipientRole,
            'body' => trim($body),
        ]);

        $this->audit->record('task.operator_message_recorded', ['message_id' => $message->id, 'recipient_role' => $recipientRole->value], $task->project, $task);

        return $message;
    }
}
