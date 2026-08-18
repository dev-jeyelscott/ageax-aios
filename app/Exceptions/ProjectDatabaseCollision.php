<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a managed project's own database configuration resolves to the same physical
 * database as AIOS's own primary connection. AIOS's execution-security contract protects the AIOS
 * repository's filesystem path (WorkspacePathResolver) and process environment
 * (SanitizedExecutionEnvironment), but a managed project's own `.env` is read by that project's own
 * process, entirely outside AIOS's control — so a misconfigured project pointing at AIOS's database
 * name/host is a distinct, undetectable-by-path-or-env-scrubbing failure mode. This exception is
 * AIOS's proactive check for that specific misconfiguration.
 */
class ProjectDatabaseCollision extends Exception
{
    //
}
