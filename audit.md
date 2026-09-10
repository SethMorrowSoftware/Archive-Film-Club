# Archive Film Club — Full Project Audit

**Audit date:** 2026-05-28
**Scope:** Complete codebase — PHP backend (bootstrap, API, services, cache, cron, DB, installer, admin), frontend JS (`app.js`, `player.js`, `sw.js`, `src/js/**`), server-rendered pages, styles, and the Electron wrapper.
**Method:** Every source file was read and analyzed. Findings marked **[verified]** were confirmed by tracing the exact code path; findings marked **[flagged]** are credible from analysis and have a clear remediation but were not exercised against a live DB.

This document records **bugs and risks**. A separate **UI/UX** section at the end records the design review and the polish applied in this pass.

---

## Executive summary

The project is, on the whole, carefully built: prepared statements everywhere, consistent server-side output escaping, hardened sessions, CSRF tokens on every state-changing endpoint, SSRF-pinned proxies, hashed tokens at rest, and an installer with a dual lock. The README's "Security" section is largely accurate (one drift noted below).

However, the audit found a **small number of high-impact bugs that break core features today**, plus a privilege-escalation path:

1. **`LIMIT ?` parameter binding is broken across the app** (native prepared statements + string binding). This silently breaks watch-history lists, recent searches, the public-collections list, popular-search stats, **and the entire cron cache pipeline**. — *Critical, verified.*
2. **An `editor` can promote themselves (or anyone) to `admin`.** — *Critical, verified.*
3. **Reflected XSS via the `?video=` URL parameter** on the player page. — *High, verified.*

These three should be fixed before any public launch. The rest are graded below.

> **Update:** the three headline issues (C1, C2, H1) were **fixed in this PR** at the maintainer's request. Each carries a "Status — FIXED" note below. All other findings remain documented for triage.

---

## CRITICAL

### C1. `LIMIT ?` / `OFFSET ?` bound parameters throw under native prepared statements **[verified]**
**Where:** root cause `db/Database.php:38` + `:86`; broken call sites:
`services/User/WatchHistoryService.php:21`, `services/User/SearchHistoryService.php:29`,
`services/Collection/CollectionService.php:121`, `services/ArchiveOrgService.php:768`,
`cache/CacheManager.php:204`, `:375`, `:464`.

`Database` connects with `PDO::ATTR_EMULATE_PREPARES => false` (native server-side prepares) and `query()` runs `$stmt->execute($params)`, which binds **every** parameter as a string (`PDO::PARAM_STR`). MySQL's native protocol rejects a quoted string in a `LIMIT`/`OFFSET` clause:

```
SQLSTATE[42000]: Syntax error or access violation: 1064 ... near "'50'"
```

So every query that binds `?` into `LIMIT`/`OFFSET` throws at runtime. Concretely this breaks:
- **Watch history list** — `WatchHistoryService::recent()` → `api/history.php?action=list`
- **Recent searches** — `SearchHistoryService` → `api/user.php?action=searches`
- **Public collections list** — `CollectionService::listPublic()` → `collections.php` / `api/collections.php`
- **Popular searches** — `ArchiveOrgService:768` → `api/stats.php?action=popular`
- **The async cache queue + warmer + stale-refresh** — `CacheManager::getStaleSearches()/getStaleMetadata()/getPendingCacheItems()` → all three cron jobs throw on their first query.

Depending on the caller, the failure surfaces either as an HTTP 500 (global handler) or as a silently-empty result (callers that `try/catch` and return `[]`). Either way the feature is non-functional.

**Fix (pick one):**
- Bind the limit/offset with an explicit integer type (`$stmt->bindValue($i, (int)$limit, PDO::PARAM_INT)`), **or**
- Validate-and-interpolate the integer directly (`"... LIMIT " . (int)$limit`) as `MetricsService` already does, **or**
- Set `PDO::ATTR_EMULATE_PREPARES => true` in `Database::connect()` (simplest; makes all `LIMIT ?` work, with the usual emulation trade-offs).

> Note: `MetricsService` and `CommentService` interpolate `(int)`-cast limits/offsets directly, so they are **not** affected — confirming interpolation is the project's already-working pattern.

**Status — FIXED in this PR.** All 7 call sites now interpolate an `(int)`-cast `LIMIT`/`OFFSET` (and the one `INTERVAL ? DAY`) directly and drop the value from the bound-params array — matching the existing `MetricsService` pattern. Provably injection-safe (the interpolated values are integer casts). `grep` confirms no bound `?` remains in any `LIMIT`/`OFFSET`, and all files lint clean. The native-prepares mode (`EMULATE_PREPARES => false`) was intentionally left unchanged to avoid altering global query/return-type behavior.

### C2. Privilege escalation — an `editor` can grant themselves `admin` **[verified]**
**Where:** `api/admin/metrics.php:32, 98-112`; `services/Admin/MetricsService.php:383-388`.

`requireAdmin()` deliberately admits both `admin` **and** `editor` (`ApiController.php:201`). The `set-role` POST action's only guard is:

```php
if ($userId === (int)$admin['id'] && $role !== 'admin') { /* block self-demote */ }
```

This blocks demoting *yourself*, but an `editor` can POST `{action:'set-role', user_id:<own id>, role:'admin'}` — the second clause (`$role !== 'admin'`) is false, so the guard does not fire — or target any other account. `MetricsService::setRole()` performs the `UPDATE` with no caller-role check. Result: the lower-trust `editor` role (intended for content curation) can seize full `admin`.

**Fix:** Gate user-role mutations behind a strict admin-only check (`if (($admin['role'] ?? '') !== 'admin') $api->error('Admin only', 403);`) before `set-role`, and refuse to assign `admin` from a non-admin caller.

**Status — FIXED in this PR.** `set-role` now rejects any caller whose role isn't exactly `admin` (403). Editors retain comment moderation (`moderate`/`resolve-reports`) but can no longer touch user roles. *(Note: the related last-admin lockout, H4, was left documented — the maintainer scoped this PR to C1/C2/H1.)*

---

## HIGH

### H1. Reflected XSS via the `?video=` parameter on the player page **[verified]**
**Where:** `player.js:583/592` (source) → `player.js:1223/1227/1247` (`buildDownloadLinks`, written via `innerHTML` at `:1256`); also `src/js/player/PlayerPlaylist.js:103/113` and `:308/358` (series cover + episode thumbnails).

`this.videoId = params.get('video')` is taken raw from the URL and interpolated, unescaped, into HTML attribute strings assigned via `innerHTML`:

```js
const url = `https://archive.org/download/${this.videoId}/${encodeURIComponent(file.name)}`;
return `<a href="${url}" ...>`;            // breaks out of the href attribute
...
this.downloadLinks.innerHTML = html;
```

A crafted link such as `player.php?video="><img src=x onerror=alert(document.cookie)>` injects markup. The download panel and the multi-episode playlist both render `this.videoId` this way.

**Exploitability:** `buildDownloadLinks` runs only after the metadata fetch succeeds, and the API sanitizes the id (`sanitizeArchiveId` strips `"<>`). So the attacker must own an archive.org item whose *sanitized* name matches the stripped payload while the raw `?video=` value still carries the breakout characters — achievable because archive.org item identifiers are freely creatable. The fix is trivial regardless, and a raw URL parameter must never reach `innerHTML` unescaped.

**Fix:** Escape `this.videoId`/`id` with `escapeHtml()` before interpolation (or build the anchors/images with `document.createElement` and assign `.href`/`.src` as properties). `helpers.js` already exports `escapeHtml`.

