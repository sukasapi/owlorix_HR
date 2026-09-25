import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { useNow } from './hooks';
import type { Summary } from './types';

const HOUR = 3_600_000;

/**
 * Today on one line: work, PC idle (hatched), and the 8-hour mark. A picture of the shift list below it, so it is
 * hidden from screen readers; the list carries the same times as text.
 */
export function DayLine({ summary }: { summary: Summary }) {
    const t = useT();
    const timezone = usePage<SharedProps>().props.app.timezone;
    const running = summary.shifts.some((shift) => !shift.clock_out_at);
    const now = useNow(60_000, running);

    if (summary.shifts.length === 0) return null;

    const end = (iso: string | null) => (iso ? new Date(iso).getTime() : now);
    const work = summary.shifts.map((shift) => [new Date(shift.clock_in_at).getTime(), end(shift.clock_out_at)] as const);
    const idle = summary.idle_periods.map((period) => [new Date(period.started_at).getTime(), end(period.ended_at)] as const);
    const mark = summary.regular_ends_at ? new Date(summary.regular_ends_at).getTime() : null;

    // Studio time is a whole-hour offset from UTC, so whole UTC hours are whole studio hours.
    const first = Math.min(...work.map(([start]) => start));
    const last = Math.max(...work.map(([, stop]) => stop), mark ?? 0);
    const from = Math.floor(first / HOUR) * HOUR;
    const to = Math.max(from + 4 * HOUR, Math.ceil((last + 1) / HOUR) * HOUR);
    const span = to - from;
    const step = span > 12 * HOUR ? 2 : 1;
    const pos = (ms: number) => `${((ms - from) / span) * 100}%`;
    const width = (a: number, b: number) => `${(Math.max(0, b - a) / span) * 100}%`;
    const hour = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', hourCycle: 'h23', timeZone: timezone });
    const ticks = Array.from({ length: Math.round(span / HOUR / step) + 1 }, (_, i) => from + i * step * HOUR);

    return (
        <section className="mt-10" aria-labelledby="day-line">
            <div className="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
                <h2 id="day-line" className="h2">
                    {t('my-day.day_line.heading')}
                </h2>
                <ul className="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-[13px] text-muted" aria-hidden>
                    <li className="flex items-center gap-1.5">
                        <span className="inline-block h-2.5 w-4 rounded-[2px] bg-[var(--primary-bg)]" />
                        {t('my-day.day_line.work')}
                    </li>
                    <li className="flex items-center gap-1.5">
                        <span className="day-idle inline-block h-2.5 w-4 rounded-[2px]" />
                        {t('my-day.day_line.idle')}
                    </li>
                    {mark && (
                        <li className="flex items-center gap-1.5">
                            <span className="inline-block h-3 w-0.5 bg-ink" />
                            {t('my-day.day_line.mark')}
                        </li>
                    )}
                </ul>
            </div>
            <div aria-hidden className="mt-4">
                <div className="relative h-8 rounded-md bg-[color-mix(in_srgb,var(--line)_60%,transparent)]">
                    {work.map(([a, b]) => (
                        <span key={`w${a}`} className="absolute inset-y-1.5 rounded-[3px] bg-[var(--primary-bg)]" style={{ left: pos(a), width: width(a, b) }} />
                    ))}
                    {idle.map(([a, b]) => (
                        <span key={`i${a}`} className="day-idle absolute inset-y-1.5 rounded-[3px]" style={{ left: pos(a), width: width(a, b) }} />
                    ))}
                    {mark && <span className="absolute -inset-y-1 w-0.5 bg-ink" style={{ left: pos(mark) }} />}
                </div>
                <div className="num relative mt-1.5 h-5 text-[12px] text-muted">
                    {ticks.map((tick, i) => (
                        <span
                            key={tick}
                            className={`absolute ${i % 2 === 1 ? 'hidden sm:inline' : ''}`}
                            style={i === 0 ? { left: 0 } : i === ticks.length - 1 ? { right: 0 } : { left: pos(tick), transform: 'translateX(-50%)' }}
                        >
                            {hour.format(tick)}
                        </span>
                    ))}
                </div>
            </div>
        </section>
    );
}
