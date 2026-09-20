import { SelectField, TextAreaField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { useT } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import { DoorOpen, User, UsersThree } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';
import type { CalendarDate, CalendarPageProps, OpenedDay, ScopeType } from './types';
import { useRequestError } from './useRequestError';

interface Props {
    day: CalendarDate;
    canOpen: boolean;
    scopes: CalendarPageProps['scopes'];
}

/** Teams and people this date is opened for, with closing and opening for Management and Superadmin. */
export function OpenedSection({ day, canOpen, scopes }: Props) {
    const t = useT();
    const headingId = useId();
    const [formOpen, setFormOpen] = useState(false);
    const hasRights = scopes.teams.length > 0 || scopes.people.length > 0;

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3 border-t border-line px-5 py-5 sm:px-7">
            <div className="flex flex-col gap-1">
                <h3 id={headingId} className="m-0 text-[16px] font-semibold">
                    {t('calendar.opened.heading')}
                </h3>
                <p className="m-0 text-[14px] text-muted">{t('calendar.opened.explain')}</p>
            </div>

            {day.opened.length === 0 ? (
                <p className="m-0 text-muted">{t('calendar.opened.none')}</p>
            ) : (
                <ul className="m-0 flex list-none flex-col gap-2 p-0">
                    {day.opened.map((opened) => (
                        <OpenedRow key={opened.id} day={day} opened={opened} />
                    ))}
                </ul>
            )}

            {canOpen &&
                (day.is_studio_workday ? (
                    <p className="m-0 text-[14px] text-muted">{t('calendar.opened.studio_workday')}</p>
                ) : !hasRights ? (
                    <Notice>{t('calendar.opened.no_rights')}</Notice>
                ) : formOpen ? (
                    <OpenForm day={day} scopes={scopes} onDone={() => setFormOpen(false)} />
                ) : (
                    <div>
                        <button type="button" className="btn btn-primary" onClick={() => setFormOpen(true)}>
                            <DoorOpen weight="bold" size={18} aria-hidden />
                            {t('calendar.opened.start')}
                        </button>
                    </div>
                ))}
        </section>
    );
}

