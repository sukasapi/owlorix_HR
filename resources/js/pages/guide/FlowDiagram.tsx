import { useT } from '@/lib/i18n';
import { CaretDown } from '@phosphor-icons/react';
import type { Flow, FlowStep } from './types';

/**
 * A flow drawn with boxes and arrows in HTML, so it follows the theme tokens, wraps on a phone, and reads as a nested
 * list to a screen reader. Start is the filled box, questions have a thick border, and ends are the quiet boxes.
 * `caption` is off where a heading above already names the flow.
 */
export function FlowDiagram({ flow, caption = true }: { flow: Flow; caption?: boolean }) {
    return (
        <figure className="m-0 overflow-x-auto rounded-md border border-line bg-paper px-3 py-4 sm:px-5">
            <figcaption className={caption ? 'mb-3 text-sm font-semibold text-muted' : 'sr-only'}>{flow.title}</figcaption>
            <Steps steps={flow.steps} />
        </figure>
    );
}

function Steps({ steps }: { steps: FlowStep[] }) {
    return (
        <ol className="m-0 flex list-none flex-col items-stretch p-0">
            {steps.map((step, index) => (
                <li key={index} className="flex flex-col items-center">
                    {index > 0 && <Arrow />}
                    {step.kind === 'decision' ? <Decision step={step} /> : <Box kind={step.kind} text={step.text} />}
                </li>
            ))}
        </ol>
    );
}

function Arrow() {
    return (
        <span className="flex flex-col items-center text-line-strong" aria-hidden>
            <span className="h-3 w-0.5 bg-current" />
            <CaretDown weight="fill" size={14} className="-mt-1" />
        </span>
    );
}

function Box({ kind, text }: { kind: 'start' | 'step' | 'end'; text: string }) {
    const style =
        kind === 'start'
            ? 'bg-[var(--primary-bg)] text-[var(--primary-fg)] font-semibold'
            : kind === 'end'
              ? 'border-2 border-line-strong bg-surface'
              : 'bg-panel';

    return <p className={`m-0 w-full max-w-[420px] rounded-md px-3.5 py-2.5 text-center text-sm leading-snug break-words ${style}`}>{text}</p>;
}

function Decision({ step }: { step: Extract<FlowStep, { kind: 'decision' }> }) {
    const t = useT();
    const columns = step.branches.length === 3 ? 'md:grid-cols-3' : 'sm:grid-cols-2';

    return (
        <div className="flex w-full flex-col items-center">
            <p className="m-0 w-full max-w-[420px] rounded-md border-2 border-[var(--eye-brow)] bg-surface px-3.5 py-2.5 text-center text-sm font-semibold leading-snug break-words">
                {step.text}
            </p>
            <Arrow />
            <ul className={`m-0 grid w-full list-none gap-3 p-0 ${columns}`}>
                {step.branches.map((branch) => (
                    <li key={branch.label} className="flex min-w-0 flex-col items-center rounded-md border border-dashed border-line-strong px-2 pt-2 pb-3">
                        <span className="chip mb-1 text-[13px]">
                            <span className="sr-only">{t('guide.flow.if')} </span>
                            {branch.label}
                        </span>
                        <Arrow />
                        <div className="w-full">
                            <Steps steps={branch.steps} />
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
