import type { Paginated } from '../people/types';

export interface PersonRef {
    id: number;
    name: string;
    username: string;
}

export type Kind = 'access' | 'change' | 'attendance';

/** Query values of the `jenis` filter */
export type KindParam = 'akses' | 'perubahan' | 'absensi';

export interface FailedSignIns {
    username: string;
    /** Set when the tries used text that matches no account: only its start was kept (may be empty) */
    masked_prefix: string | null;
    attempts: number;
    locked: boolean;
    last_at: string;
    last_ip: string | null;
    last_device: string | null;
    person: PersonRef | null;
}

export interface Refusals {
    person: PersonRef;
    refusals: number;
    last_at: string;
    last_route: string | null;
    last_path: string | null;
    last_ip: string | null;
}

export interface WebSession {
    person: PersonRef;
    browser: string | null;
    os: string | null;
    ip: string | null;
    last_seen_at: string;
}

export interface DesktopSession {
    person: PersonRef;
    device_id: string;
    hostname: string;
    app_version: string;
    last_seen_at: string;
}

export interface DayCount {
    date: string;
    access: number;
    change: number;
    attendance: number;
}

export interface TimelineRow {
    key: string;
    kind: Kind;
    event: string;
    at: string;
    person: PersonRef | null;
    username: string | null;
    masked_prefix: string | null;
    route: string | null;
    method: string | null;
    status: number | null;
    path: string | null;
    ip: string | null;
    device: { id: string; kind: 'desktop' | 'browser'; hostname: string | null } | null;
    audit_href: string | null;
}

export interface ActivityFilters {
    person: number | null;
    kind: KindParam | null;
    from: string | null;
    until: string | null;
}

export interface ActivityPageProps {
    attention: { failed: FailedSignIns[]; forbidden: Refusals[] };
    online: { web: WebSession[]; desktop: DesktopSession[]; web_tracked: boolean };
    chart: DayCount[];
    /** total_capped: there are more than `total` rows; the count stops where the pages end */
    timeline: Paginated<TimelineRow> & { total_capped: boolean };
    filters: ActivityFilters;
    options: { people: PersonRef[] };
    limits: {
        failed_threshold: number;
        attention_hours: number;
        online_minutes: number;
        chart_days: number;
        retention_days: number;
        max_page: number;
    };
    links: { audit: boolean; settings: boolean };
}

export const KIND_PARAMS: Record<Kind, KindParam> = { access: 'akses', change: 'perubahan', attendance: 'absensi' };

/** Same order and colors as the chart series, so a timeline row's key matches its column segment */
export const KIND_COLORS: Record<Kind, string> = { access: 'var(--viz-1)', change: 'var(--viz-2)', attendance: 'var(--viz-3)' };
