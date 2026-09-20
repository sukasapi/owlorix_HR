import { SelectField, TextField } from '@/components/ui/Field';
import { Dialog } from '@/components/ui/Dialog';
import { Notice } from '@/components/ui/Notice';
import { useT } from '@/lib/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { Crown, Trash, UserMinus, UserPlus, X } from '@phosphor-icons/react';
import { type FormEvent, useEffect, useId, useRef, useState } from 'react';
import { StatusChip } from '../people/StatusChip';
import type { PersonOption, TeamRow } from './types';

interface Props {
    team: TeamRow | null;
    people: PersonOption[];
    canManageUsers: boolean;
    onClose: () => void;
}

export function TeamDialog({ team, people, canManageUsers, onClose }: Props) {
    const titleId = useId();

    return (
        <Dialog open={team !== null} onClose={onClose} labelledBy={titleId} width="max-w-[640px]" closeOnBackdrop={false}>
            {team && <TeamPanel key={team.id} team={team} people={people} canManageUsers={canManageUsers} titleId={titleId} onClose={onClose} />}
        </Dialog>
    );
}

const failOptions = (setFailed: (value: boolean) => void) => ({
    onHttpException: () => {
        setFailed(true);
        return false;
    },
    onNetworkError: () => {
        setFailed(true);
        return false;
    },
});

