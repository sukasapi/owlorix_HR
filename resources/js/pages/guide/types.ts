/** Text may use **bold** for names of buttons and menus, the only markup the guide renders. */
export type Block =
    | { type: 'p'; text: string }
    | { type: 'steps'; items: string[] }
    | { type: 'list'; items: string[] }
    | { type: 'table'; head: string[]; rows: string[][] }
    | { type: 'note'; title: string; text: string }
    | { type: 'flow'; flow: string }
    | { type: 'image'; src: string; alt: string; caption: string; wide?: boolean };

export interface Chapter {
    id: string;
    title: string;
    lead: string;
}

export interface Article {
    id: string;
    chapter: string;
    title: string;
    summary: string;
    /** Permissions of which the reader needs at least one; empty or missing means everyone. */
    audience?: string[];
    /** Extra words people use for this topic, so a search finds it even when the text uses other words. */
    keywords: string[];
    /** Questions this page answers, in the words people type. */
    questions: string[];
    blocks: Block[];
}

export type FlowStep =
    | { kind: 'start' | 'step' | 'end'; text: string }
    | { kind: 'decision'; text: string; branches: { label: string; steps: FlowStep[] }[] };

export interface Flow {
    id: string;
    title: string;
    article: string;
    steps: FlowStep[];
}

export interface Term {
    term: string;
    definition: string;
    article?: string;
}
