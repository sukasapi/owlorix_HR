import { Avatar } from '@/components/ui/Avatar';
import { SelectField, TextAreaField, TextField } from '@/components/ui/Field';
import AppShell from '@/layouts/AppShell';
import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { DownloadSimple, FilePdf, Trash, UploadSimple, WarningCircle } from '@phosphor-icons/react';
import { type FormEvent, type ReactNode, useId, useRef } from 'react';

interface Profile {
    name: string;
    nickname: string | null;
    job_title: string | null;
    phone: string | null;
    birth_place: string | null;
    birth_date: string | null;
    gender: 'male' | 'female' | null;
    address: string | null;
    emergency_contact_name: string | null;
    emergency_contact_relation: string | null;
    emergency_contact_phone: string | null;
    bio: string | null;
    portfolio_url: string | null;
    username: string;
    email: string | null;
    employee_code: string | null;
    employment_type: string;
    roles: string[];
    teams: string[];
    initials: string;
    photo_url: string | null;
    cv: { name: string | null; uploaded_at: string | null; url: string } | null;
}

interface PageProps {
    profile: Profile;
    limits: { photo_max_kb: number; cv_max_kb: number };
}

type FormFields = Record<
    'name' | 'nickname' | 'job_title' | 'phone' | 'birth_place' | 'birth_date' | 'gender' | 'address' | 'emergency_contact_name' | 'emergency_contact_relation' | 'emergency_contact_phone' | 'bio' | 'portfolio_url',
    string
>;

export default function ProfileEdit() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const { profile, limits } = props;

    return (
        <AppShell title={t('profile.title')}>
            <header className="max-w-[72ch]">
                <h1 className="h1">{t('profile.title')}</h1>
                <p className="m-0 mt-2 text-muted">{t('profile.lead')}</p>
            </header>

            <div className="mt-7 grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)] lg:items-start">
                <aside className="flex flex-col gap-5">
                    <PhotoCard profile={profile} maxKb={limits.photo_max_kb} />
                    <CvCard profile={profile} maxKb={limits.cv_max_kb} />
                    <AccountCard profile={profile} />
                </aside>
                <ProfileForm profile={profile} />
            </div>
        </AppShell>
    );
}

function PhotoCard({ profile, maxKb }: { profile: Profile; maxKb: number }) {
    const t = useT();
    const input = useRef<HTMLInputElement>(null);
    const headingId = useId();
    const form = useForm<{ photo: File | null }>({ photo: null });

    const upload = (file: File | undefined) => {
        if (!file) return;
        form.transform(() => ({ photo: file }));
        form.post(route('profile.photo.store'), {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                if (input.current) input.current.value = '';
            },
        });
    };

    const remove = () => {
        if (!window.confirm(t('profile.photo.remove_confirm'))) return;
        router.delete(route('profile.photo.destroy'), { preserveScroll: true });
    };

    return (
        <section className="brow px-5 py-6" aria-labelledby={headingId}>
            <h2 id={headingId} className="h2">
                {t('profile.photo.heading')}
            </h2>
            <div className="mt-4 flex items-center gap-4">
                <Avatar initials={profile.initials} photoUrl={profile.photo_url} className="h-24 w-24 text-[28px]" />
                <div className="flex min-w-0 flex-col gap-2">
                    <input
                        ref={input}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="sr-only"
                        tabIndex={-1}
                        onChange={(e) => upload(e.target.files?.[0])}
                        aria-hidden
                    />
                    <button type="button" className="btn btn-secondary btn-sm min-h-11 bg-surface" disabled={form.processing} onClick={() => input.current?.click()}>
                        <UploadSimple weight="bold" size={16} aria-hidden />
                        {form.processing ? t('profile.photo.uploading') : profile.photo_url ? t('profile.photo.replace') : t('profile.photo.upload')}
                    </button>
                    {profile.photo_url && (
                        <button type="button" className="btn btn-quiet btn-sm min-h-11 self-start" onClick={remove}>
                            <Trash weight="bold" size={16} aria-hidden />
                            {t('profile.photo.remove')}
                        </button>
                    )}
                </div>
            </div>
            <p className="help m-0 mt-3">{t('profile.photo.help', { mb: maxKb / 1024 })}</p>
            {form.errors.photo && <ErrorLine>{form.errors.photo}</ErrorLine>}
        </section>
    );
}

