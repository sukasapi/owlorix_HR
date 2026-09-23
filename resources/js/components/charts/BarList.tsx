import { Link } from '@inertiajs/react';
import { type ReactNode, useRef, useState } from 'react';
import { type Format, plainNumber, type Series, type TipContent, Tooltip } from './core';

export interface BarRow {
    key: string | number;
    label: string;
    /** Second line under the label (a code, a team) */
    sub?: string;
    href?: string;
    /** One value per series, in series order */
    values: number[];
    /** A reference value drawn as a tick on the bar, such as an hour budget */
    marker?: number | null;
    /** Text at the end of the bar; defaults to the formatted total */
    end?: ReactNode;
}

interface Props {
    rows: BarRow[];
    series: Series[];
    format?: Format;
    markerLabel?: string;
    /** Shared scale maximum; defaults to the largest total or marker */
    max?: number;
    label: string;
}

/**
 * Horizontal bars, one row per item, stacked by series with a 2px gap. Built in HTML so long Indonesian labels
 * wrap on phones instead of being clipped.
 */
export function BarList({ rows, series, format = plainNumber, markerLabel, max, label }: Props) {
    const box = useRef<HTMLDivElement>(null);
    const [hover, setHover] = useState<{ index: number; x: number; y: number } | null>(null);
    const totals = rows.map((r) => r.values.reduce((a, b) => a + b, 0));
    const scale = max ?? Math.max(1, ...totals, ...rows.map((r) => r.marker ?? 0));

    const tip: TipContent | null =
        hover === null || !rows[hover.index]
            ? null
            : {
                  title: rows[hover.index].label,
                  lines: [
                      ...series.map((s, i) => ({ color: s.color, label: s.label, value: format(rows[hover.index].values[i] ?? 0) })),
                      ...(rows[hover.index].marker != null && markerLabel ? [{ label: markerLabel, value: format(rows[hover.index].marker as number) }] : []),
                  ],
              };

    return (
        <div ref={box} className="relative" onPointerLeave={() => setHover(null)}>
            <ul className="m-0 flex list-none flex-col gap-3 p-0" aria-label={label}>
                {rows.map((row, i) => (
                    <li
                        key={row.key}
                        className="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,180px)_minmax(0,1fr)] sm:items-center sm:gap-3"
                        onPointerMove={(e) => {
                            const rect = box.current?.getBoundingClientRect();
                            if (rect) setHover({ index: i, x: e.clientX - rect.left, y: e.clientY - rect.top });
                        }}
                    >
                        <div className="min-w-0 text-sm">
                            {row.href ? (
                                <Link href={row.href} className="link font-medium break-words">
                                    {row.label}
                                </Link>
                            ) : (
                                <span className="font-medium break-words">{row.label}</span>
                            )}
                            {row.sub && <div className="truncate text-xs text-muted">{row.sub}</div>}
                        </div>
                        <div className="flex min-w-0 items-center gap-2">
                            <div className="relative flex h-4 min-w-0 flex-1 gap-[2px]">
                                {row.values.map((v, j) =>
                                    v > 0 ? (
                                        <span
                                            key={j}
                                            className="block h-full"
                                            style={{
                                                width: `${(v / scale) * 100}%`,
                                                background: series[j]?.color ?? 'var(--viz-1)',
                                                borderRadius: j === row.values.reduce((acc, x, k) => (x > 0 ? k : acc), -1) ? '0 4px 4px 0' : 0,
                                            }}
                                        />
                                    ) : null,
                                )}
                                {row.marker != null && row.marker > 0 && (
                                    <span aria-hidden className="absolute -top-1 -bottom-1 w-[2px] bg-ink" style={{ left: `calc(${Math.min(100, (row.marker / scale) * 100)}% - 1px)` }} />
                                )}
                            </div>
                            <span className="num shrink-0 text-right text-sm font-semibold">{row.end ?? format(totals[i])}</span>
                        </div>
                    </li>
                ))}
            </ul>
            <Tooltip tip={tip} x={hover?.x ?? 0} y={hover?.y ?? 0} width={box.current?.clientWidth ?? 600} />
        </div>
    );
}
