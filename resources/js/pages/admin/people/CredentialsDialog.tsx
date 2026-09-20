import { OwlEyes } from '@/components/owl/OwlEyes';
import { Dialog } from '@/components/ui/Dialog';
import { useT } from '@/lib/i18n';
import type { IssuedCredentials } from '@/types';
import { Check, Copy, Warning, WarningCircle } from '@phosphor-icons/react';
import { useEffect, useId, useRef, useState } from 'react';

interface Props {
    credentials: IssuedCredentials | null;
    reason: 'created' | 'reset';
    onClose: () => void;
}

/**
 * The one moment an issued password is visible (mockup w08). Brow panel and open owl eyes mark it as a key step.
 * Nothing here keeps the password after the dialog closes.
 */
export function CredentialsDialog({ credentials, reason, onClose }: Props) {
    const titleId = useId();

    return (
        <Dialog open={credentials !== null} onClose={onClose} labelledBy={titleId} closeOnBackdrop={false}>
            {credentials && <Content credentials={credentials} reason={reason} titleId={titleId} onClose={onClose} />}
        </Dialog>
    );
}

function Content({ credentials, reason, titleId, onClose }: { credentials: IssuedCredentials; reason: 'created' | 'reset'; titleId: string; onClose: () => void }) {
    const t = useT();
    const [copy, setCopy] = useState<'idle' | 'copied' | 'failed'>('idle');
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const message = t('people.credentials.message', {
        name: credentials.name,
        username: credentials.username,
        password: credentials.password,
    });

    const copyMessage = async () => {
        const ok = await writeClipboard(message);
        setCopy(ok ? 'copied' : 'failed');
        window.clearTimeout(timer.current);
        if (ok) timer.current = window.setTimeout(() => setCopy('idle'), 4000);
    };

    return (
        <div className="flex max-h-[calc(100dvh-48px)] flex-col overflow-y-auto">
            <div className="brow rounded-none px-5 pt-5 pb-5 sm:px-7 sm:pt-6">
                <div className="flex items-center gap-3">
                    <OwlEyes state="open" size={48} />
                    <span className="text-sm text-muted">
                        {t(reason === 'created' ? 'people.credentials.created_eyebrow' : 'people.credentials.reset_eyebrow')}
                    </span>
                </div>
                <h2 id={titleId} className="h1 mt-2.5 text-[24px] sm:text-[28px]">
                    {t(reason === 'created' ? 'people.credentials.created_title' : 'people.credentials.reset_title', { name: credentials.name })}
                </h2>
            </div>

            <div className="flex flex-col gap-3.5 px-5 pt-5 pb-5 sm:px-7">
                <dl className="m-0 grid gap-x-4 gap-y-2.5 rounded-md border-[1.5px] border-dashed border-line-strong bg-paper px-4 py-4 sm:grid-cols-[170px_1fr]">
                    <dt className="font-semibold text-muted">{t('people.credentials.username')}</dt>
                    <dd className="m-0 font-bold break-all select-all">{credentials.username}</dd>
                    <dt className="font-semibold text-muted sm:self-center">{t('people.credentials.password')}</dt>
                    <dd className="num m-0 text-[22px] font-bold tracking-[0.06em] break-all select-all">{credentials.password}</dd>
                </dl>

                <p className="m-0 flex items-center gap-2 text-sm font-semibold text-gold-text">
                    <Warning weight="bold" size={18} className="flex-none" aria-hidden />
                    {t('people.credentials.once')}
                </p>
                <p className="m-0 text-sm text-muted">{t('people.credentials.first_sign_in', { name: credentials.name })}</p>

                {copy === 'failed' && (
                    <p role="alert" className="m-0 flex items-start gap-2 text-sm font-medium text-danger">
                        <WarningCircle weight="bold" size={18} className="mt-px flex-none" aria-hidden />
                        {t('people.credentials.copy_failed')}
                    </p>
                )}

                <div className="mt-1 flex flex-wrap items-center gap-2.5">
                    <button type="button" className="btn btn-primary" onClick={copyMessage}>
                        {copy === 'copied' ? <Check weight="bold" size={18} aria-hidden /> : <Copy weight="bold" size={18} aria-hidden />}
                        {copy === 'copied' ? t('people.credentials.copied') : t('people.credentials.copy')}
                    </button>
                    <span className="sr-only" role="status" aria-live="polite">
                        {copy === 'copied' ? t('people.credentials.copied') : ''}
                    </span>
                    <span className="grow" />
                    <button type="button" className="btn btn-quiet" onClick={onClose}>
                        {t('people.credentials.done')}
                    </button>
                </div>
            </div>
        </div>
    );
}

/** Clipboard API first; the textarea fallback covers studio PCs that open the app over plain http on the LAN. */
async function writeClipboard(text: string): Promise<boolean> {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
    } catch {
        // Fall through to the textarea copy.
    }

    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    // Inside a modal <dialog>, only elements in the top layer can take focus, so the textarea goes into the open dialog.
    const host = document.querySelector('dialog[open]') ?? document.body;
    host.appendChild(area);
    area.select();
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch {
        ok = false;
    }
    area.remove();
    previous?.focus();
    return ok;
}
