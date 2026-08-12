<?php

namespace App;

enum ProjectStatus: string
{
    case Running = 'running';
    case Paused = 'paused';
    case Stopping = 'stopping';
}
