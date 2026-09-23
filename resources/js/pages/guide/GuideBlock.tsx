import { Info } from '@phosphor-icons/react';
import { Fragment, type ReactNode } from 'react';
import { FlowDiagram } from './FlowDiagram';
import type { Block, Flow } from './types';

/** Renders **bold** (names of buttons and menus); everything else is plain text. */
export function Rich({ text }: { text: string }) {
    const parts = text.split(/(\*\*[^*]+\*\*)/g);

    return (
        <>
            {parts.map((part, i) =>
                part.startsWith('**') && part.endsWith('**') ? (
                    <strong key={i} className="font-semibold text-heading">
                        {part.slice(2, -2)}
                    </strong>
                ) : (
                    <Fragment key={i}>{part}</Fragment>
                ),
            )}
        </>
    );
}

export function GuideBlock({ block, flows }: { block: Block; flows: Flow[] }): ReactNode {
    switch (block.type) {
        case 'p':
            return (
                <p className="m-0 leading-relaxed">
                    <Rich text={block.text} />
                </p>
            );
        case 'steps':
            return (
                <ol className="m-0 flex list-none flex-col gap-2.5 p-0">
                    {block.items.map((item, i) => (
                        <li key={i} className="flex gap-3 leading-relaxed">
                            <span className="num mt-0.5 flex size-7 flex-none items-center justify-center rounded-full bg-panel text-sm font-bold" aria-hidden>
                                {i + 1}
                            </span>
                            <span className="min-w-0">
                                <Rich text={item} />
                            </span>
                        </li>
                    ))}
                </ol>
            );
        case 'list':
            return (
                <ul className="m-0 flex list-disc flex-col gap-1.5 pl-5 leading-relaxed">
                    {block.items.map((item, i) => (
                        <li key={i}>
                            <Rich text={item} />
                        </li>
                    ))}
                </ul>
            );
        case 'table':
            return (
                <div className="table-wrap overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                {block.head.map((h) => (
                                    <th key={h} scope="col">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {block.rows.map((row, i) => (
                                <tr key={i}>
                                    {row.map((cell, j) => (
                                        <td key={j} className="align-top">
                                            <Rich text={cell} />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            );
        case 'note':
            return (
                <aside className="flex gap-3 rounded-md bg-panel px-4 py-3">
                    <Info weight="bold" size={20} className="mt-0.5 flex-none" aria-hidden />
                    <div className="min-w-0">
                        <p className="m-0 font-semibold">{block.title}</p>
                        <p className="m-0 mt-0.5 leading-relaxed">
                            <Rich text={block.text} />
                        </p>
                    </div>
                </aside>
            );
        case 'flow': {
            const flow = flows.find((f) => f.id === block.flow);
            return flow ? <FlowDiagram flow={flow} /> : null;
        }
        case 'image':
            return (
                <figure className={`m-0 ${block.wide ? 'max-w-[640px]' : 'max-w-[320px]'}`}>
                    <img src={block.src} alt={block.alt} loading="lazy" decoding="async" className="block h-auto w-full rounded-md border border-line bg-surface" />
                    <figcaption className="mt-1.5 text-sm text-muted">{block.caption}</figcaption>
                </figure>
            );
    }
}
