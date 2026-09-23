import { OwlEyes } from '@/components/owl/OwlEyes';
import { formatDateTime } from '@/lib/format';
import { type Translate, useLocale, useT } from '@/lib/i18n';
import { LockSimple } from '@phosphor-icons/react';
import { pageLabel } from './labels';
import type { ActivityPageProps, FailedSignIns, PersonRef } from './types';

interface Props {
    attention: ActivityPageProps['attention'];
    limits: ActivityPageProps['limits'];
    /** Filters the timeline to one person's access rows and scrolls to it */
    onShowPerson: (person: PersonRef) => void;
}

/**
 * The focal point of Monitor aktivitas: repeated failed sign-ins and refused access in the last 24 hours. The owl
 * eyes ask for attention when something is listed and rest open when nothing is.
 */
export function AttentionPanel({ attention, limits, onShowPerson }: Props) {
    const t = useT();
    const locale = useLocale();
    const count = attention.failed.length + attention.forbidden.length;
    const windowText = t('activity-monitor.attention.window', { hours: limits.attention_hours });

    return (
        <section className="brow mt-6 px-5 py-5 sm:px-7 sm:py-6" aria-labelledby="activity-attention">
            <div className="flex items-start gap-4">
                <OwlEyes state={count > 0 ? 'attention' : 'open'} size={count > 0 ? 64 : 56} />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h2 id="activity-attention" className="h2">
                            {t('activity-monitor.attention.title')}
                        </h2>
                        {count > 0 && (
                            <span className="chip chip-pending num">
                                <span aria-hidden>{count}</span>
                                <span className="sr-only">{t('activity-monitor.attention.count_label', { count })}</span>
                            </span>
                        )}
                    </div>
                    <p className="m-0 mt-1 text-sm text-muted">{windowText}</p>
                </div>
            </div>

            {count === 0 ? (
                <div className="mt-4">
                    <p className="m-0 font-semibold">{t('activity-monitor.attention.empty_title')}</p>
                    <p className="m-0 mt-1 max-w-[62ch] text-muted">
                        {t('activity-monitor.attention.empty_body', { hours: limits.attention_hours, count: limits.failed_threshold })}
                    </p>
                </div>
            ) : (
                <div className="mt-5 grid gap-5 md:grid-cols-2">
                    {attention.failed.length > 0 && (
                        <div className="min-w-0">
                            <h3 className="m-0 text-[15px] font-semibold">{t('activity-monitor.attention.failed_title')}</h3>
                            <ul className="card m-0 mt-2 list-none divide-y divide-line p-0">
                                {attention.failed.map((row) => (
                                    <li key={row.username} className="flex flex-col gap-1 px-4 py-3">
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span className="font-semibold break-all">{failedName(row, t)}</span>
                                            {row.locked && (
                                                <span className="chip chip-bad">
                                                    <LockSimple weight="bold" size={14} aria-hidden />
                                                    {t('activity-monitor.attention.locked')}
                                                </span>
                                            )}
                                        </div>
                                        <p className="m-0 text-sm">
                                            {row.person
                                                ? row.person.name
                                                : row.masked_prefix !== null
                                                  ? t('activity-monitor.attention.masked_note')
                                                  : t('activity-monitor.attention.unknown_username')}
                                        </p>
                                        <p className="num m-0 text-sm text-muted">
                                            {t('activity-monitor.attention.failed_row', { count: row.attempts, time: formatDateTime(row.last_at, locale) })}
                                            {row.last_ip && <> {t('activity-monitor.attention.failed_from', { ip: row.last_ip })}</>}
                                            {row.last_device && <> {t('activity-monitor.attention.failed_on_device', { device: row.last_device })}</>}
                                        </p>
                                        {row.person && <ShowPerson person={row.person} onShowPerson={onShowPerson} />}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {attention.forbidden.length > 0 && (
                        <div className="min-w-0">
                            <h3 className="m-0 text-[15px] font-semibold">{t('activity-monitor.attention.forbidden_title')}</h3>
                            <ul className="card m-0 mt-2 list-none divide-y divide-line p-0">
                                {attention.forbidden.map((row) => (
                                    <li key={row.person.id} className="flex flex-col gap-1 px-4 py-3">
                                        <span className="font-semibold break-words">
                                            {row.person.name} <span className="font-normal text-muted">({row.person.username})</span>
                                        </span>
                                        <p className="num m-0 text-sm text-muted">
                                            {t('activity-monitor.attention.forbidden_row', { count: row.refusals, time: formatDateTime(row.last_at, locale) })}
                                        </p>
                                        {(row.last_route || row.last_path) && (
                                            <p className="m-0 text-sm break-words">
                                                {t('activity-monitor.attention.forbidden_last', { page: pageLabel(row.last_route, row.last_path, locale) })}
                                            </p>
                                        )}
                                        <ShowPerson person={row.person} onShowPerson={onShowPerson} />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

function failedName(row: FailedSignIns, t: Translate): string {
    if (row.masked_prefix === null) return row.username;
    return row.masked_prefix === '' ? t('activity-monitor.attention.unknown_username') : t('activity-monitor.attention.masked', { prefix: row.masked_prefix });
}

function ShowPerson({ person, onShowPerson }: { person: PersonRef; onShowPerson: Props['onShowPerson'] }) {
    const t = useT();

    return (
        <div>
            <button type="button" className="btn btn-quiet min-h-11 px-0" onClick={() => onShowPerson(person)}>
                {t('activity-monitor.attention.see_timeline')}
                <span className="sr-only">: {person.name}</span>
            </button>
        </div>
    );
}
