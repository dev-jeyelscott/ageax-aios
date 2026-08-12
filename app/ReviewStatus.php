<?php

namespace App;

enum ReviewStatus: string
{
    case InProgress = 'in_progress';
    case Approved = 'approved';
    case ChangesRequired = 'changes_required';
}
