import { formatShortDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { CalendarX, CheckCircle, HourglassMedium, Warning, XCircle } from '@phosphor-icons/react';
import type { CorrectionItem, CorrectionStatus } from './types';

export function StatusChip({ status }: { status: CorrectionStatus }) {
    const t = useT();
    const tone = status === 'applied' ? 'chip-ok' : status === 'declined' ? 'chip-bad' : 'chip-pending';
    const Icon = status === 'applied' ? CheckCircle : status === 'declined' ? XCircle : HourglassMedium;

    return (
        <span className={`chip ${tone}`}>
            <Icon weight="bold" size={15} aria-hidden />
            {t(`corrections.status.${status}`)}
        </span>
    );
}

/** "15.00 jadi 17.00", or the new time alone when nothing was recorded before. */
export function useChangeText() {
    const t = useT();
    const locale = useLocale();

    return (from: string | null, to: string) =>
        from
            ? t('corrections.item.change', { from: formatTime(from, locale), to: formatTime(to, locale) })
            : t('corrections.item.change_from_none', { to: formatTime(to, locale) });
}

interface RowProps {
    item: CorrectionItem;
    selected: boolean;
    onOpen: () => void;
}

/** One correction in the list: a card on a phone, a row that opens the detail next to the list from `lg`. */
export function CorrectionRow({ item, selected, onOpen }: RowProps) {
    const t = useT();
    const locale = useLocale();
    const change = useChangeText();

    return (
        <li
            className={`card flex flex-col gap-2 p-3 lg:rounded-none lg:border-0 lg:border-b lg:border-line lg:px-4 lg:py-3.5 ${
                selected ? 'lg:bg-[var(--selected)] lg:shadow-[inset_3px_0_0_var(--eye-brow)]' : ''
            }`}
        >
            <button
                type="button"
                id={`correction-row-${item.id}`}
                onClick={onOpen}
                aria-current={selected ? 'true' : undefined}
                className="flex min-h-11 min-w-0 cursor-pointer items-start gap-3 rounded-sm py-1 text-left"
            >
                <span className="avatar mt-0.5" aria-hidden>
                    {item.person.initials}
                </span>
                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="flex items-baseline justify-between gap-3">
                        <span className="truncate font-semibold">{item.person.name}</span>
                        <span className="num flex-none text-sm text-muted">{item.work_date ? formatShortDate(item.work_date, locale) : ''}</span>
                    </span>
                    <span className="num text-sm">
                        <span className="font-semibold">{t(`corrections.fields.${item.field}`)}</span> {change(item.old_value, item.new_value)}
                    </span>
                    <span className="line-clamp-2 text-sm break-words text-muted lg:line-clamp-1">{item.reason}</span>
                </span>
            </button>

            {(item.status !== 'proposed' || item.changed_since || item.closed_month || item.is_direct) && (
                <div className="flex flex-wrap gap-2 lg:pl-[46px]">
                    {item.status !== 'proposed' && <StatusChip status={item.status} />}
                    {item.is_direct && <span className="chip chip-info">{t('corrections.item.direct')}</span>}
                    {item.changed_since && (
                        <span className="chip border-dashed">
                            <Warning weight="bold" size={15} aria-hidden />
                            {t('corrections.item.changed')}
                        </span>
                    )}
                    {item.closed_month && (
                        <span className="chip border-dashed">
                            <CalendarX weight="bold" size={15} aria-hidden />
                            {t('corrections.item.closed_month')}
                        </span>
                    )}
                </div>
            )}
        </li>
    );
}
