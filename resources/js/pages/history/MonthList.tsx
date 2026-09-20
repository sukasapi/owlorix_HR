import { formatLongDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { CalendarX, CaretRight } from '@phosphor-icons/react';
import { dayOfMonth, weekdayName } from './dates';
import { dayAccessibleLabel, dayChips, dayStatusText, minutesLine, shiftRange } from './shifts';
import type { HistoryDay } from './types';

interface Props {
    days: HistoryDay[];
    labelledBy: string;
    busy: boolean;
    onSelect: (date: string) => void;
}

/**
 * Phones and tablets: the month as a list of dates. A date with shifts is a full-width button with its times, minutes,
 * and chips; a date without shifts is a short plain row, so there is nothing empty to tap.
 */
export function MonthList({ days, labelledBy, busy, onSelect }: Props) {
    const t = useT();
    const locale = useLocale();

    return (
        <ol aria-labelledby={labelledBy} aria-busy={busy} className={`card m-0 list-none divide-y divide-line overflow-hidden p-0 ${busy ? 'opacity-60' : ''}`}>
            {days.map((day) => {
                const label = dayAccessibleLabel(day, t, locale, formatLongDate(day.date, locale));
                const tone = day.is_workday ? 'bg-surface' : 'bg-line/40';
                // A plain workday from the work week needs no status line; holidays, days off, and opened days do
                const special = !day.is_workday || day.calendar.source !== 'work_week';

                if (day.shifts.length === 0) {
                    return (
                        <li key={day.date} className={`flex min-h-[48px] items-center gap-3 px-3.5 py-2 ${tone}`}>
                            <span className="sr-only">{label}</span>
                            <DateBadge day={day} compact />
                            <span className="flex min-w-0 flex-1 items-center gap-1.5 text-[14px] text-muted [overflow-wrap:anywhere]" aria-hidden>
                                {!day.is_workday && <CalendarX weight="bold" size={16} className="flex-none" />}
                                {special ? dayStatusText(day, t) : day.is_future ? '' : t('history.cell.no_shift')}
                            </span>
                        </li>
                    );
                }

                const chips = dayChips(day, t, locale);

                return (
                    <li key={day.date}>
                        <button
                            type="button"
                            aria-label={label}
                            aria-haspopup="dialog"
                            onClick={() => onSelect(day.date)}
                            className={`flex min-h-[72px] w-full cursor-pointer items-center gap-3 px-3.5 py-3 text-left hover:bg-panel/60 focus-visible:outline-offset-[-3px] ${tone}`}
                        >
                            <DateBadge day={day} />
                            <span className="flex min-w-0 flex-1 flex-col gap-1" aria-hidden>
                                {special && (
                                    <span className="flex items-start gap-1.5 text-[13px] font-semibold text-muted [overflow-wrap:anywhere]">
                                        {!day.is_workday && <CalendarX weight="bold" size={15} className="mt-px flex-none" />}
                                        {dayStatusText(day, t)}
                                    </span>
                                )}
                                <span className="num font-semibold [overflow-wrap:anywhere]">{day.shifts.map((s) => shiftRange(s, t, locale)).join(', ')}</span>
                                <span className="num text-[14px] [overflow-wrap:anywhere]">{minutesLine(day, t, locale)}</span>
                                {chips.length > 0 && (
                                    <span className="mt-1 flex flex-wrap gap-1.5">
                                        {chips.map(({ key, label: chipLabel, tone: chipTone, icon: Icon }) => (
                                            <span key={key} className={`chip max-w-full px-2 py-0.5 text-[12px] ${chipTone}`}>
                                                <Icon weight="bold" size={13} className="flex-none" />
                                                <span className="min-w-0 truncate">{chipLabel}</span>
                                            </span>
                                        ))}
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

function DateBadge({ day, compact = false }: { day: HistoryDay; compact?: boolean }) {
    const locale = useLocale();

    return (
        <span className={`flex w-14 flex-none items-center ${compact ? 'flex-row justify-between gap-1' : 'flex-col'}`} aria-hidden>
            <span className="text-[12px] font-semibold text-muted">{weekdayName(day.weekday, locale, 'short')}</span>
            <span
                className={`num inline-flex items-center justify-center rounded-full font-display font-bold ${compact ? 'h-7 min-w-7 px-1 text-[15px]' : 'h-8 min-w-8 px-1 text-[18px]'} ${
                    day.is_today ? 'bg-[var(--primary-bg)] text-[var(--primary-fg)]' : 'text-ink'
                }`}
            >
                {dayOfMonth(day.date)}
            </span>
        </span>
    );
}
