import { Avatar } from '@/components/ui/Avatar';
import { useT } from '@/lib/i18n';
import { MagnifyingGlass, WarningCircle } from '@phosphor-icons/react';
import { useId, useState } from 'react';
import type { AssigneeOption } from './taskTypes';

/** Longer lists get a search box; a short one is quicker to scan by eye. */
const SEARCH_FROM = 8;

interface Props {
    people: AssigneeOption[];
    value: number[];
    onChange: (ids: number[]) => void;
    max: number;
    help?: string;
    error?: string;
}

/**
 * Pengerja for a task (docs/15): a checkbox per project member, so several people can share the task. A native
 * checkbox list keeps keyboard use (Tab, Space) and screen readers working without a custom widget.
 */
export function AssigneePicker({ people, value, onChange, max, help, error }: Props) {
    const t = useT();
    const id = useId();
    const [query, setQuery] = useState('');
    const picked = new Set(value);
    const full = value.length >= max;

    const q = query.trim().toLowerCase();
    const visible = q === '' ? people : people.filter((p) => p.name.toLowerCase().includes(q) || p.username.toLowerCase().includes(q));

    const toggle = (personId: number, on: boolean) => {
        onChange(on ? [...value, personId] : value.filter((v) => v !== personId));
    };

    return (
        <fieldset className="field m-0 min-w-0 border-0 p-0" aria-describedby={`${id}-help ${error ? `${id}-error` : ''}`.trim()}>
            <legend className="label mb-1.5 p-0">{t('tasks.form.assignees')}</legend>
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                <p id={`${id}-help`} className="help m-0 max-w-[60ch] flex-1">
                    {help ?? t('tasks.form.assignees_help')} {value.length === 0 && people.length > 0 && t('tasks.form.assignees_none')}
                </p>
                <span className="num text-sm font-semibold" aria-live="polite">
                    {t('tasks.form.assignees_count', { count: value.length })}
                </span>
            </div>

            {people.length === 0 ? (
                <p className="m-0 rounded-[var(--radius-sm)] border border-line px-3 py-3 text-sm">{t('tasks.form.assignees_empty')}</p>
            ) : (
                <>
                    {people.length > SEARCH_FROM && (
                        <div className="relative">
                            <MagnifyingGlass weight="bold" size={16} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                            <input
                                type="search"
                                className="input pl-9"
                                aria-label={t('tasks.form.assignees_search')}
                                placeholder={t('tasks.form.assignees_search')}
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                // Enter in a search box means "search", not "save the task" or "approve the proposal"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') e.preventDefault();
                                }}
                            />
                        </div>
                    )}
                    <ul className="m-0 flex max-h-[264px] list-none flex-col overflow-y-auto rounded-[var(--radius-sm)] border border-line p-0">
                        {visible.map((person) => {
                            const checked = picked.has(person.id);
                            return (
                                <li key={person.id} className="border-t border-line first:border-t-0">
                                    <label className={`flex min-h-11 items-center gap-3 px-3 py-1.5 ${!checked && full ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-panel'}`}>
                                        <input
                                            type="checkbox"
                                            className="size-5 flex-none accent-[var(--primary-bg)]"
                                            checked={checked}
                                            disabled={!checked && full}
                                            onChange={(e) => toggle(person.id, e.target.checked)}
                                        />
                                        <Avatar initials={person.initials} photoUrl={person.photo_url} className="h-7 w-7 text-[11px]" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-semibold">{person.name}</span>
                                            <span className="block truncate text-[13px] text-muted">
                                                {person.username}
                                                {!person.active && ` · ${t('tasks.form.assignee_inactive')}`}
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                        {visible.length === 0 && <li className="px-3 py-3 text-sm text-muted">{t('tasks.form.assignees_search_empty', { q: query.trim() })}</li>}
                    </ul>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        {full ? <p className="help m-0">{t('tasks.form.assignees_max', { max })}</p> : <span />}
                        {value.length > 0 && (
                            <button type="button" className="btn btn-quiet btn-sm min-h-11" onClick={() => onChange([])}>
                                {t('tasks.form.assignees_clear')}
                            </button>
                        )}
                    </div>
                </>
            )}

            {error && (
                <p id={`${id}-error`} className="error-text m-0 flex items-center gap-1.5" role="alert">
                    <WarningCircle weight="bold" size={15} aria-hidden />
                    {error}
                </p>
            )}
        </fieldset>
    );
}

/** Same people, in any order: an unchanged list is not sent, so saving other fields never touches anyone's part. */
export function sameIds(a: number[], b: number[]): boolean {
    const x = [...a].sort((p, q) => p - q);
    const y = [...b].sort((p, q) => p - q);
    return x.length === y.length && x.every((id, i) => id === y[i]);
}

/** The first error Laravel sent for the list or one of its items (`assignee_ids.2`). */
export function assigneeError(errors: Record<string, string | undefined>): string | undefined {
    return Object.entries(errors).find(([key]) => key === 'assignee_ids' || key.startsWith('assignee_ids.'))?.[1];
}
