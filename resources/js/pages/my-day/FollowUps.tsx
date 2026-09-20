import { Dialog } from '@/components/ui/Dialog';
import { TextAreaField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { formatDateTime, formatMinutes, formatShortDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { ClockCounterClockwise, NotePencil } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';
import { toStudioInput } from './hooks';
import { errorText, type LateClaim, type ReportDue } from './types';

const visit = { preserveScroll: true, only: ['summary'] };

/**
 * Overtime reports still due and late claims still possible, from any device (3.4.2, 3.3.6, 3.5.5). With web
 * clock-in turned off the list stays, without the forms, because those endpoints refuse (3.11.8).
 */
export function FollowUps({ reports, claims, reasonMin, webEnabled }: { reports: ReportDue[]; claims: LateClaim[]; reasonMin: number; webEnabled: boolean }) {
    const t = useT();

    if (reports.length === 0 && claims.length === 0) return null;

    return (
        <section className="card mt-6 px-5 py-[18px]" aria-labelledby="today-follow-ups">
            <h2 id="today-follow-ups" className="h2">
                {t('my-day.follow_ups.heading')}
            </h2>
            <ul className="m-0 mt-3 list-none divide-y divide-line p-0">
                {reports.map((report) => (
                    <ReportItem key={`report-${report.shift_id}`} report={report} canWrite={webEnabled} />
                ))}
                {claims.map((claim) => (
                    <ClaimItem key={`claim-${claim.shift_id}`} claim={claim} reasonMin={reasonMin} canWrite={webEnabled} />
                ))}
            </ul>
            {!webEnabled && <p className="m-0 mt-2 text-[13px] text-muted">{t('my-day.follow_ups.desktop_only')}</p>}
        </section>
    );
}

function ReportItem({ report, canWrite }: { report: ReportDue; canWrite: boolean }) {
    const t = useT();
    const locale = useLocale();
    const [open, setOpen] = useState(false);
    const titleId = useId();
    const form = useForm({ shift_id: report.shift_id, work_report: '' });
    const range =
        report.overtime_started_at && report.overtime_ended_at
            ? t('my-day.report.range', { start: formatTime(report.overtime_started_at, locale), end: formatTime(report.overtime_ended_at, locale) })
            : '';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('web-clock.report'), { ...visit, onSuccess: () => setOpen(false) });
    };

    return (
        <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
            <span className="flex flex-col gap-0.5">
                <span className="font-semibold">{t('my-day.report.item', { date: formatShortDate(report.work_date, locale) })}</span>
                <span className="num text-sm text-muted">
                    {range} ({formatMinutes(report.overtime_minutes, locale)})
                    {report.status === 'needs_review' && ` · ${t('my-day.shift_flags.needs_review')}`}
                </span>
            </span>
            {canWrite && (
                <button type="button" className="btn btn-secondary btn-sm min-h-[44px] self-start sm:self-center" onClick={() => setOpen(true)}>
                    <NotePencil weight="bold" size={16} aria-hidden />
                    {t('my-day.report.open')}
                </button>
            )}

            <Dialog open={open} onClose={() => setOpen(false)} labelledBy={titleId} closeOnBackdrop={false}>
                <form onSubmit={submit} className="flex flex-col gap-4 px-5 py-5 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {t('my-day.report.title')}
                    </h2>
                    <div className="rounded-md bg-panel px-4 py-3">
                        <p className="m-0 text-sm text-muted">{formatShortDate(report.work_date, locale)}</p>
                        <p className="num m-0 font-display text-[22px] font-bold text-heading">{range}</p>
                        <p className="num m-0 font-semibold">{formatMinutes(report.overtime_minutes, locale)}</p>
                    </div>
                    {report.overtime_reason && <p className="m-0 border-l-2 border-line-strong pl-3 text-sm text-muted">{t('my-day.report.your_reason', { reason: report.overtime_reason })}</p>}
                    <TextAreaField
                        autoFocus
                        label={t('my-day.report.label')}
                        value={form.data.work_report}
                        onChange={(e) => form.setData('work_report', e.target.value)}
                        error={errorText(t, form.errors.work_report)}
                        maxLength={5000}
                        required
                    />
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <button type="submit" className="btn btn-primary" disabled={form.processing || form.data.work_report.trim() === ''}>
                            {form.processing ? t('my-day.saving') : t('my-day.report.submit')}
                        </button>
                        <button type="button" className="btn btn-quiet" onClick={() => setOpen(false)}>
                            {t('my-day.report.later')}
                        </button>
                    </div>
                </form>
            </Dialog>
        </li>
    );
}

