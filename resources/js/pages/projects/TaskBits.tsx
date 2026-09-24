import { Avatar } from '@/components/ui/Avatar';
import { formatMinutes, formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { Person, SharedProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ArrowCounterClockwise, CaretDoubleUp, CaretUp, CheckCircle, CircleDashed, HourglassMedium, Paperclip } from '@phosphor-icons/react';
import { type AssigneeName, PART_CHIP, STATUS_CHIP, studioToday, type PartStatus, type TaskPriority, type TaskRow, type TaskStatus } from './taskTypes';

export function StatusChip({ status }: { status: TaskStatus }) {
    const t = useT();
    return <span className={`chip ${STATUS_CHIP[status]}`}>{t(`tasks.status.${status}`)}</span>;
}

/** Icons tell the four part states apart without relying on the chip color. */
const PART_ICON = {
    open: CircleDashed,
    submitted: HourglassMedium,
    changes_requested: ArrowCounterClockwise,
    approved: CheckCircle,
} as const;

/** The state of one person's part: icon plus text, never color alone. `label` prefixes it, for example "Bagianmu". */
export function PartChip({ status, label }: { status: PartStatus; label?: string }) {
    const t = useT();
    const Icon = PART_ICON[status];

    return (
        <span className={`chip ${PART_CHIP[status]}`}>
            <Icon weight="bold" size={14} aria-hidden className="flex-none" />
            {label ? `${label}: ${t(`tasks.part.${status}`)}` : t(`tasks.part.${status}`)}
        </span>
    );
}

/** Only high and urgent get a mark, so the list is not full of icons for normal work. */
export function PriorityMark({ priority }: { priority: TaskPriority }) {
    const t = useT();
    if (priority !== 'high' && priority !== 'urgent') return null;
    const Icon = priority === 'urgent' ? CaretDoubleUp : CaretUp;

    return (
        <span className={`inline-flex items-center gap-1 text-sm font-semibold ${priority === 'urgent' ? 'text-danger' : 'text-gold-text'}`}>
            <Icon weight="bold" size={14} aria-hidden />
            {t(`tasks.priority.${priority}`)}
        </span>
    );
}

export function PersonLine({ person, fallback }: { person: Person | null; fallback: string }) {
    if (!person) return <span className="text-sm text-muted">{fallback}</span>;

    return (
        <span className="inline-flex min-w-0 items-center gap-2">
            <Avatar initials={person.initials} photoUrl={person.photo_url} className="h-7 w-7 text-[11px]" />
            <span className="min-w-0 truncate text-sm">{person.name}</span>
        </span>
    );
}

const STACK_MAX = 3;

/**
 * The people on a task in a list row. One person reads as avatar and nickname; several as up to three overlapping
 * avatars plus "+N". Either way the full names (docs/15 section 4) are the accessible label and the hover title.
 */
export function AssigneeStack({ people, fallback }: { people: AssigneeName[]; fallback: string }) {
    const t = useT();
    if (people.length === 0) return <span className="text-sm text-muted">{fallback}</span>;

    const names = t('tasks.list.assignees_label', { names: people.map((p) => p.full_name).join(', ') });

    if (people.length === 1) {
        return (
            <span className="inline-flex min-w-0" title={names}>
                <span className="sr-only">{names}</span>
                <span className="inline-flex min-w-0" aria-hidden>
                    <PersonLine person={people[0]} fallback={fallback} />
                </span>
            </span>
        );
    }

    const shown = people.slice(0, STACK_MAX);
    const more = people.length - shown.length;

    return (
        <span className="inline-flex items-center" title={names}>
            <span className="sr-only">{names}</span>
            <span className="inline-flex items-center" aria-hidden>
                {shown.map((person, i) => (
                    <Avatar key={person.id} initials={person.initials} photoUrl={person.photo_url} className={`h-7 w-7 text-[11px] ring-2 ring-surface ${i > 0 ? '-ml-2' : ''}`} />
                ))}
                {more > 0 && <span className="avatar num -ml-2 h-7 w-7 text-[11px] ring-2 ring-surface">+{more}</span>}
            </span>
        </span>
    );
}

/** One task in a list: title first (what the work is), then who and when. */
export function TaskListItem({ task, showPlace = false, showStatus = true }: { task: TaskRow; showPlace?: boolean; showStatus?: boolean }) {
    const t = useT();
    const locale = useLocale();
    const timezone = usePage<SharedProps>().props.app.timezone;
    const overdue = task.due_date !== null && task.status !== 'done' && task.status !== 'rejected' && task.due_date < studioToday(timezone);
    // On a shared task the viewer's own part can differ from the task status, so it gets its own chip
    const showMine = task.my_part != null && task.assignees.length > 1 && task.status !== 'proposed' && task.status !== 'rejected';

    const meta: string[] = [];
    if (task.logged_minutes > 0) meta.push(t('tasks.list.logged', { time: formatMinutes(task.logged_minutes, locale) }));
    if (task.estimate_minutes) meta.push(t('tasks.list.estimate', { time: formatMinutes(task.estimate_minutes, locale) }));

    return (
        <li className="flex flex-col gap-2 px-4 py-3.5 sm:flex-row sm:items-center sm:gap-4">
            <div className="min-w-0 flex-1">
                <Link href={route('tasks.show', task.id)} className="font-semibold break-words text-ink hover:underline">
                    {task.title}
                </Link>
                {showPlace && task.project && (
                    <p className="m-0 text-sm break-words text-muted">
                        {t('tasks.mine.in', { project: task.project.name, sub: task.sub_project?.name ?? '' })}
                    </p>
                )}
                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
                    <PriorityMark priority={task.priority} />
                    {task.stage && <span>{t('tasks.list.stage', { name: task.stage.name })}</span>}
                    {task.due_date && (
                        <span className={`num ${overdue ? 'font-semibold text-danger' : ''}`}>
                            {overdue ? t('tasks.list.overdue', { date: formatShortDate(task.due_date, locale) }) : t('tasks.list.due', { date: formatShortDate(task.due_date, locale) })}
                        </span>
                    )}
                    {meta.length > 0 && <span className="num">{meta.join(' · ')}</span>}
                    {!task.evidence_required && (
                        <span className="inline-flex items-center gap-1">
                            <Paperclip weight="bold" size={13} aria-hidden />
                            {t('tasks.list.evidence_optional')}
                        </span>
                    )}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-3 sm:flex-none sm:justify-end">
                <AssigneeStack people={task.assignees} fallback={t('tasks.list.unassigned')} />
                {showStatus && <StatusChip status={task.status} />}
                {showMine && task.my_part && <PartChip status={task.my_part} label={t('tasks.list.my_part')} />}
            </div>
        </li>
    );
}
