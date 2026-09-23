import { Avatar } from '@/components/ui/Avatar';
import { TextField, SelectField, TextAreaField } from '@/components/ui/Field';
import { Dialog } from '@/components/ui/Dialog';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { NotePencil, Plus, Trash, X } from '@phosphor-icons/react';
import { BudgetMeter, budgetHoursValue } from './BudgetMeter';
import { type Milestone, type MilestoneKind, Milestones } from './Milestones';
import { SubProjectDialog } from './SubProjectDialog';
import { SubProjectList } from './SubProjectList';
import type { BudgetData, PersonOption as LeadOption, SubProjectData } from './taskTypes';
import { type FormEvent, useId, useState } from 'react';

type ProjectStatus = 'planned' | 'active' | 'done';

interface Member {
    id: number;
    name: string;
    username: string;
    initials: string;
    photo_url: string | null;
    status: string;
    assigned_at: string | null;
}

interface PersonOption {
    id: number;
    name: string;
    username: string;
    status: string;
}

interface ProjectDetail {
    id: number;
    name: string;
    code: string | null;
    status: ProjectStatus;
    description: string | null;
    members_count: number;
    members: Member[];
}

interface PageProps {
    project: ProjectDetail;
    people: PersonOption[];
    can_manage: boolean;
    statuses: ProjectStatus[];
    is_assigned: boolean;
    sub_projects: SubProjectData[];
    leads: LeadOption[];
    milestones: Milestone[];
    milestone_kinds: MilestoneKind[];
    can_budget: boolean;
    /** Only for people with projects.budget; absent for everyone else. */
    budget?: BudgetData;
}

export default function ProjectShow() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const { project, can_manage, is_assigned } = props;
    const [editing, setEditing] = useState(false);
    const [creatingSub, setCreatingSub] = useState(false);

    const unassign = (member: Member) => {
        if (!window.confirm(t('projects.unassign_label', { name: member.name }))) return;
        router.delete(route('projects.unassign', [project.id, member.id]), { preserveScroll: true });
    };

    return (
        <AppShell title={project.name}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="m-0 text-sm text-muted">
                        <Link href={route('projects.index')} className="link">
                            {t('projects.title')}
                        </Link>
                    </p>
                    <h1 className="h1 mt-1 break-words">{project.name}</h1>
                    <p className="m-0 mt-1 text-muted">
                        {project.code ?? t('projects.no_code')} · {t(`projects.status.${project.status}`)}
                    </p>
                    {project.description && <p className="m-0 mt-3 max-w-[70ch]">{project.description}</p>}
                </div>
                <div className="flex flex-wrap gap-2">
                    {(is_assigned || can_manage) && (
                        <Link href={route('activity.index', { project_id: project.id })} className="btn btn-primary">
                            <NotePencil weight="bold" size={18} aria-hidden />
                            {t('projects.log_activity')}
                        </Link>
                    )}
                    {can_manage && (
                        <button type="button" className="btn btn-secondary" onClick={() => setEditing(true)}>
                            {t('projects.manage')}
                        </button>
                    )}
                </div>
            </div>

            {props.budget && (
                <div className="mt-6 max-w-[560px]">
                    <BudgetMeter
                        budget={props.budget}
                        source={t('projects.budget.source_project')}
                        action={
                            can_manage && (
                                <button type="button" className="btn btn-quiet btn-sm min-h-11" onClick={() => setEditing(true)}>
                                    {t('projects.budget.set')}
                                </button>
                            )
                        }
                    />
                </div>
            )}

            <Milestones projectId={project.id} items={props.milestones} kinds={props.milestone_kinds} canManage={can_manage} />

            <section className="mt-10" aria-labelledby="subs-heading">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="min-w-0">
                        <h2 id="subs-heading" className="h2">
                            {t('tasks.sub.heading')}
                        </h2>
                        <p className="m-0 mt-1 max-w-[70ch] text-sm text-muted">{t('tasks.sub.lead')}</p>
                    </div>
                    {can_manage && (
                        <button type="button" className="btn btn-secondary" onClick={() => setCreatingSub(true)}>
                            <Plus weight="bold" size={18} aria-hidden />
                            {t('tasks.sub.add')}
                        </button>
                    )}
                </div>
                <SubProjectList projectId={project.id} items={props.sub_projects} canManage={can_manage} />
            </section>

            <section className="mt-10" aria-labelledby="members-heading">
                <h2 id="members-heading" className="h2">
                    {t('projects.members_heading')}
                </h2>

                {can_manage && <AssignForm projectId={project.id} people={props.people} />}

                {project.members.length === 0 ? (
                    <p className="m-0 mt-4 text-muted">{t('projects.members_empty')}</p>
                ) : (
                    <ul className="card mt-4 m-0 list-none divide-y divide-line p-0">
                        {project.members.map((member) => (
                            <li key={member.id} className="flex items-center gap-3 px-4 py-3">
                                <Avatar initials={member.initials} photoUrl={member.photo_url} />
                                <div className="min-w-0 flex-1">
                                    <p className="m-0 font-semibold">{member.name}</p>
                                    <p className="m-0 text-sm break-all text-muted">{member.username}</p>
                                </div>
                                {can_manage && (
                                    <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => unassign(member)} aria-label={t('projects.unassign_label', { name: member.name })}>
                                        <Trash weight="bold" size={16} aria-hidden />
                                        {t('projects.unassign')}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {can_manage && editing && <EditProjectDialog project={project} statuses={props.statuses} budgetMinutes={props.budget?.minutes} onClose={() => setEditing(false)} />}
            {can_manage && creatingSub && (
                <SubProjectDialog projectId={project.id} leads={props.leads} statuses={props.statuses} budgetMinutes={props.can_budget ? null : undefined} onClose={() => setCreatingSub(false)} />
            )}
        </AppShell>
    );
}

function AssignForm({ projectId, people }: { projectId: number; people: PersonOption[] }) {
    const t = useT();
    const form = useForm({ user_id: people[0]?.id?.toString() ?? '' });

    if (people.length === 0) {
        return <p className="help m-0 mt-3">{t('projects.form.assign_empty')}</p>;
    }

    return (
        <form
            className="mt-4 flex flex-col gap-2.5 sm:flex-row sm:items-end"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('projects.assign', projectId), { preserveScroll: true, onSuccess: () => form.reset('user_id') });
            }}
        >
            <SelectField
                className="min-w-0 flex-1"
                label={t('projects.form.assign_title')}
                name="user_id"
                help={t('projects.form.assign_help')}
                value={form.data.user_id}
                onChange={(e) => form.setData('user_id', e.target.value)}
                error={form.errors.user_id}
            >
                <option value="">{t('projects.form.pick_person')}</option>
                {people.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.name} ({person.username})
                    </option>
                ))}
            </SelectField>
            <button type="submit" className="btn btn-primary" disabled={form.processing || form.data.user_id === ''}>
                {t('projects.form.assign_submit')}
            </button>
        </form>
    );
}

