import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import { type KeyboardEvent, useState } from 'react';
import type { AdminLeaveProps } from '../../leave/types';
import { QuotaTab } from './QuotaTab';
import { RequestsTab } from './RequestsTab';
import { TypesTab } from './TypesTab';

type Tab = 'requests' | 'quota' | 'types';
const TABS: Tab[] = ['requests', 'quota', 'types'];

/**
 * Admin cuti (Superadmin). Three jobs on one page, one tab each: requests (focal point: pending ones, oldest on
 * top), yearly quotas per person, and leave types. The tab survives filter visits and saves because every visit
 * keeps the page state.
 */
export default function LeaveAdmin(props: AdminLeaveProps) {
    const t = useT();
    const [tab, setTab] = useState<Tab>('requests');

    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        const next = TABS[(TABS.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : TABS.length - 1)) % TABS.length];
        setTab(next);
        requestAnimationFrame(() => document.getElementById(`leave-admin-tab-${next}`)?.focus());
    };

    return (
        <AppShell title={t('leave.admin.title')}>
            <div className="flex flex-col gap-5">
                <header className="flex flex-col gap-1.5">
                    <h1 className="h1">{t('leave.admin.title')}</h1>
                    <p className="m-0 max-w-[72ch] text-muted">{t('leave.admin.lead')}</p>
                </header>

                <div role="tablist" aria-label={t('leave.admin.tabs.label')} className="flex flex-wrap gap-2">
                    {TABS.map((key) => {
                        const active = key === tab;
                        return (
                            <button
                                key={key}
                                id={`leave-admin-tab-${key}`}
                                type="button"
                                role="tab"
                                aria-selected={active}
                                aria-controls={`leave-admin-panel-${key}`}
                                tabIndex={active ? 0 : -1}
                                onClick={() => setTab(key)}
                                onKeyDown={onKeyDown}
                                className={`btn ${active ? 'btn-primary' : 'btn-secondary'}`}
                            >
                                {t(`leave.admin.tabs.${key}`)}
                                {key === 'requests' && props.pending_count > 0 && (
                                    <span className="num inline-flex h-[22px] min-w-[22px] items-center justify-center rounded-full bg-gold px-1.5 text-[13px] font-bold text-[#1A1A2E]">
                                        {props.pending_count}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>

                <div role="tabpanel" id={`leave-admin-panel-${tab}`} aria-labelledby={`leave-admin-tab-${tab}`}>
                    {tab === 'requests' && <RequestsTab filters={props.filters} requests={props.requests} people={props.people} quotaYear={props.quota.year} thisYear={props.quota.this_year} />}
                    {tab === 'quota' && <QuotaTab quota={props.quota} filters={props.filters} />}
                    {tab === 'types' && <TypesTab types={props.types} />}
                </div>
            </div>
        </AppShell>
    );
}
