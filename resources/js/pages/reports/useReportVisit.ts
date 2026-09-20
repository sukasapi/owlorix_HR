import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type ReportVisit = { state: 'idle' } | { state: 'loading' | 'error'; href: string };

/**
 * Follows GET visits to Laporan (month, team, person) so the page shows what is loading and a retry
 * instead of a frozen table or Inertia's default error modal.
 */
export function useReportVisit() {
    const [visit, setVisit] = useState<ReportVisit>({ state: 'idle' });
    const active = useRef(false);

    useEffect(() => {
        const pathname = new URL(route('reports.index'), window.location.origin).pathname;
        const isReportVisit = (v: { method: string; url: URL; prefetch?: boolean }) => v.method === 'get' && v.url.pathname === pathname && !v.prefetch;

        const offStart = router.on('start', (event) => {
            if (!isReportVisit(event.detail.visit)) return;
            active.current = true;
            setVisit({ state: 'loading', href: event.detail.visit.url.href });
        });
        const offFinish = router.on('finish', (event) => {
            if (!isReportVisit(event.detail.visit)) return;
            active.current = false;
            setVisit((current) => (current.state === 'loading' ? { state: 'idle' } : current));
        });
        const fail = (event: Event) => {
            if (!active.current) return;
            event.preventDefault();
            setVisit((current) => (current.state === 'idle' ? current : { state: 'error', href: current.href }));
        };
        const offHttp = router.on('httpException', fail);
        const offNetwork = router.on('networkError', fail);

        return () => {
            offStart();
            offFinish();
            offHttp();
            offNetwork();
        };
    }, []);

    const retry = useCallback(() => {
        if (visit.state === 'idle') return;
        router.get(visit.href, {}, { preserveScroll: true });
    }, [visit]);

    return { visit, retry };
}
