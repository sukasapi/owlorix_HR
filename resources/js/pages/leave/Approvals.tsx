import { OwlEyes } from '@/components/owl/OwlEyes';
import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { HourglassMedium, Paperclip, UsersThree } from '@phosphor-icons/react';
import { useId } from 'react';
import { BalanceLine } from './BalanceLine';
import { DecisionForm } from './DecisionForm';
import { dateRange, shortDate, workdays } from './format';
import { StatusChip } from './StatusChip';
import type { ApprovalsProps, LeaveItem, PendingItem } from './types';
import { useVisitState } from './useVisitState';

const RELOAD = ['pending', 'recent'];

/**
 * Persetujuan cuti. Focal point: the request waiting longest (DESIGN.md), framed in gold because it waits for this
 * person's answer. Each request carries what a decision needs: dates, workdays, reason, annual leave left, and
 * teammates already away on those dates. Recent decisions follow as a calm table.
 */
export default function LeaveApprovals({ pending, recent, recent_limit }: ApprovalsProps) {
    const t = useT();
    const visit = useVisitState(new URL(route('leave.approvals'), window.location.origin).pathname);

    return (
        <AppShell title={t('leave.approvals.title')}>
            <div className="flex flex-col gap-6">
                <header className="flex flex-col gap-1.5">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <h1 className="h1 lg:text-[40px]">{t('leave.approvals.title')}</h1>
                        {pending.length > 0 && (
                            <span className="num inline-flex h-[26px] items-center rounded-full bg-gold px-2.5 text-[14px] font-bold text-[#1A1A2E]">
                                {t('leave.approvals.waiting_count', { count: pending.length })}
                            </span>
                        )}
                    </div>
                    <p className="m-0 max-w-[72ch] text-muted">{t('leave.approvals.lead')}</p>
                </header>

                {visit.state === 'error' && (
                    <Notice tone="danger">
                        <p className="m-0">{t('leave.list.error')}</p>
                        <button type="button" className="btn btn-secondary btn-sm mt-2 bg-surface" onClick={visit.retry}>
                            {t('leave.list.retry')}
                        </button>
                    </Notice>
                )}
                {visit.state === 'loading' && (
                    <p className="m-0 text-sm text-muted" role="status">
                        {t('leave.list.loading')}
                    </p>
                )}

                {pending.length === 0 ? (
                    <section className="card flex flex-col items-center gap-3 px-6 py-12 text-center">
                        <OwlEyes state="closed" size={96} />
                        <h2 className="h2 mt-2">{t('leave.approvals.empty_title')}</h2>
                        <p className="m-0 max-w-[52ch] text-muted">{t('leave.approvals.empty_body')}</p>
                    </section>
                ) : (
                    <ul className={`m-0 grid list-none gap-4 p-0 xl:grid-cols-2 ${visit.state === 'loading' ? 'opacity-60' : ''}`} aria-busy={visit.state === 'loading'}>
                        {pending.map((item, index) => (
                            <li key={item.id} className={index === 0 ? 'xl:col-span-2' : ''}>
                                <PendingCard item={item} oldest={index === 0} />
                            </li>
                        ))}
                    </ul>
                )}

                <RecentList items={recent} limit={recent_limit} />
            </div>
        </AppShell>
    );
}

