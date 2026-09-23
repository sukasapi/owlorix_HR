import { BarList, type BarRow, ChartFrame, LineChart, type Series } from '@/components/charts';
import { formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Warning } from '@phosphor-icons/react';
import { formatHours } from '../projects/BudgetMeter';
import { dayMonth } from './bits';
import type { BudgetRow, Buckets, HoursRow, StageRow, StatusRow, WorkMonitorProps } from './types';

const BUCKETS: (keyof Buckets)[] = ['todo', 'doing', 'review', 'done'];

/** The four task states in order, on the one teal ramp: lightest is not started, darkest is done. */
function useBucketSeries(): Series[] {
    const t = useT();
    return BUCKETS.map((key, i) => ({ key, label: t(`work-monitor.status.${key}`), color: `var(--viz-step-${i + 1})` }));
}

const total = (row: Buckets) => row.todo + row.doing + row.review + row.done;

/** Created against done per week: two lines, so a widening gap shows work piling up. */
export function ThroughputChart({ weeks, rows }: { weeks: number; rows: WorkMonitorProps['throughput'] }) {
    const t = useT();
    const locale = useLocale();
    const categories = rows.map((r) => ({
        label: `${Number(r.week.slice(8, 10))}/${Number(r.week.slice(5, 7))}`,
        title: t('work-monitor.throughput.week_of', { date: dayMonth(r.week, locale) }),
    }));
    const series = [
        { key: 'created', label: t('work-monitor.throughput.created'), color: 'var(--viz-1)', values: rows.map((r) => r.created) },
        { key: 'done', label: t('work-monitor.throughput.done'), color: 'var(--viz-2)', values: rows.map((r) => r.done) },
    ];

    return (
        <ChartFrame
            title={t('work-monitor.throughput.title')}
            subtitle={t('work-monitor.throughput.subtitle')}
            series={series}
            empty={rows.every((r) => r.created === 0 && r.done === 0)}
            emptyText={t('work-monitor.throughput.empty', { weeks })}
            table={{
                columns: [t('work-monitor.throughput.week'), t('work-monitor.throughput.created'), t('work-monitor.throughput.done')],
                rows: rows.map((r) => [dayMonth(r.week, locale), r.created, r.done]),
            }}
        >
            <LineChart categories={categories} series={series} label={t('work-monitor.throughput.label')} />
        </ChartFrame>
    );
}

export function StatusChart({ by, rows }: WorkMonitorProps['status']) {
    const t = useT();
    const series = useBucketSeries();
    const byLabel = t(`work-monitor.status.by_${by}`);
    const bars: BarRow[] = rows.map((row: StatusRow) => ({
        key: row.id,
        label: row.name,
        sub: row.code ?? undefined,
        href: row.href,
        values: BUCKETS.map((b) => row[b]),
        end: t('work-monitor.status.end', { done: row.done, total: total(row) }),
    }));

    return (
        <ChartFrame
            title={t(`work-monitor.status.title_${by}`)}
            subtitle={t('work-monitor.status.subtitle', { by: byLabel })}
            series={series}
            empty={rows.length === 0}
            emptyText={t('work-monitor.status.empty')}
            table={{
                columns: [t('work-monitor.status.name'), ...series.map((s) => s.label)],
                rows: rows.map((r) => [r.name, ...BUCKETS.map((b) => r[b])]),
            }}
        >
            <BarList rows={bars} series={series} label={t('work-monitor.status.label', { by: byLabel })} />
        </ChartFrame>
    );
}

export function StagesChart({ rows }: { rows: StageRow[] }) {
    const t = useT();
    const series = useBucketSeries();
    const name = (row: StageRow) => row.name ?? t('work-monitor.stages.none');
    const bars: BarRow[] = rows.map((row) => ({
        key: row.id ?? 'none',
        label: name(row),
        sub: row.phase ? t(`pipeline.phase.${row.phase}`) : undefined,
        values: BUCKETS.map((b) => row[b]),
        end: t('work-monitor.status.end', { done: row.done, total: total(row) }),
    }));

    return (
        <ChartFrame
            title={t('work-monitor.stages.title')}
            subtitle={t('work-monitor.stages.subtitle')}
            series={series}
            empty={rows.length === 0}
            emptyText={t('work-monitor.stages.empty')}
            table={{
                columns: [t('work-monitor.stages.stage'), ...series.map((s) => s.label)],
                rows: rows.map((r) => [name(r), ...BUCKETS.map((b) => r[b])]),
            }}
        >
            <BarList rows={bars} series={series} label={t('work-monitor.stages.label')} />
        </ChartFrame>
    );
}

