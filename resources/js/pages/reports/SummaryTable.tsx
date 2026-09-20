import { useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { Clock } from '@phosphor-icons/react';
import { useId } from 'react';
import { Count, Duration, MetricList, useMetrics } from './Figures';
import { reportHref } from './query';
import type { PersonRow, ReportGroup, Totals } from './types';

interface Props {
    groups: ReportGroup[];
    total: Totals;
    isStudio: boolean;
    month: string;
    monthName: string;
    team: number | null;
}

const NUMBER_COLUMNS = ['days_worked', 'regular', 'approved', 'pending', 'rejected', 'idle', 'short_days', 'non_workday', 'review', 'late_claims'] as const;

/**
 * Per person, grouped by team with a subtotal after each group. A table from xl up (ENERGY 1 inside the table),
 * stacked cards below it so nothing scrolls sideways on a phone.
 */
export function SummaryTable({ groups, total, isStudio, month, monthName, team }: Props) {
    const t = useT();
    const showTotal = groups.length > 1 || isStudio;
    const totalLabel = isStudio ? t('reports.table.total_studio') : t('reports.table.total_selection');
    const groupName = (group: ReportGroup) => group.team?.name ?? t('reports.table.no_team');

    return (
        <>
            <div className="table-wrap hidden xl:block">
                <table className="table num [&_td]:px-2.5 [&_th]:px-2.5">
                    <caption className="sr-only">{t('reports.table.caption', { month: monthName })}</caption>
                    <thead>
                        <tr>
                            <th scope="col" className="align-bottom">
                                {t('reports.columns.name')}
                            </th>
                            {NUMBER_COLUMNS.map((column) => (
                                <th key={column} scope="col" className="text-right align-bottom leading-tight whitespace-normal">
                                    {t(`reports.columns.${column}`)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    {groups.map((group) => (
                        <tbody key={group.team?.id ?? 'none'}>
                            <tr>
                                <th colSpan={NUMBER_COLUMNS.length + 1} scope="colgroup" className="border-b border-line bg-paper pt-4 text-left text-[14px] text-ink">
                                    {groupName(group)} <span className="font-normal text-muted">({t('reports.table.people', { count: group.people.length })})</span>
                                </th>
                            </tr>
                            {group.people.map((person) => (
                                <tr key={person.id}>
                                    <th scope="row" className="border-b border-line text-left text-sm font-normal whitespace-normal text-ink">
                                        <PersonLink person={person} month={month} team={team} />
                                    </th>
                                    <NumberCells totals={person} />
                                </tr>
                            ))}
                            <tr className="bg-paper font-semibold">
                                <th scope="row" className="border-b-0 text-left text-sm font-semibold whitespace-normal text-ink">
                                    {t('reports.table.subtotal', { team: groupName(group) })}
                                </th>
                                <NumberCells totals={group.subtotal} />
                            </tr>
                        </tbody>
                    ))}
                    {showTotal && (
                        <tfoot>
                            <tr className="font-bold">
                                <th scope="row" className="border-t-[1.5px] border-b-0 border-line-strong text-left text-sm font-bold whitespace-normal text-ink">
                                    {totalLabel} <span className="font-normal text-muted">({t('reports.table.people', { count: total.people })})</span>
                                </th>
                                <NumberCells totals={total} className="border-t-[1.5px] border-line-strong" />
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>

            <div className="flex flex-col gap-6 xl:hidden">
                {groups.map((group) => (
                    <GroupCards key={group.team?.id ?? 'none'} group={group} name={groupName(group)} month={month} team={team} />
                ))}
                {showTotal && (
                    <section className="rounded-md border-[1.5px] border-line-strong bg-surface p-4" aria-label={totalLabel}>
                        <TotalsCard title={totalLabel} totals={total} />
                    </section>
                )}
            </div>
        </>
    );
}

function NumberCells({ totals, className = '' }: { totals: Totals; className?: string }) {
    const cell = `text-right ${className}`;

    return (
        <>
            <td className={cell}>
                <Count value={totals.days_worked} />
            </td>
            <td className={cell}>
                <Duration minutes={totals.regular_minutes} />
            </td>
            <td className={cell}>
                <Duration minutes={totals.overtime_approved_minutes} />
            </td>
            <td className={cell}>
                <Duration minutes={totals.overtime_pending_minutes} />
            </td>
            <td className={cell}>
                <Duration minutes={totals.overtime_rejected_minutes} />
            </td>
            <td className={cell}>
                <Duration minutes={totals.idle_minutes} />
            </td>
            <td className={cell}>
                <Count value={totals.short_days} />
            </td>
            <td className={cell}>
                <Count value={totals.non_workday_shifts} />
            </td>
            <td className={cell}>
                <Count value={totals.review_shifts} flag />
            </td>
            <td className={cell}>
                <Count value={totals.late_claims} />
            </td>
        </>
    );
}

function PersonLink({ person, month, team, block = false }: { person: PersonRow; month: string; team: number | null; block?: boolean }) {
    const t = useT();

    return (
        <span className="flex flex-col items-start">
            <span className="flex flex-wrap items-center gap-x-2">
                <Link
                    href={reportHref({ bulan: month, tim: team, orang: person.id })}
                    className={`font-semibold text-ink underline decoration-muted underline-offset-4 hover:decoration-current ${block ? 'inline-flex min-h-11 items-center' : ''}`}
                >
                    {person.name}
                    <span className="sr-only">, {t('reports.table.open_detail')}</span>
                </Link>
                {person.status !== 'active' && <span className="text-[13px] text-muted">{t(`common.status.${person.status}`)}</span>}
            </span>
            {person.running_shifts > 0 && (
                <span className="flex items-center gap-1 text-[13px] text-muted">
                    <Clock weight="bold" size={13} aria-hidden />
                    {t('reports.table.running')}
                </span>
            )}
        </span>
    );
}

function GroupCards({ group, name, month, team }: { group: ReportGroup; name: string; month: string; team: number | null }) {
    const t = useT();
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3">
            <h2 id={headingId} className="m-0 text-[17px] font-semibold text-heading">
                {name} <span className="font-normal text-muted">({t('reports.table.people', { count: group.people.length })})</span>
            </h2>
            <ul className="m-0 grid list-none gap-3 p-0 md:grid-cols-2">
                {group.people.map((person) => (
                    <li key={person.id} className="card min-w-0 px-4 pt-2 pb-4">
                        <PersonLink person={person} month={month} team={team} block />
                        <PersonMetrics totals={person} />
                    </li>
                ))}
            </ul>
            <div className="rounded-md bg-panel p-4">
                <TotalsCard title={t('reports.table.subtotal', { team: name })} totals={group.subtotal} />
            </div>
        </section>
    );
}

function PersonMetrics({ totals }: { totals: Totals }) {
    const { main, flags } = useMetrics(totals);

    return (
        <>
            <MetricList metrics={main} className="mt-2" />
            <MetricList metrics={flags} className="mt-3 border-t border-line pt-3" />
        </>
    );
}

function TotalsCard({ title, totals }: { title: string; totals: Totals }) {
    const t = useT();

    return (
        <>
            <p className="m-0 font-semibold">
                {title} <span className="font-normal text-muted">({t('reports.table.people', { count: totals.people })})</span>
            </p>
            <PersonMetrics totals={totals} />
        </>
    );
}
