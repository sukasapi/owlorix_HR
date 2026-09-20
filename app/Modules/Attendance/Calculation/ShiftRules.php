<?php

namespace App\Modules\Attendance\Calculation;

use App\Modules\Shared\Settings\Settings;

/** Rule values the calculator needs, read from settings once per calculation. */
final readonly class ShiftRules
{
    /** 3.1.2: undo window for a clock-in */
    public const CANCEL_WINDOW_SECONDS = 120;

    /** Device ids of web browsers start with this (3.11) */
    public const WEB_DEVICE_PREFIX = 'web:';

    public static function isWebDevice(string $deviceId): bool
    {
        return str_starts_with($deviceId, self::WEB_DEVICE_PREFIX);
    }

    public function __construct(
        public int $promptAutoCloseMinutes = 30,
        public int $overtimeIdleCheckMinutes = 60,
        public int $overtimeIdleAnswerMinutes = 30,
        public int $resumeWindowMinutes = 90,
        public int $clockMismatchSeconds = 120,
        public int $lateClaimHours = 24,
        public int $heartbeatUploadSeconds = 120,
        public int $heartbeatLocalSeconds = 60,
        public int $idleThresholdMinutes = 10,
    ) {}

    public static function fromSettings(Settings $settings): self
    {
        return new self(
            promptAutoCloseMinutes: $settings->int('attendance.prompt_auto_close_minutes'),
            overtimeIdleCheckMinutes: $settings->int('attendance.overtime_idle_check_minutes'),
            overtimeIdleAnswerMinutes: $settings->int('attendance.overtime_idle_answer_minutes'),
            resumeWindowMinutes: $settings->int('attendance.resume_window_minutes'),
            clockMismatchSeconds: $settings->int('attendance.clock_mismatch_seconds'),
            lateClaimHours: $settings->int('overtime.late_claim_hours'),
            heartbeatUploadSeconds: $settings->int('sync.heartbeat_upload_seconds'),
            heartbeatLocalSeconds: $settings->int('sync.heartbeat_local_seconds'),
            idleThresholdMinutes: $settings->int('attendance.idle_threshold_minutes'),
        );
    }

    /**
     * The one definition of "not heard from": a silence longer than two heartbeats counts as a gap (3.7.2).
     * The PC's own local heartbeat (every 60 s) is used when it reported one; otherwise the server only knows uploaded
     * heartbeats (every 120 s).
     */
    public function gapThresholdMs(bool $localHeartbeat = false): int
    {
        return 2 * ($localHeartbeat ? $this->heartbeatLocalSeconds : $this->heartbeatUploadSeconds) * 1000;
    }
}
