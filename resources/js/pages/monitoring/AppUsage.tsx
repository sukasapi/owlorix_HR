import { BarList } from '@/components/charts';
import { SelectField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { formatMinutes, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { Browser, Info } from '@phosphor-icons/react';
import type { ReactNode } from 'react';

interface Session {
    app: string;
    title: string | null;
    browser: boolean;
    started_at: string;
    ended_at: string;
    seconds: number;
}

interface PersonDay {
    id: number;
    name: string;
    /** Whether this person is recorded now (rule on and their employment type switched on in Aturan) */
    recorded: boolean;
    employment_type: string | null;
    from_archive: boolean;
    total_seconds: number;
    browser_seconds: number;
    apps: { app: string; browser: boolean; seconds: number }[];
    sessions: Session[];
    shifts: { clock_in_at: string; clock_out_at: string | null }[];
}

interface Props {
    enabled: boolean;
    active_days: number;
    filters: { orang: number | null; tanggal: string };
    today: string;
    people: { id: number; name: string; username: string }[];
    person: PersonDay | null;
}

const minutes = (seconds: number) => Math.round(seconds / 60);

/**
 * Aktivitas detail (Superadmin): one person, one studio day. The applications used longest come first as bars, then
 * every window by time. Durations are rounded to minutes; a window shorter than a minute still shows as "< 1 mnt".
 */
export default function AppUsage({ enabled, active_days, filters, today, people, person }: Props) {
    const t = useT();
    const locale = useLocale();

    const apply = (next: Partial<Props['filters']>) => {
        const query = { ...filters, ...next };
        router.get(route('monitoring.app-usage'), { orang: query.orang ?? undefined, tanggal: query.tanggal }, { preserveState: true, preserveScroll: true, replace: true });
    };
    const duration = (seconds: number) => (seconds < 60 ? `< ${formatMinutes(1, locale)}` : formatMinutes(minutes(seconds), locale));

    return (
        <AppShell title={t('app-usage.title')}>
            <header className="mb-6">
                <h1 className="h1">{t('app-usage.title')}</h1>
                <p className="m-0 mt-1.5 max-w-[72ch] text-muted">{t('app-usage.lead')}</p>
            </header>

            {!enabled && (
                <Notice className="mb-6">
                    <span className="flex items-start gap-2">
                        <Info weight="bold" size={18} aria-hidden className="mt-0.5 flex-none" />
                        {t('app-usage.disabled')}
                    </span>
                </Notice>
            )}

            <form role="search" aria-label={t('app-usage.filters.label')} className="grid gap-3 sm:grid-cols-[minmax(0,320px)_200px]" onSubmit={(e) => e.preventDefault()}>
                <SelectField label={t('app-usage.filters.person')} value={filters.orang ?? ''} onChange={(e) => apply({ orang: e.target.value === '' ? null : Number(e.target.value) })}>
                    <option value="">{t('app-usage.filters.person_pick')}</option>
                    {people.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.name} ({p.username})
                        </option>
                    ))}
                </SelectField>
                <TextField label={t('app-usage.filters.date')} type="date" max={today} value={filters.tanggal} onChange={(e) => e.target.value && apply({ tanggal: e.target.value })} />
            </form>

            {person === null ? (
                <div className="card mt-8 px-5 py-6 sm:px-6">
                    <p className="m-0 font-semibold">{t('app-usage.pick_title')}</p>
                    <p className="m-0 mt-1 text-sm text-muted">{t('app-usage.pick_body')}</p>
                </div>
            ) : (
                <PersonView person={person} activeDays={active_days} duration={duration} />
            )}
        </AppShell>
    );
}

