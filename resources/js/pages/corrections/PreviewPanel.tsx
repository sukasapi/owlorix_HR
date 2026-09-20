import { Notice } from '@/components/ui/Notice';
import { formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { ArrowsClockwise } from '@phosphor-icons/react';
import type { PreviewResponse } from './types';

export type PreviewState =
    | { kind: 'idle' }
    | { kind: 'loading' }
    | { kind: 'refused'; message: string }
    | { kind: 'failed'; message: string }
    | { kind: 'ready'; data: PreviewResponse };

interface Props {
    state: PreviewState;
    onRetry: () => void;
    /** Hide the refusal text when the form already shows it under the time field. */
    refusalShownElsewhere?: boolean;
}

/**
 * The minutes of the work date before and after a correction, as the server calculates them without saving. Changes
 * are written as words next to the numbers, so they never depend on color.
 */
export function PreviewPanel({ state, onRetry, refusalShownElsewhere = false }: Props) {
    const t = useT();

    return (
        <section className="flex flex-col gap-3 rounded-md border border-line px-4 py-3.5" aria-live="polite" aria-busy={state.kind === 'loading'}>
            <h3 className="m-0 text-[15px] font-semibold">{t('corrections.preview.title')}</h3>

            {state.kind === 'idle' && <p className="m-0 text-sm text-muted">{t('corrections.preview.idle')}</p>}
            {state.kind === 'loading' && <p className="m-0 text-sm text-muted">{t('corrections.preview.loading')}</p>}
            {state.kind === 'refused' && (
                <p className="m-0 text-sm text-muted">
                    {t('corrections.preview.refused')}
                    {!refusalShownElsewhere && ` ${state.message}`}
                </p>
            )}
            {state.kind === 'failed' && (
                <div className="flex flex-col items-start gap-2">
                    <Notice tone="danger">{state.message}</Notice>
                    <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={onRetry}>
                        <ArrowsClockwise weight="bold" size={16} aria-hidden />
                        {t('corrections.preview.retry')}
                    </button>
                </div>
            )}
            {state.kind === 'ready' && <PreviewResult data={state.data} />}
        </section>
    );
}

function PreviewResult({ data }: { data: PreviewResponse }) {
    const t = useT();
    const locale = useLocale();
    const others = data.shifts.filter(
        (shift) =>
            !shift.is_target &&
            shift.before !== null &&
            (shift.before.regular_minutes !== shift.after.regular_minutes || shift.before.overtime_minutes !== shift.after.overtime_minutes),
    );

    const rows: { key: string; label: string; before: number; after: number }[] = [
        { key: 'regular', label: t('corrections.preview.regular'), before: data.date.before.regular_minutes, after: data.date.after.regular_minutes },
        { key: 'overtime', label: t('corrections.preview.overtime'), before: data.date.before.overtime_minutes, after: data.date.after.overtime_minutes },
    ];

    return (
        <>
            <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
                {rows.map((row) => (
                    <div key={row.key} className="contents">
                        <dt className="text-sm font-semibold text-muted">{row.label}</dt>
                        <dd className="num m-0 text-[15px]">
                            {row.before === row.after ? (
                                <>
                                    {formatMinutes(row.after, locale)} <span className="text-muted">({t('corrections.preview.unchanged')})</span>
                                </>
                            ) : (
                                <>
                                    <span className="sr-only">
                                        {t('corrections.item.change', { from: formatMinutes(row.before, locale), to: formatMinutes(row.after, locale) })}
                                    </span>
                                    {/* The arrow reads before-to-after at a glance; screen readers get the sentence above */}
                                    <span aria-hidden>
                                        <span className="text-muted">{formatMinutes(row.before, locale)}</span> &rarr;{' '}
                                        <strong className="font-semibold">{formatMinutes(row.after, locale)}</strong>
                                    </span>
                                </>
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
            {others.length > 0 && <p className="m-0 text-sm">{t('corrections.preview.other_shifts')}</p>}
            {data.overtime_reset && <Notice>{t('corrections.preview.overtime_reset')}</Notice>}
            {data.closed_month && <Notice>{t('corrections.preview.closed_month')}</Notice>}
        </>
    );
}
