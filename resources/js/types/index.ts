export type Locale = 'id' | 'en';
export type ThemePreference = 'system' | 'light' | 'dark';

export interface AuthUser {
    id: number;
    name: string;
    username: string;
    initials: string;
    locale: Locale;
    theme: ThemePreference;
    must_change_password: boolean;
}

export interface NavItem {
    key: string;
    href: string;
    route: string;
}

export interface NavGroup {
    group: string;
    items: NavItem[];
}

export interface IssuedCredentials {
    name: string;
    username: string;
    password: string;
}

export interface SharedProps {
    app: { name: string; timezone: string; locale: Locale };
    auth: { user: AuthUser; permissions: string[] } | null;
    nav: NavGroup[];
    /** Counts next to nav items, keyed by nav item key (for example `approvals`). */
    nav_badges?: Partial<Record<string, number>>;
    flash: { status?: string; issued_credentials?: IssuedCredentials };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface DayVerdict {
    date: string;
    is_workday: boolean;
    source: 'opened' | 'calendar' | 'work_week';
    calendar_type: 'holiday' | 'studio_day_off' | 'workday' | null;
    label: string | null;
}
