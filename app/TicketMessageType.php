<?php

namespace App;

enum TicketMessageType: string
{
    case PublicReply = 'public_reply';
    case InternalNote = 'internal_note';
    case SystemEvent = 'system_event';
}
