import { Notice } from '@/components/ui/Notice';
import { useLocale, useT } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { type FormEvent, useId } from 'react';
import { joinNames, weekdayName } from './dates';
import { useRequestError } from './useRequestError';

interface Props {
    workWeek: { weekday: number; is_workday: boolean }[];
    canManage: boolean;
}

export function WorkWeekCard({ workWeek, canManage }: Props) {
    const t = useT();
    const locale = useLocale();
    const headingId = useId();
    const saved = workWeek.filter((d) => d.is_workday).map((d) => d.weekday);
    const savedKey = saved.join(',');

    return (
        <section className="card flex flex-col gap-3 px-5 py-[18px]" aria-labelledby={headingId}>
            <h2 id={headingId} className="h2">
                {t('calendar.week.heading')}
            </h2>
            {canManage ? (
                <WorkWeekForm key={savedKey} saved={saved} />
            ) : (
                <>
                    <p className="m-0 font-semibold">
                        {saved.length === 0
                            ? t('calendar.week.none')
                            : t('calendar.week.workdays', { days: joinNames(saved.map((d) => weekdayName(d, locale, 'long')), t('calendar.week.list_and')) })}
                    </p>
                    <p className="m-0 text-muted">{t('calendar.week.lead_readonly')}</p>
                </>
            )}
        </section>
    );
}

function WorkWeekForm({ saved }: { saved: number[] }) {
    const t = useT();
    const locale = useLocale();
    const request = useRequestError();
    const form = useForm<{ workdays: number[] }>({ workdays: saved });
    const dirty = [...form.data.workdays].sort().join(',') !== [...saved].sort().join(',');

    const toggle = (weekday: number, on: boolean) => {
        form.setData('workdays', on ? [...form.data.workdays, weekday].sort() : form.data.workdays.filter((d) => d !== weekday));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(route('calendar.work-week.update'), { preserveScroll: true, preserveState: true, ...request.handlers });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            <p className="m-0 text-muted">{t('calendar.week.lead_manage')}</p>

            <fieldset className="m-0 border-0 p-0">
                <legend className="label mb-2 p-0">{t('calendar.week.days_label')}</legend>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7">
                    {[1, 2, 3, 4, 5, 6, 7].map((weekday) => {
                        const checked = form.data.workdays.includes(weekday);
                        return (
                            <label
                                key={weekday}
                                className={`flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-sm border-[1.5px] px-3 font-semibold ${
                                    checked ? 'border-[var(--primary-bg)] bg-panel' : 'border-line-strong bg-surface'
                                }`}
                            >
                                <input
                                    type="checkbox"
                                    className="h-[18px] w-[18px] flex-none cursor-pointer accent-[var(--primary-bg)]"
                                    checked={checked}
                                    onChange={(e) => toggle(weekday, e.target.checked)}
                                />
                                {weekdayName(weekday, locale, 'long')}
                            </label>
                        );
                    })}
                </div>
                {form.errors.workdays && <p className="error-text m-0 mt-2">{form.errors.workdays}</p>}
            </fieldset>

            {dirty && form.data.workdays.length === 0 && <Notice tone="danger">{t('calendar.week.none_warning')}</Notice>}
            {dirty && <Notice>{t('calendar.week.recalc_warning')}</Notice>}
            {request.error && <Notice tone="danger">{request.error}</Notice>}

            <div className="flex flex-wrap items-center gap-3">
                <button type="submit" className="btn btn-primary" disabled={!dirty || form.processing}>
                    {form.processing ? t('calendar.week.saving') : t('calendar.week.save')}
                </button>
                {dirty && !form.processing && (
                    <button
                        type="button"
                        className="btn btn-quiet"
                        onClick={() => {
                            form.reset();
                            form.clearErrors();
                            request.clear();
                        }}
                    >
                        {t('calendar.week.reset')}
                    </button>
                )}
            </div>
        </form>
    );
}
