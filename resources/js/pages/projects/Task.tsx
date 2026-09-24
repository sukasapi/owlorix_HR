import { OwlEyes } from '@/components/owl/OwlEyes';
import { Avatar } from '@/components/ui/Avatar';
import { TextAreaField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { formatDateTime, formatMinutes, formatShortDate, formatTime } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { Person, SharedProps } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, DownloadSimple, HandGrabbing, LinkSimple, Pause, PencilSimple, Play, Trash, WarningCircle } from '@phosphor-icons/react';
import { type FormEvent, type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { AssigneePicker, assigneeError, sameIds } from './AssigneePicker';
import { PartChip, PersonLine, PriorityMark, StatusChip } from './TaskBits';
import { TaskDialog } from './TaskDialog';
import type { Assignee, AssigneeOption, PartStatus, RunningTimer, StageOption, SubProjectData, TaskPriority, TaskRow } from './taskTypes';

/** An assignee on the task page: their part plus their own timer minutes. */
interface AssigneeDetail extends Assignee {
    logged_minutes: number;
    running: boolean;
}

interface TaskDetail extends TaskRow {
    assignees: AssigneeDetail[];
    my_part: PartStatus | null;
    description: string | null;
    decider: Person | null;
    decided_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    project: { id: number; name: string; code: string | null };
    sub_project: SubProjectData;
}

interface Session {
    id: number;
    user: Person | null;
    started_at: string;
    ended_at: string | null;
    minutes: number;
    logged: boolean;
}

interface Submission {
    id: number;
    note: string;
    evidence_url: string | null;
    file: { name: string | null; size: number | null; url: string } | null;
    review_status: 'pending' | 'approved' | 'changes_requested';
    review_note: string | null;
    reviewer: Person | null;
    reviewed_at: string | null;
    submitted_by: Person | null;
    created_at: string | null;
    /** Pending and still up for review (false once the sender left the task or sent again) */
    waiting: boolean;
    /** The viewer may review this one now */
    can_review: boolean;
}

interface PageProps {
    task: TaskDetail;
    sessions: Session[];
    submissions: Submission[];
    running: RunningTimer | null;
    unlogged: { count: number; minutes: number };
    can: { update: boolean; delete: boolean; decide: boolean; claim: boolean; work: boolean; review: boolean; lead: boolean };
    people: AssigneeOption[];
    max_assignees: number;
    priorities: TaskPriority[];
    stages: StageOption[];
    limits: { file_max_kb: number; file_types: string };
}

const visit = { preserveScroll: true };

export default function TaskShow() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const { task, can } = props;
    const [editing, setEditing] = useState(false);

    const remove = () => {
        if (!window.confirm(t('tasks.detail.delete_confirm'))) return;
        router.delete(route('tasks.destroy', task.id));
    };

    return (
        <AppShell title={task.title}>
            <p className="m-0 text-sm text-muted">
                <Link href={route('projects.show', task.project.id)} className="link">
                    {task.project.name}
                </Link>
                {' / '}
                <Link href={route('projects.sub.show', [task.project.id, task.sub_project.id])} className="link">
                    {task.sub_project.name}
                </Link>
            </p>
            <div className="mt-1 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="h1 break-words">{task.title}</h1>
                    <div className="mt-2 flex flex-wrap items-center gap-3">
                        <StatusChip status={task.status} />
                        <PriorityMark priority={task.priority} />
                    </div>
                </div>
                {(can.update || can.delete) && (
                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <button type="button" className="btn btn-secondary" onClick={() => setEditing(true)}>
                                <PencilSimple weight="bold" size={18} aria-hidden />
                                {t('tasks.detail.edit')}
                            </button>
                        )}
                        {can.delete && (
                            <button type="button" className="btn btn-quiet" onClick={remove}>
                                <Trash weight="bold" size={18} aria-hidden />
                                {t('tasks.detail.delete')}
                            </button>
                        )}
                    </div>
                )}
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start">
                <div className="flex min-w-0 flex-col gap-7">
                    <ActionPanel />
                    <Assignees />
                    <section aria-labelledby="task-details">
                        <h2 id="task-details" className="h2 text-[20px]">
                            {t('tasks.detail.description')}
                        </h2>
                        <p className={`m-0 mt-2 max-w-[70ch] whitespace-pre-line break-words ${task.description ? '' : 'text-muted'}`}>{task.description || t('tasks.detail.no_description')}</p>
                    </section>
                    <Submissions />
                    <Sessions />
                </div>
                <Facts />
            </div>

            {editing && (
                <TaskDialog
                    mode="edit"
                    lead={can.lead}
                    task={{
                        id: task.id,
                        title: task.title,
                        description: task.description,
                        priority: task.priority,
                        stage_id: task.stage?.id ?? null,
                        assignee_ids: task.assignees.map((a) => a.id),
                        due_date: task.due_date,
                        estimate_minutes: task.estimate_minutes,
                        evidence_required: task.evidence_required,
                    }}
                    people={props.people}
                    maxAssignees={props.max_assignees}
                    priorities={props.priorities}
                    stages={props.stages}
                    onClose={() => setEditing(false)}
                />
            )}
        </AppShell>
    );
}

