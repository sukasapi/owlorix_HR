<?php

return [

    // Times are stored in UTC and shown in this zone.
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Asia/Jakarta'),

    // Defaults for the `settings` table (docs/04-data-model.md). A row in the table overrides the value here.
    'settings' => [
        'attendance.regular_limit_minutes' => 480,
        'attendance.overtime_idle_check_minutes' => 60,
        'attendance.overtime_idle_answer_minutes' => 30,
        'attendance.idle_threshold_minutes' => 10,
        'attendance.prompt_repeat_minutes' => 10,
        'attendance.prompt_auto_close_minutes' => 30,
        'attendance.resume_window_minutes' => 90,
        'attendance.offline_sign_in_days' => 14,
        'attendance.clock_mismatch_seconds' => 120,
        'sync.heartbeat_local_seconds' => 60,
        'sync.heartbeat_upload_seconds' => 120,
        'overtime.late_claim_hours' => 24,
        // Clock in and out from the web app as well as the desktop app (owner decision 2026-09-14, docs/02 3.11)
        'attendance.web_clock_in' => true,
        'app.timezone' => 'Asia/Jakarta',
    ],

    'login' => [
        // Failures per username before the first wait, and the waits in minutes after each further block of failures.
        'failures_per_step' => 5,
        'wait_minutes' => [1, 5, 15],
        // All studio PCs share one public IP, so the per-IP limit is high.
        'ip_max_failures' => 100,
        'ip_decay_minutes' => 10,
    ],

    'password_min_length' => 12,

    // Superadmin can sign in as another person for support. Off in production unless set.
    'imposter' => [
        'enabled' => (bool) env('IMPOSTER_MODE', false),
    ],

];
