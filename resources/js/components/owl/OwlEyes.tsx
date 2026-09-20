import { useId } from 'react';

export type EyeState = 'open' | 'half' | 'closed' | 'attention';

interface Props {
    state?: EyeState;
    size?: number;
    /** Horizontal pupil offset: negative looks left, positive looks right. */
    look?: number;
    /** Accessible label; omit when a visible text label sits next to the eyes. */
    label?: string;
}

/**
 * The owl eyes motif (docs/DESIGN.md). Shape carries the state, not only color:
 * open = clocked in, half = PC quiet, closed = clocked out, attention = needs an answer (gold ring).
 * The ink outline keeps the gold iris visible on light surfaces (change G1).
 */
export function OwlEyes({ state = 'open', size = 48, look = 2, label }: Props) {
    const uid = useId().replace(/:/g, '');
    const pad = state === 'attention' ? 8 : 4;
    const height = size / 2 + (pad * size) / 50;

    const eye = (cx: number, side: 'left' | 'right') => {
        const cy = 30;
        const clipId = `${uid}-${side}`;
        const iris = (
            <>
                <circle cx={cx} cy={cy} r={8.6} fill="#C99A33" stroke="var(--eye-outline)" strokeWidth={2} />
                <circle cx={cx + look} cy={cy + 1} r={3.9} fill="#1A1A2E" />
                <circle cx={cx - 2} cy={cy - 3.2} r={1.7} fill="#FFFFFF" />
            </>
        );

        return (
            <g key={side}>
                {state === 'attention' && (
                    <>
                        <circle cx={cx} cy={cy} r={18.6} fill="none" stroke="#C99A33" strokeWidth={3.2} />
                        <circle cx={cx} cy={cy} r={20.6} fill="none" stroke="var(--eye-outline)" strokeWidth={1.1} />
                    </>
                )}
                <circle cx={cx} cy={cy} r={14} fill="var(--eye-socket)" stroke="var(--eye-outline)" strokeWidth={2.2} />
                {(state === 'open' || state === 'attention') && iris}
                {state === 'half' && (
                    <>
                        {iris}
                        <clipPath id={clipId}>
                            <circle cx={cx} cy={cy} r={13} />
                        </clipPath>
                        <rect x={cx - 15} y={cy - 15} width={30} height={15.5} fill="var(--eye-lid)" clipPath={`url(#${clipId})`} />
                        <path d={`M${cx - 13} ${cy + 0.5} L${cx + 13} ${cy + 0.5}`} stroke="var(--eye-outline)" strokeWidth={2} />
                    </>
                )}
                {state === 'closed' && (
                    <path
                        d={`M${cx - 8.5} ${cy + 0.5} Q${cx} ${cy + 7.5} ${cx + 8.5} ${cy + 0.5}`}
                        fill="none"
                        stroke="var(--eye-outline)"
                        strokeWidth={2.6}
                        strokeLinecap="round"
                    />
                )}
                <path
                    d={side === 'left' ? 'M5 9 Q25 3 49 21' : 'M95 9 Q75 3 51 21'}
                    fill="none"
                    stroke="var(--eye-brow)"
                    strokeWidth={6.5}
                    strokeLinecap="round"
                />
            </g>
        );
    };

    return (
        <svg
            viewBox={`${-pad} ${-pad} ${100 + pad * 2} ${50 + pad * 2}`}
            width={size}
            height={height}
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : true}
            className="block flex-none"
        >
            {eye(28, 'left')}
            {eye(72, 'right')}
        </svg>
    );
}
