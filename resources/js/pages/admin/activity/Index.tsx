import { OwlEyes } from '@/components/owl/OwlEyes';
import AppShell from '@/layouts/AppShell';
import { isFilterDate, useChosenFilters, useFilterInput } from '@/lib/filterInput';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowCounterClockwise, CaretLeft, CaretRight } from '@phosphor-icons/react';
import { useId } from 'react';
import { useListVisit } from '../people/useListVisit';
import { ActivityChart } from './ActivityChart';
import { AttentionPanel } from './AttentionPanel';
import { OnlinePanel } from './OnlinePanel';
import { TimelineList } from './TimelineList';
import type { ActivityFilters, ActivityPageProps, KindParam, PersonRef } from './types';

const NO_FILTERS: ActivityFilters = { person: null, kind: null, from: null, until: null };
const KIND_OPTIONS: { value: KindParam; kind: 'access' | 'change' | 'attendance' }[] = [
    { value: 'akses', kind: 'access' },
    { value: 'perubahan', kind: 'change' },
    { value: 'absensi', kind: 'attendance' },
];

/** Filter and page visits reload only the timeline; the panels above keep what the page load showed. */
const TIMELINE_ONLY = ['timeline', 'filters'];

function toQuery(filters: ActivityFilters): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filters.person !== null) query.orang = filters.person;
    if (filters.kind !== null) query.jenis = filters.kind;
    if (filters.from) query.dari = filters.from;
    if (filters.until) query.sampai = filters.until;
    return query;
}

// After Inertia has put the preserved scroll position back
const scrollToTimeline = () => window.setTimeout(() => document.getElementById('activity-timeline')?.scrollIntoView({ block: 'start' }), 0);

