import { useEffect, useState } from 'react';

export type Preview =
    | { state: 'idle' }
    | { state: 'loading' }
    | { state: 'failed' }
    | { state: 'ready'; days: number | null; error: string | null; remaining: number | null };

/**
 * Asks the server how many workdays a range counts for the signed-in person (holidays and opened days come from
 * the Calendar), a moment after the dates stop changing. Read-only; the request itself is counted again on send.
 */
export function usePreview(start: string, end: string, typeId: string): Preview {
    const [preview, setPreview] = useState<Preview>({ state: 'idle' });

    useEffect(() => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(start) || !/^\d{4}-\d{2}-\d{2}$/.test(end)) {
            setPreview({ state: 'idle' });
            return;
        }

        const controller = new AbortController();
        setPreview({ state: 'loading' });

        const timer = window.setTimeout(async () => {
            try {
                const params: Record<string, string> = { start_date: start, end_date: end };
                if (typeId) params.leave_type_id = typeId;

                const response = await fetch(route('leave.preview', params), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok) throw new Error(String(response.status));

                const data = (await response.json()) as { days: number | null; error: string | null; remaining?: number | null };
                setPreview({ state: 'ready', days: data.days, error: data.error, remaining: data.remaining ?? null });
            } catch (error) {
                if ((error as Error).name !== 'AbortError') setPreview({ state: 'failed' });
            }
        }, 300);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [start, end, typeId]);

    return preview;
}
