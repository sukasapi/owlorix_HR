import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { useT } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowSquareOut,
    ArrowUp,
    Check,
    Copy,
    FilmStrip,
    FlagCheckered,
    Kanban,
    LinkSimple,
    LockSimple,
    Microphone,
    PencilSimple,
    PersonArmsSpread,
    PersonSimpleRun,
    Plus,
    Scroll,
    SpeakerHigh,
    Trash,
    X,
} from '@phosphor-icons/react';
import { type FormEvent, useEffect, useId, useRef, useState } from 'react';

export type LinkCategory = 'scenario' | 'storyboard' | 'character_assets' | 'sound' | 'voice_over' | 'animation' | 'final' | 'tracker' | 'other';

export interface ProjectLink {
    id: number;
    category: LinkCategory;
    /** Typed by the manager; null means the category name is the title */
    label: string | null;
    url: string;
    note: string | null;
    managers_only: boolean;
    /** Known service (Drive folder, Docs, Sheets, Figma...), null for any other site */
    service: string | null;
    host: string | null;
}

/**
 * One icon per production stage, so people find their stage by shape before reading: a script scroll, a strip of
 * frames, a character in T-pose, a speaker, a microphone, a running figure, the finish flag, a task board.
 */
const CATEGORY_ICON: Record<LinkCategory, typeof LinkSimple> = {
    scenario: Scroll,
    storyboard: FilmStrip,
    character_assets: PersonArmsSpread,
    sound: SpeakerHigh,
    voice_over: Microphone,
    animation: PersonSimpleRun,
    final: FlagCheckered,
    tracker: Kanban,
    other: LinkSimple,
};

type Direction = 'up' | 'down';

interface Props {
    projectId: number;
    /** Null when the viewer is not involved in the project and may not see the links */
    items: ProjectLink[] | null;
    categories: LinkCategory[];
    canManage: boolean;
    /** Inside a project tab: the tab names the section, so the heading is for screen readers only */
    inTab?: boolean;
}