function OpenedRow({ day, opened }: { day: CalendarDate; opened: OpenedDay }) {
    const t = useT();
    const request = useRequestError();
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const Icon = opened.scope_type === 'team' ? UsersThree : User;
    const scopeLabel = opened.scope_type === 'team' ? t('calendar.opened.team', { name: opened.scope_name }) : opened.scope_name;

    const close = () => {
        router.delete(route('calendar.opened.destroy', opened.id), {
            preserveScroll: true,
            preserveState: true,
            ...request.handlers,
            onStart: () => {
                request.clear();
                setProcessing(true);
            },
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <li className="card flex flex-col gap-2 px-4 py-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex min-w-0 items-start gap-2.5">
                    <Icon weight="bold" size={20} className="mt-0.5 flex-none" aria-hidden />
                    <div className="min-w-0">
                        <p className="m-0 font-semibold [overflow-wrap:anywhere]">{scopeLabel}</p>
                        <p className="m-0 text-[13px] text-muted">{t('calendar.opened.by', { name: opened.opened_by })}</p>
                    </div>
                </div>
                {opened.can_close && !confirming && (
                    <button type="button" className="btn btn-secondary btn-sm" onClick={() => setConfirming(true)}>
                        {t('calendar.opened.close')}
                    </button>
                )}
            </div>

            {opened.note && <p className="m-0 text-[14px] whitespace-pre-line [overflow-wrap:anywhere]">{opened.note}</p>}

            {confirming && (
                <div className="flex flex-col gap-3 border-t border-line pt-3">
                    <p className="m-0 font-semibold">{t('calendar.opened.close_confirm', { scope: scopeLabel })}</p>
                    {day.is_past && <Notice>{t('calendar.day.past_warning')}</Notice>}
                    {request.error && <Notice tone="danger">{request.error}</Notice>}
                    <div className="flex flex-wrap items-center gap-3">
                        <button type="button" className="btn btn-danger btn-sm" onClick={close} disabled={processing}>
                            {processing ? t('calendar.opened.closing') : t('calendar.opened.close_yes')}
                        </button>
                        <button type="button" className="btn btn-quiet btn-sm" onClick={() => setConfirming(false)} disabled={processing}>
                            {t('calendar.opened.cancel')}
                        </button>
                    </div>
                </div>
            )}
        </li>
    );
}

function OpenForm({ day, scopes, onDone }: { day: CalendarDate; scopes: CalendarPageProps['scopes']; onDone: () => void }) {
    const t = useT();
    const request = useRequestError();

    // Scopes already open on this date would be refused, so they are not offered.
    const openTeamIds = new Set(day.opened.filter((o) => o.scope_type === 'team').map((o) => o.scope_id));
    const openUserIds = new Set(day.opened.filter((o) => o.scope_type === 'user').map((o) => o.scope_id));
    const teams = scopes.teams.filter((team) => !openTeamIds.has(team.id));
    const people = scopes.people.filter((person) => !openUserIds.has(person.id));

    const form = useForm<{ date: string; scope_type: ScopeType; scope_id: string; note: string }>({
        date: day.date,
        scope_type: scopes.teams.length > 0 ? 'team' : 'user',
        scope_id: '',
        note: '',
    });

    const choices = form.data.scope_type === 'team' ? teams : people;
    const hasBothScopes = scopes.teams.length > 0 && scopes.people.length > 0;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('calendar.opened.store'), {
            preserveScroll: true,
            preserveState: true,
            ...request.handlers,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="card flex flex-col gap-4 px-4 py-4" noValidate>
            <p className="m-0 font-semibold">{t('calendar.opened.form_heading')}</p>

            {hasBothScopes && (
                <fieldset className="m-0 border-0 p-0">
                    <legend className="label mb-2 p-0">{t('calendar.opened.scope')}</legend>
                    <div className="flex flex-wrap gap-2">
                        {(['team', 'user'] as ScopeType[]).map((scope) => (
                            <label
                                key={scope}
                                className={`flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-sm border-[1.5px] px-3 font-semibold ${
                                    form.data.scope_type === scope ? 'border-[var(--primary-bg)] bg-panel' : 'border-line-strong bg-surface'
                                }`}
                            >
                                <input
                                    type="radio"
                                    name="opened-scope"
                                    value={scope}
                                    checked={form.data.scope_type === scope}
                                    onChange={() => form.setData({ ...form.data, scope_type: scope, scope_id: '' })}
                                    className="h-[18px] w-[18px] flex-none cursor-pointer accent-[var(--primary-bg)]"
                                />
                                {scope === 'team' ? t('calendar.opened.scope_team') : t('calendar.opened.scope_person')}
                            </label>
                        ))}
                    </div>
                </fieldset>
            )}

            {choices.length === 0 ? (
                <p className="m-0 text-muted">
                    {form.data.scope_type === 'team'
                        ? t(scopes.teams.length === 0 ? 'calendar.opened.no_teams' : 'calendar.opened.all_teams_opened')
                        : t(scopes.people.length === 0 ? 'calendar.opened.no_people' : 'calendar.opened.all_people_opened')}
                </p>
            ) : (
                <SelectField
                    label={form.data.scope_type === 'team' ? t('calendar.opened.team_field') : t('calendar.opened.person_field')}
                    required
                    value={form.data.scope_id}
                    onChange={(e) => form.setData('scope_id', e.target.value)}
                    error={form.errors.scope_id ?? form.errors.scope_type}
                >
                    <option value="" disabled>
                        {form.data.scope_type === 'team' ? t('calendar.opened.choose_team') : t('calendar.opened.choose_person')}
                    </option>
                    {form.data.scope_type === 'team'
                        ? teams.map((team) => (
                              <option key={team.id} value={team.id}>
                                  {t('calendar.opened.team_option', { name: team.name, count: team.member_count })}
                              </option>
                          ))
                        : people.map((person) => (
                              <option key={person.id} value={person.id}>
                                  {t('calendar.opened.person_option', { name: person.name, username: person.username })}
                              </option>
                          ))}
                </SelectField>
            )}

            <TextAreaField
                label={t('calendar.opened.note')}
                maxLength={255}
                value={form.data.note}
                onChange={(e) => form.setData('note', e.target.value)}
                error={form.errors.note}
                help={t('calendar.opened.note_help')}
            />

            {day.is_past && <Notice>{t('calendar.day.past_warning')}</Notice>}
            {form.errors.date && <Notice tone="danger">{form.errors.date}</Notice>}
            {request.error && <Notice tone="danger">{request.error}</Notice>}

            <div className="flex flex-wrap items-center gap-3">
                <button type="submit" className="btn btn-primary" disabled={form.processing || choices.length === 0}>
                    {form.processing ? t('calendar.opened.submitting') : t('calendar.opened.submit')}
                </button>
                <button type="button" className="btn btn-quiet" onClick={onDone} disabled={form.processing}>
                    {t('calendar.opened.cancel')}
                </button>
            </div>
        </form>
    );
}
