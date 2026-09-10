<?php
/**
 * UserContext
 *
 * Resolves the "current user" for a request. A visitor is always a user;
 * the question is just whether they're a guest (anonymous, session-bound)
 * or a signed-in account.
 *
 * Resolution order for the current user:
 *   1. $_SESSION['user_id']    ← set by UserAuthService::login()
 *   2. remember-me cookie      ← set by UserAuthService::login(remember=true)
 *   3. Guest row keyed by PHP session id, auto-created if missing
 *
 * Everything that needs to know "which user is making this request"
 * should go through UserContext — never touch $_SESSION directly.
 */
class UserContext {
    private $repo;
    /** @var array|null */
    private $cached = null;

    const REMEMBER_COOKIE = 'afc_remember';

    public function __construct(?UserRepository $repo = null) {
        $this->repo = $repo ?: new UserRepository();
    }

    /**
     * Get the current user (guest or account). Guaranteed non-null for
     * any web request after bootstrap.php has started a session.
     */
    public function current(): array {
        if ($this->cached !== null) {
            return $this->cached;
        }

        // 1. Session-authenticated account user
        if (!empty($_SESSION['user_id'])) {
            $user = $this->repo->findById((int)$_SESSION['user_id']);
            if ($user && !$user['is_guest']) {
                $this->repo->updateLastSeen($user['id']);
                return $this->cached = $user;
            }
            unset($_SESSION['user_id']);
        }

        // 2. Remember-me cookie
        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            $user = $this->tryRememberCookie($_COOKIE[self::REMEMBER_COOKIE]);
            if ($user) {
                // Promoting an anonymous session to an authenticated one is
                // an auth state change — apply the same defenses as
                // UserAuthService::login(): a fresh session id (fixation)
                // and a fresh CSRF token (a token captured pre-login can't
                // be replayed against authenticated endpoints).
                if (!headers_sent()) {
                    session_regenerate_id(true);
                }
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'] ?? 'viewer';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $this->repo->updateLastSeen($user['id']);
                return $this->cached = $user;
            }
        }

        // 3. Guest user keyed by PHP session id
        $sessionId = session_id();
        $guest = $this->repo->findBySessionId($sessionId);
        if (!$guest) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            // Crawlers never sign in, bookmark or watch, so a users row for
            // each of their sessions is pure churn (cron/cache_cleanup.php
            // reaps idle guests, but there's no reason to create them). Hand
            // back an in-memory guest instead; write paths check
            // isTransient() and no-op for it.
            if (self::isCrawler($userAgent)) {
                return $this->cached = self::transientGuest();
            }
            $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $id = $this->repo->createGuest($sessionId, $userAgent, $ipHash);
            $guest = $this->repo->findById($id);
        } else {
            $this->repo->updateLastSeen($guest['id']);
        }

        return $this->cached = $guest;
    }

    /**
     * Is the current visitor a transient (in-memory, id 0) guest — i.e. a
     * crawler for which no users row exists? Anything that would INSERT a
     * row keyed on the current user id must check this first: there is no
     * row to own it, and the FK would reject user_id 0.
     */
    public function isTransient(): bool {
        return !empty($this->current()['transient']);
    }

    /**
     * Obvious bots by User-Agent. Deliberately conservative — a false
     * positive only costs that visitor server-side bookmarks/history for
     * the session, but a false negative is just one more guest row.
     */
    public static function isCrawler(?string $userAgent): bool {
        if ($userAgent === null || $userAgent === '') {
            return false;
        }
        return (bool)preg_match('/bot|crawl|spider|slurp|facebookexternalhit|Googlebot/i', $userAgent);
    }

    /**
     * A guest that exists only for this request. Same keys as a hydrated
     * users row so every reader (header partial, api/auth/me.php, comment
     * serialization) works unchanged; id 0 never matches a real row.
     */
    private static function transientGuest(): array {
        return [
            'id' => 0,
            'username' => null,
            'email' => null,
            'display_name' => null,
            'avatar_url' => null,
            'role' => 'guest',
            'is_guest' => true,
            'session_id' => null,
            'email_verified_at' => null,
            'preferences' => [],
            'created_at' => null,
            'last_seen' => null,
            'transient' => true,
        ];
    }

    /**
     * Current user's id (guest or account).
     */
    public function currentId(): int {
        return (int)$this->current()['id'];
    }

    /**
     * Is the current visitor an authenticated account (not a guest)?
     */
    public function isAuthenticated(): bool {
        return !$this->current()['is_guest'];
    }

    /**
     * Is the current user an admin (role = admin or editor)?
     */
    public function isAdmin(): bool {
        $role = $this->current()['role'] ?? 'guest';
        return $role === 'admin' || $role === 'editor';
    }

    /**
     * Flush the in-request cache. Call after login/logout/merge so the
     * next current() returns the updated identity.
     */
    public function refresh(): void {
        $this->cached = null;
    }

    /**
     * Get the current guest id, if the visitor is currently a guest,
     * without prompting creation. Used by the signup flow to hand the
     * guest row off to UserRepository::mergeGuestInto().
     */
    public function pendingGuestId(): ?int {
        if (!empty($_SESSION['user_id'])) {
            return null; // already authenticated
        }
        $guest = $this->repo->findBySessionId(session_id());
        return $guest && $guest['is_guest'] ? (int)$guest['id'] : null;
    }

    // =====================================================
    // REMEMBER-ME COOKIE
    // =====================================================

    /**
     * Try to resolve a remember-me cookie value ("tokenId:rawToken")
     * into a user. Returns the user on success, null on failure (and
     * silently clears an invalid cookie).
     */
    private function tryRememberCookie(string $cookieValue): ?array {
        $db = Database::getInstance();
        $hash = hash('sha256', $cookieValue);

        $token = $db->fetchOne(
            "SELECT user_id, expires_at FROM user_auth_tokens
             WHERE token_hash = ? AND purpose = 'remember' AND used_at IS NULL",
            [$hash]
        );

        if (!$token) {
            setcookie(self::REMEMBER_COOKIE, '', time() - 3600, app_cookie_path());
            return null;
        }
        if (strtotime($token['expires_at']) < time()) {
            $db->delete('user_auth_tokens', 'token_hash = ?', [$hash]);
            setcookie(self::REMEMBER_COOKIE, '', time() - 3600, app_cookie_path());
            return null;
        }

        $user = $this->repo->findById((int)$token['user_id']);
        if (!$user || !empty($user['is_guest'])) {
            $db->delete('user_auth_tokens', 'token_hash = ?', [$hash]);
            setcookie(self::REMEMBER_COOKIE, '', time() - 3600, app_cookie_path());
            return null;
        }

        // Single-use: rotate the token on every successful use. A stolen
        // cookie value is then only good until its owner next visits, and
        // the collision surfaces as an unexpected sign-out instead of a
        // silent 30-day shadow session. Mint the replacement FIRST (same
        // cookie params as login, via UserAuthService) and only then retire
        // the used row, so a failure mid-rotation leaves the old token
        // working rather than the user logged out. Skipped when headers
        // are already out — setcookie() couldn't deliver the new value.
        if (!headers_sent()) {
            try {
                $auth = new UserAuthService($this->repo, $this);
                $auth->issueRememberToken((int)$user['id']);
                $db->delete('user_auth_tokens', 'token_hash = ?', [$hash]);
            } catch (Throwable $e) {
                error_log('[UserContext] remember-token rotation failed: ' . $e->getMessage());
            }
        }

        return $user;
    }
}
