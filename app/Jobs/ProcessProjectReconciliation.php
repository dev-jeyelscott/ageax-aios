<?php

namespace App\Jobs;

use App\Actions\RunProjectReconciliation;
use App\Models\ProjectReconciliationRun;
use App\ProjectReconciliationStatus;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessProjectReconciliation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $runId) {}

    /**
     * Execute the job.
     */
    public function handle(RunProjectReconciliation $reconciliation): void
    {
        $run = ProjectReconciliationRun::query()->with('project')->find($this->runId);

        if ($run === null || ! in_array($run->getRawOriginal('status'), [ProjectReconciliationStatus::Queued->value, ProjectReconciliationStatus::Running->value], true)) {
            return;
        }

        $reconciliation->handle($run);
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function failed(?Throwable $exception): void
    {
        $run = ProjectReconciliationRun::query()->with('project')->find($this->runId);

        if ($run === null) {
            return;
        }

        app(AuditLogger::class)->record('reconciliation.job_failed', [
            'reconciliation_run_id' => $run->id,
            'reason' => $exception?->getMessage(),
        ], $run->project);

        if (in_array($run->getRawOriginal('status'), [ProjectReconciliationStatus::Queued->value, ProjectReconciliationStatus::Running->value], true)) {
            $run->update(['status' => ProjectReconciliationStatus::Failed, 'failure_reason' => $exception?->getMessage() ?? 'Reconciliation job failed.', 'finished_at' => now()]);
        }
    }
}
