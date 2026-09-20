import { formatDateTime, formatLongDate, formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { ArrowCounterClockwise, ClockCountdown, HourglassMedium, Timer, XCircle } from '@phosphor-icons/react';
import { useId } from 'react';
import { followUpsHref } from '../history/links';
import { timeOn } from '../history/dates';
import { overtimeIcon, overtimeTone } from '../history/shifts';
import type { Decision, OvertimeItem } from './types';

/**
 * One overtime request. A rejection leads with its note, because that is what the person needs to read first
 * (docs/02 edge cases: "the person sees the note"). The decision history lists every decision, oldest first.
 */
export function RequestCard({ item, webClock }: { item: OvertimeItem; webClock: boolean }) {
    const t = useT();
    const locale = useLocale();
    const headingId = useId();
    const StatusIcon = overtimeIcon[item.status];
    const at = (iso: string) => timeOn(iso, item.work_date, locale);

    const current = item.decisions.at(-1);
    const leadRejection = item.status === 'rejected' && current?.decision === 'rejected' ? current : null;
    const history = leadRejection ? item.decisions.slice(0, -1) : item.decisions;

    const decisionLine = (d: Decision) => {
        const label = t(`overtime.item.decision.${d.decision}`);
        const who = d.decided_by ? t('overtime.item.decision_by', { decision: label, name: d.decided_by }) : label;
        return d.decided_at ? `${who}, ${formatDateTime(d.decided_at, locale)}` : who;
    };

    return (
        <article aria-labelledby={headingId} className="card flex flex-col gap-4 px-5 py-5 sm:px-6">
            <header className="flex flex-col gap-2">
                <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <h2 id={headingId} className="h2 text-[20px]">
                        {formatLongDate(item.work_date, locale)}
                    </h2>
                    <span className={`chip ${overtimeTone[item.status]}`}>
                        <StatusIcon weight="bold" size={15} aria-hidden />
                        {t(`overtime.status.${item.status}`)}
                    </span>
                </div>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <p className="num m-0 font-semibold">
                        {item.ended_at ? t('overtime.item.range', { start: at(item.started_at), end: at(item.ended_at) }) : t('overtime.item.running_range', { start: at(item.started_at) })}
                        <span className="text-muted"> · </span>
                        {formatMinutes(item.minutes, locale)}
                    </p>
                    {item.is_running && (
                        <span className="chip chip-info">
                            <Timer weight="bold" size={15} aria-hidden />
                            {t('history.flags.running')}
                        </span>
                    )}
                    {item.is_late_claim && (
                        <span className="chip chip-info">
                            <ClockCountdown weight="bold" size={15} aria-hidden />
                            {t('overtime.item.late_claim')}
                        </span>
                    )}
                </div>
            </header>

            {leadRejection && (
                <div className="rounded-md border-2 border-danger px-4 py-3">
                    <p className="m-0 flex items-start gap-2 font-semibold text-danger">
                        <XCircle weight="bold" size={18} className="mt-px flex-none" aria-hidden />
                        {leadRejection.decided_at
                            ? leadRejection.decided_by
                                ? t('overtime.item.rejected_by', { name: leadRejection.decided_by, time: formatDateTime(leadRejection.decided_at, locale) })
                                : t('overtime.item.rejected_at', { time: formatDateTime(leadRejection.decided_at, locale) })
                            : t(`overtime.item.decision.rejected`)}
                    </p>
                    <p className="m-0 mt-1.5 text-[16px] [overflow-wrap:anywhere] whitespace-pre-line">{leadRejection.note ?? t('overtime.item.rejected_no_note')}</p>
                </div>
            )}

            {item.was_reset && (
                <p className="m-0 flex items-start gap-2 rounded-md bg-panel px-4 py-3 text-sm font-medium">
                    <ArrowCounterClockwise weight="bold" size={17} className="mt-px flex-none" aria-hidden />
                    {t('overtime.item.reset')}
                </p>
            )}

            {item.minutes === 0 && !item.is_running && <p className="m-0 text-sm">{t('overtime.item.zero_minutes')}</p>}

            <dl className="m-0 grid gap-4 md:grid-cols-2">
                <div className="min-w-0">
                    <dt className="text-[13px] font-semibold text-muted">{t('overtime.item.reason')}</dt>
                    <dd className="m-0 mt-0.5 [overflow-wrap:anywhere] whitespace-pre-line">{item.reason ?? t('overtime.item.no_reason')}</dd>
                </div>
                <div className="min-w-0">
                    <dt className="text-[13px] font-semibold text-muted">{t('overtime.item.report')}</dt>
                    <dd className="m-0 mt-0.5 [overflow-wrap:anywhere] whitespace-pre-line">
                        {item.work_report ? (
                            item.work_report
                        ) : item.is_running ? (
                            t('overtime.item.running')
                        ) : (
                            <span className="flex flex-col gap-1 rounded-md border-2 border-gold bg-gold-tint px-3 py-2.5">
                                <span className="flex items-center gap-1.5 font-semibold">
                                    <HourglassMedium weight="bold" size={16} className="flex-none" aria-hidden />
                                    {t('overtime.item.report_due_title')}
                                </span>
                                <span className="text-sm">{webClock ? t('overtime.item.report_due_web') : t('overtime.item.report_due')}</span>
                                {webClock && (
                                    <Link href={followUpsHref()} className="link self-start text-sm font-semibold text-ink">
                                        {t('overtime.item.open_my_day')}
                                    </Link>
                                )}
                            </span>
                        )}
                    </dd>
                </div>
            </dl>

            {!(leadRejection && history.length === 0) && (
                <div className="border-t border-line pt-3">
                    <h3 className="m-0 text-[14px] font-semibold">{leadRejection ? t('overtime.item.earlier_decisions') : t('overtime.item.decisions')}</h3>
                    {history.length === 0 ? (
                        <p className="m-0 mt-1 text-sm text-muted">{item.report_due || item.is_running ? t('overtime.item.waiting_report') : t('overtime.item.no_decision')}</p>
                    ) : (
                        <ol className="m-0 mt-1.5 flex list-none flex-col gap-2 p-0">
                            {history.map((d, i) => {
                                const Icon = overtimeIcon[d.decision];
                                return (
                                    <li key={`${d.decided_at}-${i}`} className="flex items-start gap-2 text-sm">
                                        <Icon weight="bold" size={16} className={`mt-px flex-none ${d.decision === 'approved' ? 'text-success' : 'text-danger'}`} aria-hidden />
                                        <span className="min-w-0">
                                            <span className="block font-semibold">{decisionLine(d)}</span>
                                            {d.note && <span className="block [overflow-wrap:anywhere] whitespace-pre-line">{d.note}</span>}
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </div>
            )}

            <Link href={route('history', { bulan: item.work_date.slice(0, 7), tanggal: item.work_date })} className="link self-start text-sm font-semibold">
                {t('overtime.item.open_history')}
            </Link>
        </article>
    );
}
