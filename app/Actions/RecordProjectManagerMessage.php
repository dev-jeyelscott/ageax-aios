<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\ProjectManagerMessage;
use App\Models\User;
use App\Services\AuditLogger;

class RecordProjectManagerMessage
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Project $project, User $user, string $body): ProjectManagerMessage
    {
        $message = ProjectManagerMessage::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'body' => trim($body),
        ]);

        $this->audit->record('project_manager.message_recorded', ['message_id' => $message->id], $project);

        return $message;
    }
}
