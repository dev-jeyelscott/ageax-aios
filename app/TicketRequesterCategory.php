<?php

namespace App;

enum TicketRequesterCategory: string
{
    case Bug = 'bug';
    case Enhancement = 'enhancement';
    case Feature = 'feature';
    case NotSure = 'not_sure';
}
