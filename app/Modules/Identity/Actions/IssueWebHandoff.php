<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\WebHandoff;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues a one-time URL that signs this desktop user into the web app (docs/11-web-handoff.md).
 * The plain token is returned once and never stored; only its SHA-256 hash is kept.
 */
class IssueWebHandoff
{
    public const TTL_SECONDS = 60;

    public function __construct(private readonly Auditor $auditor) {}

    /** @return array{url: string, expires_at: CarbonImmutable} */
    public function handle(User $user, Device $device): array
    {
        $plain = Str::random(48);
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TTL_SECONDS);

        DB::transaction(function () use ($user, $device, $plain, $expiresAt) {
            // Drop unused links from this PC so an old tab cannot sign someone in later.
            WebHandoff::query()
                ->where('user_id', $user->id)
                ->where('device_id', $device->id)
                ->whereNull('used_at')
                ->delete();

            WebHandoff::query()->create([
                'token_hash' => hash('sha256', $plain),
                'user_id' => $user->id,
                'device_id' => $device->id,
                'expires_at' => $expiresAt,
            ]);

            $this->auditor->record('auth.web_handoff_issued', $user, null, [
                'device_id' => $device->id,
                'expires_at' => $expiresAt->toIso8601String(),
            ], $user->id);
        });

        return [
            'url' => route('sign-in.desktop-handoff', ['token' => $plain], absolute: true),
            'expires_at' => $expiresAt,
        ];
    }
}
