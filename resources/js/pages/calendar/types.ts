export type EntryType = 'holiday' | 'studio_day_off' | 'workday';
export type ScopeType = 'team' | 'user';

export interface CalendarEntry {
    id: number;
    type: EntryType;
    name: string;
}

export interface OpenedDay {
    id: number;
    scope_type: ScopeType;
    scope_id: number;
    scope_name: string;
    opened_by: string;
    note: string | null;
    can_close: boolean;
}

export interface CalendarDate {
    date: string;
    /** ISO weekday, 1 = Monday ... 7 = Sunday */
    weekday: number;
    is_today: boolean;
    is_past: boolean;
    week_workday: boolean;
    is_studio_workday: boolean;
    entry: CalendarEntry | null;
    opened: OpenedDay[];
}

export interface TeamOption {
    id: number;
    name: string;
    member_count: number;
}

export interface PersonOption {
    id: number;
    name: string;
    username: string;
}

export interface CalendarPageProps {
    month: { value: string; previous: string; next: string; current: string };
    today: string;
    work_week: { weekday: number; is_workday: boolean }[];
    days: CalendarDate[];
    can: { manage_calendar: boolean; open_workdays: boolean };
    scopes: { teams: TeamOption[]; people: PersonOption[] };
}
