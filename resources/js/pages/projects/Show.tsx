import { Avatar } from '@/components/ui/Avatar';
import { TextField, SelectField, TextAreaField } from '@/components/ui/Field';
import { Dialog } from '@/components/ui/Dialog';
import AppShell from '@/layouts/AppShell';
import { formatShortDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { NotePencil, Plus, Trash, X } from '@phosphor-icons/react';
import { type FormEvent, type KeyboardEvent, type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { budgetHoursValue, formatHours } from './BudgetMeter';
import { type Milestone, type MilestoneKind, Milestones } from './Milestones';
import { type LinkCategory, type ProjectLink, ProjectLinks } from './ProjectLinks';
import { SubProjectDialog } from './SubProjectDialog';
import { SubProjectList } from './SubProjectList';
import type { BudgetData, PersonOption as LeadOption, SubProjectData } from './taskTypes';

type ProjectStatus = 'planned' | 'active' | 'done';

interface Member {
    id: number;
    name: string;
    username: string;
    initials: string;
    photo_url: string | null;
    status: string;
    assigned_at: string | null;
}

interface PersonOption {
    id: number;
    name: string;
    username: string;
    status: string;
}

interface ProjectDetail {
    id: number;
    name: string;
    code: string | null;
    status: ProjectStatus;
    description: string | null;
    members_count: number;
    members: Member[];
}

interface PageProps {
    project: ProjectDetail;
    people: PersonOption[];
    can_manage: boolean;
    statuses: ProjectStatus[];
    is_assigned: boolean;
    sub_projects: SubProjectData[];
    leads: LeadOption[];
    milestones: Milestone[];
    milestone_kinds: MilestoneKind[];
    /** Null when the viewer is not involved in the project */
    links: ProjectLink[] | null;
    link_categories: LinkCategory[];
    can_budget: boolean;
    /** Only for people with projects.budget; absent for everyone else. */
    budget?: BudgetData;
}

type Tab = 'subs' | 'milestones' | 'links' | 'members';
const TABS: Tab[] = ['subs', 'milestones', 'links', 'members'];
const TAB_PARAM = 'bagian';

function tabFromUrl(): Tab {
    if (typeof window === 'undefined') return 'subs';
    const value = new URLSearchParams(window.location.search).get(TAB_PARAM);
    return TABS.includes(value as Tab) ? (value as Tab) : 'subs';
}

/**
 * One project, layout A (docs/desainUI_v2): the name and the two actions, three facts in one ruled row, then the
 * parts of the project as tabs so one part shows at a time instead of one long page. The open tab is kept in the
 * address (?bagian=) so a reload or a shared link opens the same part.
 */
export default function ProjectShow() {
    const { props } = usePage<SharedProps & PageProps>();
    const t = useT();
    const { project, can_manage, is_assigned } = props;
    const [editing, setEditing] = useState(false);
    const [creatingSub, setCreatingSub] = useState(false);
    const [tab, setTab] = useState<Tab>('subs');

    useEffect(() => setTab(tabFromUrl()), []);

    const choose = (next: Tab) => {
        setTab(next);
        const url = new URL(window.location.href);
        if (next === 'subs') url.searchParams.delete(TAB_PARAM);
        else url.searchParams.set(TAB_PARAM, next);
        window.history.replaceState(window.history.state, '', url);
    };

    const unassign = (member: Member) => {
        if (!window.confirm(t('projects.unassign_label', { name: member.name }))) return;
        router.delete(route('projects.unassign', [project.id, member.id]), { preserveScroll: true });
    };

    const labels: Record<Tab, string> = {
        subs: t('tasks.sub.heading'),
        milestones: t('projects.milestones.heading'),
        links: t('projects.links.heading'),
        members: t('projects.members_heading'),
    };
    const counts: Partial<Record<Tab, number>> = {
        subs: props.sub_projects.length,
        milestones: props.milestones.length,
        links: props.links?.length,
        members: project.members.length,
    };

    return (
        <AppShell title={project.name}>
            <p className="m-0 text-sm">
                <Link href={route('projects.index')} className="link">
                    {t('projects.title')}
                </Link>
            </p>
            <header className="mt-1.5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div className="min-w-0">
                    <h1 className="h1 break-words">{project.name}</h1>
                    <p className="m-0 mt-1 text-muted">
                        {project.code ?? t('projects.no_code')} · {t(`projects.status.${project.status}`)}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2.5 sm:flex-none">
                    {can_manage && (
                        <button type="button" className="btn btn-secondary" onClick={() => setEditing(true)}>
                            {t('projects.manage')}
                        </button>
                    )}
                    {(is_assigned || can_manage) && (
                        <Link href={route('activity.index', { project_id: project.id })} className="btn btn-primary">
                            <NotePencil weight="bold" size={18} aria-hidden />
                            {t('projects.log_activity')}
                        </Link>
                    )}
                </div>
            </header>
            {project.description && <p className="m-0 mt-3 max-w-[72ch]">{project.description}</p>}

            <ProjectFacts subs={props.sub_projects} milestones={props.milestones} budget={props.budget} />

            <Tabs tab={tab} onChoose={choose} labels={labels} counts={counts} />

            <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} tabIndex={0} className="pt-6 focus-visible:outline-offset-4">
                {tab === 'subs' && (
                    <section aria-labelledby="subs-heading">
                        <h2 id="subs-heading" className="sr-only">
                            {t('tasks.sub.heading')}
                        </h2>
                        <PanelLead text={t('tasks.sub.lead')}>
                            {can_manage && (
                                <button type="button" className="btn btn-secondary" onClick={() => setCreatingSub(true)}>
                                    <Plus weight="bold" size={18} aria-hidden />
                                    {t('tasks.sub.add')}
                                </button>
                            )}
                        </PanelLead>
                        <SubProjectList projectId={project.id} items={props.sub_projects} canManage={can_manage} />
                    </section>
                )}

                {tab === 'milestones' && <Milestones projectId={project.id} items={props.milestones} kinds={props.milestone_kinds} canManage={can_manage} inTab />}

                {tab === 'links' && <ProjectLinks projectId={project.id} items={props.links} categories={props.link_categories} canManage={can_manage} inTab />}

                {tab === 'members' && (
                    <section aria-labelledby="members-heading">
                        <h2 id="members-heading" className="sr-only">
                            {t('projects.members_heading')}
                        </h2>

                        {can_manage && <AssignForm projectId={project.id} people={props.people} />}

                        {project.members.length === 0 ? (
                            <p className={`m-0 text-muted ${can_manage ? 'mt-6' : ''}`}>{t('projects.members_empty')}</p>
                        ) : (
                            <ul className={`card m-0 list-none divide-y divide-line p-0 ${can_manage ? 'mt-6' : ''}`}>
                                {project.members.map((member) => (
                                    <li key={member.id} className="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
                                        <Avatar initials={member.initials} photoUrl={member.photo_url} />
                                        <div className="min-w-0 flex-1">
                                            <p className="m-0 font-semibold">{member.name}</p>
                                            <p className="m-0 text-sm break-all text-muted">{member.username}</p>
                                        </div>
                                        {can_manage && (
                                            <button type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => unassign(member)} aria-label={t('projects.unassign_label', { name: member.name })}>
                                                <Trash weight="bold" size={16} aria-hidden />
                                                {t('projects.unassign')}
                                            </button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </div>

            {can_manage && editing && <EditProjectDialog project={project} statuses={props.statuses} budgetMinutes={props.budget?.minutes} onClose={() => setEditing(false)} />}
            {can_manage && creatingSub && (
                <SubProjectDialog projectId={project.id} leads={props.leads} statuses={props.statuses} budgetMinutes={props.can_budget ? null : undefined} onClose={() => setCreatingSub(false)} />
            )}
        </AppShell>
    );
}

/** The short explanation of a tab and its add button, on one line when there is room. */
function PanelLead({ text, children }: { text: string; children?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-3">
            <p className="m-0 max-w-[70ch] text-sm text-muted">{text}</p>
            {children}
        </div>
    );
}

/** Tasks done, the hour budget (budget holders only), and the next milestone, in one ruled row. */
function ProjectFacts({ subs, milestones, budget }: { subs: SubProjectData[]; milestones: Milestone[]; budget?: BudgetData }) {
    const t = useT();
    const locale = useLocale();
    const total = subs.reduce((sum, sub) => sum + (sub.tasks_total ?? 0), 0);
    const done = subs.reduce((sum, sub) => sum + (sub.tasks_done ?? 0), 0);
    const next = milestones.find((milestone) => milestone.status !== 'done');

    return (
        <dl className={`m-0 mt-6 grid border-y border-line sm:mt-7 ${budget ? 'sm:grid-cols-3' : 'sm:grid-cols-2'}`}>
            <Fact label={t('projects.facts.tasks')}>
                {total === 0 ? (
                    <span className="text-muted">{t('projects.facts.no_tasks')}</span>
                ) : (
                    <>
                        <span className="num">{t('projects.facts.tasks_value', { done, total })}</span>
                        <span className="meter mt-2.5 block" role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={done} aria-label={t('projects.facts.tasks')}>
                            <span style={{ width: `${Math.round((done / total) * 100)}%` }} />
                        </span>
                    </>
                )}
            </Fact>
            {budget && (
                <Fact label={t('projects.budget.heading')}>
                    {budget.minutes === null ? (
                        <span className="num text-[15px] font-normal">{t('projects.budget.none', { logged: formatHours(budget.logged_minutes, locale) })}</span>
                    ) : (
                        <BudgetFact logged={budget.logged_minutes} limit={budget.minutes} />
                    )}
                </Fact>
            )}
            <Fact label={t('projects.facts.next_milestone')}>
                {next ? (
                    <>
                        <span className="block break-words">{next.name}</span>
                        <span className="num mt-0.5 block text-sm font-normal text-muted">{formatShortDate(next.due_date, locale)}</span>
                    </>
                ) : (
                    <span className="text-muted">{t('projects.facts.no_milestone')}</span>
                )}
            </Fact>
        </dl>
    );
}

/** Hours logged against the budget: teal while comfortable, gold from 80 percent, red past it; the numbers are always written. */
function BudgetFact({ logged, limit }: { logged: number; limit: number }) {
    const t = useT();
    const locale = useLocale();
    const ratio = limit > 0 ? logged / limit : 0;
    const fill = ratio > 1 ? 'var(--danger)' : ratio >= 0.8 ? 'var(--gold)' : undefined;
    const text =
        logged > limit
            ? t('projects.budget.over', { logged: formatHours(logged, locale), budget: formatHours(limit, locale), over: formatHours(logged - limit, locale) })
            : t('projects.budget.text', { logged: formatHours(logged, locale), budget: formatHours(limit, locale) });

    return (
        <>
            <span className="num">{text}</span>
            <span className="meter mt-2.5 block" role="meter" aria-valuemin={0} aria-valuemax={limit} aria-valuenow={Math.round(logged)} aria-valuetext={text} aria-label={t('projects.budget.meter_label')}>
                <span style={{ width: `${Math.min(100, ratio * 100)}%`, ...(fill ? { background: fill } : {}) }} />
            </span>
        </>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="border-line py-4 not-first:border-t sm:px-6 sm:not-first:border-t-0 sm:not-first:border-l sm:first:pl-0">
            <dt className="text-sm text-muted">{label}</dt>
            <dd className="m-0 mt-1 text-[17px] font-semibold">{children}</dd>
        </div>
    );
}

/** WAI-ARIA tabs: arrow keys, Home, and End move between tabs; the panel follows the selected tab. */
function Tabs({ tab, onChoose, labels, counts }: { tab: Tab; onChoose: (tab: Tab) => void; labels: Record<Tab, string>; counts: Partial<Record<Tab, number>> }) {
    const t = useT();
    const refs = useRef<Partial<Record<Tab, HTMLButtonElement | null>>>({});

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const index = TABS.indexOf(tab);
        const next =
            event.key === 'ArrowRight' ? TABS[(index + 1) % TABS.length] : event.key === 'ArrowLeft' ? TABS[(index - 1 + TABS.length) % TABS.length] : event.key === 'Home' ? TABS[0] : event.key === 'End' ? TABS[TABS.length - 1] : null;
        if (!next) return;
        event.preventDefault();
        onChoose(next);
        refs.current[next]?.focus();
    };

    return (
        <div role="tablist" aria-label={t('projects.tabs.label')} className="tabbar mt-8" onKeyDown={onKeyDown}>
            {TABS.map((key) => (
                <button
                    key={key}
                    ref={(node) => {
                        refs.current[key] = node;
                    }}
                    type="button"
                    role="tab"
                    id={`tab-${key}`}
                    aria-selected={tab === key}
                    aria-controls={tab === key ? `panel-${key}` : undefined}
                    tabIndex={tab === key ? 0 : -1}
                    onClick={() => onChoose(key)}
                >
                    {labels[key]}
                    {counts[key] !== undefined && <span className="num font-medium text-muted">{counts[key]}</span>}
                </button>
            ))}
        </div>
    );
}

function AssignForm({ projectId, people }: { projectId: number; people: PersonOption[] }) {
    const t = useT();
    const form = useForm({ user_id: people[0]?.id?.toString() ?? '' });

    if (people.length === 0) {
        return <p className="help m-0 mt-3">{t('projects.form.assign_empty')}</p>;
    }

    return (
        <form
            className="flex flex-col gap-2.5 sm:flex-row sm:items-start"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(route('projects.assign', projectId), { preserveScroll: true, onSuccess: () => form.reset('user_id') });
            }}
        >
            <SelectField
                className="min-w-0 flex-1"
                label={t('projects.form.assign_title')}
                name="user_id"
                help={t('projects.form.assign_help')}
                value={form.data.user_id}
                onChange={(e) => form.setData('user_id', e.target.value)}
                error={form.errors.user_id}
            >
                <option value="">{t('projects.form.pick_person')}</option>
                {people.map((person) => (
                    <option key={person.id} value={person.id}>
                        {person.name} ({person.username})
                    </option>
                ))}
            </SelectField>
            <button type="submit" className="btn btn-primary field-action" disabled={form.processing || form.data.user_id === ''}>
                {t('projects.form.assign_submit')}
            </button>
        </form>
    );
}

/** `budgetMinutes` is passed only to people with projects.budget; without it the budget field is not shown or sent. */
function EditProjectDialog({ project, statuses, budgetMinutes, onClose }: { project: ProjectDetail; statuses: ProjectStatus[]; budgetMinutes?: number | null; onClose: () => void }) {
    const t = useT();
    const titleId = useId();
    const canBudget = budgetMinutes !== undefined;
    const form = useForm({
        name: project.name,
        code: project.code ?? '',
        status: project.status,
        description: project.description ?? '',
        budget_hours: budgetHoursValue(budgetMinutes),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform(({ budget_hours, ...data }) => ({ ...data, ...(canBudget ? { budget_hours: budget_hours === '' ? null : budget_hours } : {}) }));
        form.put(route('projects.update', project.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onClose={onClose} labelledBy={titleId} width="max-w-[520px]">
            <form onSubmit={submit} className="flex flex-col">
                <header className="flex items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-7">
                    <h2 id={titleId} className="h2">
                        {t('projects.form.edit_title')}
                    </h2>
                    <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 px-0" aria-label={t('common.actions.close')}>
                        <X weight="bold" size={18} aria-hidden />
                    </button>
                </header>
                <div className="flex flex-col gap-[18px] px-5 py-5 sm:px-7">
                    <TextField label={t('projects.form.name')} name="name" required maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} error={form.errors.name} />
                    <TextField label={t('projects.form.code')} name="code" maxLength={40} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} help={t('projects.form.code_help')} error={form.errors.code} />
                    <SelectField label={t('projects.form.status')} name="status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value as ProjectStatus)} error={form.errors.status}>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {t(`projects.status.${status}`)}
                            </option>
                        ))}
                    </SelectField>
                    {canBudget && (
                        <TextField
                            label={t('projects.budget.field')}
                            name="budget_hours"
                            help={t('projects.budget.field_help')}
                            type="number"
                            inputMode="decimal"
                            min={0.25}
                            max={99999}
                            step={0.25}
                            value={form.data.budget_hours}
                            onChange={(e) => form.setData('budget_hours', e.target.value)}
                            error={form.errors.budget_hours}
                        />
                    )}
                    <TextAreaField label={t('projects.form.description')} name="description" rows={3} maxLength={2000} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} error={form.errors.description} />
                </div>
                <footer className="flex justify-end gap-2.5 border-t border-line px-5 py-3.5 sm:px-7">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>
                        {t('projects.form.cancel')}
                    </button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        {form.processing ? t('common.actions.saving') : t('projects.form.submit_edit')}
                    </button>
                </footer>
            </form>
        </Dialog>
    );
}
