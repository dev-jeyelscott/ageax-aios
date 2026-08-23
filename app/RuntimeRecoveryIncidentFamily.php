<?php

namespace App;

enum RuntimeRecoveryIncidentFamily: string
{
    case ApplicationException = 'application_exception';
    case ScheduledCommandFailure = 'scheduled_command_failure';
    case SystemWorkerFailure = 'system_worker_failure';
}
