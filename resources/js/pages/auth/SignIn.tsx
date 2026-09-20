import { TextField } from '@/components/ui/Field';
import { PasswordField } from '@/components/ui/PasswordField';
import AuthLayout from '@/layouts/AuthLayout';
import { useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function SignIn({ web_clock_in_enabled }: { web_clock_in_enabled: boolean }) {
    const t = useT();
    const form = useForm({ username: '', password: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('sign-in.store'), { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout title={t('auth.sign_in.title')} greeting={t('auth.sign_in.greeting')} lead={t(web_clock_in_enabled ? 'auth.sign_in.lead' : 'auth.sign_in.lead_desktop_only')}>
            <form onSubmit={submit} className="flex flex-col gap-[18px]" noValidate>
                <div>
                    <h1 className="h1">{t('auth.sign_in.heading')}</h1>
                    <p className="m-0 mt-1.5 text-muted">{t(web_clock_in_enabled ? 'auth.sign_in.web_does_not_clock_in' : 'auth.sign_in.web_does_not_clock_in_desktop_only')}</p>
                </div>
                <TextField
                    label={t('auth.sign_in.username')}
                    name="username"
                    autoComplete="username"
                    autoCapitalize="none"
                    spellCheck={false}
                    required
                    autoFocus
                    value={form.data.username}
                    onChange={(e) => form.setData('username', e.target.value)}
                    error={form.errors.username}
                />
                <PasswordField
                    label={t('auth.sign_in.password')}
                    name="password"
                    autoComplete="current-password"
                    required
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                    showLabel={t('auth.sign_in.show_password')}
                    hideLabel={t('auth.sign_in.hide_password')}
                />
                <button type="submit" className="btn btn-primary mt-1 w-full" disabled={form.processing}>
                    {form.processing ? t('auth.sign_in.submitting') : t('auth.sign_in.submit')}
                </button>
                <div className="h-px bg-line" />
                <p className="m-0 text-sm text-muted">{t('auth.sign_in.forgot')}</p>
            </form>
        </AuthLayout>
    );
}
