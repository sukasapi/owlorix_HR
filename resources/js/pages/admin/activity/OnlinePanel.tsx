import { formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Desktop, GlobeSimple } from '@phosphor-icons/react';
import type { ReactNode } from 'react';
import type { ActivityPageProps } from './types';

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');

/** Who has the web app or the desktop app open right now, as of the page load. */
export function OnlinePanel({ online, minutes }: { online: ActivityPageProps['online']; minutes: number }) {
    const t = useT();
    const locale = useLocale();
    const total = online.web.length + online.desktop.length;

    return (
        <section className="card flex min-w-0 flex-col gap-4 p-4 sm:p-5" aria-labelledby="activity-online">
            <div>
                <h2 id="activity-online" className="h2 text-[17px]">
                    {t('activity-monitor.online.title')} <span className="num text-muted">({total})</span>
                </h2>
                <p className="m-0 mt-1 text-sm text-muted">{t('activity-monitor.online.window', { minutes })}</p>
            </div>

            <Group icon={<GlobeSimple weight="bold" size={16} aria-hidden />} title={t('activity-monitor.online.web')}>
                {!online.web_tracked ? (
                    <p className="m-0 text-sm text-muted">{t('activity-monitor.online.web_untracked')}</p>
                ) : online.web.length === 0 ? (
                    <p className="m-0 text-sm text-muted">{t('activity-monitor.online.none_web')}</p>
                ) : (
                    <ul className="m-0 flex list-none flex-col gap-3 p-0">
                        {online.web.map((session, index) => (
                            <Row
                                key={`${session.person.id}-${index}`}
                                name={session.person.name}
                                where={session.browser ? t('activity-monitor.online.browser_on', { browser: session.browser, os: session.os ?? '?' }) : t('activity-monitor.online.unknown_browser')}
                                extra={session.ip}
                                seen={t('activity-monitor.online.last_seen', { time: formatTime(session.last_seen_at, locale) })}
                            />
                        ))}
                    </ul>
                )}
            </Group>

            <Group icon={<Desktop weight="bold" size={16} aria-hidden />} title={t('activity-monitor.online.desktop')}>
                {online.desktop.length === 0 ? (
                    <p className="m-0 text-sm text-muted">{t('activity-monitor.online.none_desktop')}</p>
                ) : (
                    <ul className="m-0 flex list-none flex-col gap-3 p-0">
                        {online.desktop.map((session) => (
                            <Row
                                key={`${session.person.id}-${session.device_id}`}
                                name={session.person.name}
                                where={session.hostname}
                                extra={t('activity-monitor.online.app_version', { version: session.app_version })}
                                seen={t('activity-monitor.online.last_seen', { time: formatTime(session.last_seen_at, locale) })}
                            />
                        ))}
                    </ul>
                )}
            </Group>
        </section>
    );
}

function Group({ icon, title, children }: { icon: ReactNode; title: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <h3 className="m-0 flex items-center gap-1.5 text-[15px] font-semibold text-muted">
                {icon}
                {title}
            </h3>
            {children}
        </div>
    );
}

function Row({ name, where, extra, seen }: { name: string; where: string; extra: string | null; seen: string }) {
    return (
        <li className="flex items-start gap-3">
            <span className="avatar" aria-hidden>
                {initials(name)}
            </span>
            <div className="min-w-0 flex-1">
                <p className="m-0 font-semibold break-words">{name}</p>
                <p className="m-0 text-sm break-words">{where}</p>
                {extra && <p className="num m-0 text-sm break-words text-muted">{extra}</p>}
            </div>
            <span className="num shrink-0 pt-0.5 text-sm text-muted">{seen}</span>
        </li>
    );
}
