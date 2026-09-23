import { TextField, SelectField, TextAreaField } from '@/components/ui/Field';
import { Dialog } from '@/components/ui/Dialog';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';

type ProjectStatus = 'planned' | 'active' | 'done';

interface ProjectRow {
    id: number;
    name: string;
    code: string | null;
    status: ProjectStatus;
    members_count: number;
}

interface PageProps {
    projects: ProjectRow[];
    can_manage: boolean;
    /** projects.budget: the create form shows the hour budget field */
    can_budget: boolean;
    statuses: ProjectStatus[];
}

export default function ProjectsIndex() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const [creating, setCreating] = useState(false);

    return (
        <AppShell title={t('projects.title')}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="h1">{t('projects.title')}</h1>
                    <p className="m-0 mt-1 max-w-[60ch] text-muted">{t('projects.lead')}</p>
                </div>
                {props.can_manage && (
                    <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>
                        <Plus weight="bold" size={18} aria-hidden />
                        {t('projects.add')}
                    </button>
                )}
            </div>

            <div className="mt-[18px]">
                {props.projects.length === 0 ? (
                    <section className="card flex flex-col items-start gap-3 px-5 py-6">
                        <h2 className="h2">{t('projects.empty_title')}</h2>
                        <p className="m-0 max-w-[60ch] text-muted">{t('projects.empty_body')}</p>
                        {props.can_manage && (
                            <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>
                                <Plus weight="bold" size={18} aria-hidden />
                                {t('projects.add')}
                            </button>
                        )}
                    </section>
                ) : (
                    <>
                        <div className="table-wrap hidden md:block">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th scope="col">{t('projects.columns.name')}</th>
                                        <th scope="col">{t('projects.columns.code')}</th>
                                        <th scope="col">{t('projects.columns.status')}</th>
                                        <th scope="col">{t('projects.columns.members')}</th>
                                        <th scope="col">
                                            <span className="sr-only">{t('projects.columns.actions')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {props.projects.map((project) => (
                                        <tr key={project.id}>
                                            <td className="font-semibold">{project.name}</td>
                                            <td>{project.code ?? <span className="text-muted">{t('projects.no_code')}</span>}</td>
                                            <td>{t(`projects.status.${project.status}`)}</td>
                                            <td className="num">{t('projects.member_count', { count: project.members_count })}</td>
                                            <td className="text-right">
                                                <Link href={route('projects.show', project.id)} className="btn btn-secondary btn-sm min-h-11" aria-label={t('projects.open_label', { name: project.name })}>
                                                    {t('projects.open')}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="card m-0 list-none divide-y divide-line p-0 md:hidden">
                            {props.projects.map((project) => (
                                <li key={project.id} className="flex items-start justify-between gap-3 px-4 py-3.5">
                                    <div className="min-w-0">
                                        <p className="m-0 font-semibold">{project.name}</p>
                                        <p className="m-0 text-sm text-muted">
                                            {project.code ?? t('projects.no_code')} · {t(`projects.status.${project.status}`)}
                                        </p>
                                        <p className="m-0 text-sm">{t('projects.member_count', { count: project.members_count })}</p>
                                    </div>
                                    <Link href={route('projects.show', project.id)} className="btn btn-secondary flex-none px-3">
                                        {t('projects.open')}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>

            {props.can_manage && <CreateProjectDialog open={creating} statuses={props.statuses} canBudget={props.can_budget} onClose={() => setCreating(false)} />}
        </AppShell>
    );
}

function CreateProjectDialog({ open, statuses, canBudget, onClose }: { open: boolean; statuses: ProjectStatus[]; canBudget: boolean; onClose: () => void }) {
    const t = useT();
    const titleId = useId();
    const form = useForm({
        name: '',
        code: '',
        status: 'active' as ProjectStatus,
        description: '',
        budget_hours: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform(({ budget_hours, ...data }) => ({ ...data, ...(canBudget ? { budget_hours: budget_hours === '' ? null : budget_hours } : {}) }));
        form.post(route('projects.store'), { onSuccess: onClose });
    };

    return (
        <Dialog open={open} onClose={onClose} labelledBy={titleId} width="max-w-[520px]">
            <form onSubmit={submit} className="flex flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {t('projects.form.create_title')}
                    </h2>
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
                        {form.processing ? t('common.actions.saving') : t('projects.form.submit_create')}
                    </button>
                </footer>
            </form>
        </Dialog>
    );
}
