/**
 * Player page browser regression test (Chromium via Playwright).
 *
 * Guards the two user-facing bugs that motivated the player rework:
 *   1. Clicking the video paused it for a frame and then it kept playing
 *      (a page-level click handler re-toggled what the native controls had
 *      just toggled); Space / arrow keys double-fired the same way once the
 *      <video> held focus.
 *   2. A long series stretched the page to thousands of pixels and the
 *      video scrolled away while browsing the list.
 * Plus the behaviours added alongside: per-episode quality picker, series
 * resume (last episode + position), ?t= deep links, sticky video on phones.
 *
 * It does NOT need a database or network: archive.org and the local API are
 * mocked at the network layer, and a tiny WebM fixture stands in for the
 * video. It DOES need PHP (to serve the page) and Playwright's Chromium.
 *
 *   npm i -D playwright && npx playwright install chromium   # once
 *   node tests/browser/player.test.js
 *
 * Env: BASE_URL (skip the built-in `php -S`), CHROMIUM_PATH, PHP_BIN,
 *      PLAYWRIGHT_MODULE (path to a playwright install outside node_modules).
 */
'use strict';

const fs = require('fs');
const path = require('path');
const net = require('net');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const CLIP = fs.readFileSync(path.join(__dirname, 'fixtures', 'clip-20s.webm'));
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');

let playwright;
try { playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright'); }
catch (e) { console.error('playwright not found: npm i -D playwright (or set PLAYWRIGHT_MODULE)'); process.exit(2); }

// ---------- fixtures ----------
function seriesFixture(episodes, variants) {
  const files = [];
  for (let i = 1; i <= episodes; i++) {
    const n = String(i).padStart(2, '0');
    if (variants) files.push({ name: `Show_E${n}_512kb.mp4`, format: '512Kb MPEG4', size: '20000000', length: '600' });
    files.push({ name: `Show_E${n}.mp4`, format: 'h.264', size: '80000000', length: '600' });
  }
  const paragraphs = Array.from({ length: 30 }, (_, i) => `<p>Paragraph ${i + 1}. Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>`).join('');
  return { success: true, data: { metadata: { identifier: 'show', title: 'Show', creator: 'Studio', description: paragraphs, date: '1955-01-01', subject: ['test'] }, files } };
}
const SINGLE = { success: true, data: { metadata: { identifier: 'single', title: 'Single Film', creator: 'Solo', description: 'One video.', date: '1960' }, files: [{ name: 'Single.mp4', format: 'h.264', size: '90000000', length: '3600' }] } };

async function mockNetwork(page, fixture) {
  // Last-registered route wins in Playwright: catch-alls first.
  await page.route('**/fonts.googleapis.com/**', r => r.abort());
  await page.route('**/fonts.gstatic.com/**', r => r.abort());
  await page.route('**/api/**', r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'offline (test)' }) }));
  await page.route('**/archive.org/**', r => r.fulfill({ status: 404, body: '' }));
  await page.route('**/api/metadata.php*', r => {
    const id = new URL(r.request().url()).searchParams.get('id');
    r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(id === 'single' ? SINGLE : fixture) });
  });
  await page.route('**/sw.js*', r => r.fulfill({ status: 404, body: '' }));
  await page.route('**/archive.org/services/img/**', r => r.fulfill({ status: 200, contentType: 'image/png', body: PNG }));
  await page.route('**/archive.org/download/**', r => {
    // Chromium seeks with Range requests; honour them or seeks silently reset.
    const range = r.request().headers()['range'];
    if (range) {
      const m = /bytes=(\d*)-(\d*)/.exec(range);
      const start = m && m[1] ? parseInt(m[1], 10) : 0;
      const end = m && m[2] ? Math.min(parseInt(m[2], 10), CLIP.length - 1) : CLIP.length - 1;
      return r.fulfill({ status: 206, headers: { 'Content-Type': 'video/webm', 'Accept-Ranges': 'bytes', 'Content-Range': `bytes ${start}-${end}/${CLIP.length}`, 'Content-Length': String(end - start + 1) }, body: CLIP.subarray(start, end + 1) });
    }
    return r.fulfill({ status: 200, headers: { 'Content-Type': 'video/webm', 'Accept-Ranges': 'bytes', 'Content-Length': String(CLIP.length) }, body: CLIP });
  });
}

