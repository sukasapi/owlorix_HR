import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { formatDateTime } from '@/lib/format';
import { type Translate, useLocale, useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { ArrowCounterClockwise, CheckCircle, Globe, Info, WarningCircle } from '@phosphor-icons/react';
import { type FormEvent, useId, useRef, useState } from 'react';

type Group = 'work_hours' | 'overtime' | 'idle' | 'desktop_sync' | 'web';
type Unit = 'minutes' | 'hours' | 'seconds' | 'days';

interface SettingField {
    group: Group;
    key: string;
    type: 'integer' | 'boolean';
    unit: Unit | null;
    min: number | null;
    max: number | null;
    rule: string;
    applies: 'new_shifts' | 'calculation' | 'desktop' | 'web';
    value: number | boolean;
    default: number | boolean;
    changed_by: string | null;
    changed_at: string | null;
}

interface PageProps {
    fields: SettingField[];
    timezone: string;
}

type Values = Record<string, string | boolean>;

const GROUPS: Group[] = ['work_hours', 'overtime', 'idle', 'desktop_sync', 'web'];

const toInput = (field: SettingField): string | boolean => (field.type === 'boolean' ? Boolean(field.value) : String(field.value));

/** The value as the server would read it, so "0480" and 480 compare equal; null when not a whole number. */
function parsed(field: SettingField, value: string | boolean): number | boolean | null {
    if (field.type === 'boolean') return Boolean(value);
    const text = String(value).trim();
    return /^\d{1,6}$/.test(text) ? Number(text) : null;
}

function withUnit(t: Translate, field: SettingField, value: number | boolean): string {
    if (field.type === 'boolean') return value ? t('settings.on') : t('settings.off');
    return `${value} ${t(`settings.units.${field.unit}`)}`;
}

export default function SettingsEdit() {
    const { props } = usePage<SharedProps & PageProps>();
    const { fields, timezone } = props;
    const t = useT();
    const formRef = useRef<HTMLFormElement>(null);
    const [status, setStatus] = useState<'idle' | 'saved' | 'failed' | 'invalid'>('idle');

    const form = useForm<{ values: Values }>({ values: Object.fromEntries(fields.map((f) => [f.key, toInput(f)])) });
    const errors = form.errors as Record<string, string | undefined>;
    const values = form.data.values;

    const dirty = fields.filter((field) => parsed(field, values[field.key]) !== field.value);

    const setValue = (key: string, value: string | boolean) => {
        setStatus('idle');
        form.setData('values', { ...form.data.values, [key]: value });
        form.clearErrors(key as never);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setStatus('idle');

        form.put(route('admin.settings.update'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setStatus('saved'),
            onError: () => {
                setStatus('invalid');
                requestAnimationFrame(() => formRef.current?.querySelector('[aria-invalid="true"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
            },
            onHttpException: () => {
                setStatus('failed');
                return false;
            },
            onNetworkError: () => {
                setStatus('failed');
                return false;
            },
        });
    };

    const discard = () => {
        setStatus('idle');
        form.clearErrors();
        form.setData('values', Object.fromEntries(fields.map((f) => [f.key, toInput(f)])));
    };

    const errorFor = (field: SettingField): string | undefined => {
        const code = errors[field.key];
        if (!code) return undefined;
        if (!/^[a-z_]+$/.test(code)) return code;
        return t(`settings.errors.${code}`, {
            min: field.min ?? '',
            max: field.max ?? '',
            unit: field.unit ? t(`settings.units.${field.unit}`) : '',
            value: String(code === 'upload_below_local' ? values['sync.heartbeat_local_seconds'] : values['attendance.prompt_auto_close_minutes']),
        });
    };

    return (
        <AppShell title={t('settings.title')}>
            <div className="flex max-w-[860px] flex-col gap-1">
                <h1 className="h1">{t('settings.title')}</h1>
                <p className="m-0 max-w-[70ch] text-muted">{t('settings.intro')}</p>
            </div>

            <div className="mt-[18px] flex max-w-[860px] flex-col gap-3">
                <Notice>
                    <p className="m-0 font-semibold">{t('settings.applies_title')}</p>
                    <p className="m-0 mt-1 font-normal">{t('settings.applies_body')}</p>
                </Notice>
            </div>

            <form ref={formRef} onSubmit={submit} noValidate className="mt-5 flex max-w-[860px] flex-col gap-5">
                {GROUPS.map((group) => (
                    <section key={group} className="card" aria-labelledby={`settings-group-${group}`}>
                        <header className="border-b border-line px-5 pt-4 pb-3.5 sm:px-6">
                            <h2 id={`settings-group-${group}`} className="h2">
                                {t(`settings.groups.${group}`)}
                            </h2>
                            <p className="m-0 mt-1 text-sm text-muted">{t(`settings.group_intro.${group}`)}</p>
                        </header>
                        <div className="divide-y divide-line">
                            {group === 'work_hours' && <TimezoneRow timezone={timezone} />}
                            {fields
                                .filter((field) => field.group === group)
                                .map((field) => (
                                    <FieldRow key={field.key} field={field} value={values[field.key]} error={errorFor(field)} onChange={(value) => setValue(field.key, value)} />
                                ))}
                        </div>
                    </section>
                ))}

                {/* The bar floats over the form while there is something to save, and stays to confirm a save where the person is looking */}
                {(dirty.length > 0 || status !== 'idle' || form.processing) && (
                    <div className="floating sticky bottom-[calc(64px+env(safe-area-inset-bottom))] z-20 flex flex-col gap-3 rounded-xl border border-line bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between lg:bottom-4">
                        <div className="min-w-0 text-sm" aria-live="polite">
                            {status === 'saved' && dirty.length === 0 ? (
                                <span className="flex items-start gap-2 font-medium text-success">
                                    <CheckCircle weight="bold" size={18} aria-hidden className="mt-px flex-none" />
                                    {t('settings.saved')}
                                </span>
                            ) : status === 'failed' ? (
                                <span className="flex items-start gap-2 font-medium text-danger">
                                    <WarningCircle weight="bold" size={18} aria-hidden className="mt-px flex-none" />
                                    {t('settings.failed')}
                                </span>
                            ) : status === 'invalid' && Object.keys(errors).length > 0 ? (
                                <span className="flex items-start gap-2 font-medium text-danger">
                                    <WarningCircle weight="bold" size={18} aria-hidden className="mt-px flex-none" />
                                    {t('settings.has_errors')}
                                </span>
                            ) : (
                                <span className="num font-semibold">{t(dirty.length === 1 ? 'settings.unsaved_one' : 'settings.unsaved_other', { count: dirty.length })}</span>
                            )}
                        </div>
                        {(dirty.length > 0 || form.processing) && (
                            <div className="flex flex-col-reverse gap-2 min-[420px]:flex-row">
                                <button type="button" className="btn btn-secondary" onClick={discard} disabled={form.processing}>
                                    {t('settings.discard')}
                                </button>
                                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                                    {form.processing ? t('settings.saving') : t('settings.save')}
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </form>
        </AppShell>
    );
}

function TimezoneRow({ timezone }: { timezone: string }) {
    const t = useT();

    return (
        <div className="grid gap-3 px-5 py-4 sm:px-6 md:grid-cols-[minmax(0,1fr)_240px] md:gap-6">
            <div className="min-w-0">
                <p className="label m-0">{t('settings.timezone.label')}</p>
                <p className="help m-0 mt-1">{t('settings.timezone.help')}</p>
            </div>
            <p className="m-0 flex items-center gap-2 font-semibold">
                <Globe weight="bold" size={18} aria-hidden className="flex-none text-muted" />
                {timezone}
            </p>
        </div>
    );
}

function FieldRow({ field, value, error, onChange }: { field: SettingField; value: string | boolean; error?: string; onChange: (value: string | boolean) => void }) {
    const t = useT();
    const locale = useLocale();
    const id = useId();
    const label = t(`settings.fields.${field.key}.label`);
    const current = parsed(field, value);
    const isDefault = current === field.default;
    const defaultText = withUnit(t, field, field.default);
    const helpId = `${id}-help`;
    const errorId = `${id}-error`;
    const describedBy = [helpId, error ? errorId : null].filter(Boolean).join(' ');

    return (
        <div className="grid gap-3 px-5 py-4 sm:px-6 md:grid-cols-[minmax(0,1fr)_240px] md:gap-6">
            <div className="min-w-0">
                <label htmlFor={id} className="label">
                    {label}
                </label>
                <p id={helpId} className="help m-0 mt-1 max-w-[62ch]">
                    {t(`settings.fields.${field.key}.help`)} {t('settings.rule', { rule: field.rule })}.
                    {field.type === 'integer' && <> {t('settings.range', { min: field.min ?? '', max: field.max ?? '', unit: t(`settings.units.${field.unit}`) })}</>}
                </p>
                <p className="m-0 mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-muted">
                    {field.applies === 'new_shifts' ? (
                        <span className="chip chip-info">
                            <Info weight="bold" size={14} aria-hidden />
                            {t('settings.applies.new_shifts')}
                        </span>
                    ) : (
                        <span>{t(`settings.applies.${field.applies}`)}</span>
                    )}
                    <span className="num">
                        {field.changed_at
                            ? field.changed_by
                                ? t('settings.changed_by', { name: field.changed_by, time: formatDateTime(field.changed_at, locale) })
                                : t('settings.changed_at', { time: formatDateTime(field.changed_at, locale) })
                            : t('settings.never_changed')}
                    </span>
                </p>
            </div>

            <div className="flex min-w-0 flex-col gap-1.5">
                {field.type === 'boolean' ? (
                    <button
                        id={id}
                        type="button"
                        role="switch"
                        aria-checked={Boolean(value)}
                        aria-describedby={describedBy}
                        onClick={() => onChange(!value)}
                        className="flex min-h-11 items-center gap-3 self-start rounded-sm px-1 font-semibold"
                    >
                        <span aria-hidden className={`relative inline-flex h-7 w-12 flex-none rounded-full border-[1.5px] transition-colors ${value ? 'border-transparent bg-[var(--primary-bg)]' : 'border-line-strong bg-paper'}`}>
                            <span className={`absolute top-1/2 size-5 -translate-y-1/2 rounded-full transition-[left] ${value ? 'left-[22px] bg-[var(--primary-fg)]' : 'left-[3px] bg-muted'}`} />
                        </span>
                        {value ? t('settings.on') : t('settings.off')}
                    </button>
                ) : (
                    <div className="flex items-center gap-2">
                        <input
                            id={id}
                            type="number"
                            inputMode="numeric"
                            className="input num w-[120px] text-right"
                            min={field.min ?? undefined}
                            max={field.max ?? undefined}
                            step={1}
                            value={String(value)}
                            onChange={(e) => onChange(e.target.value)}
                            aria-invalid={Boolean(error)}
                            aria-describedby={describedBy}
                        />
                        <span className="text-sm text-muted">{t(`settings.units.${field.unit}`)}</span>
                    </div>
                )}

                {error && (
                    <p id={errorId} className="error-text m-0 flex items-start gap-1.5" role="alert">
                        <WarningCircle weight="bold" size={15} aria-hidden className="mt-px flex-none" />
                        {error}
                    </p>
                )}

                <p className="m-0 text-[13px] text-muted">
                    <span className="num">{t('settings.default', { value: defaultText })}</span>
                    {!isDefault && (
                        <button type="button" className="btn btn-quiet ml-1 min-h-11 px-1.5 text-[13px]" onClick={() => onChange(toInput({ ...field, value: field.default }))} aria-label={t('settings.reset_label', { label, value: defaultText })}>
                            <ArrowCounterClockwise weight="bold" size={14} aria-hidden />
                            {t('settings.reset')}
                        </button>
                    )}
                </p>

                {field.applies === 'new_shifts' && current !== field.value && current !== null && (
                    <p className="m-0 text-[13px] font-medium">{t('settings.new_shifts_note', { value: withUnit(t, field, field.value) })}</p>
                )}
            </div>
        </div>
    );
}
