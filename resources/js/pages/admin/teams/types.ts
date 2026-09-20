import type { PersonStatus } from '../people/types';

export interface TeamMember {
    id: number;
    name: string;
    username: string;
    initials: string;
    status: PersonStatus;
    can_lead: boolean;
}

export interface TeamRow {
    id: number;
    name: string;
    lead_user_id: number | null;
    members: TeamMember[];
}

export interface PersonOption {
    id: number;
    name: string;
    username: string;
    status: PersonStatus;
}

export interface TeamsPageProps {
    teams: TeamRow[];
    people: PersonOption[];
}
