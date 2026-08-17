<?php

namespace App;

enum TicketUrgency: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';
    case Emergency = 'emergency';
}
