import { OwlEyes } from '@/components/owl/OwlEyes';
import { Notice } from '@/components/ui/Notice';
import { formatLongDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { effectiveTheme, saveTheme, useThemeSync } from '@/lib/theme';
import type { NavGroup, SharedProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CaretDoubleLeft, CaretDoubleRight, DotsNine, Key, Moon, SignOut, Sun, X } from '@phosphor-icons/react';
import { type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { navIcons } from './navIcons';

interface Props {
    title: string;
    children: ReactNode;
}

const SIDEBAR_KEY = 'owlorix.sidebar_collapsed';
const BOTTOM_BAR_ORDER = ['my_day', 'approvals', 'team_today', 'history', 'overtime', 'my_tasks', 'activity_log', 'projects', 'reports', 'calendar', 'people', 'teams'];

function bottomRank(key: string) {
    const index = BOTTOM_BAR_ORDER.indexOf(key);
    return index === -1 ? BOTTOM_BAR_ORDER.length : index;
}

function isCurrent(url: string, href: string) {
    const path = url.split('?')[0];
    return href === '/' ? path === '/' : path === href || path.startsWith(`${href}/`);
}

function todayInStudio(timezone: string) {
    return new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date());
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

export default function AppShell({ title, children }: Props) {
    useThemeSync();
    const { props, url } = usePage<SharedProps>();
    const t = useT();
    const locale = useLocale();
    const [menuOpen, setMenuOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);

    useEffect(() => setCollapsed(readCollapsed()), []);
    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);

    const toggleCollapsed = () => {
        setCollapsed((prev) => {
            const next = !prev;
            writeCollapsed(next);
            return next;
        });
    };

    const nav = props.nav;
    const flat = nav.flatMap((g) => g.items);
    const bottomItems = [...flat].sort((a, b) => bottomRank(a.key) - bottomRank(b.key)).slice(0, 4);
    const menuOnlyItems = flat.filter((item) => !bottomItems.includes(item));

    // md+: lock shell to the viewport so the sidebar never grows past the screen.
    // Only the main column scrolls. Phone keeps document scroll + bottom bar.
    const shellCols = collapsed
        ? 'md:grid-cols-[72px_minmax(0,1fr)]'
        : 'md:grid-cols-[240px_minmax(0,1fr)] xl:grid-cols-[260px_minmax(0,1fr)]';

    return (
        <>
            <Head title={title} />
            <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-sm focus:bg-surface focus:px-3 focus:py-2">
                {locale === 'id' ? 'Lompat ke isi' : 'Skip to content'}
            </a>

            <div className={`min-h-dvh md:h-dvh md:overflow-hidden md:grid ${shellCols}`}>
                <aside
                    className={`hidden border-r border-line bg-surface md:flex md:h-full md:min-h-0 md:flex-col md:overflow-hidden ${
                        collapsed ? 'md:w-[72px]' : ''
                    }`}
                >
                    <div className={`flex flex-none items-center gap-1 ${collapsed ? 'justify-center px-2 pt-3 pb-2' : 'px-3 pt-3 pb-2 sm:px-3.5'}`}>
                        <Brand collapsed={collapsed} />
                    </div>

                    <div className={`min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain ${collapsed ? 'px-2' : 'px-2.5 sm:px-3.5'}`}>
                        <NavList groups={nav} url={url} compact collapsed={collapsed} />
                    </div>

                    <div className={`flex-none border-t border-line ${collapsed ? 'px-2 py-2' : 'px-2.5 py-2 sm:px-3.5'}`}>
                        <button
                            type="button"
                            onClick={toggleCollapsed}
                            className={`nav-item nav-item--compact w-full ${collapsed ? 'justify-center px-0' : ''}`}
                            aria-pressed={collapsed}
                            aria-label={collapsed ? t('common.shell.sidebar_expand') : t('common.shell.sidebar_collapse')}
                            title={collapsed ? t('common.shell.sidebar_expand') : t('common.shell.sidebar_collapse')}
                        >
                            {collapsed ? <CaretDoubleRight weight="bold" size={18} aria-hidden /> : <CaretDoubleLeft weight="bold" size={18} aria-hidden />}
                            {!collapsed && <span className="min-w-0 truncate">{t('common.shell.sidebar_collapse')}</span>}
                        </button>
                        {!collapsed && <SidebarFooter />}
                    </div>
                </aside>

                <div className="flex min-h-dvh min-w-0 flex-col md:h-full md:min-h-0 md:overflow-y-auto">
                    <TopBar />
                    {props.auth?.imposter && <ImposterBanner name={props.auth.imposter.actor_name} actingAs={props.auth.user.name} />}
                    <main id="main" className="flex-1 px-4 pt-5 pb-6 sm:px-6 sm:pt-6 md:px-8">
                        {props.flash.status && (
                            <Notice tone="success" className="mb-5">
                                {props.flash.status}
                            </Notice>
                        )}
                        {children}
                    </main>
                    <AppFooter />
                </div>
            </div>

            {flat.length > 0 && (
                <nav aria-label={t('common.nav.main')} className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] md:hidden">
                    <ul className="grid grid-cols-5">
                        {bottomItems.map((item) => {
                            const Icon = navIcons[item.key];
                            const current = isCurrent(url, item.href);
                            return (
                                <li key={item.key}>
                                    <Link
                                        href={item.href}
                                        aria-current={current ? 'page' : undefined}
                                        className={`flex min-h-[56px] flex-col items-center justify-center gap-0.5 px-0.5 text-center text-[11px] font-semibold sm:text-xs ${current ? 'text-ink' : 'text-muted'}`}
                                    >
                                        <span className="relative">
                                            {Icon && <Icon weight={current ? 'fill' : 'bold'} size={22} aria-hidden />}
                                            <NavBadge itemKey={item.key} className="absolute -top-2 left-3.5" />
                                        </span>
                                        <span className="max-w-full truncate px-0.5 leading-tight">{t(`common.nav.${item.key}`)}</span>
                                    </Link>
                                </li>
                            );
                        })}
                        <li className="col-start-5">
                            <button
                                type="button"
                                onClick={() => setMenuOpen(true)}
                                aria-haspopup="dialog"
                                className="flex min-h-[56px] w-full flex-col items-center justify-center gap-0.5 text-[11px] font-semibold text-muted sm:text-xs"
                            >
                                <span className="relative">
                                    <DotsNine weight="bold" size={22} aria-hidden />
                                    {menuOnlyItems.map((item) => (
                                        <NavBadge key={item.key} itemKey={item.key} className="absolute -top-2 left-3.5" />
                                    ))}
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

function Brand({ collapsed }: { collapsed?: boolean }) {
    if (collapsed) {
        return (
            <Link href={route('my-day')} className="inline-flex rounded-sm p-1" aria-label="Owlorix HR">
                <OwlEyes state="open" size={32} />
            </Link>
        );
    }

    return (
        <Link href={route('my-day')} className="flex min-w-0 items-center gap-2.5 rounded-sm px-2 py-1">
            <OwlEyes state="open" size={36} />
            <span className="truncate font-display text-[18px] font-[750] tracking-[-0.01em] text-heading xl:text-[19px]">Owlorix HR</span>
        </Link>
    );
}

function SidebarFooter() {
    const t = useT();
    const year = new Date().getFullYear();

    return (
        <div className="mt-2 px-2.5 pb-1">
            <p className="m-0 truncate text-[12px] font-semibold text-ink">{t('common.shell.footer_product')}</p>
            <p className="m-0 mt-0.5 truncate text-[11px] text-muted">{t('common.shell.footer_copy', { year })}</p>
        </div>
    );
}

function AppFooter() {
    const t = useT();
    const year = new Date().getFullYear();

    return (
        <footer className="mt-auto border-t border-line px-4 pt-4 pb-[calc(1rem+56px+env(safe-area-inset-bottom))] sm:px-6 md:px-8 md:py-5">
            <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                <p className="m-0 text-sm font-semibold text-ink">
                    {t('common.shell.footer_product')}
                    <span className="font-normal text-muted"> · {t('common.shell.footer_studio')} ~ created by <a href="https://sukasap.com" target="_blank" rel="noopener noreferrer">Sukasapi</a></span>
                </p>
                <p className="m-0 text-[13px] text-muted">{t('common.shell.footer_copy', { year })}</p>
            </div>
        </footer>
    );
}

function ImposterBanner({ name, actingAs }: { name: string; actingAs: string }) {
    const t = useT();
    const [working, setWorking] = useState(false);

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line bg-gold px-4 py-2.5 text-[#1A1A2E] sm:px-8" role="status">
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

function NavBadge({ itemKey, className = '' }: { itemKey: string; className?: string }) {
    const count = usePage<SharedProps>().props.nav_badges?.[itemKey] ?? 0;
    const t = useT();

    if (count <= 0) return null;

    return (
        <span className={`inline-flex ${className}`}>
            <span aria-hidden className="num inline-flex h-[20px] min-w-[20px] items-center justify-center rounded-full bg-gold px-1.5 text-xs leading-none font-bold text-[#1A1A2E]">
                {count > 99 ? '99+' : count}
            </span>
            <span className="sr-only">{t(`${itemKey}.nav_badge`, { count })}</span>
        </span>
    );
}

function NavList({ groups, url, compact = false, collapsed = false }: { groups: NavGroup[]; url: string; compact?: boolean; collapsed?: boolean }) {
    const t = useT();

    return (
        <nav aria-label={t('common.nav.main')} className={`flex flex-col ${compact ? 'gap-0.5' : 'gap-1'}`}>
            {groups.map((group, index) => (
                <div key={group.group} className={`flex flex-col ${compact ? 'gap-0.5' : 'gap-1'}`}>
                    {collapsed ? (
                        index > 0 ? <div className="my-1.5 border-t border-line" role="separator" /> : null
                    ) : (
                        <p className={`m-0 px-2.5 font-semibold text-muted ${compact ? 'pt-2.5 pb-1 text-[12px]' : 'pt-3.5 pb-1.5 text-[13px]'}`}>
                            {t(`common.nav.groups.${group.group}`)}
                        </p>
                    )}
                    {group.items.map((item) => {
                        const Icon = navIcons[item.key];
                        const label = t(`common.nav.${item.key}`);
                        return (
                            <Link
                                key={item.key}
                                href={item.href}
                                className={`nav-item ${compact ? 'nav-item--compact' : ''} ${collapsed ? 'justify-center px-0' : ''}`}
                                aria-current={isCurrent(url, item.href) ? 'page' : undefined}
                                aria-label={collapsed ? label : undefined}
                                title={collapsed ? label : undefined}
                            >
                                {Icon && <Icon weight="bold" size={collapsed ? 20 : compact ? 17 : 18} aria-hidden />}
                                {!collapsed && <span className="min-w-0 truncate">{label}</span>}
                                {!collapsed && <NavBadge itemKey={item.key} className="ml-auto" />}
                                {collapsed && <NavBadge itemKey={item.key} className="absolute top-0.5 right-0.5 scale-90" />}
                            </Link>
                        );
                    })}
                </div>
            ))}
        </nav>
    );
}

function TopBar() {
    const { props } = usePage<SharedProps>();
    const t = useT();
    const locale = useLocale();
    const user = props.auth?.user;
    const today = todayInStudio(props.app.timezone);

    return (
        <header className="sticky top-0 z-20 flex h-14 flex-none items-center justify-between gap-3 border-b border-line bg-paper px-4 sm:h-16 sm:px-6 md:px-8">
            <div className="flex min-w-0 items-center gap-2.5">
                <span className="md:hidden">
                    <OwlEyes state="open" size={34} />
                </span>
                <span className="truncate text-sm font-semibold sm:text-base">{formatLongDate(today, locale)}</span>
            </div>
            <div className="flex items-center gap-1.5 sm:gap-3.5">
                <LocaleSwitch />
                <ThemeToggle />
                {user && <AccountMenu />}
            </div>
        </header>
    );
}

function LocaleSwitch() {
    const t = useT();
    const locale = useLocale();

    const choose = (value: 'id' | 'en') => {
        if (value !== locale) router.patch(route('preferences.update'), { locale: value }, { preserveScroll: true });
    };

    return (
        <div className="seg" role="group" aria-label={t('common.shell.language')}>
            <button type="button" aria-pressed={locale === 'id'} onClick={() => choose('id')} lang="id">
                ID
            </button>
            <button type="button" aria-pressed={locale === 'en'} onClick={() => choose('en')} lang="en">
                EN
            </button>
        </div>
    );
}

function ThemeToggle() {
    const t = useT();
    const pref = usePage<SharedProps>().props.auth?.user.theme ?? 'system';
    const [current, setCurrent] = useState<'light' | 'dark'>('light');

    useEffect(() => setCurrent(effectiveTheme(pref)), [pref]);

    const next = current === 'dark' ? 'light' : 'dark';

    return (
        <button
            type="button"
            onClick={() => {
                setCurrent(next);
                saveTheme(next);
            }}
            aria-label={t(next === 'dark' ? 'common.shell.switch_to_dark' : 'common.shell.switch_to_light')}
            className="inline-flex min-h-[40px] items-center gap-1.5 rounded-sm px-2 text-sm font-semibold hover:bg-panel"
        >
            {current === 'dark' ? <Moon weight="bold" size={18} aria-hidden /> : <Sun weight="bold" size={18} aria-hidden />}
            <span className="hidden sm:inline">{t(current === 'dark' ? 'common.shell.theme_dark' : 'common.shell.theme_light')}</span>
        </button>
    );
}

function AccountMenu() {
    const { props } = usePage<SharedProps>();
    const t = useT();
    const user = props.auth!.user;
    const [open, setOpen] = useState(false);
    const menuId = useId();
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
        wrapper.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onClick);
        };
    }, [open]);

    return (
        <div ref={wrapper} className="relative">
            <button
                ref={trigger}
                type="button"
                className="avatar min-h-[40px] min-w-[40px] cursor-pointer"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={menuId}
                aria-label={t('common.shell.account')}
                onClick={() => setOpen((v) => !v)}
            >
                {user.initials}
            </button>
            {open && (
                <div id={menuId} role="menu" className="floating absolute top-12 right-0 z-40 w-64 rounded-md border border-line bg-surface p-2">
                    <div className="px-3 pt-2 pb-3">
                        <p className="m-0 font-semibold">{user.name}</p>
                        <p className="m-0 text-[13px] text-muted">{t('common.shell.signed_in_as', { username: user.username })}</p>
                    </div>
                    <Link href={route('password.edit')} role="menuitem" className="nav-item text-[15px]" onClick={() => setOpen(false)}>
                        <Key weight="bold" size={18} aria-hidden />
                        {t('common.shell.change_password')}
                    </Link>
                    <Link href={route('sign-out')} method="post" as="button" role="menuitem" className="nav-item w-full cursor-pointer text-left">
                        <SignOut weight="bold" size={18} aria-hidden />
                        {t('common.shell.sign_out')}
                    </Link>
                </div>
            )}
        </div>
    );
}

function MobileMenu({ groups, url, onClose }: { groups: NavGroup[]; url: string; onClose: () => void }) {
    const t = useT();
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const year = new Date().getFullYear();

    useEffect(() => {
        ref.current?.showModal();
    }, []);

    return (
        <dialog
            ref={ref}
            aria-labelledby={titleId}
            onClose={onClose}
            className="m-0 mt-auto h-auto max-h-[85dvh] w-full max-w-none overflow-y-auto rounded-t-2xl border-0 bg-surface p-4 pb-[calc(16px+env(safe-area-inset-bottom))] text-ink backdrop:bg-[rgb(16_18_31/0.55)]"
        >
            <div className="mb-2 flex items-center justify-between">
                <h2 id={titleId} className="h2">
                    {t('common.nav.menu')}
                </h2>
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm min-h-11 min-w-11" aria-label={t('common.nav.close_menu')}>
                    <X weight="bold" size={16} aria-hidden />
                </button>
            </div>
            <NavList groups={groups} url={url} />
            <div className="mt-3 border-t border-line pt-3">
                <Link href={route('password.edit')} className="nav-item">
                    <Key weight="bold" size={18} aria-hidden />
                    {t('common.shell.change_password')}
                </Link>
                <Link href={route('sign-out')} method="post" as="button" className="nav-item w-full cursor-pointer text-left">
                    <SignOut weight="bold" size={18} aria-hidden />
                    {t('common.shell.sign_out')}
                </Link>
            </div>
            <p className="m-0 mt-4 text-center text-[12px] text-muted">{t('common.shell.footer_copy', { year })}</p>
        </dialog>
    );
}
