import { OwlEyes } from '@/components/owl/OwlEyes';
import { Avatar } from '@/components/ui/Avatar';
import { Notice } from '@/components/ui/Notice';
import { useLocale, useT } from '@/lib/i18n';
import { saveTheme, useThemeSync } from '@/lib/theme';
import type { Brand, NavGroup, NavItem, SharedProps, TaskTimer, ThemePreference } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CaretDoubleLeft, CaretDoubleRight, CaretUpDown, Key, List, SignOut, Timer, UserCircle, X } from '@phosphor-icons/react';
import { type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { navIcons } from './navIcons';
import { PINNED_GROUP, badgeCount, currentChild, currentSection, itemIsCurrent } from './navModel';

interface Props {
    title: string;
    children: ReactNode;
}

const SIDEBAR_KEY = 'owlorix.sidebar_collapsed';
/** Phone bottom bar: the first four of these the person can open, then Menu. */
const BOTTOM_BAR_ORDER = ['my_day', 'my_tasks', 'approvals', 'team_today', 'projects', 'history', 'overtime', 'leave', 'activity_log', 'calendar', 'reports', 'monitoring', 'settings', 'guide'];

/** The short name for the phone bottom bar, or the full name when there is no short one. */
function shortLabel(t: (key: string) => string, key: string) {
    const short = t(`common.nav.short.${key}`);
    return short === `common.nav.short.${key}` ? t(`common.nav.${key}`) : short;
}

function bottomRank(key: string) {
    const index = BOTTOM_BAR_ORDER.indexOf(key);
    return index === -1 ? BOTTOM_BAR_ORDER.length : index;
}

function readCollapsed(): boolean {
    try {
        return localStorage.getItem(SIDEBAR_KEY) === '1';
    } catch {
        return false;
    }
}

function writeCollapsed(value: boolean) {
    try {
        localStorage.setItem(SIDEBAR_KEY, value ? '1' : '0');
    } catch {
        // Private mode / blocked storage: preference is session-only.
    }
}

function useBadges(): Record<string, number> {
    return (usePage<SharedProps>().props.nav_badges ?? {}) as Record<string, number>;
}

/**
 * App shell, layout A from docs/desainUI_v2: one quiet surface, no top bar on a wide screen. The menu carries the
 * running task timer and the account (language and theme live there, not on every page). Phones get a slim top bar,
 * a bottom bar with the four most used pages, and a Menu sheet for the rest.
 */
export default function AppShell({ title, children }: Props) {
    useThemeSync();
    const { props, url } = usePage<SharedProps>();
    const t = useT();
    const locale = useLocale();
    const badges = useBadges();
    const [menuOpen, setMenuOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);

    useEffect(() => setCollapsed(readCollapsed()), []);
    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);

    const toggleCollapsed = () => {
        setCollapsed((prev) => {
            writeCollapsed(!prev);
            return !prev;
        });
    };

    const nav = props.nav;
    const mainGroups = nav.filter((group) => group.group !== PINNED_GROUP);
    const pinned = nav.find((group) => group.group === PINNED_GROUP);
    const flat = nav.flatMap((group) => group.items);
    const bottomItems = [...flat].sort((a, b) => bottomRank(a.key) - bottomRank(b.key)).slice(0, 4);
    const menuCount = flat.filter((item) => !bottomItems.includes(item)).reduce((sum, item) => sum + badgeCount(item, badges), 0);

    // md+: lock the shell to the viewport so the menu never grows past the screen; only the page column scrolls.
    const shellCols = collapsed ? 'md:grid-cols-[76px_minmax(0,1fr)]' : 'md:grid-cols-[248px_minmax(0,1fr)]';

    return (
        <>
            <Head title={title} />
            <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-sm focus:bg-surface focus:px-3 focus:py-2">
                {locale === 'id' ? 'Lompat ke isi' : 'Skip to content'}
            </a>

            <div className={`min-h-dvh md:grid md:h-dvh md:overflow-hidden ${shellCols}`}>
                <aside className="hidden border-r border-line bg-paper md:flex md:h-full md:min-h-0 md:flex-col" aria-label={t('common.nav.main')}>
                    <div className={`flex flex-none items-center gap-1 pt-4 pb-3 ${collapsed ? 'flex-col px-2' : 'px-3'}`}>
                        <BrandLink collapsed={collapsed} />
                        <button
                            type="button"
                            onClick={toggleCollapsed}
                            className={`inline-flex size-10 flex-none cursor-pointer items-center justify-center rounded-sm text-muted hover:bg-surface hover:text-ink ${collapsed ? '' : 'ml-auto'}`}
                            aria-pressed={collapsed}
                            aria-label={collapsed ? t('common.shell.sidebar_expand') : t('common.shell.sidebar_collapse')}
                            title={collapsed ? t('common.shell.sidebar_expand') : t('common.shell.sidebar_collapse')}
                        >
                            {collapsed ? <CaretDoubleRight weight="bold" size={18} aria-hidden /> : <CaretDoubleLeft weight="bold" size={18} aria-hidden />}
                        </button>
                    </div>

                    {props.task_timer && (
                        <div className={`flex-none pb-3 ${collapsed ? 'px-2' : 'px-3'}`}>
                            <TimerCard timer={props.task_timer} collapsed={collapsed} />
                        </div>
                    )}

                    <div className={`min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain pb-3 ${collapsed ? 'px-2' : 'px-3'}`}>
                        <NavList groups={mainGroups} url={url} collapsed={collapsed} />
                    </div>

                    <div className={`flex flex-none flex-col gap-2 border-t border-line py-3 ${collapsed ? 'px-2' : 'px-3'}`}>
                        {pinned && <NavList groups={[pinned]} url={url} collapsed={collapsed} />}
                        {props.auth && <AccountMenu variant={collapsed ? 'rail' : 'sidebar'} />}
                    </div>
                </aside>

                <div className="flex min-h-dvh min-w-0 flex-col md:h-full md:min-h-0 md:overflow-y-auto">
                    <MobileTopBar title={title} />
                    {props.auth?.imposter && <ImposterBanner name={props.auth.imposter.actor_name} actingAs={props.auth.user.name} />}
                    <main id="main" className="flex-1 px-4 pt-5 pb-10 sm:px-6 sm:pt-7 lg:px-10 lg:pt-9">
                        <div className="w-full max-w-[1280px]">
                            <SectionHeader groups={nav} url={url} />
                            {props.flash.status && (
                                <Notice tone="success" className="mb-5">
                                    {props.flash.status}
                                </Notice>
                            )}
                            {children}
                        </div>
                    </main>
                    <AppFooter />
                </div>
            </div>

            {flat.length > 0 && (
                <nav aria-label={t('common.nav.main')} className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] md:hidden">
                    <ul className="m-0 grid list-none grid-cols-5 p-0">
                        {bottomItems.map((item) => {
                            const Icon = navIcons[item.key];
                            const current = itemIsCurrent(url, item);
                            return (
                                <li key={item.key}>
                                    <Link
                                        href={item.href}
                                        aria-current={current ? 'page' : undefined}
                                        className={`flex min-h-[60px] flex-col items-center justify-center gap-1 px-0.5 text-center text-[11px] font-semibold sm:text-xs ${current ? 'text-ink' : 'text-muted'}`}
                                    >
                                        <span className={`relative ${current ? 'text-heading' : ''}`}>
                                            {Icon && <Icon weight={current ? 'fill' : 'bold'} size={22} aria-hidden />}
                                            <CountBadge count={badgeCount(item, badges)} className="absolute -top-2 left-3.5" />
                                        </span>
                                        <span className="max-w-full truncate px-0.5 leading-tight">{shortLabel(t, item.key)}</span>
                                    </Link>
                                </li>
                            );
                        })}
                        <li className="col-start-5">
                            <button
                                type="button"
                                onClick={() => setMenuOpen(true)}
                                aria-haspopup="dialog"
                                className="flex min-h-[60px] w-full cursor-pointer flex-col items-center justify-center gap-1 text-[11px] font-semibold text-muted sm:text-xs"
                            >
                                <span className="relative">
                                    <List weight="bold" size={22} aria-hidden />
                                    <CountBadge count={menuCount} className="absolute -top-2 left-3.5" />
                                </span>
                                {t('common.nav.menu')}
                            </button>
                        </li>
                    </ul>
                </nav>
            )}

            {menuOpen && <MobileMenu groups={nav} url={url} onClose={() => setMenuOpen(false)} />}
        </>
    );
}

