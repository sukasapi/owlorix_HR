import type { Person } from '@/types';
import type { Milestone } from '../projects/Milestones';
import type { AssigneeName, PipelinePhase, TaskStatus } from '../projects/taskTypes';

export interface ProjectRef {
    id: number;
    name: string;
    code: string | null;
}

export interface WorkScopeInfo {
    /** projects.oversee: the whole studio */
    studio: boolean;
    /** Set when a Team Lead leads neither a team nor a sub project */
    empty_reason: 'no_team_or_sub_project' | null;
}

/** Tasks in the four ordered buckets. Proposals and rejected proposals are never counted. */
export interface Buckets {
    todo: number;
    doing: number;
    review: number;
    done: number;
}

export interface StatusRow extends Buckets {
    id: number;
    name: string;
    code: string | null;
    href: string;
}

export interface StageRow extends Buckets {
    /** Null for tasks without a stage */
    id: number | null;
    name: string | null;
    phase: PipelinePhase | null;
}

export interface HoursRow {
    id: number;
    name: string;
    code: string | null;
    href: string;
    minutes: number;
}

export interface BudgetRow {
    id: number;
    name: string;
    code: string | null;
    href: string;
    budget_minutes: number;
    logged_minutes: number;
}

export interface DueTask {
    id: number;
    title: string;
    status: TaskStatus;
    /** Studio date, Y-m-d */
    due_date: string;
    /** Negative once the date has passed */
    days_until: number;
    project: ProjectRef | null;
    sub_project: { id: number; name: string } | null;
    /** Every assignee, first assigned first */
    assignees: AssigneeName[];
}

export interface WorkMonitorProps {
    period: { from: string; until: string; weeks: number };
    today: string;
    filters: { project: number | null; weeks: number };
    options: { projects: ProjectRef[]; weeks: number[] };
    scope: WorkScopeInfo;
    headline: { open: number; overdue: number; in_review: number; done_in_period: number };
    /** Every week of the period, oldest first; `week` is its Monday */
    throughput: { week: string; created: number; done: number }[];
    status: { by: 'project' | 'sub_project'; rows: StatusRow[] };
    /** Only when one project is picked */
    stages: StageRow[] | null;
    hours: HoursRow[];
    review: { reviewed: number; changes_requested: number; median_wait_minutes: number | null };
    due: { total: number; rows: DueTask[] };
    milestones: { total: number; rows: (Milestone & { project: ProjectRef | null })[] };
    /** Sent to projects.budget holders only; missing for everyone else */
    budgets?: BudgetRow[];
}

export type LoadStatus = 'over' | 'full' | 'fit' | 'loose' | 'no_capacity';

export interface WorkloadRow {
    person: Person;
    workdays: number;
    leave_days: number;
    capacity_minutes: number;
    planned_minutes: number;
    planned_tasks: number;
    without_estimate: number;
    logged_minutes: number;
    regular_minutes: number;
    status: LoadStatus;
}

export interface WorkloadProps {
    week: { start: string; end: string; previous: string; next: string; current: string; is_current: boolean };
    limit_minutes: number;
    thresholds: { loose: number; full: number };
    /** empty_reason 'no_team': the viewer leads no team (leading sub projects does not count here) */
    scope: { studio: boolean; empty_reason: 'no_team' | null; leads_team: boolean };
    counts: Record<LoadStatus, number>;
    /** Overloaded people first */
    people: WorkloadRow[];
}