/** Document links of one project, in the order the managers set. Everyone involved opens them; managers set them. */
export function ProjectLinks({ projectId, items, categories, canManage, inTab = false }: Props) {
    const t = useT();
    const [dialog, setDialog] = useState<ProjectLink | 'new' | null>(null);
    const [reordering, setReordering] = useState(false);
    const [failed, setFailed] = useState(false);
    const [busyId, setBusyId] = useState<number | null>(null);
    const [copiedId, setCopiedId] = useState<number | null>(null);
    const [copyFailed, setCopyFailed] = useState(false);
    const [announce, setAnnounce] = useState('');
    const [focusAfterMove, setFocusAfterMove] = useState<{ id: number; direction: Direction } | null>(null);
    const listRef = useRef<HTMLUListElement>(null);
    const copiedTimer = useRef<number | undefined>(undefined);

    const list = items ?? [];
    const canReorder = canManage && list.length > 1;
    const showReorder = reordering && canReorder;
    const titleOf = (link: ProjectLink) => link.label ?? t(`projects.links.category.${link.category}`);

    useEffect(() => () => window.clearTimeout(copiedTimer.current), []);

    // React may re-insert the moved row, which drops focus; put it back on the same arrow, or the other one at an end
    useEffect(() => {
        if (!focusAfterMove) return;
        const { id, direction } = focusAfterMove;
        const other: Direction = direction === 'up' ? 'down' : 'up';
        const same = listRef.current?.querySelector<HTMLButtonElement>(`[data-move="${id}-${direction}"]:not(:disabled)`);
        (same ?? listRef.current?.querySelector<HTMLButtonElement>(`[data-move="${id}-${other}"]`))?.focus();
        setFocusAfterMove(null);
    }, [items, focusAfterMove]);

    const failOptions = {
        onError: () => setFailed(true),
        onHttpException: () => {
            setFailed(true);
            return false;
        },
        onNetworkError: () => {
            setFailed(true);
            return false;
        },
    };

    const move = (link: ProjectLink, direction: Direction) => {
        setFailed(false);
        router.post(
            route('projects.links.move', [projectId, link.id]),
            { direction },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setBusyId(link.id),
                onFinish: () => setBusyId(null),
                onSuccess: () => setFocusAfterMove({ id: link.id, direction }),
                ...failOptions,
            },
        );
    };

    const copy = async (link: ProjectLink, button: HTMLButtonElement) => {
        const ok = await copyText(link.url);
        button.focus();
        setCopyFailed(!ok);
        if (!ok) return;
        setCopiedId(link.id);
        setAnnounce(t('projects.links.copied', { name: titleOf(link) }));
        window.clearTimeout(copiedTimer.current);
        copiedTimer.current = window.setTimeout(() => setCopiedId(null), 2000);
    };

    const nextCategory = categories.find((c) => c !== 'other' && !list.some((link) => link.category === c)) ?? 'other';

    return (
        <section className={inTab ? '' : 'mt-8'} aria-labelledby="links-heading">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                    <h2 id="links-heading" className={inTab ? 'sr-only' : 'h2'}>
                        {t('projects.links.heading')}
                    </h2>
                    {items !== null && <p className={`m-0 max-w-[70ch] text-sm text-muted ${inTab ? '' : 'mt-1'}`}>{t('projects.links.lead')}</p>}
                </div>
                {canManage && list.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {canReorder && (
                            <button type="button" className="btn btn-secondary" aria-pressed={showReorder} onClick={() => setReordering((on) => !on)}>
                                {showReorder ? (
                                    <>
                                        <Check weight="bold" size={18} aria-hidden />
                                        {t('projects.links.reorder_done')}
                                    </>
                                ) : (
                                    t('projects.links.reorder')
                                )}
                            </button>
                        )}
                        {!showReorder && (
                            <button type="button" className="btn btn-secondary" onClick={() => setDialog('new')}>
                                <Plus weight="bold" size={18} aria-hidden />
                                {t('projects.links.add')}
                            </button>
                        )}
                    </div>
                )}
            </div>

            <p className="sr-only" aria-live="polite">
                {announce}
            </p>

            {failed && (
                <Notice tone="danger" className="mt-4">
                    {t('projects.links.failed')}
                </Notice>
            )}
            {copyFailed && (
                <Notice tone="danger" className="mt-4">
                    {t('projects.links.copy_failed')}
                </Notice>
            )}

            {items === null ? (
                <p className="m-0 mt-2 flex max-w-[70ch] items-start gap-2 text-sm text-muted">
                    <LockSimple weight="bold" size={16} className="mt-0.5 flex-none" aria-hidden />
                    {t('projects.links.not_involved')}
                </p>
            ) : list.length === 0 ? (
                <div className="card mt-4 flex flex-col items-start gap-1 px-5 py-5">
                    <p className="m-0 font-semibold">{t('projects.links.empty_title')}</p>
                    <p className="m-0 max-w-[60ch] text-sm text-muted">{canManage ? t('projects.links.empty_manage') : t('projects.links.empty_view')}</p>
                    {canManage && (
                        <button type="button" className="btn btn-primary mt-3" onClick={() => setDialog('new')}>
                            <Plus weight="bold" size={18} aria-hidden />
                            {t('projects.links.add')}
                        </button>
                    )}
                </div>
            ) : (
                <ul ref={listRef} className="m-0 mt-4 flex max-w-[760px] list-none flex-col gap-2 p-0">
                    {list.map((link, index) => (
                        <LinkRow
                            key={link.id}
                            link={link}
                            title={titleOf(link)}
                            canManage={canManage}
                            reordering={showReorder}
                            first={index === 0}
                            last={index === list.length - 1}
                            busy={busyId !== null}
                            copied={copiedId === link.id}
                            onCopy={(button) => copy(link, button)}
                            onEdit={() => setDialog(link)}
                            onMove={(direction) => move(link, direction)}
                        />
                    ))}
                </ul>
            )}

            {canManage && dialog !== null && (
                <LinkDialog projectId={projectId} link={dialog === 'new' ? undefined : dialog} categories={categories} defaultCategory={nextCategory} onClose={() => setDialog(null)} />
            )}
        </section>
    );
}

interface RowProps {
    link: ProjectLink;
    title: string;
    canManage: boolean;
    reordering: boolean;
    first: boolean;
    last: boolean;
    busy: boolean;
    copied: boolean;
    onCopy: (button: HTMLButtonElement) => void;
    onEdit: () => void;
    onMove: (direction: Direction) => void;
}

