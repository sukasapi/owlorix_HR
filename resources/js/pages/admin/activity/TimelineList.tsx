import { formatLongDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { describe } from './labels';
import { KIND_COLORS, type TimelineRow } from './types';

const studioDate = (iso: string) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date(iso));

/** Rows grouped under a heading per studio day, like Log audit, so each row only carries its time. */
export function TimelineList({ rows }: { rows: TimelineRow[] }) {
    const locale = useLocale();
    const days: { date: string; rows: TimelineRow[] }[] = [];

    for (const row of rows) {
        const date = studioDate(row.at);
        const last = days[days.length - 1];
        if (last && last.date === date) last.rows.push(row);
        else days.push({ date, rows: [row] });
    }

    return (
        <div className="flex flex-col gap-5">
            {days.map((day) => (
                <section key={day.date} aria-labelledby={`activity-day-${day.date}`}>
                    <h3 id={`activity-day-${day.date}`} className="mb-2 text-[15px] font-semibold text-muted">
                        {formatLongDate(day.date, locale)}
                    </h3>
                    <ul className="card m-0 list-none divide-y divide-line p-0">
                        {day.rows.map((row) => (
                            <Row key={row.key} row={row} />
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}

function Row({ row }: { row: TimelineRow }) {
    const t = useT();
    const locale = useLocale();
    const text = describe(t, row, locale);
    const who = row.person
        ? row.person.name
        : row.masked_prefix !== null
          ? row.masked_prefix === ''
              ? t('activity-monitor.timeline.masked_username_bare')
              : t('activity-monitor.timeline.masked_username', { prefix: row.masked_prefix })
          : row.username
            ? t('activity-monitor.timeline.unknown_username', { username: row.username })
            : t('activity-monitor.timeline.system');
    const device = row.device ? (row.device.kind === 'browser' ? t('activity-monitor.timeline.browser') : (row.device.hostname ?? row.device.id)) : null;
    const meta = [
        row.ip ? t('activity-monitor.timeline.ip', { ip: row.ip }) : null,
        device ? t('activity-monitor.timeline.on_device', { device }) : null,
        row.status !== null && row.status >= 400 ? t('activity-monitor.timeline.status', { status: row.status }) : null,
    ].filter(Boolean);

    return (
        <li className="grid grid-cols-[52px_minmax(0,1fr)] gap-x-3 gap-y-1 px-4 py-3 sm:grid-cols-[60px_minmax(0,1fr)_auto] sm:items-start sm:px-5">
            <time dateTime={row.at} className="num pt-0.5 font-semibold">
                {formatTime(row.at, locale)}
            </time>
            <div className="min-w-0">
                <p className="m-0 flex items-baseline gap-2 font-semibold">
                    <span className="viz-key translate-y-[1px]" style={{ background: KIND_COLORS[row.kind] }} aria-hidden />
                    <span className="min-w-0 break-words">
                        <span className="sr-only">{t(`activity-monitor.kinds.${row.kind}`)}: </span>
                        {text}
                    </span>
                </p>
                <p className="m-0 text-sm break-words">
                    {who}
                    {row.person && <span className="text-muted"> ({row.person.username})</span>}
                </p>
                {meta.length > 0 && <p className="num m-0 text-sm break-words text-muted">{meta.join(' · ')}</p>}
            </div>
            {row.audit_href && (
                <div className="col-start-2 sm:col-start-3">
                    <Link href={row.audit_href} className="btn btn-secondary btn-sm min-h-11">
                        {t('activity-monitor.timeline.audit_link')}
                        <span className="sr-only">: {text}</span>
                    </Link>
                </div>
            )}
        </li>
    );
}