/** Hours in the period per project, one series: the length is the answer, so one color. */
export function HoursChart({ weeks, rows }: { weeks: number; rows: HoursRow[] }) {
    const t = useT();
    const locale = useLocale();
    const hours = (minutes: number) => formatHours(minutes, locale);
    const series: Series[] = [{ key: 'hours', label: t('work-monitor.hours.series'), color: 'var(--viz-1)' }];

    return (
        <ChartFrame
            title={t('work-monitor.hours.title')}
            subtitle={t('work-monitor.hours.subtitle', { weeks })}
            series={series}
            empty={rows.length === 0}
            emptyText={t('work-monitor.hours.empty', { weeks })}
            table={{ columns: [t('work-monitor.hours.project'), t('work-monitor.hours.series')], rows: rows.map((r) => [r.name, hours(r.minutes)]) }}
        >
            <BarList
                rows={rows.map((r) => ({ key: r.id, label: r.name, sub: r.code ?? undefined, href: r.href, values: [r.minutes] }))}
                series={series}
                format={hours}
                label={t('work-monitor.hours.label')}
            />
        </ChartFrame>
    );
}

/**
 * Hours used to date against the budget (budget holders only). Both sides cover the whole project life, so the bar
 * and the budget tick are on one honest scale. The end text states the numbers; past the budget it says by how much.
 */
export function BudgetsChart({ rows }: { rows: BudgetRow[] }) {
    const t = useT();
    const locale = useLocale();
    const hours = (minutes: number) => formatHours(minutes, locale);
    const series: Series[] = [{ key: 'used', label: t('work-monitor.budgets.series'), color: 'var(--viz-1)' }];

    return (
        <ChartFrame
            title={t('work-monitor.budgets.title')}
            subtitle={t('work-monitor.budgets.subtitle')}
            series={series}
            empty={rows.length === 0}
            emptyText={t('work-monitor.budgets.empty')}
            table={{
                columns: [t('work-monitor.budgets.project'), t('work-monitor.budgets.series'), t('work-monitor.budgets.marker')],
                rows: rows.map((r) => [r.name, hours(r.logged_minutes), hours(r.budget_minutes)]),
            }}
        >
            <BarList
                rows={rows.map((r) => ({
                    key: r.id,
                    label: r.name,
                    sub: r.code ?? undefined,
                    href: r.href,
                    values: [r.logged_minutes],
                    marker: r.budget_minutes,
                    end:
                        r.logged_minutes > r.budget_minutes ? (
                            <span className="inline-flex items-center gap-1 text-danger">
                                <Warning weight="bold" size={14} aria-hidden />
                                {t('work-monitor.budgets.over', { over: hours(r.logged_minutes - r.budget_minutes) })}
                            </span>
                        ) : (
                            t('work-monitor.budgets.end', { logged: hours(r.logged_minutes), budget: hours(r.budget_minutes) })
                        ),
                }))}
                series={series}
                format={hours}
                markerLabel={t('work-monitor.budgets.marker')}
                label={t('work-monitor.budgets.label')}
            />
        </ChartFrame>
    );
}

/** Two numbers, each shown with its base; no chart, because a ratio and a median read best as figures. */
export function ReviewCard({ weeks, review }: { weeks: number; review: WorkMonitorProps['review'] }) {
    const t = useT();
    const locale = useLocale();
    const share = review.reviewed > 0 ? Math.round((review.changes_requested / review.reviewed) * 100) : 0;

    return (
        <section className="card flex min-w-0 flex-col gap-3 p-4 sm:p-5" aria-labelledby="review-quality-title">
            <div className="flex flex-col gap-1">
                <h2 id="review-quality-title" className="h2 text-[17px]">
                    {t('work-monitor.review.title')}
                </h2>
                <p className="m-0 text-sm text-muted">{t('work-monitor.review.subtitle', { weeks })}</p>
            </div>
            {review.reviewed === 0 ? (
                <p className="m-0 py-6 text-sm text-muted">{t('work-monitor.review.empty', { weeks })}</p>
            ) : (
                <dl className="m-0 grid gap-5 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                    <div className="min-w-0">
                        <dt className="text-sm font-semibold">{t('work-monitor.review.retake')}</dt>
                        <dd className="m-0 mt-1">
                            <span className="font-display text-[32px] leading-none font-bold text-heading">{t('work-monitor.review.retake_share', { percent: share })}</span>
                            <span className="num mt-1.5 block text-sm text-muted">{t('work-monitor.review.retake_text', { count: review.changes_requested, total: review.reviewed })}</span>
                        </dd>
                    </div>
                    {review.median_wait_minutes !== null && (
                        <div className="min-w-0">
                            <dt className="text-sm font-semibold">{t('work-monitor.review.wait')}</dt>
                            <dd className="m-0 mt-1">
                                <span className="font-display text-[32px] leading-none font-bold text-heading">{formatMinutes(review.median_wait_minutes, locale)}</span>
                                <span className="mt-1.5 block text-sm text-muted">{t('work-monitor.review.wait_help')}</span>
                            </dd>
                        </div>
                    )}
                </dl>
            )}
        </section>
    );
}
