import { OwlEyes } from '@/components/owl/OwlEyes';
import { formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { durationWords, monthLabel } from './dates';
import { overtimeIcon, overtimeTone } from './shifts';
import type { HistoryPageProps, OvertimeStatus } from './types';

interface Props {
    month: HistoryPageProps['month'];
    totals: HistoryPageProps['totals'];
    rules: HistoryPageProps['rules'];
    busy: boolean;
    webClock: boolean;
}

/**
 * The month recap is the focal point of Riwayat, so it takes the brow panel and the one large number: regular time.
 * Every value is a sum of the dates listed below it.
 */
export function MonthTotals({ month, totals, rules, busy, webClock }: Props) {
    const t = useT();
    const locale = useLocale();
    const label = monthLabel(month.value, locale);
    const duration = (minutes: number) => formatMinutes(minutes, locale);
    const counted = (key: string, count: number) => t(count === 1 ? `${key}_one` : key, { count });

    if (totals.days_worked === 0) {
        return (
            <section className={`brow flex flex-col gap-3 px-6 py-6 sm:flex-row sm:items-center sm:gap-5 sm:px-8 ${busy ? 'opacity-60' : ''}`} aria-labelledby="history-totals">
                <OwlEyes state="closed" size={64} />
                <div className="min-w-0">
                    <h2 id="history-totals" className="h2">
                        {month.value > month.current ? t('history.totals.empty_future') : t('history.totals.empty_title', { month: label })}
                    </h2>
                    <p className="m-0 mt-1.5 max-w-[64ch]">{webClock ? t('history.totals.empty_body') : t('history.totals.empty_body_desktop')}</p>
                </div>
            </section>
        );
    }

    const split: { status: OvertimeStatus; minutes: number }[] = [
        { status: 'approved', minutes: totals.overtime_approved_minutes },
        { status: 'pending', minutes: totals.overtime_pending_minutes },
        { status: 'rejected', minutes: totals.overtime_rejected_minutes },
    ];

    return (
        <section className={`brow px-6 py-6 sm:px-8 ${busy ? 'opacity-60' : ''}`} aria-labelledby="history-totals" aria-busy={busy}>
            <h2 id="history-totals" className="m-0 text-base font-semibold">
                {t('history.totals.heading', { month: label })}
            </h2>
            <p className="m-0 mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span className="display num text-[40px] text-heading">{duration(totals.regular_minutes)}</span>
                <span className="text-lg font-semibold">{t('history.totals.regular')}</span>
                <span className="num text-muted">{counted('history.totals.days_worked', totals.days_worked)}</span>
            </p>

            <dl className="m-0 mt-5 grid gap-x-8 gap-y-4 border-t border-[color-mix(in_srgb,var(--eye-brow)_22%,transparent)] pt-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]">
                <div className="flex flex-col gap-1.5">
                    <dt className="text-sm font-semibold">{t('history.totals.overtime')}</dt>
                    <dd className="m-0 flex flex-col gap-2">
                        <span className="num font-display text-[28px] leading-none font-bold text-heading">{duration(totals.overtime_minutes)}</span>
                        <span className="flex flex-wrap gap-2">
                            {split.map(({ status, minutes }) => {
                                const Icon = overtimeIcon[status];
                                return (
                                    <span key={status} className={`chip num ${overtimeTone[status]}`}>
                                        <Icon weight="bold" size={15} aria-hidden />
                                        {t(`history.totals.${status}`, { duration: duration(minutes) })}
                                    </span>
                                );
                            })}
                        </span>
                    </dd>
                </div>
                <Stat
                    label={t('history.totals.idle')}
                    value={duration(totals.idle_minutes)}
                    note={totals.has_web_shifts ? `${t('history.totals.idle_note')} ${t('history.totals.idle_note_web')}` : t('history.totals.idle_note')}
                />
                <Stat label={t('history.totals.short_days', { limit: durationWords(rules.regular_limit_minutes, locale) })} value={counted('history.totals.days', totals.short_days)} />
                <Stat label={t('history.totals.non_workdays')} value={counted('history.totals.days', totals.non_workdays_worked)} />
            </dl>

            {totals.has_live_shift && <p className="m-0 mt-4 text-sm">{t('history.totals.live')}</p>}
        </section>
    );
}

function Stat({ label, value, note }: { label: string; value: string; note?: string }) {
    return (
        <div className="flex flex-col gap-1.5">
            <dt className="text-sm font-semibold">{label}</dt>
            <dd className="m-0 flex flex-col gap-1">
                <span className="num font-display text-[28px] leading-none font-bold text-heading">{value}</span>
                {note && <span className="text-[13px]">{note}</span>}
            </dd>
        </div>
    );
}
