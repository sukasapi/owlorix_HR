import { useT } from '@/lib/i18n';
import { dayCount } from './format';
import type { Balance } from './types';

/** Annual leave left for the person of a request. Only Superadmin receives the numbers (owner, 2026-09-23). */
export function BalanceLine({ balance }: { balance: Balance | null }) {
    const t = useT();

    return (
        <p className={`num m-0 ${balance !== null && balance.remaining < 0 ? 'font-semibold text-danger' : ''}`}>
            {balance === null
                ? t('leave.approvals.no_quota')
                : balance.remaining < 0
                  ? t('leave.approvals.balance_over', { year: balance.year, days: dayCount(-balance.remaining, t) })
                  : t('leave.approvals.balance', { year: balance.year, days: dayCount(balance.remaining, t) })}
        </p>
    );
}