/**
 * What this viewer can do on the task right now: the focal point of the page. A lead who is also an assignee sees
 * the review cards and their own timer, one under the other.
 */
function ActionPanel() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const { task, can } = props;
    const toReview = props.submissions.filter((s) => s.can_review);
    const notSent = task.assignees.filter((a) => a.part_status === 'open');
    // A lead who is also an assignee handles their own part in the work form below, so the waiting lines name others
    const me = props.auth?.user.id;
    const othersNotSent = notSent.filter((a) => a.id !== me);
    const othersRevising = task.assignees.filter((a) => a.id !== me && a.part_status === 'changes_requested');

    let eyes: 'open' | 'closed' | 'attention' | 'half' = 'half';
    const blocks: { key: string; node: ReactNode }[] = [];

    if (task.status === 'proposed') {
        eyes = can.decide ? 'attention' : 'half';
        blocks.push({ key: 'decide', node: can.decide ? <DecideForm /> : <p className="m-0">{t('tasks.decide.waiting_lead')}</p> });
    } else if (task.status === 'rejected') {
        eyes = 'closed';
        blocks.push({
            key: 'rejected',
            node: (
                <>
                    <p className="m-0 font-semibold">{t('tasks.decide.rejected_heading')}</p>
                    {task.decision_note && <p className="m-0 mt-1 whitespace-pre-line">{task.decision_note}</p>}
                </>
            ),
        });
    } else {
        if (task.status === 'done') {
            eyes = 'closed';
            blocks.push({ key: 'done', node: <p className="m-0 font-semibold">{t('tasks.work.done', { date: task.completed_at ? formatDateTime(task.completed_at, locale) : '' })}</p> });
        }
        if (toReview.length > 0) {
            eyes = 'attention';
            blocks.push({ key: 'review', node: <ReviewQueue items={toReview} /> });
        }
        if (can.lead && othersNotSent.length > 0) {
            blocks.push({
                key: 'waiting',
                node: <p className="m-0">{t('tasks.work.waiting_others', { count: othersNotSent.length, names: othersNotSent.map((a) => a.name).join(', ') })}</p>,
            });
        }
        if (can.lead && othersRevising.length > 0) {
            blocks.push({
                key: 'revising',
                node: <p className="m-0">{t('tasks.work.waiting_changes', { names: othersRevising.map((a) => a.name).join(', ') })}</p>,
            });
        }
        if (can.claim) {
            eyes = toReview.length > 0 ? eyes : 'open';
            blocks.push({ key: 'claim', node: <ClaimAction /> });
        }
        if (can.work) {
            eyes = toReview.length > 0 ? eyes : 'open';
            blocks.push({ key: 'work', node: <WorkPanel /> });
        } else if (task.my_part === 'submitted') {
            blocks.push({ key: 'sent', node: <p className="m-0">{notSent.length > 0 ? t('tasks.work.my_part_sent_waiting', { count: notSent.length }) : t('tasks.work.my_part_sent')}</p> });
        } else if (task.my_part === 'approved' && task.status !== 'done') {
            blocks.push({ key: 'approved', node: <p className="m-0">{t('tasks.work.my_part_approved')}</p> });
        }
        if (blocks.length === 0) {
            const text = can.lead
                ? task.assignees.length === 0
                    ? t('tasks.detail.people_empty_lead')
                    : t('tasks.work.in_review')
                : task.status === 'in_review'
                  ? t('tasks.work.in_review')
                  : t('tasks.work.not_yours');
            blocks.push({ key: 'none', node: <p className="m-0">{text}</p> });
        }
    }

    return (
        <section className="brow px-5 py-6 sm:px-7" aria-label={t('tasks.work.heading')}>
            <div className="flex items-start gap-4">
                <span className="hidden flex-none sm:inline-block">
                    <OwlEyes state={eyes} size={56} />
                </span>
                <div className="flex min-w-0 flex-1 flex-col">
                    {blocks.map((block, i) => (
                        <div key={block.key} className={i > 0 ? 'mt-5 border-t border-[color-mix(in_srgb,var(--eye-brow)_25%,transparent)] pt-5' : ''}>
                            {block.node}
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

function DecideForm() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const { task } = props;
    const initial = task.assignees.map((a) => a.id);
    const form = useForm({ decision: 'approve' as 'approve' | 'reject', note: '', assignee_ids: initial });

    const send = (decision: 'approve' | 'reject') => (event?: FormEvent) => {
        event?.preventDefault();
        form.transform(({ assignee_ids, ...data }) => ({
            ...data,
            decision,
            note: data.note.trim() || null,
            ...(sameIds(assignee_ids, initial) ? {} : { assignee_ids }),
        }));
        form.post(route('tasks.decide', task.id), visit);
    };

    return (
        <form onSubmit={send('approve')} className="flex flex-col gap-4">
            <div>
                <h2 className="h2 text-[22px]">{t('tasks.decide.heading')}</h2>
                {task.creator && task.created_at && <p className="m-0 mt-1 text-sm">{t('tasks.detail.proposed_by', { name: task.creator.name, date: formatDateTime(task.created_at, locale) })}</p>}
            </div>
            <div className="max-w-[520px] rounded-[var(--radius-md)] bg-surface px-4 py-4">
                <AssigneePicker
                    people={props.people}
                    value={form.data.assignee_ids}
                    onChange={(ids) => form.setData('assignee_ids', ids)}
                    max={props.max_assignees}
                    help={t('tasks.decide.assignees_help')}
                    error={assigneeError(form.errors as Record<string, string | undefined>)}
                />
            </div>
            <TextAreaField label={t('tasks.decide.note')} help={t('tasks.decide.note_help')} rows={3} maxLength={2000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} error={form.errors.note} />
            <div className="flex flex-col gap-2 sm:flex-row">
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    <Check weight="bold" size={18} aria-hidden />
                    {t('tasks.decide.approve')}
                </button>
                <button type="button" className="btn btn-danger" disabled={form.processing} onClick={() => send('reject')()}>
                    {t('tasks.decide.reject')}
                </button>
            </div>
        </form>
    );
}

function ClaimAction() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const form = useForm({});

    return (
        <div className="flex flex-col gap-3">
            <p className="m-0">{t('tasks.work.claim_help')}</p>
            <button type="button" className="btn btn-primary self-start" disabled={form.processing} onClick={() => form.post(route('tasks.claim', props.task.id), visit)}>
                <HandGrabbing weight="bold" size={18} aria-hidden />
                {t('tasks.work.claim')}
            </button>
        </div>
    );
}

/** Timer and evidence for the viewer's own part. The running time updates every minute; seconds would only add noise. */
function WorkPanel() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const { task, running } = props;
    const [submitting, setSubmitting] = useState(false);
    const [busy, setBusy] = useState(false);
    const [now, setNow] = useState(() => Date.now());
    const here = running?.task_id === task.id;

    useEffect(() => {
        if (!here) return;
        const id = window.setInterval(() => setNow(Date.now()), 30_000);
        return () => window.clearInterval(id);
    }, [here]);

    const act = (url: string) => {
        setBusy(true);
        router.post(url, {}, { ...visit, onFinish: () => setBusy(false) });
    };

    const elapsed = here && running ? Math.max(0, Math.floor((now - new Date(running.started_at).getTime()) / 60_000)) : 0;

    return (
        <div className="flex flex-col gap-4">
            {task.assignees.length > 1 && <p className="m-0 text-sm font-semibold">{t('tasks.work.shared_hint', { count: task.assignees.length })}</p>}
            {task.my_part === 'changes_requested' && <LatestReviewNote />}
            {here && running ? (
                <div className="flex flex-col gap-3">
                    <p className="display num m-0 text-[40px] text-heading" aria-live="polite">
                        {formatMinutes(elapsed, locale)}
                    </p>
                    <p className="num m-0 text-sm">{t('tasks.work.running', { time: formatTime(running.started_at, locale) })}</p>
                    <button type="button" className="btn btn-secondary self-start bg-surface" disabled={busy} onClick={() => act(route('tasks.stop'))}>
                        <Pause weight="bold" size={18} aria-hidden />
                        {t('tasks.work.stop')}
                    </button>
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    {running && <p className="m-0 text-sm">{t('tasks.work.running_elsewhere', { task: running.task_title })}</p>}
                    <p className="m-0 text-sm">{t('tasks.work.idle_hint')}</p>
                    <button type="button" className="btn btn-primary self-start" disabled={busy} onClick={() => act(route('tasks.start', task.id))}>
                        <Play weight="bold" size={18} aria-hidden />
                        {t('tasks.work.start')}
                    </button>
                </div>
            )}
            <div className="border-t border-[color-mix(in_srgb,var(--eye-brow)_25%,transparent)] pt-4">
                {submitting ? (
                    <SubmitForm onCancel={() => setSubmitting(false)} />
                ) : (
                    <button type="button" className="btn btn-secondary bg-surface" onClick={() => setSubmitting(true)}>
                        {t('tasks.submit.open')}
                    </button>
                )}
            </div>
        </div>
    );
}

/** The lead's note on the viewer's latest evidence that came back for changes. */
function LatestReviewNote() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const me = props.auth?.user.id;
    const latest = props.submissions.find((s) => s.submitted_by?.id === me && s.review_status === 'changes_requested');
    if (!latest?.review_note) return null;

    return (
        <Notice tone="danger">
            <span className="font-semibold">{t('tasks.review.changes_requested')}: </span>
            <span className="whitespace-pre-line">{latest.review_note}</span>
        </Notice>
    );
}

