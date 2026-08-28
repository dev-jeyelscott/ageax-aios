<?php

namespace App;

enum ParallelTaskSafety: string
{
    case Safe = 'safe';
    case Unsafe = 'unsafe';
    case Unknown = 'unknown';

    /**
     * Determine whether this decision explicitly permits concurrent execution.
     */
    public function allowsConcurrency(): bool
    {
        return $this === self::Safe;
    }
}
