const express = require('express');
const path = require('path');
const fs = require('fs');
const http = require('http');
const https = require('https');

const APP_ROOT = path.join(__dirname, '..');

// Simple JSON file store for local data
class LocalStore {
  constructor() {
    this.dataDir = path.join(APP_ROOT, 'electron', 'data');
    if (!fs.existsSync(this.dataDir)) {
      fs.mkdirSync(this.dataDir, { recursive: true });
    }
  }

  _filePath(name) {
    return path.join(this.dataDir, `${name}.json`);
  }

  read(name, defaultValue = null) {
    try {
      const data = fs.readFileSync(this._filePath(name), 'utf-8');
      return JSON.parse(data);
    } catch {
      return defaultValue;
    }
  }

  write(name, data) {
    fs.writeFileSync(this._filePath(name), JSON.stringify(data, null, 2));
  }
}

// Follows redirects (archive.org bounces /download and /services/img to
// per-item hosts) but caps the chain so a redirect loop can't recurse forever.
const MAX_REDIRECTS = 5;

function fetchUrl(url, redirectsLeft = MAX_REDIRECTS) {
  return new Promise((resolve, reject) => {
    const mod = url.startsWith('https') ? https : http;
    const req = mod.get(url, { headers: { 'User-Agent': 'ArchiveFilmClub/1.0 Electron' } }, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        res.resume(); // discard the redirect body
        if (redirectsLeft <= 0) {
          reject(new Error('Too many redirects'));
          return;
        }
        let next;
        try {
          next = new URL(res.headers.location, url).toString();
        } catch (e) {
          reject(new Error('Invalid redirect location'));
          return;
        }
        fetchUrl(next, redirectsLeft - 1).then(resolve).catch(reject);
        return;
      }
      const chunks = [];
      res.on('data', chunk => chunks.push(chunk));
      res.on('end', () => {
        const body = Buffer.concat(chunks);
        resolve({ statusCode: res.statusCode, headers: res.headers, body });
      });
    });
    req.on('error', reject);
    req.setTimeout(15000, () => { req.destroy(); reject(new Error('Request timeout')); });
  });
}

// Allow-list of settings the desktop build accepts -- the same keys and
// rules as api/settings.php, so a payload the PHP API would reject is
// rejected here too. Anything not listed is dropped.
const SETTINGS_SCHEMA = {
  siteName:           { type: 'string', maxLength: 100 },
  tagline:            { type: 'string', maxLength: 200 },
  brandColor:         { type: 'color' },
  accentColor:        { type: 'color' },
  defaultTheme:       { type: 'enum', values: ['dark', 'light', 'system'] },
  enableThemeToggle:  { type: 'bool' },
  headerStyle:        { type: 'enum', values: ['default', 'minimal', 'centered'] },
  cardStyle:          { type: 'enum', values: ['modern', 'classic', 'compact'] },
  showDownloadCount:  { type: 'bool' },
  showCreator:        { type: 'bool' },
  showDate:           { type: 'bool' },
  enableBookmarks:    { type: 'bool' },
  enableWatchHistory: { type: 'bool' },
  defaultCollection:  { type: 'string', maxLength: 50 },
  defaultSort:        { type: 'enum', values: ['downloads', 'date', 'title', 'relevance', 'creator'] }
};
const HEX_COLOR = /^#[0-9A-Fa-f]{6}$/;

// Returns only the recognised, validated keys of `body` (partial update
// semantics: keys that are absent are simply not returned).
function sanitizeSettings(body) {
  const clean = {};
  if (!body || typeof body !== 'object' || Array.isArray(body)) return clean;
  for (const key of Object.keys(SETTINGS_SCHEMA)) {
    if (!Object.prototype.hasOwnProperty.call(body, key)) continue;
    const rule = SETTINGS_SCHEMA[key];
    const value = body[key];
    switch (rule.type) {
      case 'string':
        if (typeof value !== 'string') break;
        // strip_tags + trim + length cap, like ApiController::sanitizeText()
        clean[key] = value.replace(/<[^>]*>?/g, '').trim().slice(0, rule.maxLength);
        break;
      case 'color':
        if (typeof value === 'string' && HEX_COLOR.test(value)) clean[key] = value.toLowerCase();
        break;
      case 'bool':
        clean[key] = value === true || value === 1 || value === '1' || value === 'true';
        break;
      case 'enum':
        if (rule.values.includes(value)) clean[key] = value;
        break;
    }
  }
  return clean;
}

// Content-Security-Policy for the pages this server renders. Mirrors the
// header set in the root .htaccess (minus frame-ancestors, which browsers
// ignore in a <meta> policy).
const CSP = [
  "default-src 'self'",
  "img-src 'self' data: https://archive.org https://*.archive.org",
  "media-src 'self' https://archive.org https://*.archive.org",
  "script-src 'self' 'unsafe-inline'",
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
  "font-src 'self' https://fonts.gstatic.com",
  "connect-src 'self' https://archive.org https://*.archive.org",
  "base-uri 'self'",
  "form-action 'self'"
].join('; ');

const FAVICON_DATA_URI = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTQgOEw0IDE2QzQgMTcuMTA0NiA0Ljg5NTQzIDE4IDYgMThMMTggMThDMTkuMTA0NiAxOCAyMCAxNy4xMDQ2IDIwIDE2VjhDMjAgNi44OTU0MyAxOS4xMDQ2IDYgMTggNkw2IDZDNC44OTU0MyA2IDQgNi44OTU0MyA0IDhaIiBzdHJva2U9IiNmZjAwMDAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIi8+CjxwYXRoIGQ9Ik0xMCAxMkwxNCAxMk0xMiAxMEwxMiAxNCIgc3Ryb2tlPSIjZmYwMDAwIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgo8L3N2Zz4K';

