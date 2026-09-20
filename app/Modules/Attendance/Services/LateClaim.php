<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\EndReason;
use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Models\AttendanceEvent;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\Time;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Late overtime claim for a shift the rules ended automatically: no answer to the 8-hour prompt (3.3.6) or to the
 * presence check (3.5.5, 3.11). Stored as a server-written `overtime_claim` event, so the shift is still rebuilt
 * from events. Used by the desktop API and the web page.
 */
class LateClaim
{
    public function __construct(
        private readonly ShiftStateResolver $resolver,
        private readonly ShiftRecalculator $recalculator,
        private readonly Settings $settings,
    ) {}

    /** @throws LateClaimRefused */
    public function file(User $user, Device $device, int $shiftId, string $reason, string $workReport, CarbonImmutable $endedAt, ?CarbonImmutable $now = null): ResolvedShift
    {
        $now ??= CarbonImmutable::now();
        $model = Shift::query()->where('user_id', $user->id)->find($shiftId);
        $resolved = $model !== null ? $this->resolver->shift($model, $now) : null;

        if ($resolved === null) {
            throw new LateClaimRefused('shift_not_found', 'Shift tidak ditemukan.', 404);
        }

        $result = $resolved->result;

        if ($result->endReason !== EndReason::AutoNoAnswer || $result->clockOutAt === null) {
            throw new LateClaimRefused('not_claimable', 'Klaim susulan hanya untuk shift yang ditutup otomatis.');
        }

        if ($result->overtime?->isLateClaim) {
            throw new LateClaimRefused('already_claimed', 'Shift ini sudah punya klaim susulan.');
        }

        if ($now->greaterThan($result->clockOutAt->addHours($this->settings->int('overtime.late_claim_hours')))) {
            throw new LateClaimRefused('claim_window_closed', 'Batas waktu klaim susulan sudah lewat.');
        }

        if ($endedAt->lessThanOrEqualTo($result->clockOutAt)) {
            throw new LateClaimRefused('before_auto_end', 'Jam selesai harus setelah shift ditutup otomatis.');
        }

        // The claimed end cannot be later than the last activity recorded for the shift, the next clock-in, or now
        $latest = $result->latestClaimEndAt;

        if ($latest === null || $endedAt->greaterThan($latest)) {
            $shown = $latest?->setTimezone(Time::zone())->format('d-m-Y H.i');

            throw new LateClaimRefused(
                'after_last_activity',
                $shown !== null
                    ? "Jam selesai paling lambat {$shown}, aktivitas terakhir yang tercatat."
                    : 'Tidak ada aktivitas tercatat setelah shift ditutup otomatis.',
                latestEndAt: $latest,
            );
        }

        DB::transaction(function () use ($user, $device, $model, $now, $endedAt, $reason, $workReport) {
            AttendanceEvent::query()->create([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'device_id' => $device->id,
                'shift_id' => $model->id,
                'type' => EventType::OvertimeClaim,
                'occurred_at' => $now,
                'occurred_at_device' => $now,
                'boot_id' => 'server',
                'uptime_ms' => 0,
                'server_offset_ms' => 0,
                'offline' => false,
                'payload' => [
                    'reason' => trim($reason),
                    'work_report' => trim($workReport),
                    'ended_at' => Time::iso($endedAt),
                ],
                'received_at' => $now,
            ]);

            $this->recalculator->recalculateWorkDate($user->id, $model->work_date, $now);
        });

        return $this->resolver->shift($model->refresh(), $now);
    }
}
