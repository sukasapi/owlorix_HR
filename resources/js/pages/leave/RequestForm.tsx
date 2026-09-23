import { SelectField, TextAreaField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { CalendarCheck, CircleNotch, WarningCircle } from '@phosphor-icons/react';
import { type FormEvent, useRef, useState } from 'react';
import { dayCount, workdays } from './format';
import type { MyLeaveProps } from './types';
import { usePreview } from './usePreview';

type Data = { leave_type_id: string; start_date: string; end_date: string; reason: string; attachment: File | null };

/**
 * The request form. The workday count shows under the dates before sending, so the person sees what a range with
 * a holiday or weekend in it really costs. The server counts again and has the last word.
 */
export function RequestForm({ types, limits }: Pick<MyLeaveProps, 'types' | 'limits'>) {
    const t = useT();
    const fileRef = useRef<HTMLInputElement>(null);
    const [failed, setFailed] = useState(false);
    const initial: Data = { leave_type_id: types[0] ? String(types[0].id) : '', start_date: '', end_date: '', reason: '', attachment: null };
    const form = useForm<Data>(initial);
    const type = types.find((item) => String(item.id) === form.data.leave_type_id) ?? null;
    const preview = usePreview(form.data.start_date, form.data.end_date, form.data.leave_type_id);

    if (types.length === 0) {
        return (
            <section className="card px-5 py-5 sm:px-6">
                <h2 className="h2">{t('leave.form.title')}</h2>
                <Notice className="mt-3">{t('leave.form.no_types')}</Notice>
            </section>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);
        form.transform((data) => ({ ...data, reason: data.reason.trim() === '' ? null : data.reason }));
        form.post(route('leave.store'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                if (fileRef.current) fileRef.current.value = '';
            },
            onHttpException: () => {
                setFailed(true);
                return false;
            },
            onNetworkError: () => {
                setFailed(true);
                return false;
            },
        });
    };

    return (
        <section aria-labelledby="leave-form-title" className="card px-5 py-5 sm:px-6">
            <h2 id="leave-form-title" className="h2">
                {t('leave.form.title')}
            </h2>
            <form onSubmit={submit} noValidate className="mt-4 flex flex-col gap-4">
                <SelectField
                    label={t('leave.form.type')}
                    value={form.data.leave_type_id}
                    onChange={(e) => form.setData('leave_type_id', e.target.value)}
                    error={form.errors.leave_type_id}
                    required
                >
                    {types.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.counts_against_quota ? t('leave.form.type_quota', { name: item.name }) : item.name}
                        </option>
                    ))}
                </SelectField>

                <div className="grid gap-4 min-[420px]:grid-cols-2">
                    <TextField
                        label={t('leave.form.start')}
                        type="date"
                        min={limits.earliest}
                        max={limits.latest}
                        value={form.data.start_date}
                        onChange={(e) => {
                            const start = e.target.value;
                            // A one-day request is the common case: the end follows the start until it is set
                            form.setData((data) => ({ ...data, start_date: start, end_date: data.end_date === '' || data.end_date < start ? start : data.end_date }));
                        }}
                        error={form.errors.start_date}
                        required
                    />
                    <TextField
                        label={t('leave.form.end')}
                        type="date"
                        min={form.data.start_date || limits.earliest}
                        max={limits.latest}
                        value={form.data.end_date}
                        onChange={(e) => form.setData('end_date', e.target.value)}
                        error={form.errors.end_date}
                        required
                    />
                </div>

                <PreviewLine preview={preview} countsQuota={type?.counts_against_quota ?? false} />
                <p className="help m-0 -mt-2">{t('leave.form.dates_help', { days: limits.backdate_days, max: limits.max_span_days })}</p>

                <TextAreaField
                    label={type?.requires_note ? t('leave.form.reason_required') : t('leave.form.reason_optional')}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    error={form.errors.reason}
                    maxLength={2000}
                    required={type?.requires_note ?? false}
                />

                <div className="field">
                    <label className="label" htmlFor="leave-attachment">
                        {t('leave.form.attachment')}
                    </label>
                    <input
                        ref={fileRef}
                        id="leave-attachment"
                        type="file"
                        className="input py-2"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                        aria-describedby="leave-attachment-help"
                        aria-invalid={Boolean(form.errors.attachment)}
                        onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)}
                    />
                    <p id="leave-attachment-help" className="help m-0">
                        {t('leave.form.attachment_help', { mb: limits.attachment_max_kb / 1024 })}
                    </p>
                    {form.errors.attachment && (
                        <p className="error-text m-0 flex items-center gap-1.5" role="alert">
                            <WarningCircle weight="bold" size={15} aria-hidden />
                            {form.errors.attachment}
                        </p>
                    )}
                </div>

                {failed && <Notice tone="danger">{t('leave.form.failed')}</Notice>}

                <div>
                    <button type="submit" className="btn btn-primary w-full sm:w-auto" disabled={form.processing}>
                        {form.processing ? t('leave.form.sending') : t('leave.form.submit')}
                    </button>
                </div>
            </form>
        </section>
    );
}

/** What the dates count, in words, announced politely as it changes. */
function PreviewLine({ preview, countsQuota }: { preview: ReturnType<typeof usePreview>; countsQuota: boolean }) {
    const t = useT();

    const content = (() => {
        if (preview.state === 'idle') return <span className="text-muted">{t('leave.preview.idle')}</span>;
        if (preview.state === 'loading')
            return (
                <span className="flex items-center gap-1.5 text-muted">
                    <CircleNotch weight="bold" size={16} className="flex-none motion-safe:animate-spin" aria-hidden />
                    {t('leave.preview.loading')}
                </span>
            );
        if (preview.state === 'failed') return <span className="text-muted">{t('leave.preview.failed')}</span>;
        if (preview.error || preview.days === null)
            return (
                <span className="flex items-start gap-1.5 font-semibold text-danger">
                    <WarningCircle weight="bold" size={16} className="mt-0.5 flex-none" aria-hidden />
                    {preview.error}
                </span>
            );

        const after = preview.remaining === null ? null : preview.remaining - preview.days;

        return (
            <span className="flex items-start gap-1.5">
                <CalendarCheck weight="bold" size={16} className="mt-0.5 flex-none" aria-hidden />
                <span>
                    <span className="font-semibold">{t('leave.preview.counted', { days: workdays(preview.days, t) })}</span>
                    {countsQuota && after !== null && preview.remaining !== null && (
                        <span className={`block ${after < 0 ? 'font-semibold text-danger' : ''}`}>
                            {after < 0 ? t('leave.preview.over', { days: dayCount(Math.max(preview.remaining, 0), t) }) : t('leave.preview.remaining_after', { days: dayCount(after, t) })}
                        </span>
                    )}
                </span>
            </span>
        );
    })();

    return (
        <p className="num m-0 rounded-md bg-panel px-3.5 py-2.5 text-sm" aria-live="polite">
            {content}
        </p>
    );
}
