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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class StaleWorkerRecovery
{
    public function __construct(
        private TaskWorkflow $workflow,
        private TicketWorkflow $ticketWorkflow,
        private AuditLogger $audit,
        private WorkspacePathResolver $paths,
        private WorkerHeartbeat $heartbeat,
    ) {}

    /**
     * Recover stale project worker executions and durable workflow attempts.
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

        foreach ([
            AgentRole::Coder,
            AgentRole::Reviewer,
            AgentRole::KnowledgeArchitect,
        ] as $role) {
            $lease = $this->heartbeat->takeoverExpired(
                $project,
                $role,
                $recoveryInstanceId,
                $staleAfterSeconds,
            );

            if ($lease === null) {
                continue;
            }

            try {
                $this->recoverWorker($project, $role, $lease);
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
        return $this->recoverAbandonedFinalization($task, AgentRole::Coder);
    }

    /**
     * Catches executions abandoned by a crashed worker process without going through
     * takeoverExpired(): once a lease expires, the durable aios:work loop's own acquire()
     * (every few seconds) can silently reclaim-and-release that worker slot on its next idle
     * cycle, long before this five-minute scan runs, resetting last_heartbeat_at and clearing
     * lease_id. That leaves the AgentWorker row looking healthy while the specific AgentRun (and
     * its Task) the crashed process was executing stays stuck at status Running/Coding forever.
     * This scan is keyed on the run's own age and its lease no longer matching the worker's
     * current lease, so it is immune to that race.
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
     * Recover one orphaned running AgentRun when its original worker lease is gone.
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

            $worker = AgentWorker::query()
                ->whereBelongsTo($lockedRun->project)
                ->where('role', $role)
                ->first();

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
                'evidence' => $evidence,
            ], $lockedRun->project, $task);

            return true;
        }, attempts: 3);
    }

    /**
     * Catches a task left claimed (Coding/Validating/Reviewing) whose harness execution already
     * finished (or never started) without AIOS ever finalizing it: no `AgentRun` is currently
     * `Running` for that task/role, yet the task is still sitting in a claimed status past the
     * stale floor. This differs from recoverOrphanedRuns(), which requires an `AgentRun` still
     * marked `Running`: it exists for a worker process that crashed *during* harness execution.
     * This method exists for the harness finishing normally (or a lease-holding process crashing
     * before ever recording a run) but the surrounding orchestration code never reaching its own
     * validate/commit/transition step afterward (e.g. the host process was killed between
     * AgentRunRecorder::complete() and RunCoderTask's subsequent validation). A task in that
     * state is invisible to both recoverOrphanedRuns() (its AgentRun is not Running) and
     * WorkflowRecoveryScanner::detectStuckTasks() (Coding/Reviewing are not tracked terminal-stuck
     * statuses), so without this it would block the role's single-task-in-flight claim (and, for
     * Coder, any current-phase Reviewer review) indefinitely.
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
                ->where('updated_at', '<=', now()->subSeconds($staleAfterSeconds))
                ->get()
                ->each(function (Task $task) use ($role, &$recovered): void {
                    if ($this->recoverAbandonedFinalization($task, $role)) {
                        $recovered++;
                    }
                });
        }

        return $recovered;
    }

    /**
     * Recover one claimed task whose recorded execution ended without finalizing workflow state.
     */
    private function recoverAbandonedFinalization(Task $task, AgentRole $role): bool
    {
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
                    TaskStatus::from($lockedTask->getRawOriginal('status')),
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

            // Elapsed time alone is never sufficient evidence (see class docblock): require a
            // persisted AgentRun for this task, role, and current attempt to prove this exact
            // execution genuinely happened. A completed run from an earlier attempt must not
            // turn a fresh reviewer claim into an abandoned finalization.
            $runs = AgentRun::query()
                ->whereBelongsTo($lockedTask)
                ->where('role', $role)
                ->where(function ($query) use ($attempt): void {
                    $query->where('attempt_number', $attempt->number)
                        ->orWhere(function ($query) use ($attempt): void {
                            $query->whereNull('attempt_number')
                                ->where('started_at', '>=', $attempt->started_at);
                        });
                })
                ->get();

            if ($runs->isEmpty() || $runs->contains(fn (AgentRun $run): bool => AgentRunStatus::from($run->getRawOriginal('status')) === AgentRunStatus::Running)) {
                return false;
            }

            $worker = AgentWorker::query()
                ->whereBelongsTo($lockedTask->project)
                ->where('role', $role)
                ->whereNotNull('lease_id')
                ->where('lease_expires_at', '>', now())
                ->first();

            if ($worker !== null && $runs->contains(fn (AgentRun $run): bool => $run->worker_lease_id === $worker->lease_id)) {
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

                $this->workflow->transition($lockedTask, TaskStatus::Failed);
            } elseif ($this->workflow->reconcileExistingReviewerDecision($lockedTask, $attempt) === null) {
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
     * Recover durable state owned by an expired project worker lease.
     */
    private function recoverWorker(
        Project $project,
        AgentRole $role,
        WorkerLease $lease,
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
                'role' => $role->value,
                'worker_instance_id' => $lease->workerInstanceId,
                'lease_id' => $lease->leaseId,
            ], $project);

            return;
        }

        $recoveredTasks = [];

        $project->tasks()
            ->whereIn('status', $statuses)
            ->get()
            ->each(function (Task $task) use (
                $role,
                $project,
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
                    'role' => $role->value,
                    'evidence' => $evidence,
                ], $project, $task);

                $recoveredTasks[] = [
                    'task_key' => $task->key,
                    'evidence' => $evidence,
                ];
            });

        $this->audit->record('worker.recovered', [
            'role' => $role->value,
            'worker_instance_id' => $lease->workerInstanceId,
            'lease_id' => $lease->leaseId,
            'tasks' => $recoveredTasks,
        ], $project);
    }

    /**
     * Recover stale roadmap processing attempts owned by the Project Manager lane.
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
            ->first();

        $lease = $worker === null
            ? null
            : $this->heartbeat->takeoverExpired(
                $project,
                AgentRole::ProjectManager,
                $recoveryInstanceId,
                $staleAfterSeconds,
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
     * Recover stale Ticket triage attempts owned by the Project Manager lane.
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
            ->first();

        $lease = $worker === null
            ? null
            : $this->heartbeat->takeoverExpired(
                $project,
                AgentRole::ProjectManager,
                $recoveryInstanceId,
                $staleAfterSeconds,
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
     * Interrupt running AgentRun records associated with an expired worker lease.
     */
    private function interruptRuns(
        Project $project,
        AgentRole $role,
        WorkerLease $lease,
    ): void {
        AgentRun::query()
            ->whereBelongsTo($project)
            ->where('role', $role)
            ->where('status', AgentRunStatus::Running)
            ->where(function ($query) use ($lease): void {
                if ($lease->previousLeaseId !== null) {
                    $query->where(
                        'worker_lease_id',
                        $lease->previousLeaseId,
                    );

                    return;
                }

                $query->whereNull('worker_lease_id');
            })
            ->update([
                'status' => AgentRunStatus::Interrupted,
                'finished_at' => now(),
            ]);
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