**Status — FIXED in this PR.** Two layers: (1) `parseUrlAndLoad()` now sanitizes the `?video=` param to `[a-zA-Z0-9_.-]` at the source (the same charset the server's `sanitizeArchiveId` enforces), which neutralizes every downstream sink — download links, the playlist cover/thumbnail (`identifier` is derived from `videoId`), and the share link; (2) defense-in-depth `escapeHtml()` was added at the download-link `href` sinks (`player.js`) and the playlist `<img src>` sinks (`PlayerPlaylist.js`). `node --check` passes on both files.

### H2. Public registration auto-promotes the first account to `admin`, with no install gate and a race **[verified]**
**Where:** `services/Auth/UserAuthService.php:78-94`; `register.php` / `api/auth/register.php`.

Registration is publicly open and independent of `install.php`. `register()` does a non-atomic `SELECT COUNT(*) FROM users WHERE is_guest = 0` and grants `role='admin'` when the count is 0. Two problems:
- **No install gate:** if migrations are applied but the admin account hasn't been created yet (e.g. DB deployed before completing installer step 3), the **first stranger to hit `/register.php` becomes the site admin**.
- **Race (TOCTOU):** two simultaneous registrations on a fresh install can both read 0 and both be created as `admin`.

**Fix:** Create the admin only via `install.php` (always register web signups as `viewer`), or wrap the count+insert in a transaction with a row lock / one-shot `install_state` flag so the bootstrap admin can't be won by a public registrant or a race.

**Status — FIXED in PR #62.** Chose the install-gate approach: `register()` now always assigns `role='viewer'` (the COUNT-then-insert auto-promotion is gone). The bootstrap admin is created solely by `install.php`'s direct `INSERT` (`role='admin'`), which is independent of `register()`, so the installer flow is unchanged. Closes both the install-gate hole and the TOCTOU race.

### H3. Admin "searches" metrics are silently always zero (wrong column) **[verified]**
**Where:** `services/Admin/MetricsService.php:121-124` (`contentTotals` `searches_7d`) and `:181-184` (`dailySeries('searches')`).

Both query `FROM search_history WHERE created_at > ...`, but `search_history` defines only `searched_at` (migration `001:201`). The queries throw `Unknown column 'created_at'`, which `intQuery`/`dailySeries` catch and convert to `0` / an empty series. The admin dashboard's 7-day search count and the searches chart are therefore **permanently zero** with no error shown — a silent data-correctness bug.

**Fix:** Use `searched_at` in both queries.

**Status — FIXED in PR #62.** Both queries (`contentTotals` `searches_7d` and `dailySeriesSql('searches')`) now select/filter on `searched_at`. The `created_at` references on `users`/`video_comments` (which do have that column) were left untouched.

### H4. Last-admin lockout via `set-role` **[verified]**
**Where:** `api/admin/metrics.php:98-112`; `services/Admin/MetricsService.php:383-388`.

`set-role` blocks demoting *your own* admin role, but nothing stops an admin from demoting the **only other** admin (or any path leaving zero admins). `AdminAuthService::deleteUser` has a last-admin guard; the role-change path does not. The org can lock itself out of all admin access with no UI recovery.

**Fix:** Before demoting any `admin`, count remaining admins and refuse if the change would reach zero.

**Status — FIXED in PR #62.** `MetricsService::setRole()` now counts remaining `admin`s and throws (API → 400) when the change would remove the last one. The guard lives in the service so it covers a second admin or a stale admin session, not just the API's self-demote block.

### H5. SMTP envelope/header `To:` not CRLF-sanitized (defense-in-depth gap) **[flagged]**
**Where:** `services/Mail/MailService.php:188-191`.

`$subject` and `$from` are passed through `stripCrlf()`, but `$to` is written into `RCPT TO:<$to>` and the `To:` header relying solely on the upstream `FILTER_VALIDATE_EMAIL`. That validator does reject embedded newlines, so this is not currently exploitable — but `$to` is the one field without the defense-in-depth strip the others get.

**Fix:** Run `$to` (and every value placed into an SMTP command or header) through `stripCrlf()` too.

**Status — FIXED in PR #62.** `$to` is now `stripCrlf()`'d at the top of `sendViaSmtp()`, before it reaches `RCPT TO:<$to>` and the `To:` header.

---

## MEDIUM

### M1. Cache queue claim is non-atomic + stuck-item leak **[flagged]**
**Where:** `cache/CacheManager.php:441-474`; `services/LocalStorageService.php:257-294`.
The queue is claimed with a `SELECT ... WHERE status='pending'` followed by a separate `UPDATE ... status='processing'` — no `SELECT ... FOR UPDATE`/`GET_LOCK`/atomic claim. Two overlapping cron runs (likely on shared hosting with a short `max_execution_time` and a `*/5` schedule) process the same rows, defeating dedup. Worse, a run killed mid-item leaves rows stuck in `processing` forever — `getPendingCacheItems` only selects `pending`, so they never retry. (Largely moot until **C1** is fixed, since these queries currently throw.)
**Fix:** Claim atomically (`UPDATE cache_queue SET status='processing', attempts=attempts+1 WHERE id=? AND status='pending'` and only proceed when `rowCount()===1`); add a reaper that resets stale `processing` rows. Add a `GET_LOCK`/flock overlap guard to `cron/process_cache_queue.php`.

**Status — FIXED in PR #63.** `markQueueItemProcessing()` now claims atomically (proceeds only on `rowCount()===1`) and stamps `processed_at`; `processQueue()` skips a lost claim. `reapStuckQueueItems()` re-queues rows stuck in `processing` (or `NULL processed_at`) past a window, failing them once `attempts` is exhausted. The cron takes a per-database `GET_LOCK` (released in `finally`, auto-released on death). No schema change needed.

### M2. Migration 003 is not safely re-runnable for incremental upgrades **[verified]**
**Where:** `db/migrations/003_user_accounts.sql:30-41`; runner `install.php:217-238`.
Migration 003 is a single multi-clause `ALTER TABLE users` (8 columns + 3 keys). The installer splits on `;` (so it's one statement) and swallows "Duplicate column" errors. A fresh first run applies all clauses atomically (fine). But because MySQL aborts the whole ALTER on the first already-existing clause, **adding a new clause to this migration later and re-running will not apply it** — the ALTER dies on `ADD COLUMN username` and is swallowed. This contradicts the file's own "Safe to run … Re-runnable" comment.
**Fix:** Split into one `ALTER TABLE users ADD COLUMN …;` per clause (the one-per-statement style migration 002 already uses).

**Status — FIXED in PR #63.** The multi-clause ALTER is now 11 separate `ALTER TABLE users ADD …;` statements (each keeps its `AFTER` clause, so fresh-install column order is unchanged). Each clause now applies/skips independently on re-run.

### M3. "Permanent cache" depends on migration 002's `MODIFY` having succeeded **[verified]**
**Where:** `db/migrations/001_initial_schema.sql:100,125` (`expires_at TIMESTAMP NOT NULL`) vs `002:42,100` (`MODIFY … TIMESTAMP NULL`) vs `cache/CacheManager.php:281-283` (writes `expires_at = NULL`).
Permanent cache rows are written with `expires_at = NULL`. That only works if migration 002 made the column nullable. If 002 didn't fully apply, inserting `NULL` either errors (strict mode) or coerces to `0000-00-00`/`CURRENT_TIMESTAMP` (non-strict), making every "permanent" row instantly expired.
**Fix:** Declare `expires_at TIMESTAMP NULL DEFAULT NULL` directly in migration 001, and verify 002's `MODIFY` post-install.

**Status — FIXED in PR #63.** Both cache tables in migration 001 now declare `expires_at TIMESTAMP NULL DEFAULT NULL`, so fresh installs are correct even if 002's `MODIFY` didn't apply. `expires_at` isn't the first TIMESTAMP in either table, so there's no implicit `DEFAULT CURRENT_TIMESTAMP` interaction. Migration 002's `MODIFY` is left in place (now a harmless no-op on fresh installs).

### M4. Renaming a collection regenerates its slug and dead-links shared URLs **[flagged]**
**Where:** `services/Collection/CollectionService.php:185-215`.
`update()` regenerates `slug` from the new name on every rename, so any previously-shared `/c/{username}/{old-slug}` link 404s — defeating the purpose of shareable collections.
**Fix:** Keep the existing slug on rename (only mint a new one on explicit request), or store slug history and 301 old → current.

**Status — FIXED in PR #64.** `update()` no longer regenerates the slug on rename; it stays as minted at creation, so previously-shared `/c/{username}/{slug}` links keep resolving.

### M5. Lucene query injection / unvalidated `collection` into the archive.org query **[flagged]**
**Where:** `services/ArchiveOrgService.php:424-461`; reached from `api/search.php:17-18`.
User-controlled `q` and `collection` are concatenated raw into the Archive.org Lucene query (`AND collection:{...}`). `http_build_query` URL-encodes the result (so this is **not** SSRF and not DB injection), but a caller can still alter query semantics (inject `AND`/`OR`/field filters, break out of the intended collection scope). `sort` is also passed through unvalidated.
**Fix:** Allow-list `collection` against `[a-zA-Z0-9_.-]`, validate `sort` against the known sort map, and quote user terms.

**Status — FIXED in PR #65.** `collection` and `mediatype` are allow-listed to `[a-zA-Z0-9_.-]` before interpolation, and `sort` is resolved strictly through the known map (unknown → default, never verbatim). The free-text `q` is left as-is (quoting it would break legitimate multi-word search; it reaches archive.org URL-encoded, not the DB).

### M6. CORS allow-list is derived from the client-controlled `Host` header **[flagged]**
**Where:** `api/index.php:13-27`.
`$allowedOrigins` is built from `HTTP_HOST` and a matching `Origin` is reflected into `Access-Control-Allow-Origin`. On a host that doesn't pin `Host`, this can be coerced. Impact is limited (this is the info/router endpoint, no credentials echoed), but the pattern is unsafe and inconsistent with the hardened `api/.htaccess`.
**Fix:** Drop the dynamic list; compare `parse_url($origin, PHP_URL_HOST)` against `safe_host()`/`APP_URL` only.

**Status — FIXED in PR #65.** `api/index.php` now requires bootstrap and compares the Origin host against `safe_host()` (plus literal localhost/127.0.0.1 for dev); the client-controlled `HTTP_HOST` entry is gone. Adds `Vary: Origin`.

### M7. `forgot-password` has no per-IP throttle **[flagged]**
**Where:** `api/auth/forgot-password.php`; `services/Auth/UserAuthService.php:304-342`.
Reset tokens are capped at 3/hr **per account**, but nothing limits how many distinct emails one client submits. An attacker can loop the endpoint over an email list to generate outbound mail volume and probe response timing. (Login *is* throttled per-IP; the reset flow is not.)
**Fix:** Apply the same per-IP `auth_attempts`-style throttle to `forgot-password`.

**Status — FIXED in PR #65.** `startPasswordReset()` enforces a per-IP cap (10/hr) recorded in `auth_attempts` behind a `pwreset` marker (`success=1`, so it never counts toward the failed-login throttles). Over the cap returns null — identical to "email unknown".

### M8. Password-reset email enumeration via timing **[flagged]**
**Where:** `services/Auth/UserAuthService.php:304-342`.
The "email unknown" branch returns immediately, while the "email exists" branch does extra DB work + token insert + SMTP send. The response-time delta lets an attacker enumerate registered emails despite the identical user-facing message.
**Fix:** Normalize work/timing across both branches (or enqueue mail asynchronously).

**Status — FIXED in PR #65.** `forgot-password.php` now emits the identical generic response before any account-specific work and, where `fastcgi_finish_request` is available (PHP-FPM / LiteSpeed), closes the connection before doing the lookup + SMTP send — so response time no longer depends on whether the email is registered.

### M9. Legacy `AdminAuthService::authenticate` login path is unthrottled **[flagged]**
**Where:** `services/AdminAuthService.php:24-57`.
The new `UserAuthService::login` is rate-limited, but the legacy `admin_users` auth path has no throttling and no `auth_attempts` logging, giving an attacker an unthrottled brute-force channel where it's still wired.
**Fix:** Route the legacy path through the same throttle, or deprecate/remove it post-migration.

**Status — FIXED in PR #65.** `AdminAuthService::authenticate()` now applies a per-IP throttle (same window/cap and `auth_attempts` table as the unified login), throwing when tripped; `AdminBootstrap` catches it and shows the friendly "too many attempts" message.

### M10. Service worker token / post-login CSRF staleness **[verified]**
**Where:** `src/js/services/ApiService.js:12`, `src/js/services/CollectionService.js:21`, `src/js/services/AuthService.js:180`; server rotates token at `UserAuthService.php:173`.
`login()` rotates `$_SESSION['csrf_token']` server-side, but the client's `<meta name="csrf-token">` and the per-module cached `_csrfToken` are not refreshed without a full navigation. A write performed after login *without* navigating would send the stale token and 403. Currently masked because the auth pages navigate (redirect) after login.
**Fix:** Centralize one token cache and invalidate it on auth-state change, or always re-read the meta tag for state-changing requests.

**Status — FIXED in PR #65.** Added a single CSRF token source (`src/js/utils/csrf.js`) used by ApiService/CollectionService/AuthService. `login`/`register`/`logout` now return the rotated server token and the client adopts it (updating both the shared cache and the `<meta>` tag), so a state-changing request after a no-navigation auth change no longer 403s.

### M11. `progress_percent` is not clamped **[flagged]**
**Where:** `services/User/WatchHistoryService.php:35-48`.
`$percent = ($currentTime / $duration) * 100` with no `[0,100]` clamp and no rejection of negative/over-duration inputs. A client can store >100% (or negative), skewing "continue watching" UI and engagement metrics.
**Fix:** `max(0, min(100, $percent))` and reject negative `currentTime`/`duration`.

**Status — FIXED in PR #64.** `updateProgress()` clamps `currentTime`/`duration` to ≥0 and bounds the derived `progress_percent` to `[0,100]`.

### M12. Admin moderation `delete` doesn't decrement parent `reply_count` **[flagged]**
**Where:** `services/Comments/CommentService.php:365-367` vs self-service `delete()` at `:184-189`.
Self-service `delete()` decrements the parent's `reply_count`; the admin `moderate(…, 'delete')` path soft-deletes without decrementing it. The displayed reply count then overstates visible replies and "show more replies" can over-report.
**Fix:** Decrement the parent `reply_count` in the moderation delete branch too.

**Status — FIXED in PR #64.** `moderate(…, 'delete')` now decrements the parent's `reply_count` when removing a reply, matching self-service `delete()`, and guards on the prior status so a repeat delete can't double-decrement.

### M13. `ON DELETE CASCADE` on `video_comments.user_id` destroys whole threads **[flagged]**
**Where:** `db/migrations/006_comments.sql:33-34`.
Deleting a user cascades to their comments, and because replies cascade on `parent_id`, deleting one user can wipe **other users' replies** under those threads — contradicting the soft-delete "keep threads for consistency" design.
**Fix:** `ON DELETE SET NULL` on `video_comments.user_id` and render "deleted user".

**Status — FIXED in PR #68.** Migration 006 now declares `user_id` nullable with a named FK `fk_video_comments_user … ON DELETE SET NULL`; new migration 007 swaps the FK on existing installs (drops the auto-named CASCADE FK, re-adds it as SET NULL — re-runnable, installer-globbed). All comment-author joins in CommentService are `LEFT JOIN` and `serializeRow()` renders a NULL author as `[deleted]`; MetricsService `recentComments`/moderation `LEFT JOIN` + `COALESCE` to `[deleted]` (topCommenters stays INNER). The existing-install FK swap can't be exercised without a live DB — verify with `SHOW CREATE TABLE` post-migration.

### M14. Electron static mount serves PHP source and `.env` in cleartext **[flagged]**
**Where:** `electron/server.js:282-285`.
`express.static(APP_ROOT)` serves the whole project root. PHP isn't executed, but the **raw source** of `db/config.php`, `bootstrap.php`, `.env`, and `.git/` is served as plain text — any page in the Electron window could `fetch('/.env')` and read DB credentials. (Secondary: Electron is optional and not part of the cPanel deployment.)
**Fix:** Serve only an explicit static subtree, or denylist `db/`, `.env`, `.git`, `*.php`.

**Status — FIXED in PR #65.** A denylist middleware ahead of `express.static` blocks `.env`/`.git`/`.htaccess`, `*.php`/`*.sql`/`*.sh`/`*.log`, and the `db/`+`electron/` source dirs (directory patterns anchored to the root so the frontend's `src/js/services` modules still load).

### M15. `urlManager.parseUrlState` double-decodes the search param **[verified]**
**Where:** `src/js/utils/urlManager.js:57`.
`decodeURIComponent(params.get('search'))` — `URLSearchParams.get()` already returns a decoded value, so this decodes twice. A search containing a literal `%` throws `URIError`; `+`/`%`-sequences corrupt. The decoded value is then written into the search input.
**Fix:** Use `params.get('search')` directly; drop the extra `decodeURIComponent`.

**Status — FIXED in PR #64.** `parseUrlState()` uses the already-decoded `params.get('search')` value directly (`|| null` preserves the absent/empty → null contract), so a `%` in the query no longer throws `URIError`.

---

## LOW

> **Status — addressed in PR #67 (P6):** L2, L3, L4, L5, L7, L8, L9, L10, L11 are **FIXED**. **L1** (transaction nesting → SAVEPOINTs + `inTransaction()`-guarded rollback) is **deferred** — it changes core transaction semantics for every caller and can't be exercised without a live DB. **L6** is left as-is (**latent**: thumbnail retention defaults to `0`, so the unbounded delete path doesn't run).

- **L1. `Database::transaction()` has no nesting guard [flagged]** — `db/Database.php:215-225`. Nested `beginTransaction()` throws; `rollback()` isn't guarded by `inTransaction()`. Use depth tracking + SAVEPOINTs and guard rollback.
- **L2. `Database::upsert()` returns unreliable `lastInsertId()` on the UPDATE branch [flagged]** — `db/Database.php:166-189`. Returns 0 for existing-row updates; document or `SELECT` the id.
- **L3. No `password_needs_rehash` on login [flagged]** — `services/Auth/UserAuthService.php:149-185`. Old-cost hashes never upgrade.
- **L4. `getStaleMetadata` NULL handling [flagged]** — `cache/CacheManager.php:370-377`. `last_refreshed < …` is NULL (not true) for never-refreshed permanent rows, so they're never picked up. Add `last_refreshed IS NULL OR …`.
- **L5. `cron/cache_warmer.php` has no wall-clock budget [flagged]** — warms 20 searches + every featured/recommended video serially with sleeps; killed mid-loop on hosts with a 30s limit. Add an elapsed-time break like `process_cache_queue.php`.
- **L6. `cleanExpiredCache()` loads all stale thumbnail rows with no `LIMIT` [flagged]** — `cache/CacheManager.php:626-630`. Batch the deletes. (Default retention 0/disabled, so latent.)
- **L7. CLI guard uses `php_sapi_name() !== 'cli'` [flagged]** — cron jobs would 403 themselves under a `cgi-fcgi` cron SAPI. Also allow `defined('STDIN')`.
- **L8. `formatFileSize(0)`/large-value edge [flagged]** — `src/js/utils/helpers.js:116-121`. `log(0)` → `-Infinity` index; guarded by the `!bytes` check for 0 but not for sub-1-byte values. Minor.
- **L9. `SearchHistoryService` DISTINCT doesn't dedupe latest-per-query [flagged]** — `services/User/SearchHistoryService.php:23-32`. Use `GROUP BY query ORDER BY MAX(searched_at)`.
- **L10. Thumbnail `delete()` uses unguarded `unlink()` [flagged]** — `cache/ThumbnailCache.php:248-258`. Emits a warning on permission error; use `@unlink` + existence check.
- **L11. Installer reflects raw DB connection error to the browser [flagged]** — `install.php:177-180`. Escaped (no XSS) but leaks DB host/user to whoever reaches an un-installed instance. Log detail, show generic message.

---

## Documentation drift

- **README "Known gaps" is stale.** `README.md:437-440` states the app has "no CSRF tokens" and "no login rate-limiting." Both are **implemented**: a full CSRF system lives in `bootstrap.php` (`csrf_token`/`csrf_verify`/`csrf_meta_tag`) and is enforced by `ApiController::requireCsrf()` on every non-GET endpoint; login throttling exists in `UserAuthService::isLoginThrottled()` with migration `005_auth_throttle.sql`. Update the README so the security posture isn't understated.
- **README migration list says "001 → 005"** (`README.md:84`, `:114`) but `006_comments.sql` exists and is required for the comments feature. Update the manual-setup step and installer notes.
- **README "PHP 7.2+"** appears in the Tech Stack (`:459`) while Requirements say 7.4+. Pick one (code uses `?:`/typed properties consistent with 7.4+).

---

## Verified clean (checked, not bugs)

- **SQL injection:** all user data is bound; `insert/update/delete` build placeholders from server-controlled column names only. No string concatenation of user input into queries (the Lucene `q` is the only user-influenced query text, and it goes to archive.org, not the DB).
- **Server-side XSS:** every dynamic echo in `index.php`, `player.php`, `collection(s).php`, the auth pages, `account.php`, and all admin views is escaped (`htmlspecialchars ENT_QUOTES` / `escapeAttr`). OG/meta tags are escaped; `?video=` is filtered to `[a-zA-Z0-9_-]` server-side; inline JSON uses `JSON_HEX_TAG`.
- **Host-header poisoning:** `safe_host()`/`safe_base_url()` ignore `HTTP_HOST` and pin to `APP_URL`/`SERVER_NAME` for emailed links.
- **Tokens at rest:** remember-me, reset, and verify tokens are SHA-256 hashed; raw tokens are 32 bytes from `random_bytes`; reset tokens are single-use with prior-token invalidation.
- **Thumbnail path traversal:** ids are sanitized to `[a-zA-Z0-9_-]` before use as filenames on both the serve and write paths; `realpath()` normalizes. Not exploitable.
- **SSRF:** metadata/thumbnail proxies pin the host to `archive.org`.
- **Service worker:** the no-cache list correctly excludes `auth/`, `bookmarks.php`, `history.php`, `user.php`, `collections.php`, `cache.php`, `stats.php`; per-user comment responses fall through to network. No private data is cached cross-user. SW registration is relative (subdirectory-install safe).
- **Open redirect:** `afc_safe_next()` rejects `//host`, `\`, scheme URLs, and non-URL-safe chars (server + client).
- **CSRF header on the client:** every POST/DELETE fetch wrapper sends `X-CSRF-Token`.
- **Charset:** schema + DSN are `utf8mb4` end-to-end; emoji in titles/comments insert correctly.
- **No persistent PDO connections** (no shared-host connection exhaustion from that).
- **Electron:** `nodeIntegration:false` + `contextIsolation:true`; no `child_process`/command injection.

---

## UI/UX review & polish

The frontend is, on inspection, **already mature and professionally built**. The pass below records (1) the strengths confirmed during review, (2) the concrete change applied in this pass, and (3) prioritized recommendations deliberately left as recommendations because they are cross-cutting design decisions that should be validated visually (this environment has no MySQL/live archive.org, so the running JS app can't be rendered here — editing a tuned UI blind would risk regressions).

### Strengths confirmed (no change needed)
- **Design system:** token-driven (`styles.css:11+`) with **documented WCAG-AA contrast fixes** baked into the text-color tokens (`styles.css:25-29`).
- **Accessibility:** skip links on the two primary pages, `:focus-visible` usage, an `.sr-only` utility, ARIA roles on loading/error regions (`index.php:589,596`), and a **universal `prefers-reduced-motion` reset** (`styles.css:2989`) that covers every page loading the stylesheet (including the animation-heavy player).
- **Theming:** `system` theme honored via `matchMedia` with a **live change listener** (`index.php:653`), and a no-flash inline theme bootstrap in `<head>` on every page.
- **Performance:** `preconnect`/`dns-prefetch` to fonts + archive.org, `preload` of CSS/JS, above-the-fold thumbnail `preload` with `fetchpriority="high"`, lazy `loading="lazy"` images.
- **PWA / resilience:** manifest, install-scoped relative SW registration, and an **offline page that auto-recovers** by probing the API every 5s and on `online`/`focus`.
- **Polish details:** inline-SVG favicon (no file upload needed on cPanel — smart), `⌘K` / `/` search shortcuts with platform-aware hint, back-to-top, accessible password-reveal toggle, correct `autocomplete` tokens on auth forms, guest→account merge prompt, first-load legal disclaimer modal, and `JSON_HEX_TAG`-escaped inline JSON config.
- **Consistency:** `<html lang="en">` on every page; dynamic output uniformly escaped.

### Change applied in this pass
- **Added a `<noscript>` fallback** to `index.php` and `player.php` (+ `.noscript-banner` styles in `styles.css`). The entire results/player surface is JS-rendered, so with scripting off (or if the module bundle is blocked) the user previously saw a **blank, broken-looking page**. The banner explains the requirement and links to the underlying archive.org collection (the player deep-links to the specific item when a `?video=` id is present). **Zero risk to the normal experience** — `<noscript>` renders nothing when JS is enabled. PHP lints clean; CSS uses existing tokens.

### Recommendations (prioritized — validate visually before shipping)
1. **Persistent footer with attribution.** The "we don't host this — report to the Internet Archive" message currently appears only in the **one-time** disclaimer modal. A small, always-present `<footer>` (archive.org attribution + Report-a-problem / DMCA / Terms links, mirroring `partials/head-common.php`'s modal links) reinforces the legal posture for a site streaming third-party content and is the single biggest "looks finished" win. Ship as a shared `partials/footer.php` included on all content pages for consistency.
2. **App-load failure detection.** `<noscript>` only covers *disabled* JS. If `app.js`/`player.js` (ES modules) fail to load or throw during init (proxy stripping, network blip, ancient browser), the page stays blank with JS "on." Add a tiny inline, non-module watchdog that reveals the existing `#error` element with a "couldn't load — retry / open archive.org" message if the app hasn't initialized within ~8s. Tune the timeout to avoid false positives on slow links.
3. **Fix the search input double-decode (bug M15).** `urlManager.js:57` double-`decodeURIComponent`s the `search` param, so any query containing `%` throws and breaks the search box — a user-facing UX defect with a one-line fix.
4. **Centralize the CSRF token (bug M10).** Harmless today (auth pages navigate after login) but will surface as a 403 in any future write-without-navigation flow; one shared, invalidate-on-auth-change token cache removes the footgun.
5. **Verify first-run empty states** for bookmarks, collections, and watch history have friendly copy + a call-to-action (the `.no-results*` styles exist at `styles.css:2224+`; confirm each surface uses them rather than rendering nothing).
6. **`theme-color` light/dark variants** via `<meta name="theme-color" media="(prefers-color-scheme: …)">` for nicer mobile browser chrome in light mode.
7. **`offline.html` branding:** it hardcodes `#ff0000`/`#0a0a0b`. It's a static SW-served file so it can't read admin settings at runtime — consider templating it at install time (or neutralizing the accent) so a re-branded install stays consistent offline.

> None of items 1–7 are blockers. Items 3 and 4 are the documented bugs that most directly affect the live experience; the rest are incremental polish.

> **Status (remediation phases):**
> - **#1 Persistent footer — DONE in PR #66 (P5):** shared `partials/footer.php` (archive.org attribution + Report / DMCA / Terms) on all five content pages; styles reuse the design tokens.
> - **#2 App-load watchdog — DONE in PR #66 (P5):** inline non-module watchdog reveals a recovery message after 8s if `window.__afcReady` isn't set by `app.js`/`player.js`.
> - **#3 Search double-decode (M15) — FIXED in PR #64 (P3).**
> - **#4 Centralize CSRF (M10) — FIXED in PR #65 (P4).**
> - **#5 Empty states — PARTIAL (P5):** confirmed friendly server-rendered empty states on `collections.php` and `collection.php`. Bookmarks/watch-history are count-driven (`account.php`) and list-rendered client-side; flagged to validate the empty branch in a running app rather than edit the tuned UI blind.
> - **#6 `theme-color` light/dark — DONE in PR #66 (P5).**
> - **#7 `offline.html` branding — still open** (left as a recommendation).

---

## Player runtime bugs (post-audit pass)

The earlier passes concentrated on the PHP backend, security, and server-rendered
UI. This pass audited the **client-side player runtime** (`player.js` +
`src/js/player/*`, `src/js/services/VideoService.js`, `PlaylistService.js`) and
found four playback-breaking bugs that the original audit did not cover. All are
**fixed in this PR**; the player uses the native `<video controls>` element, so
the seek/timeline is browser-driven and the fixes are all in the JS event wiring.

### PR1. Double loading spinners on the player **[verified]**
**Where:** `player.js` `showLoader()` / `_showBuffering()`; markup `player.php:343` (`#playerLoader`) and `:397` (`#bufferingIndicator`); CSS `player-styles.css:211` (loader, `z-index:5`, opaque) and `:2100` (buffering, `z-index:60`, transparent).
Both elements render the **identical** `.loading-spinner > .spinner-ring` markup. `showLoader()` only toggled `#playerLoader`; it never cleared the buffering indicator. So switching episode/quality **while mid-buffer** (buffering spinner already `.visible`) stacked the opaque loader's spinner (z5) *and* the buffering spinner (z60) — two spinners at once. Separately, after the loader hid on initial load, the mid-playback buffering spinner could appear on top of the browser's own first-frame spinner drawn inside the `<video>`.
**Fix:** A single `_sourceCanPlay` flag (reset in `showLoader()`, set on `canplay`/`playing`) makes the two spinners mutually exclusive: `showLoader()` now hides the buffering indicator, and `_showBuffering()` bails while the loader is up **or** before the source is playable (so it's truly "mid-playback only").

### PR2. Seeking the timeline is misread as a playback failure **[verified]**
**Where:** `player.js` `setupVideoListeners()` `<video>` `error` handler.
The handler treated **every** media `error` event as fatal. Two routine, non-fatal cases slipped through: `MEDIA_ERR_ABORTED` (code 1), fired when the browser aborts an in-flight range request because the user **seeked**, or when we swap `<source>` for the next track; and transient range-request hiccups while scrubbing to an un-buffered region. The result was the reported bug — clicking/dragging the timeline would surface an error toast and either **auto-skip the episode** (playlist) or **swap to the Archive.org embed** (single video), yanking the viewer away mid-watch.
**Fix:** The handler now (a) ignores `MEDIA_ERR_ABORTED`, and (b) only acts when the source **never became playable** (`!_sourceCanPlay`). An error *after* the video has played is logged and ignored (native element recovers / user retries); it no longer skips or falls back.

### PR3. Playlists don't reliably continue past an item that fails to load **[verified]**
**Where:** `player.js` `error` handler + `playPlaylistItem()`; `PlaylistService.findNextPlayable()`.
Auto-skip groundwork existed (`failedPlaylistIndices`, `findNextPlayable`) but was bolted onto the over-aggressive error handler from PR2, so it both over-fired (on seeks) and was conceptually muddled. With PR2's `!_sourceCanPlay` gate, "**fails to load → advance to the next playable episode**" is now exactly the trigger requested: a 404/dead/undecodable source that never reaches a playable state marks the track bad and advances; already-failed indices are never retried, so the cascade terminates cleanly ("No more playable episodes" when exhausted). This also covers the **initial** page load — the playlist is configured synchronously before the async `error` fires.
**Fix:** Gate the skip on genuine load failure (PR2) and clear any stale playlist on a single-video load so the skip can't fire against an item we're no longer playing (see PR4).

### PR4. Stale playlist could drive the auto-skip on a later single-video load **[flagged]**
**Where:** `player.js` `loadVideo()`.
`playlistService.currentPlaylist` was only ever (re)initialized for multi-episode items, never cleared. If `loadVideo()` ran for a single video after a series had been set up on the same `VideoPlayer` instance, the failed-load handler would read the **previous** series' playlist and try to "advance an episode" of an item no longer on screen. Not reachable in today's flow (one item per page load; track changes use `replaceState`, not cross-item navigation), hence *flagged*, but cheap to harden.
**Fix:** `loadVideo()` now calls `playlistService.clearPlaylist()` on the single-video branch.

### Minor: relative-seek shortcuts snapped to 0 before metadata **[verified]**
**Where:** `player.js` `handleKeyboard()` ArrowRight / `l` (and now `_seekBy`).
`Math.min(video.duration || 0, …)` evaluated to `Math.min(0, …)` while `duration` was `NaN` (pre-metadata), so a forward-seek shortcut pressed early **jumped the video to the start**. Replaced the four seek cases with a `_seekBy(video, delta)` helper that treats an unknown duration as unbounded and lets the browser clamp to the seekable range.

> **Verification:** `scripts/check-syntax.sh all` (83 PHP + 35 JS files) and `php tests/smoke.php` pass. The fixes are event-wiring/logic only — no markup or CSS change — and were reasoned through every load path (initial load, track change, quality switch, end-of-video Up Next, seek, and the iframe fallback). They could not be exercised against a live browser + archive.org in this environment, so a manual smoke on a real multi-episode item (seek mid-playback; force a 404 track) is the recommended final check.

---

## Follow-up pass — 2026-06-12

**Scope:** Fresh full-codebase review, focused on the ~3,000 lines landed after the
original audit (the admin Maintenance feature, player fixes, watchdog) plus a
re-sweep of everything else. All findings below are **fixed in this PR**.

### F1. Backup dump paged without ORDER BY or a snapshot — corrupt backups possible **[verified]**
**Where:** `services/Admin/MaintenanceService.php` `dumpTable()` / `streamBackup()`.
`dumpTable()` paged with `LIMIT n OFFSET m` and **no `ORDER BY`** — MySQL gives no
ordering guarantee across unordered paged SELECTs, and nothing wrapped the dump in a
transaction, so writes landing mid-dump shift offsets: rows can be silently
**duplicated or skipped**, and tables dumped seconds apart can be mutually
inconsistent (a comment whose user row is missing). The operator's safety net could
produce corrupt dumps with no warning.
**Fix:** `streamBackup()` now dumps from a single `REPEATABLE READ` /
`START TRANSACTION WITH CONSISTENT SNAPSHOT` (the `mysqldump --single-transaction`
technique, best-effort with fallback), and `dumpTable()` orders every page by the
table's primary key (`SHOW KEYS … WHERE Key_name='PRIMARY'`).

### F2. No concurrency guard on restore / content-reset / migrations **[verified]**
**Where:** `services/Admin/MaintenanceService.php`; `api/admin/maintenance.php`.
Two admins (or one double-submit) could interleave `DROP`/`CREATE`/`INSERT` from two
restores, or a restore against a content reset — corrupting the DB and each other's
safety snapshots.
**Fix:** A per-database advisory lock (`acquireMaintenanceLock()`, same `GET_LOCK`
pattern as `CacheManager::acquireQueueLock()`) now serializes `restoreFromUpload()`,
`contentReset()`, `runMigrations()`, and the API's `backup` stream (acquired
*before* download headers, so a busy lock still returns clean JSON, 409). Held locks
auto-release if the process dies.

### F3. Uploaded `.sql.gz` decompressed without a size cap **[verified]**
**Where:** `MaintenanceService::restoreFromUpload()`.
`gzdecode()` ran uncapped on the upload; an oversized (or corrupt) archive died as
an opaque out-of-memory 500 mid-request. Now capped at 256 MB decompressed with a
clear error message. (Admin-only surface — robustness, not an attack fix.)

### F4. `catch (Exception)` around transactions misses `Error` throwables **[verified]**
**Where:** `services/SettingsService.php` (3 transactional writers),
`db/Database.php::transaction()`.
A `TypeError`/`Error` thrown mid-transaction skipped the `catch (Exception)` block,
so no rollback ran and the connection was left with an open transaction. All four
sites now catch `Throwable` (with a guarded rollback).

### F5. Electron static denylist missed the new `backups/` directory **[verified]**
**Where:** `electron/server.js` (`DENY_DIR`).
The M14 denylist predates the Maintenance feature: `backups/` (server-side **DB
dumps**) wasn't covered — `*.sql` was extension-blocked but the deny belt now also
covers the directory itself plus `logs/` and the `.installed` marker.

### F6. `collection.php` not-found page returned HTTP 200 **[verified]**
Crawlers indexed the "doesn't exist" page as a healthy 200. Now sends
`http_response_code(404)`.

### F7. Thumbnail proxy hardening **[flagged → fixed]**
**Where:** `api/thumbnail.php`, `services/ArchiveOrgService.php`.
Downloads buffered the upstream response with no size cap before handing it to GD
(the expensive decode step), and the serve path trusted `mime_content_type()`
verbatim. Now: 10 MB cap on thumbnail downloads (cURL `MAXFILESIZE` + `maxlen` +
post-check before GD), 50 MB memory-guard cap on archive.org API responses, an
image-MIME allow-list on the cached-file serve path (non-images fall back to the
archive.org redirect, never served), and `X-Content-Type-Options: nosniff`.

### F8. CollectionPicker dialog had no focus management **[verified]**
**Where:** `src/js/components/CollectionPicker.js`.
`aria-modal="true"` without moving focus in, trapping Tab, or restoring focus on
close — keyboard users tabbed into the page behind the dialog (WCAG 2.4.3). Now:
focus moves to the close button on open, Tab/Shift+Tab cycle inside the dialog, and
focus returns to the opener on close.

### Polish in the same pass
- **offline.html** brand-red button → neutral (closes UI/UX rec #7; the file is
  SW-served statically and can't read the admin brand color).
- **Restore panel copy** now states restores are not atomic and to use the safety
  snapshot if failures are reported.
- **README repairs:** env-var table un-broken (a "Maintenance Notes" H2 was wedged
  mid-table), migration lists 001→007 (007 was missing from manual setup), the
  Maintenance admin feature + `api/admin/*` endpoints documented.

### Reviewed and rejected (false positives from this pass's review)
- `Database::rollBack()` vs `rollback()` "undefined method" — PHP method names are
  case-insensitive; no `__call` involved.
- `PDO::quote()` null-byte truncation in the dump — the MySQL driver escapes
  `\x00`; no BLOB columns in the schema regardless.
- `switch` fall-through in `api/admin/metrics.php` — every case ends in
  `ok()`/`error()`, both of which exit.

> **Verification:** `scripts/check-syntax.sh all` and `php tests/smoke.php` pass
> (incl. the `splitSqlStatements` unit cases). DB-touching changes (snapshot dump,
> GET_LOCK) follow the same patterns already proven in `CacheManager`/cron but were
> not exercised against a live MySQL in this environment — recommended manual check:
> take a backup while browsing the site, restore it, and run two restores
> concurrently to see the 409/busy path.



---

# Pass 3 — 2026-09-10: player rework + full re-audit

**Trigger:** two user-reported player bugs — (1) *"long playlists are very long
scrolls back to the top to actually view"* and (2) *"clicking the video to pause
is only briefly effective and then the video continues"* — plus a request for a
full UI + backend re-audit while keeping the app an easy shared-cPanel install.

**Method:** the player was diagnosed empirically in the bundled Chromium
(Playwright) before any code changed; the rest of the codebase was re-read by
three parallel read-only audits (backend PHP, homepage/SW, admin/Electron),
each finding was re-verified against the code, then fixed by area with
non-overlapping file ownership. Everything below marked **[verified]** was
confirmed by tracing the code path or by an in-browser check.

## A. The two reported bugs

### A1. Click-to-pause "only briefly effective" — root cause **[verified in Chromium]**
`player.js` attached its own `click` handler to the `<video>` that toggled
play/pause. Every modern engine already toggles play/pause when a
`<video controls>` element is clicked, so one click produced **two** toggles:
`pause@0.32s` then `play@0.32s` in the media event log. The video paused for a
frame and resumed.

A second, related defect made the keyboard misbehave the same way: clicking the
video gives it focus, and the browser's native controls then handle Space and
the arrow keys themselves. The page-level `document` keydown handler ran too, so
Space paused-then-resumed and ←/→ seeked 10 s instead of 5 s. A document-level
`preventDefault()` does **not** stop the native handling (measured), but a
capture-phase listener on the element does.

**Fix:** removed the page-level click toggle entirely (native controls own it);
the shortcut handler now returns whether it consumed the key, and a
capture-phase `keydown` listener on the `<video>` routes to it and calls
`stopImmediatePropagation()` when it did, so each key acts exactly once
regardless of focus. Buttons the player owns blur after a mouse click so Space
pauses instead of re-firing "Next episode". Modifier chords (Ctrl+F, Cmd+L…) are
no longer hijacked — `f` used to enter fullscreen on Ctrl+F.

### A2. Long playlists stretch the page — root cause **[verified]**
The playlist `<aside>` was a flex column inside the content row with
`max-height: none`; `.player-sidebar-items` had `overflow-y: auto` but no
bounded height, so a 100-episode item rendered 100 rows tall and the
viewport-height cinema scrolled off the top while you browsed.

**Fix — layout v3** (`player.php`, `player-styles.css`, `PlayerPlaylist.js`):
- Desktop ≥1025 px with a playlist: YouTube-style two-column grid. The rail is
  `position: sticky` under the fixed header, capped at viewport height, and the
  episode list scrolls **inside** it. The video keeps a 16:9 box beside it.
  `body.has-playlist` is toggled by `PlayerPlaylist.show()/hide()`.
- Theater mode with a playlist: full-bleed cinema, rail pinned beside the
  description below (`.player-primary { display: contents }` so the grid can
  place cinema / content / rail directly).
- Single videos: unchanged full-bleed cinema.
- ≤1024 px: one column; the video is sticky under the header (only when the
  viewport is ≥560 px tall — a pinned 16:9 frame would cover a landscape
  phone), the playlist flows in the page (no nested touch scroller), and the
  playlist header + filter bar dock under the video. The video column is
  flattened with `display: contents` so the sticky frame's containing block
  spans the whole page, not just the description.
- Active-episode reveal nudges the rail's own `scrollTop` on desktop and the
  window on phones — but only when the list is already on screen, never on
  first paint.
- Pre-existing: the phone header's centred logo overlapped "Sign up" (hidden
  ≤640 px, sign-up collapses like the site header does); a 4 px horizontal
  overflow at ≤480 px from the action-pill row's bleed margins.

### A3. Player polish shipped alongside
- Per-episode **quality picker** for series (was single-video only); the
  remembered tier is applied to each episode.
- **Series resume:** the episode index is saved with the item's progress, so a
  return visit (or Continue Watching) lands on the last-watched episode and
  offers to resume its position; per-episode resume prompt on track change.
- `?t=` deep links seek on `loadedmetadata` (the 300 ms timer raced the load).
- Resume prompts only for positions >10 s and <95 %, and server progress is
  merged in for signed-in viewers; progress is never saved for
  infinite-duration sources.
- `document.title` follows the episode; Media Session metadata + handlers
  (lock screen / hardware keys: play, pause, seek, prev/next).
- Navigating from a series to a single video now hides the rail.

### A4. Verification
`tests/browser/player.test.js` (new, run in CI) drives the real page in
Chromium with archive.org + the local API mocked: 31 checks covering A1, A2,
A3 at 1440×900, 390×844 and 740×360. All pass.

## B. Backend findings fixed **[all verified]**

| # | Sev | Finding | Fix |
|---|---|---|---|
| B1 | **Critical** | Migration 007 broke every fresh install: 006 names `fk_video_comments_user`, so 007's `DROP FOREIGN KEY video_comments_ibfk_1` removed the reply-cascade FK and the following `ADD CONSTRAINT` failed with an unswallowed duplicate-name error → installer stopped at step 2. | 006 names both FKs; 007 rewritten name-agnostic + idempotent via `information_schema` lookups and `PREPARE/EXECUTE` (also repairs installs that lost the parent FK). Both runners use the quote/comment-aware `splitSqlStatements()` and `Database::exec()` (PREPARE is refused by the server-side prepared-statement protocol `query()` uses); shared, extended swallow list. Smoke test asserts the split. |
| B2 | High | Break-glass admin session (`ADMIN_PASSWORD`, DB down) could log in but every save 401'd; a dead DB 500'd instead of 401. | `ApiController::currentUser()` wraps DB lookups, recognises the break-glass session as a synthetic admin (rejected by `requireAuth`, admitted by `requireAdmin`); settings/sections/recommendations construct `SettingsService` lazily so the JSON recovery files are reachable. |
| B3 | High | `api/metadata-batch.php` opened up to 50 concurrent archive.org fetches per anonymous request. | ≤5 uncached fetched synchronously, rest queued. |
| B4 | High | Break-glass password check had no throttle. | File-backed per-IP throttle (5 fails → 15 min), DB-independent. |
| B5 | Medium | `install.php` escaped `"`/`\` in `.env`; the two loaders never unescaped → passwords with those characters broke every request after install. | Both loaders unescape double-quoted values; `db/config.php` no longer clobbers real env vars. |
| B6 | Medium | `search_history` was never written (recent searches + 3 admin metrics permanently empty). | Recorded in `api/search.php` (page 1, non-fatal). |
| B7 | Medium | Admin "Top videos" selected a non-existent `h.title` column (always empty, error swallowed). | Joins `video_metadata_cache`; every swallowing catch in `MetricsService` now logs. |
| B8 | Medium | `/api/metadata.php` shape differed on cache hit vs miss (`licenseurl` vs `license_url`, internal columns leaked) — the licence badge vanished on the second view. | `CacheManager::normalizeMetadataRow()` applied in both metadata endpoints. |
| B9 | Medium | `ThumbnailCache::downloadImage()` (cron + queue path) lacked the size caps `api/thumbnail.php` got in F7. | Same 10 MB caps + post-check before GD. |
| B10 | Medium | A `users` row per visitor session, never cleaned; crawlers inflate it unboundedly. | Cron reaper (30-day idle guests, batched); crawler UAs get a transient in-memory guest and every write path no-ops for it. |
| B11 | Medium | Remember-me auto-login didn't regenerate the session or rotate the token. | `session_regenerate_id(true)` + CSRF rotation + single-use rolling tokens. |
| B12 | Medium | `LocalStorageService::httpGet()` used only `file_get_contents` (dead on `allow_url_fopen=Off` hosts) with no size cap. | Delegates to the hardened cURL-first fetcher. |
| B13 | Medium | `api/cache.php` `cache_immediate`/`cache_single` (synchronous upstream fetches) were open to anyone. | Per-session budget (25 items / 10 min → 429). |
| B14 | Medium | Settings POST reset every omitted key to its default; editors could rebrand the site (and change the type-to-confirm phrase); `diagnose.php` and member e-mails were editor-visible. | Partial updates; settings POST + diagnose + e-mail column are full-admin only; public GET projected through the allow-list. |
| B15 | Medium | `hasAdminUsers()` on a dropped legacy table pushed a healthy install into JSON-fallback mode. | Isolated try/catch. |
| B16 | Low | `.git/` was web-readable (basename-only dotfile rule); `admin/views/` + `admin/controllers/` served directly; `admin.php` JSON lacked `JSON_HEX_TAG`; replies under hidden threads readable; 3 writes per stale-metadata read; brand colours unvalidated at render time. | `.htaccess` denies; `JSON_HEX_*`; parent-visibility join; skip re-mark when already stale; `afc_css_color()` guard in every page template and in `SettingsService`. |

## C. Homepage / service-worker findings fixed **[all verified]**

- **H1** Back-button trap: every search `pushState`'d and `popstate` pushed again. Typing/filters now `replaceState`, explicit navigations push, popstate replays without writing the URL. Browser-checked: two Backs leave the site.
- **H2** Header search form submitted `q`, which nothing read; both `search` and `q` now work.
- **H3** Stored XSS: featured-section ids landed unescaped in an inline `onclick`; replaced with escaped `data-*` + a delegated listener.
- **H4** `sanitizeHtml()` cleaned *after* parsing on a live node (img `onerror` fired) with a leaky deny-list; rewritten as a `DOMParser` allow-list.
- **H5** SW cached per-user HTML (account/collections) into an untrimmed static cache; path/`Cache-Control` aware now, trimmed, version bumped.
- **H6** New HTML + week-old JS/CSS after deploys: `asset_url()` cache-busting on every page; SW precache with `cache: 'reload'`.
- **H7** Guest bookmarks were lost on login and the previous user's list survived logout; guests now sync to the API, pulls merge, logout clears.
- **M1–M14 / L1–L12** Reset-filters left an empty page; saved sort/collection prefs were overwritten; card rows keyboard-inoperable (now real links); invisible-but-tappable remove/action buttons on touch; no live-region announcements; `AuthNav` leaked document listeners and flashed "Sign in"; uncapped N+1 metadata fan-out; Hide/Show label never updated; dynamic cache never trimmed; sort control dead on the default view; search history polluted by partial queries; modals without focus traps (shared `focusTrap.js`); bookmark icons stale after server pull; thumbnail `onerror` wiped sibling badges; SW dead paths; unthrottled scroll handler; unused services; stale results at 1–2 chars; `display:contents` link; manifest orientation lock; 4 px layout constant; `:has()` dependency; blocking archive.org fetch on `index.php?video=` (now a 302 for non-crawlers); pagination `aria-current`.

## D. Admin panel / Electron findings fixed **[all verified]**

- **H1** Stored XSS in the admin panel: `escapeHtml()` didn't escape quotes but fed `alt="…"`/`value="…"` — an archive.org title could execute in an admin's session (CSP permits inline handlers). Helper now escapes all five characters; ids escaped everywhere.
- **M3–M11, L1–L6, L15** Save button shown on unsaveable panels and a permanently lit "unsaved" dot; section-search modal had no pagination; cancelled new sections were persisted; drag-and-drop listeners stacked per render; no unsaved-changes guard; dead per-form status divs; toast rendered messages as HTML; non-wrapping headers on phones; stretched SVG axis labels; modal focus management; inert sticky table header; missing focus rings; tab ARIA; confirm before removing a pick.
- **Electron H2** Unauthenticated settings write reached an inline `<script>` and raw JSON island (persistent code execution in the desktop app): allow-list validation on write *and* read, `'` escaped, `<`/U+2028/9 escaped in JSON, CSP meta.
- **Electron H3** The desktop app couldn't play anything (its denylist 404'd `player.php`): a `/player.php` route now renders the player page; other PHP pages get a friendly "not available in the desktop app" page.
- **L8–L10, L16** Server never closed on quit; navigation/window-open guards + sandbox; denylist gaps (`.md`, JSON config files, `tests/`, `scripts/`, `partials/`); unbounded redirect recursion; `electron` moved to devDependencies (lockfile regenerated).

## E. Not done / accepted

- Admin panel stays dark-only (a light palette needs ~40 hard-coded colours reworked — out of scope for a safe pass).
- The CSP still carries `'unsafe-inline'` (admin inline handlers, brand-colour `<style>` blocks). Removing it is a separate refactor.
- No live MySQL/Apache was available in this environment: SQL and `.htaccess` changes were reviewed for MySQL 5.7/8 + MariaDB 10.2 and Apache 2.4 syntax but not executed against a server. Recommended manual check after deploy: run the installer on an empty database (exercises the new migration 007), then *System → Maintenance → Run migrations* once more (idempotency).
- `node-fetch` remains an unused Electron dependency.

## F. Verification
`bash scripts/check-syntax.sh all` (86 PHP, 38 JS files), `php tests/smoke.php`
(incl. the new migration-splitter and colour-guard cases), the new
`tests/browser/player.test.js` (31/31), and a homepage Chromium check (results,
no horizontal overflow at 390 px, Back-button behaviour, zero page errors) all
pass.
