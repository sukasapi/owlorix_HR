import { useT } from '@/lib/i18n';
import { useState } from 'react';

/**
 * Keeps a failed save inside the form that sent it. Returning false from the handlers stops Inertia
 * from replacing the page with the raw error response.
 */
export function useRequestError() {
    const t = useT();
    const [error, setError] = useState<string | null>(null);

    const handlers = {
        onStart: () => setError(null),
        onHttpException: (response: { status: number }) => {
            if (response.status === 403) setError(t('calendar.errors.forbidden'));
            else if (response.status === 404) setError(t('calendar.errors.not_found'));
            else setError(t('calendar.errors.request_failed', { status: response.status }));
            return false;
        },
        onNetworkError: () => {
            setError(t('calendar.errors.network'));
            return false;
        },
    };

    return { error, clear: () => setError(null), handlers };
}
