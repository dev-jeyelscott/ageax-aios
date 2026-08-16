<?php

namespace App;

enum AgentRole: string
{
    case ProjectManager = 'project_manager';
    case Coder = 'coder';
    case Reviewer = 'reviewer';
    case KnowledgeArchitect = 'knowledge_architect';

    /**
     * The AIOS system/reliability role for the Workflow Recovery Engineer. This is deliberately
     * not one of the project workflow roles: it is never bound to a project Agent (a project-scoped
     * Agent's role is restricted to ProjectManager/Coder/Reviewer) and never occupies an
     * AgentWorker role slot. It is instead configured as a global Agent (Agent::project_id null).
     */
    case RecoveryEngineer = 'recovery_engineer';
}
