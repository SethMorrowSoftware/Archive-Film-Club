<?php
/**
 * AdminBootstrap — auth + data loading for the admin panel.
 *
 * Extracted from the monolithic admin.php head during the Phase 1
 * modular refactor. Sets up the following variables in the global
 * scope (so views can read them directly via `include`):
 *
 *   $useDatabase       bool   — is MySQL auth available?
 *   $authService       ?AdminAuthService
 *   $settingsService   ?SettingsService
 *   $admin_user        ?array — row from users/admin_users on success
 *   $is_logged_in      bool
 *   $login_error       string
 *   $current_recommendations array
 *   $recommendations_data    array
 *   $site_settings     array
 *   $featured_sections array
 *
 * Handles login POST and ?logout=1 GET inline so the rest of the page
 * only has to render. If the user is not signed in, $is_logged_in is
 * false and the caller should render the login view.
 */

// Route through the app bootstrap so we get the same .env loader,
// autoloader, and hardened session cookie (secure/httponly/samesite +
// install-scoped cookie path) as the rest of the app. Without this,
// admin sessions run with weaker defaults and leak cookies across
// sibling installs.
require_once __DIR__ . '/../../bootstrap.php';

$useDatabase = false;
$authService = null;
$settingsService = null;
$admin_user = null;

try {
    if (file_exists(__DIR__ . '/../../.env')) {
        // Autoloader resolves AdminAuthService / SettingsService; no
        // require_once needed.
        $authService = new AdminAuthService();
        $settingsService = new SettingsService();

        // Consider the DB usable whenever an admin exists in either the
        // unified users table (migration 003) or the legacy admin_users
        // table. hasAdminUsers() on its own only checks the legacy table —
        // and that table is dropped by migration 005, so the call throws on
        // any up-to-date install. Catch it HERE (not in the outer catch,
        // which would abandon the whole block and push a perfectly healthy
        // DB into JSON-fallback mode) and fall through to the unified check.
        $legacyAdmins = false;
        try {
            $legacyAdmins = $authService->hasAdminUsers();
        } catch (Throwable $e) {
            // admin_users missing — expected once migration 005 has run.
        }
        if ($legacyAdmins) {
            $useDatabase = true;
        } else {
            try {
                $db = Database::getInstance();
                $unifiedAdmins = (int)$db->fetchColumn(
                    "SELECT COUNT(*) FROM users WHERE role IN ('admin','editor') AND is_guest = 0"
                );
                if ($unifiedAdmins > 0) {
                    $useDatabase = true;
                }
            } catch (Throwable $e) {
                // users table may lack the new columns on an old install;
                // fall through to JSON mode.
            }
        }
    }
} catch (Exception $e) {
    error_log("Admin: Database not available, using JSON fallback: " . $e->getMessage());
}

// Break-glass password fallback from environment variable. We REQUIRE the
// value to be a bcrypt/argon hash (PASSWORD_DEFAULT-style) so a plaintext
// leak of .env doesn't immediately yield admin access. Operators who set
// a plain string get a clear error and are forced to hash it.
$ADMIN_PASSWORD_HASH = getenv('ADMIN_PASSWORD') ?: null;
$adminPasswordIsHashed = $ADMIN_PASSWORD_HASH
    && (strncmp($ADMIN_PASSWORD_HASH, '$2y$', 4) === 0
        || strncmp($ADMIN_PASSWORD_HASH, '$argon2', 7) === 0);

/**
 * Break-glass login throttle — file-backed and DB-independent, because the
 * whole point of the ADMIN_PASSWORD path is that the database may be down
 * (so the auth_attempts table AdminAuthService throttles on is unusable).
 *
 * Semantics mirror AdminAuthService::isLoginThrottled() per IP, with a hard
 * lockout: 5 failures inside 15 minutes lock that IP out for 15 minutes.
 * State is a small JSON map {ipHash: {fails, first, locked_until}} under
 * logs/ (auto-created by bootstrap.php, 404'd by the root .htaccess), falling
 * back to the system temp dir when logs/ isn't writable. flock() serializes
 * concurrent updates. If no writable location exists at all we fail OPEN
 * (and log it) — the same choice the DB throttle makes when its table is
 * missing — rather than lock the operator out of their own recovery path.
 *
 *   afc_break_glass_throttle('check') → true when this IP is locked out
 *   afc_break_glass_throttle('fail')  → record one failure; true if it tripped the lockout
 *   afc_break_glass_throttle('reset') → clear this IP after a successful login
 */
