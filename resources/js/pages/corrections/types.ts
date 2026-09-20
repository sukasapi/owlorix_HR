export type CorrectionField = 'clock_in_at' | 'clock_out_at' | 'overtime_started_at' | 'overtime_ended_at';
export type CorrectionStatus = 'proposed' | 'applied' | 'declined';
export type HistoryFilter = 'all' | 'applied' | 'declined';
export type Tab = 'waiting' | 'history';

export const FIELDS: CorrectionField[] = ['clock_in_at', 'clock_out_at', 'overtime_started_at', 'overtime_ended_at'];

export interface CorrectionItem {
    id: number;
    status: CorrectionStatus;
    field: CorrectionField;
    shift_id: number;
    work_date: string | null;
    closed_month: boolean;
    person: {
        id: number | null;
        name: string | null;
        initials: string | null;
        status: 'active' | 'suspended' | 'left' | null;
    };
    old_value: string | null;
    new_value: string;
    /** What the shift shows now; only sent to people who decide. */
    current_value: string | null;
    changed_since: boolean;
    reason: string;
    proposed_by: string | null;
    proposed_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    is_direct: boolean;
    is_mine: boolean;
    can_decide: boolean;
}

export interface PersonOption {
    id: number;
    name: string;
    initials: string;
    status: 'active' | 'suspended' | 'left';
    teams: string[];
}

export interface CorrectionsPageProps {
    abilities: { apply: boolean; propose: boolean };
    filters: { status: HistoryFilter; person: number | null };
    waiting: CorrectionItem[];
    history: CorrectionItem[];
    history_limit: number;
    people: PersonOption[];
    today: string;
}

export interface ShiftOption {
    id: number;
    status: string;
    is_live: boolean;
    is_workday: boolean;
    regular_minutes: number;
    overtime_minutes: number;
    overtime_status: 'pending' | 'approved' | 'rejected' | null;
    values: Record<CorrectionField, string | null>;
    unavailable: Partial<Record<CorrectionField, 'running' | 'non_workday' | 'no_overtime'>>;
}

export interface ShiftsResponse {
    person_id: number;
    work_date: string;
    is_workday: boolean | null;
    closed_month: boolean;
    shifts: ShiftOption[];
}

interface ShiftSummary {
    status: string;
    clock_in_at: string | null;
    clock_out_at: string | null;
    overtime_started_at: string | null;
    overtime_ended_at: string | null;
    regular_minutes: number;
    overtime_minutes: number;
}

export interface PreviewResponse {
    shift_id: number;
    work_date: string;
    field: CorrectionField;
    old_value: string | null;
    new_value: string;
    closed_month: boolean;
    overtime_reset: boolean;
    date: {
        before: { regular_minutes: number; overtime_minutes: number };
        after: { regular_minutes: number; overtime_minutes: number };
    };
    shifts: { id: number; is_target: boolean; before: ShiftSummary | null; after: ShiftSummary }[];
}
