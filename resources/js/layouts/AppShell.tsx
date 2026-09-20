import { OwlEyes } from '@/components/owl/OwlEyes';
import { Notice } from '@/components/ui/Notice';
import { formatLongDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import { effectiveTheme, saveTheme, useThemeSync } from '@/lib/theme';
import type { NavGroup, SharedProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { DotsNine, Key, Moon, SignOut, Sun, X } from '@phosphor-icons/react';
import { type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { navIcons } from './navIcons';

interface Props {
    title: string;
    children: ReactNode;
}

const BOTTOM_BAR_ORDER = ['my_day', 'approvals', 'team_today', 'history', 'overtime', 'reports', 'calendar', 'people', 'teams'];

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

export default function AppShell({ title, children }: Props) {
    useThemeSync();
    const { props, url } = usePage<SharedProps>();
    const t = useT();
    const locale = useLocale();
    const [menuOpen, setMenuOpen] = useState(false);

    // Close the mobile menu after navigating.
    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);

    const nav = props.nav;
    const flat = nav.flatMap((g) => g.items);
    // Phone bottom bar: the 4 items people open most for their role (DESIGN.md App shell, mockup m01); the rest sit in Menu.
    const bottomItems = [...flat].sort((a, b) => bottomRank(a.key) - bottomRank(b.key)).slice(0, 4);
    const menuOnlyItems = flat.filter((item) => !bottomItems.includes(item));

    return (
        <>
            <Head title={title} />
            <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-sm focus:bg-surface focus:px-3 focus:py-2">
                {locale === 'id' ? 'Lompat ke isi' : 'Skip to content'}
            </a>
            <div className="min-h-dvh lg:grid lg:grid-cols-[248px_1fr]">
                <aside className="hidden border-r border-line bg-surface px-3.5 py-[18px] lg:sticky lg:top-0 lg:flex lg:h-dvh lg:flex-col lg:gap-1">
                    <Brand />
                    <NavList groups={nav} url={url} />
                </aside>

                <div className="flex min-w-0 flex-col">
                    <TopBar />
                    <main id="main" className="flex-1 px-4 pt-6 pb-28 sm:px-8 lg:pb-10">
                        {props.flash.status && (
                            <Notice tone="success" className="mb-5">
                                {props.flash.status}
                            </Notice>
                        )}
                        {children}
                    </main>
                </div>
            </div>

            {flat.length > 0 && (
                <nav aria-label={t('common.nav.main')} className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden">
                    <ul className="grid grid-cols-5">
                        {bottomItems.map((item) => {
                            const Icon = navIcons[item.key];
                            const current = isCurrent(url, item.href);
                            return (
                                <li key={item.key}>
                                    <Link
                                        href={item.href}
                                        aria-current={current ? 'page' : undefined}
                                        className={`flex min-h-[56px] flex-col items-center justify-center gap-0.5 px-1 text-center text-xs font-semibold ${current ? 'text-ink' : 'text-muted'}`}
                                    >
                                        <span className="relative">
                                            {Icon && <Icon weight={current ? 'fill' : 'bold'} size={22} aria-hidden />}
                                            <NavBadge itemKey={item.key} className="absolute -top-2 left-3.5" />
                                        </span>
                                        <span className="leading-tight">{t(`common.nav.${item.key}`)}</span>
                                    </Link>
                                </li>
                            );
                        })}
                        <li className="col-start-5">
                            <button
                                type="button"
                                onClick={() => setMenuOpen(true)}
                                aria-haspopup="dialog"
                                className="flex min-h-[56px] w-full flex-col items-center justify-center gap-0.5 text-xs font-semibold text-muted"
                            >
                                <span className="relative">
                                    <DotsNine weight="bold" size={22} aria-hidden />
                                    {/* A badged item that did not fit the bottom bar still shows its count on Menu */}
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

function Brand() {
    return (
        <Link href={route('my-day')} className="flex items-center gap-2.5 rounded-sm px-2 pt-1 pb-[18px]">
            <OwlEyes state="open" size={40} />
            <span className="font-display text-[19px] font-[750] tracking-[-0.01em] text-heading">Owlorix HR</span>
        </Link>
    );
}

/**
 * Gold count next to a nav item (DESIGN.md: gold marks the pending approval count). The visible digit is hidden
 * from screen readers; the label says what is counted, from `<item key>.nav_badge` in the module's strings.
 */
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

function NavList({ groups, url }: { groups: NavGroup[]; url: string }) {
    const t = useT();

    return (
        <nav aria-label={t('common.nav.main')} className="flex flex-col gap-1">
            {groups.map((group) => (
                <div key={group.group} className="flex flex-col gap-1">
                    <p className="m-0 px-2.5 pt-3.5 pb-1.5 text-[13px] font-semibold text-muted">{t(`common.nav.groups.${group.group}`)}</p>
                    {group.items.map((item) => {
                        const Icon = navIcons[item.key];
                        return (
                            <Link key={item.key} href={item.href} className="nav-item" aria-current={isCurrent(url, item.href) ? 'page' : undefined}>
                                {Icon && <Icon weight="bold" size={18} aria-hidden />}
                                {t(`common.nav.${item.key}`)}
                                <NavBadge itemKey={item.key} className="ml-auto" />
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
        <header className="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-line bg-paper px-4 sm:px-8">
            <div className="flex min-w-0 items-center gap-2.5">
                <span className="lg:hidden">
                    <OwlEyes state="open" size={34} />
                </span>
                <span className="truncate font-semibold">{formatLongDate(today, locale)}</span>
            </div>
            <div className="flex items-center gap-2 sm:gap-3.5">
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
                <button type="button" onClick={onClose} className="btn btn-secondary btn-sm" aria-label={t('common.nav.close_menu')}>
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
        </dialog>
    );
}
