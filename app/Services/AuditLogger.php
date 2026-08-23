<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Task;

class AuditLogger
{
    public function __construct(private SensitiveDataSanitizer $sanitizer) {}

    /**
     * Persist one append-only audit event after recursively sanitizing its payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(string $eventType, array $payload = [], ?Project $project = null, ?Task $task = null): AuditEvent
    {
        return AuditEvent::create([
            'project_id' => $project === null ? $task?->project_id : $project->id,
            'task_id' => $task?->id,
            'event_type' => $eventType,
            'payload' => $this->sanitizer->sanitizePayload($payload),
            'occurred_at' => now(),
        ]);
    }
}
