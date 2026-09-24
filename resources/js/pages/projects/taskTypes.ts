import type { Person } from '@/types';

export type TaskStatus = 'proposed' | 'rejected' | 'todo' | 'in_progress' | 'in_review' | 'changes_requested' | 'done';
/** One assignee's own part of a task (docs/15): their evidence and their review. */
export type PartStatus = 'open' | 'submitted' | 'changes_requested' | 'approved';
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent';

/** Who a task list shows as working on it: `name` is the nickname, `full_name` is read out by screen readers. */
export interface AssigneeName extends Person {
    full_name: string;
}

/** A person on a task, with the state of their part. */
export interface Assignee extends AssigneeName {
    part_status: PartStatus;
}
export type ProjectStatus = 'planned' | 'active' | 'done';
export type PipelinePhase = 'pre_production' | 'production' | 'post_production';

export const PHASES: PipelinePhase[] = ['pre_production', 'production', 'post_production'];

/** A production pipeline stage. Inactive stages stay on the tasks that have them but are not offered for new ones. */
export interface StageOption {
    id: number;
    name: string;
    phase: PipelinePhase;
    is_active: boolean;
}

/** Hour budget, sent only to people with projects.budget. `minutes` is null when no budget is set. */
export interface BudgetData {
    minutes: number | null;
    logged_minutes: number;
}

export interface TaskRow {
    id: number;
    title: string;
    status: TaskStatus;
    priority: TaskPriority;
    stage: StageOption | null;
    due_date: string | null;
    estimate_minutes: number | null;
    evidence_required: boolean;
    /** First assigned first; empty when nobody is on the task yet */
    assignees: Assignee[];
    /** The viewer's own part; only on lists built for one person (Tugas saya, a sub project) */
    my_part?: PartStatus | null;
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

/** Someone a lead can put on a task: a project member, or a current assignee (`active` false once deactivated). */
export interface AssigneeOption extends Person {
    username: string;
    active: boolean;
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

/** Chip tone per part: gold for "waiting for the lead", the same tones as the task statuses otherwise. */
export const PART_CHIP: Record<PartStatus, string> = {
    open: '',
    submitted: 'chip-pending',
    changes_requested: 'chip-bad',
    approved: 'chip-ok',
};

/** Today in the studio time zone as Y-m-d, to compare with due dates. */
export function studioToday(timezone: string): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date());
}
