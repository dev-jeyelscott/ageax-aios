<?php

namespace App;

enum RecoveryIncidentStatus: string
{
    case Detected = 'detected';
    case Diagnosing = 'diagnosing';
    case Repairing = 'repairing';
    case Validating = 'validating';
    case Recovered = 'recovered';
    case Escalated = 'escalated';
    case Failed = 'failed';

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Detected, self::Diagnosing, self::Repairing, self::Validating];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }
}
