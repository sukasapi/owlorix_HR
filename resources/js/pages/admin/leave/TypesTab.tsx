import { Dialog } from '@/components/ui/Dialog';
import { TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { CalendarMinus, NotePencil, Plus, Prohibit, X } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';
import type { AdminLeaveType } from '../../leave/types';

type Editing = { mode: 'add' } | { mode: 'edit'; type: AdminLeaveType } | null;

/** Leave types. A type is switched off, never deleted, so older requests keep their name. */
export function TypesTab({ types }: { types: AdminLeaveType[] }) {
    const t = useT();
    const [editing, setEditing] = useState<Editing>(null);

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="m-0 max-w-[64ch] text-sm text-muted">{t('leave.admin.types.help')}</p>
                <button type="button" className="btn btn-primary" onClick={() => setEditing({ mode: 'add' })}>
                    <Plus weight="bold" size={18} aria-hidden />
                    {t('leave.admin.types.add')}
                </button>
            </div>

            {types.length === 0 ? (
                <p className="card m-0 px-5 py-6 text-muted">{t('leave.admin.types.empty')}</p>
            ) : (
                <ul className="card m-0 list-none divide-y divide-line p-0">
                    {types.map((type) => (
                        <li key={type.id} className="flex flex-wrap items-center gap-3 px-4 py-3.5 sm:px-5">
                            <div className="min-w-0 flex-1">
                                <p className={`m-0 font-semibold break-words ${type.is_active ? '' : 'text-muted'}`}>{type.name}</p>
                                <div className="mt-1 flex flex-wrap items-center gap-2">
                                    {!type.is_active && (
                                        <span className="chip">
                                            <Prohibit weight="bold" size={14} aria-hidden />
                                            {t('leave.admin.types.inactive')}
                                        </span>
                                    )}
                                    {type.counts_against_quota && (
                                        <span className="chip chip-info">
                                            <CalendarMinus weight="bold" size={14} aria-hidden />
                                            {t('leave.admin.types.counts')}
                                        </span>
                                    )}
                                    {type.requires_note && (
                                        <span className="chip chip-info">
                                            <NotePencil weight="bold" size={14} aria-hidden />
                                            {t('leave.admin.types.note')}
                                        </span>
                                    )}
                                    <span className="num text-[13px] text-muted">{t('leave.admin.types.requests', { count: type.requests_count })}</span>
                                </div>
                            </div>
                            <button
                                type="button"
                                className="btn btn-secondary flex-none px-3"
                                onClick={() => setEditing({ mode: 'edit', type })}
                                aria-label={t('leave.admin.types.edit_label', { name: type.name })}
                            >
                                {t('leave.admin.types.edit')}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <TypeDialog editing={editing} onClose={() => setEditing(null)} />
        </div>
    );
}

function TypeDialog({ editing, onClose }: { editing: Editing; onClose: () => void }) {
    const titleId = useId();

    return (
        <Dialog open={editing !== null} onClose={onClose} labelledBy={titleId} width="max-w-[480px]" closeOnBackdrop={false}>
            {editing && <TypeForm key={editing.mode === 'edit' ? editing.type.id : 'add'} editing={editing} titleId={titleId} onClose={onClose} />}
        </Dialog>
    );
}

function TypeForm({ editing, titleId, onClose }: { editing: NonNullable<Editing>; titleId: string; onClose: () => void }) {
    const t = useT();
    const type = editing.mode === 'edit' ? editing.type : null;
    const [failed, setFailed] = useState(false);
    const form = useForm({
        name: type?.name ?? '',
        counts_against_quota: type?.counts_against_quota ?? false,
        requires_note: type?.requires_note ?? false,
        is_active: type?.is_active ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onClose,
            onHttpException: () => {
                setFailed(true);
                return false;
            },
            onNetworkError: () => {
                setFailed(true);
                return false;
            },
        };
        if (type) form.put(route('admin.leave.types.update', type.id), options);
        else form.post(route('admin.leave.types.store'), options);
    };

    const toggles = [
        { key: 'counts_against_quota', label: t('leave.admin.types.counts_label'), help: t('leave.admin.types.counts_help') },
        { key: 'requires_note', label: t('leave.admin.types.note_label'), help: t('leave.admin.types.note_help') },
        { key: 'is_active', label: t('leave.admin.types.active_label'), help: t('leave.admin.types.active_help') },
    ] as const;

    return (
        <form onSubmit={submit} noValidate>
            <header className="flex items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                <h2 id={titleId} className="h2">
                    {type ? t('leave.admin.types.dialog_edit') : t('leave.admin.types.dialog_add')}
                </h2>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('leave.actions.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>
            <div className="flex flex-col gap-4 px-5 py-5 sm:px-6">
                <TextField
                    label={t('leave.admin.types.name')}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name}
                    maxLength={80}
                    required
                    autoFocus
                    autoComplete="off"
                />
                <fieldset className="m-0 flex flex-col gap-1 border-0 p-0">
                    {toggles.map((toggle) => (
                        <label key={toggle.key} className="flex min-h-11 cursor-pointer items-start gap-3 rounded-md px-1 py-2">
                            <input
                                type="checkbox"
                                className="mt-0.5 h-5 w-5 flex-none accent-teal"
                                checked={form.data[toggle.key]}
                                onChange={(e) => form.setData(toggle.key, e.target.checked)}
                            />
                            <span className="min-w-0">
                                <span className="block font-semibold">{toggle.label}</span>
                                <span className="help block">{toggle.help}</span>
                            </span>
                        </label>
                    ))}
                </fieldset>
                {failed && <Notice tone="danger">{t('leave.admin.types.failed')}</Notice>}
            </div>
            <footer className="flex flex-wrap justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-6">
                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    {t('leave.admin.types.cancel')}
                </button>
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    {form.processing ? t('leave.actions.sending') : t('leave.admin.types.save')}
                </button>
            </footer>
        </form>
    );
}
