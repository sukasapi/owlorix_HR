import { formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { WarningCircle } from '@phosphor-icons/react';
import { useState } from 'react';
import { MilestoneChip } from '../projects/Milestones';
import { PersonLine, StatusChip } from '../projects/TaskBits';
import type { WorkMonitorProps } from './types';

const DUE_SOON_DAYS = 7;
const MILESTONE_DAYS = 30;
/** Rows shown before "show all", so the charts below stay in reach */
const FIRST_ROWS = 8;

/** "lewat 3 hari" in the danger color with an icon, "hari ini", or "5 hari lagi". */
function DaysText({ days }: { days: number }) {
    const t = useT();

    if (days < 0) {
        return (
            <span className="inline-flex items-center gap-1 font-semibold text-danger">
                <WarningCircle weight="bold" size={14} aria-hidden />
                {t('work-monitor.due.late_days', { count: -days })}
            </span>
        );
    }

    return <span className={days === 0 ? 'font-semibold' : 'text-muted'}>{days === 0 ? t('work-monitor.due.today') : t('work-monitor.due.in_days', { count: days })}</span>;
}

/** Open tasks past or near their due date. The due date leads each row because it is why the row is here. */
export function DueTasks({ due }: { due: WorkMonitorProps['due'] }) {
    const t = useT();
    const locale = useLocale();
    const [all, setAll] = useState(false);
    const rows = all ? due.rows : due.rows.slice(0, FIRST_ROWS);

    return (
        <section className="card flex min-w-0 flex-col p-4 sm:p-5" aria-labelledby="due-tasks-title">
            <h2 id="due-tasks-title" className="h2 text-[17px]">
                {t('work-monitor.due.heading')}
            </h2>
            <p className="m-0 mt-1 text-sm text-muted">{t('work-monitor.due.lead', { days: DUE_SOON_DAYS })}</p>

            {due.rows.length === 0 ? (
                <p className="m-0 py-6 text-sm text-muted">{t('work-monitor.due.empty', { days: DUE_SOON_DAYS })}</p>
            ) : (
                <>
                    <ul className="m-0 mt-3 flex list-none flex-col p-0">
                        {rows.map((task) => (
                            <li key={task.id} className="flex flex-col gap-1.5 border-t border-line py-3 first:border-t-0 sm:flex-row sm:items-start sm:gap-4">
                                <div className="num w-full shrink-0 text-sm sm:w-[112px]">
                                    <div className="font-semibold">{formatShortDate(task.due_date, locale)}</div>
                                    <DaysText days={task.days_until} />
                                </div>
                                <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <Link href={route('tasks.show', task.id)} className="link font-medium break-words">
                                        {task.title}
                                    </Link>
                                    {task.project && (
                                        <span className="text-sm break-words text-muted">
                                            {task.project.name}
                                            {task.sub_project ? `, ${task.sub_project.name}` : ''}
                                        </span>
                                    )}
                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                        <StatusChip status={task.status} />
                                        <PersonLine person={task.assignee} fallback={t('work-monitor.due.unassigned')} />
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                    {due.rows.length > FIRST_ROWS && (
                        <button type="button" className="btn btn-quiet mt-1 w-fit" aria-expanded={all} onClick={() => setAll((v) => !v)}>
                            {all ? t('work-monitor.due.show_less') : t('work-monitor.due.show_all', { count: due.rows.length })}
                        </button>
                    )}
                    {due.total > due.rows.length && all && <p className="m-0 mt-2 text-sm text-muted">{t('work-monitor.due.more', { shown: due.rows.length, total: due.total })}</p>}
                </>
            )}
        </section>
    );
}

export function DueMilestones({ milestones }: { milestones: WorkMonitorProps['milestones'] }) {
    const t = useT();
    const locale = useLocale();

    return (
        <section className="card flex min-w-0 flex-col p-4 sm:p-5" aria-labelledby="due-milestones-title">
            <h2 id="due-milestones-title" className="h2 text-[17px]">
                {t('work-monitor.milestones.heading')}
            </h2>
            <p className="m-0 mt-1 text-sm text-muted">{t('work-monitor.milestones.lead', { days: MILESTONE_DAYS })}</p>

            {milestones.rows.length === 0 ? (
                <p className="m-0 py-6 text-sm text-muted">{t('work-monitor.milestones.empty', { days: MILESTONE_DAYS })}</p>
            ) : (
                <>
                    <ul className="m-0 mt-3 flex list-none flex-col p-0">
                        {milestones.rows.map((milestone) => (
                            <li key={milestone.id} className="flex flex-col gap-1.5 border-t border-line py-3 first:border-t-0">
                                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                    <span className="min-w-0 font-medium break-words">{milestone.name}</span>
                                    <MilestoneChip status={milestone.status} />
                                </div>
                                <div className="num flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                    <span className="font-semibold">{formatShortDate(milestone.due_date, locale)}</span>
                                    <DaysText days={milestone.days_until} />
                                    <span className="text-muted">{t(`projects.milestones.kind.${milestone.kind}`)}</span>
                                </div>
                                {milestone.project && (
                                    <Link href={route('projects.show', milestone.project.id)} className="link w-fit text-sm break-words">
                                        {milestone.project.name}
                                    </Link>
                                )}
                            </li>
                        ))}
                    </ul>
                    {milestones.total > milestones.rows.length && (
                        <p className="m-0 mt-2 text-sm text-muted">{t('work-monitor.milestones.more', { shown: milestones.rows.length, total: milestones.total })}</p>
                    )}
                </>
            )}
        </section>
    );
}
