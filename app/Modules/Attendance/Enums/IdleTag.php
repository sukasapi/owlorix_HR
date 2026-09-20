<?php

namespace App\Modules\Attendance\Enums;

/** Render, Rapat, Istirahat, Lainnya (3.6.3) */
enum IdleTag: string
{
    case Rendering = 'rendering';
    case Meeting = 'meeting';
    case Break = 'break';
    case Other = 'other';
}
