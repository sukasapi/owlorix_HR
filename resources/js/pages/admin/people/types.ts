export type PersonStatus = 'active' | 'suspended' | 'left';
export type RoleName = 'employee' | 'team_lead' | 'project_manager' | 'project_director' | 'superadmin';

export interface PersonRow {
    id: number;
    name: string;
    username: string;
    initials: string;
    email: string | null;
    employee_code: string | null;
    status: PersonStatus;
    roles: RoleName[];
    team_ids: number[];
}

export interface TeamOption {
    id: number;
    name: string;
}

export interface Filters {
    q: string;
    status: PersonStatus | 'all';
    team: number | 'all' | 'none';
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export interface PeoplePageProps {
    people: Paginated<PersonRow>;
    filters: Filters;
    teams: TeamOption[];
    roles: RoleName[];
    statuses: PersonStatus[];
}
