import enMonitor from '@/lang/en/activity-monitor';
import idMonitor from '@/lang/id/activity-monitor';
import type { Translate } from '@/lib/i18n';
import type { Locale } from '@/types';
import { actionLabel } from '../audit/labels';
import type { TimelineRow } from './types';

type Dict = Record<string, string>;

/**
 * Route names contain dots, which the translator reads as nesting, so pages and actions are looked up in the
 * dictionaries directly. English falls back to Indonesian; an unknown route shows its name.
 */
function lookup(section: 'pages' | 'actions', key: string, locale: Locale): string | null {
    const en = enMonitor[section] as Dict;
    const id = idMonitor[section] as Dict;
    return (locale === 'en' ? en[key] : undefined) ?? id[key] ?? null;
}

export function pageLabel(route: string | null, path: string | null, locale: Locale): string {
    if (route) return lookup('pages', route, locale) ?? route;
    return path ?? '-';
}

/** One timeline row as a readable sentence. */
export function describe(t: Translate, row: TimelineRow, locale: Locale): string {
    if (row.kind === 'change') return actionLabel(row.event, locale);

    if (row.kind === 'attendance') {
        const text = t(`activity-monitor.attendance.${row.event}`);
        return text === `activity-monitor.attendance.${row.event}` ? row.event : text;
    }

    const page = pageLabel(row.route, row.path, locale);

    switch (row.event) {
        case 'page_view':
            return t('activity-monitor.events.page_view', { page });
        case 'download':
            return t('activity-monitor.events.download', { page });
        case 'forbidden':
            return t(row.status === 429 ? 'activity-monitor.events.throttled' : 'activity-monitor.events.forbidden', { page });
        case 'action': {
            const action = row.route ? lookup('actions', row.route, locale) : null;
            return action ?? t('activity-monitor.events.action_unknown', { route: row.route ?? row.path ?? '-' });
        }
        default: {
            const text = t(`activity-monitor.events.${row.event}`);
            return text === `activity-monitor.events.${row.event}` ? row.event : text;
        }
    }
}
