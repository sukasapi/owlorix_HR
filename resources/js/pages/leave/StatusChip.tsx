import { useT } from '@/lib/i18n';
import { CheckCircle, HourglassMedium, Prohibit, XCircle } from '@phosphor-icons/react';
import type { LeaveStatus } from './types';

const ICON = { pending: HourglassMedium, approved: CheckCircle, rejected: XCircle, cancelled: Prohibit } as const;
const TONE = { pending: 'chip-pending', approved: 'chip-ok', rejected: 'chip-bad', cancelled: '' } as const;

/** Status in words with its icon, so it never depends on color alone. */
export function StatusChip({ status }: { status: LeaveStatus }) {
    const t = useT();
    const Icon = ICON[status];

    return (
        <span className={`chip ${TONE[status]}`}>
            <Icon weight="bold" size={15} aria-hidden />
            {t(`leave.status.${status}`)}
        </span>
    );
}
