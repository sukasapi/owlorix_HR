import { Dialog } from '@/components/ui/Dialog';
import { TextAreaField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { useLocale, useT } from '@/lib/i18n';
import { X } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';
import { dateRange, workdays } from './format';
import type { LeaveItem } from './types';
import { useLeaveAction } from './useLeaveAction';

interface Props {
    item: LeaveItem | null;
    onClose: () => void;
}

/**
 * Confirms a cancellation. The owner only confirms; Superadmin cancelling someone else's request writes why, and
 * the person reads that note on their Cuti page.
 */
export function CancelDialog({ item, onClose }: Props) {
    const titleId = useId();

    return (
        <Dialog open={item !== null} onClose={onClose} labelledBy={titleId} width="max-w-[480px]" closeOnBackdrop={false}>
            {item && <CancelForm key={item.id} item={item} titleId={titleId} onClose={onClose} />}
        </Dialog>
    );
}

function CancelForm({ item, titleId, onClose }: { item: LeaveItem; titleId: string; onClose: () => void }) {
    const t = useT();
    const locale = useLocale();
    const action = useLeaveAction();
    const [note, setNote] = useState('');
    const [localError, setLocalError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (item.cancel_needs_note && note.trim() === '') {
            setLocalError(t('leave.cancel.note_required'));
            return;
        }
        setLocalError(null);
        action.send(route('leave.cancel', item.id), { note: note.trim() === '' ? null : note }, onClose);
    };

    const refusal = action.state.errors.leave ?? action.state.failure;
    // Only Superadmin talks about the quota; employees do not see it (owner, 2026-09-23)
    const returnsDays = item.cancel_needs_note && item.type?.counts_against_quota && (item.status === 'approved' || item.status === 'pending');

    return (
        <form onSubmit={submit} noValidate>
            <header className="flex items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                <h2 id={titleId} className="h2">
                    {item.cancel_needs_note ? t('leave.cancel.title_other', { name: item.person?.name ?? '' }) : t('leave.cancel.title_own')}
                </h2>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('leave.actions.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>
            <div className="flex flex-col gap-4 px-5 py-5 sm:px-6">
                <p className="m-0">
                    <span className="font-semibold">{item.type?.name}</span>
                    <span className="block text-muted">
                        {dateRange(item.start_date, item.end_date, locale, t)}, {workdays(item.days, t)}
                    </span>
                </p>
                {returnsDays && <p className="m-0 text-sm">{t('leave.cancel.returns_days')}</p>}
                {item.cancel_needs_note && (
                    <TextAreaField
                        label={t('leave.cancel.note_label')}
                        help={t('leave.cancel.note_help')}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        error={localError ?? action.state.errors.note}
                        maxLength={2000}
                        required
                        autoFocus
                    />
                )}
                {refusal && <Notice tone="danger">{refusal}</Notice>}
            </div>
            <footer className="flex flex-wrap justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-6">
                <button type="button" className="btn btn-secondary" onClick={onClose} disabled={action.state.processing} autoFocus={!item.cancel_needs_note}>
                    {t('leave.cancel.keep')}
                </button>
                <button type="submit" className="btn btn-danger" disabled={action.state.processing}>
                    {action.state.processing ? t('leave.actions.sending') : t('leave.cancel.confirm')}
                </button>
            </footer>
        </form>
    );
}
