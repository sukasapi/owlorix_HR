import type { EyeState } from '@/components/owl/OwlEyes';

export type BoardGroup = 'attention' | 'overtime' | 'working' | 'idle' | 'out' | 'leave' | 'not_started';
export type BoardStatus = 'prompted' | 'report_due' | 'overtime' | 'open' | 'idle' | 'interrupted' | 'out' | 'leave' | 'not_started';

export interface BoardPerson {
    id: number;
    name: string;
    initials: string;
    team_ids: number[];
    teams: string[];
    group: BoardGroup;
    status: BoardStatus;
    eyes: EyeState;
    since: string | null;
    device: string | null;
    regular_minutes: number;
    overtime_minutes: number;
    idle: {
        started_at: string | null;
        minutes: number;
        tag: 'rendering' | 'meeting' | 'break' | 'other' | null;
    } | null;
    needs_review: boolean;
    /** Approved leave today (docs/14); someone who clocks in anyway shows by their shift */
    leave: { type: string } | null;
}

export interface Board {
    date: string;
    generated_at: string;
    teams: { id: number; name: string }[];
    people: BoardPerson[];
}

export interface TeamTodayProps {
    scope: { everyone: boolean; leads_team: boolean };
    board: Board;
}
