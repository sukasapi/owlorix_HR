import { TextField, SelectField, TextAreaField } from '@/components/ui/Field';
import { Dialog } from '@/components/ui/Dialog';
import AppShell from '@/layouts/AppShell';
import { useLocale, useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus, PencilSimple, Trash } from '@phosphor-icons/react';
import { type FormEvent, useId, useMemo, useState } from 'react';

interface ProjectOption {
    id: number;
    name: string;
    code: string | null;
    status: string;
    assigned: boolean;
}

interface LogRow {
    id: number;
    project_id: number;
    project_name: string | null;
    project_code: string | null;
    task: { id: number; title: string } | null;
    description: string;
    started_at: string;
    ended_at: string;
    evidence_url: string;
}

interface PageProps {
    logs: LogRow[];
    projects: ProjectOption[];
    prefill_project_id: number | null;
}

function toLocalInput(iso: string, timezone: string): string {
    const date = new Date(iso);
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

function formatRange(started: string, ended: string, locale: string, timezone: string): string {
    const opts: Intl.DateTimeFormatOptions = {
        timeZone: timezone,
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    };
    const start = new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'id-ID', opts).format(new Date(started));
    const end = new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'id-ID', { timeZone: timezone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(ended));
    return `${start} - ${end}`;
}

export default function ActivityIndex() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const timezone = props.app.timezone;
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<LogRow | null>(null);

    const canAdd = props.projects.length > 0;

    return (
        <AppShell title={t('activity.title')}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="h1">{t('activity.title')}</h1>
                    <p className="m-0 mt-1 max-w-[60ch] text-muted">{t('activity.lead')}</p>
                </div>
                {canAdd && (
                    <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>
                        <Plus weight="bold" size={18} aria-hidden />
                        {t('activity.add')}
                    </button>
                )}
            </div>

            <div className="mt-[18px]">
                {!canAdd ? (
                    <section className="card flex flex-col gap-2 px-5 py-6">
                        <h2 className="h2">{t('activity.no_projects_title')}</h2>
                        <p className="m-0 text-muted">{t('activity.no_projects_body')}</p>
                    </section>
                ) : props.logs.length === 0 ? (
                    <section className="card flex flex-col items-start gap-3 px-5 py-6">
                        <h2 className="h2">{t('activity.empty_title')}</h2>
                        <p className="m-0 max-w-[60ch] text-muted">{t('activity.empty_body')}</p>
                        <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>
                            <Plus weight="bold" size={18} aria-hidden />
                            {t('activity.add')}
                        </button>
                    </section>
                ) : (
                    <>
                        <div className="table-wrap hidden md:block">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th scope="col">{t('activity.columns.project')}</th>
                                        <th scope="col">{t('activity.columns.when')}</th>
                                        <th scope="col">{t('activity.columns.description')}</th>
                                        <th scope="col">{t('activity.columns.evidence')}</th>
                                        <th scope="col">
                                            <span className="sr-only">{t('activity.columns.actions')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {props.logs.map((log) => (
                                        <tr key={log.id}>
                                            <td>
                                                <span className="font-semibold">{log.project_name}</span>
                                                {log.task && <TaskLink task={log.task} />}
                                            </td>
                                            <td className="num whitespace-nowrap">{formatRange(log.started_at, log.ended_at, locale, timezone)}</td>
                                            <td className="max-w-[40ch] break-words">{log.description}</td>
                                            <td>
                                                <a href={log.evidence_url} target="_blank" rel="noreferrer" className="link break-all">
                                                    {t('activity.open_evidence')}
                                                </a>
                                            </td>
                                            <td className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => setEditing(log)} aria-label={t('activity.edit')}>
                                                        <PencilSimple weight="bold" size={16} aria-hidden />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn btn-secondary btn-sm min-h-11"
                                                        onClick={() => {
                                                            if (window.confirm(t('activity.form.delete_confirm'))) {
                                                                router.delete(route('activity.destroy', log.id), { preserveScroll: true });
                                                            }
                                                        }}
                                                        aria-label={t('activity.form.delete')}
                                                    >
                                                        <Trash weight="bold" size={16} aria-hidden />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <ul className="card m-0 list-none divide-y divide-line p-0 md:hidden">
                            {props.logs.map((log) => (
                                <li key={log.id} className="flex flex-col gap-2 px-4 py-3.5">
                                    <p className="m-0 font-semibold">{log.project_name}</p>
                                    {log.task && <TaskLink task={log.task} />}
                                    <p className="m-0 text-sm text-muted">{formatRange(log.started_at, log.ended_at, locale, timezone)}</p>
                                    <p className="m-0 text-sm break-words">{log.description}</p>
                                    <a href={log.evidence_url} target="_blank" rel="noreferrer" className="link text-sm">
                                        {t('activity.open_evidence')}
                                    </a>
                                    <div className="flex gap-2">
                                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => setEditing(log)}>
                                            {t('activity.edit')}
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-secondary btn-sm"
                                            onClick={() => {
                                                if (window.confirm(t('activity.form.delete_confirm'))) {
                                                    router.delete(route('activity.destroy', log.id), { preserveScroll: true });
                                                }
                                            }}
                                        >
                                            {t('activity.form.delete')}
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>

            {creating && (
                <ActivityDialog
                    projects={props.projects}
                    timezone={timezone}
                    prefillProjectId={props.prefill_project_id}
                    onClose={() => setCreating(false)}
                />
            )}
            {editing && <ActivityDialog projects={props.projects} timezone={timezone} log={editing} onClose={() => setEditing(null)} />}
        </AppShell>
    );
}

function ActivityDialog({
    projects,
    timezone,
    prefillProjectId,
    log,
    onClose,
}: {
    projects: ProjectOption[];
    timezone: string;
    prefillProjectId?: number | null;
    log?: LogRow;
    onClose: () => void;
}) {
    const t = useT();
    const titleId = useId();
    const defaultProject = useMemo(() => {
        if (log) return String(log.project_id);
        if (prefillProjectId && projects.some((p) => p.id === prefillProjectId)) return String(prefillProjectId);
        const assigned = projects.find((p) => p.assigned);
        return String(assigned?.id ?? projects[0]?.id ?? '');
    }, [log, prefillProjectId, projects]);

    const form = useForm({
        project_id: defaultProject,
        description: log?.description ?? '',
        started_at: log ? toLocalInput(log.started_at, timezone) : '',
        ended_at: log ? toLocalInput(log.ended_at, timezone) : '',
        evidence_url: log?.evidence_url ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { onSuccess: onClose, preserveScroll: true };
        if (log) {
            form.put(route('activity.update', log.id), options);
        } else {
            form.post(route('activity.store'), options);
        }
    };

    return (
        <Dialog open onClose={onClose} labelledBy={titleId} width="max-w-[560px]" closeOnBackdrop={false}>
            <form onSubmit={submit} className="flex max-h-[calc(100dvh-48px)] flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {log ? t('activity.form.edit_title') : t('activity.form.title')}
                    </h2>
                </header>
                <div className="flex flex-1 flex-col gap-[18px] overflow-y-auto px-5 py-5 sm:px-7">
                    <SelectField label={t('activity.form.project')} name="project_id" help={t('activity.form.project_help')} value={form.data.project_id} onChange={(e) => form.setData('project_id', e.target.value)} error={form.errors.project_id} required>
                        {projects.map((project) => (
                            <option key={project.id} value={project.id}>
                                {project.name}
                                {project.assigned ? ` (${t('activity.form.assigned_tag')})` : ''}
                            </option>
                        ))}
                    </SelectField>
                    <TextAreaField
                        label={t('activity.form.description')}
                        name="description"
                        required
                        minLength={10}
                        rows={4}
                        help={t('activity.form.description_help')}
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        error={form.errors.description}
                    />
                    <div className="grid gap-[18px] sm:grid-cols-2">
                        <TextField label={t('activity.form.started_at')} name="started_at" type="datetime-local" required value={form.data.started_at} onChange={(e) => form.setData('started_at', e.target.value)} error={form.errors.started_at} />
                        <TextField label={t('activity.form.ended_at')} name="ended_at" type="datetime-local" required value={form.data.ended_at} onChange={(e) => form.setData('ended_at', e.target.value)} error={form.errors.ended_at} />
                    </div>
                    <TextField
                        label={t('activity.form.evidence_url')}
                        name="evidence_url"
                        type="url"
                        required
                        maxLength={500}
                        help={t('activity.form.evidence_help')}
                        value={form.data.evidence_url}
                        onChange={(e) => form.setData('evidence_url', e.target.value)}
                        error={form.errors.evidence_url}
                    />
                </div>
                <footer className="flex justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-7">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>
                        {t('activity.form.cancel')}
                    </button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? t('common.actions.saving') : log ? t('activity.form.submit_edit') : t('activity.form.submit')}
                    </button>
                </footer>
            </form>
        </Dialog>
    );
}

/** Rows made from task timer sessions link back to their task. */
function TaskLink({ task }: { task: { id: number; title: string } }) {
    const t = useT();

    return (
        <Link href={route('tasks.show', task.id)} className="link block text-sm break-words">
            {t('activity.from_task', { title: task.title })}
        </Link>
    );
}