/**
 * Studio logo from Pengaturan aplikasi. The bundled logo has black lettering on white, so every logo sits on a
 * white tile and reads the same in both themes.
 */
function BrandLogo({ brand, size }: { brand: Brand; size: number }) {
    return <img src={brand.logo_url} alt="" width={size} height={size} className="flex-none rounded-[6px] bg-white object-contain" style={{ width: size, height: size }} />;
}

function BrandLink({ collapsed }: { collapsed?: boolean }) {
    const brand = usePage<SharedProps>().props.app.brand;

    return (
        <Link href={route('my-day')} className={`flex min-w-0 items-center gap-2.5 rounded-sm py-1 ${collapsed ? 'p-1' : 'px-1.5'}`} aria-label={collapsed ? brand.name : undefined}>
            <BrandLogo brand={brand} size={34} />
            {!collapsed && <span className="truncate font-display text-[18px] font-[750] tracking-[-0.01em] text-heading">{brand.name}</span>}
        </Link>
    );
}

/** Slim bar on phones: where you are, the running timer, and the account. Wide screens have no top bar. */
function MobileTopBar({ title }: { title: string }) {
    const { props } = usePage<SharedProps>();

    return (
        <header className="sticky top-0 z-20 flex h-14 flex-none items-center gap-2.5 border-b border-line bg-paper pr-2 pl-4 md:hidden">
            <OwlEyes state="open" size={32} />
            <span className="min-w-0 flex-1 truncate font-display text-[18px] font-bold text-heading">{title}</span>
            {props.task_timer && <TimerChip timer={props.task_timer} />}
            {props.auth && <AccountMenu variant="top" />}
        </header>
    );
}

