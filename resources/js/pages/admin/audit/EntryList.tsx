import { formatLongDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { CaretDown, CaretUp, Code } from '@phosphor-icons/react';
import { useId, useState } from 'react';
import { actionLabel, fieldLabel, subjectText, valueText } from './labels';
import type { AuditEntry, AuditPageProps } from './types';

const studioDate = (iso: string) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date(iso));

/**
 * Entries grouped under a heading per studio day, so each row only carries its time. A row opens in place to show the
 * before and after values as a table; raw JSON stays one more click away.
 */
export function EntryList({ entries, names }: { entries: AuditEntry[]; names: AuditPageProps['names'] }) {
    const locale = useLocale();
    const days: { date: string; entries: AuditEntry[] }[] = [];

    for (const entry of entries) {
        const date = studioDate(entry.created_at);
        const last = days[days.length - 1];
        if (last && last.date === date) last.entries.push(entry);
        else days.push({ date, entries: [entry] });
    }

    return (
        <div className="flex flex-col gap-5">
            {days.map((day) => (
                <section key={day.date} aria-labelledby={`audit-day-${day.date}`}>
                    <h2 id={`audit-day-${day.date}`} className="mb-2 text-[15px] font-semibold text-muted">
                        {formatLongDate(day.date, locale)}
                    </h2>
                    <ul className="card m-0 list-none divide-y divide-line p-0">
                        {day.entries.map((entry) => (
                            <EntryRow key={entry.id} entry={entry} names={names} />
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}

function EntryRow({ entry, names }: { entry: AuditEntry; names: AuditPageProps['names'] }) {
    const t = useT();
    const locale = useLocale();
    const panelId = useId();
    const [open, setOpen] = useState(false);
    const time = formatTime(entry.created_at, locale);
    const label = actionLabel(entry.action, locale);
    const subject = entry.subject ? subjectText(t, entry.subject, locale) : '';

    return (
        <li>
            <div className="grid grid-cols-[52px_minmax(0,1fr)] gap-x-3 gap-y-2 px-4 py-3.5 sm:grid-cols-[60px_minmax(0,1fr)_auto] sm:items-start sm:px-5">
                <time dateTime={entry.created_at} className="num pt-0.5 font-semibold">
                    {time}
                </time>
                <div className="min-w-0">
                    <p className="m-0 font-semibold break-words">{label}</p>
                    {subject !== '' && (
                        <p className="m-0 text-sm break-words">
                            {entry.subject?.href ? (
                                <Link href={entry.subject.href} className="link">
                                    {subject}
                                </Link>
                            ) : (
                                subject
                            )}
                        </p>
                    )}
                    <p className="m-0 text-sm break-words text-muted">{t('audit.by', { name: entry.actor?.name ?? t('audit.system') })}</p>
                </div>
                <div className="col-start-2 sm:col-start-3">
                    <button
                        type="button"
                        className="btn btn-secondary btn-sm min-h-11"
                        aria-expanded={open}
                        aria-controls={panelId}
                        aria-label={t('audit.details_label', { action: label, time })}
                        onClick={() => setOpen((value) => !value)}
                    >
                        {t('audit.details')}
                        {open ? <CaretUp weight="bold" size={15} aria-hidden /> : <CaretDown weight="bold" size={15} aria-hidden />}
                    </button>
                </div>
            </div>
            <div id={panelId} hidden={!open} className="border-t border-line bg-paper px-4 py-4 sm:px-5">
                {open && <Details entry={entry} names={names} />}
            </div>
        </li>
    );
}

function Details({ entry, names }: { entry: AuditEntry; names: AuditPageProps['names'] }) {
    const t = useT();
    const locale = useLocale();
    const [json, setJson] = useState(false);
    const before = entry.before ?? {};
    const after = entry.after ?? {};
    const keys = [...new Set([...Object.keys(before), ...Object.keys(after)])];
    const hasBefore = entry.before !== null;
    const hasAfter = entry.after !== null;

    return (
        <div className="flex flex-col gap-3">
            {keys.length === 0 ? (
                <p className="m-0 text-sm text-muted">{t('audit.diff.no_values')}</p>
            ) : (
                <div className="table-wrap">
                    <table className="table">
                        <caption className="sr-only">{t('audit.diff.caption')}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{t('audit.diff.field')}</th>
                                {hasBefore && <th scope="col">{t('audit.diff.before')}</th>}
                                {hasAfter && <th scope="col">{t('audit.diff.after')}</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {keys.map((key, index) => {
                                const changed = hasBefore && hasAfter && JSON.stringify(before[key]) !== JSON.stringify(after[key]);
                                return (
                                    <tr key={key}>
                                        {/* A row header, styled like a body cell rather than the column headers */}
                                        <th
                                            scope="row"
                                            className="align-top"
                                            style={{ whiteSpace: 'normal', color: 'var(--ink)', fontSize: 14, borderBottom: index === keys.length - 1 ? 0 : '1px solid var(--line)' }}
                                        >
                                            {fieldLabel(t, entry, key)}
                                            {changed && <span className="sr-only"> ({t('audit.diff.changed')})</span>}
                                        </th>
                                        {hasBefore && <td className={`align-top break-words ${changed ? 'text-muted' : ''}`}>{valueText(t, locale, names, entry, key, before[key], before)}</td>}
                                        {hasAfter && <td className={`align-top break-words ${changed ? 'font-semibold' : ''}`}>{valueText(t, locale, names, entry, key, after[key], after)}</td>}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2">
                {entry.ip ? <p className="num m-0 text-sm text-muted">{t('audit.ip', { ip: entry.ip })}</p> : <span />}
                <button type="button" className="btn btn-quiet min-h-11" aria-pressed={json} onClick={() => setJson((value) => !value)}>
                    <Code weight="bold" size={16} aria-hidden />
                    {json ? t('audit.json_hide') : t('audit.json_show')}
                </button>
            </div>

            {json && (
                <pre className="m-0 max-h-[320px] overflow-auto rounded-md border border-line bg-surface p-3 text-[13px] leading-relaxed whitespace-pre">
                    {JSON.stringify({ before: entry.before, after: entry.after }, null, 2)}
                </pre>
            )}
        </div>
    );
}
