<?php

namespace App;

enum KnowledgeImprovementCandidateStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Dismissed = 'dismissed';
}
