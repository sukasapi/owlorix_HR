import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextAreaField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { formatDateTime, formatMinutes, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import { ChatCircleText, CheckCircle, HourglassMedium } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';

type Tag = 'rendering' | 'meeting' | 'break' | 'other';

interface Review {
    status: 'checked' | 'asked' | 'answered';
    question: string | null;
    answer: string | null;
    asked_by: string | null;
    asked_at: string | null;
    answered_at: string | null;
    checked_by: string | null;
    checked_at: string | null;
}

interface Period {
    shift_id: number;
    started_at: string;
    ended_at: string | null;
    minutes: number;
    tag: Tag | null;
    note: string | null;
    review: Review | null;
}

interface Group {
    id: number;
    name: string;
    total_minutes: number;
    untagged: number;
    periods: Period[];
}

interface Props {
    filters: { tanggal: string; orang: number | null; belum: boolean };
    today: string;
    people: { id: number; name: string; username: string }[];
    groups: Group[];
}

/**
 * Tim hari ini, tab PC diam: one studio day of quiet PC time per person, the tag and note they gave, and the lead's
 * review. "Sudah dicek" closes a period; "Minta penjelasan" puts a question on that person's Hari ini.
 */
export default function TeamIdle({ filters, today, people, groups }: Props) {
    const t = useT();

    const apply = (next: Partial<Props['filters']>) => {
        const q = { ...filters, ...next };
        router.get(route('team.idle'), { tanggal: q.tanggal, orang: q.orang ?? undefined, belum: q.belum ? 1 : undefined }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AppShell title={t('team-idle.title')}>
            <header className="mb-6">
                <h1 className="h1">{t('team-idle.title')}</h1>
                <p className="m-0 mt-1.5 max-w-[72ch] text-muted">{t('team-idle.lead')}</p>
            </header>

            {people.length === 0 ? (
                <div className="card px-5 py-6 sm:px-6">
                    <p className="m-0">{t('team-idle.empty_scope')}</p>
                </div>
            ) : (
                <>
                    <form role="search" aria-label={t('team-idle.filters.label')} className="grid items-end gap-3 sm:grid-cols-[200px_minmax(0,320px)_auto]" onSubmit={(e) => e.preventDefault()}>
                        <TextField label={t('team-idle.filters.date')} type="date" max={today} value={filters.tanggal} onChange={(e) => e.target.value && apply({ tanggal: e.target.value })} />
                        <SelectField label={t('team-idle.filters.person')} value={filters.orang ?? ''} onChange={(e) => apply({ orang: e.target.value === '' ? null : Number(e.target.value) })}>
                            <option value="">{t('team-idle.filters.everyone')}</option>
                            {people.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </SelectField>
                        <label className="flex min-h-11 cursor-pointer items-center gap-2.5 text-[15px] font-semibold">
                            <input type="checkbox" className="size-5 accent-[var(--primary-bg)]" checked={filters.belum} onChange={(e) => apply({ belum: e.target.checked })} />
                            {t('team-idle.filters.unchecked')}
                        </label>
                    </form>

                    {groups.length === 0 ? (
                        <div className="card mt-8 px-5 py-6 sm:px-6">
                            <p className="m-0 font-semibold">{filters.belum ? t('team-idle.empty_unchecked') : t('team-idle.empty_title')}</p>
                            {!filters.belum && <p className="m-0 mt-1 text-sm text-muted">{t('team-idle.empty_body')}</p>}
                        </div>
                    ) : (
                        <div className="mt-8 flex flex-col gap-10">
                            {groups.map((group) => (
                                <PersonIdle key={group.id} group={group} />
                            ))}
                        </div>
                    )}
                </>
            )}
        </AppShell>
    );
}

function PersonIdle({ group }: { group: Group }) {
    const t = useT();
    const locale = useLocale();
    const headingId = useId();

    return (
        <section aria-labelledby={headingId}>
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id={headingId} className="h2">
                    {group.name}
                </h2>
                <p className="num m-0 text-sm text-muted">
                    {t('team-idle.summary', { duration: formatMinutes(group.total_minutes, locale) })}
                    {group.untagged > 0 && `, ${t('team-idle.summary_untagged', { count: group.untagged })}`}
                </p>
            </div>
            <ul className="card rows m-0 mt-3 list-none p-0">
                {group.periods.map((period) => (
                    <PeriodRow key={`${period.shift_id}-${period.started_at}`} period={period} person={group.name} />
                ))}
            </ul>
        </section>
    );
}

function PeriodRow({ period, person }: { period: Period; person: string }) {
    const t = useT();
    const locale = useLocale();
    const [asking, setAsking] = useState(false);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const review = period.review;
    const time = (iso: string | null) => (iso ? formatDateTime(iso, locale) : '');

    const check = () => {
        setFailed(false);
        router.post(
            route('team.idle.check'),
            { shift_id: period.shift_id, started_at: period.started_at },
            { preserveScroll: true, only: ['groups'], onStart: () => setBusy(true), onFinish: () => setBusy(false), onError: () => setFailed(true) },
        );
    };

    return (
        <li className="grid gap-x-6 gap-y-3 px-4 py-4 sm:grid-cols-[150px_minmax(0,1fr)_auto] sm:px-5">
            <div className="num">
                <p className="m-0 font-semibold">
                    {period.ended_at
                        ? t('team-idle.range', { start: formatTime(period.started_at, locale), end: formatTime(period.ended_at, locale) })
                        : t('team-idle.open', { start: formatTime(period.started_at, locale) })}
                </p>
                <p className="m-0 text-sm text-muted">{formatMinutes(period.minutes, locale)}</p>
            </div>

            <div className="flex min-w-0 flex-col gap-1.5">
                <p className="m-0">
                    {period.tag ? <span className="chip chip-info">{t(`team-idle.tags.${period.tag}`)}</span> : <span className="text-muted">{t('team-idle.untagged')}</span>}
                </p>
                {period.note && <p className="m-0 text-sm break-words">{period.note}</p>}
                {review?.status === 'checked' && (
                    <p className="m-0 flex items-center gap-1.5 text-sm text-success">
                        <CheckCircle weight="bold" size={16} aria-hidden className="flex-none" />
                        {t('team-idle.review.checked', { name: review.checked_by ?? '', time: time(review.checked_at) })}
                    </p>
                )}
                {review?.status === 'asked' && (
                    <div className="flex flex-col gap-1 text-sm">
                        <span className="chip chip-pending self-start">
                            <HourglassMedium weight="bold" size={15} aria-hidden />
                            {t('team-idle.review.asked', { name: person })}
                        </span>
                        {review.question && <p className="m-0 break-words">&ldquo;{review.question}&rdquo;</p>}
                        <p className="m-0 text-muted">{t('team-idle.review.asked_by', { name: review.asked_by ?? '', time: time(review.asked_at) })}</p>
                    </div>
                )}
                {review?.status === 'answered' && (
                    <div className="flex flex-col gap-1 rounded-md bg-panel px-3 py-2.5 text-sm">
                        {review.question && <p className="m-0 text-muted break-words">&ldquo;{review.question}&rdquo;</p>}
                        <p className="m-0 flex items-center gap-1.5 font-semibold">
                            <ChatCircleText weight="bold" size={16} aria-hidden className="flex-none" />
                            {t('team-idle.review.answered', { time: time(review.answered_at) })}
                        </p>
                        <p className="m-0 break-words whitespace-pre-line">{review.answer}</p>
                    </div>
                )}
                {failed && (
                    <Notice tone="danger" className="mt-1">
                        {t('team-idle.failed')}
                    </Notice>
                )}
            </div>

            <div className="flex flex-wrap items-start gap-2 sm:flex-col sm:items-stretch">
                {review?.status !== 'checked' && (
                    <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={check} disabled={busy}>
                        <CheckCircle weight="bold" size={16} aria-hidden />
                        {t('team-idle.actions.check')}
                    </button>
                )}
                {review?.status !== 'asked' && (
                    <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => setAsking(true)} disabled={busy}>
                        <ChatCircleText weight="bold" size={16} aria-hidden />
                        {review?.status === 'answered' ? t('team-idle.actions.ask_again') : t('team-idle.actions.ask')}
                    </button>
                )}
            </div>

            {asking && <AskDialog period={period} person={person} onClose={() => setAsking(false)} />}
        </li>
    );
}

function AskDialog({ period, person, onClose }: { period: Period; person: string; onClose: () => void }) {
    const t = useT();
    const titleId = useId();
    const form = useForm({ shift_id: period.shift_id, started_at: period.started_at, question: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('team.idle.ask'), { preserveScroll: true, only: ['groups'], onSuccess: onClose });
    };

    return (
        <Dialog open onClose={onClose} labelledBy={titleId}>
            <form onSubmit={submit} className="flex flex-col gap-4 px-5 py-5 sm:px-7">
                <h2 id={titleId} className="h2">
                    {t('team-idle.ask_dialog.title')}
                </h2>
                <TextAreaField
                    autoFocus
                    label={t('team-idle.ask_dialog.label')}
                    help={t('team-idle.ask_dialog.help', { name: person })}
                    placeholder={t('team-idle.ask_dialog.placeholder')}
                    value={form.data.question}
                    onChange={(e) => form.setData('question', e.target.value)}
                    error={form.errors.question}
                    maxLength={500}
                    rows={3}
                />
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? t('common.actions.saving') : t('team-idle.ask_dialog.submit')}
                    </button>
                    <button type="button" className="btn btn-quiet" onClick={onClose}>
                        {t('common.actions.cancel')}
                    </button>
                </div>
            </form>
        </Dialog>
    );
}
