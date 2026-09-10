<?php
/**
 * UserAuthService
 *
 * Account lifecycle: register, login, logout, remember-me, password reset,
 * email verification. Works with the unified `users` table — both admins
 * and regular viewers live there, distinguished by the `role` column.
 *
 * This replaces the dedicated AdminAuthService over time. During the
 * migration window, AdminAuthService::validateSession() is kept as a
 * thin shim for any code that still reads $_SESSION['admin_user_id'].
 */
class UserAuthService {
    private $repo;
    private $context;
    private $db;

    // Token settings
    const REMEMBER_TTL_DAYS = 30;
    const PASSWORD_RESET_TTL_HOURS = 2;
    const EMAIL_VERIFY_TTL_DAYS = 7;

    // Throttling settings (applies to /api/auth/login)
    const LOGIN_THROTTLE_WINDOW_MINUTES = 10;
    const LOGIN_THROTTLE_MAX_PER_IP = 20;
    const LOGIN_THROTTLE_MAX_PER_IDENTIFIER = 10;

    // Cap how many active password-reset tokens a single user can have at
    // a time. Prevents an attacker who knows a victim's email from
    // spamming the victim's inbox or forcing expensive SMTP traffic.
    const RESET_TOKENS_PER_HOUR = 3;

    // Per-IP cap on forgot-password requests (across ALL emails), so one client
    // can't mail-bomb an inbox list or probe response timing. The per-user cap
    // above doesn't bound this — only the per-account token rate. Recorded in
    // the shared auth_attempts table behind a 'pwreset' marker.
    const RESET_THROTTLE_WINDOW_MINUTES = 60;
    const RESET_THROTTLE_MAX_PER_IP = 10;

    public function __construct(?UserRepository $repo = null, ?UserContext $context = null) {
        $this->repo = $repo ?: new UserRepository();
        $this->context = $context ?: new UserContext();
        $this->db = Database::getInstance();
    }

    // =====================================================
    // CURRENT USER
    // =====================================================

    /**
     * Return the currently-authenticated account user (not a guest),
     * or null if nobody is logged in. Used by ApiController::currentUser().
     */
    public function currentUser(): ?array {
        $user = $this->context->current();
        if (!$user || !empty($user['is_guest'])) {
            return null;
        }
        // Strip secrets before returning
        unset($user['password_hash']);
        return $user;
    }

    // =====================================================
    // REGISTRATION
    // =====================================================

