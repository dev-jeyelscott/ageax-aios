<?php

namespace App\Console\Commands;

use App\Actions\ClaimTask;
use App\Actions\ClaimTicketForTriage;
use App\Actions\ConvertTicketToTask;
use App\Actions\RunCoderTask;
use App\Actions\RunProjectManager;
use App\Actions\RunReviewerTask;
use App\Actions\RunTaskPlanningRevision;
use App\Actions\RunTicketTriage;
use App\Actions\SetProjectStatus;
use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\TaskPlanningEscalation;
use App\Models\TicketTriageAttempt;
use App\ProjectStatus;
use App\Services\TaskPlanningEscalationWorkflow;
use App\Services\TaskWorkflow;
use App\Services\WorkerHeartbeat;
use App\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

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
        ConvertTicketToTask $convertTicketToTask,
        RunCoderTask $runCoderTask,
        RunProjectManager $runProjectManager,
        RunTaskPlanningRevision $runTaskPlanningRevision,
        RunReviewerTask $runReviewerTask,
        RunTicketTriage $runTicketTriage,
        SetProjectStatus $setProjectStatus,
        WorkerHeartbeat $heartbeat,
        TaskPlanningEscalationWorkflow $planningEscalations,
        TaskWorkflow $workflow,
    ): int {
        $workerInstanceId = (string) Str::uuid();

        do {
            foreach (Project::query()->whereIn('status', [ProjectStatus::Running, ProjectStatus::Stopping])->get() as $project) {
                if ($this->stopRequested($project, $setProjectStatus)) {
                    continue;
                }

                // A completed PM triage decision may have been persisted immediately before the
                // worker process died. Resume that same durable conversion before allowing new PM
                // work so a crash cannot strand a conversion-eligible Ticket in triaging.
                try {
                    $this->recoverPendingTicketConversion(
                        $project,
                        $convertTicketToTask,
                    );
                } catch (Throwable $throwable) {
                    // An uncaught exception here must never kill the persistent loop for every
                    // other project (see TaskContractGuard regex crash incident). Report and let
                    // the next cycle retry; the stale-worker/workflow recovery scan covers any
                    // resulting stranded state.
                    report($throwable);

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
                        } catch (Throwable $throwable) {
                            // See the recoverPendingTicketConversion catch above: an uncaught
                            // exception anywhere in a role handler must not take down the
                            // persistent worker loop for every project.
                            report($throwable);
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

                // Planning revisions are PM work, serialized by the same lease and ordered
                // after roadmap analysis but before Ticket intake.
                if (! $this->hasPendingRoadmapWork($project)) {
                    $lease = $heartbeat->acquire($project, AgentRole::ProjectManager, $workerInstanceId);
                    if ($lease !== null) {
                        try {
                            $revisionAttempt = $planningEscalations->claim($project);
                            if ($revisionAttempt !== null) {
                                $runTaskPlanningRevision->handle($revisionAttempt, $lease);
                                AgentWorker::query()->whereBelongsTo($project)->where('role', AgentRole::ProjectManager)->update(['task_completed_at' => now()]);
                            }
                        } catch (Throwable $throwable) {
                            report($throwable);
                        } finally {
                            $heartbeat->release($lease);
                        }
                    }
                }

                if (
                    ! $this->hasActiveTicketTriage($project)
                    && ! $this->hasPendingRoadmapWork($project)
                    && ! $this->hasActivePlanningRevision($project)
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

                                $runTicketTriage->handle(
                                    $attempt,
                                    $lease,
                                );

                                $convertTicketToTask->handle($attempt);
                            }
                        } catch (Throwable $throwable) {
                            report($throwable);
                        } finally {
                            $heartbeat->release($lease);
                        }
                    }

                    if ($this->stopRequested($project, $setProjectStatus)) {
                        continue;
                    }
                }

                foreach ([AgentRole::Coder, AgentRole::Reviewer] as $role) {
                    $task = $workflow->claimedTask($project, $role);

                    if ($task === null && $this->onTaskCooldown($project, $role)) {
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

                    $task ??= $claimTask->handle($project, $role);

                    if ($task !== null) {
                        $this->info("Processing {$task->key} for {$role->value}.");

                        try {
                            if ($role === AgentRole::Coder) {
                                $runCoderTask->handle($task, $lease);
                            } else {
                                $attempt = $task->attempts()
                                    ->latest('number')
                                    ->firstOrFail();

                                $runReviewerTask->run($task, $attempt, $lease);
                            }
                        } catch (Throwable $throwable) {
                            report($throwable);
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

    private function recoverPendingTicketConversion(
        Project $project,
        ConvertTicketToTask $convertTicketToTask,
    ): void {
        $attempt = TicketTriageAttempt::query()
            ->where('status', 'completed')
            ->where(
                'structured_decision->aios_validation->automatic_task_conversion_eligible',
                true,
            )
            ->whereHas(
                'ticket',
                fn ($query) => $query
                    ->where('project_id', $project->id)
                    ->where('status', TicketStatus::Triaging->value),
            )
            ->oldest('id')
            ->first();

        if ($attempt === null) {
            return;
        }

        $task = $convertTicketToTask->handle($attempt);

        if ($task !== null) {
            $this->info(
                "Recovered automatic Ticket conversion to {$task->key}.",
            );
        }
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

    private function hasActivePlanningRevision(Project $project): bool
    {
        return TaskPlanningEscalation::query()
            ->whereIn('status', ['pending', 'running'])
            ->whereHas('task', fn ($query) => $query->where('project_id', $project->id))
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

    /**
     * @phpstan-impure
     */
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
