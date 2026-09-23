import type { Translate } from '@/lib/i18n';
import type { Locale } from '@/types';

const intlLocale = (locale: Locale) => (locale === 'id' ? 'id-ID' : 'en-GB');

/** A studio calendar date (Y-m-d) at noon UTC, so formatting never shifts it to another day. */
function dateOnly(value: string): Date {
    const [y, m, d] = value.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d, 12));
}

function format(value: string, locale: Locale, options: Intl.DateTimeFormatOptions): string {
    return new Intl.DateTimeFormat(intlLocale(locale), { ...options, timeZone: 'UTC' }).format(dateOnly(value));
}

/** "Senin, 21 September 2026" for one day, "Sen, 21 Sep sampai Rab, 23 Sep 2026" for a range. */
export function dateRange(start: string, end: string, locale: Locale, t: Translate): string {
    if (start === end) return format(start, locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    const sameYear = start.slice(0, 4) === end.slice(0, 4);
    const from = format(start, locale, { weekday: 'short', day: 'numeric', month: 'short', ...(sameYear ? {} : { year: 'numeric' }) });
    const until = format(end, locale, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

    return t('leave.range', { from, until });
}

/** "21 Sep" style, for compact lists. */
export function shortDate(value: string, locale: Locale): string {
    return format(value, locale, { day: 'numeric', month: 'short' });
}

export function monthName(value: string, locale: Locale): string {
    return format(`${value}-01`, locale, { month: 'long', year: 'numeric' });
}

/** "1 hari kerja" / "3 hari kerja"; English needs the plural. */
export function workdays(count: number, t: Translate): string {
    return t(count === 1 ? 'leave.workdays_one' : 'leave.workdays_other', { count });
}

export function dayCount(count: number, t: Translate): string {
    return t(count === 1 ? 'leave.days_one' : 'leave.days_other', { count });
}
