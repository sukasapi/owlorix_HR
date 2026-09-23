import { OwlEyes, type EyeState } from '@/components/owl/OwlEyes';
import { TextAreaField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { formatMinutes, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { ArrowsLeftRight, Bell, BellSlash, HourglassMedium, SignIn, SignOut } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useId, useRef, useState } from 'react';
import { type ReminderPermission, useEyesMotion, useNow } from './hooks';
import { errorText, type Summary } from './types';

const eyesFor: Record<Summary['status'], EyeState> = {
    open: 'open',
    overtime: 'open',
    prompted: 'attention',
    interrupted: 'half',
    signed_out: 'closed',
};

interface Props {
    summary: Summary;
    permission: ReminderPermission;
    onEnableReminders: () => void;
    heartbeatFailedAt: string | null;
}

/** Puts focus back on the button that opened a step once the step closes, so keyboard users keep their place. */
function useReturnFocus(stepOpen: boolean) {
    const trigger = useRef<HTMLButtonElement>(null);
    const wasOpen = useRef(stepOpen);

    useEffect(() => {
        if (wasOpen.current && !stepOpen) trigger.current?.focus();
        wasOpen.current = stepOpen;
    }, [stepOpen]);

    return trigger;
}

const visit = { preserveScroll: true, only: ['summary'] };

/**
 * The focal panel of Hari ini (DESIGN.md: live timer). Before clock-in its one action is "Absen masuk"; after clock-in
 * it shows the timer and the one action that fits the state: clock out, continue, or move the shift here.
 */
export function ClockPanel({ summary, permission, onEnableReminders, heartbeatFailedAt }: Props) {
    const t = useT();
    const locale = useLocale();
    const open = summary.open_shift;
    const mine = open?.on_this_browser ?? false;
    const hasShifts = summary.shifts.length > 0;
    const eyes = useEyesMotion(hasShifts || open ? eyesFor[summary.status] : 'closed');
    const time = (iso: string | null | undefined) => (iso ? formatTime(iso, locale) : '');
    const regularMinutes = useLiveRegularMinutes(summary);
    const progress = Math.min(100, Math.round((regularMinutes / Math.max(1, summary.regular_limit_minutes)) * 100));

    const heading = (() => {
        if (!open) return hasShifts ? t('my-day.status.signed_out') : t('my-day.not_clocked_in');
        if (!mine) return t('my-day.elsewhere.heading', { device: open.device_hostname ?? t('my-day.elsewhere.other_device') });
        return t(`my-day.web_status.${open.status}`);
    })();

    const detail = (() => {
        if (!open) {
            const last = summary.shifts.at(-1);
            return last?.clock_out_at ? t('my-day.detail.last_out', { time: time(last.clock_out_at) }) : null;
        }
        if (open.status === 'interrupted') return t('my-day.detail.interrupted_at', { time: time(open.last_seen_at) });
        return t(mine ? 'my-day.detail.since_here' : 'my-day.detail.since', { time: time(open.clock_in_at) });
    })();

    return (
        <section className="brow px-5 py-6 sm:px-8 sm:py-7" aria-labelledby="today-status">
            <div className="flex items-center gap-4">
                <span ref={eyes} className="inline-block origin-center">
                    <OwlEyes state={hasShifts || open ? eyesFor[summary.status] : 'closed'} size={72} />
                </span>
                <div className="min-w-0">
                    <h1 id="today-status" className="h2" aria-live="polite">
                        {heading}
                    </h1>
                    {detail && <p className="num m-0 text-muted">{detail}</p>}
                </div>
            </div>

            {(hasShifts || open) && (
                <>
                    <p className="display num m-0 mt-5 text-[52px] text-heading sm:text-[80px]">
                        {formatMinutes(regularMinutes, locale)}
                        <span className="ml-2 font-sans text-lg font-semibold tracking-normal text-muted sm:text-[22px]">{t('my-day.worked_today')}</span>
                    </p>
                    {summary.is_workday && (
                        <div
                            className="mt-3 h-3 overflow-hidden rounded-md border border-[color-mix(in_srgb,var(--eye-brow)_35%,transparent)] bg-[color-mix(in_srgb,var(--eye-brow)_18%,transparent)]"
                            role="progressbar"
                            aria-valuemin={0}
                            aria-valuemax={summary.regular_limit_minutes}
                            aria-valuenow={regularMinutes}
                            aria-label={t('my-day.worked_today')}
                        >
                            <span className="block h-full rounded-md bg-[var(--eye-brow)]" style={{ width: `${progress}%` }} />
                        </div>
                    )}
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                        <span className="num font-semibold">
                            {summary.is_workday &&
                                (regularMinutes >= summary.regular_limit_minutes
                                    ? t('my-day.regular_done')
                                    : summary.regular_ends_at && open
                                      ? t('my-day.regular_mark', { time: time(summary.regular_ends_at) })
                                      : null)}
                        </span>
                        {summary.overtime_minutes > 0 && (
                            <span className="chip chip-pending num">
                                <HourglassMedium weight="bold" size={15} aria-hidden />
                                {t('my-day.overtime_today', { duration: formatMinutes(summary.overtime_minutes, locale) })}
                            </span>
                        )}
                    </div>
                </>
            )}

            {!summary.web_clock_in_enabled ? (
                <p className="m-0 mt-5 max-w-[60ch] text-base">{t('my-day.how_to_clock_in')}</p>
            ) : !open ? (
                <ClockInAction summary={summary} />
            ) : !mine ? (
                <MoveHere hostname={open.device_hostname} status={open.status} />
            ) : (
                <>
                    {heartbeatFailedAt && open.status !== 'interrupted' && (
                        <Notice tone="danger" className="mt-5">
                            {t('my-day.web.heartbeat_failed', { time: time(heartbeatFailedAt), minutes: summary.rules.interrupted_after_minutes })}
                        </Notice>
                    )}
                    {summary.undo_until && <UndoBar until={summary.undo_until} />}
                    {open.status === 'interrupted' ? (
                        <ResumeAction until={open.resume_until} />
                    ) : open.status === 'prompted' ? (
                        <p className="m-0 mt-5 font-semibold">{t('my-day.web.answer_prompt')}</p>
                    ) : (
                        <ClockOutAction overtime={open.status === 'overtime'} reason={open.overtime?.reason ?? null} />
                    )}
                    {open.status === 'overtime' && summary.presence_check && !summary.presence_check.check_shown_at && (
                        <p className="num m-0 mt-3 text-sm">{t('my-day.presence.next', { time: time(summary.presence_check.next_check_at) })}</p>
                    )}
                    <WebNotes rules={summary.rules} permission={permission} onEnableReminders={onEnableReminders} />
                </>
            )}
        </section>
    );
}

/**
 * The server total plus the minutes since it arrived, while a shift is counting regular time (DESIGN.md: live
 * timer). The server total replaces it on every heartbeat, so the number never drifts; it stops at the day's limit
 * because time after the mark is overtime, which the server reports on its own.
 */
function useLiveRegularMinutes(summary: Summary): number {
    const counting = summary.open_shift?.status === 'open' && summary.is_workday;
    const now = useNow(30_000, counting);
    const [loadedAt, setLoadedAt] = useState(() => Date.now());

    useEffect(() => setLoadedAt(Date.now()), [summary]);

    if (!counting) return summary.regular_minutes;

    const extra = Math.max(0, Math.floor((now - loadedAt) / 60_000));
    return Math.min(summary.regular_limit_minutes, summary.regular_minutes + extra);
}

function ClockInAction({ summary }: { summary: Summary }) {
    const t = useT();
    const [askReason, setAskReason] = useState(false);
    const trigger = useReturnFocus(askReason);
    const form = useForm({ reason: '' });
    const clockIn = (event?: FormEvent) => {
        event?.preventDefault();
        form.post(route('web-clock.clock-in'), { ...visit, onSuccess: () => setAskReason(false) });
    };

    const clockError = errorText(t, (form.errors as Record<string, string | undefined>).clock, { device: summary.open_shift?.device_hostname ?? '' });

    if (!summary.is_workday && askReason) {
        const tooShort = form.data.reason.trim().length < summary.rules.reason_min_length;

        return (
            <form onSubmit={clockIn} className="mt-5 flex max-w-[520px] flex-col gap-3">
                <p className="m-0 font-semibold">{t('my-day.clock_in.non_workday')}</p>
                <TextAreaField
                    autoFocus
                    label={t('my-day.clock_in.reason_label')}
                    help={t('my-day.reason_help')}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    error={form.errors.reason}
                    maxLength={2000}
                    required
                />
                {clockError && <Notice tone="danger">{clockError}</Notice>}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <button type="submit" className="btn btn-primary" disabled={form.processing || tooShort}>
                        {form.processing ? t('my-day.saving') : t('my-day.clock_in.start_overtime')}
                    </button>
                    <button type="button" className="btn btn-quiet" onClick={() => setAskReason(false)}>
                        {t('my-day.back')}
                    </button>
                </div>
            </form>
        );
    }

    return (
        <div className="mt-5 flex flex-col gap-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                <button
                    ref={trigger}
                    type="button"
                    className="btn btn-primary min-h-[52px] px-7 text-[17px]"
                    disabled={form.processing}
                    onClick={() => (summary.is_workday ? clockIn() : setAskReason(true))}
                >
                    <SignIn weight="bold" size={20} aria-hidden />
                    {form.processing ? t('my-day.saving') : t('my-day.clock_in.button')}
                </button>
                <span className="text-sm text-muted">{summary.is_workday ? t('my-day.clock_in.hint') : t('my-day.clock_in.hint_non_workday')}</span>
            </div>
            {clockError && <Notice tone="danger">{clockError}</Notice>}
        </div>
    );
}

function UndoBar({ until }: { until: string }) {
    const t = useT();
    const now = useNow(1000);
    const form = useForm({});
    const left = Math.max(0, Math.ceil((new Date(until).getTime() - now) / 1000));

    if (left === 0) return null;

    const label = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;

    return (
        <div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-md border border-line-strong bg-surface px-4 py-3" role="status">
            <span className="text-sm font-semibold">{t('my-day.undo.text')}</span>
            <button type="button" className="btn btn-secondary btn-sm num min-h-[44px]" disabled={form.processing} onClick={() => form.post(route('web-clock.cancel'), visit)}>
                {t('my-day.undo.button', { time: label })}
            </button>
            {(form.errors as Record<string, string>).clock && <p className="error-text m-0 w-full">{errorText(t, (form.errors as Record<string, string>).clock)}</p>}
        </div>
    );
}

function MoveHere({ hostname, status }: { hostname: string | null; status: string }) {
    const t = useT();
    const [confirm, setConfirm] = useState(false);
    const trigger = useReturnFocus(confirm);
    const form = useForm({});
    const device = hostname ?? t('my-day.elsewhere.other_device');
    const questionId = useId();
    const questionRef = useRef<HTMLParagraphElement>(null);

    useEffect(() => {
        if (confirm) questionRef.current?.focus();
    }, [confirm]);

    return (
        <div className="mt-5 flex flex-col gap-3">
            <p className="m-0 text-sm">{status === 'prompted' ? t('my-day.elsewhere.prompted', { device }) : t('my-day.elsewhere.lead', { device })}</p>
            {confirm ? (
                <div role="group" aria-labelledby={questionId} className="flex flex-col gap-3 rounded-md border border-line-strong bg-surface px-4 py-4">
                    <p id={questionId} ref={questionRef} tabIndex={-1} className="m-0 font-semibold">
                        {t('my-day.elsewhere.confirm', { device })}
                    </p>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <button type="button" className="btn btn-primary" disabled={form.processing} onClick={() => form.post(route('web-clock.move'), visit)}>
                            {form.processing ? t('my-day.saving') : t('my-day.elsewhere.yes')}
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={() => setConfirm(false)}>
                            {t('my-day.elsewhere.no')}
                        </button>
                    </div>
                    {(form.errors as Record<string, string>).clock && <Notice tone="danger">{errorText(t, (form.errors as Record<string, string>).clock, { device })}</Notice>}
                </div>
            ) : (
                <button ref={trigger} type="button" className="btn btn-secondary self-start bg-surface" onClick={() => setConfirm(true)}>
                    <ArrowsLeftRight weight="bold" size={18} aria-hidden />
                    {t('my-day.elsewhere.move')}
                </button>
            )}
        </div>
    );
}

function ResumeAction({ until }: { until: string | null }) {
    const t = useT();
    const locale = useLocale();
    const form = useForm({});

    return (
        <div className="mt-5 flex flex-col gap-2">
            <button type="button" className="btn btn-primary self-start" disabled={form.processing} onClick={() => form.post(route('web-clock.resume'), visit)}>
                {form.processing ? t('my-day.saving') : t('my-day.resume.button')}
            </button>
            {until && <p className="num m-0 text-sm">{t('my-day.resume.until', { time: formatTime(until, locale) })}</p>}
            {(form.errors as Record<string, string>).clock && <Notice tone="danger">{errorText(t, (form.errors as Record<string, string>).clock)}</Notice>}
        </div>
    );
}

function ClockOutAction({ overtime, reason }: { overtime: boolean; reason: string | null }) {
    const t = useT();
    const [step, setStep] = useState<'button' | 'report'>('button');
    const trigger = useReturnFocus(step === 'report');
    const form = useForm({ work_report: '' });
    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        form.post(route('web-clock.clock-out'), { ...visit, onSuccess: () => setStep('button') });
    };

    if (overtime && step === 'report') {
        return (
            <form onSubmit={submit} className="mt-5 flex max-w-[560px] flex-col gap-3">
                {reason && <p className="m-0 border-l-2 border-line-strong pl-3 text-sm text-muted">{t('my-day.report.your_reason', { reason })}</p>}
                <TextAreaField
                    autoFocus
                    label={t('my-day.report.label')}
                    help={t('my-day.clock_out.report_later')}
                    value={form.data.work_report}
                    onChange={(e) => form.setData('work_report', e.target.value)}
                    error={form.errors.work_report}
                    maxLength={5000}
                />
                {(form.errors as Record<string, string>).clock && <Notice tone="danger">{errorText(t, (form.errors as Record<string, string>).clock)}</Notice>}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? t('my-day.saving') : t('my-day.clock_out.button')}
                    </button>
                    <button type="button" className="btn btn-quiet" onClick={() => setStep('button')}>
                        {t('my-day.back')}
                    </button>
                </div>
            </form>
        );
    }

    return (
        <div className="mt-5 flex flex-col gap-2">
            <button ref={trigger} type="button" className="btn btn-secondary self-start bg-surface" disabled={form.processing} onClick={() => (overtime ? setStep('report') : submit())}>
                <SignOut weight="bold" size={18} aria-hidden />
                {form.processing ? t('my-day.saving') : t('my-day.clock_out.button')}
            </button>
            {(form.errors as Record<string, string>).clock && <Notice tone="danger">{errorText(t, (form.errors as Record<string, string>).clock)}</Notice>}
        </div>
    );
}

