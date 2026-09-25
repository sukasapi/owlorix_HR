import AppShell from '@/layouts/AppShell';
import { useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { BookOpenText, CaretLeft, CaretRight, MagnifyingGlass, X } from '@phosphor-icons/react';
import { useDeferredValue, useEffect, useId, useMemo, useRef, useState } from 'react';
import { articles as allArticles, chapters, exampleQuestions, flows as allFlows, terms as allTerms } from './content';
import { FlowDiagram } from './FlowDiagram';
import { GuideBlock, Rich } from './GuideBlock';
import { GuideIndex, highlight, type SearchResult } from './search';
import type { Article, Flow, Term } from './types';

type View = { tab: 'book'; page: string } | { tab: 'flows' } | { tab: 'glossary' };

/** Hash routes keep the page in the address, so a link to a guide page can be shared: #buku/<id>, #alur, #kamus. */
function readHash(first: string, ids: Set<string>): View {
    const [head, rest] = decodeURIComponent(window.location.hash.replace(/^#/, '')).split('/');
    if (head === 'alur') return { tab: 'flows' };
    if (head === 'kamus') return { tab: 'glossary' };
    return { tab: 'book', page: rest && ids.has(rest) ? rest : first };
}

function hashFor(view: View): string {
    return view.tab === 'book' ? `#buku/${view.page}` : view.tab === 'flows' ? '#alur' : '#kamus';
}

/**
 * Panduan: a book of short pages by chapter, the main flows as diagrams, and a glossary. The question box on top is
 * the focal point; typing turns the page into an answer taken from the best matching paragraph.
 */
export default function GuideIndexPage() {
    const { props } = usePage<SharedProps>();
    const t = useT();
    const permissions = props.auth?.permissions ?? [];

    const articles = useMemo(() => allArticles.filter((a) => !a.audience || a.audience.some((p) => permissions.includes(p))), [permissions]);
    const ids = useMemo(() => new Set(articles.map((a) => a.id)), [articles]);
    const flows = useMemo(() => allFlows.filter((f) => ids.has(f.article)), [ids]);
    const index = useMemo(() => new GuideIndex(articles, allFlows, allTerms), [articles]);

    const [view, setView] = useState<View>({ tab: 'book', page: articles[0]?.id ?? '' });
    const [lastPage, setLastPage] = useState(articles[0]?.id ?? '');
    const [query, setQuery] = useState('');
    const deferred = useDeferredValue(query);
    const result = useMemo(() => (deferred.trim() === '' ? null : index.search(deferred)), [deferred, index]);

    useEffect(() => {
        const sync = () => {
            const next = readHash(articles[0]?.id ?? '', ids);
            setView(next);
            if (next.tab === 'book') setLastPage(next.page);
        };
        sync();
        window.addEventListener('hashchange', sync);
        return () => window.removeEventListener('hashchange', sync);
    }, [articles, ids]);

    const go = (next: View) => {
        setQuery('');
        if (window.location.hash !== hashFor(next)) window.location.hash = hashFor(next);
        else setView(next);
    };

    const note = t('guide.language_note');

    return (
        <AppShell title={t('guide.title')}>
            <header className="max-w-[72ch]">
                <h1 className="h1">{t('guide.title')}</h1>
                <p className="m-0 mt-2 text-muted">{t('guide.lead')}</p>
                {note && <p className="m-0 mt-2 text-sm">{note}</p>}
            </header>

            <AskBox query={query} onChange={setQuery} />

            {result ? (
                <Results result={result} flows={allFlows} onOpen={(id) => go({ tab: 'book', page: id })} onAsk={setQuery} />
            ) : (
                <>
                    <Tabs view={view} lastPage={lastPage} />
                    <div className="mt-5">
                        {view.tab === 'book' && <Book articles={articles} pageId={view.page} flows={allFlows} />}
                        {view.tab === 'flows' && <Flows flows={flows} articles={articles} />}
                        {view.tab === 'glossary' && <Glossary terms={allTerms} ids={ids} articles={articles} />}
                    </div>
                </>
            )}
        </AppShell>
    );
}

function AskBox({ query, onChange }: { query: string; onChange: (value: string) => void }) {
    const t = useT();
    const inputId = useId();
    const hintId = useId();
    const input = useRef<HTMLInputElement>(null);

    return (
        <section className="brow mt-6 px-5 py-5 sm:px-7 sm:py-6" role="search">
            <label htmlFor={inputId} className="h2 block text-[22px] sm:text-[26px]">
                {t('guide.ask.label')}
            </label>
            <div className="relative mt-3">
                <MagnifyingGlass weight="bold" size={20} className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-muted" aria-hidden />
                <input
                    ref={input}
                    id={inputId}
                    type="search"
                    className="input min-h-[52px] w-full pr-12 pl-11 text-[17px] [&::-webkit-search-cancel-button]:appearance-none"
                    placeholder={t('guide.ask.placeholder')}
                    value={query}
                    onChange={(e) => onChange(e.target.value)}
                    aria-describedby={hintId}
                    autoComplete="off"
                    enterKeyHint="search"
                />
                {query !== '' && (
                    <button
                        type="button"
                        className="absolute top-1/2 right-1.5 flex min-h-11 min-w-11 -translate-y-1/2 items-center justify-center rounded-sm text-muted hover:text-ink"
                        onClick={() => {
                            onChange('');
                            input.current?.focus();
                        }}
                        aria-label={t('guide.ask.clear')}
                    >
                        <X weight="bold" size={18} aria-hidden />
                    </button>
                )}
            </div>
            <p id={hintId} className="m-0 mt-2 text-sm">
                {t('guide.ask.hint')}
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <span className="text-sm font-semibold">{t('guide.ask.examples')}</span>
                {exampleQuestions.map((q) => (
                    <button key={q} type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => onChange(q)}>
                        {q}
                    </button>
                ))}
            </div>
        </section>
    );
}

function Mark({ text, stems }: { text: string; stems: string[] }) {
    return (
        <>
            {highlight(text.replace(/\*\*/g, ''), stems).map((part, i) =>
                part.hit ? (
                    <mark key={i} className="rounded-[3px] bg-gold-tint px-0.5 text-ink">
                        {part.text}
                    </mark>
                ) : (
                    <span key={i}>{part.text}</span>
                ),
            )}
        </>
    );
}

function Results({ result, flows, onOpen, onAsk }: { result: SearchResult; flows: Flow[]; onOpen: (id: string) => void; onAsk: (q: string) => void }) {
    const t = useT();
    const brand = usePage<SharedProps>().props.app.brand;
    const { answer, stems } = result;
    const block = answer ? answer.article.blocks[answer.blockIndex] : null;

    return (
        <div className="mt-6 flex flex-col gap-7" aria-live="polite">
            {answer && block ? (
                <section className="card px-5 py-5 sm:px-7" aria-labelledby="guide-answer">
                    <h2 id="guide-answer" className="m-0 text-sm font-semibold text-muted">
                        {t('guide.answer.heading')}
                    </h2>
                    <p className="h2 m-0 mt-1 text-[22px]">{answer.article.title}</p>
                    <div className="mt-4 max-w-[72ch]">
                        <GuideBlock block={block} flows={flows} />
                    </div>
                    <button type="button" className="btn btn-secondary mt-5" onClick={() => onOpen(answer.article.id)}>
                        <BookOpenText weight="bold" size={18} aria-hidden />
                        {t('guide.answer.open')}
                    </button>
                </section>
            ) : (
                <section className="card px-5 py-5 sm:px-7">
                    <h2 className="h2 text-[22px]">{t('guide.answer.none_title')}</h2>
                    <p className="m-0 mt-2">{t('guide.answer.none_body')}</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        {exampleQuestions.map((q) => (
                            <button key={q} type="button" className="btn btn-secondary btn-sm min-h-11" onClick={() => onAsk(q)}>
                                {q}
                            </button>
                        ))}
                    </div>
                    <p className="m-0 mt-4 text-sm">
                        {brand.contact_email ? t('guide.answer.contact_email', { email: brand.contact_email }) : t('guide.answer.contact')}
                    </p>
                </section>
            )}

            {result.articles.length > 0 && (
                <section aria-labelledby="guide-pages">
                    <h2 id="guide-pages" className="h2 text-[20px]">
                        {t('guide.answer.pages')}
                    </h2>
                    <p className="m-0 text-sm text-muted">{t('guide.answer.count', { count: result.articles.length })}</p>
                    <ul className="card m-0 mt-3 list-none divide-y divide-line p-0">
                        {result.articles.map(({ article }) => (
                            <li key={article.id}>
                                <button type="button" className="flex w-full cursor-pointer flex-col gap-1 px-4 py-3.5 text-left hover:bg-[var(--selected)]" onClick={() => onOpen(article.id)}>
                                    <span className="text-sm text-muted">{chapters.find((c) => c.id === article.chapter)?.title}</span>
                                    <span className="font-semibold text-heading">
                                        <Mark text={article.title} stems={stems} />
                                    </span>
                                    <span className="text-sm">
                                        <Mark text={article.summary} stems={stems} />
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {result.terms.length > 0 && (
                <section aria-labelledby="guide-terms">
                    <h2 id="guide-terms" className="h2 text-[20px]">
                        {t('guide.answer.terms')}
                    </h2>
                    <dl className="card m-0 mt-3 divide-y divide-line p-0">
                        {result.terms.map(({ term }) => (
                            <div key={term.term} className="px-4 py-3">
                                <dt className="font-semibold">{term.term}</dt>
                                <dd className="m-0 mt-0.5 text-sm">
                                    <Mark text={term.definition} stems={stems} />
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>
            )}
        </div>
    );
}

function Tabs({ view, lastPage }: { view: View; lastPage: string }) {
    const t = useT();
    const items: { key: View['tab']; href: string; label: string }[] = [
        { key: 'book', href: `#buku/${lastPage}`, label: t('guide.tabs.book') },
        { key: 'flows', href: '#alur', label: t('guide.tabs.flows') },
        { key: 'glossary', href: '#kamus', label: t('guide.tabs.glossary') },
    ];

    return (
        <nav className="mt-7 border-b border-line" aria-label={t('guide.tabs.label')}>
            <ul className="m-0 flex list-none gap-1 p-0">
                {items.map((item) => {
                    const current = view.tab === item.key;
                    return (
                        <li key={item.key}>
                            <a
                                href={item.href}
                                aria-current={current ? 'page' : undefined}
                                className={`-mb-px inline-flex min-h-11 items-center border-b-[3px] px-3 font-semibold sm:px-4 ${
                                    current ? 'border-[var(--eye-brow)] text-heading' : 'border-transparent text-muted hover:text-ink'
                                }`}
                            >
                                {item.label}
                            </a>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

function Contents({ articles, current }: { articles: Article[]; current: string }) {
    return (
        <ol className="m-0 flex list-none flex-col gap-4 p-0">
            {chapters.map((chapter, ci) => {
                const pages = articles.filter((a) => a.chapter === chapter.id);
                if (pages.length === 0) return null;
                return (
                    <li key={chapter.id}>
                        <p className="m-0 text-sm font-semibold text-muted">
                            {ci + 1}. {chapter.title}
                        </p>
                        <ul className="m-0 mt-1 flex list-none flex-col p-0">
                            {pages.map((page) => (
                                <li key={page.id}>
                                    <a
                                        href={`#buku/${page.id}`}
                                        aria-current={page.id === current ? 'page' : undefined}
                                        className={`block rounded-sm px-2.5 py-1.5 text-[15px] leading-snug ${
                                            page.id === current ? 'bg-[var(--selected)] font-semibold text-heading' : 'hover:bg-[var(--selected)]'
                                        }`}
                                    >
                                        {page.title}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </li>
                );
            })}
        </ol>
    );
}

/** One page at a time, like a sheet of a book, with the chapter and page number and a way to turn the page. */
function Book({ articles, pageId, flows }: { articles: Article[]; pageId: string; flows: Flow[] }) {
    const t = useT();
    const heading = useRef<HTMLHeadingElement>(null);
    const firstRender = useRef(true);
    const position = Math.max(0, articles.findIndex((a) => a.id === pageId));
    const page = articles[position];
    const previous = articles[position - 1];
    const next = articles[position + 1];
    const chapterIndex = chapters.findIndex((c) => c.id === page?.chapter);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        heading.current?.focus({ preventScroll: true });
        heading.current?.scrollIntoView({ block: 'start', behavior: 'instant' as ScrollBehavior });
    }, [pageId]);

    if (!page) return null;

    return (
        <div className="grid gap-6 lg:grid-cols-[270px_minmax(0,1fr)] lg:items-start">
            <details className="card px-4 py-3 lg:hidden">
                <summary className="min-h-11 cursor-pointer content-center font-semibold">{t('guide.book.contents')}</summary>
                <div className="mt-2">
                    <Contents articles={articles} current={page.id} />
                </div>
            </details>
            <nav className="hidden lg:sticky lg:top-4 lg:block" aria-label={t('guide.book.contents')}>
                <Contents articles={articles} current={page.id} />
            </nav>

            <article className="card min-w-0 px-5 py-6 sm:px-9 sm:py-8" aria-labelledby="guide-page-title">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm text-muted">
                    <span>
                        {t('guide.book.chapter', { number: chapterIndex + 1 })} · {chapters[chapterIndex]?.title}
                    </span>
                    <span className="num">{t('guide.book.page', { number: position + 1, total: articles.length })}</span>
                </div>
                <h2 id="guide-page-title" ref={heading} tabIndex={-1} className="h1 mt-2 scroll-mt-24 text-[30px] break-words sm:text-[36px]">
                    {page.title}
                </h2>
                <p className="m-0 mt-2 max-w-[65ch] text-[17px] text-muted">{page.summary}</p>
                <div className="mt-6 flex max-w-[72ch] flex-col gap-5">
                    {page.blocks.map((block, i) => (
                        <GuideBlock key={i} block={block} flows={flows} />
                    ))}
                </div>

                <nav className="mt-9 grid gap-3 border-t border-line pt-5 sm:grid-cols-2" aria-label={t('guide.book.turn')}>
                    {previous ? <Turn href={`#buku/${previous.id}`} label={t('guide.book.previous')} title={previous.title} direction="previous" /> : <span />}
                    {next && <Turn href={`#buku/${next.id}`} label={t('guide.book.next')} title={next.title} direction="next" />}
                </nav>
            </article>
        </div>
    );
}

function Turn({ href, label, title, direction }: { href: string; label: string; title: string; direction: 'previous' | 'next' }) {
    const Icon = direction === 'previous' ? CaretLeft : CaretRight;

    return (
        <a
            href={href}
            className={`flex min-h-14 items-center gap-3 rounded-md border border-line px-4 py-2.5 hover:bg-[var(--selected)] ${direction === 'next' ? 'flex-row-reverse text-right sm:col-start-2' : ''}`}
        >
            <Icon weight="bold" size={18} aria-hidden className="flex-none" />
            <span className="min-w-0">
                <span className="block text-sm text-muted">{label}</span>
                <span className="block font-semibold break-words text-heading">{title}</span>
            </span>
        </a>
    );
}

function Flows({ flows, articles }: { flows: Flow[]; articles: Article[] }) {
    const t = useT();

    return (
        <div className="flex flex-col gap-8">
            <p className="m-0 max-w-[65ch] text-muted">{t('guide.flow.lead')}</p>
            {flows.map((flow) => {
                const article = articles.find((a) => a.id === flow.article);
                return (
                    <section key={flow.id} aria-labelledby={`flow-${flow.id}`} className="flex flex-col gap-3">
                        <h2 id={`flow-${flow.id}`} className="h2 text-[22px]">
                            {flow.title}
                        </h2>
                        <FlowDiagram flow={flow} caption={false} />
                        {article && (
                            <a href={`#buku/${article.id}`} className="link self-start text-sm font-semibold">
                                {t('guide.flow.read', { title: article.title })}
                            </a>
                        )}
                    </section>
                );
            })}
        </div>
    );
}

function Glossary({ terms, ids, articles }: { terms: Term[]; ids: Set<string>; articles: Article[] }) {
    const t = useT();
    const sorted = [...terms].sort((a, b) => a.term.localeCompare(b.term, 'id'));
    const letters = [...new Set(sorted.map((term) => term.term[0].toUpperCase()))];
    const byLetter = (letter: string) => sorted.filter((term) => term.term[0].toUpperCase() === letter);

    return (
        <div className="flex flex-col gap-5">
            <p className="m-0 max-w-[65ch] text-muted">{t('guide.glossary.lead')}</p>
            <nav aria-label={t('guide.glossary.letters')}>
                <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0">
                    {letters.map((letter) => (
                        <li key={letter}>
                            <button
                                type="button"
                                className="num flex min-h-11 min-w-11 items-center justify-center rounded-sm border border-line-strong font-display text-lg font-bold hover:bg-[var(--selected)]"
                                onClick={() => document.getElementById(`huruf-${letter}`)?.scrollIntoView({ block: 'start' })}
                            >
                                {letter}
                            </button>
                        </li>
                    ))}
                </ul>
            </nav>
            {letters.map((letter) => (
                <section key={letter} id={`huruf-${letter}`} aria-labelledby={`huruf-${letter}-h`} className="scroll-mt-24">
                    <h2 id={`huruf-${letter}-h`} className="display m-0 text-[34px] text-heading">
                        {letter}
                    </h2>
                    <dl className="card m-0 mt-2 divide-y divide-line p-0">
                        {byLetter(letter).map((term) => (
                            <Entry key={term.term} term={term} article={term.article && ids.has(term.article) ? articles.find((a) => a.id === term.article) : undefined} />
                        ))}
                    </dl>
                </section>
            ))}
        </div>
    );
}

function Entry({ term, article }: { term: Term; article?: Article }) {
    const t = useT();

    return (
        <div className="px-4 py-3.5 sm:grid sm:grid-cols-[220px_minmax(0,1fr)] sm:gap-4">
            <dt className="font-semibold">{term.term}</dt>
            <dd className="m-0 mt-0.5 sm:mt-0">
                <Rich text={term.definition} />
                {article && (
                    <a href={`#buku/${article.id}`} className="link mt-1 block text-sm font-semibold">
                        {t('guide.glossary.read', { title: article.title })}
                    </a>
                )}
            </dd>
        </div>
    );
}
