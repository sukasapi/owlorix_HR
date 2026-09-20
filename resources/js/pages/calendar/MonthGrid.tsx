import { formatLongDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { CalendarX, DoorOpen } from '@phosphor-icons/react';
import { type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { dateAccessibleLabel, dayOfMonth, openedText, weeksOf, weekdayName } from './dates';
import type { CalendarDate } from './types';

interface Props {
    days: CalendarDate[];
    labelledBy: string;
    busy: boolean;
    onSelect: (date: string) => void;
}

const MOVES: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };

/**
 * Month table for tablet and desktop. One date is in the tab order at a time (roving tabindex);
 * arrow keys, Home, and End move between dates, Enter or Space opens the date.
 */
export function MonthGrid({ days, labelledBy, busy, onSelect }: Props) {
    const t = useT();
    const locale = useLocale();
    const buttons = useRef(new Map<string, HTMLButtonElement>());
    const monthStart = days[0]?.date ?? '';
    const todayInMonth = days.find((d) => d.is_today)?.date;
    const [active, setActive] = useState(todayInMonth ?? monthStart);
    const moved = useRef(false);

    // A new month resets the roving position to today or the 1st.
    useEffect(() => setActive(todayInMonth ?? monthStart), [monthStart, todayInMonth]);

    useEffect(() => {
        if (!moved.current) return;
        moved.current = false;
        buttons.current.get(active)?.focus();
    }, [active]);

    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, day: CalendarDate) => {
        const index = days.findIndex((d) => d.date === day.date);
        let next = index;

        if (event.key in MOVES) next = index + MOVES[event.key];
        else if (event.key === 'Home') next = index - (day.weekday - 1);
        else if (event.key === 'End') next = index + (7 - day.weekday);
        else return;

        event.preventDefault();
        next = Math.min(days.length - 1, Math.max(0, next));
        moved.current = true;
        setActive(days[next].date);
    };

    return (
        <div className="card overflow-hidden" aria-busy={busy}>
            <table role="grid" aria-labelledby={labelledBy} className={`w-full table-fixed border-collapse ${busy ? 'opacity-60' : ''}`}>
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
                                    <td key={day.date} className="h-px border-r border-b border-line p-0 align-top last:border-r-0 [tr:last-child_&]:border-b-0">
                                        <button
                                            ref={(el) => {
                                                if (el) buttons.current.set(day.date, el);
                                                else buttons.current.delete(day.date);
                                            }}
                                            type="button"
                                            tabIndex={day.date === active ? 0 : -1}
                                            aria-label={dateAccessibleLabel(day, locale, t, formatLongDate)}
                                            aria-haspopup="dialog"
                                            onClick={() => {
                                                setActive(day.date);
                                                onSelect(day.date);
                                            }}
                                            onFocus={() => setActive(day.date)}
                                            onKeyDown={(e) => onKeyDown(e, day)}
                                            className={`flex h-full min-h-[108px] w-full cursor-pointer flex-col items-start gap-1 p-2 text-left focus-visible:outline-offset-[-3px] lg:min-h-[120px] lg:p-2.5 ${
                                                day.is_studio_workday ? 'bg-surface hover:bg-panel/50' : 'bg-line/40 hover:bg-panel/70'
                                            }`}
                                        >
                                            <DateCellBody day={day} />
                                        </button>
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

function DateCellBody({ day }: { day: CalendarDate }) {
    const t = useT();
    const opened = openedText(day, t);

    return (
        <>
            <span className="flex w-full items-center justify-between gap-1" aria-hidden>
                <span
                    className={`num inline-flex h-7 min-w-7 items-center justify-center rounded-full px-1.5 text-[15px] font-semibold ${
                        day.is_today ? 'bg-[var(--primary-bg)] text-[var(--primary-fg)]' : 'text-ink'
                    }`}
                >
                    {dayOfMonth(day.date)}
                </span>
                {!day.is_studio_workday && <CalendarX weight="bold" size={16} className="flex-none text-muted" />}
            </span>

            {day.entry && (
                <span className="flex min-w-0 flex-col" aria-hidden>
                    <span className="text-[12px] leading-tight font-semibold text-muted">{t(`calendar.types.${day.entry.type}`)}</span>
                    <span className="line-clamp-2 text-[13px] leading-snug font-semibold text-ink [overflow-wrap:anywhere]">{day.entry.name}</span>
                </span>
            )}

            {opened && (
                <span className="chip chip-info mt-auto max-w-full gap-1 px-2 py-0.5 text-[12px]" aria-hidden>
                    <DoorOpen weight="bold" size={13} className="flex-none" />
                    <span className="truncate">{opened}</span>
                </span>
            )}
        </>
    );
}
