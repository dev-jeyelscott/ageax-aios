<?php

namespace App\Services;

use App\Models\Task;
use App\TaskComplexity;

final class ExecutionBudgetPolicy
{
    public const int SchemaVersion = 1;

    /**
     * @return array{schema_version: int, method: string, max_execution_seconds: int}
     */
    public function forCoderTask(Task $task): array
    {
        $globalMaximum = max(60, (int) config('aios.execution_timeout'));
        $configuredLowComplexityMaximum = max(60, (int) config('aios.coder_low_complexity_execution_timeout'));

        return [
            'schema_version' => self::SchemaVersion,
            'method' => $task->complexity === TaskComplexity::Low ? 'low_complexity_timeout_v1' : 'global_timeout_v1',
            'max_execution_seconds' => $task->complexity === TaskComplexity::Low
                ? min($globalMaximum, $configuredLowComplexityMaximum)
                : $globalMaximum,
        ];
    }
}