function TeamPanel({ team, people, canManageUsers, titleId, onClose }: { team: TeamRow; people: PersonOption[]; canManageUsers: boolean; titleId: string; onClose: () => void }) {
    const t = useT();
    const [failed, setFailed] = useState(false);
    const addSelect = useRef<HTMLDivElement>(null);
    const [removing, setRemoving] = useState<number | null>(null);

    const remove = (memberId: number) => {
        setFailed(false);
        router.delete(route('admin.teams.members.destroy', [team.id, memberId]), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setRemoving(memberId),
            onFinish: () => setRemoving(null),
            onSuccess: () => addSelect.current?.querySelector('select')?.focus(),
            ...failOptions(setFailed),
        });
    };

    return (
        <div className="flex max-h-[calc(100dvh-48px)] flex-col">
            <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                <div className="min-w-0">
                    <h2 id={titleId} className="h2 break-words">
                        {team.name}
                    </h2>
                    <p className="num m-0 mt-1 text-sm text-muted">{t('teams.member_count', { count: team.members.length })}</p>
                </div>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('teams.manage_dialog.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>

            <div className="flex flex-1 flex-col gap-6 overflow-y-auto px-5 py-5 sm:px-7">
                {failed && <Notice tone="danger">{t('teams.states.failed')}</Notice>}

                <DetailsForm team={team} canManageUsers={canManageUsers} onFailed={() => setFailed(true)} onBefore={() => setFailed(false)} />

                <section aria-labelledby={`members-${team.id}`} className="flex flex-col gap-3">
                    <h3 id={`members-${team.id}`} className="m-0 text-[15px] font-semibold">
                        {t('teams.manage_dialog.members')}
                    </h3>

                    {team.members.length === 0 ? (
                        <p className="m-0 text-sm text-muted">{t('teams.manage_dialog.no_members')}</p>
                    ) : (
                        <ul className="m-0 list-none divide-y divide-line rounded-md border border-line p-0">
                            {team.members.map((member) => (
                                <li key={member.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2.5">
                                    <span className="avatar" aria-hidden>
                                        {member.initials}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="m-0 font-semibold break-words">{member.name}</p>
                                        <p className="m-0 text-sm break-all text-muted">{member.username}</p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {team.lead_user_id === member.id && (
                                            <span className="chip chip-info">
                                                <Crown weight="bold" size={15} aria-hidden />
                                                {t('teams.manage_dialog.lead_chip')}
                                            </span>
                                        )}
                                        {member.status !== 'active' && <StatusChip status={member.status} />}
                                        <button
                                            type="button"
                                            className="btn btn-secondary btn-sm min-h-11"
                                            onClick={() => remove(member.id)}
                                            disabled={removing !== null}
                                            aria-label={t('teams.manage_dialog.remove_label', { name: member.name })}
                                        >
                                            <UserMinus weight="bold" size={16} aria-hidden />
                                            {t('teams.manage_dialog.remove')}
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div ref={addSelect}>
                        <AddMemberForm team={team} people={people} onFailed={() => setFailed(true)} onBefore={() => setFailed(false)} />
                    </div>
                </section>

                <DeleteTeam team={team} onDeleted={onClose} onFailed={() => setFailed(true)} />
            </div>
        </div>
    );
}

function DetailsForm({ team, canManageUsers, onFailed, onBefore }: { team: TeamRow; canManageUsers: boolean; onFailed: () => void; onBefore: () => void }) {
    const t = useT();
    const current = { name: team.name, lead_user_id: team.lead_user_id === null ? '' : String(team.lead_user_id) };
    const form = useForm(current);
    const eligible = team.members.filter((member) => member.can_lead);

    // Membership changes can clear the lead on the server; follow the saved values when they change.
    useEffect(() => {
        form.setDefaults(current);
        form.setData(current);
    }, [team.name, team.lead_user_id]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onBefore();
        form.transform((data) => ({ name: data.name, lead_user_id: data.lead_user_id === '' ? null : Number(data.lead_user_id) }));
        form.put(route('admin.teams.update', team.id), {
            preserveScroll: true,
            preserveState: true,
            onHttpException: () => {
                onFailed();
                return false;
            },
            onNetworkError: () => {
                onFailed();
                return false;
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-4" aria-labelledby={`details-${team.id}`}>
            <h3 id={`details-${team.id}`} className="m-0 text-[15px] font-semibold">
                {t('teams.manage_dialog.details')}
            </h3>
            <TextField
                label={t('teams.manage_dialog.name')}
                name="name"
                autoComplete="off"
                required
                maxLength={80}
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                error={form.errors.name}
            />
            <SelectField
                label={t('teams.manage_dialog.lead')}
                name="lead_user_id"
                value={form.data.lead_user_id}
                onChange={(e) => form.setData('lead_user_id', e.target.value)}
                error={form.errors.lead_user_id}
                help={
                    eligible.length > 0 ? (
                        t('teams.manage_dialog.lead_help')
                    ) : (
                        <>
                            {t('teams.manage_dialog.lead_help_empty')}{' '}
                            {canManageUsers && (
                                <Link href={route('admin.people.index')} className="link">
                                    {t('teams.manage_dialog.people_link')}
                                </Link>
                            )}
                        </>
                    )
                }
            >
                <option value="">{t('teams.manage_dialog.lead_none')}</option>
                {eligible.map((member) => (
                    <option key={member.id} value={member.id}>
                        {member.name}
                    </option>
                ))}
            </SelectField>
            <div>
                <button type="submit" className="btn btn-primary" disabled={form.processing || !form.isDirty}>
                    {form.processing ? t('teams.manage_dialog.saving') : t('teams.manage_dialog.save')}
                </button>
            </div>
        </form>
    );
}

function AddMemberForm({ team, people, onFailed, onBefore }: { team: TeamRow; people: PersonOption[]; onFailed: () => void; onBefore: () => void }) {
    const t = useT();
    const memberIds = new Set(team.members.map((member) => member.id));
    const candidates = people.filter((person) => !memberIds.has(person.id));
    const form = useForm({ user_id: '' });

    if (candidates.length === 0) {
        return <p className="m-0 text-sm text-muted">{t('teams.manage_dialog.everyone_in')}</p>;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onBefore();
        form.post(route('admin.teams.members.store', team.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.reset(),
            onHttpException: () => {
                onFailed();
                return false;
            },
            onNetworkError: () => {
                onFailed();
                return false;
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-2.5 sm:flex-row sm:items-end">
            <SelectField
                label={t('teams.manage_dialog.add_label')}
                name="user_id"
                className="min-w-0 flex-1"
                value={form.data.user_id}
                onChange={(e) => form.setData('user_id', e.target.value)}
                error={form.errors.user_id}
            >
                <option value="">{t('teams.manage_dialog.add_placeholder')}</option>
                {candidates.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.status === 'active' ? `${person.name} (${person.username})` : `${person.name} (${person.username}, ${t(`common.status.${person.status}`)})`}
                    </option>
                ))}
            </SelectField>
            <button type="submit" className="btn btn-secondary sm:mb-0" disabled={form.processing || form.data.user_id === ''}>
                <UserPlus weight="bold" size={18} aria-hidden />
                {t('teams.manage_dialog.add_submit')}
            </button>
        </form>
    );
}

function DeleteTeam({ team, onDeleted, onFailed }: { team: TeamRow; onDeleted: () => void; onFailed: () => void }) {
    const t = useT();
    const [step, setStep] = useState<'idle' | 'confirm' | 'working'>('idle');

    const destroy = () => {
        router.delete(route('admin.teams.destroy', team.id), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setStep('working'),
            onSuccess: onDeleted,
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
        });
    };

    return (
        <section aria-labelledby={`delete-${team.id}`} className="flex flex-col gap-2.5 border-t border-line pt-5">
            <h3 id={`delete-${team.id}`} className="m-0 text-[15px] font-semibold">
                {t('teams.manage_dialog.delete_heading')}
            </h3>
            <p className="m-0 text-sm text-muted">{t('teams.manage_dialog.delete_body')}</p>
            {step === 'idle' ? (
                <div>
                    <button type="button" className="btn btn-danger" onClick={() => setStep('confirm')}>
                        <Trash weight="bold" size={18} aria-hidden />
                        {t('teams.manage_dialog.delete_button')}
                    </button>
                </div>
            ) : (
                <div className="flex flex-col gap-2.5">
                    <p className="m-0 text-sm font-semibold" role="status">
                        {t('teams.manage_dialog.delete_confirm', { name: team.name })}
                    </p>
                    <div className="flex flex-wrap gap-2.5">
                        <button type="button" className="btn btn-danger" onClick={destroy} disabled={step === 'working'} autoFocus>
                            {step === 'working' ? t('teams.manage_dialog.deleting') : t('teams.manage_dialog.delete_confirm_button')}
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={() => setStep('idle')} disabled={step === 'working'}>
                            {t('teams.manage_dialog.cancel')}
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}
