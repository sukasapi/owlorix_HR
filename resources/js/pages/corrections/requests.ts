import type { Translate } from '@/lib/i18n';
import { http } from '@inertiajs/react';

export type JsonResult<T> = { ok: true; data: T } | { ok: false; errors: Record<string, string> | null; message: string };

/**
 * JSON calls of the correction form (shift list, preview). Inertia's HTTP client sends the XSRF token. A 422 comes
 * back as field errors; anything else as one message for the whole panel.
 */
export async function requestJson<T>(t: Translate, method: 'get' | 'post', url: string, data: Record<string, unknown>, signal?: AbortSignal): Promise<JsonResult<T>> {
    try {
        const response = await http.getClient().request({
            method,
            url,
            data: method === 'post' ? data : undefined,
            params: method === 'get' ? data : undefined,
            headers: { Accept: 'application/json' },
            signal,
        });

        return { ok: true, data: JSON.parse(response.data) as T };
    } catch (error) {
        const response = (error as { response?: { status: number; data: string } }).response;

        if (!response) {
            if ((error as { name?: string }).name === 'HttpCancelledError') throw error;
            return { ok: false, errors: null, message: t('corrections.errors.network') };
        }

        if (response.status === 422) {
            const body = safeParse(response.data);
            const errors = Object.fromEntries(Object.entries(body?.errors ?? {}).map(([key, list]) => [key, Array.isArray(list) ? String(list[0]) : String(list)]));
            return { ok: false, errors, message: Object.values(errors)[0] ?? t('corrections.errors.request_failed', { status: 422 }) };
        }

        const message =
            response.status === 419
                ? t('corrections.errors.expired')
                : response.status === 403
                  ? (safeParse(response.data)?.message ?? t('corrections.errors.forbidden'))
                  : t('corrections.errors.request_failed', { status: response.status });

        return { ok: false, errors: null, message };
    }
}

function safeParse(text: string): { errors?: Record<string, unknown>; message?: string } | null {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}

/** Date (Y-m-d) and clock time (HH:mm) of an ISO moment in the studio zone. */
export function studioParts(iso: string): { date: string; time: string } {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Jakarta',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(iso));
    const get = (type: string) => parts.find((part) => part.type === type)?.value ?? '';

    return { date: `${get('year')}-${get('month')}-${get('day')}`, time: `${get('hour')}:${get('minute')}` };
}

/** The Y-m-d date after a Y-m-d date. */
export function nextDate(date: string): string {
    const [y, m, d] = date.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d + 1)).toISOString().slice(0, 10);
}
