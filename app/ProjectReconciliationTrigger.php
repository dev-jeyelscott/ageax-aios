<?php

namespace App;

enum ProjectReconciliationTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
