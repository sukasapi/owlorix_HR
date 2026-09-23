import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextAreaField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import { CalendarBlank, CheckCircle, Clock, Plus, Trash, WarningCircle, X } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';

export type MilestoneKind = 'internal' | 'client_review' | 'delivery';
export type MilestoneStatus = 'done' | 'overdue' | 'soon' | 'scheduled';

export interface Milestone {
    id: number;
    project_id: number;
    name: string;
    kind: MilestoneKind;
    /** Studio calendar date, Y-m-d */
    due_date: string;
    done_at: string | null;
    note: string | null;
    /** Worked out by the server in the studio calendar: soon means today up to 7 days ahead */
    status: MilestoneStatus;
    /** Days from today to the due date, negative once it has passed */
    days_until: number;
}

/**
 * Chip per status, icon plus text. Gold marks "soon" because that is the date someone has to act on next;
 * late is red, done is green, a date further out stays plain.
 */
const STATUS_LOOK: Record<MilestoneStatus, { chip: string; Icon: typeof CheckCircle }> = {
    done: { chip: 'chip-ok', Icon: CheckCircle },
    overdue: { chip: 'chip-bad', Icon: WarningCircle },
    soon: { chip: 'chip-pending', Icon: Clock },
    scheduled: { chip: '', Icon: CalendarBlank },
};

export function MilestoneChip({ status }: { status: MilestoneStatus }) {
    const t = useT();
    const { chip, Icon } = STATUS_LOOK[status];

    return (
        <span className={`chip ${chip}`}>
            <Icon weight="bold" size={14} aria-hidden />
            {t(`projects.milestones.status.${status}`)}
        </span>
    );
}

interface Props {
    projectId: number;
    items: Milestone[];
    kinds: MilestoneKind[];
    canManage: boolean;
}

/** Milestones of one project, sorted by date. Everyone who sees the project reads them; managers set them. */
export function Milestones({ projectId, items, kinds, canManage }: Props) {
    const t = useT();
    const [dialog, setDialog] = useState<Milestone | 'new' | null>(null);
    const [failed, setFailed] = useState(false);
    const [busyId, setBusyId] = useState<number | null>(null);

    const toggleDone = (milestone: Milestone) => {
        setFailed(false);
        router.post(
            route('projects.milestones.complete', [projectId, milestone.id]),
            { done: milestone.done_at === null },
            {
                preserveScroll: true,
                onStart: () => setBusyId(milestone.id),
                onFinish: () => setBusyId(null),
                onError: () => setFailed(true),
                onHttpException: () => {
                    setFailed(true);
                    return false;
                },
                onNetworkError: () => {
                    setFailed(true);
                    return false;
                },
            },
        );
    };

    return (
        <section className="mt-8" aria-labelledby="milestones-heading">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="milestones-heading" className="h2">
                        {t('projects.milestones.heading')}
                    </h2>
                    <p className="m-0 mt-1 max-w-[70ch] text-sm text-muted">{t('projects.milestones.lead')}</p>
                </div>
                {canManage && items.length > 0 && (
                    <button type="button" className="btn btn-secondary" onClick={() => setDialog('new')}>
                        <Plus weight="bold" size={18} aria-hidden />
                        {t('projects.milestones.add')}
                    </button>
                )}
            </div>

            {failed && (
                <Notice tone="danger" className="mt-4">
                    {t('projects.milestones.failed')}
                </Notice>
            )}

            {items.length === 0 ? (
                <div className="card mt-4 flex flex-col items-start gap-1 px-5 py-5">
                    <p className="m-0 font-semibold">{t('projects.milestones.empty_title')}</p>
                    <p className="m-0 max-w-[60ch] text-sm text-muted">{canManage ? t('projects.milestones.empty_manage') : t('projects.milestones.empty_view')}</p>
                    {canManage && (
                        <button type="button" className="btn btn-primary mt-3" onClick={() => setDialog('new')}>
                            <Plus weight="bold" size={18} aria-hidden />
                            {t('projects.milestones.add')}
                        </button>
                    )}
                </div>
            ) : (
                <ul className="card m-0 mt-4 list-none divide-y divide-line p-0">
                    {items.map((milestone) => (
                        <MilestoneRow key={milestone.id} milestone={milestone} canManage={canManage} busy={busyId === milestone.id} onToggle={() => toggleDone(milestone)} onEdit={() => setDialog(milestone)} />
                    ))}
                </ul>
            )}

            {canManage && dialog !== null && <MilestoneDialog projectId={projectId} milestone={dialog === 'new' ? undefined : dialog} kinds={kinds} onClose={() => setDialog(null)} />}
        </section>
    );
}

