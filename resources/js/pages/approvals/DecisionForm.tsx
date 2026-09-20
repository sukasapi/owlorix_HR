import { TextAreaField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { ArrowsClockwise, Check, NotePencil, X } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import type { OvertimeItem } from './types';
import type { DecisionState } from './useDecisions';

interface Props {
    item: OvertimeItem;
    decisions: DecisionState;
    onSubmit: (item: OvertimeItem, decision: 'approved' | 'rejected', note: string) => void;
    /** Opens the reject form right away (Tolak tapped on a phone card). */
    startInReject?: boolean;
}

type Mode = 'idle' | 'note' | 'reject' | 'change';

/**
 * Approve (optional note), reject (note required, 3.4.5), or change a decision (note required, 3.4.7). Server
 * refusals from DecideOvertime show here, next to the buttons that caused them.
 */
export function DecisionForm({ item, decisions, onSubmit, startInReject = false }: Props) {
    const t = useT();
    const locale = useLocale();
    const [mode, setMode] = useState<Mode>(startInReject ? 'reject' : 'idle');
    const [note, setNote] = useState('');
    const [localError, setLocalError] = useState<string | null>(null);
    const openerRef = useRef<HTMLButtonElement>(null);

    const mine = decisions.id === item.id;
    const processing = mine && decisions.processing;
    const serverError = mine ? decisions.errors.overtime : undefined;
    const noteError = localError ?? (mine ? decisions.errors.note : undefined);
    const name = item.person.name ?? '';
    const duration = formatMinutes(item.minutes, locale);
    const pending = item.status === 'pending';
    const opposite = item.status === 'approved' ? 'rejected' : 'approved';

    useEffect(() => setLocalError(null), [mode]);

    const cancel = () => {
        setMode('idle');
        setNote('');
        requestAnimationFrame(() => openerRef.current?.focus());
    };

    const submit = (event: FormEvent, decision: 'approved' | 'rejected', required: string | null) => {
        event.preventDefault();
        if (required && note.trim() === '') {
            setLocalError(required);
            return;
        }
        onSubmit(item, decision, note);
    };

    const refusal = serverError && (
        <Notice tone="danger">
            <p className="m-0">{serverError}</p>
            <button
                type="button"
                className="btn btn-secondary btn-sm mt-2 bg-surface"
                onClick={() => router.reload({ only: ['pending', 'decided', 'teams'] })}
            >
                <ArrowsClockwise weight="bold" size={16} aria-hidden />
                {t('approvals.decide.reload')}
            </button>
        </Notice>
    );

    if (!pending) {
        if (!item.can_change) return null;

        if (mode !== 'change') {
            return (
                <div className="flex flex-col gap-3 border-t border-line pt-4">
                    {refusal}
                    <div>
                        <button ref={openerRef} type="button" className="btn btn-secondary" onClick={() => setMode('change')}>
                            <NotePencil weight="bold" size={18} aria-hidden />
                            {t('approvals.decide.change')}
                        </button>
                    </div>
                </div>
            );
        }

        return (
            <form
                className="flex flex-col gap-3 border-t border-line pt-4"
                onSubmit={(e) => submit(e, opposite, t('approvals.decide.change_required'))}
                noValidate
            >
                <TextAreaField
                    label={t('approvals.decide.change_label')}
                    help={t('approvals.decide.change_help')}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    error={noteError}
                    maxLength={2000}
                    required
                    autoFocus
                />
                {refusal}
                <div className="flex flex-wrap gap-3">
                    <button type="submit" className={opposite === 'rejected' ? 'btn btn-danger' : 'btn btn-primary'} disabled={processing}>
                        {processing ? t('approvals.decide.sending') : t(`approvals.decide.change_to_${opposite}`)}
                    </button>
                    <button type="button" className="btn btn-quiet" onClick={cancel} disabled={processing}>
                        {t('approvals.decide.cancel')}
                    </button>
                </div>
            </form>
        );
    }

    const blocked = item.blocked !== null;

    if (mode === 'reject') {
        return (
            <form
                className="flex flex-col gap-3 border-t border-line pt-4"
                onSubmit={(e) => submit(e, 'rejected', t('approvals.decide.note_required'))}
                noValidate
            >
                <TextAreaField
                    label={t('approvals.decide.reject_label')}
                    help={t('approvals.decide.reject_help', { name })}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    error={noteError}
                    maxLength={2000}
                    required
                    autoFocus
                />
                {refusal}
                <div className="flex flex-wrap gap-3">
                    <button type="submit" className="btn btn-danger" disabled={processing || blocked}>
                        <X weight="bold" size={18} aria-hidden />
                        {processing ? t('approvals.decide.sending') : t('approvals.decide.reject_submit')}
                    </button>
                    <button type="button" className="btn btn-quiet" onClick={cancel} disabled={processing}>
                        {t('approvals.decide.cancel')}
                    </button>
                </div>
            </form>
        );
    }

    return (
        <form className="flex flex-col gap-3 border-t border-line pt-4" onSubmit={(e) => submit(e, 'approved', null)} noValidate>
            {mode === 'note' && (
                <TextAreaField
                    label={t('approvals.decide.note_label', { name })}
                    help={t('approvals.decide.note_optional')}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    error={noteError}
                    maxLength={2000}
                    autoFocus
                />
            )}
            {refusal}
            <div className="grid grid-cols-[1fr_1.4fr] gap-3 sm:flex sm:flex-wrap sm:items-center">
                <button type="submit" className="btn btn-primary order-2 sm:order-1" disabled={processing || blocked}>
                    <Check weight="bold" size={18} aria-hidden />
                    <span className="num">{processing ? t('approvals.decide.sending') : t('approvals.decide.approve', { duration })}</span>
                </button>
                {mode === 'note' ? (
                    <button type="button" className="btn btn-secondary order-1 sm:order-2" onClick={cancel} disabled={processing}>
                        {t('approvals.decide.cancel')}
                    </button>
                ) : (
                    <button
                        ref={openerRef}
                        type="button"
                        className="btn btn-secondary order-1 sm:order-2"
                        onClick={() => setMode('reject')}
                        disabled={processing || blocked}
                    >
                        {t('approvals.decide.reject')}
                    </button>
                )}
                {mode === 'idle' && (
                    <button
                        type="button"
                        className="btn btn-quiet col-span-2 justify-self-start sm:order-3"
                        onClick={() => setMode('note')}
                        disabled={processing || blocked}
                    >
                        {t('approvals.decide.add_note')}
                    </button>
                )}
            </div>
        </form>
    );
}
