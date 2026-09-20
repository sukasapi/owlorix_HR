import { router } from '@inertiajs/react';
import type { AnchorHTMLAttributes, MouseEvent, ReactNode } from 'react';

type VisitOptions = NonNullable<Parameters<typeof router.visit>[1]>;

type Props = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href' | 'onClick'> & {
    href: string;
    options: VisitOptions;
    children: ReactNode;
};

/**
 * A real link that visits with full Inertia options. Inertia's <Link> does not accept `onHttpException` or
 * `onNetworkError`, so a page that shows its own error panel for a failed visit needs this instead.
 * Ctrl, Shift, Cmd and middle clicks keep the browser's own behaviour (open in a new tab).
 */
export function VisitLink({ href, options, children, ...anchor }: Props) {
    const onClick = (event: MouseEvent<HTMLAnchorElement>) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        router.visit(href, options);
    };

    return (
        <a href={href} onClick={onClick} {...anchor}>
            {children}
        </a>
    );
}
