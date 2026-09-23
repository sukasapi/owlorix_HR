interface Props {
    value: number;
    max: number;
    /** Share of max where the fill turns gold (attention), default 0.8 */
    warnAt?: number;
    label: string;
    /** Visible text next to the meter, for example "42 j dari 60 j" */
    text: string;
}

/**
 * Used against a limit (hour budget, weekly capacity). Teal while comfortable, gold from `warnAt`, red past the
 * limit. The text beside it always states the numbers, so color never carries the meaning alone.
 */
export function Meter({ value, max, warnAt = 0.8, label, text }: Props) {
    const ratio = max > 0 ? value / max : 0;
    const fill = ratio > 1 ? 'var(--danger)' : ratio >= warnAt ? 'var(--gold)' : 'var(--viz-1)';

    return (
        <div className="flex min-w-0 items-center gap-2">
            <div
                role="meter"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={max}
                aria-valuenow={Math.round(value)}
                aria-valuetext={text}
                className="relative h-2.5 min-w-[64px] flex-1 overflow-hidden rounded-full"
                style={{ background: 'color-mix(in srgb, var(--viz-1) 18%, transparent)' }}
            >
                <span className="absolute inset-y-0 left-0 rounded-full" style={{ width: `${Math.min(100, ratio * 100)}%`, background: fill }} />
            </div>
            <span className="num shrink-0 text-sm">{text}</span>
        </div>
    );
}
