import { OwlEyes } from '@/components/owl/OwlEyes';
import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextField } from '@/components/ui/Field';
import { isFilterMonth, useChosenFilters, useFilterInput } from '@/lib/filterInput';
import { useLocale, useT } from '@/lib/i18n';
import { Link, router } from '@inertiajs/react';
import { ArrowCounterClockwise, CaretLeft, CaretRight, CloudSlash, Paperclip, X } from '@phosphor-icons/react';
import { useEffect, useId, useState } from 'react';
import { BalanceLine } from '../../leave/BalanceLine';
import { CancelDialog } from '../../leave/CancelDialog';
import { DecisionForm } from '../../leave/DecisionForm';
import { dateRange, workdays } from '../../leave/format';
import { StatusChip } from '../../leave/StatusChip';
import type { AdminFilters, AdminLeaveProps, LeaveItem, LeaveStatus } from '../../leave/types';
import { useVisitState } from '../../leave/useVisitState';
import { adminQuery } from './query';

const STATUSES: LeaveStatus[] = ['pending', 'approved', 'rejected', 'cancelled'];

interface Props {
    filters: AdminFilters;
    requests: AdminLeaveProps['requests'];
    people: AdminLeaveProps['people'];
    quotaYear: number;
    thisYear: number;
}

/**
 * Every request, pending first. A calm table from lg up (ENERGY 1 inside tables), stacked rows below it, so a
 * phone never scrolls sideways. Decide and cancel open dialogs; Escape closes them.
 */
