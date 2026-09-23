import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextAreaField, TextField } from '@/components/ui/Field';
import { useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { X } from '@phosphor-icons/react';
import { type FormEvent, useId } from 'react';
import type { PersonOption, ProjectStatus, SubProjectData } from './taskTypes';

interface Props {
    projectId: number;
    subProject?: SubProjectData;
    leads: PersonOption[];
    statuses: ProjectStatus[];
    onClose: () => void;
}

/** Create or edit a sub project; the lead list holds only people who manage projects. */
export function SubProjectDialog({ projectId, subProject, leads, statuses, onClose }: Props) {
    const t = useT();
    const titleId = useId();
    const editing = subProject !== undefined;
    const form = useForm({
        name: subProject?.name ?? '',
        description: subProject?.description ?? '',
        status: subProject?.status ?? ('active' as ProjectStatus),
        lead_user_id: subProject?.lead ? String(subProject.lead.id) : '',
        due_date: subProject?.due_date ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, lead_user_id: data.lead_user_id === '' ? null : Number(data.lead_user_id), due_date: data.due_date || null }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (editing) form.put(route('projects.sub.update', [projectId, subProject.id]), options);
        else form.post(route('projects.sub.store', projectId), options);
    };

    return (
        <Dialog open onClose={onClose} labelledBy={titleId} width="max-w-[560px]" closeOnBackdrop={false}>
            <form onSubmit={submit} className="flex flex-col">
                <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {editing ? t('tasks.sub.form_edit') : t('tasks.sub.form_create')}
                    </h2>
                    <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 px-0" aria-label={t('common.actions.close')}>
                        <X weight="bold" size={18} aria-hidden />
                    </button>
                </header>
                <div className="flex flex-col gap-[18px] px-5 py-5 sm:px-7">
                    <TextField
                        autoFocus
                        label={t('tasks.sub.name')}
                        help={t('tasks.sub.name_help')}
                        required
                        maxLength={120}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                    <SelectField label={t('tasks.sub.lead_field')} help={t('tasks.sub.lead_help')} value={form.data.lead_user_id} onChange={(e) => form.setData('lead_user_id', e.target.value)} error={form.errors.lead_user_id}>
                        <option value="">{t('tasks.sub.lead_none')}</option>
                        {leads.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name} ({person.username})
                            </option>
                        ))}
                    </SelectField>
                    <div className="grid gap-[18px] sm:grid-cols-2">
                        <SelectField label={t('tasks.sub.status')} value={form.data.status} onChange={(e) => form.setData('status', e.target.value as ProjectStatus)} error={form.errors.status}>
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {t(`projects.status.${status}`)}
                                </option>
                            ))}
                        </SelectField>
                        <TextField label={t('tasks.sub.due_date')} type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} error={form.errors.due_date} />
                    </div>
                    <TextAreaField label={t('tasks.sub.description')} rows={3} maxLength={2000} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} error={form.errors.description} />
                </div>
                <footer className="flex justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-7">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? t('common.actions.saving') : editing ? t('tasks.sub.submit_edit') : t('tasks.sub.submit_create')}
                    </button>
                </footer>
            </form>
        </Dialog>
    );
}
