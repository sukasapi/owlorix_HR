import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextAreaField, TextField } from '@/components/ui/Field';
import { useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { X } from '@phosphor-icons/react';
import { type FormEvent, useId } from 'react';
import { AssigneePicker, assigneeError, sameIds } from './AssigneePicker';
import { type AssigneeOption, PHASES, type StageOption, type TaskPriority } from './taskTypes';

export interface TaskFormValues {
    id?: number;
    title: string;
    description: string | null;
    priority: TaskPriority;
    stage_id: number | null;
    assignee_ids: number[];
    due_date: string | null;
    estimate_minutes: number | null;
    evidence_required: boolean;
}

interface Props {
    /** create: a lead adds straight to the list; propose: goes to the lead; edit: an existing task */
    mode: 'create' | 'propose' | 'edit';
    /** Lead fields (assignees, evidence required) are shown only to a lead. */
    lead: boolean;
    projectId?: number;
    subProjectId?: number;
    task?: TaskFormValues;
    /** Project members (plus current assignees) a lead can tick */
    people: AssigneeOption[];
    maxAssignees: number;
    priorities: TaskPriority[];
    /** Every pipeline stage; the picker offers active ones plus the task's own stage if it was switched off. */
    stages: StageOption[];
    onClose: () => void;
}

export function TaskDialog({ mode, lead, projectId, subProjectId, task, people, maxAssignees, priorities, stages, onClose }: Props) {
    const t = useT();
    const titleId = useId();
    const form = useForm({
        title: task?.title ?? '',
        description: task?.description ?? '',
        priority: task?.priority ?? ('normal' as TaskPriority),
        stage_id: task?.stage_id ? String(task.stage_id) : '',
        assignee_ids: task?.assignee_ids ?? ([] as number[]),
        due_date: task?.due_date ?? '',
        estimate_hours: task?.estimate_minutes ? String(Math.round((task.estimate_minutes / 60) * 100) / 100) : '',
        evidence_required: task?.evidence_required ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform(({ assignee_ids, ...data }) => ({
            ...data,
            ...(lead && (mode !== 'edit' || !sameIds(assignee_ids, task?.assignee_ids ?? [])) ? { assignee_ids } : {}),
            stage_id: data.stage_id === '' ? null : Number(data.stage_id),
            due_date: data.due_date || null,
            estimate_hours: data.estimate_hours === '' ? null : data.estimate_hours,
        }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (mode === 'edit' && task?.id) form.put(route('tasks.update', task.id), options);
        else form.post(route('projects.tasks.store', [projectId, subProjectId]), options);
    };

    const pickable = stages.filter((stage) => stage.is_active || stage.id === task?.stage_id);

    const heading = mode === 'edit' ? t('tasks.form.edit_title') : mode === 'propose' ? t('tasks.form.propose_title') : t('tasks.form.create_title');
    const submitLabel = mode === 'edit' ? t('tasks.form.submit_edit') : mode === 'propose' ? t('tasks.form.submit_propose') : t('tasks.form.submit_create');

    return (
        <Dialog open onClose={onClose} labelledBy={titleId} width="max-w-[600px]" closeOnBackdrop={false}>
            <form onSubmit={submit} className="flex flex-col">
                <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                    <div className="min-w-0">
                        <h2 id={titleId} className="h2">
                            {heading}
                        </h2>
                        {mode === 'propose' && <p className="m-0 mt-1 text-sm text-muted">{t('tasks.list.propose_hint')}</p>}
                    </div>
                    <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 px-0" aria-label={t('common.actions.close')}>
                        <X weight="bold" size={18} aria-hidden />
                    </button>
                </header>
                <div className="flex flex-col gap-[18px] px-5 py-5 sm:px-7">
                    <TextField
                        autoFocus
                        label={t('tasks.form.title')}
                        help={t('tasks.form.title_help')}
                        required
                        minLength={3}
                        maxLength={160}
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        error={form.errors.title}
                    />
                    <TextAreaField
                        label={t('tasks.form.description')}
                        help={t('tasks.form.description_help')}
                        rows={4}
                        maxLength={5000}
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        error={form.errors.description}
                    />
                    {pickable.length > 0 && (
                        <SelectField label={t('tasks.form.stage')} help={t('tasks.form.stage_help')} value={form.data.stage_id} onChange={(e) => form.setData('stage_id', e.target.value)} error={form.errors.stage_id}>
                            <option value="">{t('tasks.form.stage_none')}</option>
                            {PHASES.map((phase) => {
                                const inPhase = pickable.filter((stage) => stage.phase === phase);
                                if (inPhase.length === 0) return null;
                                return (
                                    <optgroup key={phase} label={t(`pipeline.phase.${phase}`)}>
                                        {inPhase.map((stage) => (
                                            <option key={stage.id} value={stage.id}>
                                                {stage.is_active ? stage.name : t('tasks.form.stage_inactive', { name: stage.name })}
                                            </option>
                                        ))}
                                    </optgroup>
                                );
                            })}
                        </SelectField>
                    )}
                    <div className="grid gap-[18px] sm:grid-cols-3">
                        <SelectField label={t('tasks.form.priority')} value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value as TaskPriority)} error={form.errors.priority}>
                            {priorities.map((p) => (
                                <option key={p} value={p}>
                                    {t(`tasks.priority.${p}`)}
                                </option>
                            ))}
                        </SelectField>
                        <TextField label={t('tasks.form.due_date')} type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} error={form.errors.due_date} />
                        <TextField
                            label={t('tasks.form.estimate')}
                            help={t('tasks.form.estimate_help')}
                            type="number"
                            inputMode="decimal"
                            min={0.25}
                            max={999}
                            step={0.25}
                            value={form.data.estimate_hours}
                            onChange={(e) => form.setData('estimate_hours', e.target.value)}
                            error={form.errors.estimate_hours}
                        />
                    </div>
                    {lead && (
                        <>
                            <AssigneePicker
                                people={people}
                                value={form.data.assignee_ids}
                                onChange={(ids) => form.setData('assignee_ids', ids)}
                                max={maxAssignees}
                                error={assigneeError(form.errors as Record<string, string | undefined>)}
                            />
                            <label className="flex min-h-11 cursor-pointer items-start gap-3">
                                <input type="checkbox" className="mt-1 size-5 flex-none accent-[var(--primary-bg)]" checked={form.data.evidence_required} onChange={(e) => form.setData('evidence_required', e.target.checked)} />
                                <span>
                                    <span className="font-semibold">{t('tasks.form.evidence_required')}</span>
                                    <span className="help block">{t('tasks.form.evidence_required_help')}</span>
                                </span>
                            </label>
                        </>
                    )}
                </div>
                <footer className="flex justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-7">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? t('common.actions.saving') : submitLabel}
                    </button>
                </footer>
            </form>
        </Dialog>
    );
}