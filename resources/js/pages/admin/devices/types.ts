import type { Paginated } from '../people/types';

export type DeviceKind = 'desktop' | 'browser';

export interface DevicePerson {
    id: number;
    name: string;
    username: string;
    last_online_sign_in_at: string | null;
}

export interface DeviceOpenShift {
    user_id: number;
    name: string;
    clock_in_at: string | null;
    status: 'open' | 'prompted' | 'overtime' | 'interrupted';
}

export interface DeviceRow {
    id: string;
    kind: DeviceKind;
    hostname: string;
    app_version: string;
    last_seen_at: string | null;
    revoked_at: string | null;
    people: DevicePerson[];
    people_count: number;
    open_shifts: DeviceOpenShift[];
}

export interface DeviceAction {
    action: 'device.revoked' | 'device.restored';
    at: string | null;
    actor: string | null;
    reason: string | null;
}

export interface DeviceFilters {
    kind: DeviceKind;
    status: 'all' | 'active' | 'revoked';
    q: string;
}

export interface DevicesPageProps {
    devices: Paginated<DeviceRow>;
    filters: DeviceFilters;
    counts: Record<DeviceKind, number>;
    this_browser_device_id: string | null;
    resume_window_minutes: number;
    revocations: Record<string, DeviceAction>;
}