// ---------- helpers ----------
let failures = 0;

/**
 * Attach console / error capture to a page. On a hard failure (a timeout
 * waiting for the player to render) we print this buffer plus a snapshot
 * of the player's DOM state, so a CI log explains *why* instead of just
 * "waiting for locator('.playlist-item')".
 */
function instrument(page) {
  const log = [];
  page.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') log.push(`[console.${m.type()}] ${m.text().slice(0, 300)}`); });
  page.on('pageerror', e => log.push(`[pageerror] ${e.message}`));
  page.on('requestfailed', r => { const u = r.url(); if (!/fonts\.g/.test(u)) log.push(`[requestfailed] ${u} ${r.failure() && r.failure().errorText}`); });
  // Request/response trace (same-origin only): a request with no matching
  // response line in the dump is one the dev server never answered.
  const short = u => u.replace(/^https?:\/\/[^/]+/, '');
  page.on('request', r => { if (!/^https?:\/\/127\.0\.0\.1/.test(r.url())) return; log.push(`[req] ${short(r.url())}`); });
  page.on('response', r => { if (!/^https?:\/\/127\.0\.0\.1/.test(r.url())) return; log.push(`[res ${r.status()}] ${short(r.url())}`); });
  page.__afcLog = log;
  return log;
}

async function dumpState(page, label) {
  console.log(`\n---- diagnostics: ${label} ----`);
  for (const line of (page.__afcLog || []).slice(-120)) console.log('  ' + line);
  try {
    const snap = await page.evaluate(() => {
      const q = s => document.querySelector(s);
      const disp = s => { const el = q(s); return el ? getComputedStyle(el).display : 'MISSING'; };
      return {
        url: location.href, ready: document.readyState, afcReady: !!window.__afcReady,
        bodyClass: document.body.className, title: document.title,
        video: !!q('video'), videoSrc: q('video source') ? q('video source').src : null,
        videoReady: q('video') ? q('video').readyState : null, videoError: q('video') && q('video').error ? q('video').error.code : null,
        iframe: !!q('iframe.video-player'), loader: disp('#playerLoader'), sidebar: disp('#playlistSidebar'),
        items: document.querySelectorAll('.playlist-item').length, titleText: q('#videoTitle') ? q('#videoTitle').textContent : null,
        errorBox: q('.player-error') ? q('.player-error').textContent.trim().slice(0, 200) : null,
      };
    });
    console.log('  ' + JSON.stringify(snap));
  } catch (e) { console.log('  (could not snapshot page: ' + e.message + ')'); }
  console.log('----');
}

const check = (label, ok, detail) => { if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${detail ? '  — ' + detail : ''}`); };
// Context options for every page: never let the site's service worker
// register. Playwright does not intercept the worker's own script fetch or
// fetches the worker makes, so once it installed (after the first load, on a
// slow runner) it answered api/metadata.php from the real PHP endpoint and
// the mocks silently stopped applying. Also disable Chromium's speculative
// network predictor so a reload never differs from a fresh navigation.
const PAGE_OPTS = { serviceWorkers: 'block' };

const rect = (page, sel) => page.evaluate(s => { const el = document.querySelector(s); if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), width: Math.round(r.width), height: Math.round(r.height) }; }, sel);
const scrollTo = (page, y) => page.evaluate(v => window.scrollTo({ top: v, behavior: 'instant' }), y).then(() => page.waitForTimeout(150));

async function open(page, url) {
  await page.goto(url);
  await page.evaluate(() => { try { localStorage.setItem('afc_disclaimer_ack_v1', '1'); } catch (e) {} });
  await page.reload();
  try {
    await page.waitForSelector('video', { timeout: 30000 });
  } catch (e) {
    await dumpState(page, `open ${url}: <video> never rendered`);
    throw e;
  }
}
async function waitForPlaylist(page, label) {
  try {
    await page.waitForSelector('.playlist-item', { timeout: 30000 });
  } catch (e) {
    await dumpState(page, label || 'playlist did not render');
    throw e;
  }
  await page.waitForTimeout(400);
}

async function openSeries(page, url) {
  await open(page, url);
  await waitForPlaylist(page, `openSeries ${url}`);
}

function freePort() { return new Promise(res => { const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); }); }
async function startPhp() {
  const port = await freePort();
  // PHP_CLI_SERVER_WORKERS (PHP ≥7.4): the built-in server is otherwise a
  // single process, and a browser fetching ~40 ES modules in parallel —
  // plus Chromium's speculative pre-connects — can leave requests queued
  // behind one another. A few workers keep page loads deterministic.
  const env = { ...process.env, PHP_CLI_SERVER_WORKERS: process.env.PHP_CLI_SERVER_WORKERS || '4' };
  const child = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', ROOT], { stdio: 'ignore', env });
  await new Promise(r => setTimeout(r, 800));
  return { base: `http://127.0.0.1:${port}`, stop: () => child.kill() };
}

