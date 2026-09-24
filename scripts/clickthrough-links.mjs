/**
 * Project document links click-through (docs/16), on the disposable database only.
 * Setup: DB_DATABASE=owlorix_hr_links_click, users lead.klik / anggota.klik / luar.klik, project 1 (empty links)
 * and project 2 (empty, anggota.klik is a member). Serve with:
 *   DB_DATABASE=owlorix_hr_links_click php -S 127.0.0.1:8011 -t public vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
 * Usage: node scripts/clickthrough-links.mjs
 */
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = 'http://127.0.0.1:8011';
const PASSWORD = 'Klik-uji-2026';
const OUT = path.join(path.dirname(fileURLToPath(import.meta.url)), 'clickthrough-out');
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const log = (ok, step, detail = '') => {
    results.push({ ok, step, detail });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${step}${detail ? `: ${detail}` : ''}`);
};
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--font-render-hinting=none'] });

async function session(label, username, { width = 1280, height = 900, dark = false } = {}) {
    const context = await browser.createBrowserContext();
    await context.overridePermissions(BASE, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']);
    const page = await context.newPage();
    await page.setViewport({ width, height });
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: dark ? 'dark' : 'light' }]);
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (msg) => {
        if (msg.type() === 'error' && !msg.text().startsWith('Failed to load resource')) errors.push(msg.text());
    });
    page.on('dialog', (d) => d.accept());
    await page.goto(`${BASE}/masuk`, { waitUntil: 'networkidle0' });
    await page.type('input[name="username"]', username);
    await page.type('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    // php -S serves one request at a time, so the sign-in can wait behind the previous page's polling
    await page.waitForFunction(() => !location.pathname.startsWith('/masuk'), { timeout: 30000 }).catch(() => {});
    let refusal = '';
    if (page.url().includes('/masuk')) {
        refusal = await page.$$eval('[role=alert], .error-text', (els) => els.map((e) => e.textContent.trim()).join(' | '));
        await page.screenshot({ path: path.join(OUT, `links-signin-${label.replace(/\W+/g, '-')}.png`), fullPage: true });
    }
    log(!page.url().includes('/masuk'), `${label}: signed in as ${username}`, refusal || page.url());
    return { context, page, errors };
}

const section = (page) => page.$('section[aria-labelledby="links-heading"]');
const rowTitles = (page) =>
    page.$$eval('section[aria-labelledby="links-heading"] ul > li a span.font-semibold', (els) => els.map((e) => e.textContent.trim()));
async function clickButton(page, scope, text) {
    const handle = await page.evaluateHandle(
        (root, wanted) => [...(root ?? document).querySelectorAll('button')].find((b) => b.textContent.trim() === wanted || b.getAttribute('aria-label') === wanted),
        scope,
        text,
    );
    const el = handle.asElement();
    if (!el) throw new Error(`button not found: ${text}`);
    await el.click();
}
const dialogOpen = (page) => page.$eval('dialog', (d) => d.open).catch(() => false);
async function fillDialog(page, { category, label, url, note, managersOnly }) {
    if (category) await page.select('dialog select', category);
    const inputs = await page.$$('dialog input.input');
    // Order in the dialog: name, address, note
    const set = async (el, value) => {
        await el.focus();
        await page.keyboard.down('Control');
        await page.keyboard.press('KeyA');
        await page.keyboard.up('Control');
        await page.keyboard.press('Backspace');
        if (value) await el.type(value);
    };
    if (label !== undefined) await set(inputs[0], label);
    if (url !== undefined) await set(inputs[1], url);
    if (note !== undefined) await set(inputs[2], note);
    if (managersOnly !== undefined) {
        const checked = await page.$eval('dialog input[type="checkbox"]', (c) => c.checked);
        if (checked !== managersOnly) await page.click('dialog input[type="checkbox"]');
    }
}
// Every change answers with a redirect back to the project page; the page polls, so the network is never idle
const redirected = (page) =>
    page.waitForResponse((r) => r.request().method() === 'GET' && r.request().redirectChain().length > 0, { timeout: 20000 });
async function act(page, click) {
    await Promise.all([redirected(page), click()]);
    await wait(300);
}
async function submitDialog(page) {
    await act(page, () => page.click('dialog button[type="submit"]'));
}
const noOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

try {
    // Manager, desktop, light
    {
        const { context, page, errors } = await session('lead desktop', 'lead.klik');
        await page.goto(`${BASE}/proyek/1`, { waitUntil: 'networkidle0' });
        const text = await page.$eval('section[aria-labelledby="links-heading"]', (s) => s.textContent);
        log(text.includes('Belum ada tautan dokumen.') && text.includes('Tempel tautan folder Drive'), 'lead: empty state names the next action');

        const sec = await section(page);
        await clickButton(page, sec, 'Tambah tautan');
        await wait(300);
        log(await dialogOpen(page), 'lead: Tambah tautan opens the dialog');
        const defaults = await page.evaluate(() => ({ category: document.querySelector('dialog select').value, focused: document.activeElement?.getAttribute('type') }));
        log(defaults.category === 'scenario' && defaults.focused === 'url', 'lead: first link defaults to Skenario, address field focused', JSON.stringify(defaults));

        await submitDialog(page);
        let err = await page.$$eval('dialog .error-text', (els) => els.map((e) => e.textContent.trim()));
        log(err.some((e) => e.includes('Tempel alamat tautannya')), 'lead: empty address shows the server message', err.join(' | '));

        await fillDialog(page, { url: 'http://drive.google.com/drive/folders/abc' });
        await submitDialog(page);
        err = await page.$$eval('dialog .error-text', (els) => els.map((e) => e.textContent.trim()));
        log(err.some((e) => e.includes('https://')), 'lead: http address refused with https hint', err.join(' | '));

        await fillDialog(page, { url: 'https://drive.google.com/drive/folders/skenario' });
        await submitDialog(page);
        const still = (await dialogOpen(page)) ? await page.evaluate(() => [...document.querySelectorAll('dialog input.input, dialog .error-text, dialog [role=alert]')].map((e) => e.value ?? e.textContent).join(' | ')) : '';
        log(!(await dialogOpen(page)), 'lead: valid link saves and closes the dialog', still);
        let titles = await rowTitles(page);
        const service = await page.$eval('section[aria-labelledby="links-heading"] ul > li a span.text-sm', (e) => e.textContent.trim());
        log(titles.join() === 'Skenario' && service === 'Folder Google Drive', 'lead: row shows Skenario and Folder Google Drive', `${titles} / ${service}`);

        await clickButton(page, await section(page), 'Tambah tautan');
        await wait(300);
        const next = await page.$eval('dialog select', (s) => s.value);
        log(next === 'storyboard', 'lead: second link defaults to Storyboard', next);
        await fillDialog(page, { url: 'https://docs.google.com/document/d/sb/edit', note: 'Versi terbaru di folder v3' });
        await submitDialog(page);

        await clickButton(page, await section(page), 'Tambah tautan');
        await wait(300);
        await fillDialog(page, { category: 'other', label: '', url: 'https://drive.google.com/drive/folders/root' });
        await submitDialog(page);
        err = await page.$$eval('dialog .error-text', (els) => els.map((e) => e.textContent.trim()));
        log(err.some((e) => e.includes('Lainnya')), 'lead: Lainnya without a name is refused', err.join(' | '));
        await fillDialog(page, { label: 'Kontrak klien', managersOnly: true });
        await submitDialog(page);
        titles = await rowTitles(page);
        log(titles.join('|') === 'Skenario|Storyboard|Kontrak klien', 'lead: three links in order', titles.join('|'));
        const chip = await page.$$eval('section[aria-labelledby="links-heading"] .chip', (els) => els.map((e) => e.textContent.trim()));
        log(chip.join() === 'Hanya pengelola', 'lead: managers-only chip on the contract link', chip.join());

        const anchor = await page.$eval('section[aria-labelledby="links-heading"] ul > li a', (a) => ({ href: a.href, target: a.target, rel: a.rel }));
        log(anchor.href === 'https://drive.google.com/drive/folders/skenario' && anchor.target === '_blank' && anchor.rel.includes('noopener'), 'lead: link opens Drive in a new tab', JSON.stringify(anchor));

        // Escape closes the dialog
        await clickButton(page, await section(page), 'Tambah tautan');
        await wait(300);
        await page.keyboard.press('Escape');
        await wait(300);
        log(!(await dialogOpen(page)), 'lead: Escape closes the dialog');

        // Copy
        await clickButton(page, await section(page), 'Salin tautan Storyboard');
        await wait(300);
        const copied = await page.evaluate(async () => ({
            clip: await navigator.clipboard.readText().catch(() => 'unreadable'),
            live: document.querySelector('section[aria-labelledby="links-heading"] [aria-live]')?.textContent,
            check: Boolean(document.querySelector('section[aria-labelledby="links-heading"] svg.text-success')),
        }));
        log(copied.clip === 'https://docs.google.com/document/d/sb/edit' && copied.live === 'Tautan Storyboard disalin.' && copied.check, 'lead: copy puts the address on the clipboard and announces it', JSON.stringify(copied));

        // Reorder
        await clickButton(page, await section(page), 'Atur urutan');
        await wait(200);
        const firstUpDisabled = await page.$eval('[data-move$="-up"]', (b) => b.disabled);
        log(firstUpDisabled, 'lead: first link cannot move up');
        await act(page, async () => clickButton(page, await section(page), 'Turunkan Skenario'));
        titles = await rowTitles(page);
        const focus = await page.evaluate(() => document.activeElement?.getAttribute('aria-label'));
        log(titles.join('|') === 'Storyboard|Skenario|Kontrak klien', 'lead: Turunkan moves Skenario down', titles.join('|'));
        log(focus === 'Turunkan Skenario', 'lead: focus stays on the moved link', String(focus));
        const reorderStill = await page.$$eval('[data-move]', (els) => els.length);
        log(reorderStill === 6, 'lead: reorder mode stays on after a move', String(reorderStill));
        await clickButton(page, await section(page), 'Selesai mengatur');
        await wait(200);
        log((await page.$$('[data-move]')).length === 0, 'lead: Selesai mengatur leaves reorder mode');

        // Edit, then delete
        await clickButton(page, await section(page), 'Ubah tautan Storyboard');
        await wait(300);
        await fillDialog(page, { label: 'Storyboard episode 1' });
        await submitDialog(page);
        titles = await rowTitles(page);
        log(titles[0] === 'Storyboard episode 1', 'lead: edit renames the link', titles.join('|'));

        await clickButton(page, await section(page), 'Ubah tautan Kontrak klien');
        await wait(300);
        await act(page, async () => clickButton(page, await page.$('dialog'), 'Hapus tautan'));
        titles = await rowTitles(page);
        log(titles.join('|') === 'Storyboard episode 1|Skenario', 'lead: delete removes the link after confirm', titles.join('|'));

        // Put the contract back for the member check
        await clickButton(page, await section(page), 'Tambah tautan');
        await wait(300);
        await fillDialog(page, { category: 'other', label: 'Kontrak klien', url: 'https://drive.google.com/file/d/kontrak/view', managersOnly: true });
        await submitDialog(page);

        // Keyboard: Tab reaches the first link with a visible outline
        await page.focus('#links-heading');
        await page.evaluate(() => document.querySelector('#links-heading').setAttribute('tabindex', '-1'));
        await page.focus('#links-heading');
        let reached = null;
        for (let i = 0; i < 6 && !reached; i++) {
            await page.keyboard.press('Tab');
            reached = await page.evaluate(() => {
                const el = document.activeElement;
                if (el?.tagName !== 'A' || !el.closest('section[aria-labelledby="links-heading"]')) return null;
                const style = getComputedStyle(el);
                return `${style.outlineStyle} ${style.outlineWidth}`;
            });
        }
        log(reached !== null && !reached.startsWith('none'), 'lead: Tab reaches the first link with a visible focus outline', String(reached));

        const lightTheme = await page.evaluate(() => document.documentElement.dataset.theme);
        log(lightTheme === 'light', 'lead desktop: light theme active', lightTheme);
        log(await noOverflow(page), 'lead desktop: no horizontal overflow');
        await page.screenshot({ path: path.join(OUT, 'links-lead-desktop.png'), fullPage: true });
        log(errors.length === 0, 'lead desktop: no console errors', errors.join(' | '));
        await context.close();
    }

    // Manager, mobile, dark
    {
        const { context, page, errors } = await session('lead mobile dark', 'lead.klik', { width: 390, height: 844, dark: true });
        await page.goto(`${BASE}/proyek/1`, { waitUntil: 'networkidle0' });
        const theme = await page.evaluate(() => document.documentElement.dataset.theme);
        log(theme === 'dark', 'lead mobile: dark theme active', theme);
        log(await noOverflow(page), 'lead mobile: no horizontal overflow');
        const sizes = await page.$$eval('section[aria-labelledby="links-heading"] ul button', (els) => els.map((b) => [Math.round(b.getBoundingClientRect().width), Math.round(b.getBoundingClientRect().height)]));
        log(sizes.length > 0 && sizes.every(([w, h]) => w >= 44 && h >= 44), 'lead mobile: row buttons are at least 44 px', JSON.stringify(sizes));
        const clipped = await page.$$eval('section[aria-labelledby="links-heading"] ul > li', (els) => els.some((li) => li.getBoundingClientRect().right > window.innerWidth));
        log(!clipped, 'lead mobile: rows stay inside the screen');
        await clickButton(page, await section(page), 'Atur urutan');
        await wait(200);
        log(await noOverflow(page), 'lead mobile: reorder mode fits');
        await page.screenshot({ path: path.join(OUT, 'links-lead-mobile-dark.png'), fullPage: true });
        await clickButton(page, await section(page), 'Selesai mengatur');
        await clickButton(page, await section(page), 'Tambah tautan');
        await wait(300);
        log(await noOverflow(page), 'lead mobile: dialog fits');
        await page.screenshot({ path: path.join(OUT, 'links-lead-mobile-dialog.png') });
        log(errors.length === 0, 'lead mobile: no console errors', errors.join(' | '));
        await context.close();
    }

    // Member: sees links but not the managers-only one, no manage buttons
    {
        const { context, page, errors } = await session('member', 'anggota.klik', { width: 390, height: 844 });
        await page.goto(`${BASE}/proyek/1`, { waitUntil: 'networkidle0' });
        const titles = await rowTitles(page);
        log(titles.join('|') === 'Storyboard episode 1|Skenario', 'member: sees links without the managers-only one', titles.join('|'));
        const labels = await page.$$eval('section[aria-labelledby="links-heading"] button', (els) => els.map((b) => b.getAttribute('aria-label') ?? b.textContent.trim()));
        log(labels.every((l) => l.startsWith('Salin tautan')), 'member: only copy buttons, no add, edit, or reorder', labels.join(' | '));
        const html = await page.content();
        log(!html.includes('kontrak'), 'member: the managers-only address is not in the page');
        await page.screenshot({ path: path.join(OUT, 'links-member-mobile.png'), fullPage: true });

        await page.goto(`${BASE}/proyek/2`, { waitUntil: 'networkidle0' });
        const empty = await page.$eval('section[aria-labelledby="links-heading"]', (s) => s.textContent);
        log(empty.includes('Team Lead atau PM akan menambahkan'), 'member: empty project says who adds links');
        log(errors.length === 0, 'member: no console errors', errors.join(' | '));
        await context.close();
    }

    // Outsider: no links, told why
    {
        const { context, page, errors } = await session('outsider', 'luar.klik');
        await page.goto(`${BASE}/proyek/1`, { waitUntil: 'networkidle0' });
        const text = await page.$eval('section[aria-labelledby="links-heading"]', (s) => s.textContent);
        const html = await page.content();
        log(text.includes('hanya terlihat oleh orang yang terlibat') && (await rowTitles(page)).length === 0, 'outsider: told who can see the links, no rows');
        log(!html.includes('drive.google.com/drive/folders/skenario'), 'outsider: no address reaches the page');
        await page.screenshot({ path: path.join(OUT, 'links-outsider.png'), fullPage: true });
        log(errors.length === 0, 'outsider: no console errors', errors.join(' | '));
        await context.close();
    }
} catch (e) {
    log(false, 'script error', String(e?.stack ?? e));
} finally {
    await browser.close();
}

const failed = results.filter((r) => !r.ok).length;
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
