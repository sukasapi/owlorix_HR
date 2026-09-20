import { formatLongDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { CalendarX, CaretRight, DoorOpen } from '@phosphor-icons/react';
import { dateAccessibleLabel, dayOfMonth, openedText, statusText, weekdayName } from './dates';
import type { CalendarDate } from './types';

interface Props {
    days: CalendarDate[];
    labelledBy: string;
    busy: boolean;
    onSelect: (date: string) => void;
}

/** Phone layout: the month as a list of dates, each row a full-width button that opens the date. */
export function MonthList({ days, labelledBy, busy, onSelect }: Props) {
    const t = useT();
    const locale = useLocale();

    return (
        <ol aria-labelledby={labelledBy} aria-busy={busy} className={`card m-0 list-none divide-y divide-line overflow-hidden p-0 ${busy ? 'opacity-60' : ''}`}>
            {days.map((day) => {
                const opened = openedText(day, t, true);

                return (
                    <li key={day.date}>
                        <button
                            type="button"
                            aria-label={dateAccessibleLabel(day, locale, t, formatLongDate)}
                            aria-haspopup="dialog"
                            onClick={() => onSelect(day.date)}
                            className={`flex min-h-[60px] w-full cursor-pointer items-center gap-3 px-3.5 py-2.5 text-left focus-visible:outline-offset-[-3px] ${
                                day.is_studio_workday ? 'bg-surface' : 'bg-line/40'
                            }`}
                        >
                            <span className="flex w-11 flex-none flex-col items-center" aria-hidden>
                                <span className="text-[12px] font-semibold text-muted">{weekdayName(day.weekday, locale, 'short')}</span>
                                <span
                                    className={`num inline-flex h-8 min-w-8 items-center justify-center rounded-full px-1 font-display text-[18px] font-bold ${
                                        day.is_today ? 'bg-[var(--primary-bg)] text-[var(--primary-fg)]' : 'text-ink'
                                    }`}
                                >
                                    {dayOfMonth(day.date)}
                                </span>
                            </span>

                            <span className="flex min-w-0 flex-1 flex-col gap-1" aria-hidden>
                                <span className={`flex items-start gap-1.5 text-[14px] leading-snug [overflow-wrap:anywhere] ${day.entry ? 'font-semibold text-ink' : 'text-muted'}`}>
                                    {!day.is_studio_workday && <CalendarX weight="bold" size={16} className="mt-0.5 flex-none text-muted" />}
                                    {statusText(day, t)}
                                </span>
                                {opened && (
                                    <span className="chip chip-info max-w-full self-start px-2 py-0.5 text-[12px]">
                                        <DoorOpen weight="bold" size={13} className="flex-none" />
                                        <span className="truncate">{opened}</span>
                                    </span>
                                )}
                            </span>

                            <CaretRight weight="bold" size={16} className="flex-none text-muted" aria-hidden />
                        </button>
                    </li>
                );
            })}
        </ol>
    );
}
