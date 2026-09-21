<?php

namespace App\Modules\Projects\Enums;

enum ProjectStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Done = 'done';
}
