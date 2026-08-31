<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowDefinition;
use App\WorkflowDefinitionStatus;

class WorkflowDefinitionPolicy
{
    /**
     * Determine whether the user may create a new workflow definition key or version.
     */
    public function create(User $user): bool
    {
        return $user->email_verified_at !== null;
    }

    /**
     * Determine whether the user may approve a draft workflow definition version.
     */
    public function approve(User $user, WorkflowDefinition $workflowDefinition): bool
    {
        return $user->email_verified_at !== null
            && $workflowDefinition->status === WorkflowDefinitionStatus::Draft;
    }

    /**
     * Determine whether the user may archive an approved workflow definition version.
     */
    public function archive(User $user, WorkflowDefinition $workflowDefinition): bool
    {
        return $user->email_verified_at !== null
            && $workflowDefinition->status === WorkflowDefinitionStatus::Approved;
    }
}