function SubmitForm({ onCancel }: { onCancel: () => void }) {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const { task, unlogged, limits, running } = props;
    const fileRef = useRef<HTMLInputElement>(null);
    const runningHere = running?.task_id === task.id;
    const form = useForm<{ note: string; evidence_url: string; evidence_file: File | null; worked_from: string; worked_until: string }>({
        note: '',
        evidence_url: '',
        evidence_file: null,
        worked_from: '',
        worked_until: '',
    });
    const noSessions = unlogged.count === 0 && !runningHere;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('tasks.submit', task.id), { ...visit, forceFormData: true, onSuccess: onCancel });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <h3 className="h2 text-[20px]">{t('tasks.submit.heading')}</h3>
            <TextAreaField autoFocus label={t('tasks.submit.note')} help={t('tasks.submit.note_help')} rows={4} maxLength={5000} required value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} error={form.errors.note} />
            <TextField
                label={t('tasks.submit.url')}
                help={t('tasks.submit.url_help')}
                type="url"
                inputMode="url"
                placeholder="https://"
                maxLength={500}
                value={form.data.evidence_url}
                onChange={(e) => form.setData('evidence_url', e.target.value)}
                error={form.errors.evidence_url}
            />
            <div className="field">
                <label className="label" htmlFor={`evidence-file-${task.id}`}>
                    {t('tasks.submit.file')}
                </label>
                <input
                    ref={fileRef}
                    id={`evidence-file-${task.id}`}
                    type="file"
                    className="input py-2"
                    accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.mp4,.mov,.webm,.zip"
                    aria-invalid={Boolean(form.errors.evidence_file)}
                    onChange={(e) => form.setData('evidence_file', e.target.files?.[0] ?? null)}
                />
                <p className="help m-0">{t('tasks.submit.file_help', { mb: limits.file_max_kb / 1024 })}</p>
                {form.errors.evidence_file && <ErrorLine>{form.errors.evidence_file}</ErrorLine>}
            </div>
            {task.evidence_required && <p className="m-0 text-sm font-semibold">{t('tasks.submit.required_note')}</p>}

            {noSessions ? (
                <div className="flex flex-col gap-3">
                    <p className="m-0 text-sm">{t('tasks.submit.no_sessions')}</p>
                    <div className="grid gap-[18px] sm:grid-cols-2">
                        <TextField label={t('tasks.submit.worked_from')} type="datetime-local" value={form.data.worked_from} onChange={(e) => form.setData('worked_from', e.target.value)} error={form.errors.worked_from} />
                        <TextField label={t('tasks.submit.worked_until')} type="datetime-local" value={form.data.worked_until} onChange={(e) => form.setData('worked_until', e.target.value)} error={form.errors.worked_until} />
                    </div>
                </div>
            ) : (
                <p className="num m-0 text-sm">
                    {t('tasks.submit.sessions', { count: unlogged.count + (runningHere ? 1 : 0), time: formatMinutes(unlogged.minutes, locale) })}
                </p>
            )}

            <div className="flex flex-col gap-2 sm:flex-row">
                <button type="submit" className="btn btn-primary" disabled={form.processing || form.data.note.trim().length < 10}>
                    {form.processing ? t('common.actions.saving') : t('tasks.submit.submit')}
                </button>
                <button type="button" className="btn btn-quiet" onClick={onCancel}>
                    {t('tasks.submit.cancel')}
                </button>
            </div>
        </form>
    );
}

