import { useLocale, useT } from '@/lib/i18n';
import { CheckCircle, Prohibit, Timer } from '@phosphor-icons/react';
import type { DeviceAction, DeviceKind, DeviceRow } from './types';
import { shortId, when } from './when';

interface Props {
    kind: DeviceKind;
    devices: DeviceRow[];
    revocations: Record<string, DeviceAction>;
    thisBrowserId: string | null;
    onRevoke: (device: DeviceRow) => void;
    onRestore: (device: DeviceRow) => void;
}

/**
 * Calm table on wide screens (ENERGY 1 inside tables). Below lg the same rows stack as a list, so nothing scrolls
 * sideways on a phone. The running shift sits next to the action, because it is what a revoke puts at stake.
 */
export function DeviceList({ kind, devices, revocations, thisBrowserId, onRevoke, onRestore }: Props) {
    const t = useT();

    return (
        <>
            <div className="table-wrap hidden lg:block">
                <table className="table">
                    <thead>
                        <tr>
                            <th scope="col">{t(`devices.columns.device_${kind}`)}</th>
                            {kind === 'desktop' && <th scope="col">{t('devices.columns.version')}</th>}
                            <th scope="col">{t('devices.columns.last_seen')}</th>
                            <th scope="col">{t('devices.columns.people')}</th>
                            <th scope="col">{t('devices.columns.shift')}</th>
                            <th scope="col">{t('devices.columns.status')}</th>
                            <th scope="col">
                                <span className="sr-only">{t('devices.columns.actions')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {devices.map((device) => (
                            <tr key={device.id} className="align-top">
                                <td className="max-w-[240px]">
                                    <DeviceName device={device} isThisBrowser={device.id === thisBrowserId} />
                                </td>
                                {kind === 'desktop' && <td className="num whitespace-nowrap">{device.app_version}</td>}
                                <td className="num whitespace-nowrap">
                                    <LastSeen device={device} />
                                </td>
                                <td className="min-w-[180px]">
                                    <People device={device} />
                                </td>
                                <td className="min-w-[150px]">
                                    <Shifts device={device} />
                                </td>
                                <td className="max-w-[220px]">
                                    <Status device={device} action={revocations[device.id]} />
                                </td>
                                <td className="text-right">
                                    <ActionButton device={device} onRevoke={onRevoke} onRestore={onRestore} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <ul className="card m-0 list-none divide-y divide-line p-0 lg:hidden">
                {devices.map((device) => (
                    <li key={device.id} className="flex flex-col gap-3 px-4 py-4">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <DeviceName device={device} isThisBrowser={device.id === thisBrowserId} />
                            </div>
                            <Status device={device} action={revocations[device.id]} compact />
                        </div>
                        <dl className="m-0 grid gap-x-4 gap-y-2 text-sm min-[480px]:grid-cols-[auto_1fr]">
                            {kind === 'desktop' && (
                                <>
                                    <dt className="text-muted">{t('devices.columns.version')}</dt>
                                    <dd className="num m-0">{device.app_version}</dd>
                                </>
                            )}
                            <dt className="text-muted">{t('devices.columns.last_seen')}</dt>
                            <dd className="num m-0">
                                <LastSeen device={device} />
                            </dd>
                            <dt className="text-muted">{t('devices.columns.people')}</dt>
                            <dd className="m-0">
                                <People device={device} />
                            </dd>
                            <dt className="text-muted">{t('devices.columns.shift')}</dt>
                            <dd className="m-0">
                                <Shifts device={device} />
                            </dd>
                        </dl>
                        <RevokeNote device={device} action={revocations[device.id]} />
                        <div>
                            <ActionButton device={device} onRevoke={onRevoke} onRestore={onRestore} wide />
                        </div>
                    </li>
                ))}
            </ul>
        </>
    );
}

function DeviceName({ device, isThisBrowser }: { device: DeviceRow; isThisBrowser: boolean }) {
    const t = useT();

    return (
        <div className="flex min-w-0 flex-col gap-1">
            <b className="font-semibold break-words">{device.hostname}</b>
            <span className="num text-sm break-all text-muted" title={device.id}>
                {t('devices.id_short', { id: shortId(device.id) })}
            </span>
            {isThisBrowser && <span className="chip chip-info self-start">{t('devices.this_browser')}</span>}
        </div>
    );
}

function LastSeen({ device }: { device: DeviceRow }) {
    const t = useT();
    const locale = useLocale();

    return device.last_seen_at ? <>{when(device.last_seen_at, locale, t)}</> : <span className="text-muted">-</span>;
}

function People({ device }: { device: DeviceRow }) {
    const t = useT();
    const locale = useLocale();

    if (device.people.length === 0) return <span className="text-muted">{t('devices.no_people')}</span>;

    const shown = device.people.slice(0, 3);
    const hidden = device.people_count - shown.length;

    return (
        <ul className="m-0 flex list-none flex-col gap-1 p-0">
            {shown.map((person) => (
                <li key={person.id} className="min-w-0 break-words">
                    <span className="font-medium">{person.name}</span>
                    {person.last_online_sign_in_at && (
                        <span className="num text-muted"> {t('devices.signed_in', { time: when(person.last_online_sign_in_at, locale, t) })}</span>
                    )}
                </li>
            ))}
            {hidden > 0 && <li className="text-muted">{t('devices.people_more', { count: hidden })}</li>}
        </ul>
    );
}

function Shifts({ device }: { device: DeviceRow }) {
    const t = useT();
    const locale = useLocale();

    if (device.open_shifts.length === 0) return <span className="text-muted">{t('devices.no_shift')}</span>;

    return (
        <ul className="m-0 flex list-none flex-col items-start gap-1.5 p-0">
            {device.open_shifts.map((shift) => {
                const time = shift.clock_in_at ? when(shift.clock_in_at, locale, t) : '';
                return (
                    <li key={shift.user_id} className="chip chip-info max-w-full whitespace-normal">
                        <Timer weight="bold" size={15} aria-hidden className="flex-none" />
                        <span className="num break-words">
                            {shift.status === 'interrupted' ? t('devices.shift_interrupted', { name: shift.name, time }) : t('devices.shift_since', { name: shift.name, time })}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

function Status({ device, action, compact = false }: { device: DeviceRow; action?: DeviceAction; compact?: boolean }) {
    const t = useT();

    const chip = device.revoked_at ? (
        <span className="chip chip-bad">
            <Prohibit weight="bold" size={15} aria-hidden />
            {t('devices.status.revoked')}
        </span>
    ) : (
        <span className="chip chip-ok">
            <CheckCircle weight="bold" size={15} aria-hidden />
            {t('devices.status.active')}
        </span>
    );

    if (compact) return <div className="flex-none">{chip}</div>;

    return (
        <div className="flex flex-col items-start gap-1.5">
            {chip}
            <RevokeNote device={device} action={action} />
        </div>
    );
}

/** Who revoked the device, when, and why, from the latest audit entry. */
function RevokeNote({ device, action }: { device: DeviceRow; action?: DeviceAction }) {
    const t = useT();
    const locale = useLocale();

    if (!device.revoked_at) return null;

    const time = when(device.revoked_at, locale, t);
    const byline = action?.action === 'device.revoked' && action.actor ? t('devices.revoked_by', { time, name: action.actor }) : t('devices.revoked_when', { time });

    return (
        <p className="m-0 text-sm text-muted">
            <span className="num">{byline}</span>
            {action?.action === 'device.revoked' && action.reason && <span className="block break-words text-ink">{action.reason}</span>}
        </p>
    );
}

function ActionButton({ device, onRevoke, onRestore, wide = false }: { device: DeviceRow; onRevoke: (d: DeviceRow) => void; onRestore: (d: DeviceRow) => void; wide?: boolean }) {
    const t = useT();
    const size = wide ? 'w-full min-[480px]:w-auto' : 'btn-sm min-h-11 whitespace-nowrap';

    return device.revoked_at ? (
        <button type="button" className={`btn btn-secondary ${size}`} onClick={() => onRestore(device)} aria-label={t('devices.restore_label', { name: device.hostname })}>
            {t('devices.restore')}
        </button>
    ) : (
        <button type="button" className={`btn btn-danger ${size}`} onClick={() => onRevoke(device)} aria-label={t('devices.revoke_label', { name: device.hostname })}>
            {t('devices.revoke')}
        </button>
    );
}
