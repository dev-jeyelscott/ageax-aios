<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by DatabaseProtectionGuard when a protected execution attempt (Project Manager, Coder,
 * Reviewer, Recovery Engineer, or future Ticket triage) may not proceed: an active restore lock,
 * an unsafe path, or the absence of a verified recovery point that could not be freshly created.
 * Callers must not launch Codex or Claude Code when this is thrown; each role's existing
 * operational-failure handling (bounded retry, then block) applies exactly as it does for any
 * other pre-execution failure.
 */
class DatabaseProtectionFailed extends RuntimeException
{
    //
}
