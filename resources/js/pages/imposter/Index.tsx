import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { UserSwitch } from '@phosphor-icons/react';
import { useState } from 'react';

interface Person {
    id: number;
    name: string;
    username: string;
    initials: string;
    roles: string[];
}

interface PageProps {
    people: Person[];
}

export default function ImposterIndex() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const [confirmId, setConfirmId] = useState<number | null>(null);
    const [working, setWorking] = useState(false);

    const start = (person: Person) => {
        setWorking(true);
        router.post(route('imposter.start', person.id), {}, {
            onFinish: () => setWorking(false),
            onError: () => setConfirmId(null),
        });
    };

    return (
        <AppShell title={t('imposter.title')}>
            <h1 className="h1">{t('imposter.title')}</h1>
            <p className="m-0 mt-1 max-w-[60ch] text-muted">{t('imposter.lead')}</p>

            {props.people.length === 0 ? (
                <section className="card mt-[18px] flex flex-col gap-2 px-5 py-6">
                    <h2 className="h2">{t('imposter.empty_title')}</h2>
                    <p className="m-0 text-muted">{t('imposter.empty_body')}</p>
                </section>
            ) : (
                <>
                    <div className="table-wrap mt-[18px] hidden md:block">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th scope="col">{t('imposter.columns.name')}</th>
                                    <th scope="col">{t('imposter.columns.username')}</th>
                                    <th scope="col">{t('imposter.columns.roles')}</th>
                                    <th scope="col">
                                        <span className="sr-only">{t('imposter.columns.actions')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {props.people.map((person) => (
                                    <tr key={person.id}>
                                        <td>
                                            <span className="flex items-center gap-2.5">
                                                <span className="avatar" aria-hidden>
                                                    {person.initials}
                                                </span>
                                                <b className="font-semibold">{person.name}</b>
                                            </span>
                                        </td>
                                        <td className="break-all">{person.username}</td>
                                        <td>{person.roles.map((role) => t(`common.roles.${role}`)).join(', ')}</td>
                                        <td className="text-right">
                                            {confirmId === person.id ? (
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    <button type="button" className="btn btn-primary btn-sm min-h-11" disabled={working} onClick={() => start(person)}>
                                                        {t('imposter.confirm_button')}
                                                    </button>
                                                    <button type="button" className="btn btn-secondary btn-sm min-h-11" disabled={working} onClick={() => setConfirmId(null)}>
                                                        {t('common.actions.cancel')}
                                                    </button>
                                                </div>
                                            ) : (
                                                <button
                                                    type="button"
                                                    className="btn btn-secondary btn-sm min-h-11"
                                                    onClick={() => setConfirmId(person.id)}
                                                    aria-label={t('imposter.start_label', { name: person.name })}
                                                >
                                                    <UserSwitch weight="bold" size={16} aria-hidden />
                                                    {t('imposter.start')}
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="card mt-[18px] m-0 list-none divide-y divide-line p-0 md:hidden">
                        {props.people.map((person) => (
                            <li key={person.id} className="flex flex-col gap-2.5 px-4 py-3.5">
                                <div className="flex items-start gap-3">
                                    <span className="avatar mt-0.5" aria-hidden>
                                        {person.initials}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="m-0 font-semibold">{person.name}</p>
                                        <p className="m-0 text-sm break-all text-muted">{person.username}</p>
                                        <p className="m-0 mt-1 text-sm">{person.roles.map((role) => t(`common.roles.${role}`)).join(', ')}</p>
                                    </div>
                                </div>
                                {confirmId === person.id ? (
                                    <div className="flex flex-wrap gap-2">
                                        <p className="m-0 w-full text-sm font-semibold" role="status">
                                            {t('imposter.confirm', { name: person.name })}
                                        </p>
                                        <button type="button" className="btn btn-primary" disabled={working} onClick={() => start(person)}>
                                            {t('imposter.confirm_button')}
                                        </button>
                                        <button type="button" className="btn btn-secondary" disabled={working} onClick={() => setConfirmId(null)}>
                                            {t('common.actions.cancel')}
                                        </button>
                                    </div>
                                ) : (
                                    <button type="button" className="btn btn-secondary self-start" onClick={() => setConfirmId(person.id)}>
                                        <UserSwitch weight="bold" size={16} aria-hidden />
                                        {t('imposter.start')}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </AppShell>
    );
}
