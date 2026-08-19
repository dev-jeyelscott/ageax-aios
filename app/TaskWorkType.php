<?php

namespace App;

enum TaskWorkType: string
{
    case Bug = 'bug';
    case Enhancement = 'enhancement';
    case Feature = 'feature';
    case Other = 'other';
}
