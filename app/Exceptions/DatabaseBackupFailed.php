<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a disaster-recovery snapshot cannot be created or verified. Backup subsystem
 * failures must fail closed (see MASTER-PROMPT.md's database protection contract): an unsupported
 * driver, an in-memory SQLite connection, or a failed integrity check must never silently continue
 * as if a recovery point existed.
 */
class DatabaseBackupFailed extends RuntimeException
{
    //
}
