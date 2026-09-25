import { OwlEyes } from '@/components/owl/OwlEyes';
import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { formatMinutes } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { Check } from '@phosphor-icons/react';
import { type KeyboardEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import { RequestDetail } from './RequestDetail';
import { RequestRow } from './RequestList';
import type { ApprovalsPageProps, BulkResult, OvertimeItem, Tab } from './types';
import { useDecisions } from './useDecisions';

const isDesktop = () => typeof window !== 'undefined' && window.matchMedia('(min-width: 1024px)').matches;

/**
 * Persetujuan. Focal point: the oldest pending request that can be decided now (DESIGN.md). On a phone the list and
 * the detail are two views; from `lg` they sit side by side.
 */
export default function Approvals({ abilities, pending, decided, decided_limit, teams, bulk_result }: ApprovalsPageProps) {
    const t = useT();
    const locale = useLocale();
    const decisions = useDecisions();

    const [tab, setTab] = useState<Tab>(abilities.approve ? 'pending' : 'decided');
    const [teamId, setTeamId] = useState<number | 'all'>('all');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [mobileOpen, setMobileOpen] = useState(false);
    const [startInReject, setStartInReject] = useState(false);
    const [checked, setChecked] = useState<Set<number>>(new Set());
    const [bulkBusy, setBulkBusy] = useState(false);
    const [bulkError, setBulkError] = useState<string | null>(null);

    const headingRef = useRef<HTMLHeadingElement>(null);
    const listHeadingRef = useRef<HTMLHeadingElement>(null);
    const resultRef = useRef<HTMLDivElement>(null);

    const inTeam = (item: OvertimeItem) => teamId === 'all' || item.team_ids.includes(teamId);
    const pendingVisible = pending.filter(inTeam);
    const decidedVisible = decided.filter(inTeam);
    const ready = pendingVisible.filter((item) => item.blocked === null);
    const blocked = pendingVisible.filter((item) => item.blocked !== null);
    const clean = ready.filter((item) => item.flags.length === 0);

    const visible = tab === 'pending' ? pendingVisible : decidedVisible;
    const current = visible.find((item) => item.id === selectedId) ?? (tab === 'pending' ? (ready[0] ?? blocked[0]) : decidedVisible[0]) ?? null;

    const selection = ready.filter((item) => checked.has(item.id));
    const selectionFlagged = selection.filter((item) => item.flags.length > 0).length;
    const selectionMinutes = selection.reduce((sum, item) => sum + item.minutes, 0);

    // On a phone the detail replaces the list: move focus and scroll to its heading.
    useEffect(() => {
        if (mobileOpen && !isDesktop()) {
            window.scrollTo({ top: 0 });
            headingRef.current?.focus();
        }
    }, [mobileOpen, current?.id]);

    useEffect(() => {
        if (bulk_result) {
            resultRef.current?.scrollIntoView({ block: 'nearest' });
            resultRef.current?.focus();
        }
    }, [bulk_result]);

    const open = (item: OvertimeItem, reject = false) => {
        setSelectedId(item.id);
        setStartInReject(reject);
        setMobileOpen(true);
        if (decisions.state.id !== item.id) decisions.clear();
    };

    const back = () => {
        const id = current?.id;
        setMobileOpen(false);
        setStartInReject(false);
        requestAnimationFrame(() => document.getElementById(`request-row-${id}`)?.focus());
    };

    const submit = (item: OvertimeItem, decision: 'approved' | 'rejected', note: string) => {
        decisions.submit(item, decision, note, {
            onDone: () => {
                setChecked((prev) => {
                    const next = new Set(prev);
                    next.delete(item.id);
                    return next;
                });
                setStartInReject(false);
                if (isDesktop()) {
                    requestAnimationFrame(() => headingRef.current?.focus());
                } else {
                    setMobileOpen(false);
                    window.scrollTo({ top: 0 });
                    requestAnimationFrame(() => listHeadingRef.current?.focus());
                }
            },
        });
    };

    // Approve straight from a phone card. A refusal opens the detail, where it is shown with the request.
    const quickApprove = (item: OvertimeItem) => {
        setSelectedId(item.id);
        setStartInReject(false);
        decisions.submit(item, 'approved', '', {
            onRefused: () => setMobileOpen(true),
        });
    };

    const bulkApprove = () => {
        router.post(
            route('approvals.bulk'),
            {
                items: selection.map((item) => ({
                    id: item.id,
                    seen_minutes: item.minutes,
                })),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setBulkBusy(true);
                    setBulkError(null);
                },
                onSuccess: () => setChecked(new Set()),
                onError: (errors) =>
                    setBulkError(
                        Object.values(errors)[0] ??
                            t('approvals.errors.request_failed', {
                                status: 422,
                            }),
                    ),
                onHttpException: (response) => {
                    setBulkError(
                        response.status === 419
                            ? t('approvals.errors.expired')
                            : t('approvals.errors.request_failed', {
                                  status: response.status,
                              }),
                    );
                    return false;
                },
                onNetworkError: () => {
                    setBulkError(t('approvals.errors.network'));
                    return false;
                },
                onFinish: () => setBulkBusy(false),
            },
        );
    };

    const intro = !abilities.approve
        ? t('approvals.intro.change_only')
        : tab === 'pending'
          ? t('approvals.intro.pending')
          : `${t('approvals.intro.decided')} ${decided.length >= decided_limit ? t('approvals.intro.decided_limit', { count: decided_limit }) : ''}`;

    const empty = (() => {
        if (visible.length > 0) return null;
        if (teamId !== 'all' && (tab === 'pending' ? pending : decided).length > 0) {
            return (
                <EmptyState title={t('approvals.empty.filtered_title')} body={t('approvals.empty.filtered_body')}>
                    <button type="button" className="btn btn-secondary" onClick={() => setTeamId('all')}>
                        {t('approvals.empty.show_all')}
                    </button>
                </EmptyState>
            );
        }
        return tab === 'pending' ? (
            <EmptyState title={t('approvals.empty.pending_title')} body={t('approvals.empty.pending_body')} />
        ) : (
            <EmptyState title={t('approvals.empty.decided_title')} body={t('approvals.empty.decided_body')} />
        );
    })();

    const row = (item: OvertimeItem) => {
        const quick = tab === 'pending' && item.blocked === null && item.flags.length === 0;
        return (
            <RequestRow
                key={item.id}
                item={item}
                selected={current?.id === item.id}
                checkable={tab === 'pending' && abilities.approve && item.blocked === null}
                checked={checked.has(item.id)}
                onCheck={(on) =>
                    setChecked((prev) => {
                        const next = new Set(prev);
                        if (on) next.add(item.id);
                        else next.delete(item.id);
                        return next;
                    })
                }
                onOpen={() => open(item)}
                onQuickApprove={quick ? () => quickApprove(item) : undefined}
                onQuickReject={quick ? () => open(item, true) : undefined}
                quickBusy={decisions.state.id === item.id && decisions.state.processing}
            />
        );
    };

    return (
        <AppShell title={t('approvals.title')}>
            <div className="flex flex-col gap-4">
                <header className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
                    <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
                        <h1 ref={listHeadingRef} tabIndex={-1} className="h1">
                            {t('approvals.title')}
                        </h1>
                        {abilities.approve && (
                            <Tabs
                                tab={tab}
                                pendingCount={pendingVisible.length}
                                onChange={(next) => {
                                    setTab(next);
                                    setSelectedId(null);
                                    setMobileOpen(false);
                                    decisions.clear();
                                }}
                            />
                        )}
                    </div>
                    {teams.length > 1 && (
                        <label className="flex w-full items-center gap-2 sm:w-auto">
                            <span className="text-sm font-semibold">{t('approvals.filter.team')}</span>
                            <select
                                className="input w-full sm:w-auto"
                                value={teamId}
                                onChange={(e) => {
                                    setTeamId(e.target.value === 'all' ? 'all' : Number(e.target.value));
                                    setSelectedId(null);
                                }}
                            >
                                <option value="all">{t('approvals.filter.all_teams')}</option>
                                {teams.map((team) => (
                                    <option key={team.id} value={team.id}>
                                        {team.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                </header>

                <div className={`flex flex-wrap items-center justify-between gap-x-4 gap-y-1 ${mobileOpen ? 'hidden lg:flex' : ''}`}>
                    <p className="m-0 text-sm text-muted">{intro}</p>
                    {tab === 'pending' && abilities.approve && clean.length > 0 && (
                        <button
                            type="button"
                            className="btn btn-quiet min-h-11 text-sm"
                            onClick={() => setChecked(new Set(clean.map((item) => item.id)))}
                        >
                            {t('approvals.select_clean', {
                                count: clean.length,
                            })}
                        </button>
                    )}
                </div>

                {bulk_result && (
                    <div ref={resultRef} tabIndex={-1} className={mobileOpen ? 'hidden lg:block' : ''}>
                        <BulkResultNotice result={bulk_result} />
                    </div>
                )}

                {empty ?? (
                    <div className="grid items-start gap-5 lg:grid-cols-[minmax(340px,420px)_1fr]">
                        <section className={`flex flex-col ${mobileOpen ? 'hidden lg:flex' : ''}`} aria-label={t(`approvals.tabs.${tab}`)}>
                            <div className="lg:overflow-hidden lg:rounded-md lg:border lg:border-line lg:bg-surface">
                                {tab === 'pending' ? (
                                    <>
                                        {ready.length > 0 && (
                                            <ListGroup title={blocked.length > 0 ? t('approvals.groups.ready') : null}>{ready.map(row)}</ListGroup>
                                        )}
                                        {blocked.length > 0 && (
                                            <ListGroup title={t('approvals.groups.blocked')} help={t('approvals.groups.blocked_help')}>
                                                {blocked.map(row)}
                                            </ListGroup>
                                        )}
                                    </>
                                ) : (
                                    <ListGroup title={null}>{decidedVisible.map(row)}</ListGroup>
                                )}
                            </div>

                            {selection.length > 0 && (
                                <div className="fixed inset-x-0 bottom-[calc(56px+env(safe-area-inset-bottom))] z-20 flex flex-col gap-2 bg-ink px-4 py-3 text-paper lg:sticky lg:inset-x-auto lg:bottom-4 lg:mt-3 lg:rounded-md">
                                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                                        <p className="num m-0 text-sm font-semibold">
                                            {t('approvals.bulk.selected', {
                                                count: selection.length,
                                                duration: formatMinutes(selectionMinutes, locale),
                                            })}
                                            {selectionFlagged > 0 && (
                                                <span className="block font-medium">
                                                    {t('approvals.bulk.flagged_hint', {
                                                        count: selectionFlagged,
                                                    })}
                                                </span>
                                            )}
                                        </p>
                                        <div className="flex items-center gap-2">
                                            <button type="button" className="btn bg-teal text-[#10121F]" onClick={bulkApprove} disabled={bulkBusy}>
                                                <Check weight="bold" size={18} aria-hidden />
                                                {bulkBusy
                                                    ? t('approvals.decide.sending')
                                                    : t('approvals.bulk.approve', {
                                                          count: selection.length,
                                                      })}
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-quiet text-paper"
                                                onClick={() => setChecked(new Set())}
                                                disabled={bulkBusy}
                                            >
                                                {t('approvals.bulk.clear')}
                                            </button>
                                        </div>
                                    </div>
                                    {bulkError && (
                                        <p className="m-0 text-sm font-semibold" role="alert">
                                            {bulkError}
                                        </p>
                                    )}
                                </div>
                            )}
                            {selection.length > 0 && <div className="h-28 lg:hidden" aria-hidden />}
                        </section>

                        <section
                            className={`card px-4 py-5 sm:px-6 lg:sticky lg:top-[88px] lg:max-h-[calc(100dvh-112px)] lg:overflow-y-auto ${mobileOpen ? '' : 'hidden lg:block'}`}
                        >
                            {current ? (
                                <RequestDetail
                                    item={current}
                                    decisions={decisions.state}
                                    onSubmit={submit}
                                    onBack={back}
                                    headingRef={headingRef}
                                    startInReject={startInReject && current.id === selectedId}
                                />
                            ) : (
                                <p className="m-0 text-muted">{t('approvals.detail.nothing_selected')}</p>
                            )}
                        </section>
                    </div>
                )}
            </div>
        </AppShell>
    );
}

function Tabs({ tab, pendingCount, onChange }: { tab: Tab; pendingCount: number; onChange: (tab: Tab) => void }) {
    const t = useT();
    const tabs: Tab[] = ['pending', 'decided'];

    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        const next = tabs[(tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        onChange(next);
        requestAnimationFrame(() => document.getElementById(`approvals-tab-${next}`)?.focus());
    };

    return (
        <div role="tablist" aria-label={t('approvals.tabs.label')} className="flex gap-2">
            {tabs.map((key) => {
                const active = key === tab;
                return (
                    <button
                        key={key}
                        id={`approvals-tab-${key}`}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(key)}
                        onKeyDown={onKeyDown}
                        className={`btn ${active ? 'btn-primary' : 'btn-secondary'}`}
                    >
                        {t(`approvals.tabs.${key}`)}
                        {key === 'pending' && pendingCount > 0 && (
                            <span className="num inline-flex h-[22px] min-w-[22px] items-center justify-center rounded-full bg-gold px-1.5 text-[13px] font-bold text-[#1A1A2E]">
                                {pendingCount}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

function ListGroup({ title, help, children }: { title: string | null; help?: string; children: ReactNode }) {
    return (
        <div className="flex flex-col">
            {title && (
                <div className="px-1 pt-4 pb-2 lg:border-b lg:border-line lg:bg-paper lg:px-4 lg:pt-3">
                    <h2 className="m-0 text-base font-semibold">{title}</h2>
                    {help && <p className="m-0 text-[13px] text-muted">{help}</p>}
                </div>
            )}
            <ul className="m-0 flex list-none flex-col gap-3 p-0 lg:gap-0">{children}</ul>
        </div>
    );
}

function BulkResultNotice({ result }: { result: BulkResult }) {
    const t = useT();
    const skipped = (Object.entries(result.skipped) as [keyof BulkResult['skipped'], number][]).filter(([, count]) => count > 0);

    return (
        <Notice tone={skipped.length > 0 ? 'info' : 'success'}>
            <p className="m-0 font-semibold">
                {result.approved > 0
                    ? t('approvals.bulk.result_approved', {
                          count: result.approved,
                      })
                    : t('approvals.bulk.result_none')}
            </p>
            {skipped.length > 0 && (
                <ul className="m-0 mt-1 list-disc pl-5 font-normal">
                    {skipped.map(([reason, count]) => (
                        <li key={reason}>{t(`approvals.bulk.skipped_${reason}`, { count })}</li>
                    ))}
                </ul>
            )}
        </Notice>
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
