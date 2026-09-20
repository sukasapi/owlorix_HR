import { OwlEyes } from '@/components/owl/OwlEyes';
import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowCounterClockwise, CaretLeft, CaretRight, MagnifyingGlass } from '@phosphor-icons/react';
import { type KeyboardEvent, useEffect, useId, useRef, useState } from 'react';
import { useListVisit } from '../people/useListVisit';
import { DeviceDialog, type DeviceDialogMode } from './DeviceDialog';
import { DeviceList } from './DeviceList';
import type { DeviceFilters, DeviceKind, DeviceRow, DevicesPageProps } from './types';

const KINDS: DeviceKind[] = ['desktop', 'browser'];

/** Only non-default filters go into the query string, so the plain page URL stays /admin/perangkat. */
function toQuery(filters: DeviceFilters): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.kind !== 'desktop') query.jenis = filters.kind;
    if (filters.q.trim() !== '') query.q = filters.q.trim();
    if (filters.status !== 'all') query.status = filters.status;
    return query;
}

export default function DevicesIndex() {
    const { props } = usePage<SharedProps & DevicesPageProps>();
    const { devices, filters, counts, revocations } = props;
    const t = useT();
    const searchId = useId();
    const statusId = useId();
    const [query, setQuery] = useState(filters.q);
    const [dialog, setDialog] = useState<DeviceDialogMode | null>(null);
    const [done, setDone] = useState<string | null>(null);
    const list = useListVisit(new URL(route('admin.devices.index'), window.location.origin).pathname);
    const debounce = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(debounce.current), []);

    const apply = (next: DeviceFilters) => {
        window.clearTimeout(debounce.current);
        setDone(null);
        router.get(route('admin.devices.index'), toQuery(next), { preserveState: true, preserveScroll: true, replace: true });
    };

    const onSearch = (value: string) => {
        setQuery(value);
        window.clearTimeout(debounce.current);
        debounce.current = window.setTimeout(() => apply({ ...filters, q: value }), 350);
    };

    // The dialog reads the device from fresh props, so a shift that started after the page loaded shows up in it
    const current = dialog ? { ...dialog, device: devices.data.find((d) => d.id === dialog.device.id) ?? dialog.device } : null;
    const filtered = filters.q !== '' || filters.status !== 'all';

    const onDone = (kind: 'revoke' | 'restore', device: DeviceRow) => {
        setDialog(null);
        setDone(t(kind === 'revoke' ? 'devices.done.revoked' : 'devices.done.restored', { name: device.hostname }));
    };

    return (
        <AppShell title={t('devices.title')}>
            <div className="flex flex-col gap-1">
                <h1 className="h1">{t('devices.title')}</h1>
                <p className="m-0 max-w-[70ch] text-muted">{t('devices.intro')}</p>
            </div>

            <div className="mt-[18px] flex flex-col gap-3">
                <KindTabs kind={filters.kind} counts={counts} onChange={(kind) => apply({ ...filters, q: query, kind })} />

                <form
                    role="search"
                    className="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply({ ...filters, q: query });
                    }}
                >
                    <div className="relative sm:w-[340px]">
                        <label htmlFor={searchId} className="sr-only">
                            {t('devices.filters.search_label')}
                        </label>
                        <MagnifyingGlass weight="bold" size={17} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" aria-hidden />
                        <input
                            id={searchId}
                            type="search"
                            className="input pl-9"
                            placeholder={t(`devices.filters.search_placeholder_${filters.kind}`)}
                            autoComplete="off"
                            spellCheck={false}
                            value={query}
                            onChange={(e) => onSearch(e.target.value)}
                        />
                    </div>
                    <label htmlFor={statusId} className="sr-only">
                        {t('devices.filters.status_label')}
                    </label>
                    <select
                        id={statusId}
                        className="input min-w-0 font-semibold sm:w-auto"
                        value={filters.status}
                        onChange={(e) => apply({ ...filters, q: query, status: e.target.value as DeviceFilters['status'] })}
                    >
                        {(['all', 'active', 'revoked'] as const).map((status) => (
                            <option key={status} value={status}>
                                {t(`devices.filters.status.${status}`)}
                            </option>
                        ))}
                    </select>
                </form>
            </div>

            <div aria-live="polite" className="mt-3.5 mb-2.5 flex min-h-[22px] flex-col gap-2.5 text-sm text-muted">
                {done && <Notice tone="success">{done}</Notice>}
                <span>
                    {list.state === 'loading'
                        ? t('devices.states.loading')
                        : devices.total > 0 && devices.from !== null && devices.to !== null
                          ? t('devices.count', { from: devices.from, to: devices.to, total: devices.total })
                          : null}
                </span>
            </div>

            {list.state === 'error' ? (
                <section className="card flex flex-col items-start gap-3 px-5 py-6" role="alert">
                    <h2 className="h2">{t('devices.states.error_title')}</h2>
                    <p className="m-0 text-muted">{t('devices.states.error_body')}</p>
                    <button type="button" className="btn btn-secondary" onClick={list.retry}>
                        <ArrowCounterClockwise weight="bold" size={18} aria-hidden />
                        {t('common.actions.retry')}
                    </button>
                </section>
            ) : devices.data.length === 0 ? (
                <section className="card flex flex-col items-start gap-3 px-5 py-6">
                    {!filtered && <OwlEyes state="closed" size={56} />}
                    <h2 className="h2">{filtered ? t('devices.states.filtered_title') : t(`devices.states.empty_${filters.kind}_title`)}</h2>
                    <p className="m-0 max-w-[60ch] text-muted">{filtered ? t('devices.states.filtered_body') : t(`devices.states.empty_${filters.kind}_body`)}</p>
                    {filtered && (
                        <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => {
                                setQuery('');
                                apply({ kind: filters.kind, q: '', status: 'all' });
                            }}
                        >
                            {t('devices.filters.clear')}
                        </button>
                    )}
                </section>
            ) : (
                <div className={list.state === 'loading' ? 'opacity-60 transition-opacity' : 'transition-opacity'} aria-busy={list.state === 'loading'}>
                    <DeviceList
                        kind={filters.kind}
                        devices={devices.data}
                        revocations={revocations}
                        thisBrowserId={props.this_browser_device_id}
                        onRevoke={(device) => {
                            setDone(null);
                            setDialog({ kind: 'revoke', device });
                        }}
                        onRestore={(device) => {
                            setDone(null);
                            setDialog({ kind: 'restore', device });
                        }}
                    />
                </div>
            )}

            {devices.last_page > 1 && list.state !== 'error' && (
                <nav aria-label={t('devices.pagination.label')} className="mt-4 flex items-center justify-between gap-3">
                    <PageLink href={devices.prev_page_url} direction="previous" />
                    <span className="num text-sm text-muted">{t('devices.pagination.page', { current: devices.current_page, last: devices.last_page })}</span>
                    <PageLink href={devices.next_page_url} direction="next" />
                </nav>
            )}

            <DeviceDialog mode={current} resumeWindowMinutes={props.resume_window_minutes} onClose={() => setDialog(null)} onDone={onDone} />
        </AppShell>
    );
}

function KindTabs({ kind, counts, onChange }: { kind: DeviceKind; counts: Record<DeviceKind, number>; onChange: (kind: DeviceKind) => void }) {
    const t = useT();

    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        const next = KINDS[(KINDS.indexOf(kind) + 1) % KINDS.length];
        onChange(next);
        requestAnimationFrame(() => document.getElementById(`devices-tab-${next}`)?.focus());
    };

    return (
        <div role="tablist" aria-label={t('devices.tabs.label')} className="flex flex-wrap gap-2">
            {KINDS.map((key) => {
                const active = key === kind;
                return (
                    <button
                        key={key}
                        id={`devices-tab-${key}`}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        tabIndex={active ? 0 : -1}
                        onClick={() => !active && onChange(key)}
                        onKeyDown={onKeyDown}
                        className={`btn ${active ? 'btn-primary' : 'btn-secondary'}`}
                    >
                        {t(`devices.tabs.${key}`)}
                        <span className="num font-normal opacity-90">{counts[key]}</span>
                    </button>
                );
            })}
        </div>
    );
}

function PageLink({ href, direction }: { href: string | null; direction: 'previous' | 'next' }) {
    const t = useT();
    const label = t(`devices.pagination.${direction}`);
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
