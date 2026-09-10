<?php
/**
 * Application Bootstrap
 *
 * Single entrypoint for loading configuration, environment, autoloading,
 * and starting the session. Every PHP entrypoint (index.php, player.php,
 * admin.php, api/*.php, etc.) should require_once this file.
 *
 * No Composer required - this is a hand-rolled autoloader.
 */

if (defined('ARCHIVE_FILM_CLUB_BOOTSTRAPPED')) {
    return;
}
define('ARCHIVE_FILM_CLUB_BOOTSTRAPPED', true);
define('ARCHIVE_FILM_CLUB_ROOT', __DIR__);

// =====================================================
// ERROR REPORTING (production-safe defaults)
// =====================================================
// Never display errors to the browser on shared hosting -- they can leak
// query bodies, file paths, and DB credentials. Always log them instead.
// Operators who need verbose output during install can override via .env
// (APP_DEBUG=true).

if (function_exists('ini_set')) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
}

// =====================================================
// .env LOADER
// =====================================================

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Quoted values. install.php writes any value containing whitespace,
        // '#' or a quote as a double-quoted string with `\` and `"` escaped
        // (so a DB password like  pa"ss\word  round-trips); undo exactly
        // those two escapes. Single-quoted values are literal. Anything else
        // is taken verbatim. (Keep in sync with db/config.php, which has its
        // own copy of this loader for entrypoints that skip bootstrap.)
        $len = strlen($value);
        if ($len >= 2 && $value[0] === '"' && $value[$len - 1] === '"') {
            $value = preg_replace('/\\\\([\\\\"])/', '$1', substr($value, 1, -1));
        } elseif ($len >= 2 && $value[0] === "'" && $value[$len - 1] === "'") {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Toggle debug output once .env is loaded, if the operator opted in
if (strtolower((string)(getenv('APP_DEBUG') ?: 'false')) === 'true'
    && function_exists('ini_set')) {
    @ini_set('display_errors', '1');
}

// =====================================================
// LOG DIRECTORY (best-effort)
// =====================================================
// Auto-create logs/ so any code that wants to write to it doesn't have
// to. Failures are non-fatal -- on hosts where the parent dir isn't
// writable PHP will fall back to the system error log.

$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}
if (is_dir($logsDir) && is_writable($logsDir) && function_exists('ini_set')) {
    @ini_set('error_log', $logsDir . '/php-error.log');
}

// =====================================================
// AUTOLOADER (PSR-4-ish, but hand-rolled)
// =====================================================
//
// Maps class names to directories. We keep it simple:
//   - No namespaces required (matches legacy code style)
//   - Looks in services/, services/Http/, services/Auth/, services/Mail/,
//     services/User/, services/Collection/, db/, cache/, admin/controllers/
//
// New code should drop files into these dirs and the class will load
// automatically with no require_once needed.

spl_autoload_register(function ($class) {
    // Strip any leading backslash
    $class = ltrim($class, '\\');

    $searchPaths = [
        __DIR__ . '/services/',
        __DIR__ . '/services/Http/',
        __DIR__ . '/services/Auth/',
        __DIR__ . '/services/Mail/',
        __DIR__ . '/services/User/',
        __DIR__ . '/services/Collection/',
        __DIR__ . '/services/Comments/',
        __DIR__ . '/services/Admin/',
        __DIR__ . '/db/',
        __DIR__ . '/cache/',
        __DIR__ . '/admin/controllers/',
    ];

    foreach ($searchPaths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// =====================================================
// REQUEST HELPERS
// =====================================================

/**
 * Robust HTTPS detection. Honors:
 *   - $_SERVER['HTTPS'] (any non-"off" truthy value)
 *   - SERVER_PORT 443
 *   - HTTP_X_FORWARDED_PROTO (Cloudflare, LiteSpeed proxy, AWS ALB)
 *   - HTTP_X_FORWARDED_SSL
 *
 * Used everywhere we need to decide whether a request is over HTTPS,
 * so cookies get the Secure flag and links stay on the right scheme.
 */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? null) == 443) {
        return true;
    }
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === 'https') {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
        return true;
    }
    return false;
}

/**
 * Return a host name safe to embed in a self-referential URL (canonical,
 * OG image, password-reset link, etc.). NEVER uses HTTP_HOST because the
 * Host header is client-controlled and has been used for phishing via
 * password-reset links.
 *
 * Priority:
 *   1. APP_URL env -- highest trust, set by operator
 *   2. SERVER_NAME -- configured server-side, usually ServerName directive
 *   3. localhost   -- last-ditch fallback
 */
function safe_host(): string {
    $envUrl = getenv('APP_URL');
    if ($envUrl) {
        $parsed = parse_url($envUrl);
        if (!empty($parsed['host'])) return $parsed['host'];
    }
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    if ($serverName !== '') return $serverName;
    return 'localhost';
}

/**
 * Build a fully-qualified base URL for this install, honoring APP_URL when
 * set. Pairs with safe_host() to make links emailed/served-out-of-band
 * resistant to Host-header poisoning.
 */
function safe_base_url(): string {
    $envUrl = getenv('APP_URL');
    if ($envUrl) return rtrim($envUrl, '/');

    $scheme = is_https() ? 'https' : 'http';
    $host = safe_host();
    $base = app_cookie_path();
    return $scheme . '://' . $host . rtrim($base, '/');
}

// =====================================================
// SESSION
// =====================================================

