<?php

namespace App;

enum AgentHandoffStatus: string
{
    case Pending = 'pending';
    case Consumed = 'consumed';
}
