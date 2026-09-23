import { OwlEyes } from '@/components/owl/OwlEyes';
import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import { useState } from 'react';
import { BalancePanel } from './BalancePanel';
import { CancelDialog } from './CancelDialog';
import { RequestCard } from './RequestCard';
import { RequestForm } from './RequestForm';
import type { LeaveItem, MyLeaveProps } from './types';
import { useVisitState } from './useVisitState';

/**
 * Cuti. Focal point: annual leave left this year. The form sits next to it, because asking is what people come
 * for; their requests follow, newest first, each with its decision and note.
 */
export default function LeaveIndex({ balance, types, requests, list_limit, limits }: MyLeaveProps) {
    const t = useT();
    const [cancelling, setCancelling] = useState<LeaveItem | null>(null);
    const visit = useVisitState(new URL(route('leave.mine'), window.location.origin).pathname);

    return (
        <AppShell title={t('leave.title')}>
            <div className="flex flex-col gap-6">
                <header className="flex flex-col gap-1.5">
                    <h1 className="h1">{t('leave.title')}</h1>
                    <p className="m-0 max-w-[72ch] text-muted">{t('leave.lead')}</p>
                </header>

                <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                    <div className="flex min-w-0 flex-col gap-6">
                        {balance && <BalancePanel balance={balance} />}
                        <RequestForm types={types} limits={limits} />
                    </div>

                    <section aria-labelledby="leave-list-title" className="flex min-w-0 flex-col gap-3">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                            <h2 id="leave-list-title" className="h2">
                                {t('leave.list.title')}
                            </h2>
                            {requests.length >= list_limit && <p className="m-0 text-sm text-muted">{t('leave.list.limit', { count: list_limit })}</p>}
                        </div>

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

                        {requests.length === 0 ? (
                            <div className="card flex flex-col items-start gap-4 px-5 py-8 sm:flex-row sm:items-center sm:gap-6 sm:px-8">
                                <OwlEyes state="closed" size={72} />
                                <div className="min-w-0">
                                    <h3 className="h2">{t('leave.list.empty_title')}</h3>
                                    <p className="m-0 mt-1.5 max-w-[56ch] text-muted">{t('leave.list.empty_body')}</p>
                                </div>
                            </div>
                        ) : (
                            <ul className={`m-0 flex list-none flex-col gap-4 p-0 transition-opacity ${visit.state === 'loading' ? 'opacity-60' : ''}`} aria-busy={visit.state === 'loading'}>
                                {requests.map((item) => (
                                    <li key={item.id}>
                                        <RequestCard item={item} onCancel={setCancelling} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>

            <CancelDialog item={cancelling} onClose={() => setCancelling(null)} />
        </AppShell>
    );
}
