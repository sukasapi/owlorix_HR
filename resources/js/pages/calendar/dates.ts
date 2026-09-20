import type { Translate } from '@/lib/i18n';
import type { Locale } from '@/types';
import type { CalendarDate } from './types';

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
export function weeksOf(days: CalendarDate[]): (CalendarDate | null)[][] {
    if (days.length === 0) return [];
    const cells: (CalendarDate | null)[] = [...Array<null>(days[0].weekday - 1).fill(null), ...days];
    while (cells.length % 7 !== 0) cells.push(null);

    const weeks: (CalendarDate | null)[][] = [];
    for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));
    return weeks;
}

/** "Senin, Selasa, dan Rabu" */
export function joinNames(names: string[], and: string): string {
    if (names.length <= 1) return names.join('');
    return `${names.slice(0, -1).join(', ')} ${and} ${names[names.length - 1]}`;
}

/** Short status for a date: the entry when there is one, otherwise the work week. */
export function statusText(day: CalendarDate, t: Translate): string {
    if (day.entry) return `${t(`calendar.types.${day.entry.type}`)}: ${day.entry.name}`;
    return day.week_workday ? t('calendar.cell.workday') : t('calendar.cell.non_workday');
}

export function openedText(day: CalendarDate, t: Translate, long = false): string | null {
    if (day.opened.length === 0) return null;
    if (day.opened.length === 1) return t('calendar.cell.opened_one', { name: day.opened[0].scope_name });
    return t(long ? 'calendar.cell.opened_many_long' : 'calendar.cell.opened_many', { count: day.opened.length });
}

/** Everything a screen reader needs from one date button. */
export function dateAccessibleLabel(day: CalendarDate, locale: Locale, t: Translate, longDate: (d: string, l: Locale) => string): string {
    const parts = [longDate(day.date, locale)];
    if (day.is_today) parts.push(t('calendar.cell.today'));
    parts.push(day.entry ? statusText(day, t) : day.is_studio_workday ? t('calendar.cell.workday') : t('calendar.cell.non_workday'));
    const opened = openedText(day, t, true);
    if (opened) parts.push(opened);
    return parts.join('. ');
}
