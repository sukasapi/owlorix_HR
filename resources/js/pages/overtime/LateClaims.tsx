import { formatDateTime, formatLongDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { ClockCountdown } from '@phosphor-icons/react';
import { followUpsHref } from '../history/links';
import { durationWords, timeOn } from '../history/dates';
import type { LateClaim, OvertimePageProps } from './types';

/**
 * Shifts the rules ended automatically that can still get a late overtime claim (docs/02 3.3.6, I11). The claim form
 * lives on Hari ini and in the desktop app, so this panel only says what happened, the deadline, and where to go. It
 * uses the gold accent and the brow cut because it is the one thing on the page that waits for the person.
 */
export function LateClaims({ claims, rules, webClock }: { claims: LateClaim[]; rules: OvertimePageProps['rules']; webClock: boolean }) {
    const t = useT();
    const locale = useLocale();

    return (
        <section aria-labelledby="late-claims" className="rounded-[var(--r-brow)] border-2 border-gold bg-gold-tint px-5 py-5 sm:px-7">
            <h2 id="late-claims" className="h2 flex items-center gap-2 text-ink">
                <ClockCountdown weight="bold" size={24} className="flex-none" aria-hidden />
                {t('overtime.claims.heading')}
            </h2>

            <ul className="m-0 mt-3 flex list-none flex-col divide-y divide-[color-mix(in_srgb,var(--gold)_45%,transparent)] p-0">
                {claims.map((claim) => (
                    <li key={claim.shift_id} className="flex flex-col gap-1.5 py-3 first:pt-1 last:pb-0">
                        <p className="m-0 font-semibold">
                            {t(`overtime.claims.${claim.cause}`, {
                                date: formatLongDate(claim.work_date, locale),
                                time: timeOn(claim.auto_ended_at, claim.work_date, locale),
                                answer: durationWords(claim.cause === 'presence_check' ? rules.overtime_idle_answer_minutes : rules.prompt_auto_close_minutes, locale),
                            })}
                        </p>
                        <p className="num m-0 text-[17px] font-bold">{t('overtime.claims.deadline', { deadline: formatDateTime(claim.claimable_until, locale) })}</p>
                        <p className="m-0 text-sm">{t('overtime.claims.latest_end', { time: timeOn(claim.latest_end_at, claim.work_date, locale) })}</p>
                    </li>
                ))}
            </ul>

            <p className="m-0 mt-4 text-sm font-medium">{webClock ? t('overtime.claims.where_web') : t('overtime.claims.where')}</p>
            {webClock && (
                <Link href={followUpsHref()} className="btn btn-primary mt-3">
                    {t('overtime.claims.open_my_day')}
                </Link>
            )}
        </section>
    );
}
