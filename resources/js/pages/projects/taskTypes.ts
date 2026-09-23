import type { Person } from '@/types';

export type TaskStatus = 'proposed' | 'rejected' | 'todo' | 'in_progress' | 'in_review' | 'changes_requested' | 'done';
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent';
export type ProjectStatus = 'planned' | 'active' | 'done';

export interface TaskRow {
    id: number;
    title: string;
    status: TaskStatus;
    priority: TaskPriority;
    due_date: string | null;
    estimate_minutes: number | null;
    evidence_required: boolean;
    assignee: Person | null;
    creator: Person | null;
    logged_minutes: number;
    decision_note: string | null;
    updated_at: string | null;
    project?: { id: number; name: string; code: string | null } | null;
    sub_project?: { id: number; name: string } | null;
}

export interface SubProjectData {
    id: number;
    project_id: number;
    name: string;
    description: string | null;
    status: ProjectStatus;
    due_date: string | null;
    lead: Person | null;
    tasks_total?: number;
    tasks_done?: number;
    tasks_waiting?: number;
}

export interface PersonOption {
    id: number;
    name: string;
    username: string;
}

export interface RunningTimer {
    session_id: number;
    task_id: number;
    task_title: string;
    started_at: string;
}

/** Order of the groups on a sub project: what needs a decision first, finished work last. */
export const STATUS_ORDER: TaskStatus[] = ['proposed', 'in_review', 'changes_requested', 'in_progress', 'todo', 'done', 'rejected'];

/** Chip tone per status: gold is the accent for "someone must act", so only waiting states get it. */
export const STATUS_CHIP: Record<TaskStatus, string> = {
    proposed: 'chip-pending',
    in_review: 'chip-pending',
    changes_requested: 'chip-bad',
    in_progress: 'chip-info',
    todo: '',
    done: 'chip-ok',
    rejected: 'chip-bad',
};

/** Today in the studio time zone as Y-m-d, to compare with due dates. */
export function studioToday(timezone: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date());
}
