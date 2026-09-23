import AppShell from '@/layouts/AppShell';
import { useChosenFilters } from '@/lib/filterInput';
import { useLocale, useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { Warning } from '@phosphor-icons/react';
import { type ReactNode, useId } from 'react';
import { useListVisit } from '../admin/people/useListVisit';
import { EmptyCard, ErrorCard, longDate } from './bits';
import { DueMilestones, DueTasks } from './DueLists';
import type { WorkMonitorProps } from './types';
import { BudgetsChart, HoursChart, ReviewCard, StagesChart, StatusChart, ThroughputChart } from './WorkCharts';

function workHref(project: number | null, weeks: number): string {
    const query: Record<string, number> = {};
    if (project !== null) query.proyek = project;
    if (weeks !== 4) query.minggu = weeks;
    return route('monitoring.work', query);
}

/**
 * Monitor kerja. The page reads top to bottom in the order of its questions (docs/14 5.1): what is late (the
 * overdue count is the one gold tile, then the due lists), whether work is finished as fast as it arrives, where it
 * piles up, where the hours go, and for budget holders whether the budgets hold.
 */
export default function Work(props: WorkMonitorProps) {
    const { period, filters, options, scope, status } = props;
    const t = useT();
    const locale = useLocale();
    const ids = { project: useId(), weeks: useId() };
    const list = useListVisit(new URL(route('monitoring.work'), window.location.origin).pathname);

    const [chosen, setChosen] = useChosenFilters(filters);

    const apply = (project: number | null, weeks: number) => {
        setChosen({ ...chosen, project, weeks });
        router.get(workHref(project, weeks), {}, { preserveScroll: true, preserveState: true, replace: true });
    };
    const hasTasks = status.rows.length > 0;

    return (
        <AppShell title={t('work-monitor.title')}>
            <div className="flex flex-col gap-5">
                <header className="flex flex-col gap-1.5">
                    <h1 className="h1">{t('work-monitor.title')}</h1>
                    <p className="m-0 max-w-[70ch] text-muted">{scope.studio ? t('work-monitor.lead_studio') : t('work-monitor.lead_scope')}</p>
                </header>

                {scope.empty_reason !== null ? (
                    <EmptyCard title={t('work-monitor.states.no_scope_title')} body={t('work-monitor.states.no_scope_body')} />
                ) : (
                    <>
                        <form
                            role="search"
                            aria-label={t('work-monitor.filters.label')}
                            className="grid gap-3 min-[520px]:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] lg:max-w-[720px]"
                            onSubmit={(e) => e.preventDefault()}
                        >
                            <div className="field min-w-0">
                                <label htmlFor={ids.project} className="label">
                                    {t('work-monitor.filters.project')}
                                </label>
                                <select
                                    id={ids.project}
                                    className="input"
                                    value={chosen.project ?? ''}
                                    onChange={(e) => apply(e.target.value === '' ? null : Number(e.target.value), chosen.weeks)}
                                >
                                    <option value="">{t('work-monitor.filters.project_all')}</option>
                                    {options.projects.map((project) => (
                                        <option key={project.id} value={project.id}>
                                            {project.code ? `${project.name} (${project.code})` : project.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="field min-w-0">
                                <label htmlFor={ids.weeks} className="label">
                                    {t('work-monitor.filters.period')}
                                </label>
                                <select id={ids.weeks} className="input" value={chosen.weeks} onChange={(e) => apply(chosen.project, Number(e.target.value))}>
                                    {options.weeks.map((weeks) => (
                                        <option key={weeks} value={weeks}>
                                            {t('work-monitor.filters.weeks', { count: weeks })}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </form>

                        <div className="-mt-2 flex flex-col gap-0.5 text-sm text-muted">
                            <p className="num m-0">{t('work-monitor.filters.period_text', { from: longDate(period.from, locale), today: longDate(period.until, locale) })}</p>
                            <p className="m-0">{t('work-monitor.not_counted')}</p>
                        </div>

                        <p role="status" aria-live="polite" className="-my-2 min-h-[22px] text-sm font-semibold">
                            {list.state === 'loading' ? t('work-monitor.states.loading') : null}
                        </p>

                        {list.state === 'error' ? (
                            <ErrorCard title={t('work-monitor.states.error_title')} body={t('work-monitor.states.error_body')} onRetry={list.retry} />
                        ) : (
                            <div className={`flex flex-col gap-5 ${list.state === 'loading' ? 'opacity-60 transition-opacity' : 'transition-opacity'}`} aria-busy={list.state === 'loading'}>
                                <Content {...props} hasTasks={hasTasks} onShowAll={() => apply(null, chosen.weeks)} />
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppShell>
    );
}

function Content({ hasTasks, onShowAll, ...props }: WorkMonitorProps & { hasTasks: boolean; onShowAll: () => void }) {
    const t = useT();
    const { period, filters, headline } = props;
    const weeks = period.weeks;

    // Hours and milestones can exist before any task does, so they stay visible under the empty card
    const hoursRow = (
        <div className={`grid gap-5 ${props.budgets ? 'lg:grid-cols-2' : ''}`}>
            <HoursChart weeks={weeks} rows={props.hours} />
            {props.budgets && <BudgetsChart rows={props.budgets} />}
        </div>
    );

    if (!hasTasks) {
        return (
            <>
                <EmptyCard title={t('work-monitor.states.no_tasks_title')} body={filters.project ? t('work-monitor.states.no_tasks_project_body') : t('work-monitor.states.no_tasks_body')}>
                    {filters.project !== null && (
                        <button type="button" className="btn btn-secondary" onClick={onShowAll}>
                            {t('work-monitor.states.show_all')}
                        </button>
                    )}
                </EmptyCard>
                <DueMilestones milestones={props.milestones} />
                {hoursRow}
            </>
        );
    }

    return (
        <>
            <section aria-label={t('work-monitor.headline.label')}>
                <dl className="m-0 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Tile label={t('work-monitor.headline.overdue')} value={headline.overdue} help={headline.overdue > 0 ? t('work-monitor.headline.overdue_help') : t('work-monitor.headline.overdue_help_none')} attention={headline.overdue > 0} />
                    <Tile label={t('work-monitor.headline.open')} value={headline.open} help={t('work-monitor.headline.open_help')} />
                    <Tile label={t('work-monitor.headline.in_review')} value={headline.in_review} help={t('work-monitor.headline.in_review_help')} />
                    <Tile label={t('work-monitor.headline.done', { weeks })} value={headline.done_in_period} help={t('work-monitor.headline.done_help')} />
                </dl>
            </section>

            <div className="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:items-start">
                <DueTasks due={props.due} />
                <DueMilestones milestones={props.milestones} />
            </div>

            <div className="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:items-start">
                <ThroughputChart weeks={weeks} rows={props.throughput} />
                <ReviewCard weeks={weeks} review={props.review} />
            </div>

            <StatusChart by={props.status.by} rows={props.status.rows} />
            {props.stages !== null && <StagesChart rows={props.stages} />}
            {hoursRow}
        </>
    );
}

/**
 * One headline number. The overdue tile is the page's one gold accent when something is late, with an icon, so
 * the attention does not rest on color alone.
 */
function Tile({ label, value, help, attention = false }: { label: string; value: number; help: ReactNode; attention?: boolean }) {
    return (
        <div className={`flex min-w-0 flex-col gap-1 rounded-[12px] border px-4 py-3.5 ${attention ? 'border-gold bg-gold-tint' : 'border-line bg-surface'}`}>
            <dt className="flex items-center gap-1.5 text-sm font-semibold">
                {attention && <Warning weight="bold" size={16} aria-hidden />}
                {label}
            </dt>
            <dd className="m-0 flex flex-col gap-1">
                <span className="font-display text-[40px] leading-none font-bold text-heading">{value}</span>
                <span className="text-[13px] text-muted">{help}</span>
            </dd>
        </div>
    );
}