export default function ActivityIndex() {
    const { props } = usePage<SharedProps & ActivityPageProps>();
    const { attention, online, chart, timeline, filters, options, limits, links } = props;
    const t = useT();
    const ids = { person: useId(), kind: useId(), from: useId(), until: useId() };
    const list = useListVisit(new URL(route('admin.activity.index'), window.location.origin).pathname);

    const [chosen, setChosen] = useChosenFilters(filters);

    const apply = (next: ActivityFilters, onSuccess?: () => void) => {
        setChosen(next);
        router.get(route('admin.activity.index'), toQuery(next), { preserveState: true, preserveScroll: true, replace: true, only: TIMELINE_ONLY, onSuccess });
    };
    const from = useFilterInput(filters.from, isFilterDate, (value) => apply({ ...chosen, from: value }));
    const until = useFilterInput(filters.until, isFilterDate, (value) => apply({ ...chosen, until: value }));
    const filtered = filters.person !== null || filters.kind !== null || filters.from !== null || filters.until !== null;
    const showPerson = (person: PersonRef) => apply({ ...NO_FILTERS, person: person.id, kind: 'akses' }, scrollToTimeline);

    return (
        <AppShell title={t('activity-monitor.title')}>
            <div className="flex flex-col gap-1">
                <h1 className="h1">{t('activity-monitor.title')}</h1>
                <p className="m-0 max-w-[70ch] text-muted">{t('activity-monitor.intro')}</p>
                <p className="m-0 text-sm text-muted">
                    {t('activity-monitor.retention', { days: limits.retention_days })}{' '}
                    {links.settings && (
                        <Link href={route('admin.settings.edit')} className="link">
                            {t('activity-monitor.retention_link')}
                        </Link>
                    )}
                </p>
            </div>

            <AttentionPanel attention={attention} limits={limits} onShowPerson={showPerson} />

            <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                <OnlinePanel online={online} minutes={limits.online_minutes} />
                <ActivityChart days={chart} />
            </div>

            <section id="activity-timeline" className="mt-8 scroll-mt-20" aria-labelledby="activity-timeline-title">
                <h2 id="activity-timeline-title" className="h2">
                    {t('activity-monitor.timeline.title')}
                </h2>
                <p className="m-0 mt-1 text-muted">{t('activity-monitor.timeline.intro')}</p>

                <form
                    role="search"
                    aria-label={t('activity-monitor.filters.label')}
                    className="mt-4 grid gap-3 min-[520px]:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1.3fr)_minmax(0,170px)_minmax(0,170px)]"
                    onSubmit={(e) => e.preventDefault()}
                >
                    <div className="field min-w-0">
                        <label htmlFor={ids.person} className="label">
                            {t('activity-monitor.filters.person')}
                        </label>
                        <select
                            id={ids.person}
                            className="input"
                            value={chosen.person ?? ''}
                            onChange={(e) => apply({ ...chosen, person: e.target.value === '' ? null : Number(e.target.value) })}
                        >
                            <option value="">{t('activity-monitor.filters.person_all')}</option>
                            {options.people.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name} ({person.username})
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="field min-w-0">
                        <label htmlFor={ids.kind} className="label">
                            {t('activity-monitor.filters.kind')}
                        </label>
                        <select
                            id={ids.kind}
                            className="input"
                            value={chosen.kind ?? ''}
                            onChange={(e) => apply({ ...chosen, kind: e.target.value === '' ? null : (e.target.value as KindParam) })}
                        >
                            <option value="">{t('activity-monitor.filters.kind_all')}</option>
                            {KIND_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {t(`activity-monitor.kinds.${option.kind}`)}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="field min-w-0">
                        <label htmlFor={ids.from} className="label">
                            {t('activity-monitor.filters.from')}
                        </label>
                        <input
                            id={ids.from}
                            type="date"
                            className="input num"
                            max={chosen.until ?? undefined}
                            {...from}
                        />
                    </div>
                    <div className="field min-w-0">
                        <label htmlFor={ids.until} className="label">
                            {t('activity-monitor.filters.until')}
                        </label>
                        <input
                            id={ids.until}
                            type="date"
                            className="input num"
                            min={chosen.from ?? undefined}
                            {...until}
                        />
                    </div>
                </form>

                <div aria-live="polite" className="mt-3.5 mb-2.5 flex min-h-11 flex-wrap items-center justify-between gap-2 text-sm text-muted">
                    <span>
                        {list.state === 'loading'
                            ? t('activity-monitor.states.loading')
                            : timeline.total > 0 && timeline.from !== null && timeline.to !== null
                              ? t(timeline.total_capped ? 'activity-monitor.timeline.count_capped' : 'activity-monitor.timeline.count', {
                                    from: timeline.from,
                                    to: timeline.to,
                                    total: timeline.total,
                                })
                              : null}
                    </span>
                    {filtered && timeline.data.length > 0 && (
                        <button type="button" className="btn btn-quiet min-h-11" onClick={() => apply(NO_FILTERS)}>
                            {t('activity-monitor.filters.clear')}
                        </button>
                    )}
                </div>

                {list.state === 'error' ? (
                    <section className="card flex flex-col items-start gap-3 px-5 py-6" role="alert">
                        <h3 className="h2">{t('activity-monitor.states.error_title')}</h3>
                        <p className="m-0 text-muted">{t('activity-monitor.states.error_body')}</p>
                        <button type="button" className="btn btn-secondary" onClick={list.retry}>
                            <ArrowCounterClockwise weight="bold" size={18} aria-hidden />
                            {t('common.actions.retry')}
                        </button>
                    </section>
                ) : timeline.data.length === 0 ? (
                    <section className="card flex flex-col items-start gap-3 px-5 py-6">
                        {!filtered && <OwlEyes state="closed" size={56} />}
                        <h3 className="h2">{filtered ? t('activity-monitor.states.filtered_title') : t('activity-monitor.states.empty_title')}</h3>
                        <p className="m-0 max-w-[60ch] text-muted">{filtered ? t('activity-monitor.states.filtered_body') : t('activity-monitor.states.empty_body')}</p>
                        {filtered && (
                            <button type="button" className="btn btn-secondary" onClick={() => apply(NO_FILTERS)}>
                                {t('activity-monitor.filters.clear')}
                            </button>
                        )}
                    </section>
                ) : (
                    <div className={list.state === 'loading' ? 'opacity-60 transition-opacity' : 'transition-opacity'} aria-busy={list.state === 'loading'}>
                        <TimelineList key={timeline.current_page} rows={timeline.data} />
                    </div>
                )}

                {timeline.last_page > 1 && list.state !== 'error' && (
                    <nav aria-label={t('activity-monitor.pagination.label')} className="mt-4 flex items-center justify-between gap-3">
                        <PageLink href={timeline.prev_page_url} direction="previous" />
                        <span className="num text-sm text-muted">{t('activity-monitor.pagination.page', { current: timeline.current_page, last: timeline.last_page })}</span>
                        <PageLink href={timeline.next_page_url} direction="next" />
                    </nav>
                )}
                {timeline.current_page >= limits.max_page && timeline.to !== null && (timeline.total_capped || timeline.total > timeline.to) && (
                    <p className="m-0 mt-3 text-sm text-muted">{t('activity-monitor.timeline.capped', { page: limits.max_page })}</p>
                )}
            </section>
        </AppShell>
    );
}

function PageLink({ href, direction }: { href: string | null; direction: 'previous' | 'next' }) {
    const t = useT();
    const label = t(`activity-monitor.pagination.${direction}`);
    const icon = direction === 'previous' ? <CaretLeft weight="bold" size={16} aria-hidden /> : <CaretRight weight="bold" size={16} aria-hidden />;

    if (!href) return <span aria-hidden className="invisible min-w-11 sm:min-w-[140px]" />;

    return (
        <Link
            href={href}
            only={TIMELINE_ONLY}
            preserveState
            preserveScroll
            onSuccess={scrollToTimeline}
            className="btn btn-secondary min-w-11 px-3 sm:min-w-[140px]"
            rel={direction === 'previous' ? 'prev' : 'next'}
        >
            {direction === 'previous' && icon}
            <span className="sr-only sm:not-sr-only">{label}</span>
            {direction === 'next' && icon}
        </Link>
    );
}