function LinkRow({ link, title, canManage, reordering, first, last, busy, copied, onCopy, onEdit, onMove }: RowProps) {
    const t = useT();
    const Icon = CATEGORY_ICON[link.category] ?? LinkSimple;
    const where = link.service ? t(`projects.links.service.${link.service}`) : link.host;
    const iconButton = 'btn btn-secondary btn-sm min-h-11 min-w-11 px-0';

    return (
        <li className="card flex items-center gap-1 p-1">
            <a href={link.url} target="_blank" rel="noopener noreferrer" className="group flex min-h-16 min-w-0 flex-1 items-center gap-3 rounded-[8px] px-3 py-2.5 text-ink no-underline hover:bg-selected sm:gap-4">
                <span className="grid size-11 flex-none place-items-center rounded-[8px] bg-panel text-heading">
                    <Icon weight="bold" size={24} aria-hidden />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="font-semibold break-words underline-offset-3 group-hover:underline">{title}</span>
                        {link.managers_only && (
                            <span className="chip">
                                <LockSimple weight="bold" size={14} aria-hidden />
                                {t('projects.links.managers_only')}
                            </span>
                        )}
                    </span>
                    {(where || link.note) && (
                        <span className="mt-0.5 block text-sm break-words text-muted">
                            {[where, link.note].filter(Boolean).join(' · ')}
                        </span>
                    )}
                </span>
                {/* Tells people the link leaves the app for Drive in a new tab */}
                <ArrowSquareOut weight="bold" size={18} className="flex-none text-muted" aria-hidden />
                <span className="sr-only">{t('projects.links.new_tab')}</span>
            </a>

            {reordering ? (
                <div className="flex flex-none gap-1 pr-1">
                    <button type="button" className={iconButton} data-move={`${link.id}-up`} disabled={first || busy} onClick={() => onMove('up')} aria-label={t('projects.links.move_up_label', { name: title })}>
                        <ArrowUp weight="bold" size={18} aria-hidden />
                    </button>
                    <button type="button" className={iconButton} data-move={`${link.id}-down`} disabled={last || busy} onClick={() => onMove('down')} aria-label={t('projects.links.move_down_label', { name: title })}>
                        <ArrowDown weight="bold" size={18} aria-hidden />
                    </button>
                </div>
            ) : (
                <div className="flex flex-none gap-1 pr-1">
                    <button
                        type="button"
                        className={iconButton}
                        onClick={(event) => onCopy(event.currentTarget)}
                        aria-label={t('projects.links.copy_label', { name: title })}
                        title={t('projects.links.copy_label', { name: title })}
                    >
                        {copied ? <Check weight="bold" size={18} className="text-success" aria-hidden /> : <Copy weight="bold" size={18} aria-hidden />}
                    </button>
                    {canManage && (
                        <button type="button" className="btn btn-secondary btn-sm min-h-11 min-w-11 px-0 sm:px-3" onClick={onEdit} aria-label={t('projects.links.edit_label', { name: title })}>
                            <PencilSimple weight="bold" size={18} aria-hidden />
                            <span className="hidden sm:inline" aria-hidden>
                                {t('projects.links.edit')}
                            </span>
                        </button>
                    )}
                </div>
            )}
        </li>
    );
}

interface DialogProps {
    projectId: number;
    link?: ProjectLink;
    categories: LinkCategory[];
    defaultCategory: LinkCategory;
    onClose: () => void;
}

