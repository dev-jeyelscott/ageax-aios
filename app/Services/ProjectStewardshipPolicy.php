<?php

namespace App\Services;

use App\Models\Project;

/** Resolve the small, versioned per-project stewardship policy without granting semantic write authority. */
class ProjectStewardshipPolicy
{
    public const int Version = 1;

    /** @return array{version: int, automatic_task_creation: bool, circuit: array{fingerprint: ?string, failures: int, opened_at: ?string, reason: ?string}} */
    public function resolve(Project $project): array
    {
        $stored = $project->getAttribute('stewardship_policy');

        return [
            'version' => self::Version,
            'automatic_task_creation' => is_array($stored) && ($stored['automatic_task_creation'] ?? false) === true,
            'circuit' => [
                'fingerprint' => is_array($stored) && is_string($stored['circuit']['fingerprint'] ?? null) ? $stored['circuit']['fingerprint'] : null,
                'failures' => is_array($stored) ? (int) ($stored['circuit']['failures'] ?? 0) : 0,
                'opened_at' => is_array($stored) && is_string($stored['circuit']['opened_at'] ?? null) ? $stored['circuit']['opened_at'] : null,
                'reason' => is_array($stored) && is_string($stored['circuit']['reason'] ?? null) ? $stored['circuit']['reason'] : null,
            ],
        ];
    }

    public function permitsAutomaticTaskCreation(Project $project): bool
    {
        $policy = $this->resolve($project);

        return $policy['automatic_task_creation'] && $policy['circuit']['opened_at'] === null;
    }
}
