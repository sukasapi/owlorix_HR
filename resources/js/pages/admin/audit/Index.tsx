import { OwlEyes } from '@/components/owl/OwlEyes';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowCounterClockwise, CaretLeft, CaretRight } from '@phosphor-icons/react';
import { useId } from 'react';
import { useListVisit } from '../people/useListVisit';
import { EntryList } from './EntryList';
import { groupLabel } from './labels';
import type { AuditFilters, AuditPageProps } from './types';

const NO_FILTERS: AuditFilters = { group: '', actor: null, person: null, from: null, until: null };

function toQuery(filters: AuditFilters): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filters.group !== '') query.grup = filters.group;
    if (filters.actor !== null) query.pelaku = filters.actor;
    if (filters.person !== null) query.orang = filters.person;
    if (filters.from) query.dari = filters.from;
    if (filters.until) query.sampai = filters.until;
    return query;
}

const idOrNull = (value: string) => (value === '' ? null : Number(value));

export default function AuditIndex() {
    const { props } = usePage<SharedProps & AuditPageProps>();
    const { entries, filters, options, names } = props;
    const t = useT();
    const ids = { group: useId(), actor: useId(), person: useId(), from: useId(), until: useId() };
    const list = useListVisit(new URL(route('admin.audit.index'), window.location.origin).pathname);

    const apply = (next: AuditFilters) => router.get(route('admin.audit.index'), toQuery(next), { preserveState: true, preserveScroll: true, replace: true });
    const filtered = filters.group !== '' || filters.actor !== null || filters.person !== null || filters.from !== null || filters.until !== null;

    return (
        <AppShell title={t('audit.title')}>
            <div className="flex flex-col gap-1">
                <h1 className="h1">{t('audit.title')}</h1>
                <p className="m-0 max-w-[70ch] text-muted">{t('audit.intro')}</p>
            </div>

            <form role="search" aria-label={t('audit.filters.label')} className="mt-[18px] grid gap-3 min-[520px]:grid-cols-2 lg:grid-cols-[repeat(3,minmax(0,1fr))_minmax(0,170px)_minmax(0,170px)]" onSubmit={(e) => e.preventDefault()}>
                <div className="field min-w-0">
                    <label htmlFor={ids.group} className="label">
                        {t('audit.filters.group')}
                    </label>
                    <select id={ids.group} className="input" value={filters.group} onChange={(e) => apply({ ...filters, group: e.target.value })}>
                        <option value="">{t('audit.filters.group_all')}</option>
                        {options.groups.map((group) => (
                            <option key={group} value={group}>
                                {groupLabel(t, group)}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="field min-w-0">
                    <label htmlFor={ids.actor} className="label">
                        {t('audit.filters.actor')}
                    </label>
                    <select id={ids.actor} className="input" value={filters.actor ?? ''} onChange={(e) => apply({ ...filters, actor: idOrNull(e.target.value) })}>
                        <option value="">{t('audit.filters.actor_all')}</option>
                        {options.actors.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name} ({person.username})
                            </option>
                        ))}
                    </select>
                </div>
                <div className="field min-w-0">
                    <label htmlFor={ids.person} className="label">
                        {t('audit.filters.person')}
                    </label>
                    <select id={ids.person} className="input" value={filters.person ?? ''} onChange={(e) => apply({ ...filters, person: idOrNull(e.target.value) })}>
                        <option value="">{t('audit.filters.person_all')}</option>
                        {options.people.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name} ({person.username})
                            </option>
                        ))}
                    </select>
                </div>
                <div className="field min-w-0">
                    <label htmlFor={ids.from} className="label">
                        {t('audit.filters.from')}
                    </label>
                    <input id={ids.from} type="date" className="input num" value={filters.from ?? ''} max={filters.until ?? undefined} onChange={(e) => apply({ ...filters, from: e.target.value || null })} />
                </div>
                <div className="field min-w-0">
                    <label htmlFor={ids.until} className="label">
                        {t('audit.filters.until')}
                    </label>
                    <input id={ids.until} type="date" className="input num" value={filters.until ?? ''} min={filters.from ?? undefined} onChange={(e) => apply({ ...filters, until: e.target.value || null })} />
                </div>
            </form>

            <div aria-live="polite" className="mt-3.5 mb-2.5 flex min-h-11 flex-wrap items-center justify-between gap-2 text-sm text-muted">
                <span>
                    {list.state === 'loading'
                        ? t('audit.states.loading')
                        : entries.total > 0 && entries.from !== null && entries.to !== null
                          ? t('audit.count', { from: entries.from, to: entries.to, total: entries.total })
                          : null}
                </span>
                {filtered && entries.data.length > 0 && (
                    <button type="button" className="btn btn-quiet min-h-11" onClick={() => apply(NO_FILTERS)}>
                        {t('audit.filters.clear')}
                    </button>
                )}
            </div>

            {list.state === 'error' ? (
                <section className="card flex flex-col items-start gap-3 px-5 py-6" role="alert">
                    <h2 className="h2">{t('audit.states.error_title')}</h2>
                    <p className="m-0 text-muted">{t('audit.states.error_body')}</p>
                    <button type="button" className="btn btn-secondary" onClick={list.retry}>
                        <ArrowCounterClockwise weight="bold" size={18} aria-hidden />
                        {t('common.actions.retry')}
                    </button>
                </section>
            ) : entries.data.length === 0 ? (
                <section className="card flex flex-col items-start gap-3 px-5 py-6">
                    {!filtered && <OwlEyes state="closed" size={56} />}
                    <h2 className="h2">{filtered ? t('audit.states.filtered_title') : t('audit.states.empty_title')}</h2>
                    <p className="m-0 max-w-[60ch] text-muted">{filtered ? t('audit.states.filtered_body') : t('audit.states.empty_body')}</p>
                    {filtered && (
                        <button type="button" className="btn btn-secondary" onClick={() => apply(NO_FILTERS)}>
                            {t('audit.filters.clear')}
                        </button>
                    )}
                </section>
            ) : (
                <div className={list.state === 'loading' ? 'opacity-60 transition-opacity' : 'transition-opacity'} aria-busy={list.state === 'loading'}>
                    <EntryList key={entries.current_page} entries={entries.data} names={names} />
                </div>
            )}

            {entries.last_page > 1 && list.state !== 'error' && (
                <nav aria-label={t('audit.pagination.label')} className="mt-4 flex items-center justify-between gap-3">
                    <PageLink href={entries.prev_page_url} direction="previous" />
                    <span className="num text-sm text-muted">{t('audit.pagination.page', { current: entries.current_page, last: entries.last_page })}</span>
                    <PageLink href={entries.next_page_url} direction="next" />
                </nav>
            )}
        </AppShell>
    );
}

function PageLink({ href, direction }: { href: string | null; direction: 'previous' | 'next' }) {
    const t = useT();
    const label = t(`audit.pagination.${direction}`);
    const icon = direction === 'previous' ? <CaretLeft weight="bold" size={16} aria-hidden /> : <CaretRight weight="bold" size={16} aria-hidden />;

    if (!href) return <span aria-hidden className="invisible min-w-11 sm:min-w-[140px]" />;

    return (
        <Link href={href} preserveState className="btn btn-secondary min-w-11 px-3 sm:min-w-[140px]" rel={direction === 'previous' ? 'prev' : 'next'}>
            {direction === 'previous' && icon}
            <span className="sr-only sm:not-sr-only">{label}</span>
            {direction === 'next' && icon}
        </Link>
    );
}
