import { useT } from '@/lib/i18n';
import { useState } from 'react';
import { type Format, labelStride, niceTicks, plainNumber, roundedBar, type Series, type TipContent, Tooltip, useWidth } from './core';

export interface ColumnDatum {
    /** Short label under the column */
    label: string;
    /** Longer label for the tooltip, defaults to `label` */
    title?: string;
    /** One value per series, in series order */
    values: number[];
}

interface Props {
    data: ColumnDatum[];
    series: Series[];
    format?: Format;
    height?: number;
    /** Accessible summary of what the chart shows */
    label: string;
}

const PAD = { top: 12, right: 8, bottom: 26, left: 40 };

/** Columns over time. Several series stack in series order with a 2px surface gap between segments. */
export function ColumnChart({ data, series, format = plainNumber, height = 220, label }: Props) {
    const t = useT();
    const [ref, width] = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<{ index: number; x: number; y: number } | null>(null);

    const totals = data.map((d) => d.values.reduce((a, b) => a + b, 0));
    const ticks = niceTicks(Math.max(0, ...totals), 4, data.every((d) => d.values.every(Number.isInteger)));
    const top = ticks[ticks.length - 1] || 1;
    const plotW = Math.max(10, width - PAD.left - PAD.right);
    const plotH = height - PAD.top - PAD.bottom;
    const band = plotW / Math.max(1, data.length);
    const barW = Math.min(24, Math.max(4, band * 0.62));
    const y = (v: number) => PAD.top + plotH - (v / top) * plotH;
    const stride = labelStride(data.length, plotW);

    const tip: TipContent | null =
        hover === null || !data[hover.index]
            ? null
            : {
                  title: data[hover.index].title ?? data[hover.index].label,
                  lines: [
                      ...series.map((s, i) => ({ color: s.color, label: s.label, value: format(data[hover.index].values[i] ?? 0) })),
                      ...(series.length > 1 ? [{ label: t('common.charts.total'), value: format(totals[hover.index]) }] : []),
                  ],
              };

    return (
        <div ref={ref} className="relative w-full" onPointerLeave={() => setHover(null)}>
            <svg width={width} height={height} role="img" aria-label={label} className="block overflow-visible">
                {ticks.map((tick) => (
                    <g key={tick}>
                        <line x1={PAD.left} x2={width - PAD.right} y1={y(tick)} y2={y(tick)} stroke={tick === 0 ? 'var(--viz-axis)' : 'var(--viz-grid)'} strokeWidth={1} />
                        <text x={PAD.left - 6} y={y(tick)} dy="0.32em" textAnchor="end" fontSize={11} fill="var(--muted)" className="num">
                            {format(tick)}
                        </text>
                    </g>
                ))}
                {data.map((d, i) => {
                    const cx = PAD.left + band * i + band / 2;
                    let base = 0;
                    let drawn = 0;
                    const last = d.values.reduce((acc, v, j) => (v > 0 ? j : acc), -1);
                    return (
                        <g key={i}>
                            {hover?.index === i && <rect x={PAD.left + band * i} y={PAD.top} width={band} height={plotH} fill="var(--selected)" opacity={0.6} />}
                            {d.values.map((v, j) => {
                                if (v <= 0) return null;
                                const y0 = y(base);
                                base += v;
                                const y1 = y(base);
                                // 2px surface gap between stacked segments, never under the first one drawn
                                const h = Math.max(1, y0 - y1 - (drawn++ === 0 ? 0 : 2));
                                return <path key={j} d={roundedBar(cx - barW / 2, y1, barW, h, 'top', j === last ? 4 : 0)} fill={series[j]?.color ?? 'var(--viz-1)'} />;
                            })}
                            {i % stride === 0 && (
                                <text x={cx} y={height - 8} textAnchor="middle" fontSize={11} fill="var(--muted)">
                                    {d.label}
                                </text>
                            )}
                            <rect
                                x={PAD.left + band * i}
                                y={PAD.top}
                                width={band}
                                height={plotH}
                                fill="transparent"
                                onPointerMove={(e) => {
                                    const box = (e.currentTarget.ownerSVGElement as SVGSVGElement).getBoundingClientRect();
                                    setHover({ index: i, x: e.clientX - box.left, y: e.clientY - box.top });
                                }}
                            />
                        </g>
                    );
                })}
            </svg>
            <Tooltip tip={tip} x={hover?.x ?? 0} y={hover?.y ?? 0} width={width} />
        </div>
    );
}
