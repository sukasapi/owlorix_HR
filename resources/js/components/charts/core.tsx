import { useT } from '@/lib/i18n';
import { type ReactNode, useId, useLayoutEffect, useRef, useState } from 'react';

/**
 * Shared pieces of the chart kit (docs/14): width tracking, tick maths, the frame with legend and table view,
 * and the hover tooltip. Charts are plain SVG; colors come from the --viz-* tokens in app.css.
 */

export interface Series {
    key: string;
    label: string;
    /** A CSS color, normally `var(--viz-1)` or `var(--viz-step-2)` */
    color: string;
}

export type Format = (value: number) => string;

export const plainNumber: Format = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(value);

/** Tracks the rendered width of a container so the SVG is drawn at 1:1 and text keeps its size. */
export function useWidth<T extends HTMLElement>(fallback = 600) {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(fallback);

    useLayoutEffect(() => {
        const node = ref.current;
        if (!node) return;
        setWidth(node.clientWidth || fallback);
        if (typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(([entry]) => setWidth(Math.max(200, Math.round(entry.contentRect.width))));
        observer.observe(node);
        return () => observer.disconnect();
    }, [fallback]);

    return [ref, width] as const;
}

/** Round axis ticks from zero: 0 / 5 / 10 / 15, never odd steps. Whole-number data (counts) never gets 0,5 ticks. */
export function niceTicks(max: number, count = 4, integer = false): number[] {
    if (!(max > 0)) return [0, 1];
    const raw = max / count;
    const power = 10 ** Math.floor(Math.log10(raw));
    const nice = [1, 2, 2.5, 5, 10].map((m) => m * power).find((s) => s >= raw) ?? raw;
    const step = integer ? Math.max(1, Math.ceil(nice)) : nice;
    const top = Math.ceil(max / step) * step;
    const ticks: number[] = [];
    for (let v = 0; v <= top + step / 2; v += step) ticks.push(Math.round(v * 1000) / 1000);
    return ticks;
}

/** Shows every n-th category label so x labels never collide. */
export function labelStride(count: number, width: number, labelWidth = 56): number {
    return Math.max(1, Math.ceil((count * labelWidth) / Math.max(width, 1)));
}

/** A bar or column with a 4px rounded data end and a square end at the baseline. */
export function roundedBar(x: number, y: number, w: number, h: number, end: 'top' | 'right', radius = 4): string {
    const r = Math.max(0, Math.min(radius, end === 'top' ? w / 2 : h / 2, end === 'top' ? h : w));
    if (h <= 0 || w <= 0) return '';
    if (end === 'top') {
        return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
    }
    return `M${x},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h - r}Q${x + w},${y + h} ${x + w - r},${y + h}H${x}Z`;
}

export function Legend({ series }: { series: Series[] }) {
    if (series.length < 2) return null;
    return (
        <ul className="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-sm text-muted">
            {series.map((s) => (
                <li key={s.key} className="flex items-center gap-1.5">
                    <span className="viz-key" style={{ background: s.color }} aria-hidden />
                    {s.label}
                </li>
            ))}
        </ul>
    );
}

export interface TableView {
    columns: string[];
    rows: (string | number)[][];
}

interface FrameProps {
    /** The question the chart answers, used as its heading */
    title: string;
    subtitle?: ReactNode;
    series?: Series[];
    table: TableView;
    empty?: boolean;
    emptyText?: string;
    /** Heading level inside the page */
    as?: 'h2' | 'h3';
    children: ReactNode;
    className?: string;
}

/** Card with the question as title, a legend for two or more series, the chart, and a table view for keyboard and screen readers. */
export function ChartFrame({ title, subtitle, series = [], table, empty = false, emptyText, as: Heading = 'h2', children, className = '' }: FrameProps) {
    const t = useT();
    const [showTable, setShowTable] = useState(false);
    const tableId = useId();

    return (
        <figure className={`card m-0 flex min-w-0 flex-col gap-3 p-4 sm:p-5 ${className}`}>
            <figcaption className="flex flex-col gap-1">
                <Heading className="h2 text-[17px]">{title}</Heading>
                {subtitle && <p className="m-0 text-sm text-muted">{subtitle}</p>}
            </figcaption>
            {empty ? (
                <p className="m-0 py-6 text-sm text-muted">{emptyText ?? t('common.charts.empty')}</p>
            ) : (
                <>
                    <Legend series={series} />
                    {children}
                    <div>
                        <button type="button" className="btn btn-quiet btn-sm min-h-9" aria-expanded={showTable} aria-controls={tableId} onClick={() => setShowTable((v) => !v)}>
                            {showTable ? t('common.charts.hide_table') : t('common.charts.show_table')}
                        </button>
                        {showTable && (
                            <div id={tableId} className="table-wrap mt-2">
                                <table className="table">
                                    <thead>
                                        <tr>
                                            {table.columns.map((c) => (
                                                <th key={c} scope="col">
                                                    {c}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {table.rows.map((row, i) => (
                                            <tr key={i}>
                                                {row.map((cell, j) => (
                                                    <td key={j} className={j > 0 ? 'num' : undefined}>
                                                        {cell}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </>
            )}
        </figure>
    );
}

export interface TipContent {
    title: string;
    lines: { color?: string; label: string; value: string }[];
}

/** Tooltip positioned inside the chart container, flipped so it never leaves the card. */
export function Tooltip({ tip, x, y, width }: { tip: TipContent | null; x: number; y: number; width: number }) {
    if (!tip) return null;
    const left = x > width - 170 ? Math.max(0, x - 180) : x + 14;
    return (
        <div className="viz-tip" style={{ left, top: Math.max(0, y - 10) }} role="presentation">
            <div className="mb-1 font-semibold">{tip.title}</div>
            {tip.lines.map((line) => (
                <div key={line.label} className="flex items-center justify-between gap-3">
                    <span className="flex min-w-0 items-center gap-1.5 text-muted">
                        {line.color && <span className="viz-key" style={{ background: line.color }} aria-hidden />}
                        <span className="truncate">{line.label}</span>
                    </span>
                    <span className="num font-semibold">{line.value}</span>
                </div>
            ))}
        </div>
    );
}
