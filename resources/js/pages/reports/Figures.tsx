import { formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { CheckCircle, HourglassMedium, Warning, XCircle } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import type { OvertimeStatus, ShiftNote, Totals } from './types';

/** A duration, with zero shown quieter so the non-zero numbers stand out when scanning a column. */
export function Duration({ minutes }: { minutes: number }) {
    const locale = useLocale();
    if (minutes === 0) return <span className="text-muted">0</span>;
    return <span className="whitespace-nowrap">{formatMinutes(minutes, locale)}</span>;
}

/** A count. A flagged count above zero (shifts to check) gets weight and a warning icon, so it is found without color. */
export function Count({ value, flag = false }: { value: number; flag?: boolean }) {
    if (value === 0) return <span className="text-muted">0</span>;
    if (!flag) return <span>{value}</span>;
    return (
        <span className="inline-flex items-center gap-1 font-semibold">
            <Warning weight="bold" size={14} aria-hidden />
            {value}
        </span>
    );
}

export function OvertimeChip({ status }: { status: OvertimeStatus }) {
    const t = useT();
    const Icon = status === 'approved' ? CheckCircle : status === 'rejected' ? XCircle : HourglassMedium;
    const tone = status === 'approved' ? 'chip-ok' : status === 'rejected' ? 'chip-bad' : 'chip-pending';

    return (
        <span className={`chip ${tone}`}>
            <Icon weight="bold" size={14} aria-hidden />
            {t(`reports.overtime_status.${status}`)}
        </span>
    );
}

const ATTENTION: ShiftNote[] = ['needs_review', 'clock_mismatch', 'gap_unverified', 'report_due'];

export function NoteChips({ notes }: { notes: ShiftNote[] }) {
    const t = useT();
    if (notes.length === 0) return <span className="text-muted">{t('reports.detail.none')}</span>;

    return (
        <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0">
            {notes.map((note) => (
                <li key={note} className="chip">
                    {ATTENTION.includes(note) && <Warning weight="bold" size={14} aria-hidden />}
                    {t(`reports.note_labels.${note}`)}
                </li>
            ))}
        </ul>
    );
}

export type Metric = { key: string; label: string; value: ReactNode };

/** The month numbers as label and value pairs, for stacked cards on narrow screens. */
export function useMetrics(totals: Totals): { main: Metric[]; flags: Metric[] } {
    const t = useT();

    return {
        main: [
            { key: 'days', label: t('reports.columns.days_worked'), value: <Count value={totals.days_worked} /> },
            { key: 'leave', label: t('reports.columns.leave_days'), value: <Count value={totals.leave_days} /> },
            { key: 'regular', label: t('reports.columns.regular'), value: <Duration minutes={totals.regular_minutes} /> },
            { key: 'approved', label: t('reports.columns.approved'), value: <Duration minutes={totals.overtime_approved_minutes} /> },
            { key: 'pending', label: t('reports.columns.pending'), value: <Duration minutes={totals.overtime_pending_minutes} /> },
            { key: 'rejected', label: t('reports.columns.rejected'), value: <Duration minutes={totals.overtime_rejected_minutes} /> },
            { key: 'idle', label: t('reports.columns.idle'), value: <Duration minutes={totals.idle_minutes} /> },
        ],
        flags: [
            { key: 'short', label: t('reports.columns.short_days'), value: <Count value={totals.short_days} /> },
            { key: 'non_workday', label: t('reports.columns.non_workday'), value: <Count value={totals.non_workday_shifts} /> },
            { key: 'review', label: t('reports.columns.review'), value: <Count value={totals.review_shifts} flag /> },
            { key: 'late', label: t('reports.columns.late_claims'), value: <Count value={totals.late_claims} /> },
        ],
    };
}

export function MetricList({ metrics, className = '' }: { metrics: Metric[]; className?: string }) {
    return (
        <dl className={`m-0 grid grid-cols-2 gap-x-4 gap-y-2.5 text-sm ${className}`}>
            {metrics.map((metric) => (
                <div key={metric.key} className="min-w-0">
                    <dt className="text-[13px] text-muted">{metric.label}</dt>
                    <dd className="num m-0 font-semibold">{metric.value}</dd>
                </div>
            ))}
        </dl>
    );
}
