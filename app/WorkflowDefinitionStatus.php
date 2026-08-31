<?php

namespace App;

/**
 * The explicit lifecycle state of an immutable workflow definition version.
 */
enum WorkflowDefinitionStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Archived = 'archived';

    /**
     * Return the allowlisted lifecycle transitions for this status.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Approved],
            self::Approved => [self::Archived],
            self::Archived => [],
        };
    }
}
