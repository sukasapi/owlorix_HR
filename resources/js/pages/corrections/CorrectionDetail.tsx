import { TextAreaField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { formatDateTime, formatLongDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, ArrowsClockwise, Check, X } from '@phosphor-icons/react';
import { type FormEvent, type RefObject, useEffect, useRef, useState } from 'react';
import { StatusChip } from './CorrectionList';
import { PreviewPanel, type PreviewState } from './PreviewPanel';
import { requestJson, studioParts } from './requests';
import type { CorrectionItem, PreviewResponse } from './types';

interface Props {
    item: CorrectionItem;
    onBack: () => void;
    onDecided: () => void;
    headingRef: RefObject<HTMLHeadingElement | null>;
}

/** The correction being looked at: the focal panel of Koreksi, with apply and decline for Superadmin. */
export function CorrectionDetail({ item, onBack, onDecided, headingRef }: Props) {
    const t = useT();
    const locale = useLocale();
    const fieldLabel = t(`corrections.fields.${item.field}`);
    const before = item.status === 'proposed' && item.can_decide ? item.current_value : item.old_value;

    return (
        <article className="flex flex-col gap-5" aria-labelledby={`correction-${item.id}-name`}>
            <button type="button" className="btn btn-quiet -ml-1.5 self-start lg:hidden" onClick={onBack}>
                <ArrowLeft weight="bold" size={18} aria-hidden />
                {t('corrections.detail.back')}
            </button>

            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="avatar h-11 w-11 text-[15px]" aria-hidden>
                        {item.person.initials}
                    </span>
                    <div className="min-w-0">
                        <h2 id={`correction-${item.id}-name`} ref={headingRef} tabIndex={-1} className="h2 text-[22px] break-words sm:text-[24px]">
                            {item.person.name}
                        </h2>
                        <p className="m-0 text-sm text-muted">{item.work_date ? formatLongDate(item.work_date, locale) : ''}</p>
                    </div>
                </div>
                <StatusChip status={item.status} />
            </header>

            <section className="brow flex flex-col gap-2 px-5 py-4 sm:px-6" aria-label={fieldLabel}>
                <p className="m-0 text-sm font-semibold text-muted">{fieldLabel}</p>
                <div className="flex flex-wrap items-end gap-x-5 gap-y-2">
                    <div className="flex flex-col">
                        <span className="text-[13px] font-semibold text-muted">
                            {item.status === 'applied' ? t('corrections.detail.was') : t('corrections.detail.recorded')}
                        </span>
                        <span className="display num text-[32px] text-muted sm:text-[40px]">{before ? formatTime(before, locale) : t('corrections.detail.recorded_none')}</span>
                    </div>
                    {/* The arrow marks the direction of the change; the labels above each time carry the meaning */}
                    <ArrowRight weight="bold" size={28} className="mb-2.5 text-muted" aria-hidden />
                    <div className="flex flex-col">
                        <span className="text-[13px] font-semibold text-muted">
                            {item.status === 'applied' ? t('corrections.detail.now') : t('corrections.detail.should_be')}
                        </span>
                        <span className="display num text-[40px] text-heading sm:text-[64px]">{formatTime(item.new_value, locale)}</span>
                    </div>
                </div>
            </section>

            <dl className="m-0 grid gap-x-6 gap-y-4 sm:grid-cols-[140px_1fr]">
                <dt className="text-sm font-semibold text-muted">{t('corrections.detail.reason')}</dt>
                <dd className="m-0 break-words whitespace-pre-line">{item.reason}</dd>
                <dt className="text-sm font-semibold text-muted">{t('corrections.detail.proposed_by')}</dt>
                <dd className="num m-0">
                    {item.is_direct
                        ? t('corrections.item.direct')
                        : t('corrections.detail.proposed_line', {
                              name: item.proposed_by ?? '',
                              when: item.proposed_at ? formatDateTime(item.proposed_at, locale) : '',
                          })}
                </dd>
                {item.status !== 'proposed' && (
                    <>
                        <dt className="text-sm font-semibold text-muted">{t('corrections.detail.decided_by')}</dt>
                        <dd className="num m-0">
                            {t('corrections.detail.decided_line', {
                                name: item.decided_by ?? '',
                                when: item.decided_at ? formatDateTime(item.decided_at, locale) : '',
                            })}
                        </dd>
                    </>
                )}
                {item.decision_note && (
                    <>
                        <dt className="text-sm font-semibold text-muted">{t('corrections.detail.note')}</dt>
                        <dd className="m-0 break-words whitespace-pre-line">{item.decision_note}</dd>
                    </>
                )}
            </dl>

            {item.closed_month && <Notice>{t('corrections.detail.closed_month')}</Notice>}

            {item.status === 'proposed' && item.can_decide && item.changed_since && (
                <Notice>
                    {item.current_value
                        ? t('corrections.detail.changed_since', { field: fieldLabel, time: formatTime(item.current_value, locale) })
                        : t('corrections.detail.changed_since_none', { field: fieldLabel })}
                </Notice>
            )}

            {item.status === 'proposed' && !item.can_decide && <p className="m-0 text-sm text-muted">{t('corrections.detail.waiting_superadmin')}</p>}

            {item.status === 'proposed' && item.can_decide && <Decision key={`${item.id}-${item.current_value}`} item={item} onDecided={onDecided} />}
        </article>
    );
}

type Mode = 'idle' | 'decline';

function Decision({ item, onDecided }: { item: CorrectionItem; onDecided: () => void }) {
    const t = useT();
    const [mode, setMode] = useState<Mode>('idle');
    const [note, setNote] = useState('');
    const [noteError, setNoteError] = useState<string | null>(null);
    const [refusal, setRefusal] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [preview, setPreview] = useState<PreviewState>({ kind: 'loading' });
    const [attempt, setAttempt] = useState(0);
    const declineRef = useRef<HTMLButtonElement>(null);

    // What applying it would do now, calculated without saving
    useEffect(() => {
        const controller = new AbortController();
        const { date, time } = studioParts(item.new_value);
        setPreview({ kind: 'loading' });
        requestJson<PreviewResponse>(t, 'post', route('corrections.preview'), { shift_id: item.shift_id, field: item.field, date, time }, controller.signal)
            .then((result) => {
                if (result.ok) setPreview({ kind: 'ready', data: result.data });
                else setPreview(result.errors ? { kind: 'refused', message: result.message } : { kind: 'failed', message: result.message });
            })
            .catch(() => undefined);

        return () => controller.abort();
    }, [item.id, item.shift_id, item.field, item.new_value, attempt]);

    const handlers = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            setProcessing(true);
            setRefusal(null);
        },
        onSuccess: () => onDecided(),
        onError: (errors: Record<string, string>) => {
            if (errors.note) setNoteError(errors.note);
            setRefusal(errors.correction ?? null);
        },
        onHttpException: (response: { status: number }) => {
            setRefusal(
                response.status === 419
                    ? t('corrections.errors.expired')
                    : response.status === 403
                      ? t('corrections.errors.forbidden')
                      : response.status === 404
                        ? t('corrections.errors.not_found')
                        : t('corrections.errors.request_failed', { status: response.status }),
            );
            return false;
        },
        onNetworkError: () => {
            setRefusal(t('corrections.errors.network'));
            return false;
        },
        onFinish: () => setProcessing(false),
    };

    const applyNow = () => router.post(route('corrections.apply', item.id), { seen_value: item.current_value }, handlers);

    const decline = (event: FormEvent) => {
        event.preventDefault();
        if (note.trim() === '') {
            setNoteError(t('corrections.decide.decline_required'));
            return;
        }
        router.post(route('corrections.decline', item.id), { note }, handlers);
    };

    const refusalNotice = refusal && (
        <Notice tone="danger">
            <p className="m-0">{refusal}</p>
            <button type="button" className="btn btn-secondary btn-sm mt-2 min-h-11 bg-surface" onClick={() => router.reload({ only: ['waiting', 'history'] })}>
                <ArrowsClockwise weight="bold" size={16} aria-hidden />
                {t('corrections.decide.reload')}
            </button>
        </Notice>
    );

    if (mode === 'decline') {
        return (
            <form className="flex flex-col gap-3 border-t border-line pt-4" onSubmit={decline} noValidate>
                <TextAreaField
                    label={t('corrections.decide.decline_label')}
                    help={t('corrections.decide.decline_help')}
                    value={note}
                    onChange={(e) => {
                        setNote(e.target.value);
                        setNoteError(null);
                    }}
                    error={noteError ?? undefined}
                    maxLength={2000}
                    required
                    autoFocus
                />
                {refusalNotice}
                <div className="flex flex-wrap gap-3">
                    <button type="submit" className="btn btn-danger" disabled={processing}>
                        <X weight="bold" size={18} aria-hidden />
                        {processing ? t('corrections.decide.sending') : t('corrections.decide.decline_submit')}
                    </button>
                    <button
                        type="button"
                        className="btn btn-quiet"
                        disabled={processing}
                        onClick={() => {
                            setMode('idle');
                            setNote('');
                            setNoteError(null);
                            requestAnimationFrame(() => declineRef.current?.focus());
                        }}
                    >
                        {t('corrections.decide.cancel')}
                    </button>
                </div>
            </form>
        );
    }

    const refused = preview.kind === 'refused';

    return (
        <div className="flex flex-col gap-4 border-t border-line pt-4">
            <PreviewPanel state={preview} onRetry={() => setAttempt((n) => n + 1)} />
            {refused && <p className="m-0 text-sm">{t('corrections.decide.blocked')}</p>}
            {refusalNotice}
            <div className="flex flex-wrap gap-3">
                <button type="button" className="btn btn-primary" onClick={applyNow} disabled={processing || refused || preview.kind === 'loading'}>
                    <Check weight="bold" size={18} aria-hidden />
                    {processing ? t('corrections.decide.sending') : t('corrections.decide.apply')}
                </button>
                <button ref={declineRef} type="button" className="btn btn-secondary" onClick={() => setMode('decline')} disabled={processing}>
                    {t('corrections.decide.decline')}
                </button>
            </div>
        </div>
    );
}
