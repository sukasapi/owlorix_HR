import { ChartFrame, ColumnChart, type ColumnDatum, type Series } from '@/components/charts';
import { formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { KIND_COLORS, type DayCount, type Kind } from './types';

const KINDS: Kind[] = ['access', 'change', 'attendance'];

/** Records per studio day, stacked by kind in the order the timeline filter uses. */
export function ActivityChart({ days }: { days: DayCount[] }) {
    const t = useT();
    const locale = useLocale();

    const series: Series[] = KINDS.map((kind) => ({ key: kind, label: t(`activity-monitor.kinds.${kind}`), color: KIND_COLORS[kind] }));
    const data: ColumnDatum[] = days.map((day) => ({
        label: `${Number(day.date.slice(8, 10))}/${Number(day.date.slice(5, 7))}`,
        title: formatShortDate(day.date, locale),
        values: KINDS.map((kind) => day[kind]),
    }));
    const empty = days.every((day) => day.access + day.change + day.attendance === 0);

    return (
        <ChartFrame
            title={t('activity-monitor.chart.title')}
            subtitle={t('activity-monitor.chart.subtitle', { days: days.length })}
            series={series}
            empty={empty}
            emptyText={t('activity-monitor.chart.empty', { days: days.length })}
            table={{
                columns: [t('activity-monitor.chart.date'), ...series.map((s) => s.label)],
                rows: [...days].reverse().map((day) => [formatShortDate(day.date, locale), day.access, day.change, day.attendance]),
            }}
        >
            <ColumnChart data={data} series={series} label={t('activity-monitor.chart.label', { days: days.length })} />
        </ChartFrame>
    );
}
