<?php

namespace App\Modules\Shared\Http\Controllers\Admin;

/**
 * The rule settings Superadmin can edit on Aturan, grouped as on the page, with the limits the server enforces.
 * Every key of config('owlorix.settings') is listed here; `app.timezone` is shown read-only.
 * `rule` names the section of docs/02-attendance-rules.md the value comes from; empty for values that are not attendance
 * rules (the access log retention of docs/14 2.3).
 */
final class SettingsFields
{
    public const READ_ONLY = ['app.timezone'];

    /**
     * @return list<array{group: string, key: string, type: 'integer'|'boolean', unit: ?string, min: ?int, max: ?int, rule: string, applies: 'new_shifts'|'calculation'|'desktop'|'web'|'daily'|'next_request'}>
     */
    public static function all(): array
    {
        return [
            self::int('work_hours', 'attendance.regular_limit_minutes', 'minutes', 60, 720, '3.3.1', 'new_shifts'),
            self::int('work_hours', 'attendance.prompt_repeat_minutes', 'minutes', 1, 60, '3.3.5', 'desktop'),
            self::int('work_hours', 'attendance.prompt_auto_close_minutes', 'minutes', 5, 180, '3.3.5', 'calculation'),

            self::int('overtime', 'attendance.overtime_idle_check_minutes', 'minutes', 15, 240, '3.5.1', 'calculation'),
            self::int('overtime', 'attendance.overtime_idle_answer_minutes', 'minutes', 5, 120, '3.5.3', 'calculation'),
            self::int('overtime', 'overtime.late_claim_hours', 'hours', 1, 168, '3.3.6', 'calculation'),

            self::int('idle', 'attendance.idle_threshold_minutes', 'minutes', 1, 60, '3.6.2', 'calculation'),

            self::int('desktop_sync', 'sync.heartbeat_local_seconds', 'seconds', 30, 300, '3.7.2', 'calculation'),
            self::int('desktop_sync', 'sync.heartbeat_upload_seconds', 'seconds', 60, 600, '3.7.2', 'calculation'),
            self::int('desktop_sync', 'attendance.resume_window_minutes', 'minutes', 10, 480, '3.7.3', 'calculation'),
            self::int('desktop_sync', 'attendance.offline_sign_in_days', 'days', 1, 60, '3.8.5', 'desktop'),
            self::int('desktop_sync', 'attendance.clock_mismatch_seconds', 'seconds', 30, 900, '3.9.4', 'calculation'),

            ['group' => 'web', 'key' => 'attendance.web_clock_in', 'type' => 'boolean', 'unit' => null, 'min' => null, 'max' => null, 'rule' => '3.11.8', 'applies' => 'web'],

            self::int('work_target', 'target.weekly_hours', 'hours', 1, 60, '3.12.2', 'web'),
            self::int('work_target', 'target.intern_days_per_week', 'days', 1, 7, '3.12.3', 'web'),
            self::int('work_target', 'target.intern_minutes_per_day', 'minutes', 30, 720, '3.12.3', 'web'),

            self::int('leave', 'leave.annual_quota_days', 'days', 0, 40, '', 'next_request'),

            self::int('monitoring', 'monitoring.access_log_days', 'days', 30, 730, '', 'daily'),
            ['group' => 'monitoring', 'key' => 'monitoring.app_usage', 'type' => 'boolean', 'unit' => null, 'min' => null, 'max' => null, 'rule' => '', 'applies' => 'desktop'],
            ['group' => 'monitoring', 'key' => 'monitoring.app_usage_permanent', 'type' => 'boolean', 'unit' => null, 'min' => null, 'max' => null, 'rule' => '', 'applies' => 'desktop'],
            ['group' => 'monitoring', 'key' => 'monitoring.app_usage_contract', 'type' => 'boolean', 'unit' => null, 'min' => null, 'max' => null, 'rule' => '', 'applies' => 'desktop'],
            ['group' => 'monitoring', 'key' => 'monitoring.app_usage_freelance', 'type' => 'boolean', 'unit' => null, 'min' => null, 'max' => null, 'rule' => '', 'applies' => 'desktop'],
            ['group' => 'monitoring', 'key' => 'monitoring.app_usage_intern', 'type' => 'boolean', 'unit' => null, 'min' => null, 'max' => null, 'rule' => '', 'applies' => 'desktop'],
            self::int('monitoring', 'monitoring.app_usage_active_days', 'days', 30, 365, '', 'daily'),
        ];
    }

    /** @return array{group: string, key: string, type: 'integer', unit: string, min: int, max: int, rule: string, applies: 'new_shifts'|'calculation'|'desktop'|'web'|'daily'} */
    private static function int(string $group, string $key, string $unit, int $min, int $max, string $rule, string $applies): array
    {
        return ['group' => $group, 'key' => $key, 'type' => 'integer', 'unit' => $unit, 'min' => $min, 'max' => $max, 'rule' => $rule, 'applies' => $applies];
    }
}
