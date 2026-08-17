<?php

namespace App;

enum TicketStatus: string
{
    case Open = 'open';
    case Triaging = 'triaging';
    case AwaitingRequester = 'awaiting_requester';
    case Escalated = 'escalated';
    case Converted = 'converted';
    case Closed = 'closed';
    case Failed = 'failed';
}
