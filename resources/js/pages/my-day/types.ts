import type { DayVerdict, WeekTargetData } from '@/types';

export type LiveStatus = 'open' | 'prompted' | 'overtime' | 'interrupted';
export type ShiftStatus = LiveStatus | 'report_due' | 'closed' | 'needs_review';

export interface IdlePeriod {
    shift_id: number;
    started_at: string;
    ended_at: string | null;
    minutes: number;
    tag: 'rendering' | 'meeting' | 'break' | 'other' | null;
    note: string | null;
}

export interface Shift {
    id: number;
    status: ShiftStatus;
    device_id: string;
    clock_in_at: string;
    clock_out_at: string | null;
    last_seen_at: string | null;
    regular_ends_at: string | null;
    regular_minutes: number;
    overtime_minutes: number;
    idle_minutes: number;
    interruption_minutes: number;
    is_short: boolean;
    report_due: boolean;
    flags: string[];
    overtime: { minutes: number; status: 'pending' | 'approved' | 'rejected' | null; report_due: boolean } | null;
}

export interface OpenShift {
    id: number;
    status: LiveStatus;
    device_id: string;
    device_hostname: string | null;
    is_web: boolean;
    on_this_browser: boolean;
    clock_in_at: string;
    regular_ends_at: string | null;
    prompt_deadline_at: string | null;
    last_seen_at: string;
    resume_until: string | null;
    overtime: { started_at: string; minutes: number; reason: string | null } | null;
}

export interface ReportDue {
    shift_id: number;
    work_date: string;
    status: ShiftStatus;
    overtime_started_at: string | null;
    overtime_ended_at: string | null;
    overtime_minutes: number;
    overtime_reason: string | null;
}

export interface LateClaim {
    shift_id: number;
    work_date: string;
    end_reason: string | null;
    overtime_end_reason: 'clock_out' | 'presence_check_no_answer' | null;
    auto_ended_at: string;
    claimable_until: string;
    latest_end_at: string;
}

export interface PresenceCheck {
    next_check_at: string;
    check_shown_at: string | null;
    answer_deadline_at: string | null;
}

export interface Summary {
    date: string;
    is_workday: boolean;
    status: LiveStatus | 'signed_out';
    regular_limit_minutes: number;
    regular_minutes: number;
    overtime_minutes: number;
    idle_minutes: number;
    regular_ends_at: string | null;
    idle_periods: IdlePeriod[];
    shifts: Shift[];
    web_clock_in_enabled: boolean;
    this_browser_device_id: string | null;
    open_shift: OpenShift | null;
    undo_until: string | null;
    prompt_deadline_at: string | null;
    presence_check: PresenceCheck | null;
    reports_due: ReportDue[];
    late_claims: LateClaim[];
    rules: {
        undo_seconds: number;
        prompt_repeat_minutes: number;
        presence_answer_minutes: number;
        presence_check_minutes: number;
        resume_window_minutes: number;
        interrupted_after_minutes: number;
        heartbeat_seconds: number;
        reason_min_length: number;
    };
}

export interface MyDayProps {
    summary: Summary;
    day: DayVerdict;
    /** Null for an employment type without a weekly target (freelance) */
    week: WeekTargetData | null;
}

/** Refusals from the web clock come back as a stable code; everything else is a ready message. */
export function errorText(t: (key: string, replacements?: Record<string, string | number>) => string, value: string | undefined, replacements: Record<string, string | number> = {}): string | undefined {
    if (!value) return undefined;
    return /^[a-z_]+$/.test(value) ? t(`my-day.errors.${value}`, replacements) : value;
}
