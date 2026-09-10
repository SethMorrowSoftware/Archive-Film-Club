<?php
/**
 * MetricsService — aggregates data for the admin metrics panels.
 *
 * Reads from existing tables (users, user_watch_history, search_history,
 * popular_searches, video_comments, user_bookmarks) — no new tables.
 *
 * All counts gracefully degrade to 0 when a table doesn't exist yet
 * (e.g. before migration 006 has run), so panels never throw on partial
 * installs.
 */
class MetricsService {

    /** @var Database */
    private $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: Database::getInstance();
    }

    // =====================================================
    // TOP-LEVEL OVERVIEW (dashboard cards)
    // =====================================================

    public function overview(): array {
        return [
            'users' => $this->userTotals(),
            'engagement' => $this->engagementTotals(),
            'content' => $this->contentTotals(),
        ];
    }

    public function userTotals(): array {
        $accounts = $this->intQuery(
            "SELECT COUNT(*) FROM users WHERE is_guest = 0"
        );
        $guests = $this->intQuery(
            "SELECT COUNT(*) FROM users WHERE is_guest = 1"
        );
        $admins = $this->intQuery(
            "SELECT COUNT(*) FROM users WHERE is_guest = 0 AND role IN ('admin','editor')"
        );
        $newToday = $this->intQuery(
            "SELECT COUNT(*) FROM users
             WHERE is_guest = 0 AND created_at > (NOW() - INTERVAL 1 DAY)"
        );
        $new7d = $this->intQuery(
            "SELECT COUNT(*) FROM users
             WHERE is_guest = 0 AND created_at > (NOW() - INTERVAL 7 DAY)"
        );
        $new30d = $this->intQuery(
            "SELECT COUNT(*) FROM users
             WHERE is_guest = 0 AND created_at > (NOW() - INTERVAL 30 DAY)"
        );
        $active24h = $this->intQuery(
            "SELECT COUNT(*) FROM users
             WHERE is_guest = 0 AND last_seen > (NOW() - INTERVAL 1 DAY)"
        );
        $active7d = $this->intQuery(
            "SELECT COUNT(*) FROM users
             WHERE is_guest = 0 AND last_seen > (NOW() - INTERVAL 7 DAY)"
        );
        $active30d = $this->intQuery(
            "SELECT COUNT(*) FROM users
             WHERE is_guest = 0 AND last_seen > (NOW() - INTERVAL 30 DAY)"
        );
        return [
            'accounts' => $accounts,
            'guests' => $guests,
            'admins' => $admins,
            'new_today' => $newToday,
            'new_7d' => $new7d,
            'new_30d' => $new30d,
            'active_24h' => $active24h,
            'active_7d' => $active7d,
            'active_30d' => $active30d,
        ];
    }

    public function engagementTotals(): array {
        $bookmarks = $this->intQuery("SELECT COUNT(*) FROM user_bookmarks");
        $watchEvents = $this->intQuery("SELECT COUNT(*) FROM user_watch_history");
        $watch24h = $this->intQuery(
            "SELECT COUNT(*) FROM user_watch_history
             WHERE last_watched > (NOW() - INTERVAL 1 DAY)"
        );
        $watch7d = $this->intQuery(
            "SELECT COUNT(*) FROM user_watch_history
             WHERE last_watched > (NOW() - INTERVAL 7 DAY)"
        );
        $comments = $this->intQuery(
            "SELECT COUNT(*) FROM video_comments WHERE status = 'visible'"
        );
        $comments24h = $this->intQuery(
            "SELECT COUNT(*) FROM video_comments
             WHERE status = 'visible' AND created_at > (NOW() - INTERVAL 1 DAY)"
        );
        $comments7d = $this->intQuery(
            "SELECT COUNT(*) FROM video_comments
             WHERE status = 'visible' AND created_at > (NOW() - INTERVAL 7 DAY)"
        );
        $reportsPending = $this->intQuery(
            "SELECT COUNT(*) FROM comment_reports WHERE resolved_at IS NULL"
        );
        return [
            'bookmarks_total' => $bookmarks,
            'watch_events_total' => $watchEvents,
            'watch_24h' => $watch24h,
            'watch_7d' => $watch7d,
            'comments_total' => $comments,
            'comments_24h' => $comments24h,
            'comments_7d' => $comments7d,
            'reports_pending' => $reportsPending,
        ];
    }

    public function contentTotals(): array {
        $picks = $this->intQuery("SELECT COUNT(*) FROM recommended_videos WHERE enabled = 1");
        $sections = $this->intQuery("SELECT COUNT(*) FROM featured_sections");
        $searches = $this->intQuery("SELECT COUNT(*) FROM search_history");
        $searches7d = $this->intQuery(
            "SELECT COUNT(*) FROM search_history
             WHERE searched_at > (NOW() - INTERVAL 7 DAY)"
        );
        return [
            'staff_picks' => $picks,
            'sections' => $sections,
            'searches_total' => $searches,
            'searches_7d' => $searches7d,
        ];
    }

    // =====================================================
    // TIME SERIES (charts)
    // =====================================================

    /**
     * Daily counts for the past N days. Returns one row per day, padded
     * with zeros for days that had no events.
     *
     * $metric: 'signups' | 'comments' | 'views' | 'searches'
     */
    public function dailySeries(string $metric, int $days = 30): array {
        $days = max(1, min(365, $days));
        $sql = $this->dailySeriesSql($metric);
        if (!$sql) return $this->padDays([], $days);

        try {
            $rows = $this->db->fetchAll($sql, [$days]);
        } catch (Throwable $e) {
            error_log('[MetricsService::dailySeries] ' . $metric . ': ' . $e->getMessage());
            return $this->padDays([], $days);
        }

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['day']] = (int)$r['count'];
        }
        return $this->padDays($byDay, $days);
    }

    private function dailySeriesSql(string $metric): ?string {
        switch ($metric) {
            case 'signups':
                return "SELECT DATE(created_at) AS day, COUNT(*) AS count
                        FROM users
                        WHERE is_guest = 0
                          AND created_at > (NOW() - INTERVAL ? DAY)
                        GROUP BY day ORDER BY day";
            case 'comments':
                return "SELECT DATE(created_at) AS day, COUNT(*) AS count
                        FROM video_comments
                        WHERE status = 'visible'
                          AND created_at > (NOW() - INTERVAL ? DAY)
                        GROUP BY day ORDER BY day";
            case 'views':
                return "SELECT DATE(last_watched) AS day, COUNT(*) AS count
                        FROM user_watch_history
                        WHERE last_watched > (NOW() - INTERVAL ? DAY)
                        GROUP BY day ORDER BY day";
            case 'searches':
                return "SELECT DATE(searched_at) AS day, COUNT(*) AS count
                        FROM search_history
                        WHERE searched_at > (NOW() - INTERVAL ? DAY)
                        GROUP BY day ORDER BY day";
            default:
                return null;
        }
    }

    /**
     * Fill in zero values for days with no events so charts render a
     * continuous line.
     */
    private function padDays(array $byDay, int $days): array {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $out[] = ['day' => $d, 'count' => $byDay[$d] ?? 0];
        }
        return $out;
    }

    // =====================================================
    // TOP LISTS
    // =====================================================

    public function topVideos(int $limit = 10, int $days = 30): array {
        $limit = max(1, min(100, $limit));
        $days = max(1, min(365, $days));
        try {
            // user_watch_history has no title column; the title lives in
            // video_metadata_cache (unique on archive_id, so MAX() over the
            // LEFT JOIN is a no-op that keeps the GROUP BY happy). Fall back
            // to the identifier for items whose metadata was never cached.
            return $this->db->fetchAll(
                "SELECT h.archive_id,
                        COALESCE(MAX(m.title), h.archive_id) AS title,
                        COUNT(DISTINCT h.user_id) AS unique_viewers,
                        COUNT(*) AS sessions
                 FROM user_watch_history h
                 LEFT JOIN video_metadata_cache m ON m.archive_id = h.archive_id
                 WHERE h.last_watched > (NOW() - INTERVAL ? DAY)
                 GROUP BY h.archive_id
                 ORDER BY unique_viewers DESC, sessions DESC
                 LIMIT $limit",
                [$days]
            );
        } catch (Throwable $e) {
            error_log('[MetricsService::topVideos] ' . $e->getMessage());
            return [];
        }
    }

    public function topSearches(int $limit = 10): array {
        $limit = max(1, min(100, $limit));
        try {
            return $this->db->fetchAll(
                "SELECT query, search_count
                 FROM popular_searches
                 ORDER BY search_count DESC
                 LIMIT $limit"
            );
        } catch (Throwable $e) {
            error_log('[MetricsService::topSearches] popular_searches: ' . $e->getMessage());
            // Fall back to search_history if popular_searches isn't populated.
            try {
                return $this->db->fetchAll(
                    "SELECT query, COUNT(*) AS search_count
                     FROM search_history
                     WHERE query IS NOT NULL AND query <> ''
                     GROUP BY query
                     ORDER BY search_count DESC
                     LIMIT $limit"
                );
            } catch (Throwable $e2) {
                error_log('[MetricsService::topSearches] search_history: ' . $e2->getMessage());
                return [];
            }
        }
    }

    public function topCommenters(int $limit = 10, int $days = 30): array {
        $limit = max(1, min(100, $limit));
        $days = max(1, min(365, $days));
        try {
            return $this->db->fetchAll(
                "SELECT u.id, u.username, u.display_name,
                        COUNT(c.id) AS comment_count
                 FROM video_comments c
                 JOIN users u ON u.id = c.user_id
                 WHERE c.status = 'visible'
                   AND c.created_at > (NOW() - INTERVAL ? DAY)
                 GROUP BY u.id
                 ORDER BY comment_count DESC
                 LIMIT $limit",
                [$days]
            );
        } catch (Throwable $e) {
            error_log('[MetricsService::topCommenters] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param bool $includeEmail Email addresses are personal data; only a
     *   FULL admin (not an editor) gets them. The API passes the caller's role.
     */
    public function recentSignups(int $limit = 10, bool $includeEmail = true): array {
        $limit = max(1, min(100, $limit));
        $emailCol = $includeEmail ? 'email' : 'NULL AS email';
        try {
            return $this->db->fetchAll(
                "SELECT id, username, display_name, $emailCol, role, created_at, last_seen
                 FROM users
                 WHERE is_guest = 0
                 ORDER BY created_at DESC
                 LIMIT $limit"
            );
        } catch (Throwable $e) {
            error_log('[MetricsService::recentSignups] ' . $e->getMessage());
            return [];
        }
    }

    public function recentComments(int $limit = 10): array {
        $limit = max(1, min(100, $limit));
        try {
            return $this->db->fetchAll(
                "SELECT c.id, c.archive_id, c.body, c.status, c.created_at,
                        c.user_id, u.username,
                        COALESCE(u.display_name, u.username, '[deleted]') AS display_name
                 FROM video_comments c
                 LEFT JOIN users u ON u.id = c.user_id
                 WHERE c.status = 'visible'
                 ORDER BY c.created_at DESC
                 LIMIT $limit"
            );
        } catch (Throwable $e) {
            error_log('[MetricsService::recentComments] ' . $e->getMessage());
            return [];
        }
    }

    // =====================================================
    // USERS LIST (admin Users panel)
    // =====================================================

    /**
     * $opts: page, per_page, role (all|admin|editor|viewer), search, and
     * include_email (bool, default true) — email addresses are personal data
     * and are only returned to a FULL admin; the API passes the caller's role.
     */
    public function listUsers(array $opts = []): array {
        $page = max(1, (int)($opts['page'] ?? 1));
        $perPage = max(10, min(100, (int)($opts['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;
        $role = $opts['role'] ?? 'all';     // all | admin | editor | viewer
        $search = trim((string)($opts['search'] ?? ''));
        $includeEmail = !array_key_exists('include_email', $opts) || (bool)$opts['include_email'];

        $where = ["is_guest = 0"];
        $params = [];
        if (in_array($role, ['admin', 'editor', 'viewer'], true)) {
            $where[] = "role = ?";
            $params[] = $role;
        }
        if ($search !== '') {
            $where[] = "(username LIKE ? OR email LIKE ? OR display_name LIKE ?)";
            // Escape LIKE metacharacters so "%" / "_" in the search box match
            // literally instead of turning the filter into a wildcard.
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE $whereSql",
            $params
        );

        $emailCol = $includeEmail ? 'u.email' : 'NULL AS email';
        $rows = $this->db->fetchAll(
            "SELECT u.id, u.username, $emailCol, u.display_name, u.role,
                    u.email_verified_at, u.created_at, u.last_seen,
                    (SELECT COUNT(*) FROM user_bookmarks b WHERE b.user_id = u.id) AS bookmark_count,
                    (SELECT COUNT(*) FROM user_watch_history h WHERE h.user_id = u.id) AS watch_count
             FROM users u
             WHERE $whereSql
             ORDER BY u.created_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        // Comment counts in a single query (might be missing if migration 006 not run)
        $commentCounts = [];
        try {
            if (!empty($rows)) {
                $ids = array_map(fn($r) => (int)$r['id'], $rows);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $cc = $this->db->fetchAll(
                    "SELECT user_id, COUNT(*) AS c
                     FROM video_comments
                     WHERE user_id IN ($in) AND status = 'visible'
                     GROUP BY user_id",
                    $ids
                );
                foreach ($cc as $row) {
                    $commentCounts[(int)$row['user_id']] = (int)$row['c'];
                }
            }
        } catch (Throwable $e) {
            // video_comments missing (migration 006 not run) — counts stay 0.
            error_log('[MetricsService::listUsers] comment counts: ' . $e->getMessage());
        }

        foreach ($rows as &$r) {
            $r['comment_count'] = $commentCounts[(int)$r['id']] ?? 0;
        }
        unset($r);

        return [
            'users' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int)ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Change a user's role. Only admins should be able to call this
     * (enforced at the API boundary).
     */
    public function setRole(int $userId, string $role): void {
        if (!in_array($role, ['admin', 'editor', 'viewer'], true)) {
            throw new RuntimeException("Invalid role");
        }
        // Never let the org strip its last administrator: that locks everyone
        // out of the admin panel with no UI recovery. Only relevant when this
        // change *removes* admin from someone who currently holds it. The API
        // already blocks demoting your *own* role, but this guard lives in the
        // service so it also covers a second admin (or a stale admin session)
        // demoting the real last admin through the same endpoint.
        if ($role !== 'admin') {
            $currentRole = (string)$this->db->fetchColumn(
                "SELECT role FROM users WHERE id = ? AND is_guest = 0",
                [$userId]
            );
            if ($currentRole === 'admin') {
                $adminCount = (int)$this->db->fetchColumn(
                    "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_guest = 0"
                );
                if ($adminCount <= 1) {
                    throw new RuntimeException("You can't remove the last administrator.");
                }
            }
        }
        $this->db->update('users', ['role' => $role], 'id = ? AND is_guest = 0', [$userId]);
    }

    // =====================================================
    // COMMENT MODERATION (admin Comments panel)
    // =====================================================

    public function listCommentsForModeration(array $opts = []): array {
        $page = max(1, (int)($opts['page'] ?? 1));
        $perPage = max(10, min(100, (int)($opts['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;
        $filter = $opts['filter'] ?? 'all';   // all | reported | hidden | recent

        $where = ["1=1"];
        $joins = "";
        $params = [];

        if ($filter === 'reported') {
            $joins = " JOIN (SELECT DISTINCT comment_id FROM comment_reports WHERE resolved_at IS NULL) r ON r.comment_id = c.id ";
        } elseif ($filter === 'hidden') {
            $where[] = "c.status = 'hidden'";
        } elseif ($filter === 'recent') {
            $where[] = "c.created_at > (NOW() - INTERVAL 7 DAY)";
            $where[] = "c.status <> 'deleted'";
        } else {
            $where[] = "c.status <> 'deleted'";
        }

        $whereSql = implode(' AND ', $where);

        try {
            $total = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM video_comments c $joins WHERE $whereSql",
                $params
            );
            $rows = $this->db->fetchAll(
                "SELECT c.id, c.archive_id, c.body, c.status, c.parent_id,
                        c.like_count, c.created_at, c.edited_at,
                        u.id AS user_id, u.username,
                        COALESCE(u.display_name, u.username, '[deleted]') AS display_name, u.role,
                        (SELECT COUNT(*) FROM comment_reports r WHERE r.comment_id = c.id AND r.resolved_at IS NULL) AS report_count
                 FROM video_comments c
                 LEFT JOIN users u ON u.id = c.user_id
                 $joins
                 WHERE $whereSql
                 ORDER BY c.created_at DESC
                 LIMIT $perPage OFFSET $offset",
                $params
            );
        } catch (Throwable $e) {
            error_log('[MetricsService::listCommentsForModeration] ' . $e->getMessage());
            return [
                'comments' => [],
                'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'pages' => 1],
                'unavailable' => true,
            ];
        }

        return [
            'comments' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int)ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Mark all reports against a comment as resolved by the given admin.
     */
    public function resolveReportsFor(int $commentId, int $adminId): void {
        try {
            $this->db->update(
                'comment_reports',
                ['resolved_at' => date('Y-m-d H:i:s'), 'resolved_by' => $adminId],
                'comment_id = ? AND resolved_at IS NULL',
                [$commentId]
            );
        } catch (Throwable $e) {
            // comment_reports missing (migration 006 not run).
            error_log('[MetricsService::resolveReportsFor] ' . $e->getMessage());
        }
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * Run a COUNT-style query and return 0 if the table doesn't exist
     * (graceful degradation pre-migration).
     */
    private function intQuery(string $sql, array $params = []): int {
        try {
            return (int)$this->db->fetchColumn($sql, $params);
        } catch (Throwable $e) {
            // Logged (not silent) so a broken query — as opposed to a table
            // that simply isn't there yet — is visible in the error log.
            error_log('[MetricsService::intQuery] ' . $e->getMessage());
            return 0;
        }
    }
}
