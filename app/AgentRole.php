<?php

namespace App;

enum AgentRole: string
{
    case ProjectManager = 'project_manager';
    case Coder = 'coder';
    case Reviewer = 'reviewer';
    case KnowledgeArchitect = 'knowledge_architect';
}
