import { TextField } from '@/components/ui/Field';
import { Dialog } from '@/components/ui/Dialog';
import { Notice } from '@/components/ui/Notice';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { useForm } from '@inertiajs/react';
import { X } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useId, useRef, useState } from 'react';
import type { TeamsPageProps } from './types';

interface Props {
    open: boolean;
    onClose: () => void;
    /** Receives the new team's id so the page can open it for members and lead right away. */
    onCreated: (teamId: number) => void;
}

export function CreateTeamDialog({ open, onClose, onCreated }: Props) {
    const titleId = useId();

    return (
        <Dialog open={open} onClose={onClose} labelledBy={titleId} width="max-w-[460px]" closeOnBackdrop={false}>
            {open && <CreateTeamForm titleId={titleId} onClose={onClose} onCreated={onCreated} />}
        </Dialog>
    );
}

function CreateTeamForm({ titleId, onClose, onCreated }: { titleId: string; onClose: () => void; onCreated: (teamId: number) => void }) {
    const t = useT();
    const form = useForm({ name: '' });
    const [failed, setFailed] = useState(false);
    const formRef = useRef<HTMLFormElement>(null);

    useEffect(() => {
        const frame = requestAnimationFrame(() => formRef.current?.querySelector<HTMLInputElement>('input[name="name"]')?.focus());
        return () => cancelAnimationFrame(frame);
    }, []);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);
        const name = form.data.name.trim();

        form.post(route('admin.teams.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const created = (page.props as unknown as SharedProps & TeamsPageProps).teams.find((team) => team.name.toLowerCase() === name.toLowerCase());
                onClose();
                if (created) onCreated(created.id);
            },
            onHttpException: () => {
                setFailed(true);
                return false;
            },
            onNetworkError: () => {
                setFailed(true);
                return false;
            },
        });
    };

    return (
        <form ref={formRef} onSubmit={submit} noValidate>
            <header className="flex items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                <h2 id={titleId} className="h2">
                    {t('teams.create.title')}
                </h2>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('teams.manage_dialog.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>
            <div className="flex flex-col gap-4 px-5 py-5 sm:px-6">
                {failed && <Notice tone="danger">{t('teams.states.failed')}</Notice>}
                <TextField
                    label={t('teams.create.name')}
                    name="name"
                    autoComplete="off"
                    required
                    maxLength={80}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name}
                />
            </div>
            <footer className="flex flex-wrap justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-6">
                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    {t('teams.create.cancel')}
                </button>
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    {form.processing ? t('teams.create.submitting') : t('teams.create.submit')}
                </button>
            </footer>
        </form>
    );
}
