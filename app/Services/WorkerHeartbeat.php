<?php

namespace App\Services;

use App\AgentRole;
use App\Models\AgentWorker;
use App\Models\Project;
use App\WorkerLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkerHeartbeat
{
    public function __construct(private AuditLogger $audit) {}

    public function acquire(Project $project, AgentRole $role, string $workerInstanceId, string $status = 'working'): ?WorkerLease
    {
        return DB::transaction(function () use ($project, $role, $workerInstanceId, $status): ?WorkerLease {
            $worker = AgentWorker::query()->whereBelongsTo($project)->where('role', $role)->lockForUpdate()->first();
            if ($worker === null) {
                return null;
            }
            if ($worker->lease_id !== null && AgentWorker::query()->whereKey($worker)->where('lease_expires_at', '>', now())->exists()) {
                return null;
            }

            $previousLease = $worker->lease_id;
            $lease = new WorkerLease($worker->id, $workerInstanceId, (string) Str::uuid(), $previousLease);
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
            $this->audit->record($previousLease === null ? 'worker.lease_claimed' : 'worker.lease_taken_over', [
                'role' => $role->value,
                'worker_instance_id' => $lease->workerInstanceId,
                'lease_id' => $lease->leaseId,
                'previous_lease_id' => $previousLease,
                'lease_expires_at' => $leaseExpiresAt->toIso8601String(),
            ], $project);

            return $lease;
        }, attempts: 3);
    }

    public function renew(WorkerLease $lease, string $status = 'working'): bool
    {
        $updated = AgentWorker::query()
            ->whereKey($lease->workerId)
            ->where('worker_instance_id', $lease->workerInstanceId)
            ->where('lease_id', $lease->leaseId)
            ->where('lease_expires_at', '>=', now())
            ->update([
                'status' => $status,
                'last_heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
            ]);

        return $updated === 1;
    }

    public function takeoverExpired(Project $project, AgentRole $role, string $workerInstanceId, int $legacyStaleAfterSeconds): ?WorkerLease
    {
        return DB::transaction(function () use ($project, $role, $workerInstanceId, $legacyStaleAfterSeconds): ?WorkerLease {
            $worker = AgentWorker::query()->whereBelongsTo($project)->where('role', $role)->lockForUpdate()->first();
            if ($worker === null || ($worker->lease_id !== null && AgentWorker::query()->whereKey($worker)->where('lease_expires_at', '>', now())->exists())) {
                return null;
            }

            if ($worker->lease_id === null && ($worker->last_heartbeat_at === null || AgentWorker::query()->whereKey($worker)->where('last_heartbeat_at', '>=', now()->subSeconds($legacyStaleAfterSeconds))->exists())) {
                return null;
            }

            $previousLease = $worker->lease_id;
            $lease = new WorkerLease($worker->id, $workerInstanceId, (string) Str::uuid(), $previousLease);
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
                'role' => $role->value,
                'worker_instance_id' => $lease->workerInstanceId,
                'lease_id' => $lease->leaseId,
                'previous_lease_id' => $previousLease,
                'reason' => 'lease_expired',
            ], $project);

            return $lease;
        }, attempts: 3);
    }

    public function release(WorkerLease $lease, string $status = 'idle'): bool
    {
        $worker = AgentWorker::query()->whereKey($lease->workerId)->where('worker_instance_id', $lease->workerInstanceId)->where('lease_id', $lease->leaseId)->first();
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
            'role' => AgentRole::from($worker->getRawOriginal('role'))->value,
            'worker_instance_id' => $lease->workerInstanceId,
            'lease_id' => $lease->leaseId,
        ], $worker->project);

        return true;
    }

    public function beat(Project $project, AgentRole $role, string $status = 'working'): AgentWorker
    {
        $worker = AgentWorker::query()->whereBelongsTo($project)->where('role', $role)->firstOrFail();
        $worker->update(['status' => $status, 'last_heartbeat_at' => now(), 'started_at' => $worker->started_at ?? now()]);

        return $worker->refresh();
    }

    private function leaseSeconds(): int
    {
        return max(2, (int) config('aios.worker_lease_seconds'));
    }
}
