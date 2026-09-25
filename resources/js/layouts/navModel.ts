import type { NavGroup, NavItem } from '@/types';

/** The group pinned to the bottom of the menu (Pengaturan, Panduan); it has no heading of its own. */
export const PINNED_GROUP = 'more';

export function isCurrent(url: string, href: string) {
    const path = url.split('?')[0];
    return href === '/' ? path === '/' : path === href || path.startsWith(`${href}/`);
}

/** An item is open when its own page or one of its tabs is open. */
export function itemIsCurrent(url: string, item: NavItem) {
    return item.children?.length ? item.children.some((child) => isCurrent(url, child.href)) || isCurrent(url, item.href) : isCurrent(url, item.href);
}

/** Keys whose badge counts toward an item: its tabs, or the item itself. */
export function badgeKeys(item: NavItem): string[] {
    return item.children?.length ? [...new Set(item.children.map((child) => child.key))] : [item.key];
}

export function badgeCount(item: NavItem, badges: Record<string, number>) {
    return badgeKeys(item).reduce((sum, key) => sum + (badges[key] ?? 0), 0);
}

/** The item with tabs that holds the open page, if any: its tabs (or the Pengaturan crumb) sit above the page. */
export function currentSection(groups: NavGroup[], url: string): NavItem | null {
    for (const group of groups) {
        for (const item of group.items) {
            if (item.children?.length && item.children.some((child) => isCurrent(url, child.href))) return item;
        }
    }
    return null;
}
