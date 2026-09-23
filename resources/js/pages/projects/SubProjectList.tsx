import { formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { PersonLine } from './TaskBits';
import type { SubProjectData } from './taskTypes';

/**
 * Sub projects of a project. Each row leads with its name and progress; the gold count marks rows where a lead has
 * to decide something, the one accent on this list.
 */
export function SubProjectList({ projectId, items, canManage }: { projectId: number; items: SubProjectData[]; canManage: boolean }) {
    const t = useT();
    const locale = useLocale();

    if (items.length === 0) {
        return (
            <div className="card mt-4 px-5 py-5">
                <p className="m-0 font-semibold">{t('tasks.sub.empty_title')}</p>
                <p className="m-0 mt-1 text-sm text-muted">{canManage ? t('tasks.sub.empty_body_manage') : t('tasks.sub.empty_body')}</p>
            </div>
        );
    }

    return (
        <ul className="card m-0 mt-4 list-none divide-y divide-line p-0">
            {items.map((sub) => {
                const total = sub.tasks_total ?? 0;
                const done = sub.tasks_done ?? 0;
                const percent = total === 0 ? 0 : Math.round((done / total) * 100);

                return (
                    <li key={sub.id} className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:gap-6">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <Link href={route('projects.sub.show', [projectId, sub.id])} className="font-semibold break-words text-ink hover:underline" aria-label={t('tasks.sub.open', { name: sub.name })}>
                                    {sub.name}
                                </Link>
                                {sub.status !== 'active' && <span className="chip">{t(`projects.status.${sub.status}`)}</span>}
                                {(sub.tasks_waiting ?? 0) > 0 && <span className="chip chip-pending num">{t('tasks.sub.waiting', { count: sub.tasks_waiting ?? 0 })}</span>}
                            </div>
                            <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
                                <PersonLine person={sub.lead} fallback={t('tasks.sub.no_lead')} />
                                {sub.due_date && <span className="num">{t('tasks.sub.due', { date: formatShortDate(sub.due_date, locale) })}</span>}
                            </div>
                        </div>
                        <div className="flex w-full flex-col gap-1.5 sm:w-[220px] sm:flex-none">
                            <span className="num text-sm">{t('tasks.sub.progress', { done, total })}</span>
                            <div
                                className="h-2 overflow-hidden rounded-md bg-panel"
                                role="progressbar"
                                aria-valuemin={0}
                                aria-valuemax={total}
                                aria-valuenow={done}
                                aria-label={t('tasks.sub.progress', { done, total })}
                            >
                                <span className="block h-full rounded-md bg-[var(--eye-brow)]" style={{ width: `${percent}%` }} />
                            </div>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
