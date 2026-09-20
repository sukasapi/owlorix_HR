import { PasswordField } from '@/components/ui/PasswordField';
import AppShell from '@/layouts/AppShell';
import AuthLayout from '@/layouts/AuthLayout';
import { useT } from '@/lib/i18n';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function ChangePassword({ forced }: { forced: boolean }) {
    const t = useT();

    const form = (
        <PasswordForm
            forced={forced}
            heading={forced ? t('auth.password.forced_heading') : t('auth.password.heading')}
        />
    );

    if (forced) {
        return (
            <AuthLayout title={t('auth.password.title')} greeting={t('auth.password.forced_heading')} lead={t('auth.password.forced_lead')} eyes="attention">
                {form}
            </AuthLayout>
        );
    }

    return (
        <AppShell title={t('auth.password.title')}>
            <div className="max-w-[480px]">{form}</div>
        </AppShell>
    );
}

function PasswordForm({ forced, heading }: { forced: boolean; heading: string }) {
    const t = useT();
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('password.update'), { onFinish: () => form.reset() });
    };

    const pw = { showLabel: t('auth.sign_in.show_password'), hideLabel: t('auth.sign_in.hide_password') };

    return (
        <form onSubmit={submit} className="flex flex-col gap-[18px]" noValidate>
            <h1 className="h1">{heading}</h1>
            <PasswordField
                label={t('auth.password.current')}
                autoComplete="current-password"
                required
                autoFocus
                value={form.data.current_password}
                onChange={(e) => form.setData('current_password', e.target.value)}
                error={form.errors.current_password}
                {...pw}
            />
            <PasswordField
                label={t('auth.password.new')}
                autoComplete="new-password"
                required
                minLength={12}
                value={form.data.password}
                onChange={(e) => form.setData('password', e.target.value)}
                error={form.errors.password}
                help={t('auth.password.new_help')}
                {...pw}
            />
            <PasswordField
                label={t('auth.password.confirm')}
                autoComplete="new-password"
                required
                value={form.data.password_confirmation}
                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                error={form.errors.password_confirmation}
                {...pw}
            />
            <div className="flex flex-wrap items-center gap-3">
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    {form.processing ? t('common.actions.saving') : t('auth.password.submit')}
                </button>
                {forced && (
                    <Link href={route('sign-out')} method="post" as="button" className="btn btn-quiet">
                        {t('auth.password.sign_out_instead')}
                    </Link>
                )}
            </div>
        </form>
    );
}
