import { useT } from '@/lib/i18n';
import { CircleNotch, DownloadSimple, WarningCircle } from '@phosphor-icons/react';
import { useRef, useState } from 'react';

interface Props {
    href: string;
    label: string;
}

type ExportState = { kind: 'idle' } | { kind: 'busy' } | { kind: 'done'; file: string } | { kind: 'error'; message: string };

const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

function fileNameFrom(disposition: string | null): string | null {
    const match = disposition?.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * The file is built on the server during the request (shared hosting has no queue worker), which can take a few
 * seconds. Fetching it keeps the page in place, shows that it is being prepared, and turns a failure into a message.
 */
export function ExportButton({ href, label }: Props) {
    const t = useT();
    const [state, setState] = useState<ExportState>({ kind: 'idle' });
    const running = useRef(false);

    const download = async () => {
        if (running.current) return;
        running.current = true;
        setState({ kind: 'busy' });

        try {
            const response = await fetch(href, { credentials: 'same-origin', headers: { Accept: XLSX } });
            const type = response.headers.get('Content-Type') ?? '';

            if (response.status === 403) {
                setState({ kind: 'error', message: t('reports.export.forbidden') });
            } else if (response.status === 401 || response.status === 419 || (response.ok && !type.includes('spreadsheetml'))) {
                // A signed-out session is redirected to the sign-in page, which arrives here as HTML
                setState({ kind: 'error', message: t('reports.export.session') });
            } else if (!response.ok) {
                setState({ kind: 'error', message: t('reports.export.failed', { status: response.status }) });
            } else {
                const file = fileNameFrom(response.headers.get('Content-Disposition')) ?? 'owlorix-hr-laporan.xlsx';
                const url = URL.createObjectURL(await response.blob());
                const link = document.createElement('a');
                link.href = url;
                link.download = file;
                document.body.append(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
                setState({ kind: 'done', file });
            }
        } catch {
            setState({ kind: 'error', message: t('reports.export.network') });
        } finally {
            running.current = false;
        }
    };

    const busy = state.kind === 'busy';

    return (
        <div className="flex w-full flex-col items-stretch gap-1.5 sm:w-auto sm:items-end">
            <button type="button" className="btn btn-primary" onClick={download} disabled={busy} aria-describedby="report-export-status">
                {busy ? <CircleNotch weight="bold" size={18} className="animate-spin" aria-hidden /> : <DownloadSimple weight="bold" size={18} aria-hidden />}
                {busy ? t('reports.export.preparing') : label}
            </button>
            <p id="report-export-status" role="status" className="m-0 max-w-[44ch] text-[13px] text-muted sm:text-right">
                {state.kind === 'done' ? t('reports.export.done', { file: state.file }) : state.kind === 'error' ? null : t('reports.export.help')}
            </p>
            {state.kind === 'error' && (
                <p role="alert" className="error-text m-0 flex max-w-[44ch] items-start gap-1.5 sm:text-right">
                    <WarningCircle weight="bold" size={15} className="mt-0.5 flex-none" aria-hidden />
                    {state.message}
                </p>
            )}
        </div>
    );
}