/** `budgetMinutes` is passed only to people with projects.budget; without it the budget field is not shown or sent. */
function EditProjectDialog({ project, statuses, budgetMinutes, onClose }: { project: ProjectDetail; statuses: ProjectStatus[]; budgetMinutes?: number | null; onClose: () => void }) {
    const t = useT();
    const titleId = useId();
    const canBudget = budgetMinutes !== undefined;
    const form = useForm({
        name: project.name,
        code: project.code ?? '',
        status: project.status,
        description: project.description ?? '',
        budget_hours: budgetHoursValue(budgetMinutes),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform(({ budget_hours, ...data }) => ({ ...data, ...(canBudget ? { budget_hours: budget_hours === '' ? null : budget_hours } : {}) }));
        form.put(route('projects.update', project.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onClose={onClose} labelledBy={titleId} width="max-w-[520px]">
            <form onSubmit={submit} className="flex flex-col">
                <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {t('projects.form.edit_title')}
                    </h2>
                    <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 px-0" aria-label={t('common.actions.close')}>
                        <X weight="bold" size={18} aria-hidden />
                    </button>
                </header>
                <div className="flex flex-col gap-[18px] px-5 py-5 sm:px-7">
                    <TextField label={t('projects.form.name')} name="name" required maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={form.errors.name} />
                    <TextField label={t('projects.form.code')} name="code" maxLength={40} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} help={t('projects.form.code_help')} error={form.errors.code} />
                    <SelectField label={t('projects.form.status')} name="status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value as ProjectStatus)} error={form.errors.status}>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {t(`projects.status.${status}`)}
                            </option>
                        ))}
                    </SelectField>
                    {canBudget && (
                        <TextField
                            label={t('projects.budget.field')}
                            name="budget_hours"
                            help={t('projects.budget.field_help')}
                            type="number"
                            inputMode="decimal"
                            min={0.25}
                            max={99999}
                            step={0.25}
                            value={form.data.budget_hours}
                            onChange={(e) => form.setData('budget_hours', e.target.value)}
                            error={form.errors.budget_hours}
                        />
                    )}
                    <TextAreaField label={t('projects.form.description')} name="description" rows={3} maxLength={2000} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} error={form.errors.description} />
                </div>
                <footer className="flex justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-7">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>
                        {t('projects.form.cancel')}
                    </button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? t('common.actions.saving') : t('projects.form.submit_edit')}
                    </button>
                </footer>
            </form>
        </Dialog>
    );
}