function startServer() {
  return new Promise((resolve) => {
    const app = express();
    const store = new LocalStore();

    app.use(express.json());

    // Load site settings
    const defaultSettings = {
      siteName: 'Archive Film Club',
      tagline: 'Discover classic films from Archive.org',
      brandColor: '#ff0000',
      accentColor: '#065fd4',
      defaultTheme: 'dark',
      enableThemeToggle: true,
      cardStyle: 'modern',
      showDownloadCount: true,
      showCreator: true,
      showDate: true,
      enableBookmarks: true,
      enableWatchHistory: true,
      defaultCollection: 'all_videos',
      defaultSort: 'downloads'
    };

    // Stored settings are re-validated on every read (not only on write) so
    // a hand-edited electron/data/settings.json can't smuggle CSS or markup
    // into the inline <style> / <script> blocks the pages are built from.
    const loadSettings = () => ({ ...defaultSettings, ...sanitizeSettings(store.read('settings', {})) });

    // Serve index.html for root
    app.get('/', (req, res) => {
      const settings = loadSettings();
      const recommendations = store.read('recommendations') || loadJsonFallback('recommendations.json', { enabled: true, title: 'Staff Picks', videos: [] });
      const featuredSections = store.read('featured-sections') || loadJsonFallback('featured-sections.json', { sections: [] });

      const html = buildIndexHtml(settings, recommendations, featuredSections, colorsFor(settings));
      res.type('html').send(html);
    });

    // Dedicated player page (app.js navigates to player.php?video=… on click).
    // Same static markup as player.php with the PHP bits replaced by the
    // stored settings; player.js does the rest client-side.
    app.get('/player.php', (req, res) => {
      const settings = loadSettings();
      const videoId = String(req.query.video || '').replace(/[^a-zA-Z0-9_.\-]/g, '');
      res.type('html').send(buildPlayerHtml(settings, colorsFor(settings), videoId));
    });

    // index.php is the homepage on the PHP build; player.php and app.js link
    // to it. Send it to the express homepage, keeping any query string.
    app.get('/index.php', (req, res) => {
      const q = req.url.indexOf('?');
      res.redirect(302, '/' + (q >= 0 ? req.url.slice(q) : ''));
    });

    // Pages that need the PHP backend + database. Render a short explanation
    // instead of the bare 404 the .php denylist below would produce.
    const UNAVAILABLE_PAGES = {
      'collections.php': 'Collections',
      'collection.php': 'Collections',
      'account.php': 'Your account',
      'login.php': 'Sign in',
      'register.php': 'Sign up',
      'forgot-password.php': 'Password reset',
      'reset-password.php': 'Password reset',
      'verify-email.php': 'Email verification'
    };
    Object.keys(UNAVAILABLE_PAGES).forEach((file) => {
      app.get('/' + file, (req, res) => {
        const settings = loadSettings();
        res.type('html').send(buildUnavailableHtml(settings, colorsFor(settings), UNAVAILABLE_PAGES[file]));
      });
    });

    // --- API ENDPOINTS ---

    // Search proxy to Archive.org
    app.get('/api/search.php', async (req, res) => {
      try {
        const params = new URLSearchParams({
          q: req.query.q || '*',
          output: 'json',
          rows: req.query.rows || '24',
          page: req.query.page || '1'
        });

        // Add fields
        ['identifier', 'title', 'description', 'date', 'downloads', 'creator', 'runtime', 'licenseurl', 'subject', 'mediatype', 'num_items']
          .forEach(f => params.append('fl[]', f));

        // Add sorting
        const sort = req.query.sort;
        if (sort && sort !== 'relevance') {
          switch (sort) {
            case 'date': params.append('sort[]', 'publicdate desc'); break;
            case 'downloads': params.append('sort[]', 'downloads desc'); break;
            case 'title': params.append('sort[]', 'titleSorter asc'); break;
            case 'creator': params.append('sort[]', 'creatorSorter asc'); break;
          }
        }

        const url = `https://archive.org/advancedsearch.php?${params}`;
        const result = await fetchUrl(url);
        const data = JSON.parse(result.body.toString());

        res.json({
          success: true,
          cached: false,
          data: data
        });
      } catch (err) {
        res.status(500).json({ success: false, error: err.message });
      }
    });

    // Metadata proxy
    app.get('/api/metadata.php', async (req, res) => {
      try {
        const id = req.query.id;
        if (!id) return res.status(400).json({ error: 'Missing id' });

        const result = await fetchUrl(`https://archive.org/metadata/${encodeURIComponent(id)}`);
        const data = JSON.parse(result.body.toString());
        res.json(data);
      } catch (err) {
        res.status(500).json({ error: err.message });
      }
    });

    // Thumbnail proxy
    app.get('/api/thumbnail.php', async (req, res) => {
      try {
        const id = req.query.id;
        if (!id) return res.status(400).json({ error: 'Missing id' });

        const result = await fetchUrl(`https://archive.org/services/img/${encodeURIComponent(id)}`);
        const contentType = result.headers['content-type'] || 'image/jpeg';
        res.set('Content-Type', contentType);
        res.set('Cache-Control', 'public, max-age=86400');
        res.send(result.body);
      } catch (err) {
        res.redirect(`https://archive.org/services/img/${encodeURIComponent(req.query.id || '')}`);
      }
    });

    // Settings
    app.get('/api/settings.php', (req, res) => {
      const settings = loadSettings();
      res.json({ success: true, data: settings, settings });
    });

    // Same allow-list + validation as api/settings.php: unknown keys are
    // dropped, colours must be #rrggbb, enums/booleans are coerced, and a
    // body with nothing recognisable is rejected. Partial updates merge over
    // the stored file.
    app.post('/api/settings.php', (req, res) => {
      const clean = sanitizeSettings(req.body);
      if (Object.keys(clean).length === 0) {
        return res.status(400).json({ success: false, error: 'No recognised settings in request body' });
      }
      const merged = { ...sanitizeSettings(store.read('settings', {})), ...clean, updated: new Date().toISOString() };
      store.write('settings', merged);
      res.json({ success: true, data: { ...defaultSettings, ...merged } });
    });

    // Recommendations
    app.get('/api/recommendations.php', (req, res) => {
      const data = store.read('recommendations') || loadJsonFallback('recommendations.json', { enabled: true, title: 'Staff Picks', videos: [] });
      res.json(data);
    });

    // Sections
    app.get('/api/sections.php', (req, res) => {
      const data = store.read('featured-sections') || loadJsonFallback('featured-sections.json', { sections: [] });
      res.json(data);
    });

    // Bookmarks (localStorage-backed on frontend, but provide API)
    app.get('/api/bookmarks.php', (req, res) => {
      res.json({ success: true, bookmarks: store.read('bookmarks', []) });
    });

    app.post('/api/bookmarks.php', (req, res) => {
      const { action } = req.body;
      let bookmarks = store.read('bookmarks', []);

      if (action === 'add') {
        const exists = bookmarks.find(b => b.id === req.body.id);
        if (!exists) {
          bookmarks.push({ id: req.body.id, title: req.body.title, creator: req.body.creator, thumbnail: req.body.thumbnail, added: new Date().toISOString() });
          store.write('bookmarks', bookmarks);
        }
        res.json({ success: true });
      } else if (action === 'remove') {
        bookmarks = bookmarks.filter(b => b.id !== req.body.id);
        store.write('bookmarks', bookmarks);
        res.json({ success: true });
      } else if (action === 'sync') {
        store.write('bookmarks', req.body.bookmarks || []);
        res.json({ success: true });
      } else {
        res.json({ success: true, bookmarks });
      }
    });

    // History
    app.get('/api/history.php', (req, res) => {
      const history = store.read('history', []);
      if (req.query.action === 'progress' && req.query.id) {
        const entry = history.find(h => h.id === req.query.id);
        return res.json({ success: true, progress: entry || null });
      }
      const limit = parseInt(req.query.limit) || 50;
      res.json({ success: true, history: history.slice(0, limit) });
    });

    app.post('/api/history.php', (req, res) => {
      const { action } = req.body;
      if (action === 'clear') {
        store.write('history', []);
        return res.json({ success: true });
      }
      if (action === 'update') {
        let history = store.read('history', []);
        const idx = history.findIndex(h => h.id === req.body.id);
        const entry = { id: req.body.id, currentTime: req.body.currentTime, duration: req.body.duration, updated: new Date().toISOString() };
        if (idx >= 0) {
          history[idx] = entry;
        } else {
          history.unshift(entry);
        }
        // Keep last 200
        if (history.length > 200) history = history.slice(0, 200);
        store.write('history', history);
        return res.json({ success: true });
      }
      res.json({ success: true });
    });

    // User
    app.get('/api/user.php', (req, res) => {
      res.json({ success: true, preferences: store.read('user-prefs', {}) });
    });

    app.post('/api/user.php', (req, res) => {
      if (req.body.action === 'preferences') {
        store.write('user-prefs', req.body.preferences || {});
      }
      res.json({ success: true });
    });

    // Stats (stub)
    app.get('/api/stats.php', (req, res) => {
      res.json({ success: true, data: [] });
    });

    // API index / health
    app.get('/api/index.php', (req, res) => {
      res.json({ status: 'ok', version: '1.0.0' });
    });
    app.head('/api/index.php', (req, res) => {
      res.sendStatus(200);
    });

    // Auth: the desktop build has no accounts. Report a guest so AuthNav and
    // the comments module render their signed-out state instead of erroring.
    app.get('/api/auth/me.php', (req, res) => {
      res.set('Cache-Control', 'private, no-store');
      res.json({ success: true, authenticated: false, user: null, guest: null });
    });

    // Every other API endpoint (comments, collections, auth actions, cache…)
    // needs the PHP backend. Answer with JSON so the frontend's
    // `await res.json()` paths get a clean message instead of a parse error.
    app.all('/api/*', (req, res) => {
      res.status(404).json({ success: false, error: 'Not available in the desktop app' });
    });

    // Security: never serve source or secrets as static files. PHP is NOT
    // executed here, so without this block any page in the Electron window
    // could fetch('/.env') or '/db/config.php' and read DB credentials and
    // server source in cleartext. Pages are produced by the explicit routes
    // above, so no .php / source file ever needs static serving. The directory
    // patterns are anchored to the root so the frontend's own src/js/services/
    // and src/js/components/ modules keep loading.
    // Mirrors the Apache policy in .htaccess (FilesMatch / RedirectMatch).
    const DENY_EXT = /\.(php|sql|sh|log|lock|ini|md|sample|example|bak|swp|swo)$/i;
    const DENY_DOTFILE = /(^|\/)\.(env|git|ht|installed)/i;  // .env(.example), .git/, .htaccess, .installed
    const DENY_DIR = /^\/(db|electron|backups|logs|tests|scripts|partials|cron|services|node_modules)\//i; // source + server-side data dirs
    const DENY_FILE = /^\/(site-settings|recommendations|featured-sections|package|package-lock)\.json$/i; // JSON fallbacks + npm manifests
    app.use((req, res, next) => {
      let p;
      try { p = decodeURIComponent(req.path); } catch { p = req.path; }
      if (DENY_EXT.test(p) || DENY_DOTFILE.test(p) || DENY_DIR.test(p) || DENY_FILE.test(p)) {
        return res.status(404).type('text/plain').send('Not found');
      }
      next();
    });

    // Serve static files (CSS, JS, images, etc.)
    app.use(express.static(APP_ROOT, {
      index: false, // We handle index ourselves
      extensions: ['html']
    }));

    const server = app.listen(0, '127.0.0.1', () => {
      const port = server.address().port;
      console.log(`Server running on http://127.0.0.1:${port}`);
      // Hand the server back too so main.js can close it on quit.
      resolve({ port, server });
    });
  });
}

