<?php

namespace App\Jobs;

use App\Actions\RunWorkflowRecoveryScan;
use App\Models\Project;
use App\ProjectStatus;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * An operator-requested Workflow Recovery Engineer pass, run right now instead of waiting for the
 * next durable five-minute scan (App\Console\Commands\RecoverWorkflows). Runs the exact same
 * App\Actions\RunWorkflowRecoveryScan logic on the queue, so it never bypasses AIOS orchestration,
 * worker leases, or incident claiming, and never runs concurrently with itself.
 */
class RunWorkflowRecoveryEngineerNow implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $agentId) {}

    public function handle(RunWorkflowRecoveryScan $scan): void
    {
        foreach (Project::query()->whereIn('status', [ProjectStatus::Running, ProjectStatus::Stopping])->get() as $project) {
            $scan->handle($project);
        }
    }

    public function uniqueId(): string
    {
        return 'recovery-engineer-manual-invoke';
    }

    public function failed(?Throwable $exception): void
    {
        app(AuditLogger::class)->record('agent.invoke_failed', [
            'agent_id' => $this->agentId,
            'reason' => $exception?->getMessage(),
        ]);
    }
}
