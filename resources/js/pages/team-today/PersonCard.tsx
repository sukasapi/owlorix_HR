import { OwlEyes } from '@/components/owl/OwlEyes';
import { formatMinutes, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Warning } from '@phosphor-icons/react';
import type { BoardPerson } from './types';

/** The status sentence of a person, in words, so the eyes are never the only signal. */
export function useStatusLine() {
    const t = useT();
    const locale = useLocale();

    return (person: BoardPerson) => {
        const time = person.since ? formatTime(person.since, locale) : '';

        if (person.status === 'idle') {
            return t('team-today.status.idle', {
                duration: formatMinutes(person.idle?.minutes ?? 0, locale),
                time,
            });
        }

        if (person.status === 'leave') return t('team-today.status.leave', { type: person.leave?.type ?? '' });

        return t(`team-today.status.${person.status}`, { time });
    };
}

/**
 * One person on the board: owl eyes state, status in words, today's minutes. People who need an answer get the
 * gold frame (DESIGN.md: gold marks needs-answer); the group heading and status sentence say the same in words.
 */
export function PersonCard({ person, showTeam }: { person: BoardPerson; showTeam: boolean }) {
    const t = useT();
    const locale = useLocale();
    const statusLine = useStatusLine();
    const attention = person.group === 'attention';

    const extra = [
        person.status === 'overtime' && person.idle
            ? t('team-today.idle_during_overtime', {
                  duration: formatMinutes(person.idle.minutes, locale),
              })
            : null,
        person.idle?.tag
            ? t('team-today.idle_tagged', {
                  tag: t(`team-today.idle_tags.${person.idle.tag}`),
              })
            : null,
        person.device,
        person.status !== 'leave' && person.leave ? t('team-today.on_leave_today', { type: person.leave.type }) : null,
    ].filter(Boolean);

    return (
        <li className={`flex items-start gap-3 rounded-md bg-surface px-3.5 py-3 ${attention ? 'border-2 border-gold' : 'border border-line'}`}>
            <span className="mt-1">
                <OwlEyes state={person.eyes} size={52} look={0} />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="m-0 font-semibold break-words">{person.name}</p>
                <p className="num m-0 text-sm">
                    {statusLine(person)}
                    {extra.length > 0 && <span className="text-muted">, {extra.join(', ')}</span>}
                </p>
                <p className="num m-0 text-[13px] text-muted">
                    {person.overtime_minutes > 0
                        ? t('team-today.minutes', {
                              regular: formatMinutes(person.regular_minutes, locale),
                              overtime: formatMinutes(person.overtime_minutes, locale),
                          })
                        : t('team-today.minutes_regular', {
                              regular: formatMinutes(person.regular_minutes, locale),
                          })}
                    {showTeam && person.teams.length > 0 && `, ${person.teams.join(', ')}`}
                </p>
                {person.needs_review && (
                    <span className="chip chip-bad mt-1 self-start">
                        <Warning weight="bold" size={15} aria-hidden />
                        {t('team-today.needs_review')}
                    </span>
                )}
            </div>
        </li>
    );
}
