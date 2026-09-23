<?php

namespace App\Modules\Projects\Enums;

enum MilestoneKind: string
{
    case Internal = 'internal';
    case ClientReview = 'client_review';
    case Delivery = 'delivery';
}