export function RequestsTab({ filters, requests, people, quotaYear, thisYear }: Props) {
    const t = useT();
    const locale = useLocale();
    const visit = useVisitState(new URL(route('admin.leave.index'), window.location.origin).pathname);
    const [chosen, setChosen] = useChosenFilters<AdminFilters>(filters);
    const [decidingId, setDecidingId] = useState<number | null>(null);
    const [cancelling, setCancelling] = useState<LeaveItem | null>(null);
    // Read from the current list: after a reload the dialog shows the request as it is now, and closes once it
    // can no longer be decided (someone else decided or cancelled it)
    const deciding = decidingId === null ? null : (requests.data.find((item) => item.id === decidingId && item.can_decide) ?? null);
    useEffect(() => {
        if (decidingId !== null && deciding === null) setDecidingId(null);
    }, [decidingId, deciding]);

    const apply = (next: AdminFilters) => {
        setChosen(next);
        router.get(route('admin.leave.index'), adminQuery(next, quotaYear, thisYear), { preserveState: true, preserveScroll: true, replace: true });
    };
    const month = useFilterInput(filters.bulan, isFilterMonth, (bulan) => apply({ ...chosen, bulan }));

    const filtered = filters.status !== 'all' || filters.orang !== null || filters.bulan !== null;
    const loading = visit.state === 'loading';

    return (
        <div className="flex flex-col gap-4">
            <form role="search" aria-label={t('leave.admin.filters.label')} className="grid gap-3 sm:grid-cols-3 lg:flex lg:flex-wrap lg:items-end" onSubmit={(e) => e.preventDefault()}>
                <SelectField label={t('leave.admin.filters.status')} className="lg:w-[200px]" value={chosen.status} onChange={(e) => apply({ ...chosen, status: e.target.value as AdminFilters['status'] })}>
                    <option value="all">{t('leave.admin.filters.status_all')}</option>
                    {STATUSES.map((status) => (
                        <option key={status} value={status}>
                            {t(`leave.status.${status}`)}
                        </option>
                    ))}
                </SelectField>
                <SelectField
                    label={t('leave.admin.filters.person')}
                    className="lg:w-[260px]"
                    value={chosen.orang ?? 'all'}
                    onChange={(e) => apply({ ...chosen, orang: e.target.value === 'all' ? null : Number(e.target.value) })}
                >
                    <option value="all">{t('leave.admin.filters.person_all')}</option>
                    {people.map((person) => (
                        <option key={person.id} value={person.id}>
                            {person.name}
                        </option>
                    ))}
                </SelectField>
                <TextField
                    label={t('leave.admin.filters.month')}
                    type="month"
                    className="lg:w-[200px]"
                    {...month}
                />
                {filtered && (
                    <div className="flex items-end">
                        <button type="button" className="btn btn-quiet min-h-11" onClick={() => apply({ status: 'all', orang: null, bulan: null })}>
                            {t('leave.admin.filters.clear')}
                        </button>
                    </div>
                )}
            </form>

            <p aria-live="polite" className="num m-0 min-h-[22px] text-sm text-muted">
                {loading
                    ? t('leave.admin.loading')
                    : requests.total > 0 && requests.from !== null && requests.to !== null
                      ? t('leave.admin.count', { from: requests.from, to: requests.to, total: requests.total })
                      : null}
            </p>

            {visit.state === 'error' ? (
                <section className="card flex flex-col items-center gap-3 px-5 py-10 text-center" role="alert">
                    <CloudSlash weight="bold" size={40} className="text-danger" aria-hidden />
                    <h2 className="h2">{t('leave.admin.error_title')}</h2>
                    <p className="m-0 max-w-[52ch]">{t('leave.admin.error_body')}</p>
                    <button type="button" className="btn btn-primary" onClick={visit.retry}>
                        <ArrowCounterClockwise weight="bold" size={18} aria-hidden />
                        {t('leave.admin.retry')}
                    </button>
                </section>
            ) : requests.data.length === 0 ? (
                <section className="card flex flex-col items-start gap-3 px-5 py-6">
                    {!filtered && <OwlEyes state="closed" size={56} />}
                    <h2 className="h2">{filtered ? t('leave.admin.filtered_title') : t('leave.admin.empty_title')}</h2>
                    <p className="m-0 max-w-[60ch] text-muted">{filtered ? t('leave.admin.filtered_body') : t('leave.admin.empty_body')}</p>
                </section>
            ) : (
                <div className={`transition-opacity ${loading ? 'opacity-60' : ''}`} aria-busy={loading}>
                    <div className="table-wrap hidden lg:block">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th scope="col">{t('leave.admin.columns.person')}</th>
                                    <th scope="col">{t('leave.admin.columns.type')}</th>
                                    <th scope="col">{t('leave.admin.columns.dates')}</th>
                                    <th scope="col">{t('leave.admin.columns.status')}</th>
                                    <th scope="col">
                                        <span className="sr-only">{t('leave.admin.columns.actions')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.data.map((item) => (
                                    <tr key={item.id} className="align-top">
                                        <td className="min-w-[160px] font-semibold">{item.person?.name}</td>
                                        <td className="min-w-[140px]">
                                            {item.type?.name}
                                            <Extras item={item} />
                                        </td>
                                        <td className="num min-w-[200px]">
                                            {dateRange(item.start_date, item.end_date, locale, t)}
                                            <span className="block text-[13px] text-muted">{workdays(item.days, t)}</span>
                                        </td>
                                        <td className="max-w-[260px]">
                                            <StatusChip status={item.status} />
                                            <Outcome item={item} />
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            <Actions item={item} onDecide={(i) => setDecidingId(i.id)} onCancel={setCancelling} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="card m-0 list-none divide-y divide-line p-0 lg:hidden">
                        {requests.data.map((item) => (
                            <li key={item.id} className="flex flex-col gap-2 px-4 py-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <p className="m-0 font-semibold break-words">{item.person?.name}</p>
                                    <StatusChip status={item.status} />
                                </div>
                                <p className="num m-0 text-sm">
                                    <span className="font-semibold">{item.type?.name}</span>
                                    <span className="block">
                                        {dateRange(item.start_date, item.end_date, locale, t)}, {workdays(item.days, t)}
                                    </span>
                                </p>
                                <Extras item={item} />
                                <Outcome item={item} />
                                <div className="flex flex-wrap gap-2">
                                    <Actions item={item} onDecide={(i) => setDecidingId(i.id)} onCancel={setCancelling} />
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {requests.last_page > 1 && visit.state !== 'error' && (
                <nav aria-label={t('leave.admin.pagination.label')} className="mt-2 flex items-center justify-between gap-3">
                    <PageLink href={requests.prev_page_url} direction="previous" />
                    <span className="num text-sm text-muted">{t('leave.admin.pagination.page', { current: requests.current_page, last: requests.last_page })}</span>
                    <PageLink href={requests.next_page_url} direction="next" />
                </nav>
            )}

            <DecideDialog item={deciding} onClose={() => setDecidingId(null)} />
            <CancelDialog item={cancelling} onClose={() => setCancelling(null)} />
        </div>
    );
}

function Actions({ item, onDecide, onCancel }: { item: LeaveItem; onDecide: (item: LeaveItem) => void; onCancel: (item: LeaveItem) => void }) {
    const t = useT();
    const name = item.person?.name ?? '';

    return (
        <>
            {item.can_decide && (
                <button type="button" className="btn btn-primary btn-sm min-h-11" onClick={() => onDecide(item)} aria-label={t('leave.admin.decide_label', { name })}>
                    {t('leave.admin.decide')}
                </button>
            )}
            {item.can_cancel && (
                <button type="button" className="btn btn-secondary btn-sm ml-2 min-h-11 first:ml-0" onClick={() => onCancel(item)} aria-label={t('leave.admin.cancel_label', { name })}>
                    {t('leave.admin.cancel')}
                </button>
            )}
        </>
    );
}

function Extras({ item }: { item: LeaveItem }) {
    if (!item.reason && !item.attachment) return null;

    return (
        <span className="mt-1 flex flex-col gap-1 text-[13px]">
            {item.reason && <span className="line-clamp-3 [overflow-wrap:anywhere] text-muted">{item.reason}</span>}
            {item.attachment && (
                <a href={item.attachment.url} className="link inline-flex items-center gap-1 self-start font-semibold [overflow-wrap:anywhere]">
                    <Paperclip weight="bold" size={14} className="flex-none" aria-hidden />
                    {item.attachment.name}
                </a>
            )}
        </span>
    );
}

function Outcome({ item }: { item: LeaveItem }) {
    const t = useT();
    const by = item.cancellation?.by ?? item.decision?.by;
    const note = item.cancellation?.note ?? item.decision?.note;

    if (!by && !note) return null;

    return (
        <span className="mt-1 block text-[13px] text-muted">
            {by && t('leave.approvals.by', { name: by })}
            {note && <span className="block [overflow-wrap:anywhere] whitespace-pre-line text-ink">{note}</span>}
        </span>
    );
}

function DecideDialog({ item, onClose }: { item: LeaveItem | null; onClose: () => void }) {
    const t = useT();
    const locale = useLocale();
    const titleId = useId();

    return (
        <Dialog open={item !== null} onClose={onClose} labelledBy={titleId} width="max-w-[520px]" closeOnBackdrop={false}>
            {item && (
                <div>
                    <header className="flex items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                        <h2 id={titleId} className="h2">
                            {t('leave.admin.decide_title', { name: item.person?.name ?? '' })}
                        </h2>
                        <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('leave.actions.close')}>
                            <X weight="bold" size={18} aria-hidden />
                        </button>
                    </header>
                    <div className="flex flex-col gap-4 px-5 py-5 sm:px-6">
                        <p className="num m-0">
                            <span className="font-semibold">{item.type?.name}</span>
                            <span className="block">
                                {dateRange(item.start_date, item.end_date, locale, t)}, {workdays(item.days, t)}
                            </span>
                        </p>
                        {item.reason && <p className="m-0 [overflow-wrap:anywhere] whitespace-pre-line">{item.reason}</p>}
                        {item.type?.counts_against_quota && item.balance !== undefined && (
                            <div className="rounded-md bg-panel px-4 py-3 text-sm">
                                <BalanceLine balance={item.balance} />
                            </div>
                        )}
                        <DecisionForm key={item.id} item={item} onDone={onClose} reload={['requests', 'pending_count', 'quota']} />
                    </div>
                </div>
            )}
        </Dialog>
    );
}

function PageLink({ href, direction }: { href: string | null; direction: 'previous' | 'next' }) {
    const t = useT();
    const label = t(`leave.admin.pagination.${direction}`);
    const icon = direction === 'previous' ? <CaretLeft weight="bold" size={16} aria-hidden /> : <CaretRight weight="bold" size={16} aria-hidden />;

    // A missing neighbour page keeps its slot so the page counter stays centered, but is not focusable.
    if (!href) return <span aria-hidden className="invisible min-w-11 sm:min-w-[140px]" />;

    return (
        <Link href={href} preserveState preserveScroll className="btn btn-secondary min-w-11 px-3 sm:min-w-[140px]" rel={direction === 'previous' ? 'prev' : 'next'}>
            {direction === 'previous' && icon}
            <span className="sr-only sm:not-sr-only">{label}</span>
            {direction === 'next' && icon}
        </Link>
    );
}
