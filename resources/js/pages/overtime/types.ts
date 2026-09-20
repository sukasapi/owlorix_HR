import type { Decision, OvertimeStatus } from '../history/types';

export type { Decision, OvertimeStatus };

export interface OvertimeItem {
    id: number;
    shift_id: number;
    work_date: string;
    started_at: string;
    ended_at: string | null;
    minutes: number;
    reason: string | null;
    work_report: string | null;
    is_running: boolean;
    report_due: boolean;
    is_late_claim: boolean;
    status: OvertimeStatus;
    /** Decided before, back to pending because the minutes changed afterwards */
    was_reset: boolean;
    /** Oldest first; the last one is current */
    decisions: Decision[];
}

export interface LateClaim {
    shift_id: number;
    work_date: string;
    clock_in_at: string;
    auto_ended_at: string;
    cause: 'prompt' | 'presence_check';
    claimable_until: string;
    latest_end_at: string;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface Filters {
    status: OvertimeStatus | 'all';
    bulan: string | null;
}

export interface OvertimePageProps {
    filters: Filters;
    months: string[];
    has_requests: boolean;
    requests: Paginated<OvertimeItem>;
    late_claims: LateClaim[];
    /** Reports due and late claims can also be written on Hari ini */
    web_clock_in_enabled: boolean;
    /** Rule values quoted in the page copy, from the settings */
    rules: { regular_limit_minutes: number; prompt_auto_close_minutes: number; overtime_idle_answer_minutes: number; late_claim_hours: number };
}
