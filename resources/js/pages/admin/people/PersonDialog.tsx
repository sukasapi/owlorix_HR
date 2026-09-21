import { TextField } from '@/components/ui/Field';
import { Dialog } from '@/components/ui/Dialog';
import { Notice } from '@/components/ui/Notice';
import { useT } from '@/lib/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { Key, WarningCircle, X } from '@phosphor-icons/react';
import { type FormEvent, type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { ChoiceTile } from './ChoiceTile';
import type { EmploymentType, PersonRow, PersonStatus, RoleName, TeamOption } from './types';

export type PersonDialogMode = { kind: 'create' } | { kind: 'edit'; person: PersonRow };

interface Props {
    mode: PersonDialogMode | null;
    onClose: () => void;
    /** Called right before a request that returns a temporary password, so the page can title the credentials dialog. */
    onIssuing: (reason: 'created' | 'reset') => void;
    teams: TeamOption[];
    roles: RoleName[];
    statuses: PersonStatus[];
    employment_types: EmploymentType[];
    currentUserId: number;
    canManageTeams: boolean;
}

export function PersonDialog({ mode, onClose, ...rest }: Props) {
    const titleId = useId();

    return (
        <Dialog open={mode !== null} onClose={onClose} labelledBy={titleId} width="max-w-[660px]" closeOnBackdrop={false}>
            {mode && <PersonForm key={mode.kind === 'edit' ? mode.person.id : 'create'} mode={mode} titleId={titleId} onClose={onClose} {...rest} />}
        </Dialog>
    );
}

/** Suggests a username from the name (lowercase, accents removed, words joined with dots) until the field is edited by hand. */
function suggestUsername(name: string): string {
    return name
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '.')
        .replace(/^\.+|\.+$/g, '')
        .slice(0, 50);
}

type FormShape = {
    name: string;
    username: string;
    email: string;
    employee_code: string;
    employment_type: EmploymentType;
    roles: RoleName[];
    team_ids: number[];
    status: PersonStatus;
};

