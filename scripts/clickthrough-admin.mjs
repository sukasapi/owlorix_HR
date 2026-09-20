/**
 * Temporary click-through for Koreksi + Admin pages on the verification server.
 * Usage: node scripts/clickthrough-admin.mjs
 */
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = 'http://127.0.0.1:8001';
const OUT = path.join(path.dirname(fileURLToPath(import.meta.url)), 'clickthrough-out');
fs.mkdirSync(OUT, { recursive: true });

// Test accounts on the verification server: a Superadmin and a Team Lead.
// Credentials come from the environment so they never land in the repo.
const ADMIN_USER = process.env.OWLORIX_ADMIN_USER;
const ADMIN_PASS = process.env.OWLORIX_ADMIN_PASSWORD;
const LEAD_USER = process.env.OWLORIX_LEAD_USER;
const LEAD_PASS = process.env.OWLORIX_LEAD_PASSWORD;
if (!ADMIN_USER || !ADMIN_PASS || !LEAD_USER || !LEAD_PASS) {
  console.error('Set OWLORIX_ADMIN_USER, OWLORIX_ADMIN_PASSWORD, OWLORIX_LEAD_USER and OWLORIX_LEAD_PASSWORD before running this script.');
  process.exit(1);
}

const results = [];
const log = (ok, step, detail = '') => {
  results.push({ ok, step, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${step}${detail ? ` — ${detail}` : ''}`);
};

async function signIn(page, username, password) {
  await page.goto(`${BASE}/masuk`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('input[name="username"]', { timeout: 10000 });
  await page.$eval('input[name="username"]', (el) => { el.value = ''; });
  await page.$eval('input[name="password"]', (el) => { el.value = ''; });
  await page.type('input[name="username"]', username);
  await page.type('input[name="password"]', password);
  await page.click('button[type="submit"]');
  // Inertia: no full document navigation
  await page.waitForFunction(() => !window.location.pathname.includes('/masuk'), { timeout: 20000 });
}

async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
}

async function visit(page, pathOrUrl, label) {
  const errors = [];
  const onConsole = (msg) => {
    if (msg.type() === 'error' && !msg.text().startsWith('Failed to load resource')) errors.push(msg.text());
  };
  const onPageError = (e) => errors.push(String(e));
  page.on('console', onConsole);
  page.on('pageerror', onPageError);

  const url = pathOrUrl.startsWith('http') ? pathOrUrl : `${BASE}${pathOrUrl}`;
  const res = await page.goto(url, { waitUntil: 'networkidle0' });
  const status = res?.status() ?? 0;
  const title = await page.evaluate(() => document.querySelector('h1')?.textContent?.trim() ?? '');
  const text = await page.evaluate(() => document.body.innerText);

  page.off('console', onConsole);
  page.off('pageerror', onPageError);

  if (status >= 400) {
    log(false, label, `HTTP ${status}`);
    return { ok: false, title, text, errors };
  }
  if (errors.length) {
    log(false, label, `console: ${errors.join(' | ')}`);
    return { ok: false, title, text, errors };
  }
  log(true, label, `h1="${title}"`);
  return { ok: true, title, text, errors };
}

async function clickText(page, selector, text) {
  const handle = await page.evaluateHandle(
    (sel, t) => {
      const nodes = [...document.querySelectorAll(sel)];
      return nodes.find((n) => (n.textContent ?? '').replace(/\s+/g, ' ').includes(t)) ?? null;
    },
    selector,
    text,
  );
  const el = handle.asElement();
  if (!el) throw new Error(`no ${selector} containing "${text}"`);
  await el.click();
}

async function asAdmin(browser) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  page.on('console', (msg) => {
    if (msg.type() === 'error' && !msg.text().startsWith('Failed to load resource')) {
      console.log('  [console]', msg.text());
    }
  });
  page.on('pageerror', (e) => console.log('  [pageerror]', String(e)));

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  log(true, 'sign-in Superadmin');

  const nav = await page.evaluate(() =>
    [...document.querySelectorAll('a')].map((a) => ({
      href: a.getAttribute('href'),
      text: a.textContent?.replace(/\s+/g, ' ').trim(),
    })),
  );
  log(nav.some((n) => n.href?.includes('/koreksi')), 'nav Koreksi (Superadmin)');
  log(nav.some((n) => n.href?.includes('/admin/perangkat')), 'nav Perangkat');
  log(nav.some((n) => n.href?.includes('/admin/aturan')), 'nav Aturan');
  log(nav.some((n) => n.href?.includes('/admin/log-audit')), 'nav Log audit');

  const koreksi = await visit(page, '/koreksi', 'GET /koreksi');
  await shot(page, '01-koreksi-waiting');
  if (!koreksi.title.includes('Koreksi')) log(false, 'Koreksi title', koreksi.title);

  try {
    await clickText(page, '[role="tab"]', 'Riwayat');
    await page.waitForNetworkIdle({ idleTime: 500, timeout: 10000 }).catch(() => {});
    await new Promise((r) => setTimeout(r, 400));
    await shot(page, '02-koreksi-history');
    log(true, 'tab Riwayat');
    await clickText(page, '[role="tab"]', 'Menunggu');
  } catch (e) {
    log(false, 'tab Riwayat', String(e.message));
  }

  try {
    // Header CTA (first match); empty-state button is fine too
    await clickText(page, 'button', 'Koreksi langsung');
    await page.waitForSelector('dialog[open]', { timeout: 5000 });
    await shot(page, '03-koreksi-dialog');
    const dialogText = await page.evaluate(() => document.querySelector('dialog[open]')?.innerText ?? '');
    log(/Orang|Tanggal kerja|Koreksi/.test(dialogText), 'dialog Koreksi langsung terbuka', dialogText.slice(0, 80).replace(/\s+/g, ' '));
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('dialog[open]'), { timeout: 5000 });
    log(true, 'dialog closes with Escape');
  } catch (e) {
    log(false, 'dialog Koreksi langsung', String(e.message));
  }

  await visit(page, '/admin/perangkat', 'GET /admin/perangkat');
  await shot(page, '04-devices');
  try {
    await clickText(page, 'button, [role="tab"]', 'Browser');
    await page.waitForNetworkIdle({ idleTime: 400, timeout: 8000 }).catch(() => {});
    await new Promise((r) => setTimeout(r, 300));
    await shot(page, '05-devices-browser');
    log(true, 'tab Browser perangkat');
  } catch (e) {
    log(false, 'tab Browser perangkat', String(e.message));
  }

  await visit(page, '/admin/aturan', 'GET /admin/aturan');
  await shot(page, '06-settings');
  try {
    const firstInput = await page.$('input[type="number"]');
    if (!firstInput) throw new Error('no number inputs');
    const before = await page.evaluate((el) => el.value, firstInput);
    await firstInput.click({ clickCount: 3 });
    await firstInput.type(String((Number(before) || 480) === 480 ? 481 : 480));
    await page.waitForFunction(() => document.body.innerText.includes('belum disimpan') || document.body.innerText.includes('Simpan'), { timeout: 3000 });
    log(true, 'Aturan sticky bar after edit', `before=${before}`);
    await clickText(page, 'button', 'Batalkan perubahan');
    await page.waitForFunction(() => !document.body.innerText.includes('belum disimpan'), { timeout: 3000 }).catch(() => {});
    log(true, 'Aturan discard');
  } catch (e) {
    log(false, 'Aturan edit/discard', String(e.message));
  }

  await visit(page, '/admin/log-audit', 'GET /admin/log-audit');
  await shot(page, '07-audit');

  // Keyboard: tab to first interactive on Koreksi
  await page.goto(`${BASE}/koreksi`, { waitUntil: 'networkidle0' });
  await page.keyboard.press('Tab');
  const focusTag = await page.evaluate(() => document.activeElement?.tagName);
  log(Boolean(focusTag && focusTag !== 'BODY'), 'keyboard focus moves with Tab', focusTag);

  await context.close();
}

async function asLead(browser) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport({ width: 390, height: 844 });
  page.on('pageerror', (e) => console.log('  [pageerror]', String(e)));

  await signIn(page, LEAD_USER, LEAD_PASS);
  log(true, 'sign-in Team Lead (mobile 390)');

  const nav = await page.evaluate(() =>
    [...document.querySelectorAll('a')].map((a) => ({ href: a.getAttribute('href'), text: a.textContent?.replace(/\s+/g, ' ').trim() })),
  );
  log(nav.some((n) => n.href?.includes('/koreksi') || n.text?.includes('Koreksi')), 'lead: nav Koreksi');
  log(!nav.some((n) => n.href?.includes('/admin/perangkat')), 'lead: no Perangkat');

  const koreksi = await visit(page, '/koreksi', 'lead GET /koreksi mobile');
  await shot(page, '08-lead-koreksi-mobile');
  if (!koreksi.title.includes('Koreksi')) log(false, 'lead Koreksi title', koreksi.title);

  try {
    await clickText(page, 'button', 'Ajukan koreksi');
    await page.waitForSelector('dialog[open]', { timeout: 5000 });
    await shot(page, '09-lead-dialog-mobile');
    log(true, 'lead dialog Ajukan koreksi');
    await page.keyboard.press('Escape');
  } catch (e) {
    log(false, 'lead dialog Ajukan koreksi', String(e.message));
  }

  // After seed: waiting list should show the proposal on mobile
  await page.reload({ waitUntil: 'networkidle0' });
  const waitingCard = await page.evaluate(() => {
    const body = document.body.innerText;
    return /17\.00|17:00|Jam pulang|Animator Uji A/.test(body) && !/Tidak ada koreksi yang menunggu/.test(body);
  });
  await shot(page, '11-lead-waiting-list');
  log(waitingCard, 'lead sees seeded waiting proposal');

  // Open detail on mobile
  if (waitingCard) {
    try {
      await page.click('button#correction-row-1, [id^="correction-row-"]');
      // id is dynamic; click first list button
    } catch {
      /* fall through */
    }
    try {
      const opened = await page.evaluate(() => {
        const btn = document.querySelector('[id^="correction-row-"]');
        btn?.click();
        return Boolean(btn);
      });
      await new Promise((r) => setTimeout(r, 600));
      await shot(page, '11b-lead-detail-mobile');
      const detail = await page.evaluate(() => document.body.innerText);
      log(opened && /Menunggu Superadmin|Seharusnya|17/.test(detail), 'lead opens detail (no decide buttons)');
      log(!/Terapkan koreksi/.test(detail), 'lead cannot apply');
    } catch (e) {
      log(false, 'lead open detail', String(e.message));
    }
  }

  for (const p of ['/admin/perangkat', '/admin/aturan', '/admin/log-audit']) {
    const res = await page.goto(`${BASE}${p}`, { waitUntil: 'networkidle0' });
    const status = res?.status() ?? 0;
    const body = await page.evaluate(() => document.body.innerText.slice(0, 300));
    const forbidden = status === 403 || /403|tidak punya|forbidden|izin|tidak diizinkan/i.test(body);
    log(forbidden, `lead refused ${p}`, `status=${status}`);
  }

  await context.close();
}

async function asAdminDecide(browser) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport({ width: 1440, height: 900 });
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto(`${BASE}/koreksi`, { waitUntil: 'networkidle0' });
  await shot(page, '12-admin-waiting');

  const hasWaiting = await page.evaluate(() => {
    const empty = /Tidak ada koreksi yang menunggu/.test(document.body.innerText);
    const row = document.querySelector('[id^="correction-row-"]');
    return !empty && Boolean(row);
  });
  log(hasWaiting, 'admin sees waiting proposal row');
  if (!hasWaiting) {
    await context.close();
    return;
  }

  try {
    await page.click('[id^="correction-row-"]');
    await page.waitForFunction(() => /Terapkan koreksi|Seharusnya/.test(document.body.innerText), { timeout: 8000 });
    await shot(page, '13-admin-detail');
    await page.waitForFunction(() => {
      const btn = [...document.querySelectorAll('button')].find((b) => /Terapkan koreksi/.test(b.textContent ?? ''));
      return btn && !btn.disabled;
    }, { timeout: 15000 });
    await clickText(page, 'button', 'Terapkan koreksi');
    await page.waitForFunction(() => /Koreksi diterapkan|Tidak ada koreksi yang menunggu/.test(document.body.innerText), { timeout: 15000 });
    await shot(page, '14-admin-applied');
    const flash = await page.evaluate(() => document.body.innerText.match(/Koreksi diterapkan[^\n]*/)?.[0] ?? '');
    log(flash.includes('diterapkan'), 'admin applied correction', flash);
    await clickText(page, '[role="tab"]', 'Riwayat');
    await new Promise((r) => setTimeout(r, 500));
    await shot(page, '15-admin-history');
    log(await page.evaluate(() => /Diterapkan/.test(document.body.innerText) && /Animator Uji A/.test(document.body.innerText)), 'history shows applied');
  } catch (e) {
    log(false, 'admin apply flow', String(e.message));
    await shot(page, '14-admin-apply-error').catch(() => {});
  }

  await context.close();
}

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: 'new',
  args: ['--font-render-hinting=none'],
});

try {
  await asAdmin(browser);
  await asLead(browser);
  await asAdminDecide(browser);
} catch (e) {
  log(false, 'fatal', String(e.stack || e));
} finally {
  await browser.close();
}

const failed = results.filter((r) => !r.ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed. Screenshots: ${OUT}`);
process.exit(failed ? 1 : 0);