function useTimerLabel(timer: TaskTimer) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 30_000);
        return () => window.clearInterval(id);
    }, []);

    const minutes = Math.max(0, Math.floor((now - new Date(timer.started_at).getTime()) / 60_000));
    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
}

/** Running task timer on every page, so a forgotten timer gets noticed. Opens the task. */
function TimerCard({ timer, collapsed }: { timer: TaskTimer; collapsed: boolean }) {
    const t = useT();
    const label = useTimerLabel(timer);

    return (
        <Link
            href={route('tasks.show', timer.task_id)}
            className={`flex min-h-11 items-center rounded-md border border-line bg-surface hover:border-line-strong ${collapsed ? 'flex-col justify-center gap-0.5 px-1 py-2' : 'gap-3 px-3 py-2.5'}`}
            title={timer.task_title}
            aria-label={t('common.shell.timer_label', { task: timer.task_title, time: label })}
        >
            <Timer weight="bold" size={18} aria-hidden className="flex-none text-teal-text" />
            {collapsed ? (
                <span className="num text-[13px] font-semibold">{label}</span>
            ) : (
                <span className="flex min-w-0 flex-col">
                    <span className="num leading-tight font-semibold">{label}</span>
                    <span className="truncate text-[13px] text-muted">{timer.task_title}</span>
                </span>
            )}
        </Link>
    );
}

function TimerChip({ timer }: { timer: TaskTimer }) {
    const t = useT();
    const label = useTimerLabel(timer);

    return (
        <Link
            href={route('tasks.show', timer.task_id)}
            className="num inline-flex min-h-11 flex-none items-center gap-1.5 rounded-sm border border-line bg-surface px-2.5 text-sm font-semibold"
            title={timer.task_title}
            aria-label={t('common.shell.timer_label', { task: timer.task_title, time: label })}
        >
            <Timer weight="bold" size={16} aria-hidden className="text-teal-text" />
            {label}
        </Link>
    );
}

