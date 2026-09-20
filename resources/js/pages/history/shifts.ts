import { formatMinutes } from '@/lib/format';
import type { Translate } from '@/lib/i18n';
import type { Locale } from '@/types';
import { CheckCircle, ClockCountdown, HourglassMedium, type Icon, Timer, Warning, XCircle } from '@phosphor-icons/react';
import { durationWords, minutesBetween, timeOn } from './dates';
import type { HistoryDay, HistoryRules, HistoryShift, OvertimeStatus } from './types';

export interface Chip {
    key: string;
    label: string;
    tone: string;
    icon: Icon;
    /** Worth a look: shown as the one marker in a calendar cell. */
    attention: boolean;
}

export const overtimeTone: Record<OvertimeStatus, string> = { pending: 'chip-pending', approved: 'chip-ok', rejected: 'chip-bad' };
export const overtimeIcon: Record<OvertimeStatus, Icon> = { pending: HourglassMedium, approved: CheckCircle, rejected: XCircle };

/** Status chips of one shift, most urgent first. Each carries an icon and text, never color alone. */
export function shiftChips(shift: HistoryShift, t: Translate, locale: Locale): Chip[] {
    const chips: Chip[] = [];
    const add = (key: string, tone: string, icon: Icon, attention: boolean, label = t(`history.flags.${key}`)) => chips.push({ key, label, tone, icon, attention });

    if (shift.is_live) add('running', 'chip-info', Timer, false);
    if (shift.status === 'needs_review') add('needs_review', 'chip-bad', Warning, true);
    if (shift.flags.includes('clock_mismatch')) add('clock_mismatch', 'chip-bad', Warning, true);
    if (shift.flags.includes('gap_unverified')) add('gap_unverified', '', Warning, true);
    if (shift.report_due) add('report_due', 'chip-pending', HourglassMedium, true);
    if (shift.late_claim) add('claim_possible', 'chip-pending', ClockCountdown, true);
    if (shift.is_short) add('short', '', Warning, false, t('history.flags.short', { limit: durationWords(shift.regular_limit_minutes, locale) }));
    if (shift.flags.includes('late_claim')) add('late_claim', 'chip-info', ClockCountdown, false);

    const status = shift.overtime?.status;
    if (status) {
        chips.push({ key: `overtime_${status}`, label: t(`history.overtime_status.${status}`), tone: overtimeTone[status], icon: overtimeIcon[status], attention: status === 'rejected' });
    }

    return chips;
}

/** Chips of all shifts on a date, each kind once. */
export function dayChips(day: HistoryDay, t: Translate, locale: Locale): Chip[] {
    const seen = new Map<string, Chip>();
    for (const shift of day.shifts) {
        for (const chip of shiftChips(shift, t, locale)) {
            if (!seen.has(chip.key)) seen.set(chip.key, chip);
        }
    }
    return [...seen.values()];
}

export function dayStatusText(day: HistoryDay, t: Translate): string {
    const c = day.calendar;
    if (c.source === 'opened') return t('history.day.opened');
    if (c.source === 'calendar' && c.label) {
        const key = c.calendar_type === 'holiday' ? 'holiday' : c.calendar_type === 'studio_day_off' ? 'studio_day_off' : 'studio_workday';
        return t(`history.day.${key}`, { name: c.label });
    }
    return day.is_workday ? t('history.day.workday') : t('history.day.non_workday');
}

export function shiftRange(shift: HistoryShift, t: Translate, locale: Locale, short = false): string {
    const start = timeOn(shift.clock_in_at, shift.work_date, locale);
    const end = shift.clock_out_at ? timeOn(shift.clock_out_at, shift.work_date, locale) : t('history.cell.running');
    return t(short ? 'history.cell.range_short' : 'history.cell.range', { start, end });
}

/** "Reguler 8 j · Lembur 2 j · PC diam 34 mnt", leaving out what is zero apart from regular time. */
export function minutesLine(day: Pick<HistoryDay, 'regular_minutes' | 'overtime_minutes' | 'idle_minutes'>, t: Translate, locale: Locale): string {
    const parts = [t('history.cell.regular', { duration: formatMinutes(day.regular_minutes, locale) })];
    if (day.overtime_minutes > 0) parts.push(t('history.cell.overtime', { duration: formatMinutes(day.overtime_minutes, locale) }));
    if (day.idle_minutes > 0) parts.push(t('history.cell.idle', { duration: formatMinutes(day.idle_minutes, locale) }));
    return parts.join(' · ');
}

/** Everything a screen reader needs from one date button or row. */
export function dayAccessibleLabel(day: HistoryDay, t: Translate, locale: Locale, longDate: string): string {
    const parts = [longDate];
    if (day.is_today) parts.push(t('history.cell.today'));
    parts.push(dayStatusText(day, t));
    if (day.shifts.length === 0) {
        if (!day.is_future) parts.push(t('history.cell.no_shift'));
        return parts.join('. ');
    }
    parts.push(day.shifts.map((s) => shiftRange(s, t, locale)).join(', '));
    parts.push(minutesLine(day, t, locale));
    const chips = dayChips(day, t, locale);
    if (chips.length > 0) parts.push(chips.map((c) => c.label).join(', '));
    return parts.join('. ');
}