function PersonForm({ mode, titleId, onClose, onIssuing, teams, roles, statuses, employment_types, currentUserId, canManageTeams }: Omit<Props, 'mode'> & { mode: PersonDialogMode; titleId: string }) {
    const t = useT();
    const person = mode.kind === 'edit' ? mode.person : null;
    const isSelf = person?.id === currentUserId;
    const formRef = useRef<HTMLFormElement>(null);
    const usernameTouched = useRef(false);
    const [failed, setFailed] = useState(false);

    const form = useForm<FormShape>({
        name: person?.name ?? '',
        username: person?.username ?? '',
        email: person?.email ?? '',
        employee_code: person?.employee_code ?? '',
        employment_type: person?.employment_type ?? 'permanent',
        roles: person?.roles ?? ['employee'],
        team_ids: person?.team_ids ?? [],
        status: person?.status ?? 'active',
    });
    const errors = form.errors as Record<string, string | undefined>;

    // The dialog focuses its first control when it opens; move focus to the name field right after.
    useEffect(() => {
        const frame = requestAnimationFrame(() => formRef.current?.querySelector<HTMLInputElement>('input[name="name"]')?.focus());
        return () => cancelAnimationFrame(frame);
    }, []);

    const showFirstError = () =>
        requestAnimationFrame(() => formRef.current?.querySelector('[role="alert"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));

    const failHandlers = {
        onHttpException: () => {
            setFailed(true);
            return false;
        },
        onNetworkError: () => {
            setFailed(true);
            return false;
        },
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);
        const options = { preserveScroll: true, preserveState: true, onSuccess: onClose, onError: showFirstError, ...failHandlers };

        if (person) {
            form.put(route('admin.people.update', person.id), options);
        } else {
            onIssuing('created');
            form.post(route('admin.people.store'), options);
        }
    };

    const toggleRole = (role: RoleName, checked: boolean) =>
        form.setData('roles', checked ? [...form.data.roles, role] : form.data.roles.filter((r) => r !== role));
    const toggleTeam = (teamId: number, checked: boolean) =>
        form.setData('team_ids', checked ? [...form.data.team_ids, teamId] : form.data.team_ids.filter((id) => id !== teamId));

    const deactivating = person !== null && person.status === 'active' && form.data.status !== 'active';
    const heldSuperadmin = person?.roles.includes('superadmin') ?? false;

    return (
        <form ref={formRef} onSubmit={submit} noValidate className="flex max-h-[calc(100dvh-48px)] flex-col">
            <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                <div className="min-w-0">
                    <h2 id={titleId} className="h2 break-words">
                        {person ? t('people.form.edit_title', { name: person.name }) : t('people.form.create_title')}
                    </h2>
                    <p className="m-0 mt-1 text-sm text-muted">
                        {person ? t('people.form.username_fixed', { username: person.username }) : t('people.form.create_lead')}
                    </p>
                </div>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('people.form.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>

            <div className="flex flex-1 flex-col gap-[18px] overflow-y-auto px-5 py-5 sm:px-7">
                {failed && <Notice tone="danger">{t('people.form.failed')}</Notice>}
                {isSelf && <Notice>{t('people.form.self_note')}</Notice>}

                <TextField
                    label={t('people.form.name')}
                    name="name"
                    autoComplete="off"
                    required
                    maxLength={120}
                    value={form.data.name}
                    onChange={(e) => {
                        const name = e.target.value;
                        form.setData((data) => ({ ...data, name, username: !person && !usernameTouched.current ? suggestUsername(name) : data.username }));
                    }}
                    error={errors.name}
                />

                {!person && (
                    <TextField
                        label={t('people.form.username')}
                        name="username"
                        autoComplete="off"
                        autoCapitalize="none"
                        spellCheck={false}
                        required
                        maxLength={50}
                        value={form.data.username}
                        onChange={(e) => {
                            usernameTouched.current = true;
                            form.setData('username', e.target.value.toLowerCase());
                        }}
                        help={t('people.form.username_help')}
                        error={errors.username}
                    />
                )}

                <div className="grid gap-[18px] sm:grid-cols-2">
                    <TextField
                        label={t('people.form.email')}
                        name="email"
                        type="email"
                        autoComplete="off"
                        maxLength={190}
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        help={t('people.form.email_help')}
                        error={errors.email}
                    />
                    <TextField
                        label={t('people.form.employee_code')}
                        name="employee_code"
                        autoComplete="off"
                        maxLength={30}
                        value={form.data.employee_code}
                        onChange={(e) => form.setData('employee_code', e.target.value)}
                        help={t('people.form.employee_code_help')}
                        error={errors.employee_code}
                    />
                </div>

                <ChoiceGroup legend={t('people.form.employment_type')} help={t('people.form.employment_type_help')} error={errors.employment_type}>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {employment_types.map((type) => (
                            <ChoiceTile
                                key={type}
                                type="radio"
                                name="employment_type"
                                value={type}
                                label={t(`common.employment.${type}`)}
                                checked={form.data.employment_type === type}
                                onChange={() => form.setData('employment_type', type)}
                            />
                        ))}
                    </div>
                </ChoiceGroup>

                <ChoiceGroup legend={t('people.form.roles')} help={t('people.form.roles_help')} error={errors.roles ?? firstNested(errors, 'roles')}>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {roles.map((role) => (
                            <ChoiceTile
                                key={role}
                                type="checkbox"
                                name="roles[]"
                                value={role}
                                label={t(`common.roles.${role}`)}
                                checked={form.data.roles.includes(role)}
                                disabled={isSelf && role === 'superadmin' && heldSuperadmin}
                                onChange={(e) => toggleRole(role, e.target.checked)}
                            />
                        ))}
                    </div>
                </ChoiceGroup>

                <ChoiceGroup legend={t('people.form.teams')} help={teams.length > 0 ? t('people.form.teams_help') : undefined} error={errors.team_ids ?? firstNested(errors, 'team_ids')}>
                    {teams.length > 0 ? (
                        <div className="grid gap-2 sm:grid-cols-2">
                            {teams.map((team) => (
                                <ChoiceTile
                                    key={team.id}
                                    type="checkbox"
                                    name="team_ids[]"
                                    value={team.id}
                                    label={team.name}
                                    checked={form.data.team_ids.includes(team.id)}
                                    onChange={(e) => toggleTeam(team.id, e.target.checked)}
                                />
                            ))}
                        </div>
                    ) : (
                        <p className="m-0 text-sm text-muted">
                            {t('people.form.no_teams')}{' '}
                            {canManageTeams && (
                                <Link href={route('admin.teams.index')} className="link">
                                    {t('people.form.no_teams_link')}
                                </Link>
                            )}
                        </p>
                    )}
                </ChoiceGroup>

                {person && (
                    <ChoiceGroup legend={t('people.form.status')} error={errors.status}>
                        <div className="grid gap-2">
                            {statuses.map((status) => (
                                <ChoiceTile
                                    key={status}
                                    type="radio"
                                    name="status"
                                    value={status}
                                    label={t(`common.status.${status}`)}
                                    help={t(`people.form.status_help.${status}`)}
                                    checked={form.data.status === status}
                                    disabled={isSelf && person.status === 'active' && status !== 'active'}
                                    onChange={() => form.setData('status', status)}
                                />
                            ))}
                        </div>
                        {deactivating && (
                            <p className="m-0 mt-2 flex items-start gap-2 text-sm font-semibold text-gold-text">
                                <WarningCircle weight="bold" size={18} className="mt-px flex-none" aria-hidden />
                                {t('people.form.signs_out', { name: person.name })}
                            </p>
                        )}
                    </ChoiceGroup>
                )}

                {person && <ResetPassword person={person} dirty={form.isDirty} onIssuing={onIssuing} onDone={onClose} onFailed={() => setFailed(true)} />}
            </div>

            <footer className="flex flex-wrap items-center justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-7">
                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    {t('people.form.cancel')}
                </button>
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    {form.processing
                        ? person
                            ? t('common.actions.saving')
                            : t('people.form.submitting_create')
                        : person
                          ? t('people.form.submit_edit')
                          : t('people.form.submit_create')}
                </button>
            </footer>
        </form>
    );
}

function firstNested(errors: Record<string, string | undefined>, key: string): string | undefined {
    const match = Object.keys(errors).find((k) => k.startsWith(`${key}.`));
    return match ? errors[match] : undefined;
}

function ChoiceGroup({ legend, help, error, children }: { legend: string; help?: string; error?: string; children: ReactNode }) {
    const id = useId();

    return (
        <fieldset className="m-0 flex min-w-0 flex-col gap-2 border-0 p-0" aria-describedby={[help && `${id}-help`, error && `${id}-error`].filter(Boolean).join(' ') || undefined}>
            <legend className="label mb-2 p-0">{legend}</legend>
            {help && (
                <p id={`${id}-help`} className="help m-0 -mt-1">
                    {help}
                </p>
            )}
            {children}
            {error && (
                <p id={`${id}-error`} className="error-text m-0 flex items-center gap-1.5" role="alert">
                    <WarningCircle weight="bold" size={15} aria-hidden />
                    {error}
                </p>
            )}
        </fieldset>
    );
}

function ResetPassword({ person, dirty, onIssuing, onDone, onFailed }: { person: PersonRow; dirty: boolean; onIssuing: (reason: 'reset') => void; onDone: () => void; onFailed: () => void }) {
    const t = useT();
    const [step, setStep] = useState<'idle' | 'confirm' | 'working'>('idle');

    const issue = () => {
        onIssuing('reset');
        router.post(
            route('admin.people.reset-password', person.id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setStep('working'),
                onSuccess: onDone,
                onHttpException: () => {
                    setStep('confirm');
                    onFailed();
                    return false;
                },
                onNetworkError: () => {
                    setStep('confirm');
                    onFailed();
                    return false;
                },
            },
        );
    };

    return (
        <section className="flex flex-col gap-2.5 rounded-md border border-line px-4 py-4" aria-labelledby={`reset-${person.id}`}>
            <h3 id={`reset-${person.id}`} className="m-0 text-[15px] font-semibold">
                {t('people.reset.heading')}
            </h3>
            <p className="m-0 text-sm text-muted">{t('people.reset.body')}</p>

            {step === 'idle' && (
                <div className="flex flex-col items-start gap-1.5">
                    <button type="button" className="btn btn-secondary" disabled={dirty} onClick={() => setStep('confirm')}>
                        <Key weight="bold" size={18} aria-hidden />
                        {t('people.reset.button')}
                    </button>
                    {dirty && <p className="help m-0">{t('people.reset.dirty')}</p>}
                </div>
            )}

            {step !== 'idle' && (
                <div className="flex flex-col gap-2.5">
                    <p className="m-0 text-sm font-semibold" role="status">
                        {t('people.reset.confirm', { name: person.name })}
                    </p>
                    <div className="flex flex-wrap gap-2.5">
                        <button type="button" className="btn btn-primary" onClick={issue} disabled={step === 'working'} autoFocus>
                            {step === 'working' ? t('people.reset.working') : t('people.reset.confirm_button')}
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={() => setStep('idle')} disabled={step === 'working'}>
                            {t('people.form.cancel')}
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}