// ---------- suites ----------
async function desktop(browser, base) {
  const page = await browser.newPage({ ...PAGE_OPTS, viewport: { width: 1440, height: 900 } });
  const errors = []; page.on('pageerror', e => errors.push(e.message)); instrument(page);
  await mockNetwork(page, seriesFixture(60, false));
  await page.evaluate(() => { try { localStorage.clear(); } catch (e) {} }).catch(() => {});
  await openSeries(page, `${base}/player.php?video=show`);
  await page.evaluate(() => { try { localStorage.removeItem('theaterMode'); } catch (e) {} });
  await page.reload(); await waitForPlaylist(page, 'desktop reload');

  let sb = await rect(page, '#playlistSidebar');
  const items = await page.evaluate(() => { const el = document.getElementById('playlistItems'); return { sh: el.scrollHeight, ch: el.clientHeight }; });
  const docH = await page.evaluate(() => document.documentElement.scrollHeight);
  check('desktop: rail pinned under header', sb && sb.top === 76, JSON.stringify(sb));
  check('desktop: rail no taller than the viewport', sb && sb.bottom <= 900, `bottom=${sb && sb.bottom}`);
  check('desktop: episode list scrolls inside the rail', items.sh > items.ch + 50, JSON.stringify(items));
  check('desktop: page not stretched by the playlist', docH < 4000, `scrollHeight=${docH}`);
  await scrollTo(page, 500);
  sb = await rect(page, '#playlistSidebar');
  check('desktop: rail stays pinned while the page scrolls', sb && sb.top === 76, JSON.stringify(sb));
  await scrollTo(page, 0);

  await page.evaluate(() => { const el = document.getElementById('playlistItems'); el.scrollTop = el.scrollHeight; });
  await page.click('.playlist-item[data-index="44"]'); await page.waitForTimeout(600);
  const vis = await page.evaluate(() => { const a = document.querySelector('.playlist-item.active'); const c = document.getElementById('playlistItems'); const ar = a.getBoundingClientRect(), cr = c.getBoundingClientRect(); return { idx: a.dataset.index, inside: ar.top >= cr.top - 1 && ar.bottom <= cr.bottom + 1 }; });
  check('desktop: chosen episode is active and visible in the rail', vis.idx === '44' && vis.inside, JSON.stringify(vis));

  // --- click / keyboard: exactly one toggle each ---
  await page.waitForFunction(() => { const v = document.querySelector('video'); return v && !v.paused && v.currentTime > 0.2; }, null, { timeout: 15000 });
  const vr = await rect(page, 'video');
  await page.mouse.click(vr.left + vr.width / 2, vr.top + vr.height / 2); await page.waitForTimeout(400);
  let st = await page.evaluate(() => ({ paused: document.querySelector('video').paused }));
  check('click on the video pauses it and it STAYS paused', st.paused === true, JSON.stringify(st));
  await page.keyboard.press('Space'); await page.waitForTimeout(400);
  st = await page.evaluate(() => ({ paused: document.querySelector('video').paused }));
  check('Space with the video focused toggles once', st.paused === false, JSON.stringify(st));
  const t0 = await page.evaluate(() => document.querySelector('video').currentTime);
  await page.keyboard.press('ArrowRight'); await page.waitForTimeout(300);
  const t1 = await page.evaluate(() => document.querySelector('video').currentTime);
  check('ArrowRight seeks +5s once (not twice)', t1 - t0 > 4 && t1 - t0 < 7.5, `delta=${(t1 - t0).toFixed(2)}`);
  await page.keyboard.press('Space'); await page.waitForTimeout(200);
  await page.click('#sidebarNextBtn'); await page.waitForTimeout(800);
  const idx = await page.evaluate(() => document.querySelector('.playlist-item.active').dataset.index);
  await page.waitForFunction(() => { const v = document.querySelector('video'); return v && !v.paused; }, null, { timeout: 10000 }).catch(() => {});
  await page.keyboard.press('Space'); await page.waitForTimeout(400);
  st = await page.evaluate(() => ({ paused: document.querySelector('video').paused, idx: document.querySelector('.playlist-item.active').dataset.index }));
  check('Space after clicking Next pauses instead of skipping again', st.paused === true && st.idx === idx, JSON.stringify(st));
  await page.keyboard.press('Control+f'); await page.waitForTimeout(200);
  check('Ctrl+F is left to the browser', !(await page.evaluate(() => !!document.fullscreenElement)));

  // --- theater mode ---
  await page.keyboard.press('t'); await page.waitForTimeout(500);
  const cin = await rect(page, '#playerCinema'); sb = await rect(page, '#playlistSidebar');
  check('theater: cinema is full-bleed', cin && cin.left === 0 && cin.width === 1440 && cin.height === 900, JSON.stringify(cin));
  check('theater: rail sits under the video beside the description', sb && sb.top >= 900 && sb.left > 900, JSON.stringify(sb));
  await page.keyboard.press('t');

  // --- single video: no rail ---
  await open(page, `${base}/player.php?video=single`); await page.waitForTimeout(500);
  const single = await page.evaluate(() => ({ has: document.body.classList.contains('has-playlist'), rail: getComputedStyle(document.getElementById('playlistSidebar')).display }));
  check('single video: no rail, no layout class', !single.has && single.rail === 'none', JSON.stringify(single));
  check('desktop: no page errors', errors.length === 0, errors.join(' | '));
  await page.close();
}