if (!function_exists('afc_break_glass_throttle')) {
    function afc_break_glass_throttle(string $op): bool {
        $maxFails = 5;
        $lockSeconds = 900;    // 15 min lockout
        $windowSeconds = 900;  // failures older than this no longer count

        $root = dirname(__DIR__, 2);
        $logsDir = $root . '/logs';
        $file = (is_dir($logsDir) && is_writable($logsDir))
            ? $logsDir . '/break-glass-throttle.json'
            : rtrim(sys_get_temp_dir(), '/\\') . '/afc-break-glass-' . md5($root) . '.json';

        $ipKey = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $now = time();

        $fh = @fopen($file, 'c+');
        if (!$fh || !@flock($fh, LOCK_EX)) {
            if ($fh) {
                fclose($fh);
            }
            error_log('[AdminBootstrap] break-glass throttle state unavailable (' . $file . '); failing open');
            return false;
        }

        $map = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($map)) {
            $map = [];
        }
        // Prune entries whose lockout AND failure window have both lapsed.
        foreach ($map as $k => $v) {
            if (!is_array($v)
                || max((int)($v['locked_until'] ?? 0), (int)($v['first'] ?? 0) + $windowSeconds) < $now) {
                unset($map[$k]);
            }
        }
        $entry = isset($map[$ipKey]) && is_array($map[$ipKey])
            ? $map[$ipKey]
            : ['fails' => 0, 'first' => $now, 'locked_until' => 0];

        $result = false;
        switch ($op) {
            case 'check':
                $result = (int)($entry['locked_until'] ?? 0) > $now;
                break;
            case 'fail':
                if ((int)($entry['first'] ?? 0) + $windowSeconds < $now) {
                    $entry = ['fails' => 0, 'first' => $now, 'locked_until' => 0];
                }
                $entry['fails'] = (int)($entry['fails'] ?? 0) + 1;
                if ($entry['fails'] >= $maxFails) {
                    $entry['locked_until'] = $now + $lockSeconds;
                    $entry['fails'] = 0;
                    $entry['first'] = $now;
                    $result = true;
                }
                $map[$ipKey] = $entry;
                break;
            case 'reset':
                unset($map[$ipKey]);
                break;
        }

        if ($op !== 'check') {
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($map));
            fflush($fh);
        }
        flock($fh, LOCK_UN);
        fclose($fh);
        return $result;
    }
}

// Break-glass password vs real DB admin — flag for dashboard banner.
// If the install has a proper DB admin AND an ADMIN_PASSWORD is still set
// in .env, the operator has a "second key" they may not remember. Views
// read this flag and render an ADMIN_PASSWORD fallback warning banner.
$adminPasswordFallbackActive = ($useDatabase && !empty($ADMIN_PASSWORD_HASH));

// Handle login (POST only; isset($_POST['password']) covers both DB and
// fallback flows -- the right-hand operand was a strict subset, so removed).
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    // CSRF: required for both DB and fallback login paths. Without this
    // an attacker can log a victim into the attacker's admin account.
    if (!function_exists('csrf_verify') || !csrf_verify($_POST['_csrf'] ?? '')) {
        $login_error = 'Session expired. Please reload and try again.';
    } elseif ($useDatabase && $authService) {
        $username = trim($_POST['username'] ?? 'admin');
        $password = $_POST['password'] ?? '';

        try {
            $user = $authService->authenticate($username, $password);
        } catch (RuntimeException $e) {
            // Raised when the per-IP brute-force throttle trips.
            $user = null;
            $login_error = $e->getMessage();
        }
        if ($user) {
            $authService->startSession($user);
            $admin_user = $user;
            // Rotate CSRF token on auth state change
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } elseif ($login_error === '') {
            $login_error = 'Invalid username or password';
        }
    } else {
        $supplied = (string)($_POST['password'] ?? '');
        if (!$ADMIN_PASSWORD_HASH) {
            $login_error = 'Admin login is not configured. Run install.php or set ADMIN_PASSWORD (as a password_hash() value) in .env.';
        } elseif (!$adminPasswordIsHashed) {
            // Operator stored a plaintext password in .env. Reject the login
            // outright and surface the problem -- this prevents both the
            // plaintext-disclosure risk and the false sense of security.
            $login_error = 'ADMIN_PASSWORD in .env must be a password_hash() value, not plaintext. Generate one with: php -r "echo password_hash(\'yourpassword\', PASSWORD_DEFAULT);"';
        } elseif (afc_break_glass_throttle('check')) {
            // Checked BEFORE password_verify so a locked-out client can't
            // keep pumping load through the hash function either.
            $login_error = 'Too many sign-in attempts. Please wait a few minutes and try again.';
        } elseif (password_verify($supplied, $ADMIN_PASSWORD_HASH)) {
            afc_break_glass_throttle('reset');
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } else {
            afc_break_glass_throttle('fail');
            $login_error = 'Invalid password';
        }
    }
}

