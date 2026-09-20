import { OwlEyes } from '@/components/owl/OwlEyes';
import { SelectField } from '@/components/ui/Field';
import AppShell from '@/layouts/AppShell';
import { useLocale, useT } from '@/lib/i18n';
import { Link, router } from '@inertiajs/react';
import { ArrowCounterClockwise, CaretLeft, CaretRight, CloudSlash } from '@phosphor-icons/react';
import { useEffect, useState } from 'react';
import { durationWords, monthLabel } from '../history/dates';
import { LateClaims } from './LateClaims';
import { RequestCard } from './RequestCard';
import type { Filters, OvertimePageProps } from './types';
import { useListVisit } from './useListVisit';

/** Only chosen filters go into the query string, so the plain page stays /lembur. */
function toQuery(filters: Filters): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.status !== 'all') query.status = filters.status;
    if (filters.bulan) query.bulan = filters.bulan;
    return query;
}

/**
 * Lembur. Late claims that are still possible come first, because they have a deadline. Then the person's requests,
 * newest work date first, filtered by status and month.
 */
export default function OvertimeIndex({ filters, months, has_requests, requests, late_claims, web_clock_in_enabled: webClock, rules }: OvertimePageProps) {
    const t = useT();
    const locale = useLocale();
    const list = useListVisit(new URL(route('overtime.mine'), window.location.origin).pathname);
    // The selects show the choice right away; the server's filters take over once the list arrives
    const [chosen, setChosen] = useState<Filters>(filters);
    useEffect(() => setChosen(filters), [filters]);

    const apply = (next: Filters) => {
        setChosen(next);
        router.get(route('overtime.mine'), toQuery(next), { preserveState: true, preserveScroll: true, replace: true });
    };

    const filtered = filters.status !== 'all' || filters.bulan !== null;
    const loading = list.state === 'loading';

    return (
        <AppShell title={t('overtime.title')}>
            <div className="flex flex-col gap-6">
                <header className="flex flex-col gap-1.5">
                    <h1 className="h1">{t('overtime.title')}</h1>
                    <p className="m-0 max-w-[72ch] text-muted">{t('overtime.lead')}</p>
                </header>

                {late_claims.length > 0 && <LateClaims claims={late_claims} rules={rules} webClock={webClock} />}

                {has_requests && (
                    <form role="search" aria-label={t('overtime.filters.label')} className="grid gap-3 min-[480px]:grid-cols-2 sm:flex sm:flex-wrap sm:items-end" onSubmit={(e) => e.preventDefault()}>
                        <SelectField
                            label={t('overtime.filters.status')}
                            className="sm:w-[240px]"
                            value={chosen.status}
                            onChange={(e) => apply({ ...chosen, status: e.target.value as Filters['status'] })}
                        >
                            <option value="all">{t('overtime.filters.status_all')}</option>
                            {(['pending', 'approved', 'rejected'] as const).map((status) => (
                                <option key={status} value={status}>
                                    {t(`overtime.status.${status}`)}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label={t('overtime.filters.month')}
                            className="sm:w-[240px]"
                            value={chosen.bulan ?? 'all'}
                            onChange={(e) => apply({ ...chosen, bulan: e.target.value === 'all' ? null : e.target.value })}
                        >
                            <option value="all">{t('overtime.filters.month_all')}</option>
                            {months.map((month) => (
                                <option key={month} value={month}>
                                    {monthLabel(month, locale)}
                                </option>
                            ))}
                        </SelectField>
                    </form>
                )}

                <div className="flex flex-col gap-3">
                    {has_requests && (
                        <div aria-live="polite" className="flex min-h-[22px] flex-wrap justify-between gap-x-4 gap-y-1 text-sm text-muted">
                            <span className="num">
                                {loading
                                    ? t('overtime.states.loading')
                                    : requests.total > 0 && requests.from !== null && requests.to !== null
                                      ? t('overtime.count', { from: requests.from, to: requests.to, total: requests.total })
                                      : null}
                            </span>
                            <span>{t('overtime.timezone_note')}</span>
                        </div>
                    )}

                    {list.state === 'error' ? (
                        <section className="card flex flex-col items-center gap-3 px-5 py-10 text-center" role="alert">
                            <CloudSlash weight="bold" size={40} className="text-danger" aria-hidden />
                            <h2 className="h2">{t('overtime.states.error_title')}</h2>
                            <p className="m-0 max-w-[52ch]">{t('overtime.states.error_body')}</p>
                            <button type="button" className="btn btn-primary" onClick={list.retry}>
                                <ArrowCounterClockwise weight="bold" size={18} aria-hidden />
                                {t('overtime.states.retry')}
                            </button>
                        </section>
                    ) : !has_requests ? (
                        <section className="card flex flex-col items-start gap-4 px-5 py-8 sm:flex-row sm:items-center sm:gap-6 sm:px-8">
                            <OwlEyes state="closed" size={72} />
                            <div className="min-w-0">
                                <h2 className="h2">{t('overtime.states.empty_title')}</h2>
                                <p className="m-0 mt-1.5 max-w-[64ch]">{t(webClock ? 'overtime.states.empty_body' : 'overtime.states.empty_body_desktop', { limit: durationWords(rules.regular_limit_minutes, locale) })}</p>
                            </div>
                        </section>
                    ) : requests.data.length === 0 ? (
                        <section className="card flex flex-col items-start gap-3 px-5 py-6">
                            <h2 className="h2">{t('overtime.states.filtered_title')}</h2>
                            <p className="m-0 max-w-[60ch] text-muted">{t('overtime.states.filtered_body')}</p>
                            {filtered && (
                                <button type="button" className="btn btn-secondary" onClick={() => apply({ status: 'all', bulan: null })}>
                                    {t('overtime.filters.clear')}
                                </button>
                            )}
                        </section>
                    ) : (
                        <ul className={`m-0 flex list-none flex-col gap-4 p-0 transition-opacity ${loading ? 'opacity-60' : ''}`} aria-busy={loading}>
                            {requests.data.map((item) => (
                                <li key={item.id}>
                                    <RequestCard item={item} webClock={webClock} />
                                </li>
                            ))}
                        </ul>
                    )}

                    {requests.last_page > 1 && list.state !== 'error' && (
                        <nav aria-label={t('overtime.pagination.label')} className="mt-2 flex items-center justify-between gap-3">
                            <PageLink href={requests.prev_page_url} direction="previous" />
                            <span className="num text-sm text-muted">{t('overtime.pagination.page', { current: requests.current_page, last: requests.last_page })}</span>
                            <PageLink href={requests.next_page_url} direction="next" />
                        </nav>
                    )}
                </div>
            </div>
        </AppShell>
    );
}

function PageLink({ href, direction }: { href: string | null; direction: 'previous' | 'next' }) {
    const t = useT();
    const label = t(`overtime.pagination.${direction}`);
    const icon = direction === 'previous' ? <CaretLeft weight="bold" size={16} aria-hidden /> : <CaretRight weight="bold" size={16} aria-hidden />;

    // A missing neighbour page keeps its slot so the page counter stays centered, but is not focusable.
    if (!href) return <span aria-hidden className="invisible min-w-11 sm:min-w-[140px]" />;

    // On a phone the arrow carries the action and the label stays for screen readers, so the row fits 390px.
    return (
        <Link href={href} preserveState className="btn btn-secondary min-w-11 px-3 sm:min-w-[140px]" rel={direction === 'previous' ? 'prev' : 'next'}>
            {direction === 'previous' && icon}
            <span className="sr-only sm:not-sr-only">{label}</span>
            {direction === 'next' && icon}
        </Link>
    );
}
