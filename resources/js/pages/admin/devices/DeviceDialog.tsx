import { TextAreaField } from '@/components/ui/Field';
import { Dialog } from '@/components/ui/Dialog';
import { Notice } from '@/components/ui/Notice';
import { useLocale, useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { Timer, Warning, WarningCircle, X } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useId, useRef, useState } from 'react';
import type { DeviceRow } from './types';
import { when } from './when';

export type DeviceDialogMode = { kind: 'revoke' | 'restore'; device: DeviceRow };

interface Props {
    mode: DeviceDialogMode | null;
    resumeWindowMinutes: number;
    onClose: () => void;
    onDone: (kind: 'revoke' | 'restore', device: DeviceRow) => void;
}

export function DeviceDialog({ mode, onClose, ...rest }: Props) {
    const titleId = useId();

    return (
        <Dialog open={mode !== null} onClose={onClose} labelledBy={titleId} width="max-w-[600px]" closeOnBackdrop={false}>
            {mode && <DeviceForm key={`${mode.kind}-${mode.device.id}`} mode={mode} titleId={titleId} onClose={onClose} {...rest} />}
        </Dialog>
    );
}

/** A code from the server is translated; anything else is shown as sent. */
function errorText(t: ReturnType<typeof useT>, value: string | undefined): string | undefined {
    if (!value) return undefined;
    return /^[a-z_]+$/.test(value) ? t(`devices.errors.${value}`) : value;
}

function DeviceForm({ mode, titleId, onClose, onDone, resumeWindowMinutes }: Omit<Props, 'mode'> & { mode: DeviceDialogMode; titleId: string }) {
    const t = useT();
    const locale = useLocale();
    const { kind, device } = mode;
    const revoking = kind === 'revoke';
    const formRef = useRef<HTMLFormElement>(null);
    const confirmId = useId();
    const [failed, setFailed] = useState(false);
    const form = useForm({ reason: '', confirm_open_shift: false });
    const errors = form.errors as Record<string, string | undefined>;
    const hasOpenShift = revoking && device.open_shifts.length > 0;
    const confirmError = errorText(t, errors.confirm_open_shift);

    useEffect(() => {
        const frame = requestAnimationFrame(() => formRef.current?.querySelector<HTMLTextAreaElement>('textarea')?.focus());
        return () => cancelAnimationFrame(frame);
    }, []);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);

        if (hasOpenShift && !form.data.confirm_open_shift) {
            form.setError('confirm_open_shift', 'confirm_required');
            document.getElementById(confirmId)?.focus();
            return;
        }

        const url = revoking ? route('admin.devices.revoke', device.id) : route('admin.devices.restore', device.id);

        form.post(url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onDone(kind, device),
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

    const effect = revoking ? t(`devices.dialog.effect_${device.kind}`) : t(`devices.dialog.restore_effect_${device.kind}`);

    return (
        <form ref={formRef} onSubmit={submit} noValidate className="flex max-h-[calc(100dvh-48px)] flex-col">
            <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                <div className="min-w-0">
                    <h2 id={titleId} className="h2 break-words">
                        {revoking ? t('devices.dialog.revoke_title', { name: device.hostname }) : t('devices.dialog.restore_title', { name: device.hostname })}
                    </h2>
                    <p className="m-0 mt-1 text-sm break-all text-muted">
                        {t('devices.dialog.device_id')}: <span className="num">{device.id}</span>
                    </p>
                </div>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('devices.dialog.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>

            <div className="flex flex-1 flex-col gap-[18px] overflow-y-auto px-5 py-5 sm:px-7">
                {failed && <Notice tone="danger">{t('devices.dialog.failed')}</Notice>}

                <p className="m-0">{effect}</p>

                {hasOpenShift && (
                    // Gold marks a moment that needs an answer (DESIGN.md eyes motif); the tick box is that answer
                    <section className="flex flex-col gap-3 rounded-md border border-gold bg-gold-tint px-4 py-3.5" aria-labelledby={`${confirmId}-title`}>
                        <h3 id={`${confirmId}-title`} className="m-0 flex items-center gap-2 text-base font-semibold">
                            <Warning weight="bold" size={18} aria-hidden className="flex-none" />
                            {t('devices.dialog.open_shift_title')}
                        </h3>
                        <ul className="m-0 flex list-none flex-col gap-1 p-0">
                            {device.open_shifts.map((shift) => (
                                <li key={shift.user_id} className="num flex items-center gap-2 font-medium">
                                    <Timer weight="bold" size={16} aria-hidden className="flex-none" />
                                    {shift.status === 'interrupted'
                                        ? t('devices.shift_interrupted', { name: shift.name, time: shift.clock_in_at ? when(shift.clock_in_at, locale, t) : '' })
                                        : t('devices.shift_since', { name: shift.name, time: shift.clock_in_at ? when(shift.clock_in_at, locale, t) : '' })}
                                </li>
                            ))}
                        </ul>
                        <p className="m-0 text-sm">{t('devices.dialog.open_shift_body', { minutes: resumeWindowMinutes })}</p>
                        <label htmlFor={confirmId} className="flex min-h-11 cursor-pointer items-start gap-3 font-semibold">
                            <input
                                id={confirmId}
                                type="checkbox"
                                className="mt-0.5 size-5 flex-none accent-[var(--primary-bg)]"
                                checked={form.data.confirm_open_shift}
                                onChange={(e) => {
                                    form.setData('confirm_open_shift', e.target.checked);
                                    if (e.target.checked) form.clearErrors('confirm_open_shift');
                                }}
                                aria-invalid={Boolean(confirmError)}
                                aria-describedby={confirmError ? `${confirmId}-error` : undefined}
                            />
                            <span>{t('devices.dialog.open_shift_confirm')}</span>
                        </label>
                    </section>
                )}

                {!hasOpenShift && confirmError && <Notice tone="danger">{confirmError}</Notice>}
                {hasOpenShift && confirmError && (
                    <p id={`${confirmId}-error`} className="error-text m-0 flex items-center gap-1.5" role="alert">
                        <WarningCircle weight="bold" size={15} aria-hidden />
                        {confirmError}
                    </p>
                )}

                <TextAreaField
                    label={t('devices.dialog.reason')}
                    name="reason"
                    required
                    maxLength={500}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    help={revoking ? t('devices.dialog.reason_help_revoke') : t('devices.dialog.reason_help_restore')}
                    error={errorText(t, errors.reason)}
                />
            </div>

            <footer className="flex flex-col-reverse gap-2.5 border-t border-line px-5 py-4 min-[480px]:flex-row min-[480px]:justify-end sm:px-7">
                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    {t('devices.dialog.cancel')}
                </button>
                <button type="submit" className={`btn ${revoking ? 'btn-danger' : 'btn-primary'}`} disabled={form.processing}>
                    {form.processing
                        ? t(revoking ? 'devices.dialog.submitting_revoke' : 'devices.dialog.submitting_restore')
                        : t(revoking ? 'devices.dialog.submit_revoke' : 'devices.dialog.submit_restore')}
                </button>
            </footer>
        </form>
    );
}