function PendingCard({ item, oldest }: { item: PendingItem; oldest: boolean }) {
    const t = useT();
    const locale = useLocale();
    const headingId = useId();
    const person = item.person;

    return (
        <article aria-labelledby={headingId} className={`flex flex-col gap-4 rounded-md bg-surface px-5 py-5 sm:px-6 ${oldest ? 'border-2 border-gold' : 'border border-line'}`}>
            {oldest && (
                <p className="m-0 flex items-center gap-2 text-sm font-semibold">
                    <HourglassMedium weight="bold" size={18} aria-hidden />
                    {t('leave.approvals.oldest')}
                </p>
            )}
            <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="avatar" aria-hidden>
                        {person?.initials}
                    </span>
                    <div className="min-w-0">
                        <h2 id={headingId} className="m-0 text-[17px] font-semibold break-words text-heading">
                            {person?.name}
                        </h2>
                        {person && person.teams.length > 0 && <p className="m-0 text-sm text-muted">{person.teams.join(', ')}</p>}
                    </div>
                </div>
                <p className="num m-0 text-sm text-muted">{t('leave.item.sent', { time: formatDateTime(item.created_at, locale) })}</p>
            </header>

            <div className="flex flex-col gap-1">
                <p className="m-0 font-semibold">{item.type?.name}</p>
                <p className={`num m-0 ${oldest ? 'text-[20px] font-semibold text-heading' : ''}`}>
                    {dateRange(item.start_date, item.end_date, locale, t)}
                </p>
                <p className="num m-0 text-sm">{workdays(item.days, t)}</p>
            </div>

            {item.reason && (
                <div className="min-w-0">
                    <p className="m-0 text-[13px] font-semibold text-muted">{t('leave.item.reason')}</p>
                    <p className="m-0 mt-0.5 [overflow-wrap:anywhere] whitespace-pre-line">{item.reason}</p>
                </div>
            )}

            {item.attachment && (
                <a href={item.attachment.url} className="link inline-flex items-center gap-1.5 self-start text-sm font-semibold [overflow-wrap:anywhere]">
                    <Paperclip weight="bold" size={16} className="flex-none" aria-hidden />
                    {item.attachment.name}
                </a>
            )}

            {(item.balance !== undefined || item.also_off.length > 0) && (
                <div className="flex flex-col gap-2 rounded-md bg-panel px-4 py-3 text-sm">
                    {item.balance !== undefined && <BalanceLine balance={item.balance} />}
                    {item.also_off.length > 0 && (
                        <div>
                            <p className="m-0 flex items-center gap-1.5 font-semibold">
                                <UsersThree weight="bold" size={16} aria-hidden />
                                {t('leave.approvals.also_off')}
                            </p>
                            <ul className="num m-0 mt-1 list-disc pl-5">
                                {item.also_off.map((other, i) => (
                                    <li key={`${other.name}-${i}`}>
                                        {t('leave.approvals.also_off_item', {
                                            name: other.name,
                                            range:
                                                other.start_date === other.end_date
                                                    ? shortDate(other.start_date, locale)
                                                    : t('leave.range', { from: shortDate(other.start_date, locale), until: shortDate(other.end_date, locale) }),
                                        })}{' '}
                                        <span className="text-muted">({t(`leave.status.${other.status}`).toLowerCase()})</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}

            {item.can_decide && (
                <div className="border-t border-line pt-4">
                    <DecisionForm item={item} reload={RELOAD} />
                </div>
            )}
        </article>
    );
}

function RecentList({ items, limit }: { items: LeaveItem[]; limit: number }) {
    const t = useT();
    const locale = useLocale();

    return (
        <section aria-labelledby="leave-recent-title" className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id="leave-recent-title" className="h2">
                    {t('leave.approvals.recent_title')}
                </h2>
                {items.length > 0 && <p className="m-0 text-sm text-muted">{t('leave.approvals.recent_help', { count: limit })}</p>}
            </div>

            {items.length === 0 ? (
                <p className="m-0 text-muted">{t('leave.approvals.recent_empty')}</p>
            ) : (
                <>
                    <div className="table-wrap hidden md:block">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th scope="col">{t('leave.admin.columns.person')}</th>
                                    <th scope="col">{t('leave.admin.columns.type')}</th>
                                    <th scope="col">{t('leave.admin.columns.dates')}</th>
                                    <th scope="col">{t('leave.admin.columns.status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="font-semibold">{item.person?.name}</td>
                                        <td>{item.type?.name}</td>
                                        <td className="num">
                                            {dateRange(item.start_date, item.end_date, locale, t)}
                                            <span className="block text-[13px] text-muted">{workdays(item.days, t)}</span>
                                        </td>
                                        <td>
                                            <StatusChip status={item.status} />
                                            <ClosedBy item={item} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="card m-0 list-none divide-y divide-line p-0 md:hidden">
                        {items.map((item) => (
                            <li key={item.id} className="flex flex-col gap-1.5 px-4 py-3.5">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <p className="m-0 font-semibold break-words">{item.person?.name}</p>
                                    <StatusChip status={item.status} />
                                </div>
                                <p className="num m-0 text-sm">
                                    {item.type?.name}, {dateRange(item.start_date, item.end_date, locale, t)}
                                </p>
                                <ClosedBy item={item} />
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

function ClosedBy({ item }: { item: LeaveItem }) {
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
