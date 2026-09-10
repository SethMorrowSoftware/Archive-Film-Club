<?php
/**
 * ApiController - Base for all JSON API endpoints
 *
 * Provides consistent response formatting, auth helpers, input parsing,
 * and error handling. Subclass or instantiate directly at the top of each
 * api/*.php endpoint.
 *
 * Usage:
 *   require_once __DIR__ . '/../bootstrap.php';
 *   $api = new ApiController();
 *   $api->requireMethod(['GET', 'POST']);
 *   if ($api->isGet()) {
 *       $api->ok(['data' => ...]);
 *   }
 *   $body = $api->jsonBody();
 *   $api->requireAdmin();
 *   ...
 */
class ApiController {

    /** @var array|null Parsed JSON body, cached after first read */
    private $jsonBody = null;

    public function __construct() {
        // Every API response is JSON. Send the header up front so error
        // handlers don't accidentally emit HTML.
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
    }

    // =====================================================
    // METHOD HELPERS
    // =====================================================

    public function method(): string {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public function isGet(): bool {
        return $this->method() === 'GET';
    }

    public function isPost(): bool {
        return $this->method() === 'POST';
    }

    public function isDelete(): bool {
        return $this->method() === 'DELETE';
    }

    /**
     * Enforce one of a list of allowed HTTP methods. Exits with 405 if not.
     */
    public function requireMethod($allowed): void {
        $allowed = is_array($allowed) ? $allowed : [$allowed];
        $allowed = array_map('strtoupper', $allowed);
        if (!in_array($this->method(), $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            $this->error('Method not allowed', 405);
        }
    }

    /**
     * Reject any non-GET/HEAD request that doesn't carry a valid CSRF token
     * in the X-CSRF-Token header (or `_csrf` field in the parsed JSON body).
     *
     * SameSite=Lax on the session cookie blocks most CSRF, but the API is
     * fetched cross-page via JS so we layer a token check on top. The JS
     * client picks up the token from the <meta name="csrf-token"> tag
     * printed by csrf_meta_tag() in bootstrap.php.
     *
     * GET endpoints are exempt (they should never mutate state).
     */
    public function requireCsrf(): void {
        $method = $this->method();
        if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
            return;
        }

        $supplied = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($supplied === '' && $this->jsonBody !== null) {
            $supplied = (string)($this->jsonBody['_csrf'] ?? '');
        }
        if ($supplied === '') {
            // Try parsing the body opportunistically -- some endpoints call
            // requireCsrf() before jsonBody(). We can't call jsonBody() here
            // because it errors out on empty body, and some POST flows
            // (e.g. logout) ship no body at all.
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['_csrf'])) {
                    $supplied = (string)$decoded['_csrf'];
                }
            }
        }

        if (!function_exists('csrf_verify') || !csrf_verify($supplied)) {
            $this->error('Invalid or missing CSRF token', 403);
        }
    }

    // =====================================================
    // INPUT
    // =====================================================

    /**
     * Get the parsed JSON request body as an associative array.
     * Sends 400 if the body is missing or unparseable.
     */
    public function jsonBody(): array {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            $this->error('Missing request body', 400);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->error('Invalid JSON body', 400);
        }

        $this->jsonBody = $decoded;
        return $this->jsonBody;
    }

    /**
     * Get a query parameter with an optional default.
     */
    public function query(string $key, $default = null) {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get the string value of a body/query field, trimmed, or null.
     */
    public function str($source, string $key, ?string $default = null): ?string {
        $val = $source[$key] ?? null;
        if ($val === null) return $default;
        if (!is_string($val)) return $default;
        $val = trim($val);
        return $val === '' ? $default : $val;
    }

    /**
     * Require a field in the body; 400 if missing.
     */
    public function required(array $body, string $key) {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
            $this->error("Missing required field: $key", 400);
        }
        return $body[$key];
    }

    // =====================================================
    // AUTH
    // =====================================================

    /**
     * Return the currently authenticated user (of any role) or null.
     * Prefers the new unified UserAuthService; falls back to legacy admin
     * session; finally recognizes the break-glass (ADMIN_PASSWORD in .env)
     * admin session that admin.php establishes when the database is down.
     *
     * Every DB-backed lookup is wrapped: a dead database, or a users table
     * that hasn't been migrated yet, must degrade to "not signed in" (so the
     * caller answers 401) rather than escape as an uncaught exception (500).
     * It also has to keep going so the break-glass check below still runs
     * while the DB is unreachable — that is the only time it matters.
     */
    public function currentUser(): ?array {
        try {
            if (class_exists('UserAuthService')) {
                $auth = new UserAuthService();
                $user = $auth->currentUser();
                if ($user) return $user;
            }
        } catch (\Throwable $e) {
            error_log('[ApiController::currentUser] user lookup failed: ' . $e->getMessage());
        }

        // Legacy admin session fallback (pre-unified-users migration)
        try {
            if (class_exists('AdminAuthService')) {
                $auth = new AdminAuthService();
                $user = $auth->validateSession();
                if ($user) return $user;
            }
        } catch (\Throwable $e) {
            error_log('[ApiController::currentUser] admin lookup failed: ' . $e->getMessage());
        }

        // Break-glass session. AdminBootstrap sets this flag (and nothing
        // else) after a successful ADMIN_PASSWORD login in JSON-fallback mode.
        // There is no users row behind it, so it's a synthetic admin with
        // id 0: enough for the admin-only endpoints whose JSON recovery
        // files are the whole point of that mode. `break_glass` lets
        // endpoints that need a real account (requireAuth) or a live DB
        // (api/admin/maintenance.php) refuse it explicitly.
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
            return [
                'id' => 0,
                'username' => 'admin',
                'role' => 'admin',
                'is_guest' => 0,
                'break_glass' => true,
            ];
        }

        return null;
    }

    /**
     * Require an authenticated ACCOUNT of any role. Exits 401 if not logged
     * in. The break-glass admin is not an account (no users row to own
     * collections, bookmarks, a password...), so it is rejected here and
     * only admitted by requireAdmin().
     */
    public function requireAuth(): array {
        $user = $this->currentUser();
        if (!$user || !empty($user['break_glass'])) {
            $this->error('Authentication required', 401);
        }
        return $user;
    }

    /**
     * Require an admin user (role = 'admin' or 'editor'). Exits 401/403.
     * Callers that need a FULL admin, or a live database, must check
     * `role === 'admin'` / `empty($user['break_glass'])` themselves.
     */
    public function requireAdmin(): array {
        $user = $this->currentUser();
        if (!$user) {
            $this->error('Authentication required', 401);
        }
        $role = $user['role'] ?? null;
        if ($role !== 'admin' && $role !== 'editor') {
            $this->error('Admin access required', 403);
        }
        return $user;
    }

    // =====================================================
    // RESPONSES
    // =====================================================

    /**
     * Send a successful JSON response and exit.
     */
    public function ok(array $payload = [], int $status = 200): void {
        http_response_code($status);
        echo json_encode(array_merge(['success' => true], $payload));
        exit;
    }

    /**
     * Send raw data under a 'data' key and exit.
     */
    public function data($data, int $status = 200): void {
        $this->ok(['data' => $data], $status);
    }

    /**
     * Send an error response and exit.
     */
    public function error(string $message, int $status = 400, array $extra = []): void {
        http_response_code($status);
        echo json_encode(array_merge([
            'success' => false,
            'error' => $message,
        ], $extra));
        exit;
    }

    /**
     * Wrap a callable so any exception becomes a 500 JSON error.
     */
    public function safe(callable $fn): void {
        try {
            $fn($this);
        } catch (\Throwable $e) {
            error_log('[ApiController] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->error('Internal server error', 500);
        }
    }

    // =====================================================
    // SANITIZERS (used to live scattered across save-*.php)
    // =====================================================

    /**
     * Sanitize a plain-text string: strip tags, trim, max length.
     */
    public static function sanitizeText($value, int $maxLength = 255): string {
        if (!is_string($value)) return '';
        $value = strip_tags(trim($value));
        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Sanitize an archive.org identifier (alphanumeric, dash, underscore, dot).
     */
    public static function sanitizeArchiveId($value): string {
        if (!is_string($value)) return '';
        return preg_replace('/[^a-zA-Z0-9_.-]/', '', $value);
    }

    /**
     * Validate a 6-char hex color (#rrggbb).
     */
    public static function sanitizeHexColor($value, string $default = '#000000'): string {
        if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            return $value;
        }
        return $default;
    }

    /**
     * Coerce a value to bool. Accepts "true"/"false"/"1"/"0" strings.
     */
    public static function sanitizeBool($value): bool {
        if (is_bool($value)) return $value;
        if (is_string($value)) {
            $lower = strtolower($value);
            if ($lower === 'true' || $lower === '1' || $lower === 'yes') return true;
            if ($lower === 'false' || $lower === '0' || $lower === 'no' || $lower === '') return false;
        }
        return (bool)$value;
    }

    /**
     * Validate a value is in an allowed enum, else return default.
     */
    public static function sanitizeEnum($value, array $allowed, string $default): string {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
