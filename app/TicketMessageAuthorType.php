<?php

namespace App;

enum TicketMessageAuthorType: string
{
    case User = 'user';
    case Ai = 'ai';
    case System = 'system';
}
