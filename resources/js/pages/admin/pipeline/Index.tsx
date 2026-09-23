import { Dialog } from '@/components/ui/Dialog';
import { TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash, X } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';

type Phase = 'pre_production' | 'production' | 'post_production';

interface StageRow {
    id: number;
    name: string;
    is_active: boolean;
    tasks_count: number;
}

interface PhaseGroup {
    key: Phase;
    stages: StageRow[];
}

interface PageProps {
    phases: PhaseGroup[];
}

const failOptions = (setFailed: (value: boolean) => void) => ({
    onHttpException: () => {
        setFailed(true);
        return false;
    },
    onNetworkError: () => {
        setFailed(true);
        return false;
    },
});

/**
 * Pipeline produksi: one ordered list per phase, read top to bottom like the production itself. Calm list rows
 * (DESIGN.md: data screens stay ENERGY 1); order is changed with up and down buttons so it works by keyboard and
 * on a phone, where drag and drop does not.
 */
export default function PipelineIndex() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const [adding, setAdding] = useState<Phase | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [failed, setFailed] = useState(false);
    const [movingId, setMovingId] = useState<number | null>(null);

    // The dialog reads the stage from fresh props, so a save shows the server's result at once
    const editing = props.phases.flatMap((phase) => phase.stages).find((stage) => stage.id === editingId) ?? null;

    const move = (stage: StageRow, direction: 'up' | 'down') => {
        setFailed(false);
        router.post(
            route('admin.pipeline.move', stage.id),
            { direction },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setMovingId(stage.id),
                onFinish: () => setMovingId(null),
                // Keep the keyboard on the stage that moved; at the edge its button turns off, so take the other one
                onSuccess: () =>
                    requestAnimationFrame(() => {
                        const same = document.getElementById(`stage-${stage.id}-${direction}`) as HTMLButtonElement | null;
                        const other = document.getElementById(`stage-${stage.id}-${direction === 'up' ? 'down' : 'up'}`) as HTMLButtonElement | null;
                        (same && !same.disabled ? same : other)?.focus();
                    }),
                ...failOptions(setFailed),
            },
        );
    };

    return (
        <AppShell title={t('pipeline.title')}>
            <h1 className="h1">{t('pipeline.title')}</h1>
            <p className="m-0 mt-1 max-w-[70ch] text-muted">{t('pipeline.lead')}</p>

            {failed && (
                <Notice tone="danger" className="mt-4">
                    {t('pipeline.failed')}
                </Notice>
            )}

            <div className="mt-6 flex flex-col gap-9">
                {props.phases.map((phase) => (
                    <PhaseSection key={phase.key} phase={phase} movingId={movingId} onAdd={() => setAdding(phase.key)} onEdit={setEditingId} onMove={move} />
                ))}
            </div>

            <CreateStageDialog phase={adding} onClose={() => setAdding(null)} />
            <EditStageDialog stage={editing} onClose={() => setEditingId(null)} />
        </AppShell>
    );
}