function ClaimItem({ claim, reasonMin, canWrite }: { claim: LateClaim; reasonMin: number; canWrite: boolean }) {
    const t = useT();
    const locale = useLocale();
    const [open, setOpen] = useState(false);
    const titleId = useId();
    const form = useForm({ shift_id: claim.shift_id, ended_at: toStudioInput(claim.latest_end_at), reason: '', work_report: '' });
    const why = claim.overtime_end_reason === 'presence_check_no_answer' ? 'my-day.claim.why_presence' : 'my-day.claim.why_prompt';
    const minEnd = toStudioInput(new Date(new Date(claim.auto_ended_at).getTime() + 60_000).toISOString());

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('web-clock.claim'), { ...visit, onSuccess: () => setOpen(false) });
    };

    return (
        <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
            <span className="flex flex-col gap-0.5">
                <span className="num font-semibold">{t(why, { time: formatTime(claim.auto_ended_at, locale) })}</span>
                <span className="num text-sm text-muted">{t('my-day.claim.until', { time: formatDateTime(claim.claimable_until, locale) })}</span>
            </span>
            {canWrite && (
                <button type="button" className="btn btn-secondary btn-sm min-h-[44px] self-start sm:self-center" onClick={() => setOpen(true)}>
                    <ClockCounterClockwise weight="bold" size={16} aria-hidden />
                    {t('my-day.claim.open')}
                </button>
            )}

            <Dialog open={open} onClose={() => setOpen(false)} labelledBy={titleId} closeOnBackdrop={false}>
                <form onSubmit={submit} className="flex flex-col gap-4 px-5 py-5 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {t('my-day.claim.title')}
                    </h2>
                    <p className="num m-0 text-sm">{t(why, { time: formatTime(claim.auto_ended_at, locale) })}</p>
                    <TextField
                        type="datetime-local"
                        label={t('my-day.claim.ended_at')}
                        help={t('my-day.claim.ended_help', { time: formatDateTime(claim.latest_end_at, locale) })}
                        value={form.data.ended_at}
                        min={minEnd}
                        max={toStudioInput(claim.latest_end_at)}
                        onChange={(e) => form.setData('ended_at', e.target.value)}
                        error={errorText(t, form.errors.ended_at, { time: formatDateTime(claim.latest_end_at, locale) })}
                        required
                    />
                    <TextAreaField
                        label={t('my-day.claim.reason')}
                        help={t('my-day.reason_help')}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        error={form.errors.reason}
                        maxLength={2000}
                        required
                    />
                    <TextAreaField
                        label={t('my-day.report.label')}
                        value={form.data.work_report}
                        onChange={(e) => form.setData('work_report', e.target.value)}
                        error={form.errors.work_report}
                        maxLength={5000}
                        required
                    />
                    {(form.errors as Record<string, string>).shift_id && <Notice tone="danger">{(form.errors as Record<string, string>).shift_id}</Notice>}
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <button
                            type="submit"
                            className="btn btn-primary"
                            disabled={form.processing || form.data.reason.trim().length < reasonMin || form.data.work_report.trim() === ''}
                        >
                            {form.processing ? t('my-day.saving') : t('my-day.claim.submit')}
                        </button>
                        <button type="button" className="btn btn-quiet" onClick={() => setOpen(false)}>
                            {t('my-day.back')}
                        </button>
                    </div>
                </form>
            </Dialog>
        </li>
    );
}