export interface TimelineEntry {
    key: string;
    at: string;
    text: string;
    detail?: string;
    tone?: 'bad' | 'pending';
}

const ORDER: Record<string, number> = { clock_in: 0, overtime_start: 1, regular_mark: 2, device: 3, idle: 4, interruption: 5, overtime_end: 6, clock_out: 7, last_seen: 8 };

/** The shift as a list of moments: clock-in and device, moves, quiet periods, disconnections, the limit, overtime, clock-out. */
export function timeline(shift: HistoryShift, rules: HistoryRules, t: Translate, locale: Locale): TimelineEntry[] {
    const entries: TimelineEntry[] = [];
    const ot = shift.overtime;
    const reachedBefore = shift.regular_before_minutes >= shift.regular_limit_minutes;
    const overtimeFromStart = ot !== null && ot.started_at === shift.clock_in_at;
    const limit = durationWords(shift.regular_limit_minutes, locale);
    const [first, ...moves] = shift.devices;

    entries.push({
        key: 'clock_in',
        at: shift.clock_in_at,
        text: !shift.is_workday
            ? t('history.events.clock_in_non_workday')
            : reachedBefore
              ? t('history.events.clock_in_after_limit')
              : t('history.events.clock_in'),
        detail: first ? t('history.events.on_device', { device: first.name }) : undefined,
    });

    moves.forEach((step, i) => {
        entries.push({ key: `device-${i}`, at: step.at, text: t('history.events.moved', { device: step.name }) });
    });

    // The limit was reached during this shift only when the date reached it here
    if (
        shift.is_workday &&
        shift.regular_ends_at &&
        !reachedBefore &&
        shift.regular_before_minutes + shift.regular_minutes >= shift.regular_limit_minutes
    ) {
        entries.push({ key: 'regular_mark', at: shift.regular_ends_at, text: t('history.events.regular_mark', { limit }) });
    }

    // On a non-workday the clock-in line already says all time is overtime
    if (ot && !(overtimeFromStart && !shift.is_workday)) {
        entries.push({ key: 'overtime_start', at: ot.started_at, text: t('history.events.overtime_start') });
    }

    shift.idle_periods.forEach((period, i) => {
        const tag = period.tag ? t('history.events.idle_tag', { tag: t(`history.events.idle_tags.${period.tag}`) }) : t('history.events.idle_untagged');
        entries.push({
            key: `idle-${i}`,
            at: period.started_at,
            text: period.ended_at ? t('history.events.idle', { duration: formatMinutes(period.minutes, locale) }) : t('history.events.idle_open'),
            detail: [tag, period.note].filter(Boolean).join(': '),
        });
    });

    shift.interruptions.forEach((gap, i) => {
        entries.push({
            key: `interruption-${i}`,
            at: gap.started_at,
            text: gap.ended_at
                ? t('history.events.interruption', { duration: formatMinutes(minutesBetween(gap.started_at, gap.ended_at), locale) })
                : t('history.events.interruption_open'),
            tone: 'bad',
        });
    });

    const presenceEnd = shift.overtime_end_reason === 'presence_check_no_answer';
    const answer = durationWords(presenceEnd ? rules.overtime_idle_answer_minutes : rules.prompt_auto_close_minutes, locale);

    if (ot?.ended_at && ot.ended_at !== shift.clock_out_at) {
        entries.push({ key: 'overtime_end', at: ot.ended_at, text: presenceEnd ? t('history.events.overtime_end_presence', { answer }) : t('history.events.overtime_end') });
    }

    if (shift.clock_out_at) {
        const text = (() => {
            switch (shift.end_reason) {
                case 'auto_no_answer':
                    return presenceEnd ? t('history.events.clock_out_presence', { answer }) : t('history.events.clock_out_auto', { limit, answer });
                case 'shutdown_timeout':
                    return t('history.events.clock_out_shutdown', { window: durationWords(rules.resume_window_minutes, locale) });
                case 'superadmin':
                    return t('history.events.clock_out_superadmin');
                default:
                    return ot?.ended_at === shift.clock_out_at ? t('history.events.clock_out_overtime') : t('history.events.clock_out');
            }
        })();
        entries.push({ key: 'clock_out', at: shift.clock_out_at, text, tone: shift.end_reason === 'shutdown_timeout' ? 'bad' : undefined });
    } else if (shift.last_seen_at) {
        entries.push({ key: 'last_seen', at: shift.last_seen_at, text: t('history.events.last_seen') });
    }

    const rank = (key: string) => ORDER[key.split('-')[0]] ?? 9;

    return entries
        .map((entry, index) => ({ entry, index }))
        .sort((a, b) => a.entry.at.localeCompare(b.entry.at) || rank(a.entry.key) - rank(b.entry.key) || a.index - b.index)
        .map(({ entry }) => entry);
}