async function behaviours(browser, base) {
  const page = await browser.newPage({ ...PAGE_OPTS, viewport: { width: 1440, height: 900 } });
  const errors = []; page.on('pageerror', e => errors.push(e.message)); instrument(page);
  await mockNetwork(page, seriesFixture(6, true));
  await openSeries(page, `${base}/player.php?video=show`);
  await page.evaluate(() => { try { localStorage.clear(); localStorage.setItem('afc_disclaimer_ack_v1', '1'); } catch (e) {} });
  await page.reload(); await waitForPlaylist(page, 'behaviours reload');

  const pl = await page.evaluate(() => ({ items: document.querySelectorAll('.playlist-item').length, picker: getComputedStyle(document.getElementById('qualitySelector')).display, options: document.querySelectorAll('#qualityMenu .quality-option').length, src: document.querySelector('video source').src }));
  check('series: encodes collapse to one entry per episode', pl.items === 6, `items=${pl.items}`);
  check('series: quality picker lists the episode\'s encodes', pl.picker !== 'none' && pl.options === 2, JSON.stringify(pl));
  await page.waitForFunction(() => document.querySelector('video').currentTime > 1);
  await page.evaluate(() => { document.querySelector('video').currentTime = 8; }); await page.waitForTimeout(300);
  await page.click('#qualityBtn'); await page.click('#qualityMenu .quality-option:not(.active)');
  await page.waitForFunction(() => /512kb/.test(document.querySelector('video source').src)); await page.waitForTimeout(800);
  const q = await page.evaluate(() => ({ src: document.querySelector('video source').src, t: document.querySelector('video').currentTime, idx: document.querySelector('.playlist-item.active').dataset.index }));
  check('quality switch keeps the episode and the position', q.idx === '0' && /E01_512kb/.test(q.src) && q.t > 6, JSON.stringify(q));
  await page.click('#sidebarNextBtn'); await page.waitForTimeout(800);
  const n = await page.evaluate(() => document.querySelector('video source').src);
  check('next episode honours the remembered encode', /E02_512kb/.test(n), n);

  await page.click('.playlist-item[data-index="2"]'); await page.waitForTimeout(800);
  await page.waitForFunction(() => document.querySelector('video').readyState >= 1);
  await page.evaluate(() => { document.querySelector('video').currentTime = 12; }); await page.waitForTimeout(400);
  await page.evaluate(() => document.querySelector('video').pause()); await page.waitForTimeout(300);
  await page.goto(`${base}/player.php?video=show`); await waitForPlaylist(page, 'series memory reload'); await page.waitForTimeout(500);
  const back = await page.evaluate(() => ({ idx: document.querySelector('.playlist-item.active').dataset.index, prompt: getComputedStyle(document.getElementById('resumePrompt')).display, text: document.getElementById('resumeText').textContent }));
  check('series: reload lands on the last-watched episode', back.idx === '2', JSON.stringify(back));
  check('series: resume prompt offered for that episode', back.prompt !== 'none' && /0:1\d/.test(back.text), JSON.stringify(back));
  await page.click('#resumeBtn'); await page.waitForTimeout(600);
  const t = await page.evaluate(() => document.querySelector('video').currentTime);
  check('Resume seeks to the saved position', t >= 11 && t < 18, `t=${t.toFixed(1)}`);

  await page.goto(`${base}/player.php?video=show&track=2&t=14`); await waitForPlaylist(page, 'deep link');
  await page.waitForFunction(() => document.querySelector('video').currentTime >= 13.5, null, { timeout: 8000 }).catch(() => {});
  const tt = await page.evaluate(() => document.querySelector('video').currentTime);
  check('?t= deep link seeks once metadata is known', tt >= 13.5 && tt < 19, `t=${tt.toFixed(1)}`);
  check('behaviours: no page errors', errors.length === 0, errors.join(' | '));
  await page.close();
}

