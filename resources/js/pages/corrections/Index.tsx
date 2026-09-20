import { OwlEyes } from '@/components/owl/OwlEyes';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { NotePencil, Plus } from '@phosphor-icons/react';
import { type KeyboardEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { CorrectionDetail } from './CorrectionDetail';
import { CorrectionDialog } from './CorrectionDialog';
import { CorrectionRow } from './CorrectionList';
import type { CorrectionItem, CorrectionsPageProps, HistoryFilter, Tab } from './types';

const isDesktop = () => typeof window !== 'undefined' && window.matchMedia('(min-width: 1024px)').matches;

/** Query keys match the server: `orang`, `status` (history only). */
function toQuery(tab: Tab, person: number | null, status: HistoryFilter): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (person !== null) query.orang = person;
    if (tab === 'history' && status !== 'all') query.status = status;
    return query;
}

/**
 * Koreksi (3.10). Focal point: the oldest proposal waiting for Superadmin. On a phone the list and detail are two
 * views; from `lg` they sit side by side.
 */
export default function CorrectionsIndex({ abilities, filters, waiting, history, history_limit, people, today }: CorrectionsPageProps) {
    const t = useT();

    const [tab, setTab] = useState<Tab>(() => (filters.status !== 'all' ? 'history' : 'waiting'));
    const [personId, setPersonId] = useState<number | 'all'>(filters.person ?? 'all');
    const [status, setStatus] = useState<HistoryFilter>(filters.status);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [dialogOpen, setDialogOpen] = useState(false);

    const listHeadingRef = useRef<HTMLHeadingElement>(null);
    const detailHeadingRef = useRef<HTMLHeadingElement>(null);

    const personFilter = personId === 'all' ? null : personId;
    const visible = tab === 'waiting' ? waiting : history;
    const current = visible.find((item) => item.id === selectedId) ?? visible[0] ?? null;

    const canCreate = abilities.apply || abilities.propose;

    useEffect(() => {
        if (mobileOpen && !isDesktop()) {
            window.scrollTo({ top: 0 });
            detailHeadingRef.current?.focus();
        }
    }, [mobileOpen, current?.id]);

    const visit = (nextTab: Tab, nextPerson: number | null, nextStatus: HistoryFilter) => {
        router.get(route('corrections.index'), toQuery(nextTab, nextPerson, nextStatus), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => {
                setSelectedId(null);
                setMobileOpen(false);
            },
        });
    };

    const open = (item: CorrectionItem) => {
        setSelectedId(item.id);
        setMobileOpen(true);
    };

    const back = () => {
        const id = current?.id;
        setMobileOpen(false);
        requestAnimationFrame(() => document.getElementById(`correction-row-${id}`)?.focus());
    };

    const onDecided = () => {
        router.reload({ only: ['waiting', 'history', 'filters'] });
        if (isDesktop()) {
            requestAnimationFrame(() => detailHeadingRef.current?.focus());
        } else {
            setMobileOpen(false);
            window.scrollTo({ top: 0 });
            requestAnimationFrame(() => listHeadingRef.current?.focus());
        }
    };

    const onSent = () => {
        setDialogOpen(false);
        router.reload({ only: ['waiting', 'history'] });
    };

    const intro =
        tab === 'waiting'
            ? abilities.apply
                ? t('corrections.intro.waiting_apply')
                : t('corrections.intro.waiting_propose')
            : `${t('corrections.intro.history')}${history.length >= history_limit ? ` ${t('corrections.intro.history_limit', { count: history_limit })}` : ''}`;

    const filtered = personFilter !== null || (tab === 'history' && status !== 'all');

    const empty = (() => {
        if (visible.length > 0) return null;
        if (filtered) {
            return (
                <EmptyState title={t('corrections.states.filtered_title')} body={t('corrections.states.filtered_body')}>
                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => {
                            setPersonId('all');
                            setStatus('all');
                            visit(tab, null, 'all');
                        }}
                    >
                        {t('corrections.filters.clear')}
                    </button>
                </EmptyState>
            );
        }
        if (tab === 'waiting') {
            return (
                <EmptyState
                    title={t('corrections.states.waiting_empty_title')}
                    body={abilities.apply ? t('corrections.states.waiting_empty_apply') : t('corrections.states.waiting_empty_propose')}
                >
                    {canCreate && (
                        <button type="button" className="btn btn-primary" onClick={() => setDialogOpen(true)}>
                            <Plus weight="bold" size={18} aria-hidden />
                            {t(abilities.apply ? 'corrections.new.apply' : 'corrections.new.propose')}
                        </button>
                    )}
                </EmptyState>
            );
        }
        return <EmptyState title={t('corrections.states.history_empty_title')} body={t('corrections.states.history_empty_body')} />;
    })();

    return (
        <AppShell title={t('corrections.title')}>
            <div className="flex flex-col gap-4">
                <header className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
                        <h1 ref={listHeadingRef} tabIndex={-1} className="h1 lg:text-[40px]">
                            {t('corrections.title')}
                        </h1>
                        <Tabs
                            tab={tab}
                            waitingCount={waiting.length}
                            onChange={(next) => {
                                setTab(next);
                                visit(next, personFilter, status);
                            }}
                        />
                    </div>
                    {canCreate && (
                        <button type="button" className="btn btn-primary w-full sm:w-auto" onClick={() => setDialogOpen(true)}>
                            <NotePencil weight="bold" size={18} aria-hidden />
                            {t(abilities.apply ? 'corrections.new.apply' : 'corrections.new.propose')}
                        </button>
                    )}
                </header>

                <div className={`flex flex-wrap items-end gap-x-4 gap-y-3 ${mobileOpen ? 'hidden lg:flex' : ''}`}>
                    <p className="m-0 min-w-0 flex-1 text-sm text-muted">{intro}</p>
                    {(people.length > 1 || tab === 'history') && (
                        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            {people.length > 1 && (
                                <label className="flex min-w-0 flex-1 flex-col gap-1 sm:min-w-[200px]">
                                    <span className="text-sm font-semibold">{t('corrections.filters.person')}</span>
                                    <select
                                        className="input font-semibold"
                                        value={personId === 'all' ? 'all' : personId}
                                        onChange={(e) => {
                                            const next = e.target.value === 'all' ? 'all' : Number(e.target.value);
                                            setPersonId(next);
                                            visit(tab, next === 'all' ? null : next, status);
                                        }}
                                    >
                                        <option value="all">{t('corrections.filters.person_all')}</option>
                                        {people.map((person) => (
                                            <option key={person.id} value={person.id}>
                                                {person.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            {tab === 'history' && (
                                <label className="flex min-w-0 flex-1 flex-col gap-1 sm:min-w-[180px]">
                                    <span className="text-sm font-semibold">{t('corrections.filters.status')}</span>
                                    <select
                                        className="input font-semibold"
                                        value={status}
                                        onChange={(e) => {
                                            const next = e.target.value as HistoryFilter;
                                            setStatus(next);
                                            visit(tab, personFilter, next);
                                        }}
                                    >
                                        <option value="all">{t('corrections.filters.status_all')}</option>
                                        <option value="applied">{t('corrections.filters.status_applied')}</option>
                                        <option value="declined">{t('corrections.filters.status_declined')}</option>
                                    </select>
                                </label>
                            )}
                        </div>
                    )}
                </div>

                {empty ?? (
                    <div className="grid items-start gap-5 lg:grid-cols-[minmax(340px,420px)_1fr]">
                        <section className={`flex flex-col ${mobileOpen ? 'hidden lg:flex' : ''}`} aria-label={t(`corrections.tabs.${tab}`)}>
                            <div className="lg:overflow-hidden lg:rounded-md lg:border lg:border-line lg:bg-surface">
                                <ul className="m-0 flex list-none flex-col gap-3 p-0 lg:gap-0">{visible.map((item) => (
                                    <CorrectionRow key={item.id} item={item} selected={current?.id === item.id} onOpen={() => open(item)} />
                                ))}</ul>
                            </div>
                        </section>

                        <section
                            className={`card px-4 py-5 sm:px-6 lg:sticky lg:top-[88px] lg:max-h-[calc(100dvh-112px)] lg:overflow-y-auto ${mobileOpen ? '' : 'hidden lg:block'}`}
                        >
                            {current ? (
                                <CorrectionDetail item={current} onBack={back} onDecided={onDecided} headingRef={detailHeadingRef} />
                            ) : (
                                <p className="m-0 text-muted">{t('corrections.detail.nothing_selected')}</p>
                            )}
                        </section>
                    </div>
                )}
            </div>

            {canCreate && (
                <CorrectionDialog open={dialogOpen} onClose={() => setDialogOpen(false)} apply={abilities.apply} people={people} today={today} onSent={onSent} />
            )}
        </AppShell>
    );
}

function Tabs({ tab, waitingCount, onChange }: { tab: Tab; waitingCount: number; onChange: (tab: Tab) => void }) {
    const t = useT();
    const tabs: Tab[] = ['waiting', 'history'];

    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        const next = tabs[(tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        onChange(next);
        requestAnimationFrame(() => document.getElementById(`corrections-tab-${next}`)?.focus());
    };

    return (
        <div role="tablist" aria-label={t('corrections.tabs.label')} className="flex gap-2">
            {tabs.map((key) => {
                const active = key === tab;
                return (
                    <button
                        key={key}
                        id={`corrections-tab-${key}`}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(key)}
                        onKeyDown={onKeyDown}
                        className={`btn ${active ? 'btn-primary' : 'btn-secondary'}`}
                    >
                        {t(`corrections.tabs.${key}`)}
                        {key === 'waiting' && waitingCount > 0 && (
                            <span className="num inline-flex h-[22px] min-w-[22px] items-center justify-center rounded-full bg-gold px-1.5 text-[13px] font-bold text-[#1A1A2E]">
                                {waitingCount}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

function EmptyState({ title, body, children }: { title: string; body: string; children?: ReactNode }) {
    return (
        <section className="card flex flex-col items-center gap-3 px-6 py-12 text-center">
            <OwlEyes state="closed" size={96} />
            <h2 className="h2 mt-2">{title}</h2>
            <p className="m-0 max-w-[52ch] text-muted">{body}</p>
            {children}
        </section>
    );
}
