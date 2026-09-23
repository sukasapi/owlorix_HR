import { useState } from 'react';
import { type Format, labelStride, niceTicks, plainNumber, type Series, type TipContent, Tooltip, useWidth } from './core';

interface Props {
    /** Category labels along x, oldest first */
    categories: { label: string; title?: string }[];
    series: (Series & { values: number[] })[];
    format?: Format;
    height?: number;
    label: string;
}

const PAD = { top: 14, right: 16, bottom: 26, left: 40 };

/** Lines over time (at most three series). Hover shows a crosshair and every series value at that point. */
export function LineChart({ categories, series, format = plainNumber, height = 220, label }: Props) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<{ index: number; x: number; y: number } | null>(null);

    const max = Math.max(0, ...series.flatMap((s) => s.values));
    const ticks = niceTicks(max, 4, series.every((s) => s.values.every(Number.isInteger)));
    const top = ticks[ticks.length - 1] || 1;
    const plotW = Math.max(10, width - PAD.left - PAD.right);
    const plotH = height - PAD.top - PAD.bottom;
    const n = categories.length;
    const x = (i: number) => PAD.left + (n <= 1 ? plotW / 2 : (plotW * i) / (n - 1));
    const y = (v: number) => PAD.top + plotH - (v / top) * plotH;
    const stride = labelStride(n, plotW);

    const tip: TipContent | null =
        hover === null || !categories[hover.index]
            ? null
            : {
                  title: categories[hover.index].title ?? categories[hover.index].label,
                  lines: series.map((s) => ({ color: s.color, label: s.label, value: format(s.values[hover.index] ?? 0) })),
              };

    const pick = (clientX: number, svg: SVGSVGElement, clientY: number) => {
        const box = svg.getBoundingClientRect();
        const px = clientX - box.left;
        const index = n <= 1 ? 0 : Math.max(0, Math.min(n - 1, Math.round(((px - PAD.left) / plotW) * (n - 1))));
        setHover({ index, x: px, y: clientY - box.top });
    };

    return (
        <div ref={ref} className="relative w-full" onPointerLeave={() => setHover(null)}>
            <svg
                width={width}
                height={height}
                role="img"
                aria-label={label}
                className="block overflow-visible"
                onPointerMove={(e) => pick(e.clientX, e.currentTarget, e.clientY)}
            >
                {ticks.map((tick) => (
                    <g key={tick}>
                        <line x1={PAD.left} x2={width - PAD.right} y1={y(tick)} y2={y(tick)} stroke={tick === 0 ? 'var(--viz-axis)' : 'var(--viz-grid)'} strokeWidth={1} />
                        <text x={PAD.left - 6} y={y(tick)} dy="0.32em" textAnchor="end" fontSize={11} fill="var(--muted)" className="num">
                            {format(tick)}
                        </text>
                    </g>
                ))}
                {categories.map((c, i) =>
                    i % stride === 0 ? (
                        <text key={i} x={x(i)} y={height - 8} textAnchor="middle" fontSize={11} fill="var(--muted)">
                            {c.label}
                        </text>
                    ) : null,
                )}
                {hover && categories[hover.index] && <line x1={x(hover.index)} x2={x(hover.index)} y1={PAD.top} y2={PAD.top + plotH} stroke="var(--viz-axis)" strokeWidth={1} />}
                {series.map((s) => (
                    <g key={s.key}>
                        <polyline
                            points={s.values.map((v, i) => `${x(i)},${y(v)}`).join(' ')}
                            fill="none"
                            stroke={s.color}
                            strokeWidth={2}
                            strokeLinejoin="round"
                            strokeLinecap="round"
                        />
                        {/* End dot with a 2px surface ring, and the hovered point */}
                        {[n - 1, ...(hover && hover.index !== n - 1 ? [hover.index] : [])].map((i) =>
                            i >= 0 && s.values[i] !== undefined ? <circle key={i} cx={x(i)} cy={y(s.values[i])} r={4} fill={s.color} stroke="var(--surface)" strokeWidth={2} /> : null,
                        )}
                    </g>
                ))}
            </svg>
            <Tooltip tip={tip} x={hover?.x ?? 0} y={hover?.y ?? 0} width={width} />
        </div>
    );
}
