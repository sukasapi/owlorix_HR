import { Notice } from '@/components/ui/Notice';
import { formatDateTime, formatLongDate, formatMinutes, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { ArrowLeft, ClockCounterClockwise, Warning } from '@phosphor-icons/react';
import type { RefObject } from 'react';
import { DecisionForm } from './DecisionForm';
import { StatusChip } from './RequestList';
import type { IdleTag, OvertimeItem, RequestFlag } from './types';
import type { DecisionState } from './useDecisions';

interface Props {
    item: OvertimeItem;
    decisions: DecisionState;
    onSubmit: (item: OvertimeItem, decision: 'approved' | 'rejected', note: string) => void;
    onBack: () => void;
    headingRef: RefObject<HTMLHeadingElement | null>;
    startInReject: boolean;
}

/** The request being decided: the focal panel of Persetujuan (DESIGN.md, focal point per screen). */
export function RequestDetail({ item, decisions, onSubmit, onBack, headingRef, startInReject }: Props) {
    const t = useT();
    const locale = useLocale();
    const name = item.person.name ?? '';
    const time = (iso: string | null) => (iso ? formatTime(iso, locale) : '');

    const idleByTag = item.idle.reduce<Map<IdleTag | null, number>>((totals, period) => {
        totals.set(period.tag, (totals.get(period.tag) ?? 0) + period.minutes);
        return totals;
    }, new Map());

    const lateClaimNote =
        item.overtime_end_reason === 'presence_check_no_answer'
            ? t('approvals.flag_notes.late_claim_presence')
            : item.end_reason === 'auto_no_answer'
              ? t('approvals.flag_notes.late_claim_prompt')
              : t('approvals.flag_notes.late_claim');

    const flagNote = (flag: RequestFlag) => (flag === 'late_claim' ? lateClaimNote : t(`approvals.flag_notes.${flag}`));

    return (
        <article className="flex flex-col gap-5" aria-labelledby={`request-${item.id}-name`}>
            <button type="button" className="btn btn-quiet -ml-1.5 self-start lg:hidden" onClick={onBack}>
                <ArrowLeft weight="bold" size={18} aria-hidden />
                {t('approvals.detail.back')}
            </button>

            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="avatar h-11 w-11 text-[15px]" aria-hidden>
                        {item.person.initials}
                    </span>
                    <div className="min-w-0">
                        <h2 id={`request-${item.id}-name`} ref={headingRef} tabIndex={-1} className="h2 text-[22px] break-words sm:text-[24px]">
                            {name}
                        </h2>
                        <p className="m-0 text-sm text-muted">
                            {[
                                item.work_date ? formatLongDate(item.work_date, locale) : null,
                                item.teams.length ? item.teams.join(', ') : t('approvals.detail.team_none'),
                            ]
                                .filter(Boolean)
                                .join(', ')}
                        </p>
                    </div>
                </div>
                <StatusChip status={item.status} />
            </header>

            {item.person.status && item.person.status !== 'active' && <Notice>{t('approvals.detail.person_inactive')}</Notice>}

            <section className="brow flex flex-col gap-3 px-5 py-4 sm:px-6" aria-label={t('approvals.detail.overtime')}>
                <p className="m-0 text-sm font-semibold text-muted">{t('approvals.detail.overtime')}</p>
                <p className="display num m-0 text-[40px] text-heading sm:text-[64px]">{formatMinutes(item.minutes, locale)}</p>
                <p className="num m-0 font-semibold">
                    {item.ended_at
                        ? t('approvals.detail.range', {
                              start: time(item.started_at),
                              end: time(item.ended_at),
                          })
                        : t('approvals.detail.running_since', {
                              start: time(item.started_at),
                          })}
                </p>
                <OvertimeStrip item={item} />
            </section>

            <dl className="m-0 grid gap-x-6 gap-y-4 sm:grid-cols-[140px_1fr]">
                <dt className="text-sm font-semibold text-muted">{t('approvals.detail.reason')}</dt>
                <dd className="m-0 break-words whitespace-pre-line">{item.reason}</dd>
                <dt className="text-sm font-semibold text-muted">{t('approvals.detail.report')}</dt>
                <dd className={`m-0 break-words whitespace-pre-line ${item.work_report ? '' : 'text-muted'}`}>
                    {item.work_report ?? t('approvals.detail.report_missing')}
                </dd>
                <dt className="text-sm font-semibold text-muted">{t('approvals.detail.idle')}</dt>
                <dd className="num m-0">
                    {idleByTag.size === 0 ? (
                        t('approvals.detail.idle_none')
                    ) : (
                        <ul className="m-0 list-none p-0">
                            {[...idleByTag.entries()].map(([tag, minutes]) => (
                                <li key={tag ?? 'untagged'}>
                                    {tag
                                        ? t('approvals.detail.idle_tagged', {
                                              duration: formatMinutes(minutes, locale),
                                              tag: t(`approvals.detail.idle_tags.${tag}`),
                                          })
                                        : t('approvals.detail.idle_untagged', {
                                              duration: formatMinutes(minutes, locale),
                                          })}
                                </li>
                            ))}
                        </ul>
                    )}
                </dd>
            </dl>

            {item.flags.length > 0 && (
                <ul className="m-0 flex list-none flex-col gap-2 p-0">
                    {item.flags.map((flag) => (
                        <li key={flag} className="flex items-start gap-2.5 rounded-md border border-line-strong px-3.5 py-3 text-sm">
                            {flag === 'late_claim' ? (
                                <ClockCounterClockwise weight="bold" size={18} className="mt-px flex-none" aria-hidden />
                            ) : (
                                <Warning weight="bold" size={18} className="mt-px flex-none" aria-hidden />
                            )}
                            <span>
                                <strong className="font-semibold">{t(`approvals.flags.${flag}`)}.</strong> {flagNote(flag)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {item.decision && (
                <div className="flex flex-col gap-1 rounded-md bg-panel px-4 py-3 text-sm">
                    <p className="m-0 font-semibold">
                        {item.status === 'pending'
                            ? t('approvals.detail.previous', {
                                  decision: t(`approvals.detail.decision_words.${item.decision.decision}`),
                                  name: item.decision.decided_by ?? '',
                                  when: item.decision.decided_at ? formatDateTime(item.decision.decided_at, locale) : '',
                              })
                            : t('approvals.detail.decided_by', {
                                  decision: t(`approvals.status.${item.decision.decision}`),
                                  name: item.decision.decided_by ?? '',
                                  when: item.decision.decided_at ? formatDateTime(item.decision.decided_at, locale) : '',
                              })}
                    </p>
                    {item.decision.note && (
                        <p className="m-0 break-words whitespace-pre-line">
                            {t('approvals.detail.note', {
                                note: item.decision.note,
                            })}
                        </p>
                    )}
                </div>
            )}

            {item.status === 'pending' && item.blocked && (
                <Notice>
                    {item.blocked === 'running' ? t('approvals.detail.blocked_running') : t('approvals.detail.blocked_report_due', { name })}
                </Notice>
            )}

            <DecisionForm
                key={`${item.id}-${item.status}-${startInReject}`}
                item={item}
                decisions={decisions}
                onSubmit={onSubmit}
                startInReject={startInReject}
            />
        </article>
    );
}

/**
 * The overtime window as a bar, with quiet PC periods hatched. Hatching (not color) marks quiet time; the same facts
 * are in the text above, so the bar is hidden from screen readers.
 */
function OvertimeStrip({ item }: { item: OvertimeItem }) {
    const t = useT();
    const locale = useLocale();
    const start = Date.parse(item.started_at);
    const end = item.ended_at ? Date.parse(item.ended_at) : Date.now();
    const span = Math.max(1, end - start);

    const pct = (iso: string | null, fallback: number) => Math.min(100, Math.max(0, (((iso ? Date.parse(iso) : fallback) - start) / span) * 100));

    return (
        <div aria-hidden title={t('approvals.detail.timeline_label')}>
            <div className="relative h-4 overflow-hidden rounded-sm bg-[var(--eye-brow)]">
                {item.idle.map((period) => {
                    const left = pct(period.started_at, start);
                    const right = pct(period.ended_at, end);
                    return (
                        <span
                            key={`${period.started_at}`}
                            className="absolute inset-y-0 border-x border-[var(--eye-brow)] bg-surface"
                            style={{
                                left: `${left}%`,
                                width: `${Math.max(0.8, right - left)}%`,
                                backgroundImage: 'repeating-linear-gradient(135deg, var(--eye-brow) 0 2px, transparent 2px 6px)',
                            }}
                        />
                    );
                })}
            </div>
            <div className="num mt-1 flex justify-between text-[13px] text-muted">
                <span>{formatTime(item.started_at, locale)}</span>
                <span>{item.ended_at ? formatTime(item.ended_at, locale) : ''}</span>
            </div>
        </div>
    );
}