function PersonView({ person, activeDays, duration }: { person: PersonDay; activeDays: number; duration: (seconds: number) => string }) {
    const t = useT();
    const locale = useLocale();
    const shiftText = person.shifts.length
        ? person.shifts
              .map((s) => t('app-usage.facts.shift_range', { start: formatTime(s.clock_in_at, locale), end: s.clock_out_at ? formatTime(s.clock_out_at, locale) : t('app-usage.facts.running') }))
              .join(', ')
        : t('app-usage.facts.no_shift');

    return (
        <div className="mt-8">
            {!person.recorded && (
                <Notice className="mb-5">
                    {person.employment_type ? t('app-usage.not_recorded_type', { type: t(`app-usage.types.${person.employment_type}`) }) : t('app-usage.not_recorded')}
                </Notice>
            )}

            <dl className="m-0 grid border-y border-line sm:grid-cols-3">
                <Fact label={t('app-usage.facts.total')}>
                    <span className="num">{person.total_seconds ? duration(person.total_seconds) : '0'}</span>
                </Fact>
                <Fact label={t('app-usage.facts.browser')}>
                    <span className="num">{person.browser_seconds ? duration(person.browser_seconds) : '0'}</span>
                </Fact>
                <Fact label={t('app-usage.facts.shifts')}>
                    <span className="num text-[15px] font-semibold">{shiftText}</span>
                </Fact>
            </dl>

            {person.from_archive && <p className="m-0 mt-4 text-sm text-muted">{t('app-usage.archive_note', { days: activeDays })}</p>}

            {person.sessions.length === 0 ? (
                <div className="card mt-8 px-5 py-6 sm:px-6">
                    <p className="m-0 font-semibold">{t('app-usage.empty_title')}</p>
                    <p className="m-0 mt-1 max-w-[70ch] text-sm text-muted">{t('app-usage.empty_body')}</p>
                </div>
            ) : (
                <>
                    <section className="mt-10" aria-labelledby="apps-heading">
                        <h2 id="apps-heading" className="h2 mb-4">
                            {t('app-usage.apps_heading')}
                        </h2>
                        <BarList
                            label={t('app-usage.apps_label')}
                            series={[{ key: 'minutes', label: t('app-usage.series'), color: 'var(--viz-1)' }]}
                            format={(value) => formatMinutes(Math.round(value), locale)}
                            rows={person.apps.map((a) => ({
                                key: a.app,
                                label: a.app,
                                sub: a.browser ? t('app-usage.browser_tag') : undefined,
                                values: [Math.max(1, minutes(a.seconds))],
                                end: duration(a.seconds),
                            }))}
                        />
                    </section>

                    <section className="mt-10" aria-labelledby="detail-heading">
                        <h2 id="detail-heading" className="h2">
                            {t('app-usage.detail_heading')}
                        </h2>
                        <ol className="card rows m-0 mt-3 list-none p-0">
                            {person.sessions.map((s, i) => (
                                <li key={`${s.started_at}-${i}`} className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1 px-4 py-3 sm:grid-cols-[168px_minmax(0,1fr)_auto] sm:px-5">
                                    <span className="num text-sm text-muted sm:pt-px">
                                        {t('app-usage.facts.shift_range', { start: formatTime(s.started_at, locale), end: formatTime(s.ended_at, locale) })}
                                    </span>
                                    <span className="col-start-1 row-start-2 min-w-0 sm:col-start-2 sm:row-start-1">
                                        <span className="flex items-center gap-1.5 font-semibold">
                                            {s.browser && <Browser weight="bold" size={16} aria-label={t('app-usage.browser_tag')} className="flex-none text-teal-text" />}
                                            <span className="truncate">{s.app}</span>
                                        </span>
                                        <span className={`block text-sm break-words ${s.title ? '' : 'text-muted'}`}>{s.title ?? t('app-usage.no_title')}</span>
                                    </span>
                                    <span className="num col-start-2 row-span-2 row-start-1 text-sm whitespace-nowrap sm:col-start-3 sm:row-span-1">{duration(s.seconds)}</span>
                                </li>
                            ))}
                        </ol>
                    </section>
                </>
            )}
        </div>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="border-line py-4 not-first:border-t sm:px-6 sm:not-first:border-t-0 sm:not-first:border-l sm:first:pl-0">
            <dt className="text-sm text-muted">{label}</dt>
            <dd className="m-0 mt-1 text-[17px] font-semibold">{children}</dd>
        </div>
    );
}
