import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowCounterClockwise, CaretLeft, CaretRight, MagnifyingGlass, UserPlus } from '@phosphor-icons/react';
import { useEffect, useId, useRef, useState } from 'react';
import { CredentialsDialog } from './CredentialsDialog';
import { PeopleList } from './PeopleList';
import { PersonDialog, type PersonDialogMode } from './PersonDialog';
import type { Filters, PeoplePageProps } from './types';
import { useListVisit } from './useListVisit';

const DEFAULT_FILTERS: Filters = { q: '', status: 'active', team: 'all' };

/** Only non-default filters go into the query string, so the plain page URL stays /admin/orang. */
function toQuery(filters: Filters): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filters.q.trim() !== '') query.q = filters.q.trim();
    if (filters.status !== DEFAULT_FILTERS.status) query.status = filters.status;
    if (filters.team !== DEFAULT_FILTERS.team) query.team = filters.team;
    return query;
}

export default function PeopleIndex() {
    const { props } = usePage<SharedProps & PeoplePageProps>();
    const { people, filters, teams, roles, statuses, employment_types } = props;
    const t = useT();
    const searchId = useId();
    const statusId = useId();
    const teamId = useId();

    const [query, setQuery] = useState(filters.q);
    const [dialog, setDialog] = useState<PersonDialogMode | null>(null);
    const issuedReason = useRef<'created' | 'reset'>('created');
    const [credentials, setCredentials] = useState(props.flash.issued_credentials ?? null);
    const list = useListVisit(new URL(route('admin.people.index'), window.location.origin).pathname);
    const debounce = useRef<number | undefined>(undefined);

    const issued = props.flash.issued_credentials;
    useEffect(() => {
        if (issued) setCredentials(issued);
    }, [issued]);

    useEffect(() => () => window.clearTimeout(debounce.current), []);

    const apply = (next: Filters) => {
        window.clearTimeout(debounce.current);
        router.get(route('admin.people.index'), toQuery(next), { preserveState: true, preserveScroll: true, replace: true });
    };

    const onSearch = (value: string) => {
        setQuery(value);
        window.clearTimeout(debounce.current);
        debounce.current = window.setTimeout(() => apply({ ...filters, q: value }), 350);
    };

    const closeCredentials = () => {
        setCredentials(null);
        // Drop the password from the page props and this history entry, so Back never shows it again.
        router.replaceProp('flash.issued_credentials', undefined);
    };

    const filtered = filters.q !== '' || filters.team !== 'all' || filters.status !== 'active';
    const canManageTeams = props.auth?.permissions.includes('teams.manage') ?? false;

    return (
        <AppShell title={t('people.title')}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="h1">{t('people.title')}</h1>
                <button type="button" className="btn btn-primary" onClick={() => setDialog({ kind: 'create' })}>
                    <UserPlus weight="bold" size={18} aria-hidden />
                    {t('people.add')}
                </button>
            </div>

            <form
                role="search"
                className="mt-[18px] mb-3.5 flex flex-col gap-2.5 sm:flex-row sm:flex-wrap"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({ ...filters, q: query });
                }}
            >
                <div className="relative sm:w-[320px]">
                    <label htmlFor={searchId} className="sr-only">
                        {t('people.filters.search_label')}
                    </label>
                    <MagnifyingGlass weight="bold" size={17} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" aria-hidden />
                    <input
                        id={searchId}
                        type="search"
                        className="input pl-9"
                        placeholder={t('people.filters.search_placeholder')}
                        autoComplete="off"
                        spellCheck={false}
                        value={query}
                        onChange={(e) => onSearch(e.target.value)}
                    />
                </div>
                <div className="grid gap-2.5 min-[480px]:grid-cols-2 sm:flex">
                    <label htmlFor={statusId} className="sr-only">
                        {t('people.filters.status_label')}
                    </label>
                    <select id={statusId} className="input min-w-0 font-semibold sm:w-auto" value={filters.status} onChange={(e) => apply({ ...filters, q: query, status: e.target.value as Filters['status'] })}>
                        {(['active', 'suspended', 'left', 'all'] as const).map((status) => (
                            <option key={status} value={status}>
                                {t(`people.filters.status.${status}`)}
                            </option>
                        ))}
                    </select>
                    <label htmlFor={teamId} className="sr-only">
                        {t('people.filters.team_label')}
                    </label>
                    <select
                        id={teamId}
                        className="input min-w-0 font-semibold sm:w-auto"
                        value={String(filters.team)}
                        onChange={(e) => {
                            const value = e.target.value;
                            apply({ ...filters, q: query, team: value === 'all' || value === 'none' ? value : Number(value) });
                        }}
                    >
                        <option value="all">{t('people.filters.team_all')}</option>
                        <option value="none">{t('people.filters.team_none')}</option>
                        {teams.map((team) => (
                            <option key={team.id} value={team.id}>
                                {t('people.filters.team_named', { name: team.name })}
                            </option>
                        ))}
                    </select>
                </div>
            </form>

            <div aria-live="polite" className="mb-2.5 min-h-[22px] text-sm text-muted">
                {list.state === 'loading' ? t('people.states.loading') : people.total > 0 && people.from !== null && people.to !== null ? t('people.count', { from: people.from, to: people.to, total: people.total }) : null}
            </div>

            {list.state === 'error' ? (
                <section className="card flex flex-col items-start gap-3 px-5 py-6" role="alert">
                    <h2 className="h2">{t('people.states.error_title')}</h2>
                    <p className="m-0 text-muted">{t('people.states.error_body')}</p>
                    <button type="button" className="btn btn-secondary" onClick={list.retry}>
                        <ArrowCounterClockwise weight="bold" size={18} aria-hidden />
                        {t('common.actions.retry')}
                    </button>
                </section>
            ) : people.data.length === 0 ? (
                <section className="card flex flex-col items-start gap-3 px-5 py-6">
                    <h2 className="h2">{filtered ? t('people.states.filtered_title') : t('people.states.empty_title')}</h2>
                    <p className="m-0 max-w-[60ch] text-muted">{filtered ? t('people.states.filtered_body') : t('people.states.empty_body')}</p>
                    {filtered ? (
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => {
                                setQuery('');
                                apply(DEFAULT_FILTERS);
                            }}
                        >
                            {t('people.filters.clear')}
                        </button>
                    ) : (
                        <button type="button" className="btn btn-primary" onClick={() => setDialog({ kind: 'create' })}>
                            <UserPlus weight="bold" size={18} aria-hidden />
                            {t('people.add')}
                        </button>
                    )}
                </section>
            ) : (
                <div className={list.state === 'loading' ? 'opacity-60 transition-opacity' : 'transition-opacity'} aria-busy={list.state === 'loading'}>
                    <PeopleList people={people.data} teams={teams} onEdit={(person) => setDialog({ kind: 'edit', person })} />
                </div>
            )}

            {people.last_page > 1 && list.state !== 'error' && (
                <nav aria-label={t('people.pagination.label')} className="mt-4 flex items-center justify-between gap-3">
                    <PageLink href={people.prev_page_url} direction="previous" />
                    <span className="num text-sm text-muted">{t('people.pagination.page', { current: people.current_page, last: people.last_page })}</span>
                    <PageLink href={people.next_page_url} direction="next" />
                </nav>
            )}

            <PersonDialog
                mode={dialog}
                onClose={() => setDialog(null)}
                onIssuing={(reason) => {
                    issuedReason.current = reason;
                }}
                teams={teams}
                roles={roles}
                statuses={statuses}
                employment_types={employment_types}
                currentUserId={props.auth?.user.id ?? 0}
                canManageTeams={canManageTeams}
            />

            <CredentialsDialog credentials={credentials} reason={issuedReason.current} onClose={closeCredentials} />
        </AppShell>
    );
}

function PageLink({ href, direction }: { href: string | null; direction: 'previous' | 'next' }) {
    const t = useT();
    const label = t(`people.pagination.${direction}`);
    const icon = direction === 'previous' ? <CaretLeft weight="bold" size={16} aria-hidden /> : <CaretRight weight="bold" size={16} aria-hidden />;

    // A missing neighbour page keeps its slot so the page counter stays centered, but is not focusable.
    if (!href) return <span aria-hidden className="invisible min-w-11 sm:min-w-[140px]" />;

    // On a phone the arrows carry the action and the label stays for screen readers, so the row fits 390px.
    return (
        <Link href={href} preserveState className="btn btn-secondary min-w-11 px-3 sm:min-w-[140px]" rel={direction === 'previous' ? 'prev' : 'next'}>
            {direction === 'previous' && icon}
            <span className="sr-only sm:not-sr-only">{label}</span>
            {direction === 'next' && icon}
        </Link>
    );
}