async function phone(browser, base) {
  const page = await browser.newPage({ ...PAGE_OPTS, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const errors = []; page.on('pageerror', e => errors.push(e.message)); instrument(page);
  await mockNetwork(page, seriesFixture(60, false));
  await openSeries(page, `${base}/player.php?video=show`);
  const ov = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
  check('phone: no horizontal overflow', ov.sw <= ov.cw, JSON.stringify(ov));
  const hdr = await page.evaluate(() => { const a = document.querySelector('.player-header-actions').getBoundingClientRect(); const b = document.querySelector('.player-back-btn').getBoundingClientRect(); return { fits: a.right <= innerWidth && a.left > b.right }; });
  check('phone: header actions fit beside the back button', hdr.fits, JSON.stringify(hdr));
  await scrollTo(page, 2500);
  const cin = await rect(page, '#playerCinema'); const ph = await rect(page, '.player-sidebar-header');
  check('phone: video stays pinned under the header while scrolling the list', cin && cin.top === 52, JSON.stringify(cin));
  check('phone: playlist header docks under the video', ph && ph.top === cin.bottom, JSON.stringify(ph));
  const items = await page.evaluate(() => { const el = document.getElementById('playlistItems'); return el.scrollHeight === el.clientHeight; });
  check('phone: playlist flows in the page (no nested scroller)', items);
  check('phone: no page errors', errors.length === 0, errors.join(' | '));
  await page.close();

  const land = await browser.newPage({ ...PAGE_OPTS, viewport: { width: 740, height: 360 }, isMobile: true, hasTouch: true });
  instrument(land);
  await mockNetwork(land, seriesFixture(10, false));
  await openSeries(land, `${base}/player.php?video=show`);
  const pos = await land.evaluate(() => getComputedStyle(document.getElementById('playerCinema')).position);
  check('landscape phone: video is not pinned (it would cover everything)', pos !== 'sticky', pos);
  await land.close();
}

(async () => {
  const server = process.env.BASE_URL ? null : await startPhp();
  const base = process.env.BASE_URL || server.base;
  const launch = { args: ['--autoplay-policy=no-user-gesture-required', '--disable-features=NetworkPrediction'] };
  if (process.env.CHROMIUM_PATH) launch.executablePath = process.env.CHROMIUM_PATH;
  const browser = await playwright.chromium.launch(launch);
  try {
    await desktop(browser, base);
    await behaviours(browser, base);
    await phone(browser, base);
  } finally {
    await browser.close();
    if (server) server.stop();
  }
  console.log(failures ? `\n${failures} check(s) FAILED` : '\nAll player checks passed');
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