    /**
     * Register a new account.
     *
     * @param array $data ['username', 'email', 'password', 'display_name'?]
     * @return array ['user' => [...], 'errors' => []]
     */
    public function register(array $data): array {
        $username = trim($data['username'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        $displayName = trim($data['display_name'] ?? '') ?: $username;

        $errors = $this->validateRegistration($username, $email, $password);
        if ($errors) {
            return ['user' => null, 'errors' => $errors];
        }

        // Public web signups are ALWAYS low-privilege. The bootstrap admin is
        // created exclusively by install.php (a direct INSERT with
        // role='admin'), never here. The previous "first non-guest account
        // becomes admin" logic ran a non-atomic COUNT-then-insert, which let
        // the first stranger to reach /register.php on a freshly-migrated
        // install (admin not yet created) seize admin — and two concurrent
        // signups could both read 0 and both be created admin (TOCTOU).
        $role = 'viewer';

        $userId = $this->repo->createAccount([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $displayName,
            'role' => $role,
        ]);

        $user = $this->repo->findById($userId);
        unset($user['password_hash']);

        return ['user' => $user, 'errors' => []];
    }

    /**
     * Validation rules for registration. Returns an array of field → message.
     */
    public function validateRegistration(string $username, string $email, string $password): array {
        $errors = [];

        if ($username === '' || strlen($username) < 3 || strlen($username) > 50) {
            $errors['username'] = 'Username must be 3–50 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $errors['username'] = 'Username may only contain letters, numbers, _, ., and -';
        } elseif ($this->repo->findByUsername($username)) {
            $errors['username'] = 'Username is already taken';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address';
        } elseif ($this->repo->findByEmail($email)) {
            $errors['email'] = 'An account with this email already exists';
        }

        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        return $errors;
    }

    // =====================================================
    // LOGIN / LOGOUT
    // =====================================================

    /**
     * Attempt to log in with username-or-email + password.
     * On success, starts a session (and a remember-me cookie if requested)
     * and returns the user. On failure, returns null.
     *
     * Throws RuntimeException if the caller is currently rate-limited
     * (too many recent failed attempts from this IP or for this identifier).
     */
    public function login(string $identifier, string $password, bool $remember = false): ?array {
        // Throttle check FIRST -- before touching the user row -- so attacker
        // can't pump load through the password_verify hash function.
        if ($this->isLoginThrottled($identifier)) {
            throw new RuntimeException('Too many sign-in attempts. Please wait a few minutes and try again.');
        }

        $user = $this->repo->findByIdentifier($identifier);
        if (!$user || empty($user['password_hash'])) {
            $this->recordLoginAttempt($identifier, false);
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            $this->recordLoginAttempt($identifier, false);
            return null;
        }

        // Transparently upgrade the stored hash if PASSWORD_DEFAULT's algorithm
        // or cost has moved on since it was created (e.g. a newer PHP). We have
        // the plaintext here, so re-hash once on a successful login. Best-effort.
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            try {
                $this->repo->updatePassword((int)$user['id'], password_hash($password, PASSWORD_DEFAULT));
            } catch (Throwable $e) {
                error_log('[UserAuthService::login] password rehash failed: ' . $e->getMessage());
            }
        }

        // Regenerate session id to prevent fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_role'] = $user['role'];
        // Rotate the CSRF token on auth state change so a token captured
        // pre-login can't be replayed against post-login endpoints.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        if ($remember) {
            $this->issueRememberToken((int)$user['id']);
        }

        $this->repo->updateLastSeen((int)$user['id']);
        $this->context->refresh();
        $this->recordLoginAttempt($identifier, true);

        unset($user['password_hash']);
        return $user;
    }

    /**
     * Has the current caller exceeded the per-IP or per-identifier cap of
     * failed attempts inside the throttle window? Returns false (allow)
     * if the auth_attempts table is missing -- the throttle is opt-in via
     * migration 005, not required for app function.
     */
    private function isLoginThrottled(string $identifier): bool {
        try {
            $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $idNorm = mb_strtolower(trim($identifier));

            $ipCount = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE ip_hash = ? AND success = 0
                 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$ipHash, self::LOGIN_THROTTLE_WINDOW_MINUTES]
            );
            if ($ipCount >= self::LOGIN_THROTTLE_MAX_PER_IP) return true;