/** One card per submission the viewer can review. The server sends newest first; the one waiting longest goes on top. */
function ReviewQueue({ items }: { items: Submission[] }) {
    const t = useT();

    return (
        <div className="flex flex-col gap-4">
            <div>
                <h2 className="h2 text-[22px]">{t('tasks.review.heading')}</h2>
                <p className="m-0 mt-1 text-sm">{t('tasks.review.lead')}</p>
            </div>
            <ul className="m-0 flex list-none flex-col gap-3 p-0">
                {[...items].reverse().map((submission) => (
                    <li key={submission.id}>
                        <ReviewCard submission={submission} />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function ReviewCard({ submission }: { submission: Submission }) {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const headingId = useId();
    const form = useForm({ decision: 'approve' as 'approve' | 'changes', note: '' });
    // Approving the last part that is not approved yet finishes the task, so the button says so
    const finishes = props.task.assignees.filter((a) => a.id !== submission.submitted_by?.id).every((a) => a.part_status === 'approved');

    const send = (decision: 'approve' | 'changes') => (event?: FormEvent) => {
        event?.preventDefault();
        form.transform((data) => ({ ...data, decision, submission_id: submission.id, note: data.note.trim() || null }));
        form.post(route('tasks.review', props.task.id), visit);
    };

    return (
        <article className="card flex flex-col gap-3 px-4 py-4" aria-labelledby={headingId}>
            <h3 id={headingId} className="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 text-base font-semibold">
                <PersonLine person={submission.submitted_by} fallback="" />
                <span className="num text-sm font-normal text-muted">{submission.created_at ? formatDateTime(submission.created_at, locale) : ''}</span>
            </h3>
            <SubmissionBody submission={submission} />
            <form onSubmit={send('approve')} className="flex flex-col gap-3">
                <TextAreaField label={t('tasks.review.note')} help={t('tasks.review.note_help')} rows={3} maxLength={2000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} error={form.errors.note} />
                <div className="flex flex-col gap-2 sm:flex-row">
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        <Check weight="bold" size={18} aria-hidden />
                        {finishes ? t('tasks.review.approve_last') : t('tasks.review.approve')}
                    </button>
                    <button type="button" className="btn btn-danger" disabled={form.processing} onClick={() => send('changes')()}>
                        {t('tasks.review.changes')}
                    </button>
                </div>
            </form>
        </article>
    );
}

function SubmissionBody({ submission }: { submission: Submission }) {
    const t = useT();

    return (
        <div className="flex flex-col gap-2">
            <p className="m-0 whitespace-pre-line break-words">{submission.note}</p>
            <div className="flex flex-wrap gap-2">
                {submission.evidence_url && (
                    <a href={submission.evidence_url} target="_blank" rel="noopener noreferrer" className="btn btn-secondary btn-sm min-h-11 max-w-full bg-surface">
                        <LinkSimple weight="bold" size={16} aria-hidden className="flex-none" />
                        <span className="truncate">{t('tasks.history.open_link')}</span>
                    </a>
                )}
                {submission.file && (
                    <a href={submission.file.url} className="btn btn-secondary btn-sm min-h-11 max-w-full bg-surface">
                        <DownloadSimple weight="bold" size={16} aria-hidden className="flex-none" />
                        <span className="truncate">{t('tasks.history.download', { name: submission.file.name ?? '' })}</span>
                    </a>
                )}
            </div>
        </div>
    );
}

/** Pengerja: everyone on the task, the state of their part, and their own timer time (docs/15 section 4). */
function Assignees() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const headingId = useId();
    const { task, can } = props;
    const me = props.auth?.user.id;
    const showParts = task.status !== 'proposed' && task.status !== 'rejected';

    return (
        <section aria-labelledby={headingId}>
            <h2 id={headingId} className="h2 flex items-baseline gap-2 text-[20px]">
                {t('tasks.detail.people_heading')}
                {task.assignees.length > 0 && <span className="num text-base font-normal text-muted">({task.assignees.length})</span>}
            </h2>
            {task.assignees.length === 0 ? (
                <p className="m-0 mt-2 text-muted">{can.lead ? t('tasks.detail.people_empty_lead') : t('tasks.detail.people_empty')}</p>
            ) : (
                <ul className="card m-0 mt-3 list-none divide-y divide-line p-0">
                    {task.assignees.map((person) => (
                        <li key={person.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
                            <span className="inline-flex min-w-0 items-center gap-2.5">
                                <Avatar initials={person.initials} photoUrl={person.photo_url} className="h-8 w-8 text-[12px]" />
                                <span className="min-w-0">
                                    <span className="block truncate font-semibold" title={person.full_name}>
                                        {person.name}
                                        {person.id === me && <span className="font-normal text-muted"> {t('tasks.detail.people_you')}</span>}
                                    </span>
                                    <span className="num block text-sm text-muted">
                                        {person.logged_minutes > 0 ? t('tasks.detail.people_logged', { time: formatMinutes(person.logged_minutes, locale) }) : t('tasks.detail.people_no_time')}
                                        {person.running && (
                                            <span className="font-semibold text-ink">
                                                {' · '}
                                                {t('tasks.detail.people_running')}
                                            </span>
                                        )}
                                    </span>
                                </span>
                            </span>
                            {showParts && <PartChip status={person.part_status} />}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function Submissions() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const headingId = useId();
    const reviewLabel = { pending: t('tasks.review.waiting'), approved: t('tasks.review.approved'), changes_requested: t('tasks.review.changes_requested') };
    const chip = { pending: 'chip-pending', approved: 'chip-ok', changes_requested: 'chip-bad' };

    return (
        <section aria-labelledby={headingId}>
            <h2 id={headingId} className="h2 text-[20px]">
                {t('tasks.history.submissions')}
            </h2>
            {props.submissions.length === 0 ? (
                <p className="m-0 mt-2 text-muted">{t('tasks.history.submissions_empty')}</p>
            ) : (
                <ol className="card m-0 mt-3 list-none divide-y divide-line p-0">
                    {props.submissions.map((s) => {
                        const stale = s.review_status === 'pending' && !s.waiting;
                        return (
                            <li key={s.id} className="flex flex-col gap-3 px-4 py-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="num m-0 text-sm text-muted">{t('tasks.history.sent_by', { name: s.submitted_by?.name ?? '', date: s.created_at ? formatDateTime(s.created_at, locale) : '' })}</p>
                                    <span className={`chip ${stale ? '' : chip[s.review_status]}`}>{stale ? t('tasks.history.stale') : reviewLabel[s.review_status]}</span>
                                </div>
                                <SubmissionBody submission={s} />
                                {stale && <p className="m-0 text-sm text-muted">{t('tasks.history.stale_note')}</p>}
                                {s.reviewed_at && (
                                    <div className="border-l-2 border-line-strong pl-3 text-sm">
                                        <p className="num m-0 text-muted">
                                            {t('tasks.history.reviewed_by', { status: reviewLabel[s.review_status], name: s.reviewer?.name ?? '', date: formatDateTime(s.reviewed_at, locale) })}
                                        </p>
                                        {s.review_note && <p className="m-0 mt-1 whitespace-pre-line break-words">{s.review_note}</p>}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}

function Sessions() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const headingId = useId();

    return (
        <section aria-labelledby={headingId}>
            <h2 id={headingId} className="h2 text-[20px]">
                {t('tasks.history.sessions')}
            </h2>
            {props.sessions.length === 0 ? (
                <p className="m-0 mt-2 text-muted">{t('tasks.history.sessions_empty')}</p>
            ) : (
                <ul className="card m-0 mt-3 list-none divide-y divide-line p-0">
                    {props.sessions.map((s) => (
                        <li key={s.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2.5 text-sm">
                            <PersonLine person={s.user} fallback="" />
                            <span className="num">
                                {formatDateTime(s.started_at, locale)}
                                {'-'}
                                {s.ended_at ? formatTime(s.ended_at, locale) : t('tasks.history.running')}
                            </span>
                            <span className="num flex items-center gap-2">
                                {s.ended_at && formatMinutes(s.minutes, locale)}
                                <span className={s.logged ? 'text-success' : 'text-muted'}>{s.ended_at ? (s.logged ? t('tasks.history.logged') : t('tasks.history.not_logged')) : ''}</span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function Facts() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const locale = useLocale();
    const { task } = props;
    const none = <span className="text-muted">{t('tasks.detail.none')}</span>;
    const rows: [string, ReactNode][] = [
        [t('tasks.detail.priority'), t(`tasks.priority.${task.priority}`)],
        [
            t('tasks.detail.stage'),
            task.stage ? (
                <span key="s">
                    {task.stage.name}
                    <span className="block text-sm font-normal text-muted">{t(`pipeline.phase.${task.stage.phase}`)}</span>
                </span>
            ) : (
                none
            ),
        ],
        [t('tasks.detail.due'), task.due_date ? formatShortDate(task.due_date, locale) : none],
        [t('tasks.detail.estimate'), task.estimate_minutes ? formatMinutes(task.estimate_minutes, locale) : none],
        [t('tasks.detail.logged'), formatMinutes(task.logged_minutes, locale)],
        [t('tasks.detail.evidence'), task.evidence_required ? t('tasks.detail.evidence_required') : t('tasks.detail.evidence_optional')],
    ];

    return (
        <aside className="card px-5 py-5">
            <dl className="m-0 flex flex-col gap-3">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex flex-col gap-0.5">
                        <dt className="text-sm text-muted">{label}</dt>
                        <dd className="num m-0 font-semibold">{value}</dd>
                    </div>
                ))}
            </dl>
            <div className="mt-4 flex flex-col gap-1 border-t border-line pt-3 text-sm text-muted">
                {task.creator && task.created_at && <p className="m-0">{t('tasks.detail.created_by', { name: task.creator.name, date: formatDateTime(task.created_at, locale) })}</p>}
                {task.decider && task.decided_at && (
                    <p className="m-0">{t(task.status === 'rejected' ? 'tasks.detail.decided_rejected' : 'tasks.detail.decided_approved', { name: task.decider.name, date: formatDateTime(task.decided_at, locale) })}</p>
                )}
            </div>
        </aside>
    );
}

function ErrorLine({ children }: { children: ReactNode }) {
    return (
        <p className="error-text m-0 flex items-center gap-1.5" role="alert">
            <WarningCircle weight="bold" size={15} aria-hidden />
            {children}
        </p>
    );
}
