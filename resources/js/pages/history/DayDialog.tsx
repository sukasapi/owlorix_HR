import { Dialog } from '@/components/ui/Dialog';
import { formatDateTime, formatLongDate, formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { Browser, CalendarCheck, CalendarX, ClockCountdown, HourglassMedium, Warning, X } from '@phosphor-icons/react';
import { useId } from 'react';
import { durationWords, timeOn } from './dates';
import { followUpsHref } from './links';
import { dayStatusText, overtimeIcon, overtimeTone, shiftChips, timeline } from './shifts';
import type { Decision, HistoryDay, HistoryRules, HistoryShift } from './types';


interface Props {
    day: HistoryDay | null;
    rules: HistoryRules;
    /** Reports due and late claims can also be written on Hari ini */
    webClock: boolean;
    onClose: () => void;
}

/** One date in detail: calendar status, then each shift with its chips, minutes, timeline, and overtime decision. */
export function DayDialog({ day, rules, webClock, onClose }: Props) {
    const titleId = useId();

    return (
        <Dialog open={day !== null} onClose={onClose} labelledBy={titleId} width="max-w-[680px]">
            {day && <DayDetails key={day.date} day={day} rules={rules} webClock={webClock} titleId={titleId} onClose={onClose} />}
        </Dialog>
    );
}

function DayDetails({ day, rules, webClock, titleId, onClose }: { day: HistoryDay; rules: HistoryRules; webClock: boolean; titleId: string; onClose: () => void }) {
    const t = useT();
    const locale = useLocale();
    const limit = durationWords(day.shifts[0]?.regular_limit_minutes ?? rules.regular_limit_minutes, locale);

    return (
        <div>
            <header className="flex flex-col gap-2 bg-panel px-5 pt-5 pb-5 sm:px-7">
                <div className="flex items-start justify-between gap-3">
                    <h2 id={titleId} className="h2 pt-1.5 text-[22px] sm:text-[24px]">
                        {formatLongDate(day.date, locale)}
                    </h2>
                    <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none bg-surface" aria-label={t('history.detail.close')}>
                        <X weight="bold" size={16} aria-hidden />
                    </button>
                </div>
                <p className="m-0 flex items-start gap-2 font-semibold">
                    {day.is_workday ? (
                        <CalendarCheck weight="bold" size={20} className="mt-0.5 flex-none" aria-hidden />
                    ) : (
                        <CalendarX weight="bold" size={20} className="mt-0.5 flex-none" aria-hidden />
                    )}
                    <span>{dayStatusText(day, t)}</span>
                </p>
                <p className="m-0 text-sm">{day.is_workday ? t('history.detail.workday_rule', { limit }) : t('history.detail.non_workday_rule')}</p>
            </header>

            {day.shifts.length === 0 ? (
                <p className="m-0 px-5 py-6 sm:px-7">{t('history.detail.no_shift')}</p>
            ) : (
                day.shifts.map((shift, i) => <ShiftSection key={shift.id} shift={shift} rules={rules} webClock={webClock} number={day.shifts.length > 1 ? i + 1 : null} />)
            )}
        </div>
    );
}

function ShiftSection({ shift, rules, webClock, number }: { shift: HistoryShift; rules: HistoryRules; webClock: boolean; number: number | null }) {
    const t = useT();
    const locale = useLocale();
    const headingId = useId();
    const chips = shiftChips(shift, t, locale);
    const at = (iso: string) => timeOn(iso, shift.work_date, locale);

    const range = shift.clock_out_at
        ? t('history.detail.shift_range', { start: at(shift.clock_in_at), end: at(shift.clock_out_at) })
        : t('history.detail.shift_running', { start: at(shift.clock_in_at) });

    const notes = [
        shift.status === 'needs_review' && t('history.detail.needs_review_note'),
        shift.flags.includes('clock_mismatch') && t('history.detail.clock_mismatch_note'),
        shift.flags.includes('gap_unverified') && t('history.detail.gap_unverified_note'),
    ].filter((note): note is string => Boolean(note));

    const noIdle = shift.idle_detection === 'none';
    const stats = [
        { key: 'regular', value: formatMinutes(shift.regular_minutes, locale), show: true },
        { key: 'overtime', value: formatMinutes(shift.overtime_minutes, locale), show: true },
        // A browser records no quiet time, so 0 minutes would claim something that was never measured
        { key: 'idle', value: noIdle ? t('history.detail.idle_not_recorded') : formatMinutes(shift.idle_minutes, locale), show: true },
        { key: 'interruption', value: formatMinutes(shift.interruption_minutes, locale), show: shift.interruption_minutes > 0 },
    ].filter((s) => s.show);

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-4 border-t border-line px-5 py-5 first-of-type:border-t-0 sm:px-7">
            <div className="flex flex-col gap-2.5">
                <h3 id={headingId} className="num font-display text-[20px] leading-tight font-bold text-heading">
                    {number !== null ? `${t('history.detail.shift_n', { n: number })}: ${range}` : range}
                </h3>
                {chips.length > 0 && (
                    <ul className="m-0 flex list-none flex-wrap gap-2 p-0">
                        {chips.map(({ key, label, tone, icon: Icon }) => (
                            <li key={key} className={`chip max-w-full whitespace-normal ${tone}`}>
                                <Icon weight="bold" size={15} className="flex-none" aria-hidden />
                                <span className="min-w-0">{label}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <dl className="m-0 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                {stats.map(({ key, value }) => (
                    <div key={key} className="flex flex-col">
                        <dt className="text-[13px] font-semibold text-muted">{t(`history.detail.${key}`)}</dt>
                        <dd className="num m-0 text-[17px] font-semibold">{value}</dd>
                    </div>
                ))}
            </dl>

            {shift.idle_detection !== 'full' && (
                <p className="m-0 flex items-start gap-2 text-sm">
                    <Browser weight="bold" size={16} className="mt-0.5 flex-none" aria-hidden />
                    <span>{t(noIdle ? 'history.detail.web_no_idle' : 'history.detail.web_partial_idle')}</span>
                </p>
            )}

            {notes.length > 0 && (
                <ul className="m-0 flex list-none flex-col gap-1.5 p-0 text-sm">
                    {notes.map((note) => (
                        <li key={note} className="flex items-start gap-2">
                            <Warning weight="bold" size={16} className="mt-0.5 flex-none" aria-hidden />
                            <span>{note}</span>
                        </li>
                    ))}
                </ul>
            )}

            {shift.late_claim && (
                <div className="flex items-start gap-3 rounded-md border-2 border-gold bg-gold-tint px-4 py-3">
                    <ClockCountdown weight="bold" size={20} className="mt-0.5 flex-none" aria-hidden />
                    <div className="min-w-0">
                        <p className="m-0 font-semibold">{t('history.detail.late_claim_title', { deadline: formatDateTime(shift.late_claim.claimable_until, locale) })}</p>
                        <p className="m-0 mt-1 text-sm">
                            {t(webClock ? 'history.detail.late_claim_body_web' : 'history.detail.late_claim_body', { latest: at(shift.late_claim.latest_end_at) })}
                        </p>
                        {webClock && (
                            <Link href={followUpsHref()} className="link mt-1.5 inline-block text-sm font-semibold text-ink">
                                {t('history.detail.claim_on_my_day')}
                            </Link>
                        )}
                    </div>
                </div>
            )}

            <div>
                <h4 className="m-0 text-base font-semibold">{t('history.detail.timeline')}</h4>
                <ol className="m-0 mt-2 list-none divide-y divide-line p-0">
                    {timeline(shift, rules, t, locale).map((entry) => (
                        <li key={entry.key} className="flex gap-3 py-2">
                            <span className="num w-[5.5rem] flex-none font-semibold">{at(entry.at)}</span>
                            <span className="flex min-w-0 flex-col [overflow-wrap:anywhere]">
                                <span className="flex items-start gap-1.5">
                                    {entry.tone === 'bad' && <Warning weight="bold" size={15} className="mt-0.5 flex-none" aria-hidden />}
                                    {entry.text}
                                </span>
                                {entry.detail && <span className="text-sm text-muted">{entry.detail}</span>}
                            </span>
                        </li>
                    ))}
                </ol>
            </div>

            {shift.overtime && <OvertimeBlock shift={shift} overtime={shift.overtime} webClock={webClock} />}
        </section>
    );
}

function OvertimeBlock({ shift, overtime, webClock }: { shift: HistoryShift; overtime: NonNullable<HistoryShift['overtime']>; webClock: boolean }) {
    const t = useT();
    const locale = useLocale();
    const latest: Decision | undefined = overtime.decisions.at(-1);
    const status = overtime.status;
    const running = overtime.ended_at === null;

    const decisionLine = (d: Decision) => {
        const label = t(`history.decision.${d.decision}`);
        const who = d.decided_by ? t('history.decision.by', { decision: label, name: d.decided_by }) : label;
        return d.decided_at ? `${who}, ${formatDateTime(d.decided_at, locale)}` : who;
    };

    return (
        <div className="flex flex-col gap-3 rounded-md border border-line px-4 py-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="num m-0 text-base font-semibold">
                    {t('history.detail.overtime_heading')} · {formatMinutes(overtime.minutes, locale)}
                </h4>
                {status && (
                    <span className={`chip ${overtimeTone[status]}`}>
                        {(() => {
                            const Icon = overtimeIcon[status];
                            return <Icon weight="bold" size={15} aria-hidden />;
                        })()}
                        {t(`history.overtime_status.${status}`)}
                    </span>
                )}
            </div>

            {status === 'rejected' && latest?.decision === 'rejected' && (
                <div className="rounded-md border-2 border-danger px-3.5 py-3">
                    <p className="m-0 font-semibold text-danger">{decisionLine(latest)}</p>
                    {latest.note && <p className="m-0 mt-1 [overflow-wrap:anywhere] whitespace-pre-line">{latest.note}</p>}
                </div>
            )}

            <dl className="m-0 flex flex-col gap-3">
                <div>
                    <dt className="text-[13px] font-semibold text-muted">{t('history.overtime.reason')}</dt>
                    <dd className="m-0 [overflow-wrap:anywhere] whitespace-pre-line">{overtime.reason || t('history.overtime.no_reason')}</dd>
                </div>
                <div>
                    <dt className="text-[13px] font-semibold text-muted">{t('history.overtime.report')}</dt>
                    <dd className="m-0 [overflow-wrap:anywhere] whitespace-pre-line">
                        {overtime.work_report ? (
                            overtime.work_report
                        ) : running ? (
                            t('history.overtime.running')
                        ) : (
                            <span className="flex items-start gap-1.5">
                                <HourglassMedium weight="bold" size={16} className="mt-0.5 flex-none" aria-hidden />
                                <span>
                                    {t(webClock ? 'history.overtime.report_due_web' : 'history.overtime.report_due')}
                                    {webClock && (
                                        <>
                                            {' '}
                                            <Link href={followUpsHref()} className="link font-semibold">
                                                {t('history.detail.report_on_my_day')}
                                            </Link>
                                        </>
                                    )}
                                </span>
                            </span>
                        )}
                    </dd>
                </div>
            </dl>

            {!(status === 'rejected' && latest?.decision === 'rejected') && (
                <p className="m-0 text-sm">
                    {latest && status !== 'pending' ? decisionLine(latest) : overtime.work_report ? t('history.decision.none') : t('history.decision.waiting_report')}
                    {latest?.note && status !== 'pending' && <span className="block [overflow-wrap:anywhere] whitespace-pre-line">{latest.note}</span>}
                </p>
            )}
            {status === 'pending' && overtime.decisions.length > 0 && <p className="m-0 text-sm">{t('history.decision.reset')}</p>}

            <Link href={route('overtime.mine', { bulan: shift.work_date.slice(0, 7) })} className="link self-start text-sm font-semibold">
                {t('history.detail.see_overtime')}
            </Link>
        </div>
    );
}
