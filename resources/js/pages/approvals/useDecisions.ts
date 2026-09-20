import { useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import type { OvertimeItem } from './types';

export interface DecisionState {
    /** The request the last submit was for; errors belong to that request only. */
    id: number | null;
    processing: boolean;
    errors: Record<string, string>;
}

/**
 * Sends decisions with what the approver saw (status, minutes, latest decision), so the server can refuse a
 * decision on a request that changed meanwhile. Refusals and failed requests stay next to the request.
 */
export function useDecisions() {
    const t = useT();
    const [state, setState] = useState<DecisionState>({
        id: null,
        processing: false,
        errors: {},
    });

    const submit = (
        item: OvertimeItem,
        decision: 'approved' | 'rejected',
        note: string,
        callbacks: { onDone?: () => void; onRefused?: () => void } = {},
    ) => {
        const fail = (id: number, message: string) => {
            setState({ id, processing: false, errors: { overtime: message } });
            callbacks.onRefused?.();
        };

        router.post(
            route('approvals.decide', item.id),
            {
                decision,
                note: note.trim() === '' ? null : note,
                seen_status: item.status,
                seen_minutes: item.minutes,
                seen_decision_id: item.decision?.id ?? null,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setState({ id: item.id, processing: true, errors: {} }),
                onError: (errors) => {
                    setState({
                        id: item.id,
                        processing: false,
                        errors: errors as Record<string, string>,
                    });
                    callbacks.onRefused?.();
                },
                onHttpException: (response) => {
                    if (response.status === 419) fail(item.id, t('approvals.errors.expired'));
                    else if (response.status === 403) fail(item.id, t('approvals.errors.forbidden'));
                    else if (response.status === 404) fail(item.id, t('approvals.errors.not_found'));
                    else
                        fail(
                            item.id,
                            t('approvals.errors.request_failed', {
                                status: response.status,
                            }),
                        );
                    return false;
                },
                onNetworkError: () => {
                    fail(item.id, t('approvals.errors.network'));
                    return false;
                },
                onSuccess: () => {
                    setState({ id: null, processing: false, errors: {} });
                    callbacks.onDone?.();
                },
                onFinish: () => setState((current) => (current.processing ? { ...current, processing: false } : current)),
            },
        );
    };

    const clear = useCallback(() => setState({ id: null, processing: false, errors: {} }), []);

    return { state, submit, clear };
}
