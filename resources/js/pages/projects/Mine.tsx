import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { NotePencil } from '@phosphor-icons/react';

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
}

export default function MyTasks() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();

    return (
        <AppShell title={t('projects.mine_title')}>
            <h1 className="h1">{t('projects.mine_title')}</h1>
            <p className="m-0 mt-1 max-w-[60ch] text-muted">{t('projects.mine_lead')}</p>

            <div className="mt-[18px]">
                {props.assignments.length === 0 ? (
                    <section className="card flex flex-col gap-2 px-5 py-6">
                        <h2 className="h2">{t('projects.assigned_empty_title')}</h2>
                        <p className="m-0 text-muted">{t('projects.assigned_empty_body')}</p>
                    </section>
                ) : (
                    <ul className="m-0 flex list-none flex-col gap-3 p-0">
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
                                    <Link href={route('activity.index', { project_id: project.id })} className="btn btn-primary">
                                        <NotePencil weight="bold" size={18} aria-hidden />
                                        {t('projects.log_activity')}
                                    </Link>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppShell>
    );
}
