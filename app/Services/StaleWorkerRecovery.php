<?php

namespace App\Services;

use App\AgentRole;
use App\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\AgentWorker;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapAttempt;
use App\Models\Task;
use App\Models\TaskAttempt;
use App\Models\Ticket;
use App\Models\TicketTriageAttempt;
use App\TaskStatus;
use App\TicketStatus;
use App\WorkerLease;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class StaleWorkerRecovery
{
    private const int PrimaryWorkerSlot = 1;

    public function __construct(
        private TaskWorkflow $workflow,
        private TicketWorkflow $ticketWorkflow,
        private AuditLogger $audit,
        private WorkspacePathResolver $paths,
        private WorkerHeartbeat $heartbeat,
    ) {}

    /**
     * Recover stale project worker executions and durable workflow attempts across supported slots.
     */
    public function recover(
        Project $project,
        ?int $staleAfterSeconds = null,
    ): int {
        $staleAfterSeconds ??= (int) config(
            'aios.stale_worker_after_seconds',
        );

        $recoveryInstanceId = (string) Str::uuid();
        $recovered = 0;

        $workers = AgentWorker::query()
            ->whereBelongsTo($project)
            ->where(function ($query): void {
                $query->where('role', AgentRole::Coder)
                    ->orWhere(function ($query): void {
                        $query->whereIn('role', [
                            AgentRole::Reviewer,
                            AgentRole::KnowledgeArchitect,
                        ])->where('slot', self::PrimaryWorkerSlot);
                    });
            })
            ->orderBy('role')
            ->orderBy('slot')
            ->get(['id', 'role', 'slot']);

        foreach ($workers as $worker) {
            $role = AgentRole::from(
                (string) $worker->getRawOriginal('role'),
            );
            $slot = (int) $worker->slot;

            $lease = $this->heartbeat->takeoverExpired(
                $project,
                $role,
                $recoveryInstanceId,
                $staleAfterSeconds,
                slot: $slot,
            );

            if ($lease === null) {
                continue;
            }

            try {
                $this->recoverWorker(
                    $project,
                    $role,
                    $lease,
                    $slot,
                );
                $recovered++;
            } finally {
                $this->heartbeat->release($lease, 'interrupted');
            }
        }

        return $recovered
            + $this->recoverOrphanedRuns($project, $staleAfterSeconds)
            + $this->recoverAbandonedFinalizations($project, $staleAfterSeconds)
            + $this->recoverStaleRoadmaps(
                $project,
                $staleAfterSeconds,
                $recoveryInstanceId,
            )
            + $this->recoverStaleTicketTriage(
                $project,
                $staleAfterSeconds,
                $recoveryInstanceId,
            );
    }

    /**
     * Recover one Coder attempt whose harness result was persisted but whose orchestration
     * finalization did not complete before a later worker reclaimed the task.
     */
    public function recoverAbandonedCoderFinalization(Task $task): bool
    {
        return $this->recoverAbandonedFinalization(
            $task,
            AgentRole::Coder,
        );
    }

    /**
     * Recover old running AgentRuns whose original lease no longer exists.
     */
    private function recoverOrphanedRuns(
        Project $project,
        int $staleAfterSeconds,
    ): int {
        $recovered = 0;

        AgentRun::query()
            ->whereBelongsTo($project)
            ->whereIn('role', [
                AgentRole::Coder,
                AgentRole::Reviewer,
            ])
            ->where('status', AgentRunStatus::Running)
            ->where(
                'started_at',
                '<=',
                now()->subSeconds($staleAfterSeconds),
            )
            ->get()
            ->each(function (AgentRun $run) use (&$recovered): void {
                if ($this->recoverOrphanedRun($run)) {
                    $recovered++;
                }
            });

        return $recovered;
    }

    /**
     * Recover one orphaned running AgentRun when its exact originating worker lease is gone.
     */
    private function recoverOrphanedRun(AgentRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $lockedRun = AgentRun::query()
                ->lockForUpdate()
                ->find($run->id);

            if (
                $lockedRun === null
                || AgentRunStatus::from(
                    $lockedRun->getRawOriginal('status'),
                ) !== AgentRunStatus::Running
            ) {
                return false;
            }

            $role = AgentRole::from(
                (string) $lockedRun->getRawOriginal('role'),
            );

            $worker = $this->workerForRun($lockedRun, $role);

            if (
                $worker !== null
                && $worker->lease_id !== null
                && $worker->lease_id === $lockedRun->worker_lease_id
            ) {
                return false;
            }

            $task = $lockedRun->task_id === null
                ? null
                : Task::query()
                    ->lockForUpdate()
                    ->find($lockedRun->task_id);

            $expectedStatuses = match ($role) {
                AgentRole::Coder => [
                    TaskStatus::Coding,
                    TaskStatus::Validating,
                ],
                AgentRole::Reviewer => [
                    TaskStatus::Reviewing,
                ],
                AgentRole::KnowledgeArchitect,
                AgentRole::Orchestrator,
                AgentRole::ProjectManager,
                AgentRole::RecoveryEngineer => [],
            };

            if (
                $task === null
                || $expectedStatuses === []
                || ! in_array(
                    TaskStatus::from(
                        $task->getRawOriginal('status'),
                    ),
                    $expectedStatuses,
                    true,
                )
            ) {
                return false;
            }

            $lockedRun->update([
                'status' => AgentRunStatus::Interrupted,
                'finished_at' => now(),
            ]);

            $evidence = $this->recoveryEvidence($task);
            $this->storeRecoveryEvidence($task, $evidence);

            if ($role === AgentRole::Coder) {
                $task->attempts()
                    ->where('status', 'running')
                    ->update([
                        'status' => 'interrupted',
                        'finished_at' => now(),
                    ]);

                $this->workflow->transition(
                    $task,
                    TaskStatus::Failed,
                );
            } else {
                $this->workflow->recordReviewerOperationalFailure(
                    $task,
                    $task->attempts()
                        ->latest('number')
                        ->first(),
                    [
                        'reason' => 'orphaned_agent_run',
                        'evidence' => $evidence,
                    ],
                );
            }

            $this->audit->record('task.recovered', [
                'role' => $role->value,
                'reason' => 'orphaned_agent_run',
                'agent_run_id' => $lockedRun->id,
                'agent_worker_id' => $lockedRun->agent_worker_id,
                'evidence' => $evidence,
            ], $lockedRun->project, $task);

            return true;
        }, attempts: 3);
    }

    /**
     * Recover claimed tasks whose execution ended without final workflow finalization.
     */
    private function recoverAbandonedFinalizations(
        Project $project,
        int $staleAfterSeconds,
    ): int {
        $recovered = 0;

        foreach ([AgentRole::Coder, AgentRole::Reviewer] as $role) {
            $statuses = $role === AgentRole::Coder
                ? [TaskStatus::Coding, TaskStatus::Validating]
                : [TaskStatus::Reviewing];

            Task::query()
                ->whereBelongsTo($project)
                ->whereIn('status', $statuses)
                ->where(
                    'updated_at',
                    '<=',
                    now()->subSeconds($staleAfterSeconds),
                )
                ->get()
                ->each(function (Task $task) use (
                    $role,
                    &$recovered,
                ): void {
                    if (
                        $this->recoverAbandonedFinalization(
                            $task,
                            $role,
                        )
                    ) {
                        $recovered++;
                    }
                });
        }

        return $recovered;
    }

    /**
     * Recover one claimed task whose recorded execution ended without finalizing workflow state.
     */
    private function recoverAbandonedFinalization(
        Task $task,
        AgentRole $role,
    ): bool {
        return DB::transaction(function () use ($task, $role): bool {
            $lockedTask = Task::query()
                ->lockForUpdate()
                ->find($task->id);

            $expectedStatuses = $role === AgentRole::Coder
                ? [TaskStatus::Coding, TaskStatus::Validating]
                : [TaskStatus::Reviewing];

            if (
                $lockedTask === null
                || ! in_array(
                    TaskStatus::from(
                        $lockedTask->getRawOriginal('status'),
                    ),
                    $expectedStatuses,
                    true,
                )
            ) {
                return false;
            }

            $attempt = $lockedTask->attempts()
                ->when(
                    $role === AgentRole::Coder,
                    fn ($query) => $query->where('status', 'running'),
                )
                ->latest('number')
                ->first();

            if ($attempt === null) {
                return false;
            }

            $runs = AgentRun::query()
                ->whereBelongsTo($lockedTask)
                ->where('role', $role)
                ->where(function ($query) use ($attempt): void {
                    $query->where('attempt_number', $attempt->number)
                        ->orWhere(function ($query) use ($attempt): void {
                            $query->whereNull('attempt_number')
                                ->where(
                                    'started_at',
                                    '>=',
                                    $attempt->started_at,
                                );
                        });
                })
                ->get();

            if (
                $runs->isEmpty()
                || $runs->contains(
                    fn (AgentRun $run): bool => AgentRunStatus::from(
                        $run->getRawOriginal('status'),
                    ) === AgentRunStatus::Running,
                )
            ) {
                return false;
            }

            if (
                $this->hasActiveMatchingWorkerLease(
                    $lockedTask->project,
                    $role,
                    $runs,
                )
            ) {
                return false;
            }

            $evidence = $this->recoveryEvidence($lockedTask);
            $this->storeRecoveryEvidence($lockedTask, $evidence);

            if ($role === AgentRole::Coder) {
                $lockedTask->attempts()
                    ->where('status', 'running')
                    ->update([
                        'status' => 'interrupted',
                        'finished_at' => now(),
                    ]);

                $this->workflow->transition(
                    $lockedTask,
                    TaskStatus::Failed,
                );
            } elseif (
                $this->workflow->reconcileExistingReviewerDecision(
                    $lockedTask,
                    $attempt,
                ) === null
            ) {
                $this->workflow->recordReviewerOperationalFailure(
                    $lockedTask,
                    $attempt,
                    [
                        'reason' => 'abandoned_finalization',
                        'evidence' => $evidence,
                    ],
                );
            }

            $this->audit->record('task.recovered', [
                'role' => $role->value,
                'reason' => 'abandoned_finalization',
                'evidence' => $evidence,
            ], $lockedTask->project, $lockedTask);

            return true;
        }, attempts: 3);
    }

    /**
     * Recover durable state proven to belong to one expired project worker slot lease.
     */
    private function recoverWorker(
        Project $project,
        AgentRole $role,
        WorkerLease $lease,
        int $slot,
    ): void {
        $this->interruptRuns($project, $role, $lease);

        $statuses = match ($role) {
            AgentRole::Coder => [
                TaskStatus::Coding,
                TaskStatus::Validating,
            ],
            AgentRole::Reviewer => [
                TaskStatus::Reviewing,
            ],
            AgentRole::KnowledgeArchitect,
            AgentRole::Orchestrator,
            AgentRole::ProjectManager,
            AgentRole::RecoveryEngineer => [],
        };

        if ($statuses === []) {
            $this->audit->record('worker.recovered', [
                'agent_worker_id' => $lease->workerId,
                'role' => $role->value,
                'slot' => $slot,
                'worker_instance_id' => $lease->workerInstanceId,
                'lease_id' => $lease->leaseId,
            ], $project);

            return;
        }

        $recoveredTasks = [];
        $taskIds = $this->taskIdsOwnedByExpiredLease(
            $project,
            $role,
            $lease,
        );

        if (
            $taskIds === []
            && $this->roleHasSingleWorker($project, $role)
        ) {
            $taskIds = $project->tasks()
                ->whereIn('status', $statuses)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        $project->tasks()
            ->whereIn('id', $taskIds)
            ->whereIn('status', $statuses)
            ->get()
            ->each(function (Task $task) use (
                $role,
                $project,
                $lease,
                $slot,
                &$recoveredTasks,
            ): void {
                $evidence = $this->recoveryEvidence($task);
                $this->storeRecoveryEvidence($task, $evidence);

                if ($role === AgentRole::Coder) {
                    $task->attempts()
                        ->where('status', 'running')
                        ->update([
                            'status' => 'interrupted',
                            'finished_at' => now(),
                        ]);

                    $this->workflow->transition(
                        $task,
                        TaskStatus::Failed,
                    );
                } else {
                    $this->workflow->recordReviewerOperationalFailure(
                        $task,
                        $task->attempts()
                            ->latest('number')
                            ->first(),
                        [
                            'reason' => 'expired_worker_lease',
                            'evidence' => $evidence,
                        ],
                    );
                }

                $this->audit->record('task.recovered', [
                    'agent_worker_id' => $lease->workerId,
                    'role' => $role->value,
                    'slot' => $slot,
                    'evidence' => $evidence,
                ], $project, $task);

                $recoveredTasks[] = [
                    'task_key' => $task->key,
                    'evidence' => $evidence,
                ];
            });

        $this->audit->record('worker.recovered', [
            'agent_worker_id' => $lease->workerId,
            'role' => $role->value,
            'slot' => $slot,
            'worker_instance_id' => $lease->workerInstanceId,
            'lease_id' => $lease->leaseId,
            'tasks' => $recoveredTasks,
        ], $project);
    }

    /**
     * Recover stale roadmap processing attempts owned by Project Manager slot 1.
     */
    private function recoverStaleRoadmaps(
        Project $project,
        int $staleAfterSeconds,
        string $recoveryInstanceId,
    ): int {
        $attempts = RoadmapAttempt::query()
            ->whereHas(
                'roadmap',
                fn ($query) => $query
                    ->whereBelongsTo($project)
                    ->where('status', 'processing'),
            )
            ->whereIn('status', ['claimed', 'running'])
            ->where(
                'claimed_at',
                '<',
                now()->subSeconds($staleAfterSeconds),
            )
            ->get();

        if ($attempts->isEmpty()) {
            return 0;
        }

        $worker = $project->workers()
            ->where('role', AgentRole::ProjectManager)
            ->where('slot', self::PrimaryWorkerSlot)
            ->first();

        $lease = $worker === null
            ? null
            : $this->heartbeat->takeoverExpired(
                $project,
                AgentRole::ProjectManager,
                $recoveryInstanceId,
                $staleAfterSeconds,
                slot: self::PrimaryWorkerSlot,
            );

        if ($worker !== null && $lease === null) {
            return 0;
        }

        try {
            foreach ($attempts as $attempt) {
                DB::transaction(function () use (
                    $attempt,
                    $project,
                    $lease,
                    $staleAfterSeconds,
                ): void {
                    $lockedAttempt = RoadmapAttempt::query()
                        ->lockForUpdate()
                        ->findOrFail($attempt->id);

                    $roadmap = Roadmap::query()
                        ->lockForUpdate()
                        ->findOrFail($lockedAttempt->roadmap_id);

                    if (
                        $roadmap->getRawOriginal('status') !== 'processing'
                        || ! in_array(
                            $lockedAttempt->getRawOriginal('status'),
                            ['claimed', 'running'],
                            true,
                        )
                        || RoadmapAttempt::query()
                            ->whereKey($lockedAttempt)
                            ->where(
                                'claimed_at',
                                '>=',
                                now()->subSeconds($staleAfterSeconds),
                            )
                            ->exists()
                    ) {
                        return;
                    }

                    if ($lockedAttempt->agent_run_id !== null) {
                        $run = AgentRun::query()
                            ->lockForUpdate()
                            ->find($lockedAttempt->agent_run_id);

                        if (
                            $run !== null
                            && $run->getRawOriginal('status')
                                === AgentRunStatus::Running->value
                            && (
                                $lease === null
                                || $lease->previousLeaseId === null
                                || $run->worker_lease_id
                                    === $lease->previousLeaseId
                            )
                        ) {
                            $run->update([
                                'status' => AgentRunStatus::Interrupted,
                                'finished_at' => now(),
                            ]);
                        }
                    }

                    $lockedAttempt->update([
                        'status' => 'interrupted',
                        'finished_at' => now(),
                    ]);

                    $roadmap->update(['status' => 'failed']);

                    $this->audit->record(
                        'roadmap.processing_interrupted',
                        [
                            'roadmap_id' => $roadmap->id,
                            'roadmap_attempt_id' => $lockedAttempt->id,
                            'agent_run_id' => $lockedAttempt->agent_run_id,
                            'worker_instance_id' => $lease?->workerInstanceId,
                            'lease_id' => $lease?->leaseId,
                        ],
                        $project,
                    );

                    $this->audit->record(
                        'roadmap.retry_scheduled',
                        [
                            'roadmap_id' => $roadmap->id,
                            'roadmap_attempt_id' => $lockedAttempt->id,
                        ],
                        $project,
                    );
                }, attempts: 3);
            }
        } finally {
            if ($lease !== null) {
                $this->heartbeat->release(
                    $lease,
                    'interrupted',
                );
            }
        }

        return $attempts->count()
            + ($lease === null ? 0 : 1);
    }

    /**
     * Recover stale Ticket triage attempts owned by Project Manager slot 1.
     */
    private function recoverStaleTicketTriage(
        Project $project,
        int $staleAfterSeconds,
        string $recoveryInstanceId,
    ): int {
        $attempts = TicketTriageAttempt::query()
            ->whereHas(
                'ticket',
                fn ($query) => $query
                    ->whereBelongsTo($project)
                    ->where(
                        'status',
                        TicketStatus::Triaging->value,
                    ),
            )
            ->whereIn('status', ['claimed', 'running'])
            ->where(
                'claimed_at',
                '<',
                now()->subSeconds($staleAfterSeconds),
            )
            ->orderBy('claimed_at')
            ->orderBy('id')
            ->get();

        if ($attempts->isEmpty()) {
            return 0;
        }

        $worker = $project->workers()
            ->where('role', AgentRole::ProjectManager)
            ->where('slot', self::PrimaryWorkerSlot)
            ->first();

        $lease = $worker === null
            ? null
            : $this->heartbeat->takeoverExpired(
                $project,
                AgentRole::ProjectManager,
                $recoveryInstanceId,
                $staleAfterSeconds,
                slot: self::PrimaryWorkerSlot,
            );

        if ($worker !== null && $lease === null) {
            $freshWorker = AgentWorker::query()
                ->find($worker->id);

            if ($freshWorker?->lease_id !== null) {
                return 0;
            }
        }

        $recovered = 0;

        try {
            foreach ($attempts as $attempt) {
                $didRecover = DB::transaction(function () use (
                    $attempt,
                    $project,
                    $lease,
                    $staleAfterSeconds,
                ): bool {
                    $lockedWorker = AgentWorker::query()
                        ->whereBelongsTo($project)
                        ->where(
                            'role',
                            AgentRole::ProjectManager,
                        )
                        ->where('slot', self::PrimaryWorkerSlot)
                        ->lockForUpdate()
                        ->first();

                    if (
                        $lease !== null
                        && (
                            $lockedWorker === null
                            || $lockedWorker->lease_id
                                !== $lease->leaseId
                        )
                    ) {
                        return false;
                    }

                    if (
                        $lease === null
                        && $lockedWorker !== null
                        && $lockedWorker->lease_id !== null
                    ) {
                        return false;
                    }

                    $lockedAttempt = TicketTriageAttempt::query()
                        ->lockForUpdate()
                        ->findOrFail($attempt->id);

                    $ticket = Ticket::query()
                        ->lockForUpdate()
                        ->findOrFail($lockedAttempt->ticket_id);

                    if (
                        TicketStatus::from(
                            $ticket->getRawOriginal('status'),
                        ) !== TicketStatus::Triaging
                        || ! in_array(
                            $lockedAttempt->getRawOriginal('status'),
                            ['claimed', 'running'],
                            true,
                        )
                        || TicketTriageAttempt::query()
                            ->whereKey($lockedAttempt)
                            ->where(
                                'claimed_at',
                                '>=',
                                now()->subSeconds($staleAfterSeconds),
                            )
                            ->exists()
                    ) {
                        return false;
                    }

                    if ($lockedAttempt->agent_run_id !== null) {
                        $run = AgentRun::query()
                            ->lockForUpdate()
                            ->find($lockedAttempt->agent_run_id);

                        if (
                            $run !== null
                            && $run->getRawOriginal('status')
                                === AgentRunStatus::Running->value
                            && (
                                $lease === null
                                || $lease->previousLeaseId === null
                                || $run->worker_lease_id
                                    === $lease->previousLeaseId
                            )
                        ) {
                            $run->update([
                                'status' => AgentRunStatus::Interrupted,
                                'finished_at' => now(),
                            ]);
                        }
                    }

                    $lockedAttempt->update([
                        'status' => 'interrupted',
                        'finished_at' => now(),
                    ]);

                    $this->ticketWorkflow->transition(
                        $ticket,
                        TicketStatus::Failed,
                    );

                    $this->audit->record(
                        'ticket.triage_interrupted',
                        [
                            'ticket_id' => $ticket->id,
                            'ticket_key' => $ticket->key,
                            'ticket_triage_attempt_id' => $lockedAttempt->id,
                            'attempt_number' => $lockedAttempt->number,
                            'agent_run_id' => $lockedAttempt->agent_run_id,
                            'worker_instance_id' => $lease?->workerInstanceId,
                            'lease_id' => $lease?->leaseId,
                            'previous_lease_id' => $lease?->previousLeaseId,
                        ],
                        $project,
                    );

                    $this->audit->record(
                        'ticket.triage_retry_scheduled',
                        [
                            'ticket_id' => $ticket->id,
                            'ticket_key' => $ticket->key,
                            'ticket_triage_attempt_id' => $lockedAttempt->id,
                            'attempt_number' => $lockedAttempt->number,
                            'agent_run_id' => $lockedAttempt->agent_run_id,
                        ],
                        $project,
                    );

                    return true;
                }, attempts: 3);

                if ($didRecover) {
                    $recovered++;
                }
            }
        } finally {
            if ($lease !== null) {
                $this->heartbeat->release(
                    $lease,
                    'interrupted',
                );
            }
        }

        return $recovered;
    }

    /**
     * Interrupt only running AgentRun records associated with the exact expired worker lease.
     */
    private function interruptRuns(
        Project $project,
        AgentRole $role,
        WorkerLease $lease,
    ): void {
        $query = AgentRun::query()
            ->whereBelongsTo($project)
            ->where('role', $role)
            ->where('status', AgentRunStatus::Running);

        if ($lease->previousLeaseId !== null) {
            $query->where(
                'worker_lease_id',
                $lease->previousLeaseId,
            )->where(function ($query) use ($lease): void {
                $query->where('agent_worker_id', $lease->workerId)
                    ->orWhereNull('agent_worker_id');
            });
        } else {
            $query->where('agent_worker_id', $lease->workerId)
                ->whereNull('worker_lease_id');

            if ($this->roleHasSingleWorker($project, $role)) {
                $query->orWhere(function ($query) use (
                    $project,
                    $role,
                ): void {
                    $query->whereBelongsTo($project)
                        ->where('role', $role)
                        ->where('status', AgentRunStatus::Running)
                        ->whereNull('agent_worker_id')
                        ->whereNull('worker_lease_id');
                });
            }
        }

        $query->update([
            'status' => AgentRunStatus::Interrupted,
            'finished_at' => now(),
        ]);
    }

    /**
     * Resolve task IDs that durable AgentRun evidence ties to the expired worker lease.
     *
     * @return list<int>
     */
    private function taskIdsOwnedByExpiredLease(
        Project $project,
        AgentRole $role,
        WorkerLease $lease,
    ): array {
        $query = AgentRun::query()
            ->whereBelongsTo($project)
            ->where('role', $role)
            ->whereNotNull('task_id');

        if ($lease->previousLeaseId !== null) {
            $query->where(
                'worker_lease_id',
                $lease->previousLeaseId,
            )->where(function ($query) use ($lease): void {
                $query->where('agent_worker_id', $lease->workerId)
                    ->orWhereNull('agent_worker_id');
            });
        } else {
            $query->where('agent_worker_id', $lease->workerId)
                ->whereNull('worker_lease_id');
        }

        return $query->pluck('task_id')
            ->map(fn ($taskId): int => (int) $taskId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resolve the worker row that owns an AgentRun without collapsing multiple role slots to first().
     */
    private function workerForRun(
        AgentRun $run,
        AgentRole $role,
    ): ?AgentWorker {
        if ($run->agent_worker_id !== null) {
            return AgentWorker::query()
                ->whereKey($run->agent_worker_id)
                ->whereBelongsTo($run->project)
                ->where('role', $role)
                ->first();
        }

        $workers = AgentWorker::query()
            ->whereBelongsTo($run->project)
            ->where('role', $role)
            ->limit(2)
            ->get();

        return $workers->count() === 1
            ? $workers->first()
            : null;
    }

    /**
     * Determine whether any active worker lease exactly matches the current attempt run evidence.
     *
     * @param  EloquentCollection<int, AgentRun>  $runs
     */
    private function hasActiveMatchingWorkerLease(
        Project $project,
        AgentRole $role,
        EloquentCollection $runs,
    ): bool {
        $workers = AgentWorker::query()
            ->whereBelongsTo($project)
            ->where('role', $role)
            ->whereNotNull('lease_id')
            ->where('lease_expires_at', '>', now())
            ->get(['id', 'lease_id']);

        return $runs->contains(function (AgentRun $run) use (
            $workers,
        ): bool {
            return $workers->contains(function (
                AgentWorker $worker,
            ) use ($run): bool {
                return $run->worker_lease_id === $worker->lease_id
                    && (
                        $run->agent_worker_id === null
                        || (int) $run->agent_worker_id
                            === (int) $worker->id
                    );
            });
        });
    }

    /**
     * Determine whether a role still has the legacy unambiguous single-worker topology.
     */
    private function roleHasSingleWorker(
        Project $project,
        AgentRole $role,
    ): bool {
        return AgentWorker::query()
            ->whereBelongsTo($project)
            ->where('role', $role)
            ->limit(2)
            ->count() === 1;
    }

    /**
     * Capture durable task, attempt, run, and repository evidence before recovery mutates state.
     *
     * @return array<string, mixed>
     */
    private function recoveryEvidence(Task $task): array
    {
        $task->loadMissing('project');

        $attempt = $task->attempts()
            ->latest('number')
            ->first();

        $run = $task->runs()
            ->latest('started_at')
            ->first();

        $evidence = [
            'base_sha' => $attempt?->base_sha,
            'previous_attempt' => $attempt?->only([
                'number',
                'status',
                'head_sha',
                'commit_sha',
                'validation_results',
                'changed_files',
                'log_path',
            ]),
            'previous_run' => $run?->only([
                'id',
                'status',
                'exit_code',
                'result',
                'log_path',
                'worker_instance_id',
                'worker_lease_id',
            ]),
        ];

        try {
            $projectPath = $this->paths->assertProjectPath(
                $task->project->path,
            );

            $head = Process::path($projectPath)
                ->run(['git', 'rev-parse', 'HEAD']);

            $status = Process::path($projectPath)
                ->run(['git', 'status', '--porcelain']);

            $diff = Process::path($projectPath)
                ->run(['git', 'diff', '--stat', 'HEAD']);

            $evidence['current_head_sha'] = $head->successful()
                ? trim($head->output())
                : null;

            $evidence['working_tree'] = $status->successful()
                ? trim($status->output())
                : null;

            $evidence['diff_stat'] = $diff->successful()
                ? trim($diff->output())
                : null;
        } catch (Throwable $throwable) {
            $evidence['repository_inspection_error']
                = $throwable::class;
        }

        return $evidence;
    }

    /**
     * Persist captured recovery evidence on the latest Task attempt.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function storeRecoveryEvidence(
        Task $task,
        array $evidence,
    ): void {
        $attempt = $task->attempts()
            ->latest('number')
            ->first();

        if (! $attempt instanceof TaskAttempt) {
            return;
        }

        try {
            $validationResults = json_decode(
                (string) $attempt->getRawOriginal(
                    'validation_results',
                ),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            $validationResults = [];
        }

        $validationResults = is_array($validationResults)
            ? $validationResults
            : [];

        $validationResults['recovery'] = $evidence;

        $attempt->update([
            'validation_results' => $validationResults,
        ]);
    }
}
