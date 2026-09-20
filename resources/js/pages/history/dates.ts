import { formatDateTime, formatTime } from '@/lib/format';
import type { Locale } from '@/types';

const intlLocale = (locale: Locale) => (locale === 'id' ? 'id-ID' : 'en-GB');

/** Noon UTC keeps a plain Y-m-d on the same calendar date in every browser zone. */
function utcNoon(year: number, monthIndex: number, day: number) {
    return new Date(Date.UTC(year, monthIndex, day, 12));
}

/** "September 2026" from "2026-09". */
export function monthLabel(month: string, locale: Locale): string {
    const [y, m] = month.split('-').map(Number);
    return new Intl.DateTimeFormat(intlLocale(locale), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(utcNoon(y, m - 1, 1));
}

/** ISO weekday (1 = Monday) to a name. 2024-01-01 was a Monday. */
export function weekdayName(isoWeekday: number, locale: Locale, style: 'short' | 'long'): string {
    return new Intl.DateTimeFormat(intlLocale(locale), { weekday: style, timeZone: 'UTC' }).format(utcNoon(2024, 0, isoWeekday));
}

export function dayOfMonth(date: string): number {
    return Number(date.slice(8, 10));
}

/** Weeks of the month, Monday first, padded with null before the 1st and after the last day. */
export function weeksOf<T extends { weekday: number }>(days: T[]): (T | null)[][] {
    if (days.length === 0) return [];
    const cells: (T | null)[] = [...Array<null>(days[0].weekday - 1).fill(null), ...days];
    while (cells.length % 7 !== 0) cells.push(null);

    const weeks: (T | null)[][] = [];
    for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));
    return weeks;
}

/** Studio calendar date (Asia/Jakarta, Y-m-d) of a UTC moment. */
export function jakartaDate(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date(iso));
}

/** A time on its work date as "17.00"; a time on another date (a shift past midnight) also gets its date. */
export function timeOn(iso: string, workDate: string, locale: Locale): string {
    return jakartaDate(iso) === workDate ? formatTime(iso, locale) : formatDateTime(iso, locale);
}

export function minutesBetween(from: string, to: string): number {
    return Math.max(0, Math.floor((new Date(to).getTime() - new Date(from).getTime()) / 60_000));
}

/** A rule duration written out for a sentence: "8 jam", "7 jam 30 menit", "30 minutes". */
export function durationWords(total: number, locale: Locale): string {
    const h = Math.floor(total / 60);
    const m = total % 60;
    const parts: string[] = [];
    if (locale === 'id') {
        if (h > 0) parts.push(`${h} jam`);
        if (m > 0 || h === 0) parts.push(`${m} menit`);
    } else {
        if (h > 0) parts.push(`${h} ${h === 1 ? 'hour' : 'hours'}`);
        if (m > 0 || h === 0) parts.push(`${m} ${m === 1 ? 'minute' : 'minutes'}`);
    }
    return parts.join(' ');
}
