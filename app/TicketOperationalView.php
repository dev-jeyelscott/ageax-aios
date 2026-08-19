<?php

namespace App;

enum TicketOperationalView: string
{
    case NeedsOperatorDecision = 'needs_operator_decision';
    case AwaitingRequester = 'awaiting_requester';
    case RecentlyAutoConverted = 'recently_auto_converted';
    case BlockedOrFailedTriage = 'blocked_failed_triage';
    case RecentlyAutoClosed = 'recently_auto_closed';

    public function label(): string
    {
        return match ($this) {
            self::NeedsOperatorDecision => 'Needs operator decision',
            self::AwaitingRequester => 'Awaiting requester',
            self::RecentlyAutoConverted => 'Recently auto-converted',
            self::BlockedOrFailedTriage => 'Blocked / failed triage',
            self::RecentlyAutoClosed => 'Recently auto-closed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NeedsOperatorDecision => 'Escalated tickets with durable AIOS evidence that operator judgment is required.',
            self::AwaitingRequester => 'Requester-dependent tickets waiting for new public evidence.',
            self::RecentlyAutoConverted => 'Tickets converted to normal AIOS Tasks during the recent operational window.',
            self::BlockedOrFailedTriage => 'Unresolved blocked PM decisions or failed ticket triage attempts.',
            self::RecentlyAutoClosed => 'Requester-dependent tickets closed automatically for inactivity during the recent operational window.',
        };
    }
}
