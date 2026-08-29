<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown whenever the Context Gateway cannot resolve exactly one registered, enabled, approved
 * logical Agent identity in the resolved Project scope. Covers unknown, disabled, cross-project,
 * and unsupported Agent identities; every case must fail closed instead of guessing an Agent.
 */
class AgentResolutionFailed extends Exception
{
    //
}
