export type LeaveStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export interface LeaveTypeSummary {
    id: number;
    code: string;
    name: string;
    counts_against_quota: boolean;
    requires_note: boolean;
    is_active: boolean;
}

export interface Balance {
    year: number;
    quota: number;
    /** True when Superadmin set a quota for this person and year; otherwise the studio default applies */
    custom: boolean;
    used: number;
    pending: number;
    remaining: number;
}

export interface LeaveItem {
    id: number;
    status: LeaveStatus;
    type: { id: number; name: string; counts_against_quota: boolean } | null;
    start_date: string;
    end_date: string;
    days: number;
    reason: string | null;
    person: { id: number; name: string; initials: string; status: string | null; teams: string[] } | null;
    attachment: { name: string; url: string } | null;
    decision: { decision: 'approved' | 'rejected'; by: string | null; at: string | null; note: string | null } | null;
    cancellation: { by: string | null; by_owner: boolean; at: string | null; note: string | null } | null;
    created_at: string;
    can_cancel: boolean;
    /** Cancelling someone else's request (Superadmin) needs a note */
    cancel_needs_note: boolean;
    can_decide: boolean;
    /** Admin cuti only, on pending annual-leave requests: the person's balance for the request's year */
    balance?: Balance | null;
}

export interface PendingItem extends LeaveItem {
    /**
     * Annual leave of the person for the request's year, pending requests included; null for types outside the quota.
     * Missing for approvers without leave.manage: the quota is a record for Superadmin only.
     */
    balance?: Balance | null;
    also_off: { name: string; start_date: string; end_date: string; status: LeaveStatus }[];
}

export interface MyLeaveProps {
    today: string;
    /** Only for leave.manage holders */
    balance: Balance | null;
    types: LeaveTypeSummary[];
    requests: LeaveItem[];
    list_limit: number;
    limits: { attachment_max_kb: number; max_span_days: number; backdate_days: number; earliest: string; latest: string };
}

export interface ApprovalsProps {
    pending: PendingItem[];
    recent: LeaveItem[];
    recent_limit: number;
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

export interface AdminFilters {
    status: LeaveStatus | 'all';
    bulan: string | null;
    orang: number | null;
}

export interface QuotaRow extends Balance {
    id: number;
    name: string;
    initials: string;
}

export interface AdminLeaveType extends LeaveTypeSummary {
    requests_count: number;
}

export interface AdminLeaveProps {
    filters: AdminFilters;
    requests: Paginated<LeaveItem>;
    pending_count: number;
    people: { id: number; name: string; status: string | null }[];
    quota: { year: number; this_year: number; default_days: number; rows: QuotaRow[] };
    types: AdminLeaveType[];
}
