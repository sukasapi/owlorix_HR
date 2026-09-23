import { TextAreaField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { ArrowsClockwise, Check, X } from '@phosphor-icons/react';
import { type FormEvent, useRef, useState } from 'react';
import type { LeaveItem } from './types';
import { useLeaveAction } from './useLeaveAction';

type Mode = 'idle' | 'note' | 'reject';

/**
 * Approve (optional note) or reject (note required, the person reads it). A refusal from the server, such as a
 * request someone else decided meanwhile, shows next to the buttons with a way to reload.
 */
export function DecisionForm({ item, onDone, reload }: { item: LeaveItem; onDone?: () => void; reload: string[] }) {
    const t = useT();
    const action = useLeaveAction();
    const [mode, setMode] = useState<Mode>('idle');
    const [note, setNote] = useState('');
    const [localError, setLocalError] = useState<string | null>(null);
    const openerRef = useRef<HTMLButtonElement>(null);
    const name = item.person?.name ?? '';
    const processing = action.state.processing;

    const submit = (event: FormEvent, decision: 'approved' | 'rejected') => {
        event.preventDefault();
        if (decision === 'rejected' && note.trim() === '') {
            setLocalError(t('leave.decide.note_required'));
            return;
        }
        setLocalError(null);
        action.send(route('leave.decide', item.id), { decision, note: note.trim() === '' ? null : note }, onDone);
    };

    const back = () => {
        setMode('idle');
        setNote('');
        setLocalError(null);
        requestAnimationFrame(() => openerRef.current?.focus());
    };

    const refusal = (action.state.errors.leave ?? action.state.failure) && (
        <Notice tone="danger">
            <p className="m-0">{action.state.errors.leave ?? action.state.failure}</p>
            <button type="button" className="btn btn-secondary btn-sm mt-2 bg-surface" onClick={() => router.reload({ only: reload, onSuccess: action.reset })}>
                <ArrowsClockwise weight="bold" size={16} aria-hidden />
                {t('leave.actions.reload')}
            </button>
        </Notice>
    );

    if (mode === 'reject') {
        return (
            <form className="flex flex-col gap-3" onSubmit={(e) => submit(e, 'rejected')} noValidate>
                <TextAreaField
                    label={t('leave.decide.reject_label')}
                    help={t('leave.decide.reject_help', { name })}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    error={localError ?? action.state.errors.note}
                    maxLength={2000}
                    required
                    autoFocus
                />
                {refusal}
                <div className="flex flex-wrap gap-3">
                    <button type="submit" className="btn btn-danger" disabled={processing}>
                        <X weight="bold" size={18} aria-hidden />
                        {processing ? t('leave.actions.sending') : t('leave.decide.reject_submit')}
                    </button>
                    <button type="button" className="btn btn-quiet" onClick={back} disabled={processing}>
                        {t('leave.decide.back')}
                    </button>
                </div>
            </form>
        );
    }

    return (
        <form className="flex flex-col gap-3" onSubmit={(e) => submit(e, 'approved')} noValidate>
            {mode === 'note' && (
                <TextAreaField
                    label={t('leave.decide.note_label', { name })}
                    help={t('leave.decide.note_optional')}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    error={action.state.errors.note}
                    maxLength={2000}
                    autoFocus
                />
            )}
            {refusal}
            <div className="flex flex-wrap items-center gap-3">
                <button type="submit" className="btn btn-primary" disabled={processing}>
                    <Check weight="bold" size={18} aria-hidden />
                    {processing ? t('leave.actions.sending') : t('leave.decide.approve')}
                </button>
                {mode === 'note' ? (
                    <button type="button" className="btn btn-secondary" onClick={back} disabled={processing}>
                        {t('leave.decide.back')}
                    </button>
                ) : (
                    <>
                        <button ref={openerRef} type="button" className="btn btn-secondary" onClick={() => setMode('reject')} disabled={processing}>
                            {t('leave.decide.reject')}
                        </button>
                        <button type="button" className="btn btn-quiet" onClick={() => setMode('note')} disabled={processing}>
                            {t('leave.decide.add_note')}
                        </button>
                    </>
                )}
            </div>
        </form>
    );
}
