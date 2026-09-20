import { OwlEyes } from '@/components/owl/OwlEyes';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { Plus } from '@phosphor-icons/react';
import { useState } from 'react';
import { CreateTeamDialog } from './CreateTeamDialog';
import { TeamDialog } from './TeamDialog';
import type { TeamRow, TeamsPageProps } from './types';

export default function TeamsIndex() {
    const { props } = usePage<SharedProps & TeamsPageProps>();
    const { teams, people } = props;
    const t = useT();
    const [creating, setCreating] = useState(false);
    const [managingId, setManagingId] = useState<number | null>(null);

    // The dialog always reads the team from fresh props, so every save shows the server's result.
    const managing = teams.find((team) => team.id === managingId) ?? null;
    const canManageUsers = props.auth?.permissions.includes('users.manage') ?? false;

    const leadOf = (team: TeamRow) => team.members.find((member) => member.id === team.lead_user_id) ?? null;

    return (
        <AppShell title={t('teams.title')}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="h1">{t('teams.title')}</h1>
                    <p className="m-0 mt-1 text-muted">{t('teams.lead_intro')}</p>
                </div>
                <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>
                    <Plus weight="bold" size={18} aria-hidden />
                    {t('teams.add')}
                </button>
            </div>

            <div className="mt-[18px]">
                {teams.length === 0 ? (
                    <section className="card flex flex-col items-start gap-3 px-5 py-6">
                        <OwlEyes state="closed" size={56} />
                        <h2 className="h2">{t('teams.states.empty_title')}</h2>
                        <p className="m-0 max-w-[60ch] text-muted">{t('teams.states.empty_body')}</p>
                        <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>
                            <Plus weight="bold" size={18} aria-hidden />
                            {t('teams.add')}
                        </button>
                    </section>
                ) : (
                    <>
                        <div className="table-wrap hidden md:block">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th scope="col">{t('teams.columns.name')}</th>
                                        <th scope="col">{t('teams.columns.lead')}</th>
                                        <th scope="col">{t('teams.columns.members')}</th>
                                        <th scope="col">
                                            <span className="sr-only">{t('teams.columns.actions')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {teams.map((team) => {
                                        const lead = leadOf(team);
                                        return (
                                            <tr key={team.id}>
                                                <td className="font-semibold">{team.name}</td>
                                                <td>
                                                    {lead ? (
                                                        <span className="flex items-center gap-2.5">
                                                            <span className="avatar" aria-hidden>
                                                                {lead.initials}
                                                            </span>
                                                            {lead.name}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted">{t('teams.no_lead')}</span>
                                                    )}
                                                </td>
                                                <td className="num">{t('teams.member_count', { count: team.members.length })}</td>
                                                <td className="text-right">
                                                    <button
                                                        type="button"
                                                        className="btn btn-secondary btn-sm min-h-11"
                                                        onClick={() => setManagingId(team.id)}
                                                        aria-label={t('teams.manage_label', { name: team.name })}
                                                    >
                                                        {t('teams.manage')}
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <ul className="card m-0 list-none divide-y divide-line p-0 md:hidden">
                            {teams.map((team) => {
                                const lead = leadOf(team);
                                return (
                                    <li key={team.id} className="flex items-center gap-3 px-4 py-3.5">
                                        <div className="min-w-0 flex-1">
                                            <p className="m-0 font-semibold break-words">{team.name}</p>
                                            <p className="m-0 text-sm text-muted">
                                                {lead ? lead.name : t('teams.no_lead')}
                                                {' · '}
                                                <span className="num">{t('teams.member_count', { count: team.members.length })}</span>
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            className="btn btn-secondary flex-none px-3"
                                            onClick={() => setManagingId(team.id)}
                                            aria-label={t('teams.manage_label', { name: team.name })}
                                        >
                                            {t('teams.manage')}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}
            </div>

            <CreateTeamDialog open={creating} onClose={() => setCreating(false)} onCreated={setManagingId} />
            <TeamDialog team={managing} people={people} canManageUsers={canManageUsers} onClose={() => setManagingId(null)} />
        </AppShell>
    );
}
