import { useT } from '@/lib/i18n';
import { CheckCircle, DoorOpen, Prohibit } from '@phosphor-icons/react';
import type { PersonStatus } from './types';

/** Account status as icon plus text: active is calm green, suspended is blocked red, left is neutral. */
export function StatusChip({ status }: { status: PersonStatus }) {
    const t = useT();

    if (status === 'active') {
        return (
            <span className="chip chip-ok">
                <CheckCircle weight="bold" size={15} aria-hidden />
                {t('common.status.active')}
            </span>
        );
    }

    if (status === 'suspended') {
        return (
            <span className="chip chip-bad">
                <Prohibit weight="bold" size={15} aria-hidden />
                {t('common.status.suspended')}
            </span>
        );
    }

    return (
        <span className="chip">
            <DoorOpen weight="bold" size={15} aria-hidden />
            {t('common.status.left')}
        </span>
    );
}
