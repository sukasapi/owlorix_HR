import { VisitLink } from '@/components/ui/VisitLink';
import AppShell from '@/layouts/AppShell';
import { useLocale, useT } from '@/lib/i18n';
import { router, usePage } from '@inertiajs/react';
import { ArrowCounterClockwise, CalendarX, CaretLeft, CaretRight, CheckCircle, CloudSlash, HourglassMedium, XCircle } from '@phosphor-icons/react';
import { useId, useState } from 'react';
import { DayDialog } from './DayDialog';
import { monthLabel } from './dates';
import { MonthGrid } from './MonthGrid';
import { MonthList } from './MonthList';
import { MonthTotals } from './MonthTotals';
import { WeekList } from './WeekList';
import type { HistoryPageProps } from './types';

type MonthLoad = { state: 'idle' } | { state: 'loading' | 'error'; month: string };

/** `?tanggal=YYYY-MM-DD` (used by links from Lembur) opens that date when it has shifts. */
function initialDate(url: string, days: HistoryPageProps['days']): string | null {
    const value = new URLSearchParams(url.split('?')[1] ?? '').get('tanggal');
    return days.some((d) => d.date === value && d.shifts.length > 0) ? value : null;
}

/**
 * Riwayat. The month recap is the focal point; below it the month as a table on wide screens and a list of dates on
 * phones and tablets. A date with shifts opens its timeline. Everything is the signed-in person's own data.
 */
export default function HistoryIndex({ month, totals, days, weeks, web_clock_in_enabled: webClock, rules }: HistoryPageProps) {
    const t = useT();
    const locale = useLocale();
    const { url } = usePage();
    const monthHeadingId = useId();
    const [selected, setSelected] = useState<string | null>(() => initialDate(url, days));
    const [load, setLoad] = useState<MonthLoad>({ state: 'idle' });

    const selectedDay = days.find((d) => d.date === selected) ?? null;
    const monthHref = (value: string) => route('history', { bulan: value });

    const visitCallbacks = (target: string) => ({
        preserveScroll: true,
        onStart: () => {
            setSelected(null);
            setLoad({ state: 'loading', month: target });
        },
        onSuccess: () => setLoad({ state: 'idle' }),
        onHttpException: () => {
            setLoad({ state: 'error', month: target });
            return false;
        },
        onNetworkError: () => {
            setLoad({ state: 'error', month: target });
            return false;
        },
    });

    const busy = load.state === 'loading';

    return (
        <AppShell title={t('history.title')}>
            <div className="flex flex-col gap-6">
                <header className="flex flex-col gap-1.5">
                    <h1 className="h1">{t('history.title')}</h1>
                    <p className="m-0 max-w-[68ch] text-muted">{t('history.lead')}</p>
                </header>

                <section aria-labelledby={monthHeadingId} className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                        <nav aria-label={t('history.month.nav_label')} className="flex items-center gap-2">
                            <VisitLink href={monthHref(month.previous)} className="btn btn-secondary px-3" aria-label={t('history.month.previous')} options={visitCallbacks(month.previous)}>
                                <CaretLeft weight="bold" size={18} aria-hidden />
                            </VisitLink>
                            <h2 id={monthHeadingId} className="m-0 min-w-[9.5ch] text-center font-display text-[24px] leading-tight font-bold text-heading sm:min-w-[11ch] sm:text-[28px]" aria-live="polite">
                                {monthLabel(month.value, locale)}
                            </h2>
                            {month.next ? (
                                <VisitLink href={monthHref(month.next)} className="btn btn-secondary px-3" aria-label={t('history.month.next')} options={visitCallbacks(month.next)}>
                                    <CaretRight weight="bold" size={18} aria-hidden />
                                </VisitLink>
                            ) : (
                                // Nothing is recorded ahead of the current month; the slot keeps the month name centered
                                <span aria-hidden className="invisible inline-block w-[46px]" />
                            )}
                        </nav>
                        {month.value !== month.current && (
                            <VisitLink href={monthHref(month.current)} className="btn btn-quiet" options={visitCallbacks(month.current)}>
                                {t('history.month.this_month')}
                            </VisitLink>
                        )}
                        {busy && (
                            <p className="m-0 text-[14px] font-semibold" role="status">
                                {t('history.month.loading', { month: monthLabel(load.month, locale) })}
                            </p>
                        )}
                    </div>

                    {load.state === 'error' ? (
                        <div className="card flex flex-col items-center gap-3 px-5 py-10 text-center" role="alert">
                            <CloudSlash weight="bold" size={40} className="text-danger" aria-hidden />
                            <h3 className="h2">{t('history.error.title', { month: monthLabel(load.month, locale) })}</h3>
                            <p className="m-0 max-w-[52ch]">{t('history.error.body')}</p>
                            <button type="button" className="btn btn-primary" onClick={() => router.get(monthHref(load.month), {}, visitCallbacks(load.month))}>
                                <ArrowCounterClockwise weight="bold" size={18} aria-hidden />
                                {t('history.error.retry')}
                            </button>
                        </div>
                    ) : (
                        <>
                            <MonthTotals month={month} totals={totals} rules={rules} busy={busy} webClock={webClock} />

                            <Legend />

                            <div className="hidden xl:block">
                                <MonthGrid days={days} labelledBy={monthHeadingId} busy={busy} onSelect={setSelected} />
                            </div>
                            <div className="xl:hidden">
                                <MonthList days={days} labelledBy={monthHeadingId} busy={busy} onSelect={setSelected} />
                            </div>

                            {weeks && <WeekList weeks={weeks} busy={busy} />}
                        </>
                    )}
                </section>
            </div>

            <DayDialog day={selectedDay} rules={rules} webClock={webClock} onClose={() => setSelected(null)} />
        </AppShell>
    );
}

function Legend() {
    const t = useT();

    return (
        <div className="flex flex-col gap-1.5 text-[13px] text-muted">
            <ul aria-label={t('history.legend.label')} className="m-0 flex list-none flex-wrap items-center gap-x-5 gap-y-1.5 p-0">
                <li className="flex items-center gap-1.5">
                    <span className="inline-flex h-5 w-5 items-center justify-center rounded-sm border border-line bg-line/40 text-muted" aria-hidden>
                        <CalendarX weight="bold" size={13} />
                    </span>
                    {t('history.legend.non_workday')}
                </li>
                <li className="flex items-center gap-1.5">
                    <span className="inline-block h-4 w-4 rounded-full bg-[var(--primary-bg)]" aria-hidden />
                    {t('history.legend.today')}
                </li>
                <li className="flex items-center gap-1.5">
                    <CheckCircle weight="bold" size={15} className="text-success" aria-hidden />
                    {t('history.legend.approved')}
                </li>
                <li className="flex items-center gap-1.5">
                    <HourglassMedium weight="bold" size={15} className="text-gold-text" aria-hidden />
                    {t('history.legend.pending')}
                </li>
                <li className="flex items-center gap-1.5">
                    <XCircle weight="bold" size={15} className="text-danger" aria-hidden />
                    {t('history.legend.rejected')}
                </li>
                <li>{t('history.timezone_note')}</li>
            </ul>
            {/* Keyboard hint; phones have no Tab key */}
            <p className="m-0 hidden sm:block">{t('history.legend.keys')}</p>
        </div>
    );
}
