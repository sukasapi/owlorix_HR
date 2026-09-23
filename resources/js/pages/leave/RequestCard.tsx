import { formatDateTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { CheckCircle, Paperclip, Prohibit, XCircle } from '@phosphor-icons/react';
import { type ReactNode, useId } from 'react';
import { dateRange, workdays } from './format';
import { StatusChip } from './StatusChip';
import type { LeaveItem } from './types';

/**
 * One of the person's own requests. A rejection or a cancellation by someone else leads with its note, because
 * that is what the person needs to read first.
 */
export function RequestCard({ item, onCancel }: { item: LeaveItem; onCancel: (item: LeaveItem) => void }) {
    const t = useT();
    const locale = useLocale();
    const headingId = useId();
    const rejected = item.status === 'rejected' && item.decision !== null;
    const cancelledByOther = item.cancellation !== null && !item.cancellation.by_owner;

    return (
        <article aria-labelledby={headingId} className="card flex flex-col gap-3 px-5 py-4 sm:px-6">
            <header className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <div className="min-w-0">
                    <h3 id={headingId} className="m-0 text-[17px] font-semibold break-words text-heading">
                        {item.type?.name}
                    </h3>
                    <p className="num m-0 text-sm">
                        {dateRange(item.start_date, item.end_date, locale, t)}
                        <span className="text-muted"> · </span>
                        {workdays(item.days, t)}
                    </p>
                </div>
                <StatusChip status={item.status} />
            </header>

            {rejected && item.decision && (
                <Outcome icon={<XCircle weight="bold" size={18} className="mt-px flex-none" aria-hidden />}>
                    <p className="m-0 font-semibold">
                        {item.decision.by && item.decision.at
                            ? t('leave.item.rejected_by', { name: item.decision.by, time: formatDateTime(item.decision.at, locale) })
                            : t('leave.status.rejected')}
                    </p>
                    <p className="m-0 mt-1 [overflow-wrap:anywhere] whitespace-pre-line">{item.decision.note ?? t('leave.item.no_note')}</p>
                </Outcome>
            )}

            {cancelledByOther && item.cancellation && (
                <Outcome icon={<Prohibit weight="bold" size={18} className="mt-px flex-none" aria-hidden />}>
                    <p className="m-0 font-semibold">
                        {item.cancellation.at
                            ? t('leave.item.cancelled_by', { name: item.cancellation.by ?? t('leave.item.someone'), time: formatDateTime(item.cancellation.at, locale) })
                            : t('leave.status.cancelled')}
                    </p>
                    {item.cancellation.note && <p className="m-0 mt-1 [overflow-wrap:anywhere] whitespace-pre-line">{item.cancellation.note}</p>}
                </Outcome>
            )}

            {item.reason && (
                <div className="min-w-0">
                    <p className="m-0 text-[13px] font-semibold text-muted">{t('leave.item.reason')}</p>
                    <p className="m-0 mt-0.5 [overflow-wrap:anywhere] whitespace-pre-line">{item.reason}</p>
                </div>
            )}

            {item.attachment && (
                <a href={item.attachment.url} className="link inline-flex items-center gap-1.5 self-start text-sm font-semibold [overflow-wrap:anywhere]">
                    <Paperclip weight="bold" size={16} className="flex-none" aria-hidden />
                    {item.attachment.name}
                </a>
            )}

            {item.decision && item.decision.decision === 'approved' && (
                <p className="m-0 flex items-start gap-2 text-sm">
                    <CheckCircle weight="bold" size={16} className="mt-0.5 flex-none text-success" aria-hidden />
                    <span className="min-w-0">
                        <span className="block font-semibold">
                            {item.decision.by && item.decision.at
                                ? t('leave.item.approved_by', { name: item.decision.by, time: formatDateTime(item.decision.at, locale) })
                                : t('leave.status.approved')}
                        </span>
                        {item.decision.note && <span className="block [overflow-wrap:anywhere] whitespace-pre-line">{item.decision.note}</span>}
                    </span>
                </p>
            )}

            {item.status === 'pending' && <p className="m-0 text-sm text-muted">{t('leave.item.waiting')}</p>}

            {item.cancellation?.by_owner && item.cancellation.at && (
                <p className="m-0 text-sm text-muted">{t('leave.item.cancelled_own', { time: formatDateTime(item.cancellation.at, locale) })}</p>
            )}

            {item.can_cancel && (
                <div className="border-t border-line pt-3">
                    <button type="button" className="btn btn-secondary" onClick={() => onCancel(item)}>
                        {t('leave.cancel.open')}
                    </button>
                </div>
            )}
        </article>
    );
}

/** A rejection or a cancellation by someone else: red frame, icon, and the words, never color alone. */
function Outcome({ icon, children }: { icon: ReactNode; children: ReactNode }) {
    return (
        <div className="flex items-start gap-2 rounded-md border-2 border-danger px-4 py-3">
            <span className="text-danger">{icon}</span>
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}
