import { Notice } from '@/components/ui/Notice';
import { VisitLink } from '@/components/ui/VisitLink';
import AppShell from '@/layouts/AppShell';
import { useLocale, useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { ArrowCounterClockwise, CalendarX, CaretLeft, CaretRight, DoorOpen } from '@phosphor-icons/react';
import { useId, useState } from 'react';
import { DayDialog } from './DayDialog';
import { monthLabel } from './dates';
import { MonthGrid } from './MonthGrid';
import { MonthList } from './MonthList';
import type { CalendarPageProps } from './types';
import { WorkWeekCard } from './WorkWeekCard';

type MonthLoad = { state: 'idle' } | { state: 'loading' | 'error'; month: string };

/**
 * Kalender. The month is the focal point: the grid on tablet and desktop, a list of dates on phones.
 * Picking a date opens its details, where Superadmin edits entries and Management opens workdays.
 */
export default function CalendarIndex({ month, today, work_week, days, can, scopes }: CalendarPageProps) {
    const t = useT();
    const locale = useLocale();
    const monthHeadingId = useId();
    const [selected, setSelected] = useState<string | null>(null);
    const [load, setLoad] = useState<MonthLoad>({ state: 'idle' });

    // A date that is no longer on screen (another month, or an entry moved away) closes the dialog.
    const selectedDay = days.find((d) => d.date === selected) ?? null;

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

    const monthHref = (value: string) => route('calendar.index', { bulan: value });
    const busy = load.state === 'loading';

    return (
        <AppShell title={t('calendar.title')}>
            <div className="flex flex-col gap-6">
                <header className="flex flex-col gap-1.5">
                    <h1 className="h1">{t('calendar.title')}</h1>
                    <p className="m-0 max-w-[68ch] text-muted">{can.manage_calendar ? t('calendar.lead_manage') : t('calendar.lead_open')}</p>
                </header>

                <section aria-labelledby={monthHeadingId} className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                        <nav aria-label={t('calendar.month.nav_label')} className="flex items-center gap-2">
                            <VisitLink href={monthHref(month.previous)} className="btn btn-secondary px-3" aria-label={t('calendar.month.previous')} options={visitCallbacks(month.previous)}>
                                <CaretLeft weight="bold" size={18} aria-hidden />
                            </VisitLink>
                            <h2 id={monthHeadingId} className="m-0 min-w-[9.5ch] text-center font-display text-[24px] leading-tight font-bold text-heading sm:min-w-[11ch] sm:text-[28px]" aria-live="polite">
                                {monthLabel(month.value, locale)}
                            </h2>
                            <VisitLink href={monthHref(month.next)} className="btn btn-secondary px-3" aria-label={t('calendar.month.next')} options={visitCallbacks(month.next)}>
                                <CaretRight weight="bold" size={18} aria-hidden />
                            </VisitLink>
                        </nav>
                        {month.value !== month.current && (
                            <VisitLink href={monthHref(month.current)} className="btn btn-quiet" options={visitCallbacks(month.current)}>
                                {t('calendar.month.this_month')}
                            </VisitLink>
                        )}
                        {load.state === 'loading' && (
                            <p className="m-0 text-[14px] font-semibold" role="status">
                                {t('calendar.month.loading', { month: monthLabel(load.month, locale) })}
                            </p>
                        )}
                    </div>

                    {load.state === 'error' && (
                        <Notice tone="danger">
                            <span>{t('calendar.month.error', { month: monthLabel(load.month, locale) })}</span>{' '}
                            <button
                                type="button"
                                className="btn btn-secondary btn-sm mt-2 bg-surface text-ink sm:mt-0 sm:ml-2"
                                onClick={() => router.get(monthHref(load.month), {}, visitCallbacks(load.month))}
                            >
                                <ArrowCounterClockwise weight="bold" size={16} aria-hidden />
                                {t('calendar.month.retry')}
                            </button>
                        </Notice>
                    )}

                    <Legend />

                    <div className="hidden md:block">
                        <MonthGrid days={days} labelledBy={monthHeadingId} busy={busy} onSelect={setSelected} />
                    </div>
                    <div className="md:hidden">
                        <MonthList days={days} labelledBy={monthHeadingId} busy={busy} onSelect={setSelected} />
                    </div>
                </section>

                <div className="max-w-[760px]">
                    <WorkWeekCard workWeek={work_week} canManage={can.manage_calendar} />
                </div>
            </div>

            <DayDialog day={selectedDay} today={today} can={can} scopes={scopes} onClose={() => setSelected(null)} onSelect={setSelected} />
        </AppShell>
    );
}

function Legend() {
    const t = useT();

    return (
        <div className="flex flex-col gap-1.5 text-[13px] text-muted">
            <ul aria-label={t('calendar.legend.label')} className="m-0 flex list-none flex-wrap items-center gap-x-5 gap-y-1.5 p-0">
                <li className="flex items-center gap-1.5">
                    <span className="inline-flex h-5 w-5 items-center justify-center rounded-sm border border-line bg-line/40 text-muted" aria-hidden>
                        <CalendarX weight="bold" size={13} />
                    </span>
                    {t('calendar.legend.non_workday')}
                </li>
                <li className="flex items-center gap-1.5">
                    <DoorOpen weight="bold" size={15} className="text-ink" aria-hidden />
                    {t('calendar.legend.opened')}
                </li>
                <li className="flex items-center gap-1.5">
                    <span className="inline-block h-4 w-4 rounded-full bg-[var(--primary-bg)]" aria-hidden />
                    {t('calendar.legend.today')}
                </li>
                <li>{t('calendar.timezone_note')}</li>
            </ul>
            <p className="m-0 hidden md:block">{t('calendar.legend.keys')}</p>
        </div>
    );
}