function CvCard({ profile, maxKb }: { profile: Profile; maxKb: number }) {
    const t = useT();
    const locale = useLocale();
    const input = useRef<HTMLInputElement>(null);
    const headingId = useId();
    const form = useForm<{ cv: File | null }>({ cv: null });

    const upload = (file: File | undefined) => {
        if (!file) return;
        form.transform(() => ({ cv: file }));
        form.post(route('profile.cv.store'), {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                if (input.current) input.current.value = '';
            },
        });
    };

    const remove = () => {
        if (!window.confirm(t('profile.cv.remove_confirm'))) return;
        router.delete(route('profile.cv.destroy'), { preserveScroll: true });
    };

    return (
        <section className="card px-5 py-5" aria-labelledby={headingId}>
            <h2 id={headingId} className="h2">
                {t('profile.cv.heading')}
            </h2>
            {profile.cv ? (
                <div className="mt-3 flex items-start gap-3">
                    <FilePdf weight="bold" size={28} aria-hidden className="mt-0.5 flex-none text-teal-text" />
                    <div className="min-w-0">
                        <p className="m-0 font-semibold break-all">{profile.cv.name}</p>
                        {profile.cv.uploaded_at && <p className="m-0 text-sm text-muted">{t('profile.cv.uploaded', { date: formatDateTime(profile.cv.uploaded_at, locale) })}</p>}
                    </div>
                </div>
            ) : (
                <p className="m-0 mt-3 text-muted">{t('profile.cv.empty')}</p>
            )}
            <input ref={input} type="file" accept="application/pdf" className="sr-only" tabIndex={-1} onChange={(e) => upload(e.target.files?.[0])} aria-hidden />
            <div className="mt-4 flex flex-wrap gap-2">
                <button type="button" className="btn btn-secondary btn-sm min-h-11" disabled={form.processing} onClick={() => input.current?.click()}>
                    <UploadSimple weight="bold" size={16} aria-hidden />
                    {form.processing ? t('profile.photo.uploading') : profile.cv ? t('profile.cv.replace') : t('profile.cv.upload')}
                </button>
                {profile.cv && (
                    <>
                        <a href={profile.cv.url} className="btn btn-secondary btn-sm min-h-11">
                            <DownloadSimple weight="bold" size={16} aria-hidden />
                            {t('profile.cv.download')}
                        </a>
                        <button type="button" className="btn btn-quiet btn-sm min-h-11" onClick={remove}>
                            <Trash weight="bold" size={16} aria-hidden />
                            {t('profile.cv.remove')}
                        </button>
                    </>
                )}
            </div>
            <p className="help m-0 mt-3">{t('profile.cv.help', { mb: maxKb / 1024 })}</p>
            {form.errors.cv && <ErrorLine>{form.errors.cv}</ErrorLine>}
        </section>
    );
}

