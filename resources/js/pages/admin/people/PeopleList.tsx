import { useT } from '@/lib/i18n';
import { PencilSimple } from '@phosphor-icons/react';
import { StatusChip } from './StatusChip';
import type { PersonRow, TeamOption } from './types';

interface Props {
    people: PersonRow[];
    teams: TeamOption[];
    onEdit: (person: PersonRow) => void;
}

/**
 * Calm table on wide screens (ENERGY 1 inside tables). Below md the same rows stack as a list,
 * so nothing scrolls sideways on a phone.
 */
export function PeopleList({ people, teams, onEdit }: Props) {
    const t = useT();
    const teamName = new Map(teams.map((team) => [team.id, team.name]));

    const teamsOf = (person: PersonRow) => person.team_ids.map((id) => teamName.get(id)).filter(Boolean).join(', ');
    const rolesOf = (person: PersonRow) => person.roles.map((role) => t(`common.roles.${role}`)).join(', ');

    return (
        <>
            <div className="table-wrap hidden md:block">
                <table className="table">
                    <thead>
                        <tr>
                            <th scope="col">{t('people.columns.name')}</th>
                            <th scope="col">{t('people.columns.username')}</th>
                            <th scope="col">{t('people.columns.teams')}</th>
                            <th scope="col">{t('people.columns.roles')}</th>
                            <th scope="col">{t('people.columns.status')}</th>
                            <th scope="col">
                                <span className="sr-only">{t('people.columns.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {people.map((person) => (
                            <tr key={person.id}>
                                <td>
                                    <span className="flex items-center gap-2.5">
                                        <span className="avatar" aria-hidden>
                                            {person.initials}
                                        </span>
                                        <b className="font-semibold">{person.name}</b>
                                    </span>
                                </td>
                                <td className="break-all">{person.username}</td>
                                <td>{teamsOf(person) || <span className="text-muted">{t('people.no_team')}</span>}</td>
                                <td>{rolesOf(person)}</td>
                                <td>
                                    <StatusChip status={person.status} />
                                </td>
                                <td className="text-right">
                                    <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => onEdit(person)} aria-label={t('people.edit_label', { name: person.name })}>
                                        <PencilSimple weight="bold" size={16} aria-hidden />
                                        {t('people.edit')}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ul className="card m-0 list-none divide-y divide-line p-0 md:hidden">
                {people.map((person) => (
                    <li key={person.id} className="flex items-start gap-3 px-4 py-3.5">
                        <span className="avatar mt-0.5" aria-hidden>
                            {person.initials}
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="m-0 font-semibold break-words">{person.name}</p>
                            <p className="m-0 text-sm break-all text-muted">{person.username}</p>
                            <p className="m-0 mt-1 text-sm">{rolesOf(person)}</p>
                            <p className="m-0 text-sm text-muted">{teamsOf(person) || t('people.no_team')}</p>
                            <div className="mt-2">
                                <StatusChip status={person.status} />
                            </div>
                        </div>
                        <button type="button" className="btn btn-secondary flex-none px-3" onClick={() => onEdit(person)} aria-label={t('people.edit_label', { name: person.name })}>
                            <PencilSimple weight="bold" size={16} aria-hidden />
                            {t('people.edit')}
                        </button>
                    </li>
                ))}
            </ul>
        </>
    );
}
