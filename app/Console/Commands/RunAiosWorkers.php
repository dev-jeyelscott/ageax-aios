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
use App\Models\Agent;
use App\Models\AgentWorker;
use App\Models\GoalRun;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\TaskPlanningEscalation;
use App\Models\TicketTriageAttempt;
use App\ProjectStatus;
use App\Services\GoalSessionRecorder;
use App\Services\TaskPlanningEscalationWorkflow;
use App\Services\TaskWorkflow;
use App\Services\WorkerHeartbeat;
use App\TicketStatus;
use App\WorkerLease;
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
    private const int PrimaryWorkerSlot = 1;

    /**
     * Execute the durable AIOS worker loop using only primary slot 1 until parallel claiming is separately enabled.
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
        GoalSessionRecorder $goalSessions,
        TaskWorkflow $workflow,
    ): int {
        $workerInstanceId = (string) Str::uuid();

        do {
            foreach (
                Project::query()
                    ->whereIn('status', [
                        ProjectStatus::Running,
                        ProjectStatus::Stopping,
                    ])
                    ->get() as $project
            ) {
                if ($this->stopRequested($project, $setProjectStatus)) {
                    continue;
                }

                try {
                    $this->recoverPendingTicketConversion(
                        $project,
                        $convertTicketToTask,
                    );
                } catch (Throwable $throwable) {
                    report($throwable);

                    continue;
                }

                $activeTicketTriage = $this->hasActiveTicketTriage($project);

                $dueRoadmap = $activeTicketTriage
                    ? null
                    : Roadmap::query()
                        ->whereBelongsTo($project)
                        ->whereIn('status', [
                            'uploaded',
                            'failed',
                            'in_progress',
                        ])
                        ->oldest()
                        ->first();

                $roadmapOnCooldown = $dueRoadmap !== null
                    && $dueRoadmap->getRawOriginal('status') !== 'in_progress'
                    && $this->onRoadmapCooldown($project);

                $roadmap = $roadmapOnCooldown ? null : $dueRoadmap;

                if ($roadmap !== null) {
                    $lease = $heartbeat->acquire(
                        $project,
                        AgentRole::ProjectManager,
                        $workerInstanceId,
                        slot: self::PrimaryWorkerSlot,
                    );

                    if ($lease !== null) {
                        $agentName = $this->agentNameForLease(
                            $lease,
                            AgentRole::ProjectManager,
                        );

                        $this->info(
                            "Processing roadmap {$roadmap->id} for project_manager [agent: {$agentName}].",
                        );

                        try {
                            $runProjectManager->handle($roadmap, $lease);
                        } catch (Throwable $throwable) {
                            report($throwable);
                        } finally {
                            $this->markTaskCompleted($lease);
                            $heartbeat->release($lease);
                        }
                    }

                    if ($this->stopRequested($project, $setProjectStatus)) {
                        continue;
                    }
                }

                if (! $this->hasPendingRoadmapWork($project)) {
                    $lease = $heartbeat->acquire(
                        $project,
                        AgentRole::ProjectManager,
                        $workerInstanceId,
                        slot: self::PrimaryWorkerSlot,
                    );

                    if ($lease !== null) {
                        try {
                            $revisionAttempt = $planningEscalations->claim(
                                $project,
                            );

                            if ($revisionAttempt !== null) {
                                $agentName = $this->agentNameForLease(
                                    $lease,
                                    AgentRole::ProjectManager,
                                );

                                $this->info(
                                    "Processing task planning revision for project_manager [agent: {$agentName}].",
                                );

                                $runTaskPlanningRevision->handle(
                                    $revisionAttempt,
                                    $lease,
                                );

                                $this->markTaskCompleted($lease);
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
                        slot: self::PrimaryWorkerSlot,
                    );

                    if ($lease !== null) {
                        try {
                            $attempt = $claimTicketForTriage->handle($project);

                            if ($attempt !== null) {
                                $ticket = $attempt->ticket()->firstOrFail();
                                $agentName = $this->agentNameForLease(
                                    $lease,
                                    AgentRole::ProjectManager,
                                );

                                $this->info(
                                    "Claimed {$ticket->key} for project_manager ticket triage attempt {$attempt->number} [agent: {$agentName}].",
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

                    if (
                        $task === null
                        && $this->onTaskCooldown($project, $role)
                    ) {
                        continue;
                    }

                    // P10-003 only introduces durable slot capacity. P10-004 owns concurrent task
                    // claiming, so the normal worker loop remains explicitly pinned to slot 1.
                    $lease = $heartbeat->acquire(
                        $project,
                        $role,
                        $workerInstanceId,
                        slot: self::PrimaryWorkerSlot,
                    );

                    if ($lease === null) {
                        continue;
                    }

                    $task ??= $claimTask->handle($project, $role);

                    if ($task !== null) {
                        $agentName = $this->agentNameForLease(
                            $lease,
                            $role,
                        );

                        $this->info(
                            "Processing {$task->key} for {$role->value} [agent: {$agentName}].",
                        );

                        try {
                            if ($role === AgentRole::Coder) {
                                $goalRun = $task->goalRun;
                                if ($goalRun instanceof GoalRun) {
                                    $goalRun->update(['status' => 'implementing']);
                                    $runCoderTask->handle($task, $lease, AgentRole::BackendEngineer);
                                    $goalSessions->recordLatest($goalRun, AgentRole::BackendEngineer);
                                } else {
                                    $runCoderTask->handle($task, $lease);
                                }
                            } else {
                                $attempt = $task->attempts()
                                    ->latest('number')
                                    ->firstOrFail();

                                $runReviewerTask->run(
                                    $task,
                                    $attempt,
                                    $lease,
                                );
                                $goalRun = $task->goalRun;
                                if ($goalRun instanceof GoalRun) {
                                    $goalSessions->recordLatest($goalRun, AgentRole::Reviewer);
                                    $goalRun->refresh();
                                    $goalRun->update(['status' => $task->fresh()?->status->value === 'done' ? 'completed' : 'implementing', 'completed_at' => $task->fresh()?->status->value === 'done' ? now() : null]);
                                }
                            }
                        } catch (Throwable $throwable) {
                            report($throwable);
                        } finally {
                            $this->markTaskCompleted($lease);
                            $heartbeat->release($lease);
                        }

                        if (
                            $this->stopRequested(
                                $project,
                                $setProjectStatus,
                            )
                        ) {
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

    /**
     * Resume a completed durable Ticket triage conversion before new PM work is considered.
     */
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

    /**
     * Determine whether roadmap work still owns Project Manager scheduling precedence.
     */
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

    /**
     * Determine whether the project has an eligible Ticket ready for Project Manager triage.
     */
    private function hasEligibleTicketTriage(Project $project): bool
    {
        return $project->tickets()
            ->whereIn('status', [
                TicketStatus::Open->value,
                TicketStatus::Failed->value,
            ])
            ->exists();
    }

    /**
     * Determine whether a Ticket triage attempt already owns the Project Manager lane.
     */
    private function hasActiveTicketTriage(Project $project): bool
    {
        return $project->tickets()
            ->where('status', TicketStatus::Triaging->value)
            ->exists();
    }

    /**
     * Determine whether a planning revision is already pending or running for the project.
     */
    private function hasActivePlanningRevision(Project $project): bool
    {
        return TaskPlanningEscalation::query()
            ->whereIn('status', ['pending', 'running'])
            ->whereHas(
                'task',
                fn ($query) => $query
                    ->where('project_id', $project->id),
            )
            ->exists();
    }

    /**
     * Determine whether primary slot 1 is still within the configured Coder or Reviewer cooldown.
     */
    private function onTaskCooldown(
        Project $project,
        AgentRole $role,
    ): bool {
        return $this->onCooldown(
            $project,
            $role,
            (int) config('aios.worker_task_cooldown_seconds'),
        );
    }

    /**
     * Determine whether primary Project Manager slot 1 is still within roadmap retry cooldown.
     */
    private function onRoadmapCooldown(Project $project): bool
    {
        return $this->onCooldown(
            $project,
            AgentRole::ProjectManager,
            (int) config('aios.roadmap_retry_cooldown_seconds'),
        );
    }

    /**
     * Evaluate the cooldown timestamp for the exact primary role slot used by the serial scheduler.
     */
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
            ->where('slot', self::PrimaryWorkerSlot)
            ->first();

        $taskCompletedAt = $worker?->getAttribute('task_completed_at');

        return $taskCompletedAt instanceof CarbonImmutable
            && $taskCompletedAt
                ->addSeconds($cooldownSeconds)
                ->isFuture();
    }

    /**
     * Resolve the human-readable Agent name from the exact currently owned worker lease.
     */
    private function agentNameForLease(
        WorkerLease $lease,
        AgentRole $role,
    ): string {
        try {
            $worker = AgentWorker::query()
                ->with('agent:id,name')
                ->whereKey($lease->workerId)
                ->where('role', $role)
                ->where('worker_instance_id', $lease->workerInstanceId)
                ->where('lease_id', $lease->leaseId)
                ->first();

            $agent = $worker?->getRelation('agent');
            $name = $agent instanceof Agent
                ? $agent->getAttribute('name')
                : null;

            return is_string($name) && trim($name) !== ''
                ? $name
                : 'unavailable';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    /**
     * Persist completion timing only while the caller still owns the exact worker lease.
     */
    private function markTaskCompleted(WorkerLease $lease): void
    {
        AgentWorker::query()
            ->whereKey($lease->workerId)
            ->where('worker_instance_id', $lease->workerInstanceId)
            ->where('lease_id', $lease->leaseId)
            ->update(['task_completed_at' => now()]);
    }

    /**
     * Complete an operator-requested project stop before any new worker lease is acquired.
     *
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
