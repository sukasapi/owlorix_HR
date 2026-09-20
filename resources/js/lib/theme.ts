import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { SharedProps, ThemePreference } from '@/types';

const media = () => window.matchMedia('(prefers-color-scheme: dark)');

export function effectiveTheme(pref: ThemePreference): 'light' | 'dark' {
    if (pref === 'system') return media().matches ? 'dark' : 'light';
    return pref;
}

export function applyTheme(pref: ThemePreference) {
    document.documentElement.dataset.themePref = pref;
    document.documentElement.dataset.theme = effectiveTheme(pref);
}

/** Keeps <html data-theme> in sync with the saved preference and, for "system", with the OS setting. */
export function useThemeSync() {
    const { auth, app } = usePage<SharedProps>().props;
    const pref = auth?.user.theme ?? 'system';

    // Inertia visits do not re-render <html>, so keep its lang in step with the chosen language.
    useEffect(() => {
        document.documentElement.lang = app.locale;
    }, [app.locale]);

    useEffect(() => {
        applyTheme(pref);
        if (pref !== 'system') return;
        const query = media();
        const onChange = () => applyTheme('system');
        query.addEventListener('change', onChange);
        return () => query.removeEventListener('change', onChange);
    }, [pref]);
}

export function saveTheme(pref: ThemePreference) {
    applyTheme(pref);
    router.patch(route('preferences.update'), { theme: pref }, { preserveScroll: true, preserveState: true });
}