function loadJsonFallback(filename, defaultValue) {
  try {
    const filePath = path.join(APP_ROOT, filename);
    if (fs.existsSync(filePath)) {
      return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
    }
  } catch {}
  return defaultValue;
}

function darkenColor(hex, percent = 20) {
  hex = hex.replace('#', '');
  if (hex.length !== 6) return '#' + hex;
  let r = parseInt(hex.substring(0, 2), 16);
  let g = parseInt(hex.substring(2, 4), 16);
  let b = parseInt(hex.substring(4, 6), 16);
  r = Math.max(0, Math.round(r - (r * percent / 100)));
  g = Math.max(0, Math.round(g - (g * percent / 100)));
  b = Math.max(0, Math.round(b - (b * percent / 100)));
  return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
}

function esc(str) {
  if (str === null || str === undefined || str === '') return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// JSON for embedding inside <script> (application/json data islands and
// inline scripts). `<` is escaped so a value containing "</script>" or
// "<!--" can't break out of the element; U+2028/2029 are escaped because
// they are valid in JSON but line terminators in JavaScript.
function jsonForScript(value) {
  return JSON.stringify(value)
    .replace(/</g, '\\u003c')
    .replace(/\u2028/g, '\\u2028')
    .replace(/\u2029/g, '\\u2029');
}

function colorsFor(settings) {
  const brandColor = HEX_COLOR.test(settings.brandColor || '') ? settings.brandColor : '#ff0000';
  const accentColor = HEX_COLOR.test(settings.accentColor || '') ? settings.accentColor : '#065fd4';
  return {
    brandColor,
    accentColor,
    brandColorDark: darkenColor(brandColor),
    accentColorDark: darkenColor(accentColor),
    initialTheme: settings.defaultTheme === 'system' ? 'dark' : (settings.defaultTheme || 'dark')
  };
}

function buildIndexHtml(settings, recommendations, featuredSections, colors) {
  const { brandColor, accentColor, brandColorDark, accentColorDark, initialTheme } = colors;
  const siteName = settings.siteName || 'Archive Film Club';

  return `<!DOCTYPE html>
<html lang="en" data-theme="${esc(initialTheme)}">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Content-Security-Policy" content="${CSP}" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes" />
  <title>${esc(siteName)}</title>
  <meta name="description" content="${esc(settings.tagline)}" />
  <meta name="theme-color" content="${esc(brandColor)}" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://archive.org">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="icon" type="image/svg+xml" href="${FAVICON_DATA_URI}" />

  <link rel="stylesheet" href="styles.css">

  <style>
    :root {
      --brand-color: ${esc(brandColor)};
      --brand-color-dark: ${esc(brandColorDark)};
      --accent-color: ${esc(accentColor)};
      --accent-color-dark: ${esc(accentColorDark)};
    }
  </style>

  <script>
    (function() {
      var savedTheme = localStorage.getItem('theme');
      var defaultTheme = '${esc(settings.defaultTheme || 'dark')}';
      var theme = savedTheme || defaultTheme;
      if (theme === 'system') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body data-card-style="${esc(settings.cardStyle || 'modern')}">
  <header class="site-header">
    <div class="header-content">
      <button class="mobile-menu-btn" aria-label="Open menu">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M3 12H21M3 6H21M3 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>

      <a href="/" class="logo-section" title="Go to homepage">
        <div class="logo-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 8L4 16C4 17.1046 4.89543 18 6 18L18 18C19.1046 18 20 17.1046 20 16V8C20 6.89543 19.1046 6 18 6L6 6C4.89543 6 4 6.89543 4 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M10 12L14 12M12 10L12 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <span class="logo-text">${esc(siteName)}</span>
      </a>

      <form id="searchForm" class="header-search-form" role="search" aria-label="Search videos">
        <div class="header-search-input-wrapper">
          <input id="searchInput" type="search" class="header-search-input" placeholder="Search" autocomplete="off" aria-label="Search videos" />
          <button id="clearSearchBtn" class="clear-search-btn" type="button" style="display: none;" aria-label="Clear search">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
        <button type="submit" class="search-submit-btn" aria-label="Search">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M21 21L15 15M17 10C17 13.866 13.866 17 10 17C6.13401 17 3 13.866 3 10C3 6.13401 6.13401 3 10 3C13.866 3 17 6.13401 17 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
      </form>

      <div class="header-end">
        ${settings.enableThemeToggle ? `
        <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme" title="Toggle light/dark mode">
          <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="5"></circle>
            <line x1="12" y1="1" x2="12" y2="3"></line>
            <line x1="12" y1="21" x2="12" y2="23"></line>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
            <line x1="1" y1="12" x2="3" y2="12"></line>
            <line x1="21" y1="12" x2="23" y2="12"></line>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
          </svg>
          <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
          </svg>
        </button>
        ` : ''}
      </div>
    </div>
  </header>

  <div class="mobile-overlay"></div>

  <main class="main-layout">
    <aside class="sidebar" aria-label="Filter videos">
      <button class="mobile-close-btn" aria-label="Close menu">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>

      <section class="filter-section">
        <h2 class="filter-title">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3 7H21M6 12H18M9 17H15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Collections
        </h2>
        <div class="filter-field">
          <label for="collection">Select Collection</label>
          <div class="select-wrapper">
            <select id="collection" class="filter-select"></select>
            <svg class="select-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
        </div>
      </section>

      <section class="filter-section">
        <h2 class="filter-title">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3 6H21M6 12H18M11 18H13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Sort & Filters
        </h2>

        <div class="filter-field">
          <label for="sortBy">Sort By</label>
          <div class="select-wrapper">
            <select id="sortBy" class="filter-select">
              <option value="relevance">Relevance</option>
              <option value="date">Date (Newest First)</option>
              <option value="downloads" selected>Most Downloaded</option>
              <option value="title">Title (A-Z)</option>
              <option value="creator">Creator (A-Z)</option>
            </select>
            <svg class="select-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
        </div>

        <div class="checkbox-group">
          <input id="publicDomain" type="checkbox" class="checkbox-input" />
          <label for="publicDomain" class="checkbox-label">Public Domain Only</label>
        </div>

        <div class="checkbox-group">
          <input id="collectionsOnly" type="checkbox" class="checkbox-input" />
          <label for="collectionsOnly" class="checkbox-label">Collections Only</label>
        </div>
      </section>

      <button id="clearFilters" class="btn btn-secondary btn-full" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Reset All Filters
      </button>

      <div id="searchStats" class="stats">Ready to search</div>
    </aside>

    <section class="content-area">
      <section id="recommendedSection" class="recommended-section" style="display: none;">
        <div class="recommended-header">
          <h2 class="recommended-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
              <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
            </svg>
            Staff Picks
          </h2>
          <button id="hideRecommended" class="btn btn-ghost" aria-label="Hide recommendations">Hide</button>
        </div>
        <div id="recommendedGrid" class="recommended-grid"></div>
      </section>

      <div id="featuredSectionsContainer"></div>

      <div id="playerContainer" class="player" aria-hidden="true">
        <div class="player-controls">
          <button id="playPauseBtn" class="play-pause-btn" aria-label="Play/Pause">
            <svg class="play-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M5 3L19 12L5 21V3Z" fill="currentColor"/>
            </svg>
            <svg class="pause-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:none;">
              <path d="M6 4H10V20H6V4ZM14 4H18V20H14V4Z" fill="currentColor"/>
            </svg>
          </button>
          <div class="player-info">
            <h2 id="playerTitle">No video selected</h2>
            <p id="playerMeta">Select a video to start playing</p>
          </div>
        </div>
        <div class="video-wrapper">
          <div class="player-loader" style="display: none;">
            <div class="loading-spinner">
              <div class="spinner-ring"></div>
            </div>
          </div>
        </div>
      </div>

      <div id="playerInfo" class="player-info-container"></div>

      <div id="loading" class="loading" hidden>
        <div class="loading-spinner">
          <div class="spinner-ring"></div>
        </div>
        <span class="loading-text">Searching archive...</span>
      </div>

      <div id="error" class="error" role="alert" hidden></div>

      <div id="results" class="results-grid"></div>

      <nav id="pagination" class="pagination" aria-label="Page navigation"></nav>
    </section>
  </main>

  <script id="siteSettingsConfig" type="application/json">${jsonForScript(settings)}</script>
  <script id="recommendedConfig" type="application/json">${jsonForScript(recommendations)}</script>
  <script id="featuredSectionsConfig" type="application/json">${jsonForScript(featuredSections)}</script>

  <script>
    (function() {
      var themeToggle = document.getElementById('themeToggle');
      if (!themeToggle) return;

      function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
      }

      function toggleTheme() {
        var currentTheme = document.documentElement.getAttribute('data-theme');
        var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        setTheme(newTheme);
      }

      themeToggle.addEventListener('click', toggleTheme);

      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        var savedTheme = localStorage.getItem('theme');
        if (!savedTheme || savedTheme === 'system') {
          setTheme(e.matches ? 'dark' : 'light');
        }
      });
    })();
  </script>

  <script type="module" src="app.js"></script>
</body>
</html>`;
}

// Player page for the desktop build. The <body> is the static markup from
// player.php (ported verbatim; keep the two in step when the player DOM
// changes) with its PHP replaced by the stored settings. Open Graph tags are
// omitted -- there are no crawlers inside an Electron window.
function buildPlayerHtml(settings, colors, videoId) {
  const { brandColor, accentColor, brandColorDark, accentColorDark, initialTheme } = colors;
  const siteName = settings.siteName || 'Archive Film Club';
  const noscriptUrl = videoId
    ? 'https://archive.org/details/' + encodeURIComponent(videoId)
    : 'https://archive.org/details/movies';

  return `<!DOCTYPE html>
<html lang="en" data-theme="${esc(initialTheme)}">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Content-Security-Policy" content="${CSP}" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover" />
  <title>${esc(siteName)}</title>
  <meta name="description" content="${esc(settings.tagline)}" />
  <meta name="theme-color" content="${esc(brandColor)}" />
  <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0a0a0b" />
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://archive.org">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="icon" type="image/svg+xml" href="${FAVICON_DATA_URI}" />

  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="player-styles.css">
  <link rel="stylesheet" href="auth-styles.css">

  <style>
    :root {
      --brand-color: ${esc(brandColor)};
      --brand-color-dark: ${esc(brandColorDark)};
      --accent-color: ${esc(accentColor)};
      --accent-color-dark: ${esc(accentColorDark)};
    }
  </style>

  <script>
    (function() {
      var savedTheme = localStorage.getItem('theme');
      var defaultTheme = '${esc(settings.defaultTheme || 'dark')}';
      var theme = savedTheme || defaultTheme;
      if (theme === 'system') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body class="player-page">
  <a class="skip-link" href="#playerCinema">Skip to player</a>
  <noscript>
    <div class="noscript-banner" role="alert">
      <h2>JavaScript is required to play videos</h2>
      <p>This player streams films directly from the Internet Archive and needs JavaScript to run. Please enable JavaScript and reload the page.</p>
      <p>You can also watch this title directly at <a href="${esc(noscriptUrl)}" target="_blank" rel="noopener">archive.org</a>.</p>
    </div>
  </noscript>

  <!-- Player Header -->
  <header class="player-header" id="playerHeader">
    <div class="player-header-content">
      <a href="index.php" class="player-back-btn" title="Back to search" aria-label="Back to search">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span class="player-back-text">Back to search</span>
      </a>

      <a href="index.php" class="player-logo" title="Go to homepage">
        <div class="player-logo-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 8L4 16C4 17.1046 4.89543 18 6 18L18 18C19.1046 18 20 17.1046 20 16V8C20 6.89543 19.1046 6 18 6L6 6C4.89543 6 4 6.89543 4 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M10 12L14 12M12 10L12 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <span class="player-logo-text">${esc(siteName)}</span>
      </a>

      <div class="player-header-actions">
        <div class="header-auth" data-auth-nav></div>
        <button id="bookmarkBtn" class="player-action-btn" title="Bookmark" aria-label="Bookmark this video">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M5 5C5 3.89543 5.89543 3 7 3H17C18.1046 3 19 3.89543 19 5V21L12 17.5L5 21V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <button id="saveToCollectionBtn" class="player-action-btn" title="Save to collection" aria-label="Save to collection">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M4 6H20M4 12H14M4 18H14M18 15V21M15 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        ${settings.enableThemeToggle ? `
        <button id="themeToggle" class="player-action-btn theme-toggle" aria-label="Toggle theme" title="Toggle light/dark mode">
          <svg class="sun-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="5"></circle>
            <line x1="12" y1="1" x2="12" y2="3"></line>
            <line x1="12" y1="21" x2="12" y2="23"></line>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
            <line x1="1" y1="12" x2="3" y2="12"></line>
            <line x1="21" y1="12" x2="23" y2="12"></line>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
          </svg>
          <svg class="moon-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
          </svg>
        </button>
        ` : ''}
      </div>
    </div>
  </header>

  <!-- Layout:
         .player-layout            one column, or [video column | playlist rail]
           .player-primary         cinema + everything below it
             .player-cinema
             .player-content
           .player-sidebar         playlist rail (shown only for multi-part items;
                                   JS adds body.has-playlist) -->
  <main class="player-main">
   <div class="player-layout" id="playerLayout">
   <div class="player-primary">
    <div class="player-cinema" id="playerCinema">
      <div id="videoWrapper" class="player-video-wrapper">
        <div id="playerLoader" class="player-loader" role="status" aria-busy="true">
          <div class="loading-spinner" aria-hidden="true">
            <div class="spinner-ring"></div>
          </div>
          <span class="loading-text">Loading video...</span>
        </div>
      </div>

      <!-- Controls Overlay Bar (bottom of cinema) -->
      <div class="player-controls-bar" id="controlsBar">
        <div class="controls-bar-left">
          <button id="prevEpisodeBtn" class="pctl-btn" title="Previous episode (Shift+P)" aria-label="Previous episode" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
          </button>
          <button id="nextEpisodeBtn" class="pctl-btn" title="Next episode (Shift+N)" aria-label="Next episode" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 18h2V6h-2zM6 18l8.5-6L6 6z"/></svg>
          </button>
          <span id="episodeIndicator" class="episode-indicator" style="display:none;"></span>
        </div>
        <div class="controls-bar-right">
          <div id="speedSelector" class="quality-selector">
            <button id="speedBtn" class="pctl-btn quality-btn" title="Playback speed" aria-label="Playback speed">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
              </svg>
              <span id="speedLabel" class="quality-label">1x</span>
            </button>
            <div id="speedMenu" class="quality-menu"></div>
          </div>
          <button id="captionsBtn" class="pctl-btn captions-btn" title="Subtitles / captions (c)" aria-label="Subtitles / captions" aria-pressed="false" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="2" y="5" width="20" height="14" rx="2"/>
              <path d="M7 15h3M14 15h3M7 11h3M14 11h3"/>
            </svg>
            <span class="captions-underline" aria-hidden="true"></span>
          </button>
          <button id="pipBtn" class="pctl-btn" title="Picture in picture (i)" aria-label="Picture in picture" style="display:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="2" y="4" width="20" height="14" rx="2"/>
              <rect x="12" y="10" width="8" height="6" rx="1" fill="currentColor"/>
            </svg>
          </button>
          <div id="qualitySelector" class="quality-selector" style="display:none;">
            <button id="qualityBtn" class="pctl-btn quality-btn" title="Video quality" aria-label="Video quality">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 15V17M12 7V13M8 3H16L21 8V16L16 21H8L3 16V8L8 3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span id="qualityLabel" class="quality-label">HD</span>
            </button>
            <div id="qualityMenu" class="quality-menu"></div>
          </div>
        </div>
      </div>

      <!-- Buffering indicator — appears mid-playback when the network stalls. -->
      <div id="bufferingIndicator" class="player-buffering" aria-hidden="true">
        <div class="loading-spinner" aria-hidden="true">
          <div class="spinner-ring"></div>
        </div>
      </div>

      <!-- Keyboard Shortcut Indicator (kept inside cinema so it shows in fullscreen) -->
      <div id="shortcutIndicator" class="shortcut-indicator" aria-hidden="true"></div>

      <!-- Up Next overlay (kept inside cinema so it shows in fullscreen + autoplay countdown) -->
      <div id="upNextOverlay" class="up-next-overlay" role="dialog" aria-label="Playing next">
        <div class="up-next-card">
          <div class="up-next-eyebrow">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polygon points="5 3 19 12 5 21 5 3"/>
            </svg>
            Up next
          </div>
          <div class="up-next-body">
            <div class="up-next-thumb">
              <img id="upNextThumb" src="" alt="" hidden />
            </div>
            <div class="up-next-info">
              <div id="upNextTitle" class="up-next-title">Next episode</div>
              <div id="upNextCountdown" class="up-next-countdown">Playing in 8…</div>
            </div>
          </div>
          <div class="up-next-actions">
            <button id="upNextCancel" type="button" class="up-next-btn up-next-btn-secondary">Cancel</button>
            <button id="upNextPlay" type="button" class="up-next-btn up-next-btn-primary">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M5 3L19 12L5 21V3Z"/>
              </svg>
              Play now
            </button>
          </div>
          <div class="up-next-progress" aria-hidden="true"><span></span></div>
        </div>
      </div>
    </div>

    <!-- Content Below Video -->
    <div class="player-content" id="playerContent">
      <div class="player-content-main">
        <!-- Video Info -->
        <section id="videoInfo" class="player-video-info">
          <h1 id="videoTitle" class="player-title">Loading...</h1>
          <div class="player-meta-row">
            <span id="videoCreator" class="player-creator"></span>
            <span id="videoDate" class="player-date"></span>
          </div>
          <div id="videoMetaPills" class="player-meta-pills" style="display:none;"></div>
          <div id="videoActions" class="player-video-actions">
            <a id="archiveLink" href="#" target="_blank" class="player-pill-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M18 13V19C18 20.1046 17.1046 21 16 21H5C3.89543 21 3 20.1046 3 19V8C3 6.89543 3.89543 6 5 6H11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 3H21V9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Archive.org
            </a>
            <button id="shareBtn" class="player-pill-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 12V20C4 21.1046 4.89543 22 6 22H18C19.1046 22 20 21.1046 20 20V12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="16 6 12 2 8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="2" x2="12" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Share
            </button>
            <button id="downloadBtn" class="player-pill-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L7 10M12 15L17 10M3 17V19C3 20.1046 3.89543 21 5 21H19C20.1046 21 21 20.1046 21 19V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Download
            </button>
            <button id="reportBtn" class="player-pill-btn" title="Report this video to Archive.org">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
              Report
            </button>
          </div>
          <div id="videoTagsRow" class="player-tags-row" style="display:none;"></div>
        </section>

        <!-- Description -->
        <section id="descriptionSection" class="player-description-section" style="display: none;">
          <button id="descriptionToggle" class="player-description-toggle">
            <span>Description</span>
            <svg class="toggle-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <div id="descriptionContent" class="player-description-content"></div>
        </section>

        <!-- Comments (members-only, site-local — never posted to archive.org) -->
        <section id="commentsSection" class="player-comments-section" style="display: none;" aria-label="Member comments"></section>

        <!-- Downloads Panel -->
        <section id="downloadsPanel" class="player-downloads-panel" style="display: none;">
          <div class="player-downloads-header">
            <h3>Download Options</h3>
            <button id="closeDownloads" class="player-close-panel-btn">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
          <div id="downloadLinks" class="player-download-links"></div>
        </section>
      </div>
    </div><!-- /.player-content -->
   </div><!-- /.player-primary -->

      <!-- Sidebar: Playlist -->
      <aside id="playlistSidebar" class="player-sidebar" style="display: none;" data-density="comfortable">
        <div class="player-sidebar-header">
          <div class="sidebar-header-info">
            <h3 id="playlistTitle">Episodes</h3>
            <span id="playlistCount" class="player-sidebar-count"></span>
          </div>
          <div class="sidebar-header-nav">
            <button id="sidebarPrevBtn" class="sidebar-nav-btn" disabled title="Previous episode (Shift+P)" aria-label="Previous episode">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button id="sidebarNextBtn" class="sidebar-nav-btn" disabled title="Next episode (Shift+N)" aria-label="Next episode">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
        </div>
        <div id="playlistItems" class="player-sidebar-items"></div>
      </aside>
   </div><!-- /.player-layout -->
  </main>

  <!-- Keyboard Shortcuts Help (triggered by \`?\`) -->
  <div id="shortcutsHelp" class="shortcuts-help" role="dialog" aria-modal="true" aria-label="Keyboard shortcuts" hidden>
    <div class="shortcuts-help-panel">
      <div class="shortcuts-help-header">
        <h3>Keyboard shortcuts</h3>
        <button type="button" id="shortcutsHelpClose" class="shortcuts-help-close" aria-label="Close shortcuts">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>
      <div class="shortcuts-help-grid">
        <div class="shortcut-row"><kbd>Space</kbd><kbd>K</kbd><span>Play / pause</span></div>
        <div class="shortcut-row"><kbd>F</kbd><span>Fullscreen</span></div>
        <div class="shortcut-row"><kbd>T</kbd><span>Theater mode</span></div>
        <div class="shortcut-row"><kbd>I</kbd><span>Picture in picture</span></div>
        <div class="shortcut-row"><kbd>C</kbd><span>Subtitles / captions</span></div>
        <div class="shortcut-row"><kbd>M</kbd><span>Mute / unmute</span></div>
        <div class="shortcut-row"><kbd>J</kbd><span>Back 10s</span></div>
        <div class="shortcut-row"><kbd>L</kbd><span>Forward 10s</span></div>
        <div class="shortcut-row"><kbd>&larr;</kbd><kbd>&rarr;</kbd><span>Seek &plusmn;5s</span></div>
        <div class="shortcut-row"><kbd>&uarr;</kbd><kbd>&darr;</kbd><span>Volume</span></div>
        <div class="shortcut-row"><kbd>&lt;</kbd><kbd>&gt;</kbd><span>Slower / faster</span></div>
        <div class="shortcut-row"><kbd>Shift</kbd> + <kbd>N</kbd><span>Next episode</span></div>
        <div class="shortcut-row"><kbd>Shift</kbd> + <kbd>P</kbd><span>Previous episode</span></div>
        <div class="shortcut-row"><kbd>?</kbd><span>This menu</span></div>
      </div>
    </div>
  </div>

  <!-- Resume Prompt (non-blocking) -->
  <div id="resumePrompt" class="resume-prompt" role="dialog" aria-label="Resume playback" style="display: none;">
    <div class="resume-prompt-content">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/></svg>
      <span id="resumeText">Resume from 0:00?</span>
      <button id="resumeBtn" class="resume-btn">Resume</button>
      <button id="resumeDismiss" class="resume-dismiss" aria-label="Dismiss">&times;</button>
    </div>
  </div>

  <!-- Site Settings (JSON_HEX_TAG prevents \`</script>\` injection breakout) -->
  <script id="siteSettingsConfig" type="application/json">${jsonForScript(settings)}</script>

  <!-- Theme Toggle Script -->
  <script>
    (function() {
      var themeToggle = document.getElementById('themeToggle');
      if (!themeToggle) return;

      function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
      }

      function toggleTheme() {
        var currentTheme = document.documentElement.getAttribute('data-theme');
        var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        setTheme(newTheme);
      }

      themeToggle.addEventListener('click', toggleTheme);

      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        var savedTheme = localStorage.getItem('theme');
        if (!savedTheme || savedTheme === 'system') {
          setTheme(e.matches ? 'dark' : 'light');
        }
      });
    })();
  </script>

<footer class="afc-footer" role="contentinfo">
  <div class="afc-footer-inner">
    <p class="afc-footer-note">
      Films are streamed from the
      <a href="https://archive.org" target="_blank" rel="noopener">Internet Archive</a>.
      Archive Film Club does not host this content.
    </p>
    <nav class="afc-footer-links" aria-label="Reporting and legal">
      <a href="https://help.archive.org/help/problems-or-errors/" target="_blank" rel="noopener">Report a problem</a>
      <a href="https://help.archive.org/help/how-do-i-request-to-remove-something-from-archive-org/" target="_blank" rel="noopener">Request removal / DMCA</a>
      <a href="https://archive.org/about/terms.php" target="_blank" rel="noopener">Terms of Use</a>
    </nav>
  </div>
</footer>

  <script>
  /* App-load watchdog (non-module) — see index.php for rationale. player.js
     sets window.__afcReady as soon as it runs; if that hasn't happened within
     the window we reveal a recovery message instead of a blank player. */
  (function () {
    setTimeout(function () {
      if (window.__afcReady) return;
      var msg = "We couldn’t load the player. Check your connection and try again, or open this item on archive.org.";
      var el = document.getElementById('error');
      if (el) {
        el.innerHTML = '<p>' + msg + '</p><p>'
          + '<button type="button" onclick="location.reload()">Retry</button> '
          + '<a href="https://archive.org/details/movies" target="_blank" rel="noopener">Open archive.org</a></p>';
        el.hidden = false;
      } else if (document.body) {
        var b = document.createElement('div');
        b.setAttribute('role', 'alert');
        b.style.cssText = 'position:fixed;left:16px;right:16px;top:16px;z-index:10000;background:#1d1d22;color:#f5f5f7;border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:16px;font:14px/1.5 system-ui,-apple-system,sans-serif';
        b.innerHTML = '<strong>Couldn’t load.</strong> ' + msg
          + ' <button type="button" onclick="location.reload()" style="margin-left:8px">Retry</button>'
          + ' <a href="https://archive.org/details/movies" target="_blank" rel="noopener" style="color:#8ab4f8">archive.org</a>';
        document.body.appendChild(b);
      }
    }, 8000);
  })();
  </script>

  <script type="module" src="player.js"></script>
</body>
</html>`;
}

// Minimal page for features that need the PHP backend (accounts, collections).
function buildUnavailableHtml(settings, colors, feature) {
  const siteName = settings.siteName || 'Archive Film Club';
  const light = colors.initialTheme === 'light';
  return `<!DOCTYPE html>
<html lang="en" data-theme="${esc(colors.initialTheme)}">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="Content-Security-Policy" content="${CSP}" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>${esc(feature)} - ${esc(siteName)}</title>
  <link rel="icon" type="image/svg+xml" href="${FAVICON_DATA_URI}" />
  <style>
    body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
           font: 15px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
           background: ${light ? '#ffffff' : '#0a0a0b'}; color: ${light ? '#111114' : '#f5f5f7'}; padding: 24px; box-sizing: border-box; }
    .card { max-width: 440px; padding: 32px; border-radius: 16px;
            background: ${light ? '#f4f4f6' : '#18181b'}; border: 1px solid ${light ? '#e2e2e8' : '#2a2a30'}; }
    h1 { font-size: 20px; margin: 0 0 12px; }
    p { margin: 0 0 12px; color: ${light ? '#4b4b55' : '#b3b3bd'}; }
    a { color: ${esc(colors.accentColor)}; font-weight: 600; text-decoration: none; }
  </style>
</head>
<body>
  <main class="card">
    <h1>${esc(feature)} isn't available in the desktop app</h1>
    <p>Accounts, comments and collections need the web version of ${esc(siteName)}, which runs on a server. Browsing, search, playback, bookmarks and watch history all work offline-first here.</p>
    <p><a href="/">&larr; Back to browsing</a></p>
  </main>
</body>
</html>`;
}

module.exports = { startServer };
