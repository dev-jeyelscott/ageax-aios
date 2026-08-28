<?php

namespace App;

enum OrchestrationRecommendationStatus: string
{
    case Active = 'active';
    case Dismissed = 'dismissed';
    case Superseded = 'superseded';
}