function LinkDialog({ projectId, link, categories, defaultCategory, onClose }: DialogProps) {
    const t = useT();
    const titleId = useId();
    const onlyId = useId();
    const editing = link !== undefined;
    const [failed, setFailed] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const form = useForm({
        category: link?.category ?? defaultCategory,
        label: link?.label ?? '',
        url: link?.url ?? '',
        note: link?.note ?? '',
        managers_only: link?.managers_only ?? false,
    });
    const other = form.data.category === 'other';
    const name = link ? (link.label ?? t(`projects.links.category.${link.category}`)) : '';

    const failOptions = {
        onHttpException: () => {
            setFailed(true);
            return false;
        },
        onNetworkError: () => {
            setFailed(true);
            return false;
        },
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);
        form.transform((data) => ({ ...data, label: data.label.trim() || null, url: data.url.trim(), note: data.note.trim() || null }));
        const options = { preserveScroll: true, onSuccess: onClose, ...failOptions };
        if (editing) form.put(route('projects.links.update', [projectId, link.id]), options);
        else form.post(route('projects.links.store', projectId), options);
    };

    const remove = () => {
        if (!link || !window.confirm(t('projects.links.form.delete_confirm', { name }))) return;
        setFailed(false);
        router.delete(route('projects.links.destroy', [projectId, link.id]), {
            preserveScroll: true,
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            onSuccess: onClose,
            ...failOptions,
        });
    };

    return (
        <Dialog open onClose={onClose} labelledBy={titleId} width="max-w-[560px]" closeOnBackdrop={false}>
            <form onSubmit={submit} className="flex flex-col" noValidate>
                <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {editing ? t('projects.links.form.edit_title') : t('projects.links.form.create_title')}
                    </h2>
                    <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 px-0" aria-label={t('common.actions.close')}>
                        <X weight="bold" size={18} aria-hidden />
                    </button>
                </header>
                <div className="flex flex-col gap-[18px] px-5 py-5 sm:px-7">
                    {failed && <Notice tone="danger">{t('projects.links.failed')}</Notice>}
                    <SelectField label={t('projects.links.form.category')} value={form.data.category} onChange={(e) => form.setData('category', e.target.value as LinkCategory)} error={form.errors.category}>
                        {categories.map((category) => (
                            <option key={category} value={category}>
                                {t(`projects.links.category.${category}`)}
                            </option>
                        ))}
                    </SelectField>
                    <TextField
                        label={other ? t('projects.links.form.label') : t('projects.links.form.label_optional')}
                        help={other ? t('projects.links.form.label_help_other') : t('projects.links.form.label_help_preset')}
                        required={other}
                        maxLength={80}
                        value={form.data.label}
                        onChange={(e) => form.setData('label', e.target.value)}
                        error={form.errors.label}
                    />
                    <TextField
                        data-autofocus
                        label={t('projects.links.form.url')}
                        help={t('projects.links.form.url_help')}
                        type="url"
                        inputMode="url"
                        autoComplete="off"
                        spellCheck={false}
                        placeholder="https://"
                        required
                        maxLength={500}
                        value={form.data.url}
                        onChange={(e) => form.setData('url', e.target.value)}
                        error={form.errors.url}
                    />
                    <TextField label={t('projects.links.form.note')} help={t('projects.links.form.note_help')} maxLength={200} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} error={form.errors.note} />
                    <label htmlFor={onlyId} className="flex min-h-11 cursor-pointer items-start gap-3 py-1">
                        <input
                            id={onlyId}
                            type="checkbox"
                            className="mt-0.5 size-5 flex-none accent-[var(--primary-bg)]"
                            checked={form.data.managers_only}
                            onChange={(e) => form.setData('managers_only', e.target.checked)}
                            aria-describedby={`${onlyId}-help`}
                        />
                        <span className="min-w-0">
                            <span className="block font-semibold">{t('projects.links.form.managers_only')}</span>
                            <span id={`${onlyId}-help`} className="help block">
                                {t('projects.links.form.managers_only_help')}
                            </span>
                        </span>
                    </label>
                </div>
                <footer className="flex flex-wrap items-center justify-between gap-2.5 border-t border-line px-5 py-3.5 sm:px-7">
                    {editing ? (
                        <button type="button" className="btn btn-quiet" onClick={remove} disabled={deleting || form.processing}>
                            <Trash weight="bold" size={18} aria-hidden />
                            {t('projects.links.form.delete')}
                        </button>
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-wrap justify-end gap-2.5">
                        <button type="button" className="btn btn-secondary" onClick={onClose}>
                            {t('common.actions.cancel')}
                        </button>
                        <button type="submit" className="btn btn-primary" disabled={form.processing || deleting}>
                            {form.processing ? t('common.actions.saving') : editing ? t('projects.links.form.submit_edit') : t('projects.links.form.submit_create')}
                        </button>
                    </div>
                </footer>
            </form>
        </Dialog>
    );
}

/** The clipboard API needs https; on the studio LAN over plain http the old copy command still works. */
async function copyText(text: string): Promise<boolean> {
    try {
        if (window.isSecureContext && navigator.clipboard) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch {
        // Permission refused: try the old way below
    }

    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch {
        ok = false;
    }
    area.remove();

    return ok;
}
