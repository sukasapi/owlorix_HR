import { useWeekTargetText, WeekTargetBar } from '@/components/WeekTarget';
import { formatMinutes, formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { HistoryPageProps } from './types';

type Week = NonNullable<HistoryPageProps['weeks']>[number];

/** Each studio week of the month against the weekly target. Weeks are whole, so the first and last cross the month. */
export function WeekList({ weeks, busy }: { weeks: Week[]; busy: boolean }) {
    const t = useT();

    if (weeks.length === 0) return null;

    return (
        <section className={`card px-5 py-[18px] ${busy ? 'opacity-60' : ''}`} aria-labelledby="history-weeks">
            <h2 id="history-weeks" className="h2">
                {t('week-target.history_heading')}
            </h2>
            <p className="m-0 mt-1 max-w-[70ch] text-sm text-muted">{t('week-target.note')}</p>
            <ul className="m-0 mt-3 list-none divide-y divide-line p-0">
                {weeks.map((week) => (
                    <WeekRow key={week.week_start} week={week} />
                ))}
            </ul>
        </section>
    );
}

function WeekRow({ week }: { week: Week }) {
    const t = useT();
    const locale = useLocale();
    const text = useWeekTargetText(week);
    const range = t('week-target.range', { from: formatShortDate(week.week_start, locale), to: formatShortDate(week.week_end, locale) });

    const status =
        week.target_minutes === 0
            ? t('week-target.none_short')
            : week.short_minutes === 0
              ? t('week-target.done')
              : week.is_current
                ? t('week-target.short', { duration: formatMinutes(week.short_minutes, locale) })
                : t('week-target.short_past', { duration: formatMinutes(week.short_minutes, locale) });

    return (
        <li className="grid gap-x-6 gap-y-1.5 py-3 sm:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] sm:items-center">
            <div className="min-w-0">
                <p className="num m-0 font-semibold">{range}</p>
                {week.is_current && <p className="m-0 text-sm text-muted">{t('week-target.current')}</p>}
            </div>
            <div className="flex min-w-0 flex-col gap-1.5">
                {week.target_minutes > 0 && (
                    <>
                        <p className="num m-0 text-sm">{text}</p>
                        <WeekTargetBar week={week} />
                    </>
                )}
                <p className={`num m-0 text-sm ${week.short_minutes > 0 && !week.is_current ? 'font-semibold' : 'text-muted'}`}>{status}</p>
            </div>
        </li>
    );
}
