import { useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

export interface ActionState {
    processing: boolean;
    /** Field errors from the server, plus `leave` for a refusal about the request itself */
    errors: Record<string, string>;
    /** A failure that is not about a field: network, expired session, server error */
    failure: string | null;
}

const idle: ActionState = { processing: false, errors: {}, failure: null };

/**
 * Sends one decision or cancellation and keeps its errors next to the request that caused them, instead of the
 * default error modal.
 */
export function useLeaveAction() {
    const t = useT();
    const [state, setState] = useState<ActionState>(idle);

    const send = (url: string, data: Record<string, string | null>, onDone?: () => void) => {
        router.post(url, data, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setState({ processing: true, errors: {}, failure: null }),
            onError: (errors) => setState({ processing: false, errors: errors as Record<string, string>, failure: null }),
            onHttpException: (response) => {
                const key = response.status === 419 ? 'expired' : response.status === 403 ? 'forbidden' : response.status === 404 ? 'not_found' : null;
                setState({ processing: false, errors: {}, failure: key ? t(`leave.errors.${key}`) : t('leave.errors.failed', { status: response.status }) });
                return false;
            },
            onNetworkError: () => {
                setState({ processing: false, errors: {}, failure: t('leave.errors.network') });
                return false;
            },
            onSuccess: () => {
                setState(idle);
                onDone?.();
            },
            onFinish: () => setState((current) => (current.processing ? { ...current, processing: false } : current)),
        });
    };

    const reset = useCallback(() => setState(idle), []);

    return { state, send, reset };
}
