import { useT } from '@/lib/i18n';
import { ArrowDown, ArrowUp, DotsSixVertical } from '@phosphor-icons/react';
import { type PointerEvent as ReactPointerEvent, useEffect, useId, useRef, useState } from 'react';
import { PriorityMark } from './TaskBits';
import type { TaskRow, TaskStatus } from './taskTypes';

/** Rows this close to the top or bottom of the window scroll the page while a task is dragged */
const EDGE = 72;
const SCROLL_STEP = 14;

interface Props {
    status: TaskStatus;
    items: TaskRow[];
    /** A request is on its way; arrows and handles wait for it */
    busy: boolean;
    /** Asks the server to put the task at `index` in this group. `done` runs when the request settles. */
    onMove: (task: TaskRow, index: number, via: 'drag' | 'up' | 'down', done: () => void) => void;
}

interface DragState {
    id: number;
    from: number;
    pointerId: number;
    y: number;
    frame: number;
}

/**
 * One status group in reorder mode. A row is dragged by its handle (mouse or finger) and snaps into place as it
 * passes the middle of its neighbour; the arrows do the same one step at a time for the keyboard.
 */
export function ReorderGroup({ status, items, busy, onMove }: Props) {
    const t = useT();
    const headingId = useId();
    const listRef = useRef<HTMLUListElement>(null);
    const drag = useRef<DragState | null>(null);
    // The order shown while dragging and until the server answers; null shows the order from the page props
    const [draft, setDraft] = useState<number[] | null>(null);
    const [draggingId, setDraggingId] = useState<number | null>(null);

    const byId = new Map(items.map((task) => [task.id, task]));
    const shown = draft ? draft.map((id) => byId.get(id)).filter((task): task is TaskRow => task !== undefined) : items;
    const shownRef = useRef(shown);
    shownRef.current = shown;

    useEffect(() => () => stop(), []);

    // Escape puts a dragged row back where it started
    useEffect(() => {
        if (draggingId === null) return;
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') cancel();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [draggingId]);

    const stop = () => {
        if (drag.current) cancelAnimationFrame(drag.current.frame);
        drag.current = null;
        setDraggingId(null);
    };

    const cancel = () => {
        stop();
        setDraft(null);
    };

    /** Puts the dragged row after every other row whose middle is above the pointer. */
    const retarget = (y: number) => {
        const state = drag.current;
        const list = listRef.current;
        if (!state || !list) return;

        const ids = shownRef.current.map((task) => task.id);
        const rows = Array.from(list.children) as HTMLElement[];
        let index = 0;
        rows.forEach((row, i) => {
            if (ids[i] === state.id) return;
            const box = row.getBoundingClientRect();
            if (y > box.top + box.height / 2) index++;
        });

        const others = ids.filter((id) => id !== state.id);
        const next = [...others.slice(0, index), state.id, ...others.slice(index)];
        if (next.some((id, i) => id !== ids[i])) setDraft(next);
    };

    // Scrolls while the finger rests near an edge, so a long list can be crossed on a phone
    const tick = () => {
        const state = drag.current;
        if (!state) return;
        const step = state.y < EDGE ? -SCROLL_STEP : state.y > window.innerHeight - EDGE ? SCROLL_STEP : 0;
        if (step !== 0) {
            window.scrollBy(0, step);
            retarget(state.y);
        }
        state.frame = requestAnimationFrame(tick);
    };

    const onPointerDown = (event: ReactPointerEvent<HTMLSpanElement>, task: TaskRow, index: number) => {
        if (busy || event.button !== 0) return;
        event.preventDefault();
        event.currentTarget.setPointerCapture(event.pointerId);
        drag.current = { id: task.id, from: index, pointerId: event.pointerId, y: event.clientY, frame: 0 };
        drag.current.frame = requestAnimationFrame(tick);
        setDraggingId(task.id);
        setDraft(items.map((item) => item.id));
    };

    const onPointerMove = (event: ReactPointerEvent<HTMLSpanElement>) => {
        const state = drag.current;
        if (!state || state.pointerId !== event.pointerId) return;
        state.y = event.clientY;
        retarget(event.clientY);
    };

    const onPointerUp = (event: ReactPointerEvent<HTMLSpanElement>, task: TaskRow) => {
        const state = drag.current;
        if (!state || state.pointerId !== event.pointerId) return;
        const to = shownRef.current.findIndex((item) => item.id === task.id);
        const from = state.from;
        stop();

        if (to === from || to < 0) {
            setDraft(null);
            return;
        }
        onMove(task, to, 'drag', () => setDraft(null));
    };

    const step = (task: TaskRow, index: number, direction: 'up' | 'down') => {
        const to = direction === 'up' ? index - 1 : index + 1;
        const ids = items.map((item) => item.id);
        [ids[index], ids[to]] = [ids[to], ids[index]];
        setDraft(ids);
        onMove(task, to, direction, () => setDraft(null));
    };

    const iconButton = 'btn btn-secondary btn-sm min-h-11 min-w-11 px-0';

    return (
        <section aria-labelledby={headingId}>
            <h2 id={headingId} className="m-0 flex items-center gap-2 text-base font-semibold">
                {t(`tasks.status.${status}`)}
                <span className="num font-normal text-muted">({items.length})</span>
            </h2>
            <ul ref={listRef} className="card m-0 mt-2 list-none divide-y divide-line p-0">
                {shown.map((task, index) => {
                    const dragging = draggingId === task.id;
                    return (
                        <li
                            key={task.id}
                            className={`relative flex items-center gap-2 py-2 pr-2 pl-1 ${dragging ? 'z-10 rounded-[8px] bg-selected shadow-[var(--shadow-float)]' : ''}`}
                        >
                            {/* Mouse and touch only; the arrows next to it are the keyboard way */}
                            <span
                                aria-hidden
                                title={t('tasks.order.handle')}
                                className={`grid min-h-11 min-w-11 flex-none place-items-center rounded-[8px] text-muted select-none ${busy ? 'cursor-wait opacity-50' : dragging ? 'cursor-grabbing text-ink' : 'cursor-grab hover:bg-selected hover:text-ink'}`}
                                style={{ touchAction: 'none' }}
                                onPointerDown={(event) => onPointerDown(event, task, index)}
                                onPointerMove={onPointerMove}
                                onPointerUp={(event) => onPointerUp(event, task)}
                                onPointerCancel={cancel}
                            >
                                <DotsSixVertical weight="bold" size={22} />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="m-0 font-semibold break-words text-ink">{task.title}</p>
                                {(task.priority === 'high' || task.priority === 'urgent') && (
                                    <div className="mt-0.5">
                                        <PriorityMark priority={task.priority} />
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-none gap-1">
                                <button
                                    type="button"
                                    className={iconButton}
                                    data-move={`${task.id}-up`}
                                    disabled={index === 0 || busy || draggingId !== null}
                                    onClick={() => step(task, index, 'up')}
                                    aria-label={t('tasks.order.up_label', { name: task.title })}
                                >
                                    <ArrowUp weight="bold" size={18} aria-hidden />
                                </button>
                                <button
                                    type="button"
                                    className={iconButton}
                                    data-move={`${task.id}-down`}
                                    disabled={index === shown.length - 1 || busy || draggingId !== null}
                                    onClick={() => step(task, index, 'down')}
                                    aria-label={t('tasks.order.down_label', { name: task.title })}
                                >
                                    <ArrowDown weight="bold" size={18} aria-hidden />
                                </button>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
