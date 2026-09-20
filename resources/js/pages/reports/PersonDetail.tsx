import { formatDateTime, formatMinutes, formatShortDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { Locale } from '@/types';
import { Link } from '@inertiajs/react';
import { ArrowLeft, CalendarX } from '@phosphor-icons/react';
import { useId } from 'react';
import { Duration, MetricList, NoteChips, OvertimeChip, useMetrics } from './Figures';
import { reportHref, studioDate } from './query';
import type { PersonRow, ShiftRow } from './types';

interface Props {
    person: PersonRow;
    shifts: ShiftRow[];
    month: string;
    monthName: string;
    team: number | null;
}

/** One person's month, one row per shift. A table from md up, a card per shift on phones. */
export function PersonDetail({ person, shifts, month, monthName, team }: Props) {
    const t = useT();
    const headingId = useId();
    const { main, flags } = useMetrics(person);

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-4">
            <div>
                <Link href={reportHref({ bulan: month, tim: team })} className="btn btn-secondary btn-sm min-h-11">
                    <ArrowLeft weight="bold" size={16} aria-hidden />
                    {t('reports.detail.back')}
                </Link>
            </div>

            <div className="flex items-start gap-3">
                <span className="avatar mt-1" aria-hidden>
                    {person.initials}
                </span>
                <div className="min-w-0">
                    <p className="m-0 text-[13px] font-semibold text-muted">{t('reports.detail.heading')}</p>
                    <h2 id={headingId} className="m-0 font-display text-[24px] leading-tight font-bold break-words text-heading">
                        {person.name}
                    </h2>
                    <p className="m-0 text-sm break-words text-muted">
                        {[
                            person.username,
                            person.employee_code,
                            person.teams.length > 0 ? t('reports.detail.teams', { teams: person.teams.join(', ') }) : t('reports.detail.no_team'),
                            person.status !== 'active' ? t(`common.status.${person.status}`) : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
            </div>

            <div className="card grid gap-4 p-4 md:grid-cols-[3fr_2fr]">
                <MetricList metrics={main} className="sm:grid-cols-3" />
                <MetricList metrics={flags} className="border-t border-line pt-3 md:border-t-0 md:border-l md:pt-0 md:pl-4" />
            </div>

            {shifts.length === 0 ? (
                <div className="card flex flex-col items-start gap-2 px-5 py-6">
                    <h3 className="h2">{t('reports.detail.empty_title', { name: person.name, month: monthName })}</h3>
                    <p className="m-0 max-w-[60ch] text-muted">{t('reports.detail.empty_body')}</p>
                </div>
            ) : (
                <>
                    <div className="table-wrap hidden md:block">
                        <table className="table num">
                            <caption className="sr-only">{t('reports.detail.caption', { name: person.name, month: monthName })}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{t('reports.detail.columns.date')}</th>
                                    <th scope="col">{t('reports.detail.columns.day_type')}</th>
                                    <th scope="col">{t('reports.detail.columns.clock_in')}</th>
                                    <th scope="col">{t('reports.detail.columns.clock_out')}</th>
                                    <th scope="col" className="text-right">
                                        {t('reports.detail.columns.regular')}
                                    </th>
                                    <th scope="col">{t('reports.detail.columns.overtime')}</th>
                                    <th scope="col" className="text-right">
                                        {t('reports.detail.columns.idle')}
                                    </th>
                                    <th scope="col">{t('reports.detail.columns.notes')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {shifts.map((shift) => (
                                    <tr key={shift.id}>
                                        <th scope="row" className="border-b border-line text-left text-sm font-semibold whitespace-nowrap text-ink">
                                            <ShiftDate shift={shift} />
                                        </th>
                                        <td>
                                            <DayType isWorkday={shift.is_workday} />
                                        </td>
                                        <td className="whitespace-nowrap">
                                            <ClockTime iso={shift.clock_in_at} workDate={shift.work_date} />
                                        </td>
                                        <td className="whitespace-nowrap">
                                            <ClockOut shift={shift} />
                                        </td>
                                        <td className="text-right">
                                            <Duration minutes={shift.regular_minutes} />
                                        </td>
                                        <td>
                                            <OvertimeCell shift={shift} />
                                        </td>
                                        <td className="text-right">
                                            <Duration minutes={shift.idle_minutes} />
                                        </td>
                                        <td>
                                            <ShiftNotes shift={shift} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="m-0 flex list-none flex-col gap-3 p-0 md:hidden">
                        {shifts.map((shift) => (
                            <li key={shift.id} className="card px-4 py-3.5">
                                <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <p className="m-0 font-semibold">
                                        <ShiftDate shift={shift} />
                                    </p>
                                    <DayType isWorkday={shift.is_workday} />
                                </div>
                                <p className="num m-0 mt-1 text-sm">
                                    <ClockTime iso={shift.clock_in_at} workDate={shift.work_date} /> <span className="text-muted">{t('reports.detail.until')}</span> <ClockOut shift={shift} />
                                </p>
                                <dl className="num m-0 mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm">
                                    <div>
                                        <dt className="text-[13px] text-muted">{t('reports.detail.columns.regular')}</dt>
                                        <dd className="m-0 font-semibold">
                                            <Duration minutes={shift.regular_minutes} />
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-[13px] text-muted">{t('reports.detail.columns.idle')}</dt>
                                        <dd className="m-0 font-semibold">
                                            <Duration minutes={shift.idle_minutes} />
                                        </dd>
                                    </div>
                                    <div className="col-span-2">
                                        <dt className="text-[13px] text-muted">{t('reports.detail.columns.overtime')}</dt>
                                        <dd className="m-0 font-semibold">
                                            <OvertimeCell shift={shift} />
                                        </dd>
                                    </div>
                                    <div className="col-span-2">
                                        <dt className="text-[13px] text-muted">{t('reports.detail.columns.notes')}</dt>
                                        <dd className="m-0 mt-0.5">
                                            <ShiftNotes shift={shift} />
                                        </dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

function ShiftDate({ shift }: { shift: ShiftRow }) {
    const locale = useLocale();
    return <>{formatShortDate(shift.work_date, locale)}</>;
}

function DayType({ isWorkday }: { isWorkday: boolean }) {
    const t = useT();

    if (isWorkday) return <span className="text-sm">{t('reports.detail.workday')}</span>;

    return (
        <span className="chip">
            <CalendarX weight="bold" size={14} aria-hidden />
            {t('reports.detail.non_workday')}
        </span>
    );
}

/** A time on the work date shows as 09.02; one after midnight carries its own date. */
function clockText(iso: string, workDate: string, locale: Locale) {
    return studioDate(iso) === workDate ? formatTime(iso, locale) : formatDateTime(iso, locale);
}

function ClockTime({ iso, workDate }: { iso: string; workDate: string }) {
    const locale = useLocale();
    return <>{clockText(iso, workDate, locale)}</>;
}

function ClockOut({ shift }: { shift: ShiftRow }) {
    const t = useT();
    if (shift.clock_out_at === null) return <span className="text-muted">{t('reports.detail.running')}</span>;
    return <ClockTime iso={shift.clock_out_at} workDate={shift.work_date} />;
}

function OvertimeCell({ shift }: { shift: ShiftRow }) {
    if (shift.overtime_status === null) return <span className="text-muted">0</span>;

    return (
        <span className="flex flex-wrap items-center gap-2">
            <Duration minutes={shift.overtime_minutes} />
            <OvertimeChip status={shift.overtime_status} />
        </span>
    );
}

function ShiftNotes({ shift }: { shift: ShiftRow }) {
    const t = useT();
    const locale = useLocale();

    if (shift.interruption_minutes === 0) return <NoteChips notes={shift.notes} />;

    return (
        <span className="flex flex-col items-start gap-1">
            {shift.notes.length > 0 && <NoteChips notes={shift.notes} />}
            <span className="text-[13px] text-muted">{t('reports.detail.interruption', { duration: formatMinutes(shift.interruption_minutes, locale) })}</span>
        </span>
    );
}
