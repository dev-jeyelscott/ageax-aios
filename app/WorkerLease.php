<?php

namespace App;

final readonly class WorkerLease
{
    public function __construct(public int $workerId, public string $workerInstanceId, public string $leaseId, public ?string $previousLeaseId = null) {}
}