function WebNotes({ rules, permission, onEnableReminders }: { rules: Summary['rules']; permission: ReminderPermission; onEnableReminders: () => void }) {
    const t = useT();

    return (
        <div className="mt-5 flex flex-col gap-2 border-t border-[color-mix(in_srgb,var(--eye-brow)_25%,transparent)] pt-4 text-sm">
            <p className="m-0">{t('my-day.web.heartbeat_note', { minutes: rules.resume_window_minutes })}</p>
            <p className="m-0">{t('my-day.web.no_idle_note', { minutes: rules.presence_check_minutes })}</p>
            {permission === 'unsupported' && <p className="m-0">{t('my-day.reminders.unsupported')}</p>}
            {permission === 'default' && (
                <button type="button" className="btn btn-secondary btn-sm min-h-[44px] self-start bg-surface" onClick={onEnableReminders}>
                    <Bell weight="bold" size={16} aria-hidden />
                    {t('my-day.reminders.enable')}
                </button>
            )}
            {permission === 'granted' && (
                <p className="m-0 flex items-center gap-1.5 font-semibold">
                    <Bell weight="bold" size={16} aria-hidden />
                    {t('my-day.reminders.on')}
                </p>
            )}
            {permission === 'denied' && (
                <p className="m-0 flex items-center gap-1.5">
                    <BellSlash weight="bold" size={16} aria-hidden />
                    {t('my-day.reminders.blocked')}
                </p>
            )}
        </div>
    );
}
