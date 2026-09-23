import type { Article, Block, Flow, FlowStep, Term } from './types';

/**
 * Search and "ask" over the guide, in the browser. No outside service: the guide is small enough to score every
 * paragraph on each keystroke. Words are matched on rough Indonesian stems and by containment ("kirim" finds
 * "mengirim"), and a short synonym list maps words people type to words the guide uses.
 */

const STOPWORDS = new Set(
    (
        'apa apakah bagaimana gimana gmn cara caranya kenapa mengapa kok kah dong sih ya yg yang dan atau di ke dari untuk utk ' +
        'dengan dgn pada saya aku gue gw kamu anda kita kami ini itu ada adalah jadi bisa boleh tidak tak ga gak nggak enggak ' +
        'belum sudah udah akan mau ingin harus perlu juga saja aja lagi kalau kalo jika bila agar supaya sebagai oleh tolong ' +
        'mohon the a an to of is how what why do does can i my me'
    ).split(' '),
);

/** Words people type, mapped to words the guide uses. */
const SYNONYMS: Record<string, string[]> = {
    password: ['sandi'],
    pass: ['sandi'],
    pw: ['sandi'],
    login: ['masuk'],
    signin: ['masuk'],
    logout: ['keluar'],
    overtime: ['lembur'],
    ot: ['lembur'],
    clockin: ['absen', 'masuk'],
    clockout: ['absen', 'pulang'],
    checkin: ['absen', 'masuk'],
    checkout: ['absen', 'pulang'],
    presensi: ['absen'],
    upload: ['unggah'],
    evidence: ['bukti'],
    logbook: ['log', 'kerja'],
    timesheet: ['log', 'kerja'],
    task: ['tugas'],
    project: ['proyek'],
    reset: ['nol', 'ulang', 'terputus'],
    nol: ['terputus', 'ulang'],
    ulang: ['terputus'],
    hilang: ['terputus'],
    gaji: ['bayar'],
    dibayar: ['bayar'],
    approve: ['setuju'],
    reject: ['tolak'],
    hp: ['web', 'ponsel'],
    handphone: ['web', 'ponsel'],
    laptop: ['web', 'pindah'],
    wifi: ['internet', 'koneksi'],
    offline: ['internet', 'koneksi'],
    avatar: ['foto'],
    resign: ['keluar'],
    libur: ['libur', 'bukan', 'hari', 'kerja'],
    weekend: ['sabtu', 'minggu', 'libur'],
    error: ['pesan', 'gagal'],
};

export function normalize(text: string): string {
    return text
        .toLowerCase()
        .replace(/\*\*/g, '')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .trim();
}

/** Rough Indonesian stem: particles, possessives, then common affixes, never below 4 letters. */
export function stem(word: string): string {
    let w = word;
    const strip = (re: RegExp) => {
        const next = w.replace(re, '');
        if (next.length >= 4) w = next;
    };
    strip(/(lah|kah|pun)$/);
    strip(/(nya|ku|mu)$/);
    strip(/(kan|an)$/);
    strip(/^(meng|meny|mem|men|me|peng|peny|pem|pen|ber|ter|di|ke|se)/);
    return w;
}

export function terms(text: string): string[] {
    return normalize(text)
        .split(' ')
        .filter((w) => w.length > 1 && !STOPWORDS.has(w))
        .map(stem);
}

export function queryTerms(query: string): string[] {
    const words = normalize(query)
        .split(' ')
        .filter((w) => w.length > 1 && !STOPWORDS.has(w));
    const out = new Set<string>();
    for (const word of words) {
        out.add(stem(word));
        for (const extra of SYNONYMS[word] ?? []) out.add(stem(extra));
    }
    return [...out];
}

function flowText(steps: FlowStep[]): string {
    return steps.map((s) => (s.kind === 'decision' ? `${s.text} ${s.branches.map((b) => `${b.label} ${flowText(b.steps)}`).join(' ')}` : s.text)).join(' ');
}

export function blockText(block: Block, flows: Flow[]): string {
    switch (block.type) {
        case 'p':
            return block.text;
        case 'note':
            return `${block.title} ${block.text}`;
        case 'steps':
        case 'list':
            return block.items.join(' ');
        case 'table':
            return [block.head.join(' '), ...block.rows.map((r) => r.join(' '))].join(' ');
        case 'image':
            return `${block.alt} ${block.caption}`;
        case 'flow': {
            const flow = flows.find((f) => f.id === block.flow);
            return flow ? `${flow.title} ${flowText(flow.steps)}` : '';
        }
    }
}

interface Passage {
    article: Article;
    blockIndex: number | null;
    words: string[];
    weight: number;
}

export interface AnswerHit {
    article: Article;
    blockIndex: number;
    score: number;
}

export interface ArticleHit {
    article: Article;
    score: number;
}