function PhaseSection({
    phase,
    movingId,
    onAdd,
    onEdit,
    onMove,
}: {
    phase: PhaseGroup;
    movingId: number | null;
    onAdd: () => void;
    onEdit: (id: number) => void;
    onMove: (stage: StageRow, direction: 'up' | 'down') => void;
}) {
    const t = useT();
    const headingId = useId();
    const phaseName = t(`pipeline.phase.${phase.key}`);
    const last = phase.stages.length - 1;

    return (
        <section aria-labelledby={headingId}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 id={headingId} className="h2 flex items-baseline gap-2">
                    {phaseName}
                    <span className="num text-sm font-normal text-muted">{t('pipeline.stage_count', { count: phase.stages.length })}</span>
                </h2>
                <button type="button" className="btn btn-secondary" onClick={onAdd} aria-label={t('pipeline.add_to', { phase: phaseName })}>
                    <Plus weight="bold" size={18} aria-hidden />
                    {t('pipeline.add')}
                </button>
            </div>

            {phase.stages.length === 0 ? (
                <p className="card m-0 mt-3 px-5 py-4 text-sm text-muted">{t('pipeline.empty_phase')}</p>
            ) : (
                <ol className="card m-0 mt-3 list-none divide-y divide-line p-0">
                    {phase.stages.map((stage, index) => (
                        <li key={stage.id} className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3">
                            <span className="num w-6 flex-none text-right font-semibold text-muted" aria-hidden>
                                {index + 1}
                            </span>
                            <div className="min-w-[9rem] flex-1">
                                <p className="m-0 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                    <span className={`font-semibold break-words ${stage.is_active ? '' : 'text-muted'}`}>{stage.name}</span>
                                    {!stage.is_active && <span className="chip">{t('pipeline.inactive')}</span>}
                                </p>
                                <p className="num m-0 text-sm text-muted">{stage.tasks_count > 0 ? t('pipeline.used_by', { count: stage.tasks_count }) : t('pipeline.unused')}</p>
                            </div>
                            <div className="ml-auto flex flex-none items-center gap-1.5">
                                <button
                                    id={`stage-${stage.id}-up`}
                                    type="button"
                                    className="btn btn-secondary min-w-11 px-0"
                                    disabled={index === 0 || movingId !== null}
                                    onClick={() => onMove(stage, 'up')}
                                    aria-label={t('pipeline.move_up', { name: stage.name })}
                                >
                                    <ArrowUp weight="bold" size={18} aria-hidden />
                                </button>
                                <button
                                    id={`stage-${stage.id}-down`}
                                    type="button"
                                    className="btn btn-secondary min-w-11 px-0"
                                    disabled={index === last || movingId !== null}
                                    onClick={() => onMove(stage, 'down')}
                                    aria-label={t('pipeline.move_down', { name: stage.name })}
                                >
                                    <ArrowDown weight="bold" size={18} aria-hidden />
                                </button>
                                <button type="button" className="btn btn-secondary px-3.5" onClick={() => onEdit(stage.id)} aria-label={t('pipeline.edit_label', { name: stage.name })}>
                                    {t('pipeline.edit')}
                                </button>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}

function CreateStageDialog({ phase, onClose }: { phase: Phase | null; onClose: () => void }) {
    const titleId = useId();

    return (
        <Dialog open={phase !== null} onClose={onClose} labelledBy={titleId} width="max-w-[460px]" closeOnBackdrop={false}>
            {phase && <CreateStageForm key={phase} phase={phase} titleId={titleId} onClose={onClose} />}
        </Dialog>
    );
}

function CreateStageForm({ phase, titleId, onClose }: { phase: Phase; titleId: string; onClose: () => void }) {
    const t = useT();
    const [failed, setFailed] = useState(false);
    const form = useForm({ name: '', phase });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);
        form.post(route('admin.pipeline.store'), { preserveScroll: true, onSuccess: onClose, ...failOptions(setFailed) });
    };

    return (
        <form onSubmit={submit} noValidate>
            <header className="flex items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                <h2 id={titleId} className="h2">
                    {t('pipeline.form.create_title', { phase: t(`pipeline.phase.${phase}`) })}
                </h2>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('common.actions.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>
            <div className="flex flex-col gap-4 px-5 py-5 sm:px-6">
                {failed && <Notice tone="danger">{t('pipeline.failed')}</Notice>}
                <TextField
                    autoFocus
                    label={t('pipeline.form.name')}
                    help={t('pipeline.form.name_help')}
                    autoComplete="off"
                    required
                    maxLength={60}
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name ?? form.errors.phase}
                />
            </div>
            <footer className="flex flex-wrap justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-6">
                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    {t('common.actions.cancel')}
                </button>
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    {form.processing ? t('common.actions.saving') : t('pipeline.form.submit_create')}
                </button>
            </footer>
        </form>
    );
}

function EditStageDialog({ stage, onClose }: { stage: StageRow | null; onClose: () => void }) {
    const titleId = useId();

    return (
        <Dialog open={stage !== null} onClose={onClose} labelledBy={titleId} width="max-w-[520px]" closeOnBackdrop={false}>
            {stage && <EditStagePanel key={stage.id} stage={stage} titleId={titleId} onClose={onClose} />}
        </Dialog>
    );
}

/** Rename, switch on or off, and delete. A stage that tasks use cannot be deleted; the panel offers switching it off. */
function EditStagePanel({ stage, titleId, onClose }: { stage: StageRow; titleId: string; onClose: () => void }) {
    const t = useT();
    const [failed, setFailed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const form = useForm({ name: stage.name });

    const rename = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);
        form.transform((data) => ({ ...data, is_active: stage.is_active }));
        form.put(route('admin.pipeline.update', stage.id), { preserveScroll: true, preserveState: true, ...failOptions(setFailed) });
    };

    const toggle = () => {
        setFailed(false);
        router.put(
            route('admin.pipeline.update', stage.id),
            { name: stage.name, is_active: !stage.is_active },
            { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), ...failOptions(setFailed) },
        );
    };

    const remove = () => {
        if (!window.confirm(t('pipeline.delete.confirm', { name: stage.name }))) return;
        setFailed(false);
        setDeleteError(null);
        router.delete(route('admin.pipeline.destroy', stage.id), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: onClose,
            onError: (errors) => setDeleteError(errors.stage ?? null),
            ...failOptions(setFailed),
        });
    };

    const used = stage.tasks_count > 0;

    return (
        <div className="flex flex-col">
            <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                <div className="min-w-0">
                    <h2 id={titleId} className="h2 break-words">
                        {t('pipeline.form.edit_title')}
                    </h2>
                    <p className="num m-0 mt-1 text-sm break-words text-muted">
                        {stage.name} · {used ? t('pipeline.used_by', { count: stage.tasks_count }) : t('pipeline.unused')}
                    </p>
                </div>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('common.actions.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>

            <div className="flex flex-col gap-6 px-5 py-5 sm:px-7">
                {failed && <Notice tone="danger">{t('pipeline.failed')}</Notice>}

                <form onSubmit={rename} className="flex flex-col gap-3 sm:flex-row sm:items-start" noValidate>
                    <TextField
                        className="min-w-0 flex-1"
                        autoFocus
                        label={t('pipeline.form.name')}
                        autoComplete="off"
                        required
                        maxLength={60}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />
                    <button type="submit" className="btn btn-primary sm:mt-[27px]" disabled={form.processing || form.data.name.trim() === stage.name}>
                        {form.processing ? t('common.actions.saving') : t('pipeline.form.submit_edit')}
                    </button>
                </form>

                <section className="border-t border-line pt-5" aria-labelledby={`${titleId}-status`}>
                    <h3 id={`${titleId}-status`} className="m-0 text-base font-semibold">
                        {t('pipeline.status.heading')}
                    </h3>
                    <p className="m-0 mt-1 text-sm">{stage.is_active ? t('pipeline.status.active_body') : t('pipeline.status.inactive_body')}</p>
                    <button type="button" className="btn btn-secondary mt-3" onClick={toggle} disabled={busy}>
                        {stage.is_active ? t('pipeline.status.deactivate') : t('pipeline.status.activate')}
                    </button>
                </section>

                <section className="border-t border-line pt-5" aria-labelledby={`${titleId}-delete`}>
                    <h3 id={`${titleId}-delete`} className="m-0 text-base font-semibold">
                        {t('pipeline.delete.heading')}
                    </h3>
                    <p className="m-0 mt-1 text-sm">{used ? t('pipeline.delete.used_body', { count: stage.tasks_count }) : t('pipeline.delete.body')}</p>
                    {deleteError && (
                        <Notice tone="danger" className="mt-3">
                            {deleteError}
                        </Notice>
                    )}
                    {!used && (
                        <button type="button" className="btn btn-danger mt-3" onClick={remove} disabled={busy}>
                            <Trash weight="bold" size={18} aria-hidden />
                            {t('pipeline.delete.button')}
                        </button>
                    )}
                </section>
            </div>

            <footer className="flex justify-end border-t border-line px-5 py-3.5 sm:px-7">
                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    {t('common.actions.close')}
                </button>
            </footer>
        </div>
    );
}
