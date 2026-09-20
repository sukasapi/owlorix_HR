import { CheckCircle, Info, WarningCircle } from '@phosphor-icons/react';
import type { ReactNode } from 'react';

interface Props {
    tone?: 'info' | 'success' | 'danger';
    children: ReactNode;
    className?: string;
}

/** Inline message with icon plus text, so meaning never depends on color alone. */
export function Notice({ tone = 'info', children, className = '' }: Props) {
    const Icon = tone === 'success' ? CheckCircle : tone === 'danger' ? WarningCircle : Info;
    const color = tone === 'success' ? 'text-success' : tone === 'danger' ? 'text-danger' : 'text-ink';

    return (
        <div role={tone === 'danger' ? 'alert' : 'status'} className={`flex items-start gap-2 rounded-md bg-panel px-4 py-3 text-sm font-medium ${color} ${className}`}>
            <Icon weight="bold" size={18} className="mt-px flex-none" aria-hidden />
            <div className="min-w-0">{children}</div>
        </div>
    );
}
