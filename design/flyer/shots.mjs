// Screenshots für den Flyer aus der lokal laufenden App (Demodaten).
//   Dev-Server:  C:\xampp\php\php.exe -S localhost:8080 -t public tools/dev-router.php
//   Aufruf:      node design/flyer/shots.mjs [motiv …]
// Zugang aus data/dev-credentials.txt (wird nur gelesen, nie ausgegeben). Divera-Abrufe werden blockiert.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

process.env.PUPPETEER_CACHE_DIR ??= 'C:/Users/Florian/tools/html2pdf/.cache';
const require = createRequire('C:/Users/Florian/tools/html2pdf/package.json');
const puppeteer = require('puppeteer');

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, '../..');
const out = path.join(here, 'shots');
fs.mkdirSync(out, { recursive: true });
const BASE = 'http://localhost:8080';

const cred = fs.readFileSync(path.join(repo, 'data/dev-credentials.txt'), 'utf8');
const login = cred.match(/^(demo\.admin)\s*\/\s*(\S+)/m);
const qrKey = (cred.match(/QR-Zugang Fahrzeug MTW:\s*(\S+)/) || [])[1];
if (!login || !qrKey) throw new Error('dev-credentials.txt: Demo-Zugang oder QR-Schlüssel nicht gefunden');

const PHONE = { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true };
const DESK = { width: 1280, height: 800, deviceScaleFactor: 2 };
const wanted = process.argv.slice(2);

async function guard(page) {
  await page.setRequestInterception(true);
  page.on('request', (r) => {
    const u = r.url();
    const local = u.startsWith(BASE) || u.startsWith('data:') || u.startsWith('blob:');
    if (!local || /divera/i.test(u) || /divera/i.test(r.postData() || '')) return r.abort();
    r.continue();
  });
}

async function prep(page, theme) {
  await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: theme }]);
  await page.evaluateOnNewDocument((t) => { try { localStorage.setItem('euedok-theme', t); } catch (e) {} }, theme);
}

async function settle(page) {
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all([...document.images].map((i) => (i.complete ? 0 : new Promise((r) => { i.onload = i.onerror = r; }))));
  });
  await page.evaluate(() => document.querySelectorAll('.msg, #banners > *, .toast').forEach((e) => e.remove()));
  await new Promise((r) => setTimeout(r, 400));
}

async function snap(page, name, opt = {}) {
  await settle(page);
  const file = path.join(out, name + '.jpg');
  await page.screenshot({ path: file, type: 'jpeg', quality: 85, fullPage: !!opt.fullPage, clip: opt.clip });
  console.log('ok', name);
}

const browser = await puppeteer.launch({ headless: 'shell' });
try {
  // angemeldeter Kontext
  const ctx = await browser.createBrowserContext();
  const p = await ctx.newPage();
  await guard(p);
  await p.setViewport(PHONE);
  await p.goto(BASE + '/login', { waitUntil: 'networkidle0' });
  await p.type('input[name=username]', login[1]);
  await p.type('input[name=password]', login[2]);
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.click('form button[type=submit], form .btn.pri')]);
  if (p.url().includes('/login')) throw new Error('Anmeldung fehlgeschlagen');

  const shots = {
    async home() {
      await prep(p, 'light'); await p.setViewport(PHONE);
      await p.goto(BASE + '/', { waitUntil: 'networkidle0' });
      await snap(p, 'home');
    },
    async form() {
      await prep(p, 'light'); await p.setViewport(PHONE);
      await p.goto(BASE + '/reports/E-2026-015', { waitUntil: 'networkidle0' });
      await p.click('#tabs [data-pane=people]');
      await snap(p, 'form-people');
    },
    async formdark() {
      await prep(p, 'dark'); await p.setViewport(PHONE);
      await p.goto(BASE + '/reports/E-2026-015', { waitUntil: 'networkidle0' });
      await p.click('#tabs [data-pane=people]');
      await p.evaluate(() => window.scrollBy(0, 470));
      await snap(p, 'form-people-dark');
    },
    async timedlg() {
      await prep(p, 'light'); await p.setViewport(PHONE);
      await p.goto(BASE + '/reports/E-2026-015', { waitUntil: 'networkidle0' });
      await p.click('#tabs [data-pane=people]');
      await p.evaluate(() => document.querySelector('.ptb').click());
      await p.waitForSelector('dialog[open], .dlg, .dialog', { timeout: 3000 }).catch(() => {});
      await snap(p, 'form-time');
    },
    async view() {
      await prep(p, 'light'); await p.setViewport(PHONE);
      await p.goto(BASE + '/reports/E-2026-004', { waitUntil: 'networkidle0' });
      await snap(p, 'view');
    },
    async list() {
      await prep(p, 'light'); await p.setViewport(DESK);
      await p.goto(BASE + '/reports', { waitUntil: 'networkidle0' });
      await snap(p, 'reports-desk');
    },
    async confirm() {
      await prep(p, 'light'); await p.setViewport(DESK);
      await p.goto(BASE + '/reports/E-2026-004/confirmations', { waitUntil: 'networkidle0' });
      await snap(p, 'confirm-desk');
    },
    async settings() {
      await prep(p, 'light'); await p.setViewport(DESK);
      await p.goto(BASE + '/settings/fields', { waitUntil: 'networkidle0' });
      await snap(p, 'settings-desk');
    },
    async pdf() {
      // Bericht-PDF über die Download-Route der App, dann Seite 1 mit dem Windows-PDF-Renderer als Bild
      await p.goto(BASE + '/', { waitUntil: 'networkidle0' });
      const b64 = await p.evaluate(async (u) => {
        const r = await fetch(u); if (!r.ok) throw new Error('HTTP ' + r.status);
        const a = new Uint8Array(await r.arrayBuffer()); let s = '';
        for (let i = 0; i < a.length; i += 0x8000) s += String.fromCharCode(...a.subarray(i, i + 0x8000));
        return btoa(s);
      }, BASE + '/reports/E-2026-004/pdf');
      const pdf = path.join(out, 'bericht.pdf');
      fs.writeFileSync(pdf, Buffer.from(b64, 'base64'));
      execFileSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(here, 'pdf2img.ps1'), pdf, out, '1700', '1']);
      // PNG -> JPEG über den Browser, damit das Flyer-PDF klein bleibt
      const png = path.join(out, 'bericht-1.png');
      const v = await browser.newPage();
      await v.setViewport({ width: 1700, height: 2404 });
      await v.setContent(`<body style="margin:0"><img src="data:image/png;base64,${fs.readFileSync(png).toString('base64')}" style="display:block;width:1700px">`);
      await v.screenshot({ path: path.join(out, 'pdf-page.jpg'), type: 'jpeg', quality: 85, fullPage: true });
      await v.close(); fs.unlinkSync(png); fs.unlinkSync(pdf);
      console.log('ok pdf-page');
    },
  };

  // QR-Schnellerfassung: eigener Kontext ohne Anmeldung (Startseite der Schnellerfassung, legt nichts an)
  shots.qr = async () => {
    const qc = await browser.createBrowserContext();
    const q = await qc.newPage();
    await guard(q); await prep(q, 'light'); await q.setViewport(PHONE);
    await q.goto(BASE + '/q/' + qrKey, { waitUntil: 'networkidle0' });
    await snap(q, 'qr-start');
    await qc.close();
  };

  for (const [name, fn] of Object.entries(shots)) {
    if (wanted.length && !wanted.includes(name)) continue;
    try { await fn(); } catch (e) { console.error('FEHLER', name, e.message); }
  }
  await ctx.close();
} finally {
  await browser.close();
}
