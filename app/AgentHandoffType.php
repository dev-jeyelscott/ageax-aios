<?php

namespace App;

enum AgentHandoffType: string
{
    case ImplementationHandoff = 'implementation_handoff';
    case ReviewRequest = 'review_request';
    case ReviewFinding = 'review_finding';
    case ContextRequest = 'context_request';
    case RecoveryAdvice = 'recovery_advice';
    case KnowledgeReference = 'knowledge_reference';
}
