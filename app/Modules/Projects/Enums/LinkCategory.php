<?php

namespace App\Modules\Projects\Enums;

/** The fixed list of document links a project can have, in the studio's production order, plus a free one (docs/16). */
enum LinkCategory: string
{
    case Scenario = 'scenario';
    case Storyboard = 'storyboard';
    case CharacterAssets = 'character_assets';
    case Sound = 'sound';
    case VoiceOver = 'voice_over';
    case Animation = 'animation';
    case Final = 'final';
    case Tracker = 'tracker';
    case Other = 'other';

    public function label(): string
    {
        return __('projects::messages.link_categories.'.$this->value);
    }
}
