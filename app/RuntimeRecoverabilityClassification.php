<?php

namespace App;

enum RuntimeRecoverabilityClassification: string
{
    case KnownDeterministicRepair = 'known_deterministic_repair';
    case CandidateAiRepair = 'candidate_ai_repair';
    case OperatorOnly = 'operator_only';
    case NonActionable = 'non_actionable';
}
