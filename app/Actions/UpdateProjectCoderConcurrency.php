<?php

namespace App\Actions;

use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateProjectCoderConcurrency
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Persist an authorized, validated per-project Coder concurrency bound of 1 or 2.
     *
     * Raising to 2 provisions the durable second Coder worker slot so it can be leased.
     * Lowering to 1 only stops new admission onto the second slot; it never interrupts
     * or invalidates already active work still holding that slot's lease.
     */
    public function handle(Project $project, int $concurrency): Project
    {
        if (! in_array($concurrency, [1, 2], true)) {
            throw ValidationException::withMessages([
                'coder_concurrency' => 'Coder concurrency must be 1 or 2.',
            ]);
        }

        $previous = $project->coderConcurrency();

        $updatedProject = DB::transaction(function () use ($project, $concurrency): Project {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($concurrency === 2) {
                $this->provisionSecondCoderSlot($lockedProject);
            }

            $lockedProject->update(['coder_concurrency' => $concurrency]);

            return $lockedProject->refresh();
        }, attempts: 3);

        $this->audit->record('project.coder_concurrency_updated', [
            'previous_concurrency' => $previous,
            'concurrency' => $concurrency,
        ], $updatedProject);

        return $updatedProject;
    }

    /**
     * Ensure the durable second Coder worker slot exists before it can be leased.
     */
    private function provisionSecondCoderSlot(Project $project): void
    {
        if (
            AgentWorker::query()
                ->whereBelongsTo($project)
                ->where('role', AgentRole::Coder)
                ->where('slot', 2)
                ->exists()
        ) {
            return;
        }

        $agent = $project->agents()
            ->where('role', AgentRole::Coder)
            ->where('enabled', true)
            ->orderBy('id')
            ->first();

        $worker = AgentWorker::create([
            'project_id' => $project->id,
            'role' => AgentRole::Coder,
            'slot' => 2,
            'agent_id' => $agent?->id,
            'status' => 'idle',
        ]);

        $this->audit->record('worker.slot_provisioned', [
            'agent_worker_id' => $worker->id,
            'role' => AgentRole::Coder->value,
            'slot' => 2,
        ], $project);
    }
}
