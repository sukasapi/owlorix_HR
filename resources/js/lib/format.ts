import type { Locale } from '@/types';

/**
 * Times arrive from the server in UTC (ISO strings) and are shown in the studio zone.
 * Indonesian time uses a dot separator (09.02), as in the mockups.
 */
const TIMEZONE = 'Asia/Jakarta';

const intlLocale = (locale: Locale) => (locale === 'id' ? 'id-ID' : 'en-GB');

/** A plain calendar date (Y-m-d) is formatted as a local date without shifting zones. */
function dateOnly(value: string): Date {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d, 12));
}

export function formatLongDate(value: string, locale: Locale): string {
    return new Intl.DateTimeFormat(intlLocale(locale), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(dateOnly(value));
}

export function formatShortDate(value: string, locale: Locale): string {
    return new Intl.DateTimeFormat(intlLocale(locale), { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }).format(dateOnly(value));
}

export function formatTime(iso: string, locale: Locale): string {
    return new Intl.DateTimeFormat(intlLocale(locale), { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: TIMEZONE })
        .format(new Date(iso))
        .replace(':', locale === 'id' ? '.' : ':');
}

export function formatDateTime(iso: string, locale: Locale): string {
    const date = new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'short', timeZone: TIMEZONE }).format(new Date(iso));
    return `${date} ${formatTime(iso, locale)}`;
}

/** Durations in hours and minutes, no seconds (DESIGN.md change G7). */
export function formatMinutes(total: number, locale: Locale): string {
    const h = Math.floor(total / 60);
    const m = total % 60;
    const [hu, mu] = locale === 'id' ? ['j', 'mnt'] : ['h', 'min'];
    if (h === 0) return `${m} ${mu}`;
    return m === 0 ? `${h} ${hu}` : `${h} ${hu} ${m} ${mu}`;
}
