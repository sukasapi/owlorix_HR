import { formatLongDate, formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { CalendarX } from '@phosphor-icons/react';
import { dayOfMonth, weekdayName, weeksOf } from './dates';
import { dayAccessibleLabel, dayChips, dayStatusText, overtimeIcon, shiftRange } from './shifts';
import type { HistoryDay, OvertimeStatus } from './types';

interface Props {
    days: HistoryDay[];
    labelledBy: string;
    busy: boolean;
    onSelect: (date: string) => void;
}

const MAX_SHIFT_LINES = 2;

/**
 * Month table for wide screens. Only dates with shifts are buttons, so Tab walks through worked dates in reading
 * order; the other cells show the calendar status and nothing to open.
 */
export function MonthGrid({ days, labelledBy, busy, onSelect }: Props) {
    const t = useT();
    const locale = useLocale();

    return (
        <div className="card overflow-hidden" aria-busy={busy}>
            <table aria-labelledby={labelledBy} className={`w-full table-fixed border-collapse ${busy ? 'opacity-60' : ''}`}>
                <thead>
                    <tr>
                        {[1, 2, 3, 4, 5, 6, 7].map((weekday) => (
                            <th key={weekday} scope="col" abbr={weekdayName(weekday, locale, 'long')} className="border-b border-line-strong px-2.5 py-2 text-left text-[13px] font-semibold text-muted">
                                {weekdayName(weekday, locale, 'short')}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {weeksOf(days).map((week) => (
                        <tr key={week.find(Boolean)?.date}>
                            {week.map((day, i) =>
                                day ? (
                                    <td
                                        key={day.date}
                                        className={`h-px border-r border-b border-line p-0 align-top last:border-r-0 [tr:last-child_&]:border-b-0 ${day.is_workday ? 'bg-surface' : 'bg-line/40'}`}
                                    >
                                        {day.shifts.length > 0 ? (
                                            <button
                                                type="button"
                                                aria-haspopup="dialog"
                                                aria-label={dayAccessibleLabel(day, t, locale, formatLongDate(day.date, locale))}
                                                onClick={() => onSelect(day.date)}
                                                className="flex h-full min-h-[132px] w-full cursor-pointer flex-col items-start p-2.5 text-left hover:bg-panel/60 focus-visible:outline-offset-[-3px]"
                                            >
                                                <Cell day={day} />
                                            </button>
                                        ) : (
                                            <div className="flex h-full min-h-[132px] flex-col p-2.5">
                                                <span className="sr-only">{dayAccessibleLabel(day, t, locale, formatLongDate(day.date, locale))}</span>
                                                <Cell day={day} />
                                            </div>
                                        )}
                                    </td>
                                ) : (
                                    <td key={`pad-${i}`} className="border-r border-b border-line bg-paper last:border-r-0 [tr:last-child_&]:border-b-0" />
                                ),
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Cell({ day }: { day: HistoryDay }) {
    const t = useT();
    const locale = useLocale();
    // Overtime status already shows as icons and a running shift in its times, so the marker is a flag
    const flags = dayChips(day, t, locale).filter((c) => !c.key.startsWith('overtime_') && c.key !== 'running');
    // The cell has room for one flag, the most urgent; the count says more are waiting in the date details
    const marker = flags.find((c) => c.attention) ?? flags[0];
    const others = flags.length - (marker ? 1 : 0);
    const statuses = [...new Set(day.shifts.map((s) => s.overtime?.status).filter((s): s is OvertimeStatus => Boolean(s)))];

    return (
        <span className="flex w-full min-w-0 flex-1 flex-col gap-1" aria-hidden>
            <span className="flex w-full items-center justify-between gap-1">
                <span
                    className={`num inline-flex h-7 min-w-7 items-center justify-center rounded-full px-1.5 text-[15px] font-semibold ${
                        day.is_today ? 'bg-[var(--primary-bg)] text-[var(--primary-fg)]' : day.is_future ? 'text-muted' : 'text-ink'
                    }`}
                >
                    {dayOfMonth(day.date)}
                </span>
                {!day.is_workday && <CalendarX weight="bold" size={16} className="flex-none text-muted" />}
            </span>

            {day.calendar.source !== 'work_week' && (
                <span className="line-clamp-2 text-[12px] leading-tight font-semibold text-muted [overflow-wrap:anywhere]">{dayStatusText(day, t)}</span>
            )}

            {day.shifts.slice(0, MAX_SHIFT_LINES).map((shift) => (
                <span key={shift.id} className="num truncate text-[13px] leading-snug font-semibold text-ink">
                    {shiftRange(shift, t, locale, true)}
                </span>
            ))}
            {day.shifts.length > MAX_SHIFT_LINES && <span className="text-[12px] text-muted">{t('history.cell.more_shifts', { count: day.shifts.length - MAX_SHIFT_LINES })}</span>}

            {day.shifts.length > 0 && (
                <span className="num flex flex-col text-[12px] leading-snug text-ink">
                    {day.regular_minutes > 0 && <span className="truncate">{t('history.cell.regular', { duration: formatMinutes(day.regular_minutes, locale) })}</span>}
                    {day.overtime_minutes > 0 && (
                        <span className="flex min-w-0 items-center gap-1">
                            <span className="truncate">{t('history.cell.overtime', { duration: formatMinutes(day.overtime_minutes, locale) })}</span>
                            {statuses.map((status) => {
                                const Icon = overtimeIcon[status];
                                return <Icon key={status} weight="bold" size={13} className={`flex-none ${status === 'approved' ? 'text-success' : status === 'rejected' ? 'text-danger' : 'text-gold-text'}`} />;
                            })}
                        </span>
                    )}
                    {day.idle_minutes > 0 && <span className="truncate text-muted">{t('history.cell.idle', { duration: formatMinutes(day.idle_minutes, locale) })}</span>}
                </span>
            )}

            {marker && (
                <span className="mt-auto flex max-w-full items-center gap-1">
                    <span className={`chip min-w-0 gap-1 px-2 py-0.5 text-[12px] ${marker.tone}`}>
                        <marker.icon weight="bold" size={13} className="flex-none" />
                        <span className="min-w-0 truncate">{marker.label}</span>
                    </span>
                    {others > 0 && <span className="num flex-none text-[12px] font-semibold text-muted">{t('history.cell.more_flags', { count: others })}</span>}
                </span>
            )}
        </span>
    );
}
