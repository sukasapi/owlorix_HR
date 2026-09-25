import { OwlEyes } from '@/components/owl/OwlEyes';
import { WeekTargetLine } from '@/components/WeekTarget';
import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { router, usePoll } from '@inertiajs/react';
import { ArrowsClockwise, Warning } from '@phosphor-icons/react';
import { type ReactNode, useEffect, useState } from 'react';
import { PersonCard, useStatusLine } from './PersonCard';
import type { BoardGroup, BoardPerson, TeamTodayProps } from './types';

const POLL_MS = 30_000;
const CARD_GROUPS: BoardGroup[] = ['attention', 'overtime', 'working', 'idle'];

/**
 * Tim hari ini. Focal point: the board of people (DESIGN.md). The `board` prop is reloaded every 30 seconds while
 * the tab is visible; Inertia only slows polling in a background tab, so the page stops it and refreshes on return.
 */
export default function TeamToday({ scope, board }: TeamTodayProps) {
    const t = useT();
    const locale = useLocale();
    const [teamId, setTeamId] = useState<number | 'all'>('all');
    const [failedAt, setFailedAt] = useState<string | null>(null);
    const [retrying, setRetrying] = useState(false);

    const canHavePeople = scope.everyone || scope.leads_team;

    const reloadOptions = {
        only: ['board'],
        onSuccess: () => setFailedAt(null),
        onHttpException: () => {
            setFailedAt(new Date().toISOString());
            return false;
        },
        onNetworkError: () => {
            setFailedAt(new Date().toISOString());
            return false;
        },
    };

    const { start, stop } = usePoll(POLL_MS, reloadOptions, {
        autoStart: canHavePeople,
    });

    useEffect(() => {
        if (!canHavePeople) return;
        const onVisibility = () => {
            if (document.hidden) {
                stop();
            } else {
                router.reload(reloadOptions);
                start();
            }
        };
        document.addEventListener('visibilitychange', onVisibility);
        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, [canHavePeople]); // start and stop are stable; reloadOptions only calls state setters

    const retry = () =>
        router.reload({
            ...reloadOptions,
            onStart: () => setRetrying(true),
            onFinish: () => setRetrying(false),
        });

    const people = teamId === 'all' ? board.people : board.people.filter((person) => person.team_ids.includes(teamId));
    const byGroup = (group: BoardGroup) => people.filter((person) => person.group === group);
    const nobodyIn = people.length > 0 && people.every((person) => person.group === 'not_started' || person.group === 'leave');
    const quietGroups = (['out', 'leave', 'not_started'] as const).filter((group) => byGroup(group).length > 0);
    const showTeam = teamId === 'all' && board.teams.length > 1;

    return (
        <AppShell title={t('team-today.title')}>
            <div className="flex flex-col gap-6">
                <header className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                    <h1 className="h1">{t('team-today.title')}</h1>
                    {canHavePeople && (
                        <div className="flex w-full flex-wrap items-center gap-x-5 gap-y-2 sm:w-auto">
                            {board.teams.length > 1 && (
                                <label className="flex w-full items-center gap-2 sm:w-auto">
                                    <span className="text-sm font-semibold">{t('team-today.team')}</span>
                                    <select
                                        className="input w-full sm:w-auto"
                                        value={teamId}
                                        onChange={(e) => setTeamId(e.target.value === 'all' ? 'all' : Number(e.target.value))}
                                    >
                                        <option value="all">{t('team-today.all_teams')}</option>
                                        {board.teams.map((team) => (
                                            <option key={team.id} value={team.id}>
                                                {team.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <p className="num m-0 flex items-center gap-1.5 text-sm text-muted">
                                <ArrowsClockwise weight="bold" size={16} aria-hidden />
                                {t('team-today.updated', {
                                    time: formatTime(board.generated_at, locale),
                                })}
                            </p>
                        </div>
                    )}
                </header>

                {failedAt && (
                    <Notice tone="danger">
                        <p className="m-0">
                            {t('team-today.errors.poll_failed', {
                                time: formatTime(failedAt, locale),
                                shown: formatTime(board.generated_at, locale),
                            })}
                        </p>
                        <button type="button" className="btn btn-secondary btn-sm mt-2 bg-surface" onClick={retry} disabled={retrying}>
                            <ArrowsClockwise weight="bold" size={16} aria-hidden />
                            {retrying ? t('team-today.updating') : t('team-today.errors.retry')}
                        </button>
                    </Notice>
                )}

                {!canHavePeople ? (
                    <EmptyState title={t('team-today.empty.no_team_title')} body={t('team-today.empty.no_team_body')} />
                ) : board.people.length === 0 ? (
                    <EmptyState title={t('team-today.empty.no_people_title')} body={t('team-today.empty.no_people_body')} />
                ) : people.length === 0 ? (
                    <EmptyState title={t('team-today.empty.filtered_title')}>
                        <button type="button" className="btn btn-secondary" onClick={() => setTeamId('all')}>
                            {t('team-today.empty.show_all')}
                        </button>
                    </EmptyState>
                ) : (
                    <>
                        {nobodyIn && <EmptyState title={t('team-today.empty.nobody_in_title')} body={t('team-today.empty.nobody_in_body')} />}

                        {CARD_GROUPS.map((group) => {
                            const members = byGroup(group);
                            if (members.length === 0) return null;
                            return (
                                <GroupSection key={group} group={group} count={members.length}>
                                    <ul className="m-0 grid list-none gap-3 p-0 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                                        {members.map((person) => (
                                            <PersonCard key={person.id} person={person} showTeam={showTeam} />
                                        ))}
                                    </ul>
                                </GroupSection>
                            );
                        })}

                        {quietGroups.length > 0 && (
                            <div className={`grid items-start gap-6 lg:grid-cols-2 ${quietGroups.length > 2 ? 'xl:grid-cols-3' : ''}`}>
                                {quietGroups.map((group) => {
                                    const members = byGroup(group);
                                    if (members.length === 0) return null;
                                    return (
                                        <GroupSection key={group} group={group} count={members.length} withEyes>
                                            <CompactList people={members} />
                                        </GroupSection>
                                    );
                                })}
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppShell>
    );
}

function GroupSection({ group, count, withEyes = false, children }: { group: BoardGroup; count: number; withEyes?: boolean; children: ReactNode }) {
    const t = useT();
    const id = `team-group-${group}`;

    return (
        <section aria-labelledby={id} className="flex flex-col gap-3">
            <h2 id={id} className="m-0 flex items-center gap-2.5 font-display text-lg font-[650] text-heading">
                {withEyes && <OwlEyes state="closed" size={40} />}
                {t(`team-today.groups.${group}`)}
                <span className={`chip num ${group === 'attention' ? 'chip-pending' : 'chip-info'}`}>{count}</span>
            </h2>
            {children}
        </section>
    );
}

function CompactList({ people }: { people: BoardPerson[] }) {
    const t = useT();
    const statusLine = useStatusLine();

    return (
        <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
            {people.map((person) => (
                <li key={person.id} className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="font-semibold">{person.name}</span>
                    {(person.status === 'out' || person.status === 'leave') && <span className="num text-sm text-muted">{statusLine(person)}</span>}
                    {person.needs_review && (
                        <span className="chip chip-bad">
                            <Warning weight="bold" size={15} aria-hidden />
                            {t('team-today.needs_review')}
                        </span>
                    )}
                    {person.week && <WeekTargetLine week={person.week} className="basis-full" />}
                </li>
            ))}
        </ul>
    );
}

function EmptyState({ title, body, children }: { title: string; body?: string; children?: ReactNode }) {
    return (
        <section className="card flex flex-col items-center gap-3 px-6 py-10 text-center">
            <OwlEyes state="closed" size={96} />
            <h2 className="h2 mt-2">{title}</h2>
            {body && <p className="m-0 max-w-[56ch] text-muted">{body}</p>}
            {children}
        </section>
    );
}
