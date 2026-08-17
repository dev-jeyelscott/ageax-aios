<?php

namespace App;

enum TicketDecision: string
{
    case Approved = 'approved';
    case NeedsInformation = 'needs_information';
    case Blocked = 'blocked';
    case SelfService = 'self_service';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
}
