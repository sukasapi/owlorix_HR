export type Locale = 'id' | 'en';
export type ThemePreference = 'system' | 'light' | 'dark';

export interface AuthUser {
    id: number;
    name: string;
    /** Nickname when set, otherwise the full name. */
    display_name: string;
    username: string;
    initials: string;
    photo_url: string | null;
    locale: Locale;
    theme: ThemePreference;
    must_change_password: boolean;
}

export interface ImposterState {
    active: true;
    actor_name: string;
    actor_username: string;
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

export interface Brand {
    name: string;
    studio: string;
    footer_text: string;
    footer_link_label: string;
    footer_link_url: string;
    contact_email: string;
    logo_url: string;
    custom_logo: boolean;
}

export interface TaskTimer {
    session_id: number;
    task_id: number;
    task_title: string;
    started_at: string;
}

export interface Person {
    id: number;
    name: string;
    initials: string;
    photo_url: string | null;
}

export interface SharedProps {
    app: { name: string; timezone: string; locale: Locale; brand: Brand };
    task_timer?: TaskTimer | null;
    auth: { user: AuthUser; permissions: string[]; imposter: ImposterState | null } | null;
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
