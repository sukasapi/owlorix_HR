import { formatMinutes, formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { CheckCircle, ClockCounterClockwise, HourglassMedium, NotePencil, Warning, XCircle } from '@phosphor-icons/react';
import type { OvertimeItem, RequestFlag, RequestStatus } from './types';

export function StatusChip({ status }: { status: RequestStatus }) {
    const t = useT();
    const tone = status === 'approved' ? 'chip-ok' : status === 'rejected' ? 'chip-bad' : 'chip-pending';
    const Icon = status === 'approved' ? CheckCircle : status === 'rejected' ? XCircle : HourglassMedium;

    return (
        <span className={`chip ${tone}`}>
            <Icon weight="bold" size={15} aria-hidden />
            {t(`approvals.status.${status}`)}
        </span>
    );
}

export function FlagChips({ flags }: { flags: RequestFlag[] }) {
    const t = useT();

    return (
        <>
            {flags.map((flag) => (
                <span key={flag} className="chip border-dashed">
                    {flag === 'late_claim' ? (
                        <ClockCounterClockwise weight="bold" size={15} aria-hidden />
                    ) : (
                        <Warning weight="bold" size={15} aria-hidden />
                    )}
                    {t(`approvals.flags.${flag}`)}
                </span>
            ))}
        </>
    );
}

interface RowProps {
    item: OvertimeItem;
    selected: boolean;
    checkable: boolean;
    checked: boolean;
    onCheck: (checked: boolean) => void;
    onOpen: () => void;
    /** Phone cards answer clean requests in place; everything else opens the detail. */
    onQuickApprove?: () => void;
    onQuickReject?: () => void;
    quickBusy?: boolean;
}

/**
 * One request in the list. On a phone it is a card with its own buttons; from `lg` it is a row that opens the
 * detail panel next to the list.
 */
export function RequestRow({ item, selected, checkable, checked, onCheck, onOpen, onQuickApprove, onQuickReject, quickBusy = false }: RowProps) {
    const t = useT();
    const locale = useLocale();
    const name = item.person.name ?? '';
    const date = item.work_date ? formatShortDate(item.work_date, locale) : '';
    const duration = formatMinutes(item.minutes, locale);

    return (
        <li
            className={`card flex flex-col gap-2 p-3 lg:rounded-none lg:border-0 lg:border-b lg:border-line lg:py-3.5 lg:pr-4 lg:pl-2 ${
                selected ? 'lg:bg-[var(--selected)] lg:shadow-[inset_3px_0_0_var(--eye-brow)]' : ''
            }`}
        >
            <div className="flex items-start gap-1">
                {checkable ? (
                    <label className="-ml-1 flex h-11 w-11 flex-none cursor-pointer items-center justify-center">
                        <input
                            type="checkbox"
                            className="h-5 w-5 cursor-pointer accent-[var(--primary-bg)]"
                            checked={checked}
                            onChange={(e) => onCheck(e.target.checked)}
                            aria-label={t('approvals.item.select', {
                                name,
                                date,
                            })}
                        />
                    </label>
                ) : (
                    <span className="hidden w-11 flex-none lg:block" aria-hidden />
                )}
                <button
                    type="button"
                    id={`request-row-${item.id}`}
                    onClick={onOpen}
                    aria-current={selected ? 'true' : undefined}
                    className="flex min-h-11 min-w-0 flex-1 cursor-pointer items-start gap-3 rounded-sm py-1 text-left"
                >
                    <span className="avatar mt-0.5" aria-hidden>
                        {item.person.initials}
                    </span>
                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span className="flex items-baseline justify-between gap-3">
                            <span className="truncate font-semibold">{name}</span>
                            <span className="num flex-none font-semibold">{duration}</span>
                        </span>
                        <span className="num text-sm text-muted">
                            {date}
                            {item.teams.length > 0 && `, ${item.teams.join(', ')}`}
                        </span>
                        <span className="line-clamp-2 text-sm break-words lg:line-clamp-1">{item.reason}</span>
                    </span>
                </button>
            </div>

            {(item.flags.length > 0 || item.blocked || item.status !== 'pending') && (
                <div className="flex flex-wrap gap-2 pl-0 lg:pl-[90px]">
                    {item.status !== 'pending' && <StatusChip status={item.status} />}
                    {item.status === 'pending' && item.blocked && (
                        <span className="chip">
                            <HourglassMedium weight="bold" size={15} aria-hidden />
                            {t(`approvals.item.${item.blocked}`)}
                        </span>
                    )}
                    <FlagChips flags={item.flags} />
                </div>
            )}

            {onQuickApprove && onQuickReject && (
                <div className="grid grid-cols-[1fr_1.4fr] gap-2 lg:hidden">
                    <button type="button" className="btn btn-secondary" onClick={onQuickReject} disabled={quickBusy}>
                        {t('approvals.decide.reject')}
                    </button>
                    <button type="button" className="btn btn-primary" onClick={onQuickApprove} disabled={quickBusy}>
                        <span className="num">{quickBusy ? t('approvals.decide.sending') : t('approvals.decide.approve', { duration })}</span>
                    </button>
                </div>
            )}

            {!onQuickApprove && item.status === 'pending' && (
                <button type="button" className="btn btn-secondary lg:hidden" onClick={onOpen}>
                    <NotePencil weight="bold" size={18} aria-hidden />
                    {t('approvals.item.open')}
                </button>
            )}
        </li>
    );
}
