import { OwlEyes } from '@/components/owl/OwlEyes';
import { TextAreaField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { type FormEvent, type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { useEyesMotion } from './hooks';
import { errorText, type OpenShift, type PresenceCheck, type Summary } from './types';

const visit = { preserveScroll: true, only: ['summary'] };

/** Buttons stay disabled for a moment after the choices appear, so a stray key press cannot answer (DESIGN.md G8). */
const ARM_MS = 1500;

/** Card that asks for an answer: brow cut and the eyes in attention, the two places DESIGN.md reserves the motif for. */
function AskCard({ labelledBy, eyesTime, title, children }: { labelledBy: string; eyesTime: string; title: ReactNode; children: ReactNode }) {
    const eyes = useEyesMotion('attention');

    return (
        <section aria-labelledby={labelledBy} className="overflow-hidden rounded-[var(--r-brow)] border border-line bg-surface">
            <div className="bg-panel px-5 pt-5 pb-4 sm:px-7">
                <div className="flex items-start justify-between gap-3">
                    <span ref={eyes} className="inline-block origin-center">
                        <OwlEyes state="attention" size={64} />
                    </span>
                    <span className="num text-sm text-muted">{eyesTime}</span>
                </div>
                {title}
            </div>
            <div className="flex flex-col gap-3 px-5 py-5 sm:px-7">{children}</div>
        </section>
    );
}

/** 3.3 on the web: "Jawab" first, then "Absen pulang" or "Lanjut lembur" with a reason. */
export function PromptCard({ shift, repeatMinutes, reasonMin }: { shift: OpenShift; repeatMinutes: number; reasonMin: number }) {
    const t = useT();
    const locale = useLocale();
    const titleId = useId();
    const [step, setStep] = useState<'closed' | 'choices' | 'reason'>('closed');
    const [armed, setArmed] = useState(false);
    const choicesRef = useRef<HTMLParagraphElement>(null);
    const clockOut = useForm({});
    const overtime = useForm({ reason: '' });
    const mark = shift.regular_ends_at ?? shift.clock_in_at;
    const errors = { ...(clockOut.errors as Record<string, string>), ...(overtime.errors as Record<string, string>) };

    useEffect(() => {
        if (step !== 'choices') return;
        choicesRef.current?.focus();
        const id = window.setTimeout(() => setArmed(true), ARM_MS);
        return () => window.clearTimeout(id);
    }, [step]);

    // Every time the choices appear they start disarmed, also when coming back from the reason step
    const openChoices = () => {
        setArmed(false);
        setStep('choices');
    };

    const startOvertime = (event: FormEvent) => {
        event.preventDefault();
        overtime.post(route('web-clock.keep-working'), visit);
    };

    return (
        <AskCard
            labelledBy={titleId}
            eyesTime={formatTime(mark, locale)}
            title={
                <>
                    <h2 id={titleId} className="display mt-3 text-[30px] text-heading sm:text-[36px]">
                        {step === 'reason' ? t('my-day.prompt.reason_title') : t('my-day.prompt.title')}
                    </h2>
                    {step !== 'reason' && <p className="num m-0 mt-2 text-base">{t('my-day.prompt.lead', { time: formatTime(mark, locale) })}</p>}
                </>
            }
        >
            {step === 'closed' && (
                <button type="button" className="btn btn-primary w-full sm:w-auto sm:self-start" onClick={openChoices}>
                    {t('my-day.prompt.answer')}
                </button>
            )}

            {step === 'choices' && (
                <div role="group" aria-label={t('my-day.prompt.title')} className="flex flex-col gap-3">
                    <p ref={choicesRef} tabIndex={-1} className="m-0 text-sm text-muted" aria-live="polite">
                        {armed ? t('my-day.prompt.choose') : t('my-day.prompt.arming')}
                    </p>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <button type="button" className="btn btn-primary" disabled={!armed || clockOut.processing} onClick={() => clockOut.post(route('web-clock.prompt-clock-out'), visit)}>
                            {clockOut.processing ? t('my-day.saving') : t('my-day.clock_out.button')}
                        </button>
                        <button type="button" className="btn btn-secondary" disabled={!armed} onClick={() => setStep('reason')}>
                            {t('my-day.prompt.keep_working')}
                        </button>
                    </div>
                </div>
            )}

            {step === 'reason' && (
                <form onSubmit={startOvertime} className="flex flex-col gap-3">
                    <TextAreaField
                        autoFocus
                        label={t('my-day.clock_in.reason_label')}
                        help={t('my-day.reason_help')}
                        value={overtime.data.reason}
                        onChange={(e) => overtime.setData('reason', e.target.value)}
                        error={errors.reason}
                        maxLength={2000}
                        required
                    />
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <button type="submit" className="btn btn-primary" disabled={overtime.processing || overtime.data.reason.trim().length < reasonMin}>
                            {overtime.processing ? t('my-day.saving') : t('my-day.prompt.start_overtime')}
                        </button>
                        <button type="button" className="btn btn-quiet" onClick={openChoices}>
                            {t('my-day.back')}
                        </button>
                    </div>
                </form>
            )}

            {errors.clock && <Notice tone="danger">{errorText(t, errors.clock)}</Notice>}

            <div className="border-t border-line pt-3 text-sm text-muted">
                {shift.prompt_deadline_at && (
                    <p className="num m-0">{t('my-day.prompt.deadline', { deadline: formatTime(shift.prompt_deadline_at, locale), mark: formatTime(mark, locale) })}</p>
                )}
                <p className="m-0 mt-1">{t('my-day.prompt.repeat', { minutes: repeatMinutes })}</p>
            </div>
        </AskCard>
    );
}

/** 3.11: "Masih lembur?" during overtime in a browser. */
export function PresenceCard({ check, answerMinutes, checkMinutes }: { check: PresenceCheck; answerMinutes: number; checkMinutes: number }) {
    const t = useT();
    const locale = useLocale();
    const titleId = useId();
    const form = useForm({});
    const shownAt = check.check_shown_at ?? check.next_check_at;
    const deadline = check.answer_deadline_at ?? new Date(new Date(shownAt).getTime() + answerMinutes * 60_000).toISOString();
    const error = (form.errors as Record<string, string>).clock;

    return (
        <AskCard
            labelledBy={titleId}
            eyesTime={formatTime(shownAt, locale)}
            title={
                <>
                    <h2 id={titleId} className="display mt-3 text-[30px] text-heading sm:text-[36px]">
                        {t('my-day.presence.title')}
                    </h2>
                    <p className="num m-0 mt-2 text-base">{t('my-day.presence.lead', { deadline: formatTime(deadline, locale), shown: formatTime(shownAt, locale) })}</p>
                </>
            }
        >
            <button type="button" className="btn btn-primary w-full sm:w-auto sm:self-start" disabled={form.processing} onClick={() => form.post(route('web-clock.still-working'), visit)}>
                {form.processing ? t('my-day.saving') : t('my-day.presence.answer')}
            </button>
            {error && <Notice tone="danger">{errorText(t, error)}</Notice>}
            <p className="m-0 border-t border-line pt-3 text-sm text-muted">{t('my-day.presence.why', { minutes: checkMinutes })}</p>
        </AskCard>
    );
}

export function presenceDue(summary: Summary, now: number): PresenceCheck | null {
    const check = summary.presence_check;
    if (!check || !summary.open_shift?.on_this_browser || summary.open_shift.status !== 'overtime') return null;
    return check.check_shown_at || now >= new Date(check.next_check_at).getTime() ? check : null;
}
