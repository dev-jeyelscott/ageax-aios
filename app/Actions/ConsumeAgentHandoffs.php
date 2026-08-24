<?php

namespace App\Actions;

use App\AgentHandoffStatus;
use App\AgentRunStatus;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\Task;
use App\Services\AgentHandoffContextSelector;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ConsumeAgentHandoffs
{
    /**
     * Inject deterministic freshness validation and append-only auditing.
     */
    public function __construct(
        private AgentHandoffContextSelector $selector,
        private AuditLogger $audit,
    ) {}

    /**
     * Atomically consume the exact handoffs surviving final Context Budget approval.
     *
     * @param  list<int>  $handoffIds
     */
    public function handle(
        AgentRun $targetRun,
        array $handoffIds,
        string $contextHash,
        ?AgentRun $recoverySource = null,
    ): void {
        $handoffIds = $this->normalizedIds($handoffIds);

        if ($handoffIds === []) {
            return;
        }

        if ($contextHash === '') {
            throw new LogicException(
                'Agent handoff consumption requires the final approved context hash.',
            );
        }

        DB::transaction(
            function () use (
                $targetRun,
                $handoffIds,
                $contextHash,
                $recoverySource,
            ): void {
                $run = AgentRun::query()
                    ->whereKey($targetRun->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $run->getRawOriginal('status') !== AgentRunStatus::Running->value
                    || $run->task_id === null
                ) {
                    throw new LogicException(
                        'Agent handoffs may only be consumed for a running task-scoped AgentRun.',
                    );
                }

                $task = Task::query()
                    ->whereKey($run->task_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $task->project_id !== (int) $run->project_id) {
                    throw new LogicException(
                        'Agent handoff consumption cannot cross the target AgentRun project boundary.',
                    );
                }

                $run->setRelation('task', $task);

                $lockedRecoverySource = null;

                if ($recoverySource !== null) {
                    $lockedRecoverySource = AgentRun::query()
                        ->whereKey($recoverySource->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $handoffs = AgentHandoff::query()
                    ->whereIn('id', $handoffIds)
                    ->with('sourceRun')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($handoffs->count() !== count($handoffIds)) {
                    throw new LogicException(
                        'One or more approved Agent handoffs no longer exist at consumption time.',
                    );
                }

                foreach ($handoffs as $handoff) {
                    if (
                        ! $this->selector->isConsumableFor(
                            $handoff,
                            $run,
                            $lockedRecoverySource,
                        )
                    ) {
                        throw new LogicException(
                            "Agent handoff [{$handoff->id}] is no longer eligible for this execution.",
                        );
                    }
                }

                $consumedAt = now();

                foreach ($handoffs as $handoff) {
                    $handoff->update([
                        'status' => AgentHandoffStatus::Consumed->value,
                        'consumed_at' => $consumedAt,
                    ]);

                    $this->audit->record(
                        'agent_handoff.consumed',
                        [
                            'agent_handoff_id' => $handoff->id,
                            'source_agent_run_id' => $handoff->from_agent_run_id,
                            'target_agent_run_id' => $run->id,
                            'from_role' => (string) $handoff->getRawOriginal('from_role'),
                            'to_role' => (string) $handoff->getRawOriginal('to_role'),
                            'handoff_type' => (string) $handoff->getRawOriginal('handoff_type'),
                            'schema_version' => $handoff->schema_version,
                            'content_hash' => $handoff->content_hash,
                            'project_id' => $run->project_id,
                            'task_id' => $run->task_id,
                            'target_attempt_number' => $run->attempt_number,
                            'context_hash' => $contextHash,
                            'recovery_source_agent_run_id' => $lockedRecoverySource?->id,
                        ],
                        $run->project,
                        $run->task,
                    );
                }
            },
            attempts: 3,
        );
    }

    /**
     * Normalize requested IDs to deterministic unique positive integers.
     *
     * @param  list<int>  $handoffIds
     * @return list<int>
     */
    private function normalizedIds(
        array $handoffIds,
    ): array {
        $ids = [];

        foreach ($handoffIds as $id) {
            if ($id < 1) {
                throw new LogicException(
                    'Agent handoff consumption received an invalid handoff id.',
                );
            }

            if (in_array($id, $ids, true)) {
                throw new LogicException(
                    'Agent handoff consumption received duplicate handoff ids.',
                );
            }

            $ids[] = $id;
        }

        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
