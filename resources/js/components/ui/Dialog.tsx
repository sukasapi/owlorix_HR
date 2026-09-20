import { type ReactNode, useEffect, useRef } from 'react';

interface Props {
    open: boolean;
    onClose: () => void;
    labelledBy: string;
    children: ReactNode;
    /** Tailwind max-width class for the panel. */
    width?: string;
    /** Close on a click outside the panel. Turn off for forms and one-time information, so a stray click loses nothing. */
    closeOnBackdrop?: boolean;
}

/**
 * Native <dialog>: focus is trapped by the browser, Escape closes it, and focus returns to the opener.
 */
export function Dialog({ open, onClose, labelledBy, children, width = 'max-w-[560px]', closeOnBackdrop = true }: Props) {
    const ref = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const dialog = ref.current;
        if (!dialog) return;
        if (open && !dialog.open) dialog.showModal();
        if (!open && dialog.open) dialog.close();
    }, [open]);

    return (
        <dialog
            ref={ref}
            aria-labelledby={labelledBy}
            onClose={onClose}
            onClick={(event) => {
                if (closeOnBackdrop && event.target === ref.current) onClose();
            }}
            className={`floating m-auto max-h-[calc(100dvh-32px)] w-[calc(100%-32px)] ${width} overflow-y-auto overscroll-contain rounded-2xl border-0 bg-surface p-0 text-ink backdrop:bg-[rgb(16_18_31/0.55)]`}
        >
            {open && children}
        </dialog>
    );
}
