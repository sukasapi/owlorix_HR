import { Meter } from '@/components/charts';
import { useLocale, useT } from '@/lib/i18n';
import type { Locale } from '@/types';
import type { ReactNode } from 'react';
import type { BudgetData } from './taskTypes';

/** Minutes as whole or one-decimal hours: "42 j", "42,5 j" (id) or "42.5 h" (en). */
export function formatHours(minutes: number, locale: Locale): string {
    const hours = new Intl.NumberFormat(locale === 'id' ? 'id-ID' : 'en-GB', { maximumFractionDigits: 1 }).format(minutes / 60);
    return `${hours} ${locale === 'id' ? 'j' : 'h'}`;
}

/** Hours for the budget field: 750 minutes becomes "12.5" (the number input takes a dot). */
export function budgetHoursValue(minutes: number | null | undefined): string {
    return minutes ? String(Math.round((minutes / 60) * 100) / 100) : '';
}

/**
 * Logged hours against the hour budget, for budget holders only. The meter turns gold from 80 percent and red past
 * the budget; the text always carries the numbers, so the color is never the only signal.
 */
export function BudgetMeter({ budget, source, action }: { budget: BudgetData; source: string; action?: ReactNode }) {
    const t = useT();
    const locale = useLocale();
    const logged = formatHours(budget.logged_minutes, locale);

    return (
        <section className="card px-4 py-3.5 sm:px-5" aria-labelledby="budget-heading">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <h2 id="budget-heading" className="m-0 text-base font-semibold">
                    {t('projects.budget.heading')}
                </h2>
                {action}
            </div>
            {budget.minutes === null ? (
                <p className="num m-0 mt-1.5 text-sm">{t('projects.budget.none', { logged })}</p>
            ) : (
                <div className="mt-2">
                    <Meter
                        value={budget.logged_minutes}
                        max={budget.minutes}
                        label={t('projects.budget.meter_label')}
                        text={
                            budget.logged_minutes > budget.minutes
                                ? t('projects.budget.over', { logged, budget: formatHours(budget.minutes, locale), over: formatHours(budget.logged_minutes - budget.minutes, locale) })
                                : t('projects.budget.text', { logged, budget: formatHours(budget.minutes, locale) })
                        }
                    />
                </div>
            )}
            <p className="help m-0 mt-1.5">{source}</p>
        </section>
    );
}
