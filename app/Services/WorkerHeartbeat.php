<?php

namespace App\Services;

use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\WorkerLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class WorkerHeartbeat
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Atomically acquire the requested durable project role and slot when its lease is available.
     */
    public function acquire(
        Project $project,
        AgentRole $role,
        string $workerInstanceId,
        string $status = 'working',
        int $slot = 1,
    ): ?WorkerLease {
        $this->assertSupportedSlot($role, $slot);

        return DB::transaction(function () use (
            $project,
            $role,
            $workerInstanceId,
            $status,
            $slot,
        ): ?WorkerLease {
            $worker = AgentWorker::query()
                ->whereBelongsTo($project)
                ->where('role', $role)
                ->where('slot', $slot)
                ->lockForUpdate()
                ->first();

            if ($worker === null) {
                return null;
            }

            if (
                $worker->lease_id !== null
                && AgentWorker::query()
                    ->whereKey($worker)
                    ->where('lease_expires_at', '>', now())
                    ->exists()
            ) {
                return null;
            }

            $previousLease = $worker->lease_id;
            $lease = new WorkerLease(
                $worker->id,
                $workerInstanceId,
                (string) Str::uuid(),
                $previousLease,
            );
            $leaseExpiresAt = now()->addSeconds($this->leaseSeconds());

            $worker->update([
                'status' => $status,
                'worker_instance_id' => $lease->workerInstanceId,
                'lease_id' => $lease->leaseId,
                'lease_expires_at' => $leaseExpiresAt,
                'last_heartbeat_at' => now(),
                'process_id' => getmypid() ?: null,
                'started_at' => $worker->started_at ?? now(),
                'stopped_at' => null,
            ]);

            $this->audit->record(
                $previousLease === null
                    ? 'worker.lease_claimed'
                    : 'worker.lease_taken_over',
                [
                    'agent_worker_id' => $worker->id,
                    'role' => $role->value,
                    'slot' => $slot,
                    'worker_instance_id' => $lease->workerInstanceId,
                    'lease_id' => $lease->leaseId,
                    'previous_lease_id' => $previousLease,
                    'lease_expires_at' => $leaseExpiresAt->toIso8601String(),
                ],
                $project,
            );

            return $lease;
        }, attempts: 3);
    }

    /**
     * Atomically renew one exact worker lease without allowing a stale owner to extend a replacement lease.
     */
    public function renew(
        WorkerLease $lease,
        string $status = 'working',
    ): bool {
        $updated = AgentWorker::query()
            ->whereKey($lease->workerId)
            ->where('worker_instance_id', $lease->workerInstanceId)
            ->where('lease_id', $lease->leaseId)
            ->where('lease_expires_at', '>=', now())
            ->update([
                'status' => $status,
                'last_heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds(
                    $this->leaseSeconds(),
                ),
            ]);

        return $updated === 1;
    }

    /**
     * Atomically take over one exact stale project role and slot for deterministic recovery.
     */
    public function takeoverExpired(
        Project $project,
        AgentRole $role,
        string $workerInstanceId,
        int $legacyStaleAfterSeconds,
        int $slot = 1,
    ): ?WorkerLease {
        $this->assertSupportedSlot($role, $slot);

        return DB::transaction(function () use (
            $project,
            $role,
            $workerInstanceId,
            $legacyStaleAfterSeconds,
            $slot,
        ): ?WorkerLease {
            $worker = AgentWorker::query()
                ->whereBelongsTo($project)
                ->where('role', $role)
                ->where('slot', $slot)
                ->lockForUpdate()
                ->first();

            if (
                $worker === null
                || (
                    $worker->lease_id !== null
                    && AgentWorker::query()
                        ->whereKey($worker)
                        ->where('lease_expires_at', '>', now())
                        ->exists()
                )
            ) {
                return null;
            }

            if (
                $worker->lease_id === null
                && (
                    $worker->last_heartbeat_at === null
                    || AgentWorker::query()
                        ->whereKey($worker)
                        ->where(
                            'last_heartbeat_at',
                            '>=',
                            now()->subSeconds($legacyStaleAfterSeconds),
                        )
                        ->exists()
                )
            ) {
                return null;
            }

            $previousLease = $worker->lease_id;
            $lease = new WorkerLease(
                $worker->id,
                $workerInstanceId,
                (string) Str::uuid(),
                $previousLease,
            );
            $leaseExpiresAt = now()->addSeconds($this->leaseSeconds());

            $worker->update([
                'status' => 'recovering',
                'worker_instance_id' => $lease->workerInstanceId,
                'lease_id' => $lease->leaseId,
                'lease_expires_at' => $leaseExpiresAt,
                'last_heartbeat_at' => now(),
                'process_id' => getmypid() ?: null,
            ]);

            $this->audit->record('worker.lease_taken_over', [
                'agent_worker_id' => $worker->id,
                'role' => $role->value,
                'slot' => $slot,
                'worker_instance_id' => $lease->workerInstanceId,
                'lease_id' => $lease->leaseId,
                'previous_lease_id' => $previousLease,
                'reason' => 'lease_expired',
            ], $project);

            return $lease;
        }, attempts: 3);
    }

    /**
     * Atomically release one exact owned lease so a stale process cannot clear a replacement owner.
     */
    public function release(
        WorkerLease $lease,
        string $status = 'idle',
    ): bool {
        return DB::transaction(function () use ($lease, $status): bool {
            $worker = AgentWorker::query()
                ->whereKey($lease->workerId)
                ->where('worker_instance_id', $lease->workerInstanceId)
                ->where('lease_id', $lease->leaseId)
                ->lockForUpdate()
                ->first();

            if ($worker === null) {
                return false;
            }

            $worker->update([
                'status' => $status,
                'lease_id' => null,
                'lease_expires_at' => null,
                'last_heartbeat_at' => now(),
                'stopped_at' => now(),
            ]);

            $this->audit->record('worker.lease_released', [
                'agent_worker_id' => $worker->id,
                'role' => AgentRole::from(
                    $worker->getRawOriginal('role'),
                )->value,
                'slot' => (int) $worker->slot,
                'worker_instance_id' => $lease->workerInstanceId,
                'lease_id' => $lease->leaseId,
            ], $worker->project);

            return true;
        }, attempts: 3);
    }

    /**
     * Update the legacy non-leased heartbeat path for one exact supported project role and slot.
     */
    public function beat(
        Project $project,
        AgentRole $role,
        string $status = 'working',
        int $slot = 1,
    ): AgentWorker {
        $this->assertSupportedSlot($role, $slot);

        $worker = AgentWorker::query()
            ->whereBelongsTo($project)
            ->where('role', $role)
            ->where('slot', $slot)
            ->firstOrFail();

        $worker->update([
            'status' => $status,
            'last_heartbeat_at' => now(),
            'started_at' => $worker->started_at ?? now(),
        ]);

        return $worker->refresh();
    }

    /**
     * Reject unsupported role and slot combinations before any durable lease mutation occurs.
     */
    private function assertSupportedSlot(AgentRole $role, int $slot): void
    {
        if ($slot < 1) {
            throw new LogicException(
                'Worker slot must be a positive integer.',
            );
        }

        if ($role !== AgentRole::Coder && $slot !== 1) {
            throw new LogicException(
                'Only Coder workers may use execution slots above 1.',
            );
        }
    }

    /**
     * Resolve the bounded lease lifetime enforced for every worker slot.
     */
    private function leaseSeconds(): int
    {
        return max(2, (int) config('aios.worker_lease_seconds'));
    }
}
