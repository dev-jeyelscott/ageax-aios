<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown when a workflow role has no Agent bound at all. Callers treat this as the
 * legacy, backward-compatible execution path rather than a blocking failure: a project
 * that has never configured an Agent for the role keeps running its existing harness.
 */
class AgentNotBoundToRole extends LogicException
{
    //
}
