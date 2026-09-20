import type { Locale } from '@/types';

export interface ReportQuery {
    bulan: string;
    tim?: number | null;
    orang?: number | null;
}

/** Only the filters in use go into the URL, so links stay short and shareable. */
export function reportHref({ bulan, tim, orang }: ReportQuery): string {
    const params: Record<string, string | number> = { bulan };
    if (tim) params.tim = tim;
    if (orang) params.orang = orang;
    return route('reports.index', params);
}

export function exportHref({ bulan, tim }: ReportQuery): string {
    const params: Record<string, string | number> = { bulan };
    if (tim) params.tim = tim;
    return route('reports.export', params);
}

const intlLocale = (locale: Locale) => (locale === 'id' ? 'id-ID' : 'en-GB');

/** "September 2026" from "2026-09". Noon UTC keeps the date stable in every browser zone. */
export function monthLabel(month: string, locale: Locale): string {
    const [y, m] = month.split('-').map(Number);
    return new Intl.DateTimeFormat(intlLocale(locale), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, 1, 12)));
}

/** The Asia/Jakarta calendar date (Y-m-d) of a UTC timestamp. */
export function studioDate(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date(iso));
}