function MilestoneRow({ milestone, canManage, busy, onToggle, onEdit }: { milestone: Milestone; canManage: boolean; busy: boolean; onToggle: () => void; onEdit: () => void }) {
    const t = useT();
    const locale = useLocale();
    const done = milestone.status === 'done';
    const days = milestone.days_until;
    const relative = done ? null : days < 0 ? t('projects.milestones.late_days', { count: -days }) : days === 0 ? t('projects.milestones.today') : t('projects.milestones.in_days', { count: days });

    return (
        <li className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start sm:gap-5">
            <div className="flex items-baseline gap-2 sm:w-[140px] sm:flex-none sm:flex-col sm:gap-0">
                <span className="num font-semibold">{formatShortDate(milestone.due_date, locale)}</span>
                {relative && <span className={`num text-sm ${milestone.status === 'overdue' ? 'font-semibold text-danger' : 'text-muted'}`}>{relative}</span>}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <p className="m-0 font-semibold break-words">{milestone.name}</p>
                    <MilestoneChip status={milestone.status} />
                </div>
                <p className="m-0 mt-0.5 text-sm text-muted">{t(`projects.milestones.kind.${milestone.kind}`)}</p>
                {milestone.note && <p className="m-0 mt-1.5 max-w-[70ch] text-sm whitespace-pre-line break-words">{milestone.note}</p>}
            </div>
            {canManage && (
                <div className="flex flex-wrap gap-2 sm:flex-none sm:justify-end">
                    <button
                        type="button"
                        className="btn btn-secondary btn-sm min-h-11"
                        disabled={busy}
                        onClick={onToggle}
                        aria-label={done ? t('projects.milestones.reopen_label', { name: milestone.name }) : t('projects.milestones.mark_done_label', { name: milestone.name })}
                    >
                        {done ? t('projects.milestones.reopen') : t('projects.milestones.mark_done')}
                    </button>
                    <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={onEdit} aria-label={t('projects.milestones.edit_label', { name: milestone.name })}>
                        {t('projects.milestones.edit')}
                    </button>
                </div>
            )}
        </li>
    );
}

function MilestoneDialog({ projectId, milestone, kinds, onClose }: { projectId: number; milestone?: Milestone; kinds: MilestoneKind[]; onClose: () => void }) {
    const t = useT();
    const titleId = useId();
    const editing = milestone !== undefined;
    const [failed, setFailed] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const form = useForm({
        name: milestone?.name ?? '',
        kind: milestone?.kind ?? ('internal' as MilestoneKind),
        due_date: milestone?.due_date ?? '',
        note: milestone?.note ?? '',
    });

    const failOptions = {
        onHttpException: () => {
            setFailed(true);
            return false;
        },
        onNetworkError: () => {
            setFailed(true);
            return false;
        },
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);
        form.transform((data) => ({ ...data, note: data.note.trim() || null }));
        const options = { preserveScroll: true, onSuccess: onClose, ...failOptions };
        if (editing) form.put(route('projects.milestones.update', [projectId, milestone.id]), options);
        else form.post(route('projects.milestones.store', projectId), options);
    };

    const remove = () => {
        if (!milestone || !window.confirm(t('projects.milestones.form.delete_confirm', { name: milestone.name }))) return;
        setFailed(false);
        router.delete(route('projects.milestones.destroy', [projectId, milestone.id]), {
            preserveScroll: true,
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: onClose,
            ...failOptions,
        });
    };

    return (
        <Dialog open onClose={onClose} labelledBy={titleId} width="max-w-[520px]" closeOnBackdrop={false}>
            <form onSubmit={submit} className="flex flex-col" noValidate>
                <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {editing ? t('projects.milestones.form.edit_title') : t('projects.milestones.form.create_title')}
                    </h2>
                    <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 px-0" aria-label={t('common.actions.close')}>
                        <X weight="bold" size={18} aria-hidden />
                    </button>
                </header>
                <div className="flex flex-col gap-[18px] px-5 py-5 sm:px-7">
                    {failed && <Notice tone="danger">{t('projects.milestones.failed')}</Notice>}
                    <TextField
                        autoFocus
                        label={t('projects.milestones.form.name')}
                        help={t('projects.milestones.form.name_help')}
                        required
                        maxLength={120}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                    <div className="grid gap-[18px] sm:grid-cols-2">
                        <SelectField label={t('projects.milestones.form.kind')} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value as MilestoneKind)} error={form.errors.kind}>
                            {kinds.map((kind) => (
                                <option key={kind} value={kind}>
                                    {t(`projects.milestones.kind.${kind}`)}
                                </option>
                            ))}
                        </SelectField>
                        <TextField label={t('projects.milestones.form.due_date')} type="date" required value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} error={form.errors.due_date} />
                    </div>
                    <TextAreaField label={t('projects.milestones.form.note')} rows={3} maxLength={2000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} error={form.errors.note} />
                </div>
                <footer className="flex flex-wrap items-center justify-between gap-2.5 border-t border-line px-5 py-3.5 sm:px-7">
                    {editing ? (
                        <button type="button" className="btn btn-quiet" onClick={remove} disabled={deleting || form.processing}>
                            <Trash weight="bold" size={18} aria-hidden />
                            {t('projects.milestones.form.delete')}
                        </button>
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-wrap justify-end gap-2.5">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            {t('common.actions.cancel')}
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={form.processing || deleting}>
                            {form.processing ? t('common.actions.saving') : editing ? t('projects.milestones.form.submit_edit') : t('projects.milestones.form.submit_create')}
                        </button>
                    </div>
                </footer>
            </form>
        </Dialog>
    );
}