/** Account data only Superadmin changes, shown so the person can check it. */
function AccountCard({ profile }: { profile: Profile }) {
    const t = useT();
    const headingId = useId();
    const none = <span className="text-muted">{t('profile.fields.not_set')}</span>;
    const rows: [string, ReactNode][] = [
        [t('profile.fields.username'), profile.username],
        [t('profile.fields.email'), profile.email ?? none],
        [t('profile.fields.employee_code'), profile.employee_code ?? none],
        [t('profile.fields.employment_type'), t(`common.employment.${profile.employment_type}`)],
        [t('profile.fields.roles'), profile.roles.map((role) => t(`common.roles.${role}`)).join(', ') || none],
        [t('profile.fields.teams'), profile.teams.join(', ') || none],
    ];

    return (
        <section className="card px-5 py-5" aria-labelledby={headingId}>
            <h2 id={headingId} className="m-0 text-base font-semibold">
                {t('profile.sections.account')}
            </h2>
            <dl className="m-0 mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                {rows.map(([label, value]) => (
                    <div key={label} className="contents">
                        <dt className="text-muted">{label}</dt>
                        <dd className="m-0 break-words">{value}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

function ProfileForm({ profile }: { profile: Profile }) {
    const t = useT();
    const form = useForm<FormFields>({
        name: profile.name ?? '',
        nickname: profile.nickname ?? '',
        job_title: profile.job_title ?? '',
        phone: profile.phone ?? '',
        birth_place: profile.birth_place ?? '',
        birth_date: profile.birth_date ?? '',
        gender: profile.gender ?? '',
        address: profile.address ?? '',
        emergency_contact_name: profile.emergency_contact_name ?? '',
        emergency_contact_relation: profile.emergency_contact_relation ?? '',
        emergency_contact_phone: profile.emergency_contact_phone ?? '',
        bio: profile.bio ?? '',
        portfolio_url: profile.portfolio_url ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('profile.update'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const bind = (field: keyof FormFields) => ({
        name: field,
        value: form.data[field],
        onChange: (e: { target: { value: string } }) => form.setData(field, e.target.value),
        error: form.errors[field],
    });

    return (
        <form onSubmit={submit} className="card flex flex-col px-5 py-6 sm:px-7">
            <Section title={t('profile.sections.identity')}>
                <TextField label={t('profile.fields.name')} help={t('profile.fields.name_help')} required maxLength={120} autoComplete="name" {...bind('name')} />
                <TextField label={t('profile.fields.nickname')} help={t('profile.fields.nickname_help')} maxLength={60} autoComplete="nickname" {...bind('nickname')} />
                <TextField label={t('profile.fields.job_title')} help={t('profile.fields.job_title_help')} maxLength={80} autoComplete="organization-title" {...bind('job_title')} />
            </Section>

            <Section title={t('profile.sections.personal')}>
                <TextField label={t('profile.fields.birth_place')} maxLength={80} {...bind('birth_place')} />
                <TextField label={t('profile.fields.birth_date')} type="date" autoComplete="bday" {...bind('birth_date')} />
                <SelectField label={t('profile.fields.gender')} {...bind('gender')}>
                    <option value="">{t('profile.fields.gender_none')}</option>
                    <option value="male">{t('profile.fields.gender_male')}</option>
                    <option value="female">{t('profile.fields.gender_female')}</option>
                </SelectField>
            </Section>

            <Section title={t('profile.sections.contact')}>
                <TextField label={t('profile.fields.phone')} type="tel" inputMode="tel" maxLength={30} autoComplete="tel" {...bind('phone')} />
                <TextAreaField className="sm:col-span-2" label={t('profile.fields.address')} rows={3} maxLength={500} autoComplete="street-address" {...bind('address')} />
            </Section>

            <Section title={t('profile.sections.emergency')}>
                <TextField label={t('profile.fields.emergency_name')} maxLength={120} {...bind('emergency_contact_name')} />
                <TextField label={t('profile.fields.emergency_relation')} help={t('profile.fields.emergency_relation_help')} maxLength={60} {...bind('emergency_contact_relation')} />
                <TextField label={t('profile.fields.emergency_phone')} type="tel" inputMode="tel" maxLength={30} {...bind('emergency_contact_phone')} />
            </Section>

            <Section title={t('profile.sections.about')} last>
                <TextAreaField className="sm:col-span-2" label={t('profile.fields.bio')} help={t('profile.fields.bio_help')} rows={3} maxLength={500} {...bind('bio')} />
                <TextField className="sm:col-span-2" label={t('profile.fields.portfolio_url')} help={t('profile.fields.portfolio_help')} type="url" inputMode="url" maxLength={300} placeholder="https://" {...bind('portfolio_url')} />
            </Section>

            <div className="sticky bottom-[calc(56px+env(safe-area-inset-bottom))] -mx-5 mt-2 flex justify-end border-t border-line bg-surface px-5 pt-4 pb-1 sm:-mx-7 sm:px-7 md:bottom-0">
                <button type="submit" className="btn btn-primary" disabled={form.processing || !form.isDirty}>
                    {form.processing ? t('common.actions.saving') : t('profile.save')}
                </button>
            </div>
        </form>
    );
}

function Section({ title, children, last = false }: { title: string; children: ReactNode; last?: boolean }) {
    const id = useId();

    return (
        <fieldset className={`m-0 min-w-0 border-0 p-0 ${last ? 'mb-4' : 'mb-7'}`} aria-labelledby={id}>
            <legend id={id} className="h2 mb-4 p-0 text-[20px]">
                {title}
            </legend>
            <div className="grid gap-[18px] sm:grid-cols-2">{children}</div>
        </fieldset>
    );
}

function ErrorLine({ children }: { children: ReactNode }) {
    return (
        <p className="error-text m-0 mt-2 flex items-center gap-1.5" role="alert">
            <WarningCircle weight="bold" size={15} aria-hidden />
            {children}
        </p>
    );
}
