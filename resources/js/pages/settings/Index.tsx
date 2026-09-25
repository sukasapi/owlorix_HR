import AppShell from '@/layouts/AppShell';
import { navIcons } from '@/layouts/navIcons';
import { useT } from '@/lib/i18n';
import type { NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { CaretRight } from '@phosphor-icons/react';

interface Props {
    /** Admin pages this person can open, by section (people, work, oversight) */
    sections: Record<string, NavItem[]>;
}

const ORDER = ['people', 'work', 'oversight'];

/** Pengaturan: one door to every admin page, so the menu stays short (docs/desainUI_v2, layout A). */
export default function SettingsHub({ sections }: Props) {
    const t = useT();
    const keys = ORDER.filter((key) => sections[key]?.length);

    return (
        <AppShell title={t('settings-hub.title')}>
            <header className="mb-8">
                <h1 className="h1">{t('settings-hub.title')}</h1>
                <p className="m-0 mt-1.5 text-muted">{t('settings-hub.lead')}</p>
            </header>

            <div className="grid items-start gap-8 lg:grid-cols-2 lg:gap-x-10">
                {keys.map((key) => (
                    <section key={key} aria-labelledby={`settings-${key}`}>
                        <h2 id={`settings-${key}`} className="h2 mb-3">
                            {t(`settings-hub.sections.${key}`)}
                        </h2>
                        <ul className="card rows m-0 list-none overflow-hidden p-0">
                            {sections[key].map((item) => {
                                const Icon = navIcons[item.key];
                                return (
                                    <li key={item.key}>
                                        <Link href={item.href} className="flex min-h-[64px] items-center gap-4 px-5 py-3.5 hover:bg-[color-mix(in_srgb,var(--selected)_55%,transparent)]">
                                            {Icon && <Icon weight="bold" size={20} aria-hidden className="flex-none text-heading" />}
                                            <span className="flex min-w-0 flex-1 flex-col">
                                                <span className="font-semibold">{t(`common.nav.${item.key}`)}</span>
                                                <span className="text-sm text-muted">{t(`settings-hub.items.${item.key}`)}</span>
                                            </span>
                                            <CaretRight weight="bold" size={16} aria-hidden className="flex-none text-muted" />
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                ))}
            </div>
        </AppShell>
    );
}
