import { formatDateTime, formatTime } from '@/lib/format';
import type { Translate } from '@/lib/i18n';
import type { Locale } from '@/types';

const studioDate = (date: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(date);

/** "Hari ini 09.02", "Kemarin 17.40", or "12 Sep 10.00", all in studio time. */
export function when(iso: string, locale: Locale, t: Translate): string {
    const moment = new Date(iso);
    const day = studioDate(moment);
    const now = new Date();

    if (day === studioDate(now)) return t('devices.when.today', { time: formatTime(iso, locale) });
    if (day === studioDate(new Date(now.getTime() - 86_400_000))) return t('devices.when.yesterday', { time: formatTime(iso, locale) });
    return formatDateTime(iso, locale);
}

/** Long ids keep their start and end, so two PCs with the same prefix still differ: "web:Ab12…9xYz". */
export function shortId(id: string): string {
    return id.length <= 18 ? id : `${id.slice(0, 8)}…${id.slice(-4)}`;
}
