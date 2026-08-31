<?php

namespace App\Exceptions;

/**
 * Thrown when a Task's persisted immutable workflow-version binding does not declare the steps
 * or transition required to execute a requested TaskStatus transition. This is the deterministic
 * fail-closed signal used when bound workflow execution encounters unsupported or inconsistent
 * workflow state; it never silently falls back to the built-in lifecycle once a Task is bound.
 */
class InvalidWorkflowExecutionState extends InvalidTaskTransition
{
    //
}
