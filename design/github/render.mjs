// Rendert social-preview.html als PNG (1280×640) für GitHub → Settings → Social preview
// Aufruf: node design/github/render.mjs
import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

process.env.PUPPETEER_CACHE_DIR ??= 'C:/Users/Florian/tools/html2pdf/.cache';
const puppeteer = createRequire('C:/Users/Florian/tools/html2pdf/package.json')('puppeteer');
const here = path.dirname(fileURLToPath(import.meta.url));

const browser = await puppeteer.launch({ headless: 'shell' });
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 640, deviceScaleFactor: 1 });
await page.goto(pathToFileURL(path.join(here, 'social-preview.html')).href, { waitUntil: 'networkidle0' });
await page.screenshot({ path: path.join(here, 'social-preview.png') });
await browser.close();
console.log('social-preview.png');
