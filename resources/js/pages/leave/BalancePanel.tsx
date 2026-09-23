import { useT } from '@/lib/i18n';
import { dayCount } from './format';
import type { Balance } from './types';

/**
 * The focal point of Cuti: annual leave left this year, as one large number. The bar repeats the three numbers
 * below it for a quick read; the numbers carry the meaning, so the bar is hidden from screen readers. Pending days
 * are gold, the colour that marks waiting for an answer (DESIGN.md).
 */
export function BalancePanel({ balance }: { balance: Balance }) {
    const t = useT();
    const total = Math.max(balance.quota, balance.used + balance.pending, 1);
    const share = (days: number) => `${(Math.max(days, 0) / total) * 100}%`;
    const over = balance.remaining < 0;

    return (
        <section aria-labelledby="leave-balance-title" className="brow flex flex-col gap-4 px-5 py-6 sm:px-7">
            <div>
                <h2 id="leave-balance-title" className="m-0 text-base font-semibold text-heading">
                    {t('leave.balance.title', { year: balance.year })}
                </h2>
                <p className="num m-0 mt-1 flex items-baseline gap-2">
                    <span className="display text-[40px] text-heading">{Math.max(balance.remaining, 0)}</span>
                    <span className="text-[20px] font-semibold">{t('leave.balance.unit')}</span>
                </p>
            </div>

            <div className="flex h-3 w-full overflow-hidden rounded-full border border-line-strong bg-surface" aria-hidden>
                <span className="h-full bg-ink" style={{ width: share(balance.used) }} />
                <span className="h-full bg-gold" style={{ width: share(balance.pending) }} />
            </div>

            <dl className="num m-0 grid grid-cols-3 gap-3">
                <Figure label={t('leave.balance.quota')} value={dayCount(balance.quota, t)} />
                <Figure label={t('leave.balance.used')} value={dayCount(balance.used, t)} swatch="bg-ink" />
                <Figure label={t('leave.balance.pending')} value={dayCount(balance.pending, t)} swatch="bg-gold" />
            </dl>

            <p className="m-0 text-sm text-muted">
                {balance.custom ? t('leave.balance.quota_custom') : t('leave.balance.quota_default')}
                {balance.pending > 0 && ` ${t('leave.balance.pending_note')}`}
            </p>
            {over && <p className="m-0 text-sm font-semibold text-danger">{t('leave.balance.over', { days: dayCount(-balance.remaining, t) })}</p>}
        </section>
    );
}

function Figure({ label, value, swatch }: { label: string; value: string; swatch?: string }) {
    return (
        <div className="min-w-0">
            <dt className="flex items-center gap-1.5 text-[13px] text-muted">
                {swatch && <span className={`inline-block h-2.5 w-2.5 flex-none rounded-full ${swatch}`} aria-hidden />}
                {label}
            </dt>
            <dd className="m-0 font-semibold">{value}</dd>
        </div>
    );
}
