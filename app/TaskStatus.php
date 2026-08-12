<?php

namespace App;

enum TaskStatus: string
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
}