            if ($idNorm !== '') {
                $idCount = (int)$this->db->fetchColumn(
                    "SELECT COUNT(*) FROM auth_attempts
                     WHERE identifier = ? AND success = 0
                     AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                    [$idNorm, self::LOGIN_THROTTLE_WINDOW_MINUTES]
                );
                if ($idCount >= self::LOGIN_THROTTLE_MAX_PER_IDENTIFIER) return true;
            }
        } catch (Throwable $e) {
            // Table missing or other DB issue -- fail open. Operators who
            // want throttling enabled should run migration 005.
            return false;
        }
        return false;
    }

    /**
     * Persist one row per attempt for throttling and audit. Best-effort:
     * a write failure does not block the login flow.
     */
    private function recordLoginAttempt(string $identifier, bool $success): void {
        try {
            $this->db->insert('auth_attempts', [
                'ip_hash' => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                'identifier' => mb_substr(mb_strtolower(trim($identifier)), 0, 255),
                'success' => $success ? 1 : 0,
            ]);
            // Opportunistic cleanup: roll off rows older than 24h on roughly
            // 1% of inserts so the table doesn't grow unbounded. We don't
            // need exact retention -- this is rate-limit state, not audit.
            if (mt_rand(1, 100) === 1) {
                $this->db->query(
                    "DELETE FROM auth_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                );
            }
        } catch (Throwable $e) {
            // Table missing (migration 005 not run) or DB unavailable.
        }
    }

    /**
     * Per-IP throttle for forgot-password. Counts 'pwreset'-marked rows in the
     * shared auth_attempts table (recorded with success=1 so they never count
     * toward the failed-login throttles). Fails open if the table is missing.
     */
    private function isResetThrottledByIp(): bool {
        try {
            $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $count = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM auth_attempts
                 WHERE ip_hash = ? AND identifier = 'pwreset' AND success = 1
                 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$ipHash, self::RESET_THROTTLE_WINDOW_MINUTES]
            );
            return $count >= self::RESET_THROTTLE_MAX_PER_IP;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Record one forgot-password request for the per-IP throttle. Best-effort. */
    private function recordResetAttempt(): void {
        try {
            $this->db->insert('auth_attempts', [
                'ip_hash' => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                'identifier' => 'pwreset',
                'success' => 1,
            ]);
        } catch (Throwable $e) {
            // auth_attempts missing (migration 005 not run) or DB unavailable.
        }
    }

    public function logout(): void {
        // Kill remember cookie + token
        if (!empty($_COOKIE[UserContext::REMEMBER_COOKIE])) {
            $hash = hash('sha256', $_COOKIE[UserContext::REMEMBER_COOKIE]);
            $this->db->delete('user_auth_tokens', 'token_hash = ?', [$hash]);
            setcookie(UserContext::REMEMBER_COOKIE, '', time() - 3600, app_cookie_path());
        }

        unset($_SESSION['user_id'], $_SESSION['user_role']);
        session_regenerate_id(true);
        // Rotate CSRF token on logout so any captured token can't be replayed.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $this->context->refresh();
    }

    // =====================================================
    // GUEST MERGE
    // =====================================================

    /**
     * Move a guest user's bookmarks, history, and search history
     * into an account user, then delete the guest row.
     */
    public function mergeGuest(int $guestId, int $accountId): void {
        $this->repo->mergeGuestInto($guestId, $accountId);
        $this->context->refresh();
    }

    // =====================================================
    // PASSWORD
    // =====================================================

    /**
     * Change password for the currently-authenticated user.
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): bool {
        $user = $this->db->fetchOne(
            "SELECT password_hash FROM users WHERE id = ?",
            [$userId]
        );
        if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
            return false;
        }
        if (strlen($newPassword) < 8) {
            return false;
        }
        $this->repo->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        return true;
    }

    /**
     * Start a password reset — issues a one-time token. Caller is
     * responsible for emailing it via MailService.
     * Returns the raw token (not stored — only the hash is), or null if
     * the email is unknown OR the user has already hit the per-hour cap
     * (so an attacker can't spam a victim's inbox).
     */
    public function startPasswordReset(string $email): ?array {
        // Per-IP throttle (across all emails): treated exactly like "email
        // unknown" (return null) so the caller's response is identical and the
        // throttle itself isn't observable. Recorded before the lookup so the
        // counter reflects every request from this client.
        if ($this->isResetThrottledByIp()) {
            return null;
        }
        $this->recordResetAttempt();

        $user = $this->repo->findByEmail($email);
        if (!$user) return null;

        // Per-user cap: at most N active reset tokens per hour. Returns null
        // to the caller, which treats it identically to "email unknown" so
        // attackers can't enumerate accounts by triggering rate-limit
        // responses.
        try {
            $recent = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM user_auth_tokens
                 WHERE user_id = ? AND purpose = 'password_reset'
                 AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [(int)$user['id']]
            );
            if ($recent >= self::RESET_TOKENS_PER_HOUR) {
                return null;
            }

            // Invalidate prior unused reset tokens for this user so only
            // the freshest one works. Without this every issued token
            // remained valid until its 2h TTL.
            $this->db->query(
                "UPDATE user_auth_tokens SET used_at = NOW()
                 WHERE user_id = ? AND purpose = 'password_reset' AND used_at IS NULL",
                [(int)$user['id']]
            );
        } catch (Throwable $e) {
            // Token table issue -- log and continue (the issue is more
            // critical to surface than to silently rate-limit).
            error_log('[UserAuthService::startPasswordReset] token bookkeeping: ' . $e->getMessage());
        }

        $rawToken = bin2hex(random_bytes(32));
        $this->storeToken((int)$user['id'], $rawToken, 'password_reset',
            '+' . self::PASSWORD_RESET_TTL_HOURS . ' hours');

        return ['user' => $user, 'token' => $rawToken];
    }

    /**
     * Complete a password reset given the raw token and new password.
     */
    public function completePasswordReset(string $rawToken, string $newPassword): bool {
        if (strlen($newPassword) < 8) return false;

        $hash = hash('sha256', $rawToken);
        $token = $this->db->fetchOne(
            "SELECT id, user_id, expires_at, used_at FROM user_auth_tokens
             WHERE token_hash = ? AND purpose = 'password_reset'",
            [$hash]
        );
        if (!$token || $token['used_at'] !== null || strtotime($token['expires_at']) < time()) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $this->repo->updatePassword((int)$token['user_id'],
                password_hash($newPassword, PASSWORD_DEFAULT));
            $this->db->query(
                "UPDATE user_auth_tokens SET used_at = NOW() WHERE id = ?",
                [$token['id']]
            );
            // Invalidate every other reset + remember token for this user
            $this->db->delete('user_auth_tokens',
                "user_id = ? AND purpose IN ('password_reset','remember') AND id != ?",
                [(int)$token['user_id'], (int)$token['id']]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            error_log('[UserAuthService::completePasswordReset] ' . $e->getMessage());
            return false;
        }
    }

    // =====================================================
    // EMAIL VERIFICATION
    // =====================================================

    public function startEmailVerification(int $userId): string {
        $rawToken = bin2hex(random_bytes(32));
        $this->storeToken($userId, $rawToken, 'email_verify',
            '+' . self::EMAIL_VERIFY_TTL_DAYS . ' days');
        return $rawToken;
    }

    public function completeEmailVerification(string $rawToken): bool {
        $hash = hash('sha256', $rawToken);
        $token = $this->db->fetchOne(
            "SELECT id, user_id, expires_at, used_at FROM user_auth_tokens
             WHERE token_hash = ? AND purpose = 'email_verify'",
            [$hash]
        );
        if (!$token || $token['used_at'] !== null || strtotime($token['expires_at']) < time()) {
            return false;
        }

        $this->repo->setEmailVerified((int)$token['user_id']);
        $this->db->query(
            "UPDATE user_auth_tokens SET used_at = NOW() WHERE id = ?",
            [$token['id']]
        );
        return true;
    }

    // =====================================================
    // TOKEN STORAGE HELPERS
    // =====================================================

    private function storeToken(int $userId, string $rawToken, string $purpose, string $expires): void {
        // mb_strcut byte-truncates at a UTF-8 boundary so a partial multibyte
        // sequence can't end up stored in the utf8mb4 column.
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua !== '') {
            $ua = function_exists('mb_strcut')
                ? mb_strcut($ua, 0, 500, 'UTF-8')
                : substr($ua, 0, 500);
        }
        $this->db->insert('user_auth_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawToken),
            'purpose' => $purpose,
            'user_agent' => $ua,
            'ip_hash' => hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            'expires_at' => date('Y-m-d H:i:s', strtotime($expires)),
        ]);
    }

    /**
     * Mint a remember-me token for $userId and set the cookie. Public so
     * UserContext can rotate the token on every successful remember-me
     * login through this one code path (same TTL, same cookie params).
     */
    public function issueRememberToken(int $userId): void {
        $rawToken = bin2hex(random_bytes(32));
        $this->storeToken($userId, $rawToken, 'remember',
            '+' . self::REMEMBER_TTL_DAYS . ' days');

        // Use the centralized is_https() so the Secure flag follows behind
        // CDNs / proxies that terminate TLS upstream (X-Forwarded-Proto).
        setcookie(
            UserContext::REMEMBER_COOKIE,
            $rawToken,
            [
                'expires' => time() + (self::REMEMBER_TTL_DAYS * 86400),
                'path' => app_cookie_path(),
                'secure' => is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}
