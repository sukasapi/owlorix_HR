export type RequestStatus = 'pending' | 'approved' | 'rejected';
export type RequestFlag = 'late_claim' | 'needs_review' | 'clock_mismatch' | 'gap_unverified';
export type IdleTag = 'rendering' | 'meeting' | 'break' | 'other';

export interface OvertimeItem {
    id: number;
    status: RequestStatus;
    person: {
        id: number | null;
        name: string | null;
        initials: string | null;
        status: 'active' | 'suspended' | 'left' | null;
    };
    team_ids: number[];
    teams: string[];
    work_date: string | null;
    started_at: string;
    ended_at: string | null;
    minutes: number;
    reason: string;
    work_report: string | null;
    submitted_at: string | null;
    end_reason: 'manual' | 'auto_no_answer' | 'shutdown_timeout' | 'superadmin' | null;
    overtime_end_reason: 'clock_out' | 'presence_check_no_answer' | null;
    flags: RequestFlag[];
    blocked: 'running' | 'report_due' | null;
    idle: {
        started_at: string | null;
        ended_at: string | null;
        minutes: number;
        tag: IdleTag | null;
    }[];
    decision: {
        id: number;
        decision: 'approved' | 'rejected';
        note: string | null;
        decided_at: string | null;
        decided_by: string | null;
    } | null;
    can_change: boolean;
}

export interface BulkResult {
    approved: number;
    skipped: {
        flagged: number;
        not_ready: number;
        changed: number;
        not_allowed: number;
    };
}

export interface ApprovalsPageProps {
    abilities: { approve: boolean; change: boolean };
    pending: OvertimeItem[];
    decided: OvertimeItem[];
    decided_limit: number;
    teams: { id: number; name: string }[];
    bulk_result: BulkResult | null;
}

export type Tab = 'pending' | 'decided';
