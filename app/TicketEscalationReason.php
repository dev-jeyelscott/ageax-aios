<?php

namespace App;

enum TicketEscalationReason: string
{
    case LowConfidence = 'low_confidence';
    case UnclearOrContradictoryRequirements = 'unclear_or_contradictory_requirements';
    case ArchitecturalDecisionRequired = 'architectural_decision_required';
    case BreakingPublicApiOrDataContract = 'breaking_public_api_or_data_contract';
    case MaterialSchemaOrDataMigrationRisk = 'material_schema_or_data_migration_risk';
    case DestructiveOperation = 'destructive_operation';
    case SecurityPrivacyOrAuthJudgmentRequired = 'security_privacy_or_auth_judgment_required';
    case ApprovedDocumentationConflict = 'approved_documentation_conflict';
    case BusinessPriorityUnclear = 'business_priority_unclear';
    case HighComplexity = 'high_complexity';
    case MultipleTasksOrPhasesRequired = 'multiple_tasks_or_phases_required';
    case RoadmapOrPhaseReorderingOrInterruptionRequested = 'roadmap_or_phase_reordering_or_interruption_requested';
    case CriticalOrEmergencyPreemptionRequested = 'critical_or_emergency_preemption_requested';
    case UnsafeOrUnresolvedDependencyPlacement = 'unsafe_or_unresolved_dependency_placement';
}
