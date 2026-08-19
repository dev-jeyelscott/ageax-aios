<?php

namespace App;

enum TicketOperatorAction: string
{
    case ApproveProposedHandling = 'approve_proposed_handling';
    case Reject = 'reject';
    case RequestRequesterInformation = 'request_requester_information';
    case ProvideDirection = 'provide_direction';
}
