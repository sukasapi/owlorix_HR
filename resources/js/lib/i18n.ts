import { usePage } from '@inertiajs/react';
import type { Locale, SharedProps } from '@/types';

/**
 * UI strings live in resources/js/lang/<locale>/<namespace>.ts, one file per module,
 * so modules can add strings without editing a shared file. Keys read as `namespace.path.to.key`.
 * Bahasa Indonesia is the default; a missing English string falls back to Indonesian.
 */
type Dict = { [key: string]: string | Dict };

const files = import.meta.glob<{ default: Dict }>('../lang/*/*.ts', { eager: true });

const dictionaries: Record<Locale, Dict> = { id: {}, en: {} };

for (const [path, module] of Object.entries(files)) {
    const match = path.match(/lang\/(id|en)\/([\w-]+)\.ts$/);
    if (match) {
        dictionaries[match[1] as Locale][match[2]] = module.default;
    }
}

function lookup(dict: Dict, key: string): string | undefined {
    let node: string | Dict | undefined = dict;
    for (const part of key.split('.')) {
        if (typeof node !== 'object' || node === null) return undefined;
        node = node[part];
    }
    return typeof node === 'string' ? node : undefined;
}

export type Translate = (key: string, replacements?: Record<string, string | number>) => string;

export function translator(locale: Locale): Translate {
    return (key, replacements = {}) => {
        const text = lookup(dictionaries[locale], key) ?? lookup(dictionaries.id, key) ?? key;
        return text.replace(/:(\w+)/g, (whole, name: string) => (name in replacements ? String(replacements[name]) : whole));
    };
}

export function useT(): Translate {
    const { app } = usePage<SharedProps>().props;
    return translator(app.locale);
}

export function useLocale(): Locale {
    return usePage<SharedProps>().props.app.locale;
}
