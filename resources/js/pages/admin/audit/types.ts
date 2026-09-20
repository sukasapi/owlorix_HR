import type { Paginated } from '../people/types';

export type Payload = Record<string, unknown> | null;

export interface AuditSubject {
    kind: 'person' | 'team' | 'overtime' | 'shift' | 'correction' | 'calendar_day' | 'opened_workday' | 'device' | 'setting' | 'work_week' | 'other';
    label: string | null;
    person: string | null;
    date: string | null;
    href: string | null;
}

export interface AuditEntry {
    id: number;
    created_at: string;
    action: string;
    group: string;
    actor: { id: number; name: string; username: string } | null;
    subject: AuditSubject | null;
    before: Payload;
    after: Payload;
    /** Only sent to Superadmin */
    ip: string | null;
}

export interface AuditFilters {
    group: string;
    actor: number | null;
    person: number | null;
    from: string | null;
    until: string | null;
}

export interface PersonOption {
    id: number;
    name: string;
    username: string;
}

export interface AuditPageProps {
    entries: Paginated<AuditEntry>;
    names: { people: Record<string, string>; teams: Record<string, string> };
    filters: AuditFilters;
    options: { groups: string[]; actors: PersonOption[]; people: PersonOption[] };
}
