<?php

namespace App\Contracts\Context;

/**
 * Classifies whether one context source is non-overridable evidence or eligible for
 * deterministic Context Budget reduction. Mirrors the required/reducible split already
 * enforced by ContextBudgetGuard without introducing a competing policy.
 */
enum ContextAuthority: string
{
    case Required = 'required';
    case Reducible = 'reducible';
}
