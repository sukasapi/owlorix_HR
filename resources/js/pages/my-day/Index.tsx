import AppShell from '@/layouts/AppShell';
import { formatLongDate, formatMinutes, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { WeekTargetCard } from '@/components/WeekTarget';
import type { DayVerdict } from '@/types';
import { usePoll } from '@inertiajs/react';
import { CheckCircle, HourglassMedium, Warning } from '@phosphor-icons/react';
import { useEffect } from 'react';
import { ClockPanel } from './ClockPanel';
import { DayLine } from './DayLine';
import { NeedsYou } from './NeedsYou';
import { useNotificationPermission, useNow, useRepeatingReminder, useWebHeartbeat } from './hooks';
import { PresenceCard, PromptCard, presenceDue } from './PromptCards';
import type { MyDayProps, Shift } from './types';

/**
 * Hari ini, layout A (docs/desainUI_v2). The date is the page title; the clock panel is the focal point, with
 * Perlu kamu next to it on a wide screen and under it on a phone. Questions that need an answer now (8-hour prompt,
 * "Masih lembur?") sit above both while they wait. Clocking in works here and in the desktop app (docs/02 3.11).
 */
export default function MyDay({ summary, day, week, idle_questions }: MyDayProps) {
    const t = useT();
    const locale = useLocale();
    const open = summary.open_shift;
    const mine = summary.web_clock_in_enabled && Boolean(open?.on_this_browser);
    const live = summary.status !== 'signed_out';
    const now = useNow(15_000, mine);
    const [permission, requestPermission] = useNotificationPermission();

    const heartbeatFailedAt = useWebHeartbeat(mine, summary.rules.heartbeat_seconds);

    // A shift on another device still updates here; the poll may pause in a hidden tab, the heartbeat above may not
    const poll = usePoll(60_000, { only: ['summary', 'week', 'idle_questions'] }, { autoStart: false });
    useEffect(() => {
        if (live && !mine) poll.start();
        else poll.stop();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [live, mine]);

    const prompted = mine && open?.status === 'prompted' ? open : null;
    const presence = mine ? presenceDue(summary, now) : null;

    useRepeatingReminder(
        prompted ? `prompt-${prompted.id}` : null,
        t('my-day.prompt.title'),
        t('my-day.reminders.prompt_body', { time: prompted?.prompt_deadline_at ? formatTime(prompted.prompt_deadline_at, locale) : '' }),
        summary.rules.prompt_repeat_minutes,
        permission,
    );
    useRepeatingReminder(
        presence ? `presence-${presence.next_check_at}` : null,
        t('my-day.presence.title'),
        t('my-day.reminders.presence_body'),
        summary.rules.prompt_repeat_minutes,
        permission,
    );

    const hasShifts = summary.shifts.length > 0;

    return (
        <AppShell title={t('my-day.title')}>
            <header className="mb-6 sm:mb-8">
                <h1 className="h1">{formatLongDate(summary.date, locale)}</h1>
                <DayLineText day={day} webEnabled={summary.web_clock_in_enabled} />
            </header>

            {(prompted || presence) && (
                <div className="mb-6 flex flex-col gap-6">
                    {prompted && <PromptCard shift={prompted} repeatMinutes={summary.rules.prompt_repeat_minutes} reasonMin={summary.rules.reason_min_length} />}
                    {presence && <PresenceCard check={presence} answerMinutes={summary.rules.presence_answer_minutes} checkMinutes={summary.rules.presence_check_minutes} />}
                </div>
            )}

            <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px] xl:gap-8">
                <ClockPanel summary={summary} permission={permission} onEnableReminders={requestPermission} heartbeatFailedAt={heartbeatFailedAt} />
                <NeedsYou
                    reports={summary.reports_due}
                    claims={summary.late_claims}
                    questions={idle_questions ?? []}
                    reasonMin={summary.rules.reason_min_length}
                    webEnabled={summary.web_clock_in_enabled}
                />
            </div>

            <DayLine summary={summary} />

            {(week || hasShifts) && (
                <div className="mt-10 grid items-start gap-10 lg:grid-cols-2 lg:gap-14">
                    {week && (
                        <section className="flex flex-col gap-3" aria-labelledby="week-target">
                            <WeekTargetCard week={week} />
                        </section>
                    )}
                    {hasShifts && <ShiftsToday summary={summary} />}
                </div>
            )}
        </AppShell>
    );
}

/** Under the date: what kind of day it is, in one line (the calendar card of the old layout). */
function DayLineText({ day, webEnabled }: { day: DayVerdict; webEnabled: boolean }) {
    const t = useT();

    const calendarLine = (() => {
        if (day.source === 'opened') return t('my-day.opened_note');
        if (day.source === 'calendar' && day.label) {
            const key = day.calendar_type === 'holiday' ? 'holiday' : day.calendar_type === 'studio_day_off' ? 'studio_day_off' : 'studio_workday';
            return t(`my-day.${key}`, { name: day.label });
        }
        return null;
    })();

    return (
        <p className="m-0 mt-1.5 max-w-[72ch] text-muted">
            <span className="font-semibold text-ink">{day.is_workday ? t('my-day.workday') : t('my-day.non_workday')}.</span>{' '}
            {calendarLine && <>{calendarLine}. </>}
            {day.is_workday ? t('my-day.regular_rule') : webEnabled ? t('my-day.non_workday_note_web') : t('my-day.non_workday_note')}
        </p>
    );
}

function ShiftsToday({ summary }: { summary: MyDayProps['summary'] }) {
    const t = useT();
    const locale = useLocale();
    const time = (iso: string | null) => (iso ? formatTime(iso, locale) : '');
    const anyWebShift = summary.shifts.some((shift) => shift.device_id.startsWith('web:'));

    return (
        <section aria-labelledby="today-shifts">
            <h2 id="today-shifts" className="h2">
                {t('my-day.shifts_heading')}
            </h2>
            <ul className="rows m-0 mt-2 list-none p-0">
                {summary.shifts.map((shift) => (
                    <li key={shift.id} className="flex flex-col gap-1.5 py-3.5">
                        <span className="num font-semibold">
                            {t('my-day.shift_row', {
                                start: time(shift.clock_in_at),
                                end: shift.clock_out_at ? time(shift.clock_out_at) : t('my-day.shift_running'),
                            })}
                        </span>
                        <span className="num text-sm text-muted">
                            {t('my-day.shift_regular', { duration: formatMinutes(shift.regular_minutes, locale) })}
                            {shift.overtime_minutes > 0 && ` · ${t('my-day.shift_overtime', { duration: formatMinutes(shift.overtime_minutes, locale) })}`}
                            {shift.interruption_minutes > 0 && ` · ${t('my-day.shift_interrupted', { duration: formatMinutes(shift.interruption_minutes, locale) })}`}
                        </span>
                        <ShiftChips shift={shift} />
                    </li>
                ))}
            </ul>

            <h3 id="today-idle" className="mt-6 text-[15px] font-semibold">
                {t('my-day.idle_heading')}
            </h3>
            {summary.idle_periods.length === 0 ? (
                <p className="m-0 mt-2 text-muted">{anyWebShift ? t('my-day.idle_none_web') : t('my-day.idle_none')}</p>
            ) : (
                <ul className="rows m-0 mt-1 list-none p-0" aria-labelledby="today-idle">
                    {summary.idle_periods.map((period) => (
                        <li key={`${period.shift_id}-${period.started_at}`} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3">
                            <span className="num">
                                {t('my-day.idle_row', {
                                    start: time(period.started_at),
                                    end: period.ended_at ? time(period.ended_at) : t('my-day.idle_open'),
                                })}
                            </span>
                            <span className="flex items-center gap-3">
                                <span className="num text-sm text-muted">{formatMinutes(period.minutes, locale)}</span>
                                <span className={`chip ${period.tag ? 'chip-info' : 'text-muted'}`}>
                                    {period.tag ? t(`my-day.idle_tags.${period.tag}`) : t('my-day.idle_untagged')}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <p className="m-0 mt-2 text-[13px] text-muted">{t('my-day.idle_note')}</p>
        </section>
    );
}

function ShiftChips({ shift }: { shift: Shift }) {
    const t = useT();
    const chips: { key: string; label: string; tone: string; icon: typeof CheckCircle }[] = [];

    if (shift.status === 'needs_review') chips.push({ key: 'review', label: t('my-day.shift_flags.needs_review'), tone: 'chip-bad', icon: Warning });
    if (shift.flags.includes('clock_mismatch')) chips.push({ key: 'clock', label: t('my-day.shift_flags.clock_mismatch'), tone: 'chip-bad', icon: Warning });
    if (shift.flags.includes('gap_unverified')) chips.push({ key: 'gap', label: t('my-day.shift_flags.gap_unverified'), tone: '', icon: Warning });
    if (shift.report_due) chips.push({ key: 'report', label: t('my-day.shift_flags.report_due'), tone: 'chip-pending', icon: HourglassMedium });
    if (shift.is_short) chips.push({ key: 'short', label: t('my-day.shift_flags.short'), tone: '', icon: Warning });
    if (shift.overtime?.status) {
        const tone = shift.overtime.status === 'approved' ? 'chip-ok' : shift.overtime.status === 'rejected' ? 'chip-bad' : 'chip-pending';
        const icon = shift.overtime.status === 'pending' ? HourglassMedium : shift.overtime.status === 'approved' ? CheckCircle : Warning;
        chips.push({ key: 'ot', label: t(`my-day.overtime_status.${shift.overtime.status}`), tone, icon });
    }

    if (chips.length === 0) return null;

    return (
        <span className="flex flex-wrap gap-x-4 gap-y-1.5">
            {chips.map(({ key, label, tone, icon: Icon }) => (
                <span key={key} className={`chip ${tone} whitespace-normal`}>
                    <Icon weight="bold" size={15} aria-hidden />
                    {label}
                </span>
            ))}
        </span>
    );
}
