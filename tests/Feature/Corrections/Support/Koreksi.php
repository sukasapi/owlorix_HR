<?php

namespace Tests\Feature\Corrections\Support;

use App\Modules\Attendance\Models\Correction;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Attendance\Support\Desk;
use Tests\TestCase;

/** The Koreksi page as a person uses it: the form, the preview, and the Superadmin's decisions. Times are Asia/Jakarta. */
final class Koreksi
{
    public const REASON = 'Lupa absen pulang, sudah dicek dengan lead';

    /** One finished shift recorded by the desktop app. */
    public static function workedShift(TestCase $test, User $person, string $in = '2026-09-14 09:00', string $out = '15:00', array $outPayload = []): Shift
    {
        $desk = Desk::for($test, $person);
        $desk->send('clock_in', at: $in);
        $desk->send('clock_out', $outPayload, $out);

        return $desk->shift();
    }

    /** @return array<string, mixed> */
    public static function form(Shift $shift, string $field, string $at, ?string $reason = self::REASON): array
    {
        [$date, $time] = explode(' ', $at);

        return ['shift_id' => $shift->id, 'field' => $field, 'date' => $date, 'time' => $time, 'reason' => $reason];
    }

    public static function submit(TestCase $test, User $actor, Shift $shift, string $field, string $at, ?string $reason = self::REASON): TestResponse
    {
        return $test->actingAs($actor)
            ->from(route('corrections.index'))
            ->post(route('corrections.store'), self::form($shift, $field, $at, $reason));
    }

    public static function preview(TestCase $test, User $actor, Shift $shift, string $field, string $at): TestResponse
    {
        return $test->actingAs($actor)->postJson(route('corrections.preview'), self::form($shift, $field, $at, null));
    }

    public static function apply(TestCase $test, User $actor, Correction $correction, ?string $seen = null): TestResponse
    {
        return $test->actingAs($actor)
            ->from(route('corrections.index'))
            ->post(route('corrections.apply', $correction), ['seen_value' => $seen ?? $correction->refresh()->old_value]);
    }

    public static function decline(TestCase $test, User $actor, Correction $correction, ?string $note): TestResponse
    {
        return $test->actingAs($actor)
            ->from(route('corrections.index'))
            ->post(route('corrections.decline', $correction), ['note' => $note]);
    }

    /** A time as the server stores it: UTC ISO with milliseconds. */
    public static function iso(string $at): string
    {
        return Desk::time($at)->format('Y-m-d\TH:i:s.v\Z');
    }
}
