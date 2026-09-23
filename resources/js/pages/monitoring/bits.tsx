import { useT } from '@/lib/i18n';
import type { Locale } from '@/types';
import { ArrowCounterClockwise } from '@phosphor-icons/react';
import type { ReactNode } from 'react';

const intlLocale = (locale: Locale) => (locale === 'id' ? 'id-ID' : 'en-GB');

/** "21 Sep" from a studio date, without shifting zones. */
export function dayMonth(date: string, locale: Locale): string {
    const [y, m, d] = date.split('-').map(Number);
    return new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d, 12)));
}

/** "21 September 2026" from a studio date. */
export function longDate(date: string, locale: Locale): string {
    const [y, m, d] = date.split('-').map(Number);
    return new Intl.DateTimeFormat(intlLocale(locale), { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d, 12)));
}

/** A state that replaces content: says what is going on and, when there is one, the action that changes it. */
export function EmptyCard({ title, body, children, role }: { title: string; body: string; children?: ReactNode; role?: 'alert' }) {
    return (
        <section className="card flex flex-col items-start gap-3 px-5 py-6" role={role}>
            <h2 className="h2">{title}</h2>
            <p className="m-0 max-w-[62ch] text-muted">{body}</p>
            {children}
        </section>
    );
}

export function ErrorCard({ title, body, onRetry }: { title: string; body: string; onRetry: () => void }) {
    const t = useT();

    return (
        <EmptyCard title={title} body={body} role="alert">
            <button type="button" className="btn btn-secondary" onClick={onRetry}>
                <ArrowCounterClockwise weight="bold" size={18} aria-hidden />
                {t('work-monitor.states.retry')}
            </button>
        </EmptyCard>
    );
}