export interface TermHit {
    term: Term;
    score: number;
}

export interface SearchResult {
    stems: string[];
    answer: AnswerHit | null;
    articles: ArticleHit[];
    terms: TermHit[];
}

/** 1 for the same stem, 0.7 when one contains the other (at least 4 letters), else 0. */
function matchStrength(q: string, word: string): number {
    if (q === word) return 1;
    const [short, long] = q.length <= word.length ? [q, word] : [word, q];
    return short.length >= 4 && long.includes(short) ? 0.7 : 0;
}

function passageMatch(q: string, words: string[]): number {
    let best = 0;
    for (const w of words) {
        const m = matchStrength(q, w);
        if (m > best) best = m;
        if (best === 1) break;
    }
    return best;
}

export class GuideIndex {
    private passages: Passage[] = [];
    private idf = new Map<string, number>();
    private readonly articles: Article[];
    private readonly glossary: Term[];

    constructor(articles: Article[], flows: Flow[], glossary: Term[]) {
        this.articles = articles;
        this.glossary = glossary;

        for (const article of articles) {
            this.passages.push({ article, blockIndex: null, weight: 1.6, words: terms([article.title, article.title, article.summary, ...article.keywords, ...article.questions].join(' ')) });
            article.blocks.forEach((block, i) => {
                if (block.type === 'image') return;
                this.passages.push({ article, blockIndex: i, weight: 1, words: terms(blockText(block, flows)) });
            });
        }
    }

    private idfFor(q: string): number {
        const cached = this.idf.get(q);
        if (cached !== undefined) return cached;
        const n = this.passages.length;
        const df = this.passages.filter((p) => passageMatch(q, p.words) > 0).length;
        const value = Math.log(1 + (n - df + 0.5) / (df + 0.5));
        this.idf.set(q, value);
        return value;
    }

    private score(stems: string[], words: string[]): { score: number; coverage: number } {
        let score = 0;
        let matched = 0;
        for (const q of stems) {
            const m = passageMatch(q, words);
            if (m > 0) {
                matched++;
                score += m * this.idfFor(q);
            }
        }
        const coverage = stems.length === 0 ? 0 : matched / stems.length;
        // Short passages that match fully beat long ones that mention a word once
        return { score: (score * coverage * coverage) / Math.log(4 + words.length / 12), coverage };
    }

    search(query: string): SearchResult {
        const stems = queryTerms(query);
        if (stems.length === 0) return { stems, answer: null, articles: [], terms: [] };

        const meta = new Map<string, number>();
        const blocks: { article: Article; blockIndex: number; score: number; coverage: number }[] = [];

        for (const p of this.passages) {
            const { score, coverage } = this.score(stems, p.words);
            if (score <= 0) continue;
            if (p.blockIndex === null) meta.set(p.article.id, score * p.weight);
            else blocks.push({ article: p.article, blockIndex: p.blockIndex, score, coverage });
        }

        const bestBlock = new Map<string, number>();
        for (const b of blocks) bestBlock.set(b.article.id, Math.max(bestBlock.get(b.article.id) ?? 0, b.score));

        const articles = this.articles
            .map((article) => ({ article, score: (meta.get(article.id) ?? 0) + (bestBlock.get(article.id) ?? 0) }))
            .filter((h) => h.score > 0)
            .sort((a, b) => b.score - a.score)
            .slice(0, 8);

        // The answer is the paragraph that matches best, lifted by how well its page matches the question as a whole
        const ranked = blocks
            .map((b) => ({ ...b, total: b.score + 0.6 * (meta.get(b.article.id) ?? 0) }))
            .sort((a, b) => b.total - a.total);
        const top = ranked[0];
        const answer = top && top.total > 0.35 ? { article: top.article, blockIndex: top.blockIndex, score: top.total } : null;

        const glossary = this.glossary
            .map((term) => {
                const head = this.score(stems, terms(term.term));
                const body = this.score(stems, terms(term.definition));
                // A term shows only when it covers at least half the question, so one shared word is not enough
                const covers = Math.max(head.coverage, body.coverage) >= 0.5;
                return { term, score: covers ? head.score * 2 + body.score : 0 };
            })
            .filter((h) => h.score > 0.3)
            .sort((a, b) => b.score - a.score)
            .slice(0, 4);

        return { stems, answer, articles, terms: glossary };
    }
}

/** Splits text into parts, marking words that match one of the query stems, for highlighting. */
export function highlight(text: string, stems: string[]): { text: string; hit: boolean }[] {
    if (stems.length === 0) return [{ text, hit: false }];
    return text
        .split(/(\s+)/)
        .map((part) => {
            const clean = normalize(part);
            const hit = clean.length > 1 && !STOPWORDS.has(clean) && stems.some((q) => matchStrength(q, stem(clean)) > 0);
            return { text: part, hit };
        });
}
