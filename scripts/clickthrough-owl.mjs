/**
 * Sign-in owl click-through: toggle off/on must remount without Invalid hook call.
 * Usage: node scripts/clickthrough-owl.mjs
 */
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = 'http://127.0.0.1:8001';
const OUT = path.join(path.dirname(fileURLToPath(import.meta.url)), 'clickthrough-out');
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const log = (ok, step, detail = '') => {
  results.push({ ok, step, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${step}${detail ? ` — ${detail}` : ''}`);
};

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: 'new',
  args: ['--font-render-hinting=none', '--use-angle=swiftshader'],
});

try {
  for (const [label, width, height] of [
    ['desktop', 1440, 900],
    ['mobile', 390, 844],
  ]) {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({ width, height });

    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (msg) => {
      if (msg.type() === 'error' && !msg.text().startsWith('Failed to load resource')) errors.push(msg.text());
    });

    await page.goto(`${BASE}/masuk`, { waitUntil: 'networkidle0' });
    // Clear any prior off switch
    await page.evaluate(() => localStorage.removeItem('owlorix.owl3d'));
    await page.reload({ waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 800));

    const hasToggle = await page.evaluate(() =>
      [...document.querySelectorAll('button')].some((b) => /Matikan animasi|Turn off the owl/i.test(b.textContent ?? '')),
    );
    const hasCanvas = await page.evaluate(() => Boolean(document.querySelector('canvas')));
    log(hasToggle, `${label}: turn-off control visible`);
    log(hasCanvas, `${label}: 3D canvas mounted`);
    await page.screenshot({ path: path.join(OUT, `owl-${label}-on.png`), fullPage: true });

    if (!hasToggle) {
      await context.close();
      continue;
    }

    // Turn off -> SVG fallback
    await page.evaluate(() => {
      const btn = [...document.querySelectorAll('button')].find((b) => /Matikan animasi|Turn off the owl/i.test(b.textContent ?? ''));
      btn?.click();
    });
    await new Promise((r) => setTimeout(r, 500));
    const offState = await page.evaluate(() => ({
      storage: localStorage.getItem('owlorix.owl3d'),
      canvas: Boolean(document.querySelector('canvas')),
      turnOn: [...document.querySelectorAll('button')].some((b) => /Nyalakan animasi|Turn on the owl/i.test(b.textContent ?? '')),
      svg: Boolean(document.querySelector('svg')),
    }));
    log(offState.storage === 'off' && offState.turnOn && !offState.canvas, `${label}: turned off`, JSON.stringify(offState));
    await page.screenshot({ path: path.join(OUT, `owl-${label}-off.png`), fullPage: true });

    // Turn back on — this is the former Invalid hook call path
    errors.length = 0;
    await page.evaluate(() => {
      const btn = [...document.querySelectorAll('button')].find((b) => /Nyalakan animasi|Turn on the owl/i.test(b.textContent ?? ''));
      btn?.click();
    });
    await page.waitForFunction(() => Boolean(document.querySelector('canvas')), { timeout: 10000 }).catch(() => null);
    await new Promise((r) => setTimeout(r, 1000));
    const onAgain = await page.evaluate(() => ({
      storage: localStorage.getItem('owlorix.owl3d'),
      canvas: Boolean(document.querySelector('canvas')),
      turnOff: [...document.querySelectorAll('button')].some((b) => /Matikan animasi|Turn off the owl/i.test(b.textContent ?? '')),
    }));
    const hookBug = errors.some((e) => /Invalid hook call|CanvasImpl|Cannot read/i.test(e));
    log(!hookBug && onAgain.canvas && onAgain.turnOff && onAgain.storage !== 'off', `${label}: turned on again without hook error`, JSON.stringify({ onAgain, errors }));
    await page.screenshot({ path: path.join(OUT, `owl-${label}-on-again.png`), fullPage: true });

    // Auth layout follows OS theme (useThemeSync); no in-page theme toggle to exercise here.
    log(errors.filter((e) => /Invalid hook call|CanvasImpl/i.test(e)).length === 0, `${label}: no fiber/hook errors after remount`, errors.join(' | ') || 'clean');

    await context.close();
  }
} catch (e) {
  log(false, 'fatal', String(e.stack || e));
} finally {
  await browser.close();
}

const failed = results.filter((r) => !r.ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
