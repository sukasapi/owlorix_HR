import { TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { formatLongDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import { PencilSimple, Plus, Trash } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';
import type { CalendarDate, CalendarEntry, EntryType } from './types';
import { useRequestError } from './useRequestError';

interface Props {
    day: CalendarDate;
    today: string;
    canManage: boolean;
    onSaved: (date: string) => void;
}

const TYPES: EntryType[] = ['holiday', 'studio_day_off', 'workday'];

/** The calendar entry of one date (holiday, studio day off, studio-wide workday). Editable by Superadmin. */
export function EntrySection({ day, today, canManage, onSaved }: Props) {
    const t = useT();
    const headingId = useId();
    const [mode, setMode] = useState<'view' | 'form' | 'delete'>('view');
    const entry = day.entry;

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3 border-t border-line px-5 py-5 sm:px-7">
            <h3 id={headingId} className="m-0 text-[16px] font-semibold">
                {t('calendar.entry.heading')}
            </h3>

            {mode === 'form' && canManage ? (
                <EntryForm
                    day={day}
                    entry={entry}
                    today={today}
                    onCancel={() => setMode('view')}
                    onSaved={(date) => {
                        setMode('view');
                        onSaved(date);
                    }}
                />
            ) : mode === 'delete' && entry && canManage ? (
                <DeleteEntry day={day} entry={entry} onDone={() => setMode('view')} />
            ) : (
                <>
                    {entry ? (
                        <p className="m-0">
                            <span className="font-semibold">{t(`calendar.types.${entry.type}`)}:</span> {entry.name}
                        </p>
                    ) : (
                        <p className="m-0 text-muted">{t('calendar.entry.none')}</p>
                    )}

                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            {entry ? (
                                <>
                                    <button type="button" className="btn btn-secondary btn-sm" onClick={() => setMode('form')}>
                                        <PencilSimple weight="bold" size={16} aria-hidden />
                                        {t('calendar.entry.edit')}
                                    </button>
                                    <button type="button" className="btn btn-danger btn-sm" onClick={() => setMode('delete')}>
                                        <Trash weight="bold" size={16} aria-hidden />
                                        {t('calendar.entry.delete')}
                                    </button>
                                </>
                            ) : (
                                <button type="button" className="btn btn-secondary btn-sm" onClick={() => setMode('form')}>
                                    <Plus weight="bold" size={16} aria-hidden />
                                    {t('calendar.entry.add')}
                                </button>
                            )}
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

function EntryForm({ day, entry, today, onCancel, onSaved }: { day: CalendarDate; entry: CalendarEntry | null; today: string; onCancel: () => void; onSaved: (date: string) => void }) {
    const t = useT();
    const locale = useLocale();
    const request = useRequestError();
    const typeLegendId = useId();
    const form = useForm<{ date: string; type: EntryType; name: string }>({
        date: day.date,
        type: entry?.type ?? (day.week_workday ? 'holiday' : 'workday'),
        name: entry?.name ?? '',
    });

    const validDate = /^\d{4}-\d{2}-\d{2}$/.test(form.data.date);
    const inPast = validDate && form.data.date < today;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, preserveState: true, ...request.handlers, onSuccess: () => onSaved(form.data.date) };
        if (entry) form.put(route('calendar.days.update', entry.id), options);
        else form.post(route('calendar.days.store'), options);
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <p className="m-0 font-semibold">{entry ? t('calendar.entry.form_edit') : t('calendar.entry.form_add')}</p>

            <TextField
                label={t('calendar.entry.date')}
                type="date"
                required
                value={form.data.date}
                onChange={(e) => form.setData('date', e.target.value)}
                error={form.errors.date}
            />

            <fieldset className="m-0 flex flex-col gap-2 border-0 p-0" aria-describedby={form.errors.type ? `${typeLegendId}-error` : undefined}>
                <legend id={typeLegendId} className="label mb-2 p-0">
                    {t('calendar.entry.type')}
                </legend>
                {TYPES.map((type) => (
                    <label
                        key={type}
                        className={`flex cursor-pointer items-start gap-3 rounded-sm border-[1.5px] px-3 py-2.5 ${
                            form.data.type === type ? 'border-[var(--primary-bg)] bg-panel' : 'border-line-strong bg-surface'
                        }`}
                    >
                        <input
                            type="radio"
                            name="entry-type"
                            value={type}
                            checked={form.data.type === type}
                            onChange={() => form.setData('type', type)}
                            className="mt-1 h-[18px] w-[18px] flex-none cursor-pointer accent-[var(--primary-bg)]"
                        />
                        <span className="flex flex-col">
                            <span className="font-semibold">{t(`calendar.types.${type}`)}</span>
                            <span className="text-[13px] text-muted">{t(`calendar.entry.type_help.${type}`)}</span>
                        </span>
                    </label>
                ))}
                {form.errors.type && (
                    <p id={`${typeLegendId}-error`} className="error-text m-0" role="alert">
                        {form.errors.type}
                    </p>
                )}
            </fieldset>

            <TextField
                label={t('calendar.entry.name')}
                required
                maxLength={120}
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                error={form.errors.name}
                help={t('calendar.entry.name_help')}
            />

            {inPast && <Notice>{t('calendar.entry.past_date', { date: formatLongDate(form.data.date, locale) })}</Notice>}
            {request.error && <Notice tone="danger">{request.error}</Notice>}

            <div className="flex flex-wrap items-center gap-3">
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    {form.processing ? t('calendar.entry.saving') : t('calendar.entry.save')}
                </button>
                <button type="button" className="btn btn-quiet" onClick={onCancel} disabled={form.processing}>
                    {t('calendar.entry.cancel')}
                </button>
            </div>
        </form>
    );
}

function DeleteEntry({ day, entry, onDone }: { day: CalendarDate; entry: CalendarEntry; onDone: () => void }) {
    const t = useT();
    const request = useRequestError();
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        router.delete(route('calendar.days.destroy', entry.id), {
            preserveScroll: true,
            preserveState: true,
            ...request.handlers,
            onStart: () => {
                request.clear();
                setProcessing(true);
            },
            onFinish: () => setProcessing(false),
            onSuccess: onDone,
        });
    };

    return (
        <div className="flex flex-col gap-3">
            <p className="m-0 font-semibold">{t('calendar.entry.delete_confirm', { name: entry.name })}</p>
            {day.is_past && <Notice>{t('calendar.day.past_warning')}</Notice>}
            {request.error && <Notice tone="danger">{request.error}</Notice>}
            <div className="flex flex-wrap items-center gap-3">
                <button type="button" className="btn btn-danger" onClick={confirm} disabled={processing}>
                    {processing ? t('calendar.entry.deleting') : t('calendar.entry.delete_yes')}
                </button>
                <button type="button" className="btn btn-quiet" onClick={onDone} disabled={processing}>
                    {t('calendar.entry.cancel')}
                </button>
            </div>
        </div>
    );
}
