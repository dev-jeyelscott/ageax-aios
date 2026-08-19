<?php

namespace App\Console\Commands;

use App\Actions\ClaimTask;
use App\Actions\ClaimTicketForTriage;
use App\Actions\RunCoderTask;
use App\Actions\RunProjectManager;
use App\Actions\RunReviewerTask;
use App\Actions\SetProjectStatus;
use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Roadmap;
use App\ProjectStatus;
use App\Services\WorkerHeartbeat;
use App\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('aios:work {--once}')]
#[Description('Run durable AIOS workers until stopped, or one cycle with --once')]
class RunAiosWorkers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        ClaimTask $claimTask,
        ClaimTicketForTriage $claimTicketForTriage,
        RunCoderTask $runCoderTask,
        RunProjectManager $runProjectManager,
        RunReviewerTask $runReviewerTask,
        SetProjectStatus $setProjectStatus,
        WorkerHeartbeat $heartbeat,
    ): int {
        $workerInstanceId = (string) Str::uuid();

        do {
            foreach (Project::query()->whereIn('status', [ProjectStatus::Running, ProjectStatus::Stopping])->get() as $project) {
                if ($this->stopRequested($project, $setProjectStatus)) {
                    continue;
                }

                // Stale worker/lease and workflow-failure recovery is owned by the Workflow
                // Recovery Engineer's five-minute scheduled scan (aios:recover-workflows), not
                // this loop; see App\Services\WorkflowRecoveryScanner/WorkflowRecoveryEngine.
                //
                // An already-claimed Ticket triage attempt is also durable PM work. Do not start
                // roadmap analysis over it even if its worker process died; recovery must first
                // resolve that same attempt.
                $activeTicketTriage = $this->hasActiveTicketTriage($project);

                $dueRoadmap = $activeTicketTriage
                    ? null
                    : Roadmap::query()
                        ->whereBelongsTo($project)
                        ->whereIn('status', ['uploaded', 'failed', 'in_progress'])
                        ->oldest()
                        ->first();

                // 'in_progress' roadmaps are mid multi-batch decomposition (see
                // ApplyRoadmapPlan's per-batch phase cap): the cooldown throttles new/retry PM
                // invocations, not a continuation AIOS has already committed to completing.
                $roadmapOnCooldown = $dueRoadmap !== null
                    && $dueRoadmap->getRawOriginal('status') !== 'in_progress'
                    && $this->onRoadmapCooldown($project);

                $roadmap = $roadmapOnCooldown ? null : $dueRoadmap;

                if ($roadmap !== null) {
                    $lease = $heartbeat->acquire(
                        $project,
                        AgentRole::ProjectManager,
                        $workerInstanceId,
                    );

                    if ($lease !== null) {
                        try {
                            $runProjectManager->handle($roadmap, $lease);
                        } finally {
                            $heartbeat->release($lease);
                        }

                        AgentWorker::query()
                            ->whereBelongsTo($project)
                            ->where('role', AgentRole::ProjectManager)
                            ->update(['task_completed_at' => now()]);
                    }

                    if ($this->stopRequested($project, $setProjectStatus)) {
                        continue;
                    }
                }

                if (
                    ! $this->hasActiveTicketTriage($project)
                    && ! $this->hasPendingRoadmapWork($project)
                    && $this->hasEligibleTicketTriage($project)
                ) {
                    $lease = $heartbeat->acquire(
                        $project,
                        AgentRole::ProjectManager,
                        $workerInstanceId,
                    );

                    if ($lease !== null) {
                        try {
                            $attempt = $claimTicketForTriage->handle($project);

                            if ($attempt !== null) {
                                $ticket = $attempt->ticket()->firstOrFail();

                                $this->info(
                                    "Claimed {$ticket->key} for project_manager ticket triage attempt {$attempt->number}.",
                                );
                            }
                        } finally {
                            $heartbeat->release($lease);
                        }
                    }

                    if ($this->stopRequested($project, $setProjectStatus)) {
                        continue;
                    }
                }

                foreach ([AgentRole::Coder, AgentRole::Reviewer] as $role) {
                    if ($this->onTaskCooldown($project, $role)) {
                        continue;
                    }

                    $lease = $heartbeat->acquire(
                        $project,
                        $role,
                        $workerInstanceId,
                    );

                    if ($lease === null) {
                        continue;
                    }

                    $task = $claimTask->handle($project, $role);

                    if ($task !== null) {
                        $this->info("Claimed {$task->key} for {$role->value}.");

                        try {
                            if ($role === AgentRole::Coder) {
                                $runCoderTask->handle($task, $lease);
                            } else {
                                $attempt = $task->attempts()
                                    ->latest('number')
                                    ->firstOrFail();

                                $runReviewerTask->run($task, $attempt, $lease);
                            }
                        } finally {
                            $heartbeat->release($lease);
                        }

                        AgentWorker::query()
                            ->whereBelongsTo($project)
                            ->where('role', $role)
                            ->update(['task_completed_at' => now()]);

                        if ($this->stopRequested($project, $setProjectStatus)) {
                            continue 2;
                        }

                        continue;
                    }

                    $heartbeat->release($lease);
                }
            }

            if (! $this->option('once')) {
                sleep(5);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function hasPendingRoadmapWork(Project $project): bool
    {
        return $project->roadmaps()
            ->whereIn('status', [
                'uploaded',
                'failed',
                'in_progress',
                'processing',
            ])
            ->exists();
    }

    private function hasEligibleTicketTriage(Project $project): bool
    {
        return $project->tickets()
            ->whereIn('status', [
                TicketStatus::Open->value,
                TicketStatus::Failed->value,
            ])
            ->exists();
    }

    private function hasActiveTicketTriage(Project $project): bool
    {
        return $project->tickets()
            ->where('status', TicketStatus::Triaging->value)
            ->exists();
    }

    private function onTaskCooldown(Project $project, AgentRole $role): bool
    {
        return $this->onCooldown(
            $project,
            $role,
            (int) config('aios.worker_task_cooldown_seconds'),
        );
    }

    /**
     * PM roadmap retries deliberately use their own, much longer timer. RunProjectManager also
     * enforces a bounded roadmap-attempt limit; this cooldown controls the cadence between the
     * automatic retries that are still allowed before terminal operator intervention.
     */
    private function onRoadmapCooldown(Project $project): bool
    {
        return $this->onCooldown(
            $project,
            AgentRole::ProjectManager,
            (int) config('aios.roadmap_retry_cooldown_seconds'),
        );
    }

    private function onCooldown(
        Project $project,
        AgentRole $role,
        int $cooldownSeconds,
    ): bool {
        $cooldownSeconds = max(0, $cooldownSeconds);

        if ($cooldownSeconds === 0) {
            return false;
        }

        $worker = AgentWorker::query()
            ->whereBelongsTo($project)
            ->where('role', $role)
            ->first();

        $taskCompletedAt = $worker?->getAttribute('task_completed_at');

        return $taskCompletedAt instanceof CarbonImmutable
            && $taskCompletedAt->addSeconds($cooldownSeconds)->isFuture();
    }

    /** @phpstan-impure */
    private function stopRequested(
        Project $project,
        SetProjectStatus $setProjectStatus,
    ): bool {
        $freshProject = Project::query()->find($project->id);

        if (
            $freshProject === null
            || ProjectStatus::from(
                $freshProject->getRawOriginal('status'),
            ) !== ProjectStatus::Stopping
        ) {
            return false;
        }

        $setProjectStatus->completeStopping($freshProject);

        return true;
    }
}
