import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type VisitState = 'idle' | 'loading' | 'error';

/**
 * Tracks GET visits to one page (filters, pages, reloads) so the page shows a loading line and a retry panel
 * instead of a frozen list or the default error modal.
 */
export function useVisitState(pathname: string) {
    const [state, setState] = useState<VisitState>('idle');
    const active = useRef(false);
    const lastHref = useRef<string | null>(null);

    useEffect(() => {
        const isOurs = (visit: { method: string; url: URL; prefetch?: boolean }) => visit.method === 'get' && visit.url.pathname === pathname && !visit.prefetch;

        const offStart = router.on('start', (event) => {
            if (!isOurs(event.detail.visit)) return;
            active.current = true;
            lastHref.current = event.detail.visit.url.href;
            setState('loading');
        });
        const offFinish = router.on('finish', (event) => {
            if (!isOurs(event.detail.visit)) return;
            active.current = false;
            setState((current) => (current === 'loading' ? 'idle' : current));
        });
        const fail = (event: Event) => {
            if (!active.current) return;
            event.preventDefault();
            setState('error');
        };
        const offHttp = router.on('httpException', fail);
        const offNetwork = router.on('networkError', fail);

        return () => {
            offStart();
            offFinish();
            offHttp();
            offNetwork();
        };
    }, [pathname]);

    const retry = useCallback(() => {
        router.get(lastHref.current ?? window.location.href, {}, { preserveState: true, preserveScroll: true, replace: true });
    }, []);

    return { state, retry };
}
