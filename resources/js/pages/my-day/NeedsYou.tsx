import { Dialog } from '@/components/ui/Dialog';
import { TextAreaField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { formatDateTime, formatMinutes, formatShortDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, useForm, usePage } from '@inertiajs/react';
import { ChatCircleText, CheckCircle, ClockCounterClockwise, NotePencil, WarningCircle } from '@phosphor-icons/react';
import { type FormEvent, type ReactNode, useId, useState } from 'react';
import { toStudioInput } from './hooks';
import { errorText, type IdleQuestion, type LateClaim, type ReportDue } from './types';

const visit = { preserveScroll: true, only: ['summary', 'week'] };

/**
 * Perlu kamu: everything on Hari ini that waits for this person's answer, in one list (docs/desainUI_v2). Overtime
 * reports still due and late claims come from any device (3.4.2, 3.3.6, 3.5.5); with web clock-in turned off they
 * stay listed without the forms, because those endpoints refuse (3.11.8). Decisions waiting in Persetujuan and in
 * tasks are counts that link to their page.
 */
export function NeedsYou({
    reports,
    claims,
    questions,
    reasonMin,
    webEnabled,
}: {
    reports: ReportDue[];
    claims: LateClaim[];
    questions: IdleQuestion[];
    reasonMin: number;
    webEnabled: boolean;
}) {
    const t = useT();
    const badges = (usePage<SharedProps>().props.nav_badges ?? {}) as Record<string, number>;
    const approvals = badges.approvals ?? 0;
    const tasks = badges.my_tasks ?? 0;
    const count = reports.length + claims.length + questions.length + (approvals > 0 ? 1 : 0) + (tasks > 0 ? 1 : 0);

    return (
        <section className="card px-5 pt-4 pb-1.5 sm:px-6" aria-labelledby="needs-you">
            <h2 id="needs-you" className="h2 flex items-center gap-2">
                {t('my-day.needs.heading')}
                {count > 0 && <span className="num font-medium text-muted">{count}</span>}
            </h2>
            {count === 0 ? (
                <p className="m-0 flex items-start gap-2.5 py-4 text-muted">
                    <CheckCircle weight="bold" size={18} aria-hidden className="mt-0.5 flex-none text-success" />
                    {t('my-day.needs.empty')}
                </p>
            ) : (
                <ul className="rows m-0 mt-1 list-none p-0">
                    {reports.map((report) => (
                        <ReportItem key={`report-${report.shift_id}`} report={report} canWrite={webEnabled} />
                    ))}
                    {claims.map((claim) => (
                        <ClaimItem key={`claim-${claim.shift_id}`} claim={claim} reasonMin={reasonMin} canWrite={webEnabled} />
                    ))}
                    {questions.map((question) => (
                        <IdleQuestionItem key={`idle-${question.id}`} question={question} />
                    ))}
                    {approvals > 0 && <LinkItem text={t('my-day.needs.approvals', { count: approvals })} href={route('approvals.index')} />}
                    {tasks > 0 && <LinkItem text={t('my-day.needs.tasks', { count: tasks })} href={route('projects.mine')} />}
                </ul>
            )}
            {!webEnabled && reports.length + claims.length > 0 && <p className="m-0 pb-3 text-[13px] text-muted">{t('my-day.follow_ups.desktop_only')}</p>}
        </section>
    );
}

/** One waiting thing: what it is, the detail, and the one action that answers it. */
function Row({ title, detail, action, children }: { title: ReactNode; detail?: ReactNode; action?: ReactNode; children?: ReactNode }) {
    return (
        <li className="flex flex-col gap-2.5 py-3.5">
            <span className="flex items-start gap-2.5">
                <WarningCircle weight="bold" size={18} aria-hidden className="mt-0.5 flex-none text-gold-text" />
                <span className="flex min-w-0 flex-col gap-0.5">
                    <span className="font-semibold break-words">{title}</span>
                    {detail && <span className="num text-sm text-muted">{detail}</span>}
                </span>
            </span>
            {action && <span className="pl-7">{action}</span>}
            {children}
        </li>
    );
}

function LinkItem({ text, href }: { text: string; href: string }) {
    const t = useT();

    return (
        <Row
            title={text}
            action={
                <Link href={href} className="btn btn-secondary btn-sm min-h-11">
                    {t('my-day.needs.open')}
                </Link>
            }
        />
    );
}

/** A lead's question about one PC diam period; the answer goes back to the lead's PC diam tab. */
function IdleQuestionItem({ question }: { question: IdleQuestion }) {
    const t = useT();
    const locale = useLocale();
    const [open, setOpen] = useState(false);
    const titleId = useId();
    const form = useForm({ answer: '' });
    const name = question.asked_by ?? '';
    const date = question.work_date ? formatShortDate(question.work_date, locale) : '';
    const title = question.ended_at
        ? t('my-day.needs.idle_question', { name, date, start: formatTime(question.started_at, locale), end: formatTime(question.ended_at, locale) })
        : t('my-day.needs.idle_question_open', { name, date, start: formatTime(question.started_at, locale) });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('idle-reviews.answer', question.id), { preserveScroll: true, only: ['idle_questions', 'nav_badges'], onSuccess: () => setOpen(false) });
    };

    return (
        <Row
            title={title}
            detail={
                <>
                    {question.minutes !== null && formatMinutes(question.minutes, locale)}
                    {question.question && <span className="mt-1 block text-ink">&ldquo;{question.question}&rdquo;</span>}
                </>
            }
            action={
                <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => setOpen(true)}>
                    <ChatCircleText weight="bold" size={16} aria-hidden />
                    {t('my-day.needs.idle_answer')}
                </button>
            }
        >
            <Dialog open={open} onClose={() => setOpen(false)} labelledBy={titleId} closeOnBackdrop={false}>
                <form onSubmit={submit} className="flex flex-col gap-4 px-5 py-5 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {t('my-day.needs.idle_answer_title')}
                    </h2>
                    <div className="rounded-md bg-panel px-4 py-3 text-sm">
                        <p className="num m-0 font-semibold">{title}</p>
                        {question.question && <p className="m-0 mt-1">&ldquo;{question.question}&rdquo;</p>}
                        {question.tag && <p className="m-0 mt-1 text-muted">{t('my-day.needs.idle_tagged', { tag: t(`my-day.idle_tags.${question.tag}`) })}</p>}
                    </div>
                    <TextAreaField
                        autoFocus
                        label={t('my-day.needs.idle_answer_label')}
                        help={t('my-day.needs.idle_answer_help', { name })}
                        value={form.data.answer}
                        onChange={(e) => form.setData('answer', e.target.value)}
                        error={form.errors.answer === 'answer_closed' ? t('my-day.needs.idle_closed') : form.errors.answer}
                        maxLength={1000}
                        required
                    />
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <button type="submit" className="btn btn-primary" disabled={form.processing || form.data.answer.trim().length < 5}>
                            {form.processing ? t('my-day.saving') : t('my-day.needs.idle_answer_submit')}
                        </button>
                        <button type="button" className="btn btn-quiet" onClick={() => setOpen(false)}>
                            {t('my-day.back')}
                        </button>
                    </div>
                </form>
            </Dialog>
        </Row>
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
        <Row
            title={t('my-day.report.item', { date: formatShortDate(report.work_date, locale) })}
            detail={
                <>
                    {range} ({formatMinutes(report.overtime_minutes, locale)})
                    {report.status === 'needs_review' && ` · ${t('my-day.shift_flags.needs_review')}`}
                </>
            }
            action={
                canWrite && (
                    <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => setOpen(true)}>
                        <NotePencil weight="bold" size={16} aria-hidden />
                        {t('my-day.report.open')}
                    </button>
                )
            }
        >
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
        </Row>
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
        <Row
            title={t(why, { time: formatTime(claim.auto_ended_at, locale) })}
            detail={t('my-day.claim.until', { time: formatDateTime(claim.claimable_until, locale) })}
            action={
                canWrite && (
                    <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => setOpen(true)}>
                        <ClockCounterClockwise weight="bold" size={16} aria-hidden />
                        {t('my-day.claim.open')}
                    </button>
                )
            }
        >
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
        </Row>
    );
}
