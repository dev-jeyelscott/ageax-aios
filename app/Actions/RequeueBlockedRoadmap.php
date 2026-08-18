<?php

namespace App\Actions;

use App\Jobs\ProcessRoadmap;
use App\Models\Roadmap;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class RequeueBlockedRoadmap
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Roadmap $roadmap): Roadmap
    {
        abort_unless($roadmap->getRawOriginal('status') === 'blocked', 409, 'Only blocked roadmaps may be requeued.');

        DB::transaction(function () use ($roadmap): void {
            $lockedRoadmap = Roadmap::query()->lockForUpdate()->findOrFail($roadmap->id);
            if ($lockedRoadmap->getRawOriginal('status') !== 'blocked') {
                return;
            }

            // Requeuing onto 'uploaded' (rather than 'failed') deliberately skips
            // RunProjectManager::claimRoadmap's retry-exhaustion check, which only applies to
            // 'failed' roadmaps: an operator manually clearing a block always gets exactly one
            // fresh attempt, without resetting or discarding the prior attempt history that
            // still bounds any further automatic failures after this one.
            $lockedRoadmap->update(['status' => 'uploaded']);
            $this->audit->record('roadmap.requeued', [
                'roadmap_id' => $lockedRoadmap->id,
                'reason' => 'manual operator retry',
            ], $lockedRoadmap->project);
        }, attempts: 3);

        try {
            ProcessRoadmap::dispatch($roadmap->id);
        } catch (\Throwable) {
            // Immediate reprocessing is a convenience; the durable aios:work polling loop
            // still picks up the roadmap from its 'uploaded' status regardless of this
            // dispatch's outcome, so a queue hiccup here must not fail the requeue itself.
        }

        return $roadmap->refresh();
    }
}
