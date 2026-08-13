<?php

namespace App\Jobs;

use App\Actions\RunProjectManager;
use App\AgentRole;
use App\Models\Roadmap;
use App\Services\AuditLogger;
use App\Services\WorkerHeartbeat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ProcessRoadmap implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $roadmapId) {}

    /**
     * Execute the job.
     */
    public function handle(RunProjectManager $projectManager, WorkerHeartbeat $heartbeat): void
    {
        $roadmap = Roadmap::query()->with('project')->find($this->roadmapId);
        if ($roadmap === null || ! in_array($roadmap->getRawOriginal('status'), ['uploaded', 'failed'], true)) {
            return;
        }

        $lease = $heartbeat->acquire($roadmap->project, AgentRole::ProjectManager, (string) Str::uuid());
        if ($lease === null) {
            $this->release(30);

            return;
        }

        try {
            $projectManager->handle($roadmap, $lease);
        } finally {
            $heartbeat->release($lease);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->roadmapId;
    }

    public function failed(?Throwable $exception): void
    {
        $roadmap = Roadmap::query()->with('project')->find($this->roadmapId);
        if ($roadmap !== null) {
            app(AuditLogger::class)->record('roadmap.job_failed', ['reason' => $exception?->getMessage(), 'roadmap_id' => $roadmap->id], $roadmap->project);
        }
    }
}