/**
 * Compute the cookie path for this install. Scopes session + auth
 * cookies to the install directory so two installs on the same
 * domain (e.g. /films/ and /shorts/) don't stomp on each other, and
 * so links in a /films/ install don't leak cookies to a sibling
 * /shorts/ install. Used by bootstrap.php, UserContext, UserAuthService,
 * and AdminAuthService.
 */
function app_cookie_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $path = rtrim(str_replace('\\', '/', dirname($script)), '/');
    // API endpoints live one level deeper; strip /api[/...] so cookies
    // are set at the install root and are visible everywhere in the app.
    if (preg_match('#^(.*)/api(/.*)?$#', $path, $m)) {
        $path = $m[1];
    }
    if ($path === '' || $path === false) return '/';
    return substr($path, -1) === '/' ? $path : $path . '/';
}

if (session_status() === PHP_SESSION_NONE) {
    // Custom session name so we don't share PHPSESSID with sibling PHP apps
    // on the same shared-cPanel domain (would let one install hijack
    // another's session).
    @session_name('afc_session');

    // Harden session cookie for auth use
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_cookie_path(),
        'domain' => '',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// =====================================================
// CSRF TOKEN
// =====================================================
// One token per session, rotated only on login/logout. Exposed to the
// page via csrf_token() and to JS via the meta tag printed by
// csrf_meta_tag(). ApiController::requireCsrf() validates an
// X-CSRF-Token request header on every non-GET request.

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Return the current CSRF token. Pages that render server-side forms
 * (login.php, register.php, install.php, admin.php) should emit this
 * inside a hidden input so the POST handler can verify it.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Constant-time check of a supplied token against the session token.
 */
function csrf_verify(?string $supplied): bool {
    if ($supplied === null || $supplied === '') return false;
    $expected = $_SESSION['csrf_token'] ?? '';
    if ($expected === '') return false;
    return hash_equals($expected, $supplied);
}

/**
 * Print the <meta name="csrf-token"> tag for inclusion in <head>.
 * JS reads window.AFC_CSRF (see csrf-init.php partial) or this meta
 * directly to populate the X-CSRF-Token header on fetch() calls.
 */
function csrf_meta_tag(): string {
    return '<meta name="csrf-token" content="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

// =====================================================
// GLOBAL CONFIG ACCESS
// =====================================================

/**
 * Fetch a value from the environment with a default.
 */
function env(string $key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    // Coerce common boolean strings
    $lower = strtolower($value);
    if ($lower === 'true') return true;
    if ($lower === 'false') return false;
    if ($lower === 'null') return null;
    return $value;
}

/**
 * Absolute path helper anchored at project root.
 */
function base_path(string $relative = ''): string {
    $relative = ltrim($relative, '/');
    return ARCHIVE_FILM_CLUB_ROOT . ($relative === '' ? '' : '/' . $relative);
}

/**
 * Render-time guard for a CSS color that ends up inside an inline
 * <style> / meta tag (brand + accent color). Only a hex color is allowed
 * through — #rgb, #rgba, #rrggbb, #rrggbbaa — anything else (a stray
 * `;`, `url(`, an expression, an empty string) collapses to $fallback.
 * htmlspecialchars() alone doesn't help inside a <style> block, which is
 * why the page templates call this in addition to escaping.
 */
function afc_css_color(string $value, string $fallback): string {
    return preg_match('/^#[0-9a-f]{3,8}$/i', $value) ? $value : $fallback;
}

/**
 * Cache-busting URL for a local static asset (CSS / JS / etc.).
 *
 * Appends `?v=<mtime>` derived from the file's last-modified time, so any
 * deployed change to the file yields a brand-new URL. That new URL sidesteps
 * BOTH layers of caching that would otherwise hide the change:
 *   1. the 1-week browser HTTP cache (see `ExpiresByType` in .htaccess), and
 *   2. the service worker's cache-first static handler (see sw.js), whose
 *      background "refresh" fetch can itself be answered from that HTTP cache.
 * Without it, a CSS/JS fix can stay invisible behind those caches for days.
 *
 * Returns a RELATIVE url (no leading slash) to preserve subdirectory
 * deployments, and falls back to the bare path if the file can't be stat'd.
 *
 * @param string $relative Path relative to project root, e.g. "player-styles.css".
 */
function asset_url(string $relative): string {
    $relative = ltrim($relative, '/');
    $mtime = @filemtime(base_path($relative));
    return $mtime ? ($relative . '?v=' . $mtime) : $relative;
}

// =====================================================
// GLOBAL ERROR HANDLERS
// =====================================================
// Without these, a thrown exception (e.g. "Database connection failed")
// during an API request returns an empty 500 -- because display_errors=0
// suppresses PHP's default error output. We wrap the response so callers
// always get either valid JSON (for /api/*) or a friendly HTML page.

set_exception_handler(function (\Throwable $e) {
    error_log('[uncaught] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
           || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

    if (!headers_sent()) {
        http_response_code(500);
        if ($isApi || $isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Internal server error']);
            return;
        }
        // Fall back to the rendered 500 page if it exists; otherwise a
        // text-only line so we don't recursively crash.
        $errorPage = __DIR__ . '/500.php';
        if (file_exists($errorPage)) {
            include $errorPage;
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Internal server error.\n";
        }
    }
});

// Convert PHP fatal-error-class errors to exceptions so the handler above
// catches them too. We DON'T catch warnings/notices -- they're best left
// in error_log.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    if ($severity & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