// Handle logout -- must be a POST with a valid CSRF token. A GET-based
// logout is a CSRF sink: any third-party image tag pointed at
// ?logout=1 silently signs the admin out.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (function_exists('csrf_verify') && csrf_verify($_POST['_csrf'] ?? '')) {
        if ($useDatabase && $authService) {
            $authService->endSession();
        } else {
            session_destroy();
        }
        header('Location: admin.php');
        exit;
    }
    // Bad/missing CSRF: silently fall through to the login screen.
}

// Check if logged in
if ($useDatabase && $authService) {
    $admin_user = $authService->validateSession();
    $is_logged_in = $admin_user !== null;
} else {
    $is_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Database-maintenance ("System") panel is restricted to FULL admins on a
// live DB. Editors (curation role) and the JSON-fallback break-glass login
// (which has no database to maintain) never see it. The API enforces the
// same rule server-side — this flag only governs the UI.
$canMaintain = $useDatabase && $is_logged_in && (($admin_user['role'] ?? '') === 'admin');

// ---- Data loading (only meaningful once logged in, but cheap to do always) ----

$recommendations_file = __DIR__ . '/../../recommendations.json';
$current_recommendations = [];
$recommendations_data = ['enabled' => true, 'title' => 'Staff Picks', 'videos' => []];

if ($useDatabase && $settingsService && $is_logged_in) {
    try {
        $recommendations_data = $settingsService->getRecommendations();
        $current_recommendations = $recommendations_data['videos'] ?? [];
    } catch (Exception $e) {
        error_log("Failed to load recommendations from DB: " . $e->getMessage());
    }
}

// JSON fallback only when the DB is unreachable. An empty list from a
// healthy DB means the admin removed all picks; falling back to JSON
// would resurrect deleted picks in the admin UI.
if (!$useDatabase && file_exists($recommendations_file)) {
    $content = file_get_contents($recommendations_file);
    $data = json_decode($content, true);
    if ($data) {
        $recommendations_data = $data;
        if (isset($data['videos'])) {
            $current_recommendations = $data['videos'];
        }
    }
}

$settings_file = __DIR__ . '/../../site-settings.json';
$site_settings = [
    'siteName' => 'Archive Film Club',
    'tagline' => 'Discover classic films from Archive.org',
    'brandColor' => '#ff0000',
    'accentColor' => '#065fd4',
    'defaultTheme' => 'dark',
    'enableThemeToggle' => true,
    'headerStyle' => 'default',
    'cardStyle' => 'modern',
    'showDownloadCount' => true,
    'showCreator' => true,
    'showDate' => true,
    'enableBookmarks' => true,
    'enableWatchHistory' => true,
    'defaultCollection' => 'all_videos',
    'defaultSort' => 'downloads'
];

if ($useDatabase && $settingsService && $is_logged_in) {
    try {
        $dbSettings = $settingsService->getSettings();
        if (!empty($dbSettings)) {
            $site_settings = array_merge($site_settings, $dbSettings);
        }
    } catch (Exception $e) {
        error_log("Failed to load settings from DB: " . $e->getMessage());
    }
}

// JSON fallback only when DB is unreachable. The previous unconditional
// merge meant a stale site-settings.json would overwrite live DB values,
// because JSON was loaded AFTER the DB read.
if (!$useDatabase && file_exists($settings_file)) {
    $content = file_get_contents($settings_file);
    $data = json_decode($content, true);
    if ($data) {
        $site_settings = array_merge($site_settings, $data);
    }
}

$sections_file = __DIR__ . '/../../featured-sections.json';
$featured_sections = [];

if ($useDatabase && $settingsService && $is_logged_in) {
    try {
        $sectionsData = $settingsService->getFeaturedSections();
        $featured_sections = $sectionsData['sections'] ?? [];
    } catch (Exception $e) {
        error_log("Failed to load sections from DB: " . $e->getMessage());
    }
}

// Same rule: JSON fallback only when DB is unreachable, never on an
// empty-but-valid DB result.
if (!$useDatabase && file_exists($sections_file)) {
    $content = file_get_contents($sections_file);
    $data = json_decode($content, true);
    if ($data && isset($data['sections'])) {
        $featured_sections = $data['sections'];
    }
}
