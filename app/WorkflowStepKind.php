<?php

namespace App;

/**
 * The application-owned allowlist of declarative workflow step kinds.
 *
 * Every case maps deterministically to the compatibility TaskStatus it represents.
 * No case may carry executable PHP, shell commands, webhooks, plugins, or arbitrary
 * payload execution; step behavior is limited to this fixed vocabulary.
 */
enum WorkflowStepKind: string
{
    case Queued = 'queued';
    case Coding = 'coding';
    case Validating = 'validating';
    case ReadyForReview = 'ready_for_review';
    case Reviewing = 'reviewing';
    case ChangesRequired = 'changes_required';
    case Done = 'done';
    case Blocked = 'blocked';
    case Interrupted = 'interrupted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Return the deterministic TaskStatus compatibility mapping for this step kind.
     */
    public function toTaskStatus(): TaskStatus
    {
        return TaskStatus::from($this->value);
    }
}