/** Tabs of the open section (Persetujuan, Pantauan), or the way back to the Pengaturan page from one of its pages. */
function SectionHeader({ groups, url }: { groups: NavGroup[]; url: string }) {
    const t = useT();
    const badges = useBadges();
    const section = currentSection(groups, url);

    if (!section?.children) return null;

    if (section.key === 'settings') {
        return (
            <p className="m-0 mb-3 text-sm">
                <Link href={section.href} className="link">
                    {t('common.nav.settings')}
                </Link>
            </p>
        );
    }

    if (section.children.length < 2) return null;
    const open = currentChild(section, url);

    return (
        <nav aria-label={t(`common.nav.${section.key}`)} className="tabbar mb-6">
            {section.children.map((child) => (
                <Link key={child.key} href={child.href} aria-current={open?.key === child.key ? 'page' : undefined}>
                    {t(`common.nav.tabs.${child.key}`)}
                    <CountBadge count={badges[child.key] ?? 0} />
                </Link>
            ))}
        </nav>
    );
}

/** Footer text, credit link, and contact come from Pengaturan aplikasi; empty values are left out. */
function AppFooter() {
    const t = useT();
    const brand = usePage<SharedProps>().props.app.brand;
    const year = new Date().getFullYear();
    const hasLink = brand.footer_link_label !== '' && brand.footer_link_url !== '';

    return (
        <footer className="mt-auto border-t border-line px-4 pt-4 pb-[calc(1rem+60px+env(safe-area-inset-bottom))] text-[13px] text-muted sm:px-6 md:py-4 lg:px-10">
            <div className="flex max-w-[1280px] flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                <p className="m-0">
                    {t('common.shell.footer_copy', { year, studio: brand.studio })}
                    {brand.footer_text !== '' && ` · ${brand.footer_text}`}
                    {hasLink && (
                        <>
                            {brand.footer_text !== '' ? ' ' : ' · '}
                            <a href={brand.footer_link_url} target="_blank" rel="noopener noreferrer" className="link">
                                {brand.footer_link_label}
                            </a>
                        </>
                    )}
                </p>
                {brand.contact_email !== '' && (
                    <p className="m-0">
                        <a href={`mailto:${brand.contact_email}`} className="link">
                            {brand.contact_email}
                        </a>
                    </p>
                )}
            </div>
        </footer>
    );
}

function ImposterBanner({ name, actingAs }: { name: string; actingAs: string }) {
    const t = useT();
    const [working, setWorking] = useState(false);

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line bg-gold px-4 py-2.5 text-[#1A1A2E] sm:px-6 lg:px-10" role="status">
            <p className="m-0 text-sm font-semibold">
                {t('common.shell.imposter_banner', { name: actingAs })}
                <span className="font-normal"> ({name})</span>
            </p>
            <button
                type="button"
                className="btn btn-secondary btn-sm min-h-11 border-[#1A1A2E]/30 bg-paper"
                disabled={working}
                onClick={() => {
                    setWorking(true);
                    router.post(route('imposter.stop'), {}, { onFinish: () => setWorking(false) });
                }}
            >
                {t('common.shell.imposter_return')}
            </button>
        </div>
    );
}

function CountBadge({ count, className = '' }: { count: number; className?: string }) {
    const t = useT();

    if (count <= 0) return null;

    return (
        <span className={`inline-flex ${className}`}>
            <span aria-hidden className="num inline-flex h-[20px] min-w-[20px] items-center justify-center rounded-full bg-gold px-1.5 text-xs leading-none font-bold text-[#1A1A2E]">
                {count > 99 ? '99+' : count}
            </span>
            <span className="sr-only">{t('common.nav.group_badge', { count })}</span>
        </span>
    );
}

