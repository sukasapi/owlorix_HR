<?php

namespace App\Modules\Monitoring\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Settings\Settings;

/**
 * Whether Aktivitas detail records this person: the rule is on and their employment type is switched on in Aturan
 * (owner 2026-09-25: permanent and contract by default; freelance and intern not). A person without a type is not
 * recorded.
 */
class AppUsagePolicy
{
    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('monitoring.app_usage');
    }

    public function records(User $user): bool
    {
        $type = $user->employment_type?->value;

        return $this->enabled() && $type !== null && (bool) $this->settings->get("monitoring.app_usage_{$type}");
    }
}
