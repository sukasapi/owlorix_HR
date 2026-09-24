import enAudit from '@/lang/en/audit';
import idAudit from '@/lang/id/audit';
import { formatDateTime, formatMinutes, formatShortDate } from '@/lib/format';
import type { Translate } from '@/lib/i18n';
import type { Locale } from '@/types';
import type { AuditEntry, AuditPageProps, AuditSubject } from './types';

type Names = AuditPageProps['names'];

/**
 * Action names contain dots, which the translator reads as nesting, so they are looked up in the audit
 * dictionaries directly. English falls back to Indonesian, an unknown action shows its raw name.
 */
export function actionLabel(action: string, locale: Locale): string {
    const en = enAudit.actions as Record<string, string>;
    const id = idAudit.actions as Record<string, string>;
    return (locale === 'en' ? en[action] : undefined) ?? id[action] ?? action;
}

/** A translation, or null when the key has none (the translator returns the key itself). */
function maybe(t: Translate, key: string, replacements?: Record<string, string | number>): string | null {
    const text = t(key, replacements);
    return text === key ? null : text;
}

export function groupLabel(t: Translate, group: string): string {
    return maybe(t, `audit.groups.${group}`) ?? group;
}

/** A setting key shown with the label Aturan uses for it. */
export function settingLabel(t: Translate, key: string): string {
    return maybe(t, `settings.fields.${key}.label`) ?? key;
}

export function fieldLabel(t: Translate, entry: AuditEntry, key: string): string {
    if (entry.action === 'settings.updated') return settingLabel(t, key);
    return maybe(t, `audit.fields.${key}`) ?? key;
}

export function subjectText(t: Translate, subject: AuditSubject, locale: Locale): string {
    const date = subject.date ? formatShortDate(subject.date, locale) : '';
    const person = subject.person ?? t('audit.subject.unknown_person');

    switch (subject.kind) {
        case 'shift':
        case 'overtime':
        case 'correction':
            return t(`audit.subject.${subject.kind}`, { person, date });
        case 'calendar_day':
            return subject.label ? t('audit.subject.calendar_day_named', { date, name: subject.label }) : t('audit.subject.calendar_day', { date });
        case 'opened_workday':
            return subject.label ? t('audit.subject.opened_workday_for', { date, name: subject.label }) : t('audit.subject.opened_workday', { date });
        case 'work_week':
            return t('audit.subject.work_week');
        case 'setting':
            return t('audit.subject.setting', { name: settingLabel(t, subject.label ?? '') });
        default:
            return subject.label ?? '';
    }
}

const ISO_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
const PERSON_KEYS = ['user_id', 'person_id', 'lead_user_id', 'opened_by', 'proposed_by', 'approved_by', 'decided_by'];
/** Lists of person ids, each item read as a person */
const PERSON_LIST_KEYS = ['assignee_ids', 'assignees_added', 'assignees_removed'];

function unitFor(key: string): string | null {
    const match = key.match(/_(minutes|seconds|hours|days)$/);
    return match ? match[1] : null;
}

/** One value of a before/after payload as readable text. */
export function valueText(t: Translate, locale: Locale, names: Names, entry: AuditEntry, key: string, value: unknown, payload: Record<string, unknown>): string {
    if (value === null || value === undefined || value === '') return t('audit.diff.empty');
    if (typeof value === 'boolean') return value ? t('audit.values.yes') : t('audit.values.no');

    if (Array.isArray(value)) {
        if (value.length === 0) return t('audit.diff.empty');
        const itemKey = key === 'team_ids' ? 'team_id' : key === 'workdays' ? 'weekday' : PERSON_LIST_KEYS.includes(key) ? 'person_id' : key;
        return value.map((item) => valueText(t, locale, names, entry, itemKey, item, payload)).join(', ');
    }

    // A nested snapshot (a shift before and after a correction) reads as "Jam masuk: 09.00; Status: Selesai"
    if (typeof value === 'object') {
        const nested = value as Record<string, unknown>;
        return Object.entries(nested)
            .map(([k, v]) => `${fieldLabel(t, entry, k)}: ${valueText(t, locale, names, entry, k, v, nested)}`)
            .join('; ');
    }

    if (PERSON_KEYS.includes(key) || (key === 'scope_id' && payload.scope_type === 'user')) {
        return names.people[String(value)] ?? `#${value}`;
    }

    if (key === 'team_id' || (key === 'scope_id' && payload.scope_type === 'team')) {
        return names.teams[String(value)] ?? `#${value}`;
    }

    if (key === 'weekday') return maybe(t, `audit.values.weekdays.${value}`) ?? String(value);
    if (key === 'roles') return maybe(t, `common.roles.${value}`) ?? String(value);
    if (['status', 'part_status', 'kind', 'type', 'scope_type', 'reason', 'field'].includes(key)) {
        const known = maybe(t, `audit.values.${key}.${value}`);
        if (known) return known;
    }

    if (typeof value === 'string' && ISO_TIME.test(value)) return formatDateTime(value, locale);
    if (typeof value === 'string' && DATE_ONLY.test(value)) return formatShortDate(value, locale);

    if (typeof value === 'number' && entry.action === 'settings.updated') {
        const unit = unitFor(key);
        return unit ? `${value} ${t(`audit.values.units.${unit}`)}` : String(value);
    }

    if (typeof value === 'number' && (key === 'minutes' || key.endsWith('_minutes'))) return formatMinutes(value, locale);

    return String(value);
}
