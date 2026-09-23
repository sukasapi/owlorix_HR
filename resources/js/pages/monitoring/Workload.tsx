import { Meter } from '@/components/charts';
import { Avatar } from '@/components/ui/Avatar';
import AppShell from '@/layouts/AppShell';
import { formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { CaretLeft, CaretRight, Warning } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import { useListVisit } from '../admin/people/useListVisit';
import { Duration } from '../reports/Figures';
import { dayMonth, EmptyCard, ErrorCard, longDate } from './bits';
import type { LoadStatus, WorkloadProps, WorkloadRow } from './types';

const STATUS_ORDER: LoadStatus[] = ['over', 'full', 'fit', 'loose', 'no_capacity'];

/** Status chips carry text (and an icon where it needs attention), never color alone. */
const STATUS_CHIP: Record<LoadStatus, string> = { over: 'chip-bad', full: 'chip-pending', fit: '', loose: 'chip-info', no_capacity: 'chip-info' };

const weekHref = (monday: string) => route('monitoring.workload', { minggu: monday });

/**
 * Beban kerja. Focal point: who has more planned than they can do this week, so the summary line names that count
 * and the list starts with them. Rows are a table on wide screens and stacked cards on phones.
 */
export default function Workload({ week, limit_minutes, scope, counts, people }: WorkloadProps) {
    const t = useT();
    const locale = useLocale();
    const list = useListVisit(new URL(route('monitoring.workload'), window.location.origin).pathname);
    const noScope = scope.empty_reason !== null;

    return (
        <AppShell title={t('workload.title')}>
            <div className="flex flex-col gap-5">
                <header className="flex flex-col gap-1.5">
                    <h1 className="h1">{t('workload.title')}</h1>
                    <p className="m-0 max-w-[70ch] text-muted">{scope.studio ? t('workload.lead_studio') : t('workload.lead_scope')}</p>
                </header>

                {noScope ? (
                    <EmptyCard title={t('workload.states.no_scope_title')} body={t('workload.states.no_scope_body')} />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                            <nav aria-label={t('workload.week.nav_label')} className="flex items-center gap-2">
                                <Link href={weekHref(week.previous)} className="btn btn-secondary px-3" aria-label={t('workload.week.previous')} preserveScroll>
                                    <CaretLeft weight="bold" size={18} aria-hidden />
                                </Link>
                                <h2 className="num m-0 text-center font-display text-[20px] leading-tight font-bold text-heading sm:text-[24px]">
                                    {t('workload.week.range', { from: dayMonth(week.start, locale), until: longDate(week.end, locale) })}
                                </h2>
                                <Link href={weekHref(week.next)} className="btn btn-secondary px-3" aria-label={t('workload.week.next')} preserveScroll>
                                    <CaretRight weight="bold" size={18} aria-hidden />
                                </Link>
                            </nav>
                            {!week.is_current && (
                                <Link href={weekHref(week.current)} className="btn btn-quiet" preserveScroll>
                                    {t('workload.week.this_week')}
                                </Link>
                            )}
                        </div>

                        <p role="status" aria-live="polite" className="-my-2 min-h-[22px] text-sm font-semibold">
                            {list.state === 'loading' ? t('workload.states.loading', { week: dayMonth(week.start, locale) }) : null}
                        </p>

                        {list.state === 'error' ? (
                            <ErrorCard title={t('workload.states.error_title')} body={t('workload.states.error_body')} onRetry={list.retry} />
                        ) : people.length === 0 ? (
                            <EmptyCard
                                title={t('workload.states.no_people_title')}
                                body={scope.studio ? t('workload.states.no_people_body_studio') : t('workload.states.no_people_body_scope')}
                            />
                        ) : (
                            <div className={`flex flex-col gap-5 ${list.state === 'loading' ? 'opacity-60 transition-opacity' : 'transition-opacity'}`} aria-busy={list.state === 'loading'}>
                                <Summary counts={counts} />
                                <ReadingNotes limitMinutes={limit_minutes} />
                                <PeopleTable people={people} />
                                <PeopleCards people={people} />
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppShell>
    );
}

function Summary({ counts }: { counts: WorkloadProps['counts'] }) {
    const t = useT();
    const over = counts.over;

    return (
        <section className="flex flex-col gap-3">
            <p className={`m-0 flex items-start gap-2 font-display text-[20px] leading-snug font-bold sm:text-[24px] ${over > 0 ? 'text-danger' : 'text-heading'}`}>
                {over > 0 && <Warning weight="bold" size={24} className="mt-0.5 flex-none" aria-hidden />}
                {over === 0 ? t('workload.summary.none_over') : over === 1 ? t('workload.summary.over_one') : t('workload.summary.over_many', { count: over })}
            </p>
            <ul className="m-0 flex list-none flex-wrap gap-2 p-0" aria-label={t('workload.summary.counts_label')}>
                {STATUS_ORDER.filter((status) => counts[status] > 0).map((status) => (
                    <li key={status}>
                        <StatusChip status={status} suffix={` ${counts[status]}`} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

function ReadingNotes({ limitMinutes }: { limitMinutes: number }) {
    const t = useT();
    const locale = useLocale();

    return (
        <details className="rounded-md bg-panel px-4 py-1 text-sm">
            <summary className="inline-flex min-h-11 cursor-pointer items-center rounded-sm font-semibold">{t('workload.notes.heading')}</summary>
            <ul className="m-0 mb-3 flex max-w-[80ch] list-disc flex-col gap-1 pl-5">
                <li>{t('workload.notes.capacity', { limit: formatMinutes(limitMinutes, locale) })}</li>
                <li>{t('workload.notes.planned')}</li>
                <li>{t('workload.notes.review')}</li>
                <li>{t('workload.notes.status')}</li>
                <li>{t('workload.notes.estimate')}</li>
            </ul>
        </details>
    );
}

function StatusChip({ status, suffix = '' }: { status: LoadStatus; suffix?: string }) {
    const t = useT();

    return (
        <span className={`chip ${STATUS_CHIP[status]}`}>
            {status === 'over' && <Warning weight="bold" size={14} aria-hidden />}
            {t(`workload.status.${status}`)}
            {suffix && <span className="num">{suffix}</span>}
        </span>
    );
}

/** Planned against capacity. No workdays means there is nothing to measure against, so the text says that instead. */
function Load({ row }: { row: WorkloadRow }) {
    const t = useT();
    const locale = useLocale();

    if (row.capacity_minutes === 0) {
        return (
            <span className="text-sm text-muted">
                {row.planned_minutes > 0 ? t('workload.row.planned_no_capacity', { planned: formatMinutes(row.planned_minutes, locale) }) : t('workload.row.no_capacity')}
            </span>
        );
    }

    // The hours sit in their own columns, so the meter states the share
    return (
        <Meter
            value={row.planned_minutes}
            max={row.capacity_minutes}
            label={t('workload.row.meter_label', { name: row.person.name })}
            text={t('workload.row.meter_text', { percent: Math.round((row.planned_minutes / row.capacity_minutes) * 100) })}
        />
    );
}

function CapacityNote({ row }: { row: WorkloadRow }) {
    const t = useT();
    const days = row.workdays - row.leave_days;
    return <span className="text-[13px] whitespace-nowrap text-muted">{row.leave_days > 0 ? t('workload.row.days_leave', { days, leave: row.leave_days }) : t('workload.row.days', { days })}</span>;
}

function WithoutEstimate({ count }: { count: number }) {
    const t = useT();
    if (count === 0) return <span className="text-muted">{t('workload.row.none')}</span>;

    return (
        <span className="inline-flex items-center gap-1 font-semibold">
            <Warning weight="bold" size={14} aria-hidden />
            {count}
        </span>
    );
}

function PersonCell({ row }: { row: WorkloadRow }) {
    return (
        <span className="inline-flex min-w-0 items-center gap-2.5">
            <Avatar initials={row.person.initials} photoUrl={row.person.photo_url} />
            <span className="min-w-0 font-semibold break-words">{row.person.name}</span>
        </span>
    );
}

function PeopleTable({ people }: { people: WorkloadRow[] }) {
    const t = useT();

    return (
        <div className="table-wrap hidden lg:block">
            <table className="table">
                <thead>
                    <tr>
                        <th scope="col">{t('workload.columns.person')}</th>
                        <th scope="col">{t('workload.columns.status')}</th>
                        <th scope="col" className="w-[26%]">
                            {t('workload.columns.load')}
                        </th>
                        <th scope="col">{t('workload.columns.capacity')}</th>
                        <th scope="col">{t('workload.columns.planned')}</th>
                        <th scope="col">{t('workload.columns.without_estimate')}</th>
                        <th scope="col">{t('workload.columns.logged')}</th>
                        <th scope="col">{t('workload.columns.regular')}</th>
                    </tr>
                </thead>
                <tbody>
                    {people.map((row) => (
                        <tr key={row.person.id}>
                            <th scope="row" className="text-left font-normal">
                                <PersonCell row={row} />
                            </th>
                            <td>
                                <StatusChip status={row.status} />
                            </td>
                            <td>
                                <Load row={row} />
                            </td>
                            <td className="num">
                                <div className="flex flex-col">
                                    <Duration minutes={row.capacity_minutes} />
                                    <CapacityNote row={row} />
                                </div>
                            </td>
                            <td className="num">
                                <div className="flex flex-col">
                                    <Duration minutes={row.planned_minutes} />
                                    {row.planned_tasks > 0 && <span className="text-[13px] whitespace-nowrap text-muted">{t('workload.row.tasks', { count: row.planned_tasks })}</span>}
                                </div>
                            </td>
                            <td className="num">
                                <WithoutEstimate count={row.without_estimate} />
                            </td>
                            <td className="num">
                                <Duration minutes={row.logged_minutes} />
                            </td>
                            <td className="num">
                                <Duration minutes={row.regular_minutes} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function PeopleCards({ people }: { people: WorkloadRow[] }) {
    const t = useT();

    const item = (label: string, value: ReactNode, key: string) => (
        <div key={key} className="min-w-0">
            <dt className="text-[13px] text-muted">{label}</dt>
            <dd className="num m-0 font-semibold">{value}</dd>
        </div>
    );

    return (
        <ul className="m-0 flex list-none flex-col gap-3 p-0 lg:hidden">
            {people.map((row) => (
                <li key={row.person.id} className="card flex flex-col gap-3 px-4 py-3.5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <PersonCell row={row} />
                        <StatusChip status={row.status} />
                    </div>
                    <Load row={row} />
                    <dl className="m-0 grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm">
                        {item(
                            t('workload.columns.capacity'),
                            <span className="flex flex-col">
                                <Duration minutes={row.capacity_minutes} />
                                <CapacityNote row={row} />
                            </span>,
                            'capacity',
                        )}
                        {item(
                            t('workload.columns.planned'),
                            <span className="flex flex-col">
                                <Duration minutes={row.planned_minutes} />
                                {row.planned_tasks > 0 && <span className="text-[13px] font-normal text-muted">{t('workload.row.tasks', { count: row.planned_tasks })}</span>}
                            </span>,
                            'planned',
                        )}
                        {item(t('workload.columns.without_estimate'), <WithoutEstimate count={row.without_estimate} />, 'estimate')}
                        {item(t('workload.columns.logged'), <Duration minutes={row.logged_minutes} />, 'logged')}
                        {item(t('workload.columns.regular'), <Duration minutes={row.regular_minutes} />, 'regular')}
                    </dl>
                </li>
            ))}
        </ul>
    );
}
