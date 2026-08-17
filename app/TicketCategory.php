<?php

namespace App;

enum TicketCategory: string
{
    case Bug = 'bug';
    case Enhancement = 'enhancement';
    case Feature = 'feature';
}
