import AppShell from '@/layouts/AppShell';
import { formatMinutes, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { DayVerdict } from '@/types';
import { usePoll } from '@inertiajs/react';
import { CalendarCheck, CalendarX, CheckCircle, HourglassMedium, Warning } from '@phosphor-icons/react';
import { useEffect } from 'react';
import { ClockPanel } from './ClockPanel';
import { FollowUps } from './FollowUps';
import { useNotificationPermission, useNow, useRepeatingReminder, useWebHeartbeat } from './hooks';
import { PresenceCard, PromptCard, presenceDue } from './PromptCards';
import type { MyDayProps, Shift } from './types';

/**
 * Hari ini. Focal point is the clock panel with today's regular time against the 8-hour limit (DESIGN.md, Layout).
 * Questions that need an answer (8-hour prompt, "Masih lembur?") sit above it while they wait. Clocking in works here
 * and in the desktop app (docs/02 3.11).
 */
export default function MyDay({ summary, day }: MyDayProps) {
    const t = useT();
    const locale = useLocale();
    const open = summary.open_shift;
    const mine = summary.web_clock_in_enabled && Boolean(open?.on_this_browser);
    const live = summary.status !== 'signed_out';
    const now = useNow(15_000, mine);
    const [permission, requestPermission] = useNotificationPermission();

    const heartbeatFailedAt = useWebHeartbeat(mine, summary.rules.heartbeat_seconds);

    // A shift on another device still updates here; the poll may pause in a hidden tab, the heartbeat above may not
    const poll = usePoll(60_000, { only: ['summary'] }, { autoStart: false });
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
    const time = (iso: string | null) => (iso ? formatTime(iso, locale) : '');
    const anyWebShift = summary.shifts.some((shift) => shift.device_id.startsWith('web:'));

    return (
        <AppShell title={t('my-day.title')}>
            <div className="grid items-start gap-6 xl:grid-cols-[1fr_340px]">
                <div className="flex min-w-0 flex-col gap-6">
                    {prompted && <PromptCard shift={prompted} repeatMinutes={summary.rules.prompt_repeat_minutes} reasonMin={summary.rules.reason_min_length} />}
                    {presence && <PresenceCard check={presence} answerMinutes={summary.rules.presence_answer_minutes} checkMinutes={summary.rules.presence_check_minutes} />}
                    <ClockPanel summary={summary} permission={permission} onEnableReminders={requestPermission} heartbeatFailedAt={heartbeatFailedAt} />
                </div>

                <section className="card flex flex-col gap-3 px-5 py-[18px]" aria-labelledby="today-calendar">
                    <CalendarCard day={day} webEnabled={summary.web_clock_in_enabled} />
                </section>
            </div>

            <FollowUps reports={summary.reports_due} claims={summary.late_claims} reasonMin={summary.rules.reason_min_length} webEnabled={summary.web_clock_in_enabled} />

            {hasShifts && (
                <div className="mt-6 grid items-start gap-6 lg:grid-cols-2">
                    <section className="card px-5 py-[18px]" aria-labelledby="today-shifts">
                        <h2 id="today-shifts" className="h2">
                            {t('my-day.shifts_heading')}
                        </h2>
                        <ul className="m-0 mt-3 list-none divide-y divide-line p-0">
                            {summary.shifts.map((shift) => (
                                <li key={shift.id} className="flex flex-col gap-1.5 py-3">
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
                    </section>

                    <section className="card px-5 py-[18px]" aria-labelledby="today-idle">
                        <h2 id="today-idle" className="h2">
                            {t('my-day.idle_heading')}
                        </h2>
                        {summary.idle_periods.length === 0 ? (
                            <p className="m-0 mt-3 text-muted">{anyWebShift ? t('my-day.idle_none_web') : t('my-day.idle_none')}</p>
                        ) : (
                            <ul className="m-0 mt-3 list-none divide-y divide-line p-0">
                                {summary.idle_periods.map((period) => (
                                    <li key={`${period.shift_id}-${period.started_at}`} className="flex flex-wrap items-center justify-between gap-2 py-3">
                                        <span className="num font-semibold">
                                            {t('my-day.idle_row', {
                                                start: time(period.started_at),
                                                end: period.ended_at ? time(period.ended_at) : t('my-day.idle_open'),
                                            })}
                                        </span>
                                        <span className="flex items-center gap-2">
                                            <span className="num text-sm text-muted">{formatMinutes(period.minutes, locale)}</span>
                                            <span className={`chip ${period.tag ? 'chip-info' : ''}`}>
                                                {period.tag ? t(`my-day.idle_tags.${period.tag}`) : t('my-day.idle_untagged')}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="m-0 mt-2 text-[13px] text-muted">{t('my-day.idle_note')}</p>
                    </section>
                </div>
            )}
        </AppShell>
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
        <span className="flex flex-wrap gap-2">
            {chips.map(({ key, label, tone, icon: Icon }) => (
                <span key={key} className={`chip ${tone} whitespace-normal`}>
                    <Icon weight="bold" size={15} aria-hidden />
                    {label}
                </span>
            ))}
        </span>
    );
}

function CalendarCard({ day, webEnabled }: { day: DayVerdict; webEnabled: boolean }) {
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
        <>
            <h2 id="today-calendar" className="h2 flex items-center gap-2">
                {day.is_workday ? <CalendarCheck weight="bold" size={22} aria-hidden /> : <CalendarX weight="bold" size={22} aria-hidden />}
                {day.is_workday ? t('my-day.workday') : t('my-day.non_workday')}
            </h2>
            {calendarLine && <p className="m-0 font-semibold">{calendarLine}</p>}
            <p className="m-0 text-muted">{day.is_workday ? t('my-day.regular_rule') : webEnabled ? t('my-day.non_workday_note_web') : t('my-day.non_workday_note')}</p>
        </>
    );
}
