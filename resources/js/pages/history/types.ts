import type { DayVerdict } from '@/types';

export type OvertimeStatus = 'pending' | 'approved' | 'rejected';
export type IdleTag = 'rendering' | 'meeting' | 'break' | 'other';

export interface Decision {
    decision: 'approved' | 'rejected';
    note: string | null;
    decided_at: string | null;
    decided_by: string | null;
}

export interface HistoryShift {
    id: number;
    work_date: string;
    is_workday: boolean;
    is_live: boolean;
    status: 'open' | 'prompted' | 'overtime' | 'report_due' | 'closed' | 'interrupted' | 'needs_review';
    clock_in_at: string;
    clock_out_at: string | null;
    regular_ends_at: string | null;
    last_seen_at: string | null;
    regular_limit_minutes: number;
    regular_before_minutes: number;
    regular_minutes: number;
    overtime_minutes: number;
    idle_minutes: number;
    interruption_minutes: number;
    end_reason: 'manual' | 'auto_no_answer' | 'shutdown_timeout' | 'superadmin' | null;
    overtime_end_reason: 'clock_out' | 'presence_check_no_answer' | null;
    is_short: boolean;
    report_due: boolean;
    late_claim: { claimable_until: string; latest_end_at: string } | null;
    flags: string[];
    idle_periods: { started_at: string; ended_at: string | null; minutes: number; tag: IdleTag | null; note: string | null }[];
    interruptions: { started_at: string; ended_at: string | null }[];
    overtime: {
        started_at: string;
        ended_at: string | null;
        minutes: number;
        reason: string | null;
        work_report: string | null;
        report_due: boolean;
        is_late_claim: boolean;
        status: OvertimeStatus | null;
        decisions: Decision[];
    } | null;
    /** Where the shift ran: the clock-in device first, then each move to another device */
    devices: { at: string; device_id: string; name: string; is_web: boolean }[];
    /** A browser cannot see keyboard or mouse input: `none` when the whole shift ran on the web */
    idle_detection: 'full' | 'partial' | 'none';
}

export interface HistoryDay {
    date: string;
    /** ISO weekday, 1 = Monday ... 7 = Sunday */
    weekday: number;
    is_today: boolean;
    is_future: boolean;
    is_workday: boolean;
    calendar: DayVerdict;
    regular_minutes: number;
    overtime_minutes: number;
    idle_minutes: number;
    is_short: boolean;
    shifts: HistoryShift[];
}

export interface MonthTotals {
    days_worked: number;
    regular_minutes: number;
    overtime_minutes: number;
    overtime_approved_minutes: number;
    overtime_pending_minutes: number;
    overtime_rejected_minutes: number;
    idle_minutes: number;
    short_days: number;
    non_workdays_worked: number;
    has_live_shift: boolean;
    has_web_shifts: boolean;
}

/** Rule values quoted in the page copy, from the settings */
export interface HistoryRules {
    regular_limit_minutes: number;
    prompt_auto_close_minutes: number;
    overtime_idle_answer_minutes: number;
    resume_window_minutes: number;
    late_claim_hours: number;
}

export interface HistoryPageProps {
    month: { value: string; previous: string; next: string | null; current: string };
    today: string;
    totals: MonthTotals;
    days: HistoryDay[];
    /** Reports due and late claims can also be written on Hari ini */
    web_clock_in_enabled: boolean;
    rules: HistoryRules;
}
