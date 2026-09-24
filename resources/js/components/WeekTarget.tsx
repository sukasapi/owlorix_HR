import { formatMinutes, formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { WeekTargetData } from '@/types';

/** The worked-against-target line, numbers always in the text so the bar is never the only signal. */
export function useWeekTargetText(week: WeekTargetData): string {
    const t = useT();
    const locale = useLocale();
    const worked = formatMinutes(week.worked_minutes, locale);
    const target = formatMinutes(week.target_minutes, locale);

    return week.kind === 'intern'
        ? t('week-target.intern_text', { attended: week.attended_days, days: week.target_days ?? 0, worked, target })
        : t('week-target.hours_text', { worked, target });
}

/** One line for lists (Tim hari ini): the week so far and what is left, no bar. */
export function WeekTargetLine({ week, className = '' }: { week: WeekTargetData; className?: string }) {
    const t = useT();
    const locale = useLocale();
    const worked = formatMinutes(week.worked_minutes, locale);
    const target = formatMinutes(week.target_minutes, locale);

    if (week.target_minutes === 0) return null;

    const main =
        week.kind === 'intern'
            ? t('week-target.compact_intern', { attended: week.attended_days, days: week.target_days ?? 0, worked, target })
            : t('week-target.compact', { worked, target });

    return (
        <p className={`num m-0 text-[13px] text-muted ${className}`}>
            {main}
            {week.short_minutes > 0 ? `, ${t('week-target.compact_short', { duration: formatMinutes(week.short_minutes, locale) })}` : `, ${t('week-target.compact_done')}`}
        </p>
    );
}

/** Same bar as the daily one on Hari ini, so the two read as one scale: filled means done, never a warning color. */
export function WeekTargetBar({ week, className = '' }: { week: WeekTargetData; className?: string }) {
    const t = useT();
    const text = useWeekTargetText(week);
    const percent = week.target_minutes > 0 ? Math.min(100, Math.round((week.worked_minutes / week.target_minutes) * 100)) : 0;

    return (
        <div
            className={`h-2.5 overflow-hidden rounded-md border border-[color-mix(in_srgb,var(--eye-brow)_35%,transparent)] bg-[color-mix(in_srgb,var(--eye-brow)_18%,transparent)] ${className}`}
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={week.target_minutes}
            aria-valuenow={Math.min(week.worked_minutes, week.target_minutes)}
            aria-valuetext={text}
            aria-label={t('week-target.label')}
        >
            <span className="block h-full rounded-md bg-[var(--eye-brow)]" style={{ width: `${percent}%` }} />
        </div>
    );
}

/** Hari ini: the week so far, what is left, and how the target was set. */
export function WeekTargetCard({ week }: { week: WeekTargetData }) {
    const t = useT();
    const locale = useLocale();
    const text = useWeekTargetText(week);
    const basis =
        week.kind === 'intern'
            ? t('week-target.intern_basis', { days: week.target_days ?? 0, per_day: formatMinutes(week.minutes_per_day ?? 0, locale) })
            : t(week.target_minutes < week.full_target_minutes ? 'week-target.hours_basis_reduced' : 'week-target.hours_basis', {
                  target: formatMinutes(week.target_minutes, locale),
                  days: week.available_days,
              });

    return (
        <>
            <div>
                <h2 id="week-target" className="h2">
                    {t('week-target.heading')}
                </h2>
                <p className="num m-0 text-sm text-muted">
                    {t('week-target.range', { from: formatShortDate(week.week_start, locale), to: formatShortDate(week.week_end, locale) })}
                </p>
            </div>
            {week.target_minutes === 0 ? (
                <p className="m-0">{t('week-target.none')}</p>
            ) : (
                <>
                    <p className="num m-0 font-semibold">{text}</p>
                    <WeekTargetBar week={week} />
                    <p className="num m-0">
                        {week.short_minutes === 0 ? t('week-target.done') : t('week-target.short', { duration: formatMinutes(week.short_minutes, locale) })}
                    </p>
                    <p className="m-0 text-sm text-muted">{basis}</p>
                </>
            )}
            <p className="m-0 text-[13px] text-muted">{t('week-target.note')}</p>
        </>
    );
}
