<?php

namespace App;

enum OrchestrationRecommendationType: string
{
    case AgentConfiguration = 'agent_configuration';
    case HarnessModel = 'harness_model';
    case ReasoningLevel = 'reasoning_level';
    case RetryStrategy = 'retry_strategy';
    case ContextStrategy = 'context_strategy';
    case TaskDecomposition = 'task_decomposition';
    case RecoveryDirection = 'recovery_direction';
    case WorkflowImprovement = 'workflow_improvement';
}