function NavList({ groups, url, collapsed = false, large = false }: { groups: NavGroup[]; url: string; collapsed?: boolean; large?: boolean }) {
    const t = useT();
    const badges = useBadges();

    return (
        <div className="flex flex-col gap-0.5">
            {groups.map((group, index) => (
                <div key={group.group} className="flex flex-col gap-0.5">
                    {collapsed
                        ? index > 0 && <div className="mx-2 my-2 border-t border-line" role="separator" />
                        : group.group !== PINNED_GROUP || large
                          ? (
                                <p className={`m-0 px-2.5 pb-1 text-[13px] font-semibold text-muted ${index === 0 ? 'pt-1' : 'pt-4'}`}>{t(`common.nav.groups.${group.group}`)}</p>
                            )
                          : null}
                    {group.items.map((item) => (
                        <NavLink key={item.key} item={item} url={url} collapsed={collapsed} large={large} count={badgeCount(item, badges)} />
                    ))}
                </div>
            ))}
        </div>
    );
}

function NavLink({ item, url, collapsed, large, count }: { item: NavItem; url: string; collapsed: boolean; large: boolean; count: number }) {
    const t = useT();
    const Icon = navIcons[item.key];
    const label = t(`common.nav.${item.key}`);

    return (
        <Link
            href={item.href}
            className={`nav-item ${large ? '' : 'nav-item--compact'} ${collapsed ? 'justify-center px-0' : ''}`}
            aria-current={itemIsCurrent(url, item) ? 'page' : undefined}
            aria-label={collapsed ? label : undefined}
            title={collapsed ? label : undefined}
        >
            {Icon && <Icon weight="bold" size={collapsed ? 20 : 18} aria-hidden />}
            {!collapsed && <span className="min-w-0 truncate">{label}</span>}
            {!collapsed && <CountBadge count={count} className="ml-auto" />}
            {collapsed && <CountBadge count={count} className="absolute top-0.5 right-0.5 scale-90" />}
        </Link>
    );
}

