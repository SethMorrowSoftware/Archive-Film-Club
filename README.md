# Archive Film Club

A feature-rich web application for discovering and watching classic films from [Archive.org](https://archive.org). Built with PHP, vanilla JavaScript, and a modern dark UI.

## Features

### For Viewers
- **Search & Browse** - Full-text search across Archive.org's video library with 20+ collection filters and search suggestions
- **Video Player** - Dedicated player page with multi-episode playlists, a per-episode quality selector, theater mode, resume-from-where-you-left-off (including which episode of a series you were on), keyboard shortcuts (`?` lists them), lock-screen / hardware media controls, and an "Up Next" countdown that survives auto-advance. On desktop the playlist is a viewport-height rail beside the video that scrolls internally, so a 200-episode series never turns the page into a long scroll; on phones the video stays pinned under the header while you browse the list
- **User Accounts** - Register, log in, log out, password reset by email, optional email verification, "remember me" tokens, and account self-service
- **Bookmarks** - Save favorite videos for quick access; survives logout via guest session and merges into your account on signup
- **Collections** - Create personal collections of videos, reorder items, add per-item notes, and share publicly with a slug-based URL
- **Watch History** - Automatic progress tracking with resume support
- **Offline Support** - Service Worker keeps the app shell, recent search results, metadata and thumbnails available offline (pages with per-user content are deliberately never cached)
- **Theme Toggle** - Switch between dark and light mode
- **Responsive Design** - Works on desktop, tablet, and mobile

### For Admins
- **Dashboard** - Overview of site stats, configuration, and quick actions
- **Staff Picks** - Curate featured videos with drag-and-drop ordering and Archive.org search
- **Featured Sections** - Create custom content sections for the homepage
- **Site Settings** - Configure site name, default collection, sort order, and more
- **Appearance** - Customize brand colors, theme, and card styles with live preview
- **Display Options** - Toggle video card metadata, bookmarks, and watch history features
- **Maintenance** - Database status, one-click SQL backup/restore (with automatic pre-restore safety snapshot), cache refresh, schema migrations, and a type-to-confirm content reset

### Backend
- **Unified User Model** - Guests and accounts share one `users` table, distinguished by `is_guest` and a `role` of `guest|viewer|editor|admin`. Guest activity merges into the new account on signup
- **MySQL Database** - Full database support with JSON file fallback when the DB is unavailable
- **Multi-layer Caching** - Browser + service worker, then DB caches (search 30 min, metadata 24 hr, thumbnails 7 day), then JSON file fallback, fronted by an async DB-backed queue
- **RESTful API** - Complete API for search, metadata, bookmarks, history, collections, user, settings, and auth
- **Email** - SMTP (no PHPMailer dependency) with a PHP `mail()` fallback; host-header-poisoning safe
- **Cron Jobs** - Automated cache cleanup, warming, and async processing
- **Electron Desktop App** - Optional desktop client with Express backend

## Requirements

- **PHP** 7.4+ (8.x recommended) with the following extensions enabled:
  `pdo_mysql`, `mbstring`, `curl`, `openssl`, `gd`, `fileinfo`, `json`,
  `session`, `filter`, `hash`. On cPanel these are toggled under
  **Select PHP Version → Extensions**.
- **MySQL** 5.7+ or **MariaDB** 10.2+ (optional — falls back to JSON files)
- **Apache 2.4** with `mod_rewrite` and `mod_headers` (or Nginx with
  equivalent rules). `AllowOverride All` or at least `AllowOverride
  FileInfo Limit Indexes AuthConfig` must be enabled for the bundled
  `.htaccess` to take effect.
- **Node.js** 18+ — only needed for the optional Electron desktop app.
  **Not required for the PHP/cPanel deployment.**

## Quick Start

### 1. Download & Deploy

Upload the project files to your web server's document root or a subdirectory:

```bash
git clone https://github.com/morroware/videos.git
cd videos
```

### 2. Configure Environment

Copy the example environment file and update with your database credentials:

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```ini
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
```

### 3. Run the Installer

Visit `https://yourdomain.com/install.php` in your browser. The setup wizard will:

1. Test your database connection
2. Run the database migrations (001 → 007)
3. Create your admin account (and write the `.installed` lock immediately)
4. Migrate any existing JSON data (or skip on a fresh install)
5. Show you the post-install lock-down checklist

> The admin account is created only by the installer (step 3). Public
> signups through `register.php` are always created with the `viewer` role —
> promote further admins/editors from **Admin → Users**.

> **Important — do one of the following after setup:**
>
> 1. **Delete `install.php`** from the server (recommended), **or**
> 2. Open `.htaccess` and **uncomment the `<FilesMatch "^install\.php$">` deny block** near the bottom.
>
> The installer also refuses to re-run once an admin user exists in the
> database, but these web-server-level locks are the safest backstop.

### 4. Access the Admin Panel

Visit `https://yourdomain.com/admin.php` and log in with the credentials you created.

## Manual Database Setup

If you prefer to set up the database manually (e.g., via SSH), run the
migrations in order:

```bash
mysql -u your_db_user -p your_database_name < db/migrations/001_initial_schema.sql
mysql -u your_db_user -p your_database_name < db/migrations/002_permanent_local_cache.sql
mysql -u your_db_user -p your_database_name < db/migrations/003_user_accounts.sql
mysql -u your_db_user -p your_database_name < db/migrations/004_collections.sql
mysql -u your_db_user -p your_database_name < db/migrations/005_auth_throttle.sql
mysql -u your_db_user -p your_database_name < db/migrations/006_comments.sql
mysql -u your_db_user -p your_database_name < db/migrations/007_comment_user_set_null.sql
```

After the migrations are in place, create the first admin account. Public
signups via `register.php` are always `viewer`, so either run the installer
(`install.php`, step 3 creates the admin and writes the `.installed` lock),
or register an account and promote it directly in SQL:

```sql
UPDATE users SET role = 'admin' WHERE username = 'your-username' AND is_guest = 0;
```

Alternatively, set `ADMIN_PASSWORD` in `.env` as a break-glass fallback for
the admin panel. It **must** be a `password_hash()` value, not plaintext —
generate one with:

```bash
php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), \"\n\";"
```

Then unset `ADMIN_PASSWORD` once a real admin account exists. While both
DB admin and fallback are active the dashboard shows a warning banner.

## cPanel Hosting — production checklist

For cPanel shared hosting (e.g., Hostinger, Bluehost, GoDaddy, A2,
HostGator). Follow these steps in order; the installer also walks you
through the database half but the steps below cover what cPanel doesn't
automate.

1. **Set PHP to 7.4 or newer.** *cPanel → Select PHP Version*. Enable
   the extensions listed under **Requirements** above (especially `gd`
   and `fileinfo` — they're commonly disabled by default).
2. **Create the database via cPanel.** *cPanel → MySQL Databases* → make
   a database, then a user, then add the user to the database with
   **ALL PRIVILEGES**. Names get prefixed with your cPanel account name
   (`cpaneluser_filmclub`, `cpaneluser_filmuser`). The installer
   intentionally does NOT try to `CREATE DATABASE` — shared cPanel users
   don't have that privilege.
3. **Upload the project** to `public_html/` (or a subdirectory like
   `public_html/films/`). You can omit `electron/`, `node_modules/`,
   `package.json`, and `package-lock.json` — those are Electron-only.
4. **File permissions.** Most cPanel hosts run PHP as the owning user
   under suPHP/PHP-FPM, so default perms work — but verify:
   - The install root, `thumbnails/`, and `logs/` need to be **writable**
     by the PHP user (typically `0755` on dirs, `0644` on files). `logs/`
     also holds the break-glass login throttle state.
   - The installer auto-creates `logs/` and `cache/` paths it needs.
5. **Install SSL first**, then enable HTTPS enforcement. The root
   `.htaccess` ships with the force-HTTPS block **commented out**.
   Uncomment it (and the matching `Strict-Transport-Security` line)
   only after AutoSSL has issued a certificate and `https://yourdomain.com`
   loads cleanly. Doing it earlier 301-redirects users to a broken URL.
6. **Run the installer** at `https://yourdomain.com/install.php`.
   The installer self-locks after step 3 and the bundled `.htaccess`
   denies `install.php` by default — but always also delete the file
   once you're done. (To rerun the installer later you can temporarily
   comment out the deny block.)
7. **Configure email** by editing `.env`:
   ```ini
   APP_URL=https://yourdomain.com
   MAIL_FROM=noreply@yourdomain.com
   MAIL_FROM_NAME=Archive Film Club
   SMTP_HOST=smtp.example.com
   SMTP_PORT=587
   SMTP_USERNAME=...
   SMTP_PASSWORD=...
   SMTP_ENCRYPTION=tls
   ```
   `APP_URL` is critical — it pins canonical hostnames in password-reset
   links so a forged Host header can't poison them. Without SMTP, the
   PHP `mail()` fallback works on most shared hosts but emails are often
   spam-filtered.
8. **Add the cron jobs.** *cPanel → Cron Jobs*. Use the full paths the
   File Manager shows (right-click any file → Copy Path). `cache_cleanup`
   also reaps guest visitor rows idle for 30+ days, so the `users` table
   doesn't grow with every crawler hit. Suggested schedules:
   ```
   */5  * * * *  php /home/cpaneluser/public_html/cron/process_cache_queue.php
   0    * * * *  php /home/cpaneluser/public_html/cron/cache_cleanup.php
   */30 * * * *  php /home/cpaneluser/public_html/cron/cache_warmer.php
   ```
   If your plan doesn't include cron, the app still works — the cache
   just won't trim itself.
9. **Run migration 005** (auth throttling). The installer covers this
   automatically; if you ran an older version, hit *Step 2 → Create Tables*
   in the installer once to apply.
10. **Delete `api/diagnose.php`** once you've confirmed everything is
    working. The file exposes infrastructure info and is denied by
    `.htaccess` and admin-only by default, but the safest state is gone.

> **Subdirectory installs work out of the box.** All client and server URLs
> in this app are relative, the session cookie is install-scoped via
> `app_cookie_path()`, and the service worker registers from the install
> directory — so `/films/` and `/shorts/` on the same domain run fully
> independent installs without colliding.

## Project Structure

```
videos/
├── bootstrap.php              # Loaded first by every PHP entrypoint:
│                              #   .env loader, autoloader, hardened session
├── index.php                  # Search / browse homepage
├── player.php                 # Video player page
├── admin.php                  # Admin control panel
├── install.php                # Setup wizard (delete or htaccess-block after install)
├── account.php                # Account self-service page
├── login.php                  # Sign in
├── register.php               # Sign up
├── forgot-password.php        # Request a reset link
├── reset-password.php         # Reset via emailed token
├── verify-email.php           # Email-verification handler
├── collection.php             # Single collection view (owner or public-by-slug)
├── collections.php            # My-collections list
├── app.js                     # Homepage frontend entry
├── player.js                  # Player frontend entry
├── styles.css                 # Main stylesheet
├── player-styles.css          # Player styles
├── auth-styles.css            # Login / register / reset styles
├── sw.js                      # Service worker
│
├── api/                       # REST API endpoints
│   ├── search.php             # Video search
│   ├── metadata.php           # Video metadata
│   ├── thumbnail.php          # Thumbnail proxy/cache
│   ├── bookmarks.php          # User bookmarks
│   ├── history.php            # Watch history
│   ├── collections.php        # Collections CRUD + public view
│   ├── user.php               # User info, preferences, search history
│   ├── recommendations.php    # Staff picks
│   ├── sections.php           # Featured sections
│   ├── settings.php           # Site settings
│   ├── stats.php              # Analytics
│   ├── cache.php              # Cache management (admin-gated writes)
│   ├── diagnose.php           # System diagnostics (admin-gated)
│   └── auth/                  # Account authentication endpoints
│       ├── login.php
│       ├── logout.php
│       ├── register.php
│       ├── me.php
│       ├── profile.php
│       ├── change-password.php
│       ├── forgot-password.php
│       └── reset-password.php
│
├── partials/                  # Shared server-rendered fragments
│   └── header.php             # Site header partial
│
├── db/                        # Database layer
│   ├── Database.php           # PDO singleton + helpers
│   ├── config.php             # Configuration loader
│   └── migrations/            # 001 → 007 SQL migration files
│
├── services/                  # Business logic (PSR-4 autoloaded)
│   ├── ArchiveOrgService.php  # Archive.org API client + cache glue
│   ├── SettingsService.php    # Site settings (DB + JSON fallback)
│   ├── LocalStorageService.php# JSON-file fallback layer
│   ├── AdminAuthService.php   # Legacy shim (pre-migration-003 installs)
│   ├── UserService.php        # Legacy session-only user record
│   ├── Http/
│   │   └── ApiController.php  # Base class for api/*.php handlers
│   ├── Auth/
│   │   └── UserAuthService.php# Register, login, password reset, email verify
│   ├── User/
│   │   ├── UserContext.php    # Resolves the current request to a user/guest
│   │   ├── UserRepository.php # users table CRUD + guest-merge
│   │   ├── BookmarkService.php
│   │   ├── WatchHistoryService.php
│   │   └── SearchHistoryService.php
│   ├── Collection/
│   │   └── CollectionService.php
│   └── Mail/
│       └── MailService.php    # SMTP or PHP mail() fallback
│
├── cache/                     # Cache management
│   ├── CacheManager.php       # Cache orchestrator
│   ├── SearchCache.php        # Search result cache
│   ├── MetadataCache.php      # Metadata cache
│   └── ThumbnailCache.php     # Thumbnail cache
│
├── cron/                      # Scheduled tasks
│   ├── cache_cleanup.php      # Expire old cache entries
│   ├── cache_warmer.php       # Pre-warm popular queries
│   └── process_cache_queue.php# Async cache processing
│
├── admin/                     # Admin panel internals
│   ├── controllers/           # Bootstrap + data loading
│   ├── views/                 # PHP templates (panels)
│   └── assets/                # admin.css / admin.js
│
├── src/js/                    # Frontend ES modules
│   ├── config.js              # App configuration
│   ├── components/            # UI components (AuthNav, Toast, …)
│   ├── services/              # API + data services
│   ├── player/                # Player modules
│   └── utils/                 # Helpers, icons, URL manager
│
├── electron/                  # Desktop app (optional)
│   ├── main.js                # Electron main process
│   └── server.js              # Express backend
│
├── tests/                     # Dependency-free checks
│   ├── smoke.php              # Boot + class-link + unit checks (CI, no DB)
│   └── browser/               # Playwright regression test for the player page
│
├── .env.example               # Environment template
├── .htaccess                  # Apache rewrite rules + security denies
└── package.json               # Node.js dependencies (Electron only)
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|---|---|---|
| `DB_HOST` | `localhost` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_DATABASE` | - | Database name |
| `DB_USERNAME` | - | Database user |
| `DB_PASSWORD` | - | Database password |
| `CACHE_SEARCH_TTL` | `1800` | Search cache duration (seconds) |
| `CACHE_METADATA_TTL` | `86400` | Metadata cache duration (seconds) |
| `CACHE_THUMBNAIL_TTL` | `604800` | Thumbnail cache duration (seconds) |
| `CACHE_SETTINGS_TTL` | `3600` | Settings cache duration (seconds) |
| `ENABLE_THUMBNAIL_CACHING` | `true` | Enable local thumbnail caching |
| `ENABLE_SEARCH_CACHING` | `true` | Enable search result caching |
| `ENABLE_USER_SESSIONS` | `true` | Enable user session tracking |
| `ENABLE_API_LOGGING` | `true` | Enable API request logging |
| `APP_URL` | auto | Base URL used in email links (e.g. password reset). Set explicitly in production |
| `MAIL_FROM` | - | From address for outgoing mail |
| `MAIL_FROM_NAME` | site name | From name for outgoing mail |
| `SMTP_HOST` | - | SMTP server (leave blank to use PHP mail()) |
| `SMTP_PORT` | `587` | SMTP port |
| `SMTP_USERNAME` | - | SMTP auth username |
| `SMTP_PASSWORD` | - | SMTP auth password |
| `SMTP_ENCRYPTION` | `tls` | `tls` (STARTTLS) or `ssl` |
| `ADMIN_PASSWORD` | - | Break-glass admin fallback used only when the DB is unavailable. Leave empty post-install |
| `THUMBNAIL_CACHE_PATH` | `<install>/thumbnails` | Filesystem path for cached thumbnail files |
| `LOG_PATH` | `<install>/logs` | Filesystem path for app log files |

### Cron Jobs (Optional)

Set up cron jobs for automated cache management:

```crontab
# Clean expired cache entries + idle guest rows every hour
0 * * * * php /path/to/videos/cron/cache_cleanup.php

# Warm cache for popular searches daily
0 3 * * * php /path/to/videos/cron/cache_warmer.php

# Process async cache queue every 5 minutes
*/5 * * * * php /path/to/videos/cron/process_cache_queue.php
```

## Maintenance Notes

- Keep `README.md` as the canonical documentation source for setup and operations.
- If behavior changes, update this README in the same pull request as the code change.

## API Reference

All API endpoints are located under `/api/` and return JSON responses. All
handlers extend `services/Http/ApiController.php`, which sets the JSON
content type, gates auth/admin where needed, and provides input
sanitizers.

### Public + viewer endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/search.php` | GET | Search videos (`q`, `collection`, `sort`, `page`, `rows`); page-1 searches are recorded in the signed-in user's search history |
| `/api/metadata.php` | GET | Get video metadata (`id`); cache hits and misses return the same normalized shape |
| `/api/metadata-batch.php` | GET | Metadata for up to 50 `ids` (comma-separated); at most 5 uncached items are fetched synchronously, the rest are queued and returned as stubs |
| `/api/thumbnail.php` | GET | Get/cache thumbnail (`id`) |
| `/api/recommendations.php` | GET | Get staff picks |
| `/api/sections.php` | GET | Get featured sections |
| `/api/settings.php` | GET | Public site settings, projected through the same allow-list the admin can write (operational cache knobs never leave the server) |
| `/api/bookmarks.php` | GET/POST/DELETE | Manage bookmarks for the current user/guest |
| `/api/history.php` | GET/POST | Watch history & progress for the current user/guest |
| `/api/collections.php` | GET/POST | Collections CRUD; supports public lookup by `username` + `slug` |
| `/api/user.php` | GET/POST | User info, preferences, and recent search history |
| `/api/stats.php` | GET | Site analytics |

### Authentication endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/auth/register.php` | POST | Create an account (always the `viewer` role; admins are created by the installer or promoted in Admin → Users) |
| `/api/auth/login.php` | POST | Sign in; issues a session and optional remember-me token |
| `/api/auth/logout.php` | POST | Invalidate session + remember-me token |
| `/api/auth/me.php` | GET | Return the current user (used to hydrate the header `AuthNav`) |
| `/api/auth/profile.php` | POST | Update display name / email (re-triggers email verification on change) |
| `/api/auth/change-password.php` | POST | Change password while signed in |
| `/api/auth/forgot-password.php` | POST | Send password-reset email |
| `/api/auth/reset-password.php` | POST | Consume a reset token and set a new password |

### Admin endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/api/cache.php` | GET/POST | Cache stats (GET); `queue` is open, `cache_immediate`/`cache_single` are capped per session (25 items / 10 min, then 429), destructive actions require `admin`/`editor` role |
| `/api/diagnose.php` | GET | System diagnostics; full `admin` only |
| `/api/settings.php` | POST | Update site settings; full `admin` only (editors curate but can't rebrand). Partial updates: only keys present in the body are written |
| `/api/admin/metrics.php` | GET/POST | Dashboard metrics, user-role management, comment moderation (role changes are full-admin-only) |
| `/api/admin/maintenance.php` | GET/POST | DB status, SQL backup/restore, cache refresh, content reset; full-admin-only with type-to-confirm |

## Security

The app ships with a verified security baseline:

- **Prepared statements everywhere** via `db/Database.php`; no SQL string
  concatenation
- **Output escaping** with `htmlspecialchars(..., ENT_QUOTES)` on every
  template branch
- **Hardened sessions** — `httponly`, `secure` (when HTTPS), `SameSite=Lax`,
  and an install-scoped cookie `path` so sibling subdirectory installs
  don't share sessions
- **Password storage** with `password_hash(PASSWORD_DEFAULT)`; remember-me,
  password-reset, and email-verification tokens are SHA-256 hashed at rest
  and only the raw token leaves the server (in the cookie or email link)
- **`session_regenerate_id(true)`** on login, register, and password reset
- **SSRF pin** — the metadata + thumbnail proxies refuse to fetch anything
  outside `archive.org`
- **Open-redirect whitelist** via `afc_safe_next()` (server + client)
- **`.htaccess` defenses** — HTTPS force (opt-in after SSL), deny on `.env`,
  `Database.php`, `config.php`, `*.md`, `*.sql`, `*.log`, the `.git/`
  directory, `admin/views/` + `admin/controllers/`, no directory indexing,
  a lenient `Content-Security-Policy`, and standard `X-Content-Type-Options`
  / `X-Frame-Options` / `Referrer-Policy` / `Permissions-Policy` headers
- **Remember-me tokens are single-use** — each auto-login rotates the token,
  regenerates the session id, and rotates the CSRF token
- **Break-glass admin login is throttled** without the database (file-backed,
  5 failures → 15-minute lockout) and can save settings / picks / sections to
  the JSON recovery files while MySQL is down
- **Client-side HTML sanitizer** for archive.org descriptions is a strict
  allow-list parsed in an inert `DOMParser` document (no `on*`, no
  `javascript:`/`data:` links, no `svg`/`iframe`/`form`)
- **Installer dual-guard** — refuses to run once a `.installed` marker
  exists or once an admin row is present, and `chmod`s `.env` to `0600`

> **Post-install hardening:** delete `install.php` from the server *or*
> uncomment the `<FilesMatch "^install\.php$">` deny block near the
> bottom of `.htaccess`. Unset `ADMIN_PASSWORD` in `.env` once you have a
> real admin account.

Known gaps accepted for beta: the `Content-Security-Policy` still allows
`'unsafe-inline'` scripts/styles (the admin panel and brand-colour `<style>`
blocks depend on it), and `Strict-Transport-Security` ships commented out
until you have confirmed HTTPS works. (CSRF protection —
`csrf_token()`/`csrf_verify()` in `bootstrap.php`, enforced by
`ApiController::requireCsrf()` on every non-GET endpoint — and
per-IP/per-account login rate-limiting — `UserAuthService::isLoginThrottled()`
with migration `005_auth_throttle.sql` — are both implemented.)

## Testing

There is no build step, so the checks stay dependency-free:

```bash
bash scripts/check-syntax.sh all   # php -l + ESM-aware node --check on every file
php tests/smoke.php                # boots bootstrap.php, links every class, unit-checks
                                   # the SQL splitter / colour guard — no DB needed
```

Both run in CI (`.github/workflows/ci.yml`) on PHP 7.4 and 8.3.

The player also has a browser regression test that drives the real page in
Chromium with archive.org and the local API mocked at the network layer. It
covers the click-to-pause / keyboard double-toggle bugs, the playlist rail
layout at desktop and phone widths, theater mode, per-episode quality
switching, series resume and `?t=` deep links. It needs PHP (it starts its own
`php -S`) and Playwright's Chromium:

```bash
npm i --no-save playwright && npx playwright install chromium   # once
node tests/browser/player.test.js
```

CI runs it too (the `browser` job), so a layout or playback regression fails
the pull request.

## Desktop App (Electron)

An optional Electron wrapper is included for running as a desktop application:

```bash
# Install dependencies
npm install

# Run in development mode
npm run dev

# Run in production mode
npm start
```

## Tech Stack

- **Backend:** PHP 7.4+, MySQL 5.7+, PDO
- **Frontend:** Vanilla JavaScript (ES6 modules), HTML5, CSS3
- **Styling:** CSS Custom Properties, Grid, Flexbox
- **Caching:** Multi-layer (database + local storage + service worker)
- **Desktop:** Electron 33, Express 4
- **External API:** Archive.org Advanced Search API

## License

This project is provided as-is for personal and educational use.
