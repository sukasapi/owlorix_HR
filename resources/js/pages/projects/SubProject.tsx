import AppShell from '@/layouts/AppShell';
import { formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { SelectField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { Link, router, usePage } from '@inertiajs/react';
import { Check, Plus } from '@phosphor-icons/react';
import { useEffect, useId, useRef, useState } from 'react';
import { BudgetMeter } from './BudgetMeter';
import { SubProjectDialog } from './SubProjectDialog';
import { PersonLine, TaskListItem } from './TaskBits';
import { TaskDialog } from './TaskDialog';
import { ReorderGroup } from './TaskReorder';
import {
    type AssigneeOption,
    type BudgetData,
    PHASES,
    type PersonOption,
    type ProjectStatus,
    type StageOption,
    STATUS_ORDER,
    type SubProjectData,
    type TaskPriority,
    type TaskRow,
    type TaskStatus,
} from './taskTypes';

interface PageProps {
    project: { id: number; name: string; code: string | null; status: ProjectStatus };
    sub_project: SubProjectData;
    tasks: TaskRow[];
    can: { manage: boolean; lead: boolean; reorder: boolean; create_task: boolean; propose_task: boolean; budget: boolean };
    /** Project members a lead can put on a task; empty for everyone else */
    people: AssigneeOption[];
    max_assignees: number;
    leads: PersonOption[];
    statuses: ProjectStatus[];
    priorities: TaskPriority[];
    stages: StageOption[];
    /** Only for people with projects.budget; absent for everyone else. */
    budget?: BudgetData;
}

/** 'all', 'none' (tasks without a stage), or a stage id as text */
type StageFilter = string;

/**
 * One sub project: tasks grouped by where they stand, decisions first (DESIGN.md: focal point is what needs an answer).
 * A list instead of a board so it reads the same on a phone.
 */
export default function SubProjectShow() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const { project, sub_project: sub, tasks, can } = props;
    const [dialog, setDialog] = useState<'task' | 'edit' | null>(null);
    const [showRejected, setShowRejected] = useState(false);
    const [stageFilter, setStageFilter] = useState<StageFilter>('all');
    const [reordering, setReordering] = useState(false);
    const [moving, setMoving] = useState(false);
    const [moveFailed, setMoveFailed] = useState(false);
    const [announce, setAnnounce] = useState('');
    const [focusAfterMove, setFocusAfterMove] = useState<{ id: number; direction: 'up' | 'down' } | null>(null);
    const groupsRef = useRef<HTMLDivElement>(null);

    // The filter lists only stages that tasks here use, in pipeline order
    const usedIds = new Set(tasks.map((task) => task.stage?.id).filter((id): id is number => id !== undefined));
    const usedStages = props.stages.filter((stage) => usedIds.has(stage.id));
    const hasUnstaged = tasks.some((task) => task.stage === null);
    const visible = tasks.filter((task) => (stageFilter === 'all' ? true : stageFilter === 'none' ? task.stage === null : String(task.stage?.id) === stageFilter));

    const byStatus = (status: TaskStatus) => visible.filter((task) => task.status === status);
    const rejected = byStatus('rejected');
    const canAdd = can.create_task || can.propose_task;
    const closed = sub.status === 'done' || project.status === 'done';
    // Reordering works on whole status groups, so it needs at least one group with two tasks and no stage filter
    const canReorder = can.reorder && STATUS_ORDER.some((status) => status !== 'rejected' && tasks.filter((task) => task.status === status).length > 1);
    const showReorder = reordering && canReorder;

    // React re-inserts the moved row, which drops focus; put it back on the same arrow, or the other one at an end
    useEffect(() => {
        if (!focusAfterMove) return;
        const { id, direction } = focusAfterMove;
        const other = direction === 'up' ? 'down' : 'up';
        const same = groupsRef.current?.querySelector<HTMLButtonElement>(`[data-move="${id}-${direction}"]:not(:disabled)`);
        (same ?? groupsRef.current?.querySelector<HTMLButtonElement>(`[data-move="${id}-${other}"]`))?.focus();
        setFocusAfterMove(null);
    }, [tasks, focusAfterMove]);

    const failMove = () => {
        setMoveFailed(true);
        return false;
    };

    const move = (task: TaskRow, index: number, via: 'drag' | 'up' | 'down', done: () => void) => {
        setMoveFailed(false);
        const total = tasks.filter((item) => item.status === task.status).length;
        router.post(
            route('tasks.move', task.id),
            { index },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setMoving(true),
                onFinish: () => {
                    setMoving(false);
                    done();
                },
                onSuccess: () => {
                    setAnnounce(t('tasks.order.moved', { name: task.title, position: index + 1, total }));
                    if (via !== 'drag') setFocusAfterMove({ id: task.id, direction: via });
                },
                onError: () => setMoveFailed(true),
                onHttpException: failMove,
                onNetworkError: failMove,
            },
        );
    };

    const toggleReorder = () => {
        setStageFilter('all');
        setMoveFailed(false);
        setReordering((on) => !on);
    };

    return (
        <AppShell title={`${sub.name} · ${project.name}`}>
            <p className="m-0 text-sm text-muted">
                <Link href={route('projects.index')} className="link">
                    {t('projects.title')}
                </Link>
                {' / '}
                <Link href={route('projects.show', project.id)} className="link">
                    {project.name}
                </Link>
            </p>

            <div className="mt-1 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="h1 break-words">{sub.name}</h1>
                    <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-muted">
                        <PersonLine person={sub.lead} fallback={t('tasks.sub.no_lead')} />
                        <span>{t(`projects.status.${sub.status}`)}</span>
                        {sub.due_date && <span className="num">{t('tasks.sub.due', { date: formatShortDate(sub.due_date, locale) })}</span>}
                    </div>
                    {sub.description && <p className="m-0 mt-3 max-w-[70ch] whitespace-pre-line">{sub.description}</p>}
                </div>
                <div className="flex flex-wrap gap-2">
                    {canReorder && (
                        <button type="button" className="btn btn-secondary" aria-pressed={showReorder} onClick={toggleReorder}>
                            {showReorder ? (
                                <>
                                    <Check weight="bold" size={18} aria-hidden />
                                    {t('tasks.order.done')}
                                </>
                            ) : (
                                t('tasks.order.start')
                            )}
                        </button>
                    )}
                    {canAdd && !showReorder && (
                        <button type="button" className="btn btn-primary" onClick={() => setDialog('task')}>
                            <Plus weight="bold" size={18} aria-hidden />
                            {can.create_task ? t('tasks.list.add') : t('tasks.list.propose')}
                        </button>
                    )}
                    {can.manage && (
                        <button type="button" className="btn btn-secondary" onClick={() => setDialog('edit')}>
                            {t('tasks.sub.edit')}
                        </button>
                    )}
                </div>
            </div>

            {props.budget && (
                <div className="mt-5 max-w-[560px]">
                    <BudgetMeter
                        budget={props.budget}
                        source={t('projects.budget.source_sub')}
                        action={
                            can.manage && (
                                <button type="button" className="btn btn-quiet btn-sm min-h-11" onClick={() => setDialog('edit')}>
                                    {t('projects.budget.set')}
                                </button>
                            )
                        }
                    />
                </div>
            )}

            <p className="m-0 mt-4 max-w-[70ch] text-sm">
                {showReorder
                    ? t('tasks.order.hint')
                    : closed
                      ? t('tasks.list.closed_note')
                      : can.create_task
                        ? t('tasks.list.lead_hint')
                        : can.propose_task
                          ? t('tasks.list.propose_hint')
                          : t('tasks.list.cannot_propose')}
            </p>

            <p className="sr-only" aria-live="polite">
                {announce}
            </p>
            {moveFailed && (
                <Notice tone="danger" className="mt-4">
                    {t('tasks.order.failed')}
                </Notice>
            )}

            {tasks.length === 0 ? (
                <div className="card mt-6 px-5 py-5">
                    <p className="m-0 font-semibold">{t('tasks.list.empty_title')}</p>
                    <p className="m-0 mt-1 text-sm text-muted">{can.create_task ? t('tasks.list.empty_lead') : t('tasks.list.empty_member')}</p>
                </div>
            ) : (
                <div ref={groupsRef} className="mt-6 flex flex-col gap-7">
                    {usedStages.length > 0 && !showReorder && (
                        <SelectField className="w-full sm:max-w-[320px]" label={t('tasks.list.filter_stage')} value={stageFilter} onChange={(e) => setStageFilter(e.target.value)}>
                            <option value="all">{t('tasks.list.filter_all')}</option>
                            {hasUnstaged && <option value="none">{t('tasks.list.filter_none')}</option>}
                            {PHASES.map((phase) => {
                                const inPhase = usedStages.filter((stage) => stage.phase === phase);
                                if (inPhase.length === 0) return null;
                                return (
                                    <optgroup key={phase} label={t(`pipeline.phase.${phase}`)}>
                                        {inPhase.map((stage) => (
                                            <option key={stage.id} value={stage.id}>
                                                {stage.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                );
                            })}
                        </SelectField>
                    )}
                    {visible.length === 0 && (
                        <div className="card px-5 py-5">
                            <p className="m-0 font-semibold">{t('tasks.list.filter_empty')}</p>
                            <button type="button" className="btn btn-quiet btn-sm mt-1 min-h-11 px-0" onClick={() => setStageFilter('all')}>
                                {t('tasks.list.filter_reset')}
                            </button>
                        </div>
                    )}
                    {STATUS_ORDER.filter((status) => status !== 'rejected').map((status) => {
                        const items = byStatus(status);
                        if (items.length === 0) return null;
                        if (showReorder && items.length > 1) return <ReorderGroup key={status} status={status} items={items} busy={moving} onMove={move} />;
                        return <TaskGroup key={status} status={status} items={items} />;
                    })}
                    {rejected.length > 0 && !showReorder && (
                        <div>
                            <button type="button" className="btn btn-quiet btn-sm min-h-11 px-0" aria-expanded={showRejected} onClick={() => setShowRejected((v) => !v)}>
                                {showRejected ? t('tasks.list.rejected_hide') : t('tasks.list.rejected_toggle', { count: rejected.length })}
                            </button>
                            {showRejected && <TaskGroup status="rejected" items={rejected} />}
                        </div>
                    )}
                </div>
            )}

            {dialog === 'task' && (
                <TaskDialog
                    mode={can.create_task ? 'create' : 'propose'}
                    lead={can.create_task}
                    projectId={project.id}
                    subProjectId={sub.id}
                    people={props.people}
                    maxAssignees={props.max_assignees}
                    priorities={props.priorities}
                    stages={props.stages}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'edit' && (
                <SubProjectDialog projectId={project.id} subProject={sub} leads={props.leads} statuses={props.statuses} budgetMinutes={props.budget?.minutes} onClose={() => setDialog(null)} />
            )}
        </AppShell>
    );
}

function TaskGroup({ status, items }: { status: TaskStatus; items: TaskRow[] }) {
    const t = useT();
    const headingId = useId();

    return (
        <section aria-labelledby={headingId}>
            <h2 id={headingId} className="m-0 flex items-center gap-2 text-base font-semibold">
                {t(`tasks.status.${status}`)}
                <span className="num font-normal text-muted">({items.length})</span>
            </h2>
            <ul className="card m-0 mt-2 list-none divide-y divide-line p-0">
                {items.map((task) => (
                    <TaskListItem key={task.id} task={task} showStatus={false} />
                ))}
            </ul>
        </section>
    );
}
