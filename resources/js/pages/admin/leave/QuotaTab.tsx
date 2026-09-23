import { Dialog } from '@/components/ui/Dialog';
import { SelectField, TextField } from '@/components/ui/Field';
import { Notice } from '@/components/ui/Notice';
import { useT } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import { X } from '@phosphor-icons/react';
import { type FormEvent, useId, useState } from 'react';
import { dayCount } from '../../leave/format';
import type { AdminFilters, AdminLeaveProps, QuotaRow } from '../../leave/types';
import { useVisitState } from '../../leave/useVisitState';
import { adminQuery } from './query';

/**
 * Annual leave per person for one year. Requests over the quota are not refused; the remaining column is the record
 * Superadmin reads, so it sits last, next to the action that changes it.
 */
export function QuotaTab({ quota, filters }: { quota: AdminLeaveProps['quota']; filters: AdminFilters }) {
    const t = useT();
    const visit = useVisitState(new URL(route('admin.leave.index'), window.location.origin).pathname);
    const [editing, setEditing] = useState<QuotaRow | null>(null);
    const years = [quota.this_year - 1, quota.this_year, quota.this_year + 1];
    const loading = visit.state === 'loading';

    const chooseYear = (year: number) =>
        router.get(route('admin.leave.index'), adminQuery(filters, year, quota.this_year), { preserveState: true, preserveScroll: true, replace: true });

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-end gap-x-6 gap-y-3">
                <SelectField label={t('leave.admin.quota.year')} className="w-[160px]" value={quota.year} onChange={(e) => chooseYear(Number(e.target.value))}>
                    {(years.includes(quota.year) ? years : [...years, quota.year].sort()).map((year) => (
                        <option key={year} value={year}>
                            {year}
                        </option>
                    ))}
                </SelectField>
                <p className="m-0 max-w-[64ch] pb-2 text-sm text-muted">{t('leave.admin.quota.help', { days: quota.default_days })}</p>
            </div>

            {visit.state === 'error' && (
                <Notice tone="danger">
                    <p className="m-0">{t('leave.admin.error_title')}</p>
                    <button type="button" className="btn btn-secondary btn-sm mt-2 bg-surface" onClick={visit.retry}>
                        {t('leave.admin.retry')}
                    </button>
                </Notice>
            )}
            {loading && (
                <p className="m-0 text-sm text-muted" role="status">
                    {t('leave.admin.loading')}
                </p>
            )}

            {quota.rows.length === 0 ? (
                <p className="card m-0 px-5 py-6 text-muted">{t('leave.admin.quota.empty')}</p>
            ) : (
                <div className={`transition-opacity ${loading ? 'opacity-60' : ''}`} aria-busy={loading}>
                    <div className="table-wrap hidden md:block">
                        <table className="table num">
                            <thead>
                                <tr>
                                    <th scope="col">{t('leave.admin.quota.columns.person')}</th>
                                    <th scope="col" className="text-right">
                                        {t('leave.admin.quota.columns.quota')}
                                    </th>
                                    <th scope="col" className="text-right">
                                        {t('leave.admin.quota.columns.used')}
                                    </th>
                                    <th scope="col" className="text-right">
                                        {t('leave.admin.quota.columns.pending')}
                                    </th>
                                    <th scope="col" className="text-right">
                                        {t('leave.admin.quota.columns.remaining')}
                                    </th>
                                    <th scope="col">
                                        <span className="sr-only">{t('leave.admin.quota.columns.actions')}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {quota.rows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="font-semibold">{row.name}</td>
                                        <td className="text-right">
                                            {row.quota}
                                            {!row.custom && <span className="ml-1.5 text-[13px] text-muted">({t('leave.admin.quota.default_mark')})</span>}
                                        </td>
                                        <td className="text-right">{row.used}</td>
                                        <td className="text-right">{row.pending}</td>
                                        <td className={`text-right font-semibold ${row.remaining < 0 ? 'text-danger' : ''}`}>{row.remaining}</td>
                                        <td className="text-right">
                                            <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => setEditing(row)} aria-label={t('leave.admin.quota.edit_label', { name: row.name })}>
                                                {t('leave.admin.quota.edit')}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="card m-0 list-none divide-y divide-line p-0 md:hidden">
                        {quota.rows.map((row) => (
                            <li key={row.id} className="flex items-center gap-3 px-4 py-3.5">
                                <div className="min-w-0 flex-1">
                                    <p className="m-0 font-semibold break-words">{row.name}</p>
                                    <p className="num m-0 text-sm">
                                        <span className={`font-semibold ${row.remaining < 0 ? 'text-danger' : ''}`}>
                                            {t('leave.admin.quota.columns.remaining')} {row.remaining}
                                        </span>
                                        <span className="text-muted">
                                            {' · '}
                                            {t('leave.admin.quota.columns.quota')} {row.quota}
                                            {!row.custom && ` (${t('leave.admin.quota.default_mark')})`}, {t('leave.admin.quota.columns.used')} {row.used}, {t('leave.admin.quota.columns.pending')} {row.pending}
                                        </span>
                                    </p>
                                </div>
                                <button type="button" className="btn btn-secondary flex-none px-3" onClick={() => setEditing(row)} aria-label={t('leave.admin.quota.edit_label', { name: row.name })}>
                                    {t('leave.admin.quota.edit')}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <QuotaDialog row={editing} year={quota.year} onClose={() => setEditing(null)} />
        </div>
    );
}

function QuotaDialog({ row, year, onClose }: { row: QuotaRow | null; year: number; onClose: () => void }) {
    const titleId = useId();

    return (
        <Dialog open={row !== null} onClose={onClose} labelledBy={titleId} width="max-w-[440px]" closeOnBackdrop={false}>
            {row && <QuotaForm key={`${row.id}-${year}`} row={row} year={year} titleId={titleId} onClose={onClose} />}
        </Dialog>
    );
}

function QuotaForm({ row, year, titleId, onClose }: { row: QuotaRow; year: number; titleId: string; onClose: () => void }) {
    const t = useT();
    const form = useForm({ year, days: String(row.quota) });
    const [failed, setFailed] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setFailed(false);
        form.put(route('admin.leave.quota', row.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: onClose,
            onHttpException: () => {
                setFailed(true);
                return false;
            },
            onNetworkError: () => {
                setFailed(true);
                return false;
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate>
            <header className="flex items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                <h2 id={titleId} className="h2">
                    {t('leave.admin.quota.dialog_title', { name: row.name, year })}
                </h2>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 flex-none px-0" aria-label={t('leave.actions.close')}>
                    <X weight="bold" size={18} aria-hidden />
                </button>
            </header>
            <div className="flex flex-col gap-4 px-5 py-5 sm:px-6">
                <TextField
                    label={t('leave.admin.quota.days')}
                    help={t('leave.admin.quota.days_help', { used: dayCount(row.used, t), pending: dayCount(row.pending, t) })}
                    type="number"
                    inputMode="numeric"
                    min={0}
                    max={365}
                    step={1}
                    required
                    autoFocus
                    value={form.data.days}
                    onChange={(e) => form.setData('days', e.target.value)}
                    error={form.errors.days ?? form.errors.year}
                />
                {failed && <Notice tone="danger">{t('leave.admin.quota.failed')}</Notice>}
            </div>
            <footer className="flex flex-wrap justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-6">
                <button type="button" className="btn btn-secondary" onClick={onClose}>
                    {t('leave.admin.quota.cancel')}
                </button>
                <button type="submit" className="btn btn-primary" disabled={form.processing}>
                    {form.processing ? t('leave.actions.sending') : t('leave.admin.quota.save')}
                </button>
            </footer>
        </form>
    );
}
