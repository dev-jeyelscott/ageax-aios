<?php

namespace App;

enum AgentRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Interrupted = 'interrupted';
}
