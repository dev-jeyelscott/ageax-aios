<?php

namespace App;

enum ProjectReconciliationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case SkippedNoChange = 'skipped_no_change';
    case Failed = 'failed';
}