/** Language and theme: set once, so they live in the account menu instead of on every page. */
function Preferences() {
    const t = useT();
    const locale = useLocale();
    const pref = usePage<SharedProps>().props.auth?.user.theme ?? 'system';

    const chooseLocale = (value: 'id' | 'en') => {
        if (value !== locale) router.patch(route('preferences.update'), { locale: value }, { preserveScroll: true });
    };
    const themes: ThemePreference[] = ['light', 'dark', 'system'];

    return (
        <div className="flex flex-col gap-3 px-2.5 py-2">
            <div className="flex flex-col gap-1.5">
                <span className="text-[13px] font-semibold text-muted">{t('common.shell.language')}</span>
                <div className="seg self-start" role="group" aria-label={t('common.shell.language')}>
                    <button type="button" aria-pressed={locale === 'id'} onClick={() => chooseLocale('id')} lang="id">
                        Indonesia
                    </button>
                    <button type="button" aria-pressed={locale === 'en'} onClick={() => chooseLocale('en')} lang="en">
                        English
                    </button>
                </div>
            </div>
            <div className="flex flex-col gap-1.5">
                <span className="text-[13px] font-semibold text-muted">{t('common.shell.theme')}</span>
                <div className="seg self-start" role="group" aria-label={t('common.shell.theme')}>
                    {themes.map((value) => (
                        <button key={value} type="button" aria-pressed={pref === value} onClick={() => pref !== value && saveTheme(value)}>
                            {t(`common.shell.theme_${value}`)}
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}

function AccountLinks({ onPick }: { onPick?: () => void }) {
    const t = useT();

    return (
        <>
            <Link href={route('profile.edit')} className="nav-item" onClick={onPick}>
                <UserCircle weight="bold" size={18} aria-hidden />
                {t('common.shell.profile')}
            </Link>
            <Link href={route('password.edit')} className="nav-item" onClick={onPick}>
                <Key weight="bold" size={18} aria-hidden />
                {t('common.shell.change_password')}
            </Link>
        </>
    );
}

function SignOutLink() {
    const t = useT();

    return (
        <Link href={route('sign-out')} method="post" as="button" className="nav-item w-full cursor-pointer text-left">
            <SignOut weight="bold" size={18} aria-hidden />
            {t('common.shell.sign_out')}
        </Link>
    );
}

/**
 * The account: profile, password, language, theme, sign out. A disclosure (button + panel), closed with Escape or a
 * click outside; focus goes into the panel on open and back to the button on Escape.
 */
function AccountMenu({ variant }: { variant: 'sidebar' | 'rail' | 'top' }) {
    const { props } = usePage<SharedProps>();
    const t = useT();
    const user = props.auth!.user;
    const [open, setOpen] = useState(false);
    const panelId = useId();
    const wrapper = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setOpen(false);
                trigger.current?.focus();
            }
        };
        const onClick = (e: MouseEvent) => {
            if (!wrapper.current?.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onClick);
        wrapper.current?.querySelector<HTMLElement>(`#${CSS.escape(panelId)} a, #${CSS.escape(panelId)} button`)?.focus();
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onClick);
        };
    }, [open, panelId]);

    const panelPlace = variant === 'top' ? 'top-full right-0 mt-2' : variant === 'rail' ? 'bottom-0 left-full ml-3' : 'bottom-full left-0 mb-2';

    return (
        <div ref={wrapper} className="relative">
            <button
                ref={trigger}
                type="button"
                className={
                    variant === 'sidebar'
                        ? 'flex min-h-[52px] w-full cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 text-left hover:bg-surface'
                        : 'inline-flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-full'
                }
                aria-expanded={open}
                aria-controls={panelId}
                aria-label={variant === 'sidebar' ? undefined : t('common.shell.account')}
                onClick={() => setOpen((v) => !v)}
            >
                <Avatar initials={user.initials} photoUrl={user.photo_url} className={variant === 'sidebar' ? 'h-9 w-9' : 'h-10 w-10'} />
                {variant === 'sidebar' && (
                    <>
                        <span className="flex min-w-0 flex-1 flex-col">
                            <span className="truncate text-[14px] leading-tight font-semibold">{user.display_name}</span>
                            <span className="truncate text-[13px] text-muted">{user.username}</span>
                        </span>
                        <CaretUpDown weight="bold" size={16} aria-hidden className="flex-none text-muted" />
                    </>
                )}
            </button>
            {open && (
                <div id={panelId} className={`floating absolute z-40 w-[280px] max-w-[calc(100vw-24px)] rounded-md border border-line bg-surface p-2 ${panelPlace}`}>
                    <div className="flex items-center gap-3 px-2.5 pt-1.5 pb-3">
                        <Avatar initials={user.initials} photoUrl={user.photo_url} className="h-10 w-10" />
                        <div className="min-w-0">
                            <p className="m-0 truncate font-semibold">{user.display_name}</p>
                            <p className="m-0 truncate text-[13px] text-muted">{t('common.shell.signed_in_as', { username: user.username })}</p>
                        </div>
                    </div>
                    <AccountLinks onPick={() => setOpen(false)} />
                    <div className="my-1.5 border-t border-line" />
                    <Preferences />
                    <div className="my-1.5 border-t border-line" />
                    <SignOutLink />
                </div>
            )}
        </div>
    );
}

function MobileMenu({ groups, url, onClose }: { groups: NavGroup[]; url: string; onClose: () => void }) {
    const t = useT();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();

    useEffect(() => {
        ref.current?.showModal();
    }, []);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            className="m-0 mt-auto h-auto max-h-[88dvh] w-full max-w-none overflow-y-auto rounded-t-2xl border-0 bg-surface px-4 pt-4 pb-[calc(20px+env(safe-area-inset-bottom))] text-ink backdrop:bg-[rgb(16_18_31/0.55)]"
        >
            <div className="mb-1 flex items-center justify-between">
                <h2 id={titleId} className="font-display text-[20px] font-bold text-heading">
                    {t('common.nav.menu')}
                </h2>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11 px-0" aria-label={t('common.nav.close_menu')}>
                    <X weight="bold" size={16} aria-hidden />
                </button>
            </div>
            <NavList groups={groups} url={url} large />
            <p className="m-0 px-2.5 pt-4 pb-1 text-[13px] font-semibold text-muted">{t('common.shell.account')}</p>
            <AccountLinks />
            <Preferences />
            <SignOutLink />
        </dialog>
    );
}
