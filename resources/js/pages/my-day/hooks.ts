import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { EyeState } from '@/components/owl/OwlEyes';

/** Current time, refreshed every `intervalMs` while `active`. */
export function useNow(intervalMs = 1000, active = true): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!active) return;
        setNow(Date.now());
        const id = window.setInterval(() => setNow(Date.now()), intervalMs);
        return () => window.clearInterval(id);
    }, [intervalMs, active]);

    return now;
}

/**
 * Heartbeat while this browser's shift is open (docs/02 3.11). It keeps running in a hidden tab: an open tab is an
 * open page. Each heartbeat also refreshes the summary, so the page sees the 8-hour mark and the presence check.
 * When the tab shows again (a phone unlocked) one is sent at once, so an interruption shows without waiting.
 * A failed heartbeat never opens an error dialog; it returns the time of the failure so the page can say so.
 */
export function useWebHeartbeat(active: boolean, seconds: number): string | null {
    const [failedAt, setFailedAt] = useState<string | null>(null);

    useEffect(() => {
        if (!active) {
            setFailedAt(null);
            return;
        }

        const fail = () => {
            setFailedAt(new Date().toISOString());
            return false;
        };
        // A refusal (web clock-in turned off, session ended) shows its effect on the page instead of a dialog
        const refused = () => {
            fail();
            router.reload({ only: ['summary', 'week'], onHttpException: () => false, onNetworkError: () => false });
            return false;
        };
        let lastBeat = Date.now();
        const beat = () => {
            lastBeat = Date.now();
            router.post(
                route('web-clock.heartbeat'),
                {},
                {
                    only: ['summary', 'week'],
                    preserveScroll: true,
                    preserveState: true,
                    preserveErrors: true,
                    async: true,
                    showProgress: false,
                    onSuccess: () => setFailedAt(null),
                    onHttpException: refused,
                    onNetworkError: fail,
                },
            );
        };
        // Switching back and forth between tabs sends at most one extra heartbeat per half interval
        const onVisibility = () => {
            if (!document.hidden && Date.now() - lastBeat > (seconds * 1000) / 2) beat();
        };

        // One heartbeat right away: someone who reopens the page 3 minutes after closing it is back before the
        // interruption limit, and should not wait another full interval for the server to know it
        beat();
        const id = window.setInterval(beat, seconds * 1000);
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            window.clearInterval(id);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [active, seconds]);

    return failedAt;
}

export type ReminderPermission = NotificationPermission | 'unsupported';

export function useNotificationPermission(): [ReminderPermission, () => void] {
    const supported = typeof window !== 'undefined' && 'Notification' in window;
    const [permission, setPermission] = useState<ReminderPermission>(supported ? Notification.permission : 'unsupported');

    const request = () => {
        if (!supported) return;
        void Notification.requestPermission().then(setPermission);
    };

    return [permission, request];
}

/**
 * Browser notification for a question waiting for an answer, repeated every `repeatMinutes` while the page is open.
 * Only when the person turned reminders on.
 */
export function useRepeatingReminder(key: string | null, title: string, body: string, repeatMinutes: number, permission: ReminderPermission): void {
    const last = useRef<{ key: string; at: number } | null>(null);

    useEffect(() => {
        if (key === null || permission !== 'granted') return;

        const notify = () => {
            const previous = last.current;
            if (previous && previous.key === key && Date.now() - previous.at < repeatMinutes * 60_000) return;
            last.current = { key, at: Date.now() };
            try {
                new Notification(title, { body, tag: `owlorix-${key}` });
            } catch {
                // Some mobile browsers only allow notifications from a service worker; the card on the page still shows
            }
        };

        notify();
        const id = window.setInterval(notify, 30_000);
        return () => window.clearInterval(id);
    }, [key, title, body, repeatMinutes, permission]);
}

/**
 * Eyes motion from DESIGN.md: eyes open over 300 ms on clock-in, the gold ring pulses twice when an answer is needed.
 * Skipped for prefers-reduced-motion.
 */
export function useEyesMotion(state: EyeState) {
    const ref = useRef<HTMLSpanElement>(null);
    const previous = useRef<EyeState>(state);

    useEffect(() => {
        const el = ref.current;
        const before = previous.current;
        previous.current = state;
        if (!el || before === state || typeof el.animate !== 'function') return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        if (state === 'open' && before === 'closed') {
            el.animate([{ transform: 'scaleY(0.2)' }, { transform: 'scaleY(1)' }], { duration: 300, easing: 'ease-out' });
        } else if (state === 'attention') {
            el.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.08)' }, { transform: 'scale(1)' }], { duration: 450, iterations: 2, easing: 'ease-in-out' });
        }
    }, [state]);

    return ref;
}

/** Studio wall-clock value for <input type="datetime-local"> (Asia/Jakarta). */
export function toStudioInput(iso: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Jakarta',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).formatToParts(new Date(iso));
    const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';
    const hour = get('hour') === '24' ? '00' : get('hour');
    return `${get('year')}-${get('month')}-${get('day')}T${hour}:${get('minute')}`;
}
