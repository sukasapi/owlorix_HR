import { TextField } from '@/components/ui/Field';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowCounterClockwise, UploadSimple, WarningCircle } from '@phosphor-icons/react';
import { type FormEvent, useId, useRef } from 'react';

type Key = 'app_name' | 'studio_name' | 'footer_text' | 'footer_link_label' | 'footer_link_url' | 'contact_email';

interface PageProps {
    values: Record<Key, string>;
    defaults: Record<Key, string>;
    logo_url: string;
    custom_logo: boolean;
    logo_max_kb: number;
}

export default function AppSettingsEdit() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();

    return (
        <AppShell title={t('app-settings.title')}>
            <header className="max-w-[72ch]">
                <h1 className="h1">{t('app-settings.title')}</h1>
                <p className="m-0 mt-2 text-muted">{t('app-settings.lead')}</p>
            </header>
            <div className="mt-7 grid gap-6 lg:grid-cols-[320px_minmax(0,1fr)] lg:items-start">
                <LogoCard url={props.logo_url} custom={props.custom_logo} maxKb={props.logo_max_kb} />
                <TextSettings values={props.values} defaults={props.defaults} />
            </div>
        </AppShell>
    );
}

function LogoCard({ url, custom, maxKb }: { url: string; custom: boolean; maxKb: number }) {
    const t = useT();
    const input = useRef<HTMLInputElement>(null);
    const headingId = useId();
    const form = useForm<{ logo: File | null }>({ logo: null });

    const upload = (file: File | undefined) => {
        if (!file) return;
        form.transform(() => ({ logo: file }));
        form.post(route('admin.app-settings.logo.store'), {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                if (input.current) input.current.value = '';
            },
        });
    };

    const reset = () => {
        if (!window.confirm(t('app-settings.logo.reset_confirm'))) return;
        router.delete(route('admin.app-settings.logo.destroy'), { preserveScroll: true });
    };

    return (
        <section className="brow px-5 py-6" aria-labelledby={headingId}>
            <h2 id={headingId} className="h2">
                {t('app-settings.logo.heading')}
            </h2>
            {/* White tile as in the menu: the bundled logo has black lettering on white */}
            <div className="mt-4 flex items-center gap-4">
                <img src={url} alt="" width={96} height={96} className="size-24 flex-none rounded-md bg-white object-contain" />
                <p className="m-0 text-sm">{custom ? t('app-settings.logo.custom') : t('app-settings.logo.bundled')}</p>
            </div>
            <input ref={input} type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" tabIndex={-1} aria-hidden onChange={(e) => upload(e.target.files?.[0])} />
            <div className="mt-4 flex flex-wrap gap-2">
                <button type="button" className="btn btn-secondary btn-sm min-h-11 bg-surface" disabled={form.processing} onClick={() => input.current?.click()}>
                    <UploadSimple weight="bold" size={16} aria-hidden />
                    {form.processing ? t('app-settings.logo.uploading') : t('app-settings.logo.upload')}
                </button>
                {custom && (
                    <button type="button" className="btn btn-quiet btn-sm min-h-11" onClick={reset}>
                        <ArrowCounterClockwise weight="bold" size={16} aria-hidden />
                        {t('app-settings.logo.reset')}
                    </button>
                )}
            </div>
            <p className="help m-0 mt-3">{t('app-settings.logo.help', { kb: maxKb })}</p>
            {form.errors.logo && (
                <p className="error-text m-0 mt-2 flex items-center gap-1.5" role="alert">
                    <WarningCircle weight="bold" size={15} aria-hidden />
                    {form.errors.logo}
                </p>
            )}
        </section>
    );
}

function TextSettings({ values, defaults }: { values: Record<Key, string>; defaults: Record<Key, string> }) {
    const t = useT();
    const form = useForm<Record<Key, string>>({ ...values });
    const namesId = useId();
    const footerId = useId();
    const previewId = useId();
    const d = form.data;
    const hasLink = d.footer_link_label.trim() !== '' && d.footer_link_url.trim() !== '';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('admin.app-settings.update'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const bind = (key: Key) => ({
        name: key,
        value: d[key],
        onChange: (e: { target: { value: string } }) => form.setData(key, e.target.value),
        error: form.errors[key],
    });

    const helpWithDefault = (help: string, key: Key) => (
        <>
            {help}
            {defaults[key] !== '' && defaults[key] !== d[key] && <> {t('app-settings.default_value', { value: defaults[key] })}</>}
        </>
    );

    return (
        <form onSubmit={submit} className="card flex flex-col gap-7 px-5 py-6 sm:px-7">
            <fieldset className="m-0 min-w-0 border-0 p-0" aria-labelledby={namesId}>
                <legend id={namesId} className="h2 mb-4 p-0 text-[20px]">
                    {t('app-settings.names')}
                </legend>
                <div className="grid gap-[18px] sm:grid-cols-2">
                    <TextField label={t('app-settings.fields.app_name')} help={helpWithDefault(t('app-settings.fields.app_name_help'), 'app_name')} required maxLength={40} {...bind('app_name')} />
                    <TextField label={t('app-settings.fields.studio_name')} help={helpWithDefault(t('app-settings.fields.studio_name_help'), 'studio_name')} required maxLength={80} {...bind('studio_name')} />
                </div>
            </fieldset>

            <fieldset className="m-0 min-w-0 border-0 p-0" aria-labelledby={footerId}>
                <legend id={footerId} className="h2 mb-4 p-0 text-[20px]">
                    {t('app-settings.footer')}
                </legend>
                <div className="grid gap-[18px] sm:grid-cols-2">
                    <TextField className="sm:col-span-2" label={t('app-settings.fields.footer_text')} help={t('app-settings.fields.footer_text_help')} maxLength={120} {...bind('footer_text')} />
                    <TextField label={t('app-settings.fields.footer_link_label')} help={t('app-settings.fields.footer_link_help')} maxLength={60} {...bind('footer_link_label')} />
                    <TextField label={t('app-settings.fields.footer_link_url')} type="url" inputMode="url" placeholder="https://" maxLength={300} {...bind('footer_link_url')} />
                    <TextField className="sm:col-span-2" label={t('app-settings.fields.contact_email')} help={t('app-settings.fields.contact_email_help')} type="email" inputMode="email" maxLength={190} {...bind('contact_email')} />
                </div>
            </fieldset>

            <section aria-labelledby={previewId} className="rounded-md border border-dashed border-line-strong px-4 py-3">
                <h3 id={previewId} className="m-0 text-sm font-semibold text-muted">
                    {t('app-settings.preview')}
                </h3>
                <p className="m-0 mt-2 text-sm font-semibold break-words text-ink">
                    {d.app_name}
                    <span className="font-normal text-muted">
                        {` · ${d.studio_name}`}
                        {(d.footer_text.trim() !== '' || hasLink) && ' · '}
                        {d.footer_text}
                        {hasLink && <span className="link">{`${d.footer_text.trim() !== '' ? ' ' : ''}${d.footer_link_label}`}</span>}
                    </span>
                </p>
                <p className="m-0 mt-1 text-[13px] break-words text-muted">
                    {d.contact_email.trim() !== '' && `${d.contact_email} · `}
                    {t('common.shell.footer_copy', { year: new Date().getFullYear(), studio: d.studio_name })}
                </p>
            </section>

            <div className="flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p className="help m-0">{t('app-settings.reload_note')}</p>
                <button type="submit" className="btn btn-primary" disabled={form.processing || !form.isDirty}>
                    {form.processing ? t('common.actions.saving') : t('app-settings.save')}
                </button>
            </div>
        </form>
    );
}
