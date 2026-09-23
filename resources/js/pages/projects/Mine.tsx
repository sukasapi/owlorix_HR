import { OwlEyes } from '@/components/owl/OwlEyes';
import AppShell from '@/layouts/AppShell';
import { formatMinutes, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { NotePencil, Pause } from '@phosphor-icons/react';
import { type ReactNode, useEffect, useId, useState } from 'react';
import { StatusChip, TaskListItem } from './TaskBits';
import type { RunningTimer, TaskRow } from './taskTypes';

type ProjectStatus = 'planned' | 'active' | 'done';

interface Assignment {
    assigned_at: string | null;
    project: {
        id: number;
        name: string;
        code: string | null;
        status: ProjectStatus;
        members_count: number;
    };
}

interface PageProps {
    assignments: Assignment[];
    my_tasks: TaskRow[];
    waiting: TaskRow[];
    proposals: TaskRow[];
    running: RunningTimer | null;
}

/**
 * Tugas saya: a running timer first (it is counting right now), then decisions a lead owes others, then the person's
 * own tasks, proposals, and assigned projects.
 */
export default function MyTasks() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const canLead = props.auth?.permissions.includes('projects.manage') ?? false;

    return (
        <AppShell title={t('projects.mine_title')}>
            <h1 className="h1">{t('projects.mine_title')}</h1>
            <p className="m-0 mt-1 max-w-[60ch] text-muted">{t('projects.mine_lead')}</p>

            <div className="mt-6 flex flex-col gap-8">
                {props.running && <RunningCard timer={props.running} />}

                {(canLead || props.waiting.length > 0) && (
                    <Section title={t('tasks.mine.waiting_heading')} lead={t('tasks.mine.waiting_lead')}>
                        {props.waiting.length === 0 ? (
                            <p className="m-0 mt-3 text-muted">{t('tasks.mine.waiting_empty')}</p>
                        ) : (
                            <ul className="card m-0 mt-3 list-none divide-y divide-line p-0">
                                {props.waiting.map((task) => (
                                    <TaskListItem key={task.id} task={task} showPlace />
                                ))}
                            </ul>
                        )}
                    </Section>
                )}

                <Section title={t('tasks.mine.tasks_heading')}>
                    {props.my_tasks.length === 0 ? (
                        <div className="card mt-3 px-5 py-5">
                            <p className="m-0 font-semibold">{t('tasks.mine.tasks_empty')}</p>
                            <p className="m-0 mt-1 text-sm text-muted">{t('tasks.mine.tasks_empty_body')}</p>
                        </div>
                    ) : (
                        <ul className="card m-0 mt-3 list-none divide-y divide-line p-0">
                            {props.my_tasks.map((task) => (
                                <TaskListItem key={task.id} task={task} showPlace />
                            ))}
                        </ul>
                    )}
                </Section>

                {props.proposals.length > 0 && (
                    <Section title={t('tasks.mine.proposals_heading')}>
                        <ul className="card m-0 mt-3 list-none divide-y divide-line p-0">
                            {props.proposals.map((task) => (
                                <li key={task.id} className="flex flex-col gap-1.5 px-4 py-3.5">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <Link href={route('tasks.show', task.id)} className="font-semibold break-words text-ink hover:underline">
                                            {task.title}
                                        </Link>
                                        <StatusChip status={task.status} />
                                    </div>
                                    {task.project && <p className="m-0 text-sm text-muted">{t('tasks.mine.in', { project: task.project.name, sub: task.sub_project?.name ?? '' })}</p>}
                                    {task.status === 'rejected' && task.decision_note && (
                                        <p className="m-0 border-l-2 border-line-strong pl-3 text-sm whitespace-pre-line">{t('tasks.mine.reject_reason', { note: task.decision_note })}</p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                <Section title={t('tasks.mine.projects_heading')}>
                    {props.assignments.length === 0 ? (
                        <div className="card mt-3 px-5 py-5">
                            <p className="m-0 font-semibold">{t('projects.assigned_empty_title')}</p>
                            <p className="m-0 mt-1 text-sm text-muted">{t('projects.assigned_empty_body')}</p>
                        </div>
                    ) : (
                        <ul className="m-0 mt-3 flex list-none flex-col gap-3 p-0">
                            {props.assignments.map(({ project }) => (
                                <li key={project.id} className="card flex flex-wrap items-center justify-between gap-3 px-4 py-4">
                                    <div className="min-w-0">
                                        <p className="m-0 font-semibold">{project.name}</p>
                                        <p className="m-0 text-sm text-muted">
                                            {project.code ?? t('projects.no_code')} · {t(`projects.status.${project.status}`)}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Link href={route('projects.show', project.id)} className="btn btn-secondary">
                                            {t('projects.open')}
                                        </Link>
                                        <Link href={route('activity.index', { project_id: project.id })} className="btn btn-secondary">
                                            <NotePencil weight="bold" size={18} aria-hidden />
                                            {t('projects.log_activity')}
                                        </Link>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </AppShell>
    );
}

function Section({ title, lead, children }: { title: string; lead?: string; children: ReactNode }) {
    const id = useId();

    return (
        <section aria-labelledby={id}>
            <h2 id={id} className="h2">
                {title}
            </h2>
            {lead && <p className="m-0 mt-1 text-sm text-muted">{lead}</p>}
            {children}
        </section>
    );
}

function RunningCard({ timer }: { timer: RunningTimer }) {
    const t = useT();
    const locale = useLocale();
    const [now, setNow] = useState(() => Date.now());
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 30_000);
        return () => window.clearInterval(id);
    }, []);

    const minutes = Math.max(0, Math.floor((now - new Date(timer.started_at).getTime()) / 60_000));

    return (
        <section className="brow flex flex-col gap-4 px-5 py-6 sm:flex-row sm:items-center sm:px-7" aria-label={t('tasks.mine.timer_heading')}>
            <OwlEyes state="open" size={56} />
            <div className="min-w-0 flex-1">
                <p className="m-0 text-sm font-semibold">{t('tasks.mine.timer_heading')}</p>
                <Link href={route('tasks.show', timer.task_id)} className="h2 mt-1 block break-words hover:underline">
                    {timer.task_title}
                </Link>
                <p className="num m-0 mt-1 text-sm">
                    {formatMinutes(minutes, locale)} · {t('tasks.work.running', { time: formatTime(timer.started_at, locale) })}
                </p>
            </div>
            <button
                type="button"
                className="btn btn-secondary self-start bg-surface sm:self-center"
                disabled={busy}
                onClick={() => {
                    setBusy(true);
                    router.post(route('tasks.stop'), {}, { preserveScroll: true, onFinish: () => setBusy(false) });
                }}
            >
                <Pause weight="bold" size={18} aria-hidden />
                {t('tasks.mine.timer_stop')}
            </button>
        </section>
    );
}
