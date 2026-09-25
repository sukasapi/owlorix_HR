import { SelectField, TextField } from '@/components/ui/Field';
import AppShell from '@/layouts/AppShell';
import { formatMinutes, formatShortDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { Link, router } from '@inertiajs/react';
import { ArrowSquareOut, CaretLeft, CaretRight } from '@phosphor-icons/react';

interface Entry {
    id: number;
    person: string | null;
    project: { id: number; name: string; code: string | null } | null;
    task: { id: number; title: string } | null;
    description: string;
    /** Studio date (Asia/Jakarta) */
    date: string;
    started_at: string;
    ended_at: string;
    minutes: number;
    evidence_url: string | null;
}

interface Props {
    filters: { orang: number | null; proyek: number | null; dari: string; sampai: string };
    today: string;
    people: { id: number; name: string; username: string }[];
    projects: { id: number; name: string; code: string | null }[];
    total_minutes: number;
    entries: { data: Entry[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
}

/** Tim hari ini, tab Log kerja: what the team wrote in their work logs, newest first. Read only. */
export default function TeamLogs({ filters, today, people, projects, total_minutes, entries }: Props) {
    const t = useT();
    const locale = useLocale();

    const apply = (next: Partial<Props['filters']>) => {
        const q = { ...filters, ...next };
        router.get(route('team.logs'), { orang: q.orang ?? undefined, proyek: q.proyek ?? undefined, dari: q.dari, sampai: q.sampai }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AppShell title={t('team-logs.title')}>
            <header className="mb-6">
                <h1 className="h1">{t('team-logs.title')}</h1>
                <p className="m-0 mt-1.5 max-w-[72ch] text-muted">{t('team-logs.lead')}</p>
            </header>

            {people.length === 0 ? (
                <div className="card px-5 py-6 sm:px-6">
                    <p className="m-0">{t('team-logs.empty_scope')}</p>
                </div>
            ) : (
                <>
                    <form role="search" aria-label={t('team-logs.filters.label')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,240px)_minmax(0,260px)_180px_180px]" onSubmit={(e) => e.preventDefault()}>
                        <SelectField label={t('team-logs.filters.person')} value={filters.orang ?? ''} onChange={(e) => apply({ orang: e.target.value === '' ? null : Number(e.target.value) })}>
                            <option value="">{t('team-logs.filters.everyone')}</option>
                            {people.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField label={t('team-logs.filters.project')} value={filters.proyek ?? ''} onChange={(e) => apply({ proyek: e.target.value === '' ? null : Number(e.target.value) })}>
                            <option value="">{t('team-logs.filters.all_projects')}</option>
                            {projects.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.code ? `${p.code}, ${p.name}` : p.name}
                                </option>
                            ))}
                        </SelectField>
                        <TextField label={t('team-logs.filters.from')} type="date" max={filters.sampai} value={filters.dari} onChange={(e) => e.target.value && apply({ dari: e.target.value })} />
                        <TextField label={t('team-logs.filters.to')} type="date" min={filters.dari} max={today} value={filters.sampai} onChange={(e) => e.target.value && apply({ sampai: e.target.value })} />
                    </form>

                    {entries.total === 0 ? (
                        <div className="card mt-8 px-5 py-6 sm:px-6">
                            <p className="m-0 font-semibold">{t('team-logs.empty_title')}</p>
                            <p className="m-0 mt-1 text-sm text-muted">{t('team-logs.empty_body')}</p>
                        </div>
                    ) : (
                        <>
                            <p className="num m-0 mt-6 text-sm text-muted">{t('team-logs.summary', { count: entries.total, duration: formatMinutes(total_minutes, locale) })}</p>
                            <ol className="card rows m-0 mt-3 list-none p-0">
                                {entries.data.map((entry) => (
                                    <li key={entry.id} className="grid gap-x-6 gap-y-2 px-4 py-4 sm:grid-cols-[150px_minmax(0,1fr)_auto] sm:px-5">
                                        <div className="num text-sm">
                                            <p className="m-0 font-semibold">{formatShortDate(entry.date, locale)}</p>
                                            <p className="m-0 text-muted">{t('team-logs.range', { start: formatTime(entry.started_at, locale), end: formatTime(entry.ended_at, locale) })}</p>
                                        </div>
                                        <div className="flex min-w-0 flex-col gap-1">
                                            <p className="m-0 font-semibold">{entry.person}</p>
                                            <p className="m-0 text-sm text-muted">
                                                {entry.project ? (entry.project.code ? `${entry.project.code}, ${entry.project.name}` : entry.project.name) : t('team-logs.no_project')}
                                                {entry.task && (
                                                    <>
                                                        {', '}
                                                        <Link href={route('tasks.show', entry.task.id)} className="link">
                                                            {entry.task.title}
                                                        </Link>
                                                    </>
                                                )}
                                            </p>
                                            <p className="m-0 break-words whitespace-pre-line">{entry.description}</p>
                                            {entry.evidence_url && (
                                                <a href={entry.evidence_url} target="_blank" rel="noopener noreferrer" className="link inline-flex min-h-11 items-center gap-1.5 self-start text-sm sm:min-h-0">
                                                    <ArrowSquareOut weight="bold" size={15} aria-hidden />
                                                    {t('team-logs.evidence')}
                                                </a>
                                            )}
                                        </div>
                                        <p className="num m-0 text-sm font-semibold whitespace-nowrap sm:text-right">{formatMinutes(entry.minutes, locale)}</p>
                                    </li>
                                ))}
                            </ol>

                            {entries.last_page > 1 && (
                                <nav aria-label={t('team-logs.pagination.label')} className="mt-4 flex items-center justify-between gap-3">
                                    <PageLink href={entries.prev_page_url} direction="previous" />
                                    <span className="num text-sm text-muted">{t('team-logs.pagination.page', { current: entries.current_page, last: entries.last_page })}</span>
                                    <PageLink href={entries.next_page_url} direction="next" />
                                </nav>
                            )}
                        </>
                    )}
                </>
            )}
        </AppShell>
    );
}

function PageLink({ href, direction }: { href: string | null; direction: 'previous' | 'next' }) {
    const t = useT();
    const label = t(`team-logs.pagination.${direction}`);
    const icon = direction === 'previous' ? <CaretLeft weight="bold" size={16} aria-hidden /> : <CaretRight weight="bold" size={16} aria-hidden />;

    if (!href) return <span aria-hidden className="invisible min-w-11 sm:min-w-[140px]" />;

    return (
        <Link href={href} preserveState className="btn btn-secondary min-w-11 px-3 sm:min-w-[140px]" rel={direction === 'previous' ? 'prev' : 'next'}>
            {direction === 'previous' && icon}
            <span className="sr-only sm:not-sr-only">{label}</span>
            {direction === 'next' && icon}
        </Link>
    );
}
