export interface Totals {
    people: number;
    days_worked: number;
    regular_minutes: number;
    overtime_approved_minutes: number;
    overtime_pending_minutes: number;
    overtime_rejected_minutes: number;
    idle_minutes: number;
    short_days: number;
    non_workday_shifts: number;
    review_shifts: number;
    late_claims: number;
    pending_shifts: number;
    running_shifts: number;
}

export interface PersonRow extends Totals {
    id: number;
    name: string;
    username: string;
    employee_code: string | null;
    initials: string;
    status: 'active' | 'suspended' | 'left';
    teams: string[];
}

export interface ReportGroup {
    team: { id: number; name: string } | null;
    people: PersonRow[];
    subtotal: Totals;
}

export type ShiftNote = 'running' | 'needs_review' | 'clock_mismatch' | 'gap_unverified' | 'late_claim' | 'offline_sign_in' | 'short' | 'report_due';

export type OvertimeStatus = 'approved' | 'pending' | 'rejected';

export interface ShiftRow {
    id: number;
    work_date: string;
    is_workday: boolean;
    clock_in_at: string;
    clock_out_at: string | null;
    regular_minutes: number;
    overtime_minutes: number;
    overtime_status: OvertimeStatus | null;
    idle_minutes: number;
    interruption_minutes: number;
    notes: ShiftNote[];
}

export interface ReportMonth {
    value: string;
    previous: string;
    next: string | null;
    current: string;
    is_current: boolean;
    is_future: boolean;
}

export interface ReportsPageProps {
    month: ReportMonth;
    filters: { team: number | null; person: number | null };
    teams: { id: number; name: string }[];
    scope: 'everyone' | 'led_teams';
    report: {
        groups: ReportGroup[];
        total: Totals;
        is_studio: boolean;
        shared_people: boolean;
    } | null;
    detail: { person: PersonRow; shifts: ShiftRow[] } | null;
    generated_at: string;
    can: { export: boolean };
    links: { approvals: string | null };
}
