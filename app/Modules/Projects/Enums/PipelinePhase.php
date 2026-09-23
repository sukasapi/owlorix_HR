<?php

namespace App\Modules\Projects\Enums;

/** The three phases of an animation production; pipeline stages belong to one (docs/14 3.1). */
enum PipelinePhase: string
{
    case PreProduction = 'pre_production';
    case Production = 'production';
    case PostProduction = 'post_production';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
