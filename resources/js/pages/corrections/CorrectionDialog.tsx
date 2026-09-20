import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextAreaField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { formatMinutes, formatShortDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { ArrowsClockwise, X } from '@phosphor-icons/react';
import type { Locale } from '@/types';
import { type FormEvent, type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { PreviewPanel, type PreviewState } from './PreviewPanel';
import { nextDate, requestJson, studioParts } from './requests';
import { type CorrectionField, FIELDS, type PersonOption, type PreviewResponse, type ShiftOption, type ShiftsResponse } from './types';

const REASON_MIN = 10;

interface Props {
    open: boolean;
    onClose: () => void;
    /** Superadmin applies at once; Management sends a proposal. */
    apply: boolean;
    people: PersonOption[];
    today: string;
    onSent: () => void;
}

export function CorrectionDialog({ open, onClose, ...rest }: Props) {
    const titleId = useId();

    return (
        <Dialog open={open} onClose={onClose} labelledBy={titleId} width="max-w-[640px]" closeOnBackdrop={false}>
            <CorrectionForm titleId={titleId} onClose={onClose} {...rest} />
        </Dialog>
    );
}

type ShiftsState = { kind: 'idle' } | { kind: 'loading' } | { kind: 'failed'; message: string } | { kind: 'ready'; data: ShiftsResponse };

/**
 * The correction form: person, work date, shift, the time to correct, the right time (Asia/Jakarta) and a reason. The
 * result on the work date is calculated by the server while the form is filled in, before anything is sent.
 */
function CorrectionForm({ titleId, onClose, apply, people, today, onSent }: Omit<Props, 'open'> & { titleId: string }) {
    const t = useT();
    const locale = useLocale();
    const formRef = useRef<HTMLFormElement>(null);

    const [personId, setPersonId] = useState('');
    const [workDate, setWorkDate] = useState('');
    const [shifts, setShifts] = useState<ShiftsState>({ kind: 'idle' });
    const [shiftsAttempt, setShiftsAttempt] = useState(0);
    const [shiftId, setShiftId] = useState<number | null>(null);
    const [field, setField] = useState<CorrectionField | null>(null);
    const [day, setDay] = useState('');
    const [time, setTime] = useState('');
    const [reason, setReason] = useState('');
    const [preview, setPreview] = useState<PreviewState>({ kind: 'idle' });
    const [previewAttempt, setPreviewAttempt] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);

    // The dialog focuses its close button when it opens; the person field is where the form starts
    useEffect(() => {
        const frame = requestAnimationFrame(() => formRef.current?.querySelector<HTMLSelectElement>('select[name="person"]')?.focus());
        return () => cancelAnimationFrame(frame);
    }, []);

    const shift = shifts.kind === 'ready' ? (shifts.data.shifts.find((s) => s.id === shiftId) ?? null) : null;
    const recorded = shift && field ? shift.values[field] : null;

    // Shifts of the chosen person on the chosen date
    useEffect(() => {
        setShiftId(null);
        setField(null);
        if (personId === '' || !/^\d{4}-\d{2}-\d{2}$/.test(workDate)) {
            setShifts({ kind: 'idle' });
            return;
        }

        const controller = new AbortController();
        setShifts({ kind: 'loading' });
        requestJson<ShiftsResponse>(t, 'get', route('corrections.shifts'), { person_id: personId, work_date: workDate }, controller.signal)
            .then((result) => {
                if (!result.ok) {
                    setShifts({ kind: 'failed', message: result.message });
                    return;
                }
                setShifts({ kind: 'ready', data: result.data });
                const open = result.data.shifts.filter((s) => !s.is_live);
                if (result.data.shifts.length === 1 && open.length === 1) setShiftId(open[0].id);
            })
            .catch(() => undefined);

        return () => controller.abort();
    }, [personId, workDate, shiftsAttempt]);

    // Start from the recorded time of the chosen field
    useEffect(() => {
        setErrors((current) => omit(current, 'time', 'date'));
        if (!field || !shift) return;
        const value = shift.values[field];
        if (value) {
            const parts = studioParts(value);
            setDay(parts.date);
            setTime(parts.time);
        } else {
            setDay(shifts.kind === 'ready' ? shifts.data.work_date : workDate);
            setTime('');
        }
    }, [field, shiftId]);

    const sameAsRecorded = recorded !== null && studioParts(recorded).date === day && studioParts(recorded).time === time;
    const complete = shift !== null && field !== null && /^\d{4}-\d{2}-\d{2}$/.test(day) && /^\d{2}:\d{2}$/.test(time);

    // The result on the work date, calculated by the server without saving
    useEffect(() => {
        if (!complete || sameAsRecorded) {
            setPreview({ kind: 'idle' });
            return;
        }

        const controller = new AbortController();
        setPreview({ kind: 'loading' });
        const timer = window.setTimeout(() => {
            requestJson<PreviewResponse>(t, 'post', route('corrections.preview'), { shift_id: shiftId, field, date: day, time }, controller.signal)
                .then((result) => {
                    if (result.ok) {
                        setPreview({ kind: 'ready', data: result.data });
                        setErrors((current) => omit(current, 'time', 'shift_id'));
                    } else if (result.errors) {
                        setPreview({ kind: 'refused', message: result.message });
                        setErrors((current) => ({ ...omit(current, 'time', 'shift_id'), ...pick(result.errors ?? {}, 'time', 'shift_id', 'date') }));
                    } else {
                        setPreview({ kind: 'failed', message: result.message });
                    }
                })
                .catch(() => undefined);
        }, 400);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [complete, sameAsRecorded, shiftId, field, day, time, previewAttempt]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailure(null);

        const local: Record<string, string> = {};
        if (personId === '') local.person = t('corrections.form.person_required');
        if (!shift) local.shift_id = t('corrections.form.shift_required');
        if (shift && !field) local.field = t('corrections.form.field_required');
        if (shift && field && !/^\d{2}:\d{2}$/.test(time)) local.time = t('corrections.form.time_required');
        if (reason.trim() === '') local.reason = t('corrections.form.reason_required');
        else if (reason.trim().length < REASON_MIN) local.reason = t('corrections.form.reason_short');

        if (Object.keys(local).length > 0 || !complete) {
            setErrors((current) => ({ ...current, ...local }));
            focusFirstError();
            return;
        }

        router.post(
            route('corrections.store'),
            { shift_id: shiftId, field, date: day, time, reason },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onSuccess: () => onSent(),
                onError: (serverErrors) => {
                    setErrors(serverErrors as Record<string, string>);
                    focusFirstError();
                },
                onHttpException: (response) => {
                    setFailure(
                        response.status === 419 ? t('corrections.form.expired') : response.status === 403 ? t('corrections.form.forbidden') : t('corrections.form.failed'),
                    );
                    return false;
                },
                onNetworkError: () => {
                    setFailure(t('corrections.form.failed'));
                    return false;
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const focusFirstError = () =>
        requestAnimationFrame(() => formRef.current?.querySelector('[role="alert"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));

    const dayOptions = shifts.kind === 'ready' ? unique([shifts.data.work_date, nextDate(shifts.data.work_date), day].filter(Boolean)) : [];

    return (
        <form ref={formRef} onSubmit={submit} noValidate className="flex max-h-[calc(100dvh-48px)] flex-col">
            <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                <div className="min-w-0">
                    <h2 id={titleId} className="h2">
                        {apply ? t('corrections.form.title_apply') : t('corrections.form.title_propose')}
                    </h2>
                    <p className="m-0 mt-1 text-sm text-muted">{apply ? t('corrections.form.lead_apply') : t('corrections.form.lead_propose')}</p>
                </div>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('corrections.form.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>

            <div className="flex flex-1 flex-col gap-[18px] overflow-y-auto px-5 py-5 sm:px-7">
                {failure && <Notice tone="danger">{failure}</Notice>}
                {errors.correction && <Notice tone="danger">{errors.correction}</Notice>}

                <div className="grid gap-[18px] sm:grid-cols-2">
                    <SelectField
                        name="person"
                        label={t('corrections.form.person')}
                        value={personId}
                        onChange={(e) => {
                            setPersonId(e.target.value);
                            setErrors((current) => omit(current, 'person', 'shift_id', 'correction'));
                        }}
                        error={errors.person}
                        required
                    >
                        <option value="">{t('corrections.form.person_placeholder')}</option>
                        {people.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.status === 'active' ? person.name : t('corrections.form.person_inactive', { name: person.name })}
                                {person.teams.length > 0 ? `, ${person.teams.join(', ')}` : ''}
                            </option>
                        ))}
                    </SelectField>
                    <TextField
                        type="date"
                        label={t('corrections.form.work_date')}
                        help={t('corrections.form.work_date_help')}
                        value={workDate}
                        max={today}
                        onChange={(e) => setWorkDate(e.target.value)}
                        required
                    />
                </div>

                <Choices legend={t('corrections.form.shift')} error={errors.shift_id}>
                    {shifts.kind === 'idle' && <p className="m-0 text-sm text-muted">{t('corrections.form.shift_pick_first')}</p>}
                    {shifts.kind === 'loading' && (
                        <p className="m-0 text-sm text-muted" role="status">
                            {t('corrections.form.shifts_loading')}
                        </p>
                    )}
                    {shifts.kind === 'failed' && (
                        <div className="flex flex-col items-start gap-2">
                            <Notice tone="danger">{shifts.message}</Notice>
                            <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => setShiftsAttempt((n) => n + 1)}>
                                <ArrowsClockwise weight="bold" size={16} aria-hidden />
                                {t('corrections.form.shifts_retry')}
                            </button>
                        </div>
                    )}
                    {shifts.kind === 'ready' && shifts.data.shifts.length === 0 && <p className="m-0 text-sm text-muted">{t('corrections.form.shifts_empty')}</p>}
                    {shifts.kind === 'ready' &&
                        shifts.data.shifts.map((option) => (
                            <ChoiceRow
                                key={option.id}
                                name="shift"
                                checked={shiftId === option.id}
                                disabled={option.is_live}
                                onChange={() => {
                                    setShiftId(option.id);
                                    if (field && option.unavailable[field]) setField(null);
                                    setErrors((current) => omit(current, 'shift_id'));
                                }}
                                title={
                                    option.values.clock_out_at
                                        ? t('corrections.form.shift_range', {
                                              start: formatTime(option.values.clock_in_at ?? '', locale),
                                              end: shiftEnd(option, shifts.data.work_date, locale),
                                          })
                                        : t('corrections.form.shift_running', { start: formatTime(option.values.clock_in_at ?? '', locale) })
                                }
                                detail={
                                    option.is_live
                                        ? t('corrections.form.shift_running_note')
                                        : t('corrections.form.shift_minutes', {
                                              regular: formatMinutes(option.regular_minutes, locale),
                                              overtime: formatMinutes(option.overtime_minutes, locale),
                                          })
                                }
                            />
                        ))}
                </Choices>

                {shift && (
                    <Choices legend={t('corrections.form.field')} error={errors.field} columns>
                        {FIELDS.map((key) => {
                            const unavailable = shift.unavailable[key];
                            const value = shift.values[key];
                            return (
                                <ChoiceRow
                                    key={key}
                                    name="field"
                                    checked={field === key}
                                    disabled={unavailable !== undefined}
                                    onChange={() => {
                                        setField(key);
                                        setErrors((current) => omit(current, 'field'));
                                    }}
                                    title={t(`corrections.fields.${key}`)}
                                    detail={
                                        unavailable
                                            ? t(`corrections.form.unavailable.${unavailable}`)
                                            : value
                                              ? t('corrections.form.recorded', { time: formatTime(value, locale) })
                                              : t('corrections.form.recorded_none')
                                    }
                                />
                            );
                        })}
                    </Choices>
                )}

                {shift && field && (
                    <div className="grid gap-[18px] sm:grid-cols-2">
                        <TextField
                            type="time"
                            label={t('corrections.form.time')}
                            help={t('corrections.form.time_help')}
                            value={time}
                            onChange={(e) => setTime(e.target.value)}
                            error={errors.time ?? errors.date}
                            required
                        />
                        {field !== 'clock_in_at' && (
                            <SelectField label={t('corrections.form.day')} value={day} onChange={(e) => setDay(e.target.value)}>
                                {dayOptions.map((option, index) => (
                                    <option key={option} value={option}>
                                        {index === 1 && option === nextDate(dayOptions[0])
                                            ? t('corrections.form.day_next', { date: formatShortDate(option, locale) })
                                            : formatShortDate(option, locale)}
                                    </option>
                                ))}
                            </SelectField>
                        )}
                    </div>
                )}

                <TextAreaField
                    label={t('corrections.form.reason')}
                    help={t('corrections.form.reason_help')}
                    value={reason}
                    onChange={(e) => {
                        setReason(e.target.value);
                        setErrors((current) => omit(current, 'reason'));
                    }}
                    error={errors.reason}
                    maxLength={2000}
                    required
                />

                <PreviewPanel state={preview} onRetry={() => setPreviewAttempt((n) => n + 1)} refusalShownElsewhere={Boolean(errors.time || errors.shift_id)} />
            </div>

            <footer className="flex flex-wrap gap-3 border-t border-line px-5 py-4 sm:px-7">
                <button type="submit" className="btn btn-primary" disabled={processing || preview.kind === 'refused'}>
                    {processing ? t('corrections.form.sending') : apply ? t('corrections.form.submit_apply') : t('corrections.form.submit_propose')}
                </button>
                <button type="button" className="btn btn-quiet" onClick={onClose} disabled={processing}>
                    {t('corrections.form.cancel')}
                </button>
            </footer>
        </form>
    );
}

function Choices({ legend, error, columns = false, children }: { legend: string; error?: string; columns?: boolean; children: ReactNode }) {
    const errorId = useId();

    return (
        <fieldset className="m-0 flex min-w-0 flex-col gap-2 border-0 p-0" aria-describedby={error ? errorId : undefined}>
            <legend className="label mb-1.5 p-0">{legend}</legend>
            <div className={columns ? 'grid gap-2 sm:grid-cols-2' : 'flex flex-col gap-2'}>{children}</div>
            {error && (
                <p id={errorId} className="error-text m-0" role="alert">
                    {error}
                </p>
            )}
        </fieldset>
    );
}

function ChoiceRow({
    name,
    checked,
    disabled,
    onChange,
    title,
    detail,
}: {
    name: string;
    checked: boolean;
    disabled: boolean;
    onChange: () => void;
    title: string;
    detail: string;
}) {
    return (
        <label
            className={`flex min-h-11 items-start gap-3 rounded-sm border-[1.5px] px-3 py-2.5 ${
                checked ? 'border-[var(--focus)] bg-[var(--selected)]' : 'border-line-strong'
            } ${disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'}`}
        >
            <input type="radio" name={name} className="mt-1 h-4 w-4 flex-none accent-[var(--primary-bg)]" checked={checked} disabled={disabled} onChange={onChange} />
            <span className="flex min-w-0 flex-col">
                <span className="num font-semibold">{title}</span>
                <span className="num text-sm text-muted">{detail}</span>
            </span>
        </label>
    );
}

/** The end of a shift, with its date when it ended on a later day. */
function shiftEnd(option: ShiftOption, workDate: string, locale: Locale): string {
    const end = option.values.clock_out_at ?? '';
    const parts = studioParts(end);
    return parts.date === workDate ? formatTime(end, locale) : `${formatShortDate(parts.date, locale)} ${formatTime(end, locale)}`;
}

function omit(errors: Record<string, string>, ...keys: string[]): Record<string, string> {
    const next = { ...errors };
    for (const key of keys) delete next[key];
    return next;
}

function pick(errors: Record<string, string>, ...keys: string[]): Record<string, string> {
    return Object.fromEntries(Object.entries(errors).filter(([key]) => keys.includes(key)));
}

function unique(values: string[]): string[] {
    return [...new Set(values)];
}
