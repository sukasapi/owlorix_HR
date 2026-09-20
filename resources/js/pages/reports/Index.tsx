import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link, router } from '@inertiajs/react';
import { ArrowCounterClockwise, CaretLeft, CaretRight, HourglassMedium } from '@phosphor-icons/react';
import { type ReactNode, useId } from 'react';
import { ExportButton } from './ExportButton';
import { PersonDetail } from './PersonDetail';
import { exportHref, monthLabel, reportHref } from './query';
import { SummaryTable } from './SummaryTable';
import type { ReportsPageProps } from './types';
import { useReportVisit } from './useReportVisit';

/**
 * Laporan. The per-person table is the focal point; month, team and the export sit above it, the notes that change
 * how the numbers are read sit between. Picking a name swaps the table for that person's day-by-day breakdown.
 */
export default function ReportsIndex({ month, filters, teams, scope, report, detail, generated_at, can, links }: ReportsPageProps) {
    const t = useT();
    const locale = useLocale();
    const teamSelectId = useId();
    const monthHeadingId = useId();
    const { visit, retry } = useReportVisit();

    const monthName = monthLabel(month.value, locale);
    const team = teams.find((item) => item.id === filters.team) ?? null;
    const totals = detail?.person ?? report?.total ?? null;
    const pending = totals?.pending_shifts ?? 0;
    const loadingMonth = visit.state === 'idle' ? monthName : monthLabel(new URL(visit.href).searchParams.get('bulan') ?? month.value, locale);

    const exportLabel = team ? t('reports.export.button_team', { team: team.name, month: monthName }) : t('reports.export.button', { month: monthName });

    return (
        <AppShell title={t('reports.title')}>
            <div className="flex flex-col gap-5">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1.5">
                        <h1 className="h1">{t('reports.title')}</h1>
                        <p className="m-0 max-w-[68ch] text-muted">{scope === 'everyone' ? t('reports.lead_everyone') : t('reports.lead_led_teams')}</p>
                    </div>
                    {can.export && <ExportButton href={exportHref({ bulan: month.value, tim: filters.team })} label={exportLabel} />}
                </header>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                    <nav aria-label={t('reports.month.nav_label')} className="flex items-center gap-2">
                        <Link href={reportHref({ bulan: month.previous, tim: filters.team, orang: filters.person })} className="btn btn-secondary px-3" aria-label={t('reports.month.previous')} preserveScroll>
                            <CaretLeft weight="bold" size={18} aria-hidden />
                        </Link>
                        <h2 id={monthHeadingId} className="m-0 min-w-[9.5ch] text-center font-display text-[24px] leading-tight font-bold text-heading sm:min-w-[11ch] sm:text-[28px]">
                            {monthName}
                        </h2>
                        {month.next ? (
                            <Link href={reportHref({ bulan: month.next, tim: filters.team, orang: filters.person })} className="btn btn-secondary px-3" aria-label={t('reports.month.next')} preserveScroll>
                                <CaretRight weight="bold" size={18} aria-hidden />
                            </Link>
                        ) : (
                            // Keeps the month centred between the arrows; there is nothing to report after this month yet
                            <span aria-hidden className="invisible inline-block w-11" />
                        )}
                    </nav>

                    {month.value !== month.current && (
                        <Link href={reportHref({ bulan: month.current, tim: filters.team, orang: filters.person })} className="btn btn-quiet" preserveScroll>
                            {t('reports.month.this_month')}
                        </Link>
                    )}

                    {!detail && teams.length > (scope === 'everyone' ? 0 : 1) && (
                        <div className="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                            <label htmlFor={teamSelectId} className="label flex-none">
                                {t('reports.filters.team_label')}
                            </label>
                            <select
                                id={teamSelectId}
                                className="input min-w-0 font-semibold sm:w-auto"
                                value={filters.team ?? ''}
                                onChange={(event) => router.get(reportHref({ bulan: month.value, tim: event.target.value ? Number(event.target.value) : null }), {}, { preserveScroll: true, preserveState: true })}
                            >
                                <option value="">{scope === 'everyone' ? t('reports.filters.team_all_everyone') : t('reports.filters.team_all_led')}</option>
                                {teams.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                <p role="status" aria-live="polite" className="-my-2 min-h-[22px] text-sm font-semibold">
                    {visit.state === 'loading' ? t('reports.states.loading', { month: loadingMonth }) : null}
                </p>

                {visit.state === 'error' && (
                    <Notice tone="danger">
                        <span>{t('reports.states.error', { month: loadingMonth })}</span>{' '}
                        <button type="button" className="btn btn-secondary btn-sm mt-2 bg-surface text-ink sm:mt-0 sm:ml-2" onClick={retry}>
                            <ArrowCounterClockwise weight="bold" size={16} aria-hidden />
                            {t('reports.states.retry')}
                        </button>
                    </Notice>
                )}

                {pending > 0 && !month.is_future && (
                    <div role="status" className="flex items-start gap-2.5 rounded-md border-[1.5px] border-gold bg-gold-tint px-4 py-3 text-sm text-ink">
                        <HourglassMedium weight="bold" size={18} className="mt-px flex-none" aria-hidden />
                        <p className="m-0">
                            {t('reports.notes.pending', { count: pending })}{' '}
                            {links.approvals && (
                                <Link href={links.approvals} className="link font-semibold">
                                    {t('reports.notes.open_approvals')}
                                </Link>
                            )}
                        </p>
                    </div>
                )}

                {!month.is_future && <ReadingNotes month={month} monthName={monthName} generatedAt={generated_at} shared={report?.shared_people ?? false} />}

                <div className={visit.state === 'loading' ? 'opacity-60 transition-opacity' : 'transition-opacity'} aria-busy={visit.state === 'loading'}>
                    {month.is_future ? (
                        <EmptyCard title={t('reports.states.future_title', { month: monthName })} body={t('reports.states.future_body')}>
                            <Link href={reportHref({ bulan: month.current, tim: filters.team, orang: filters.person })} className="btn btn-secondary">
                                {t('reports.month.this_month')}
                            </Link>
                        </EmptyCard>
                    ) : detail ? (
                        <PersonDetail person={detail.person} shifts={detail.shifts} month={month.value} monthName={monthName} team={filters.team} />
                    ) : report && report.groups.length > 0 ? (
                        <div className="flex flex-col gap-4">
                            {report.total.days_worked === 0 && <Notice>{t('reports.states.no_shifts', { month: monthName })}</Notice>}
                            <SummaryTable groups={report.groups} total={report.total} isStudio={report.is_studio} month={month.value} monthName={monthName} team={filters.team} />
                        </div>
                    ) : scope === 'led_teams' && teams.length === 0 ? (
                        <EmptyCard title={t('reports.states.no_teams_title')} body={t('reports.states.no_teams_body')} />
                    ) : (
                        <EmptyCard title={t('reports.states.no_people_title')} body={filters.team ? t('reports.states.no_people_team_body') : t('reports.states.no_people_body')}>
                            {filters.team && (
                                <Link href={reportHref({ bulan: month.value })} className="btn btn-secondary">
                                    {t('reports.states.show_all_teams')}
                                </Link>
                            )}
                        </EmptyCard>
                    )}
                </div>

                {!month.is_future && (report?.groups.length || detail) ? <ColumnLegend /> : null}
            </div>
        </AppShell>
    );
}

function EmptyCard({ title, body, children }: { title: string; body: string; children?: ReactNode }) {
    return (
        <section className="card flex flex-col items-start gap-3 px-5 py-6">
            <h2 className="h2">{title}</h2>
            <p className="m-0 max-w-[60ch] text-muted">{body}</p>
            {children}
        </section>
    );
}

function ReadingNotes({ month, monthName, generatedAt, shared }: { month: ReportsPageProps['month']; monthName: string; generatedAt: string; shared: boolean }) {
    const t = useT();
    const locale = useLocale();
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3 rounded-md bg-panel px-4 py-3.5 text-sm">
            <h2 id={headingId} className="m-0 text-[15px] font-semibold">
                {t('reports.notes.heading')}
            </h2>
            <ul className="m-0 flex list-disc flex-col gap-1 pl-5">
                {month.is_current && <li className="font-semibold">{t('reports.notes.running_month', { month: monthName, time: formatTime(generatedAt, locale) })}</li>}
                <li>{t('reports.notes.idle')}</li>
                <li>{t('reports.notes.rejected')}</li>
                <li>{t('reports.notes.timezone')}</li>
                {shared && <li>{t('reports.notes.shared')}</li>}
            </ul>
        </section>
    );
}

function ColumnLegend() {
    const t = useT();

    return (
        <details className="text-sm">
            <summary className="inline-flex min-h-11 cursor-pointer items-center rounded-sm font-semibold text-teal-text underline underline-offset-4">{t('reports.legend.toggle')}</summary>
            <ul className="m-0 mt-1 flex max-w-[80ch] list-disc flex-col gap-1 pl-5 text-muted">
                {(['days_worked', 'regular', 'overtime', 'idle', 'short_days', 'non_workday', 'review', 'late_claims'] as const).map((key) => (
                    <li key={key}>{t(`reports.legend.${key}`)}</li>
                ))}
            </ul>
        </details>
    );
}
