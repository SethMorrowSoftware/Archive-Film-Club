<?php
/**
 * CommentService
 *
 * All comments live in this database only. Nothing in this service ever
 * makes an outbound write to archive.org — comments are private to the
 * Archive Film Club community and stay on this site.
 *
 * Threading is flat at one level: top-level comments have parent_id NULL,
 * replies have parent_id pointing at a top-level comment. "Reply to a
 * reply" still attaches to the top-level parent so the thread doesn't
 * deep-nest.
 */
class CommentService {

    const MAX_BODY = 2000;
    const MIN_BODY = 1;

    // Rate limits: window seconds → max comments allowed
    const RATE_LIMIT_WINDOW = 600;   // 10 min
    const RATE_LIMIT_MAX = 10;       // 10 comments per 10 min per user

    const DEFAULT_PAGE_SIZE = 20;
    const REPLY_PAGE_SIZE = 50;

    /** @var Database */
    private $db;
    /** @var UserContext */
    private $context;

    public function __construct(?Database $db = null, ?UserContext $context = null) {
        $this->db = $db ?: Database::getInstance();
        $this->context = $context ?: new UserContext();
    }

    // =====================================================
    // READ
    // =====================================================

    /**
     * Get a page of top-level comments for an archive item, plus the
     * first batch of replies for each thread. Returns a shape ready to
     * serialize for the player UI.
     */
    public function listForVideo(string $archiveId, array $opts = []): array {
        $sort = $opts['sort'] ?? 'top';            // 'top' | 'newest'
        $page = max(1, (int)($opts['page'] ?? 1));
        $perPage = self::DEFAULT_PAGE_SIZE;
        $offset = ($page - 1) * $perPage;

        $orderBy = $sort === 'newest'
            ? 'c.created_at DESC'
            : 'c.like_count DESC, c.created_at DESC';

        $rows = $this->db->fetchAll(
            "SELECT c.id, c.user_id, c.parent_id, c.body, c.status,
                    c.like_count, c.reply_count, c.edited_at, c.created_at,
                    u.username, u.display_name, u.role
             FROM video_comments c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.archive_id = ?
               AND c.parent_id IS NULL
               AND c.status <> 'hidden'
             ORDER BY $orderBy
             LIMIT $perPage OFFSET $offset",
            [$archiveId]
        );

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM video_comments
             WHERE archive_id = ? AND parent_id IS NULL AND status <> 'hidden'",
            [$archiveId]
        );

        $viewerId = $this->context->currentId();
        $likedSet = $this->fetchLikedSet($rows, $viewerId);

        // Fetch first batch of replies for each top-level comment that
        // has any. Single round-trip rather than N queries.
        $repliesByParent = [];
        $topIds = array_map(fn($r) => (int)$r['id'], $rows);
        if (!empty($topIds)) {
            $in = implode(',', array_fill(0, count($topIds), '?'));
            $replyRows = $this->db->fetchAll(
                "SELECT c.id, c.user_id, c.parent_id, c.body, c.status,
                        c.like_count, c.edited_at, c.created_at,
                        u.username, u.display_name, u.role
                 FROM video_comments c
                 LEFT JOIN users u ON u.id = c.user_id
                 WHERE c.parent_id IN ($in) AND c.status <> 'hidden'
                 ORDER BY c.created_at ASC",
                $topIds
            );
            $replyLiked = $this->fetchLikedSet($replyRows, $viewerId);
            foreach ($replyRows as $r) {
                $pid = (int)$r['parent_id'];
                if (!isset($repliesByParent[$pid])) $repliesByParent[$pid] = [];
                $repliesByParent[$pid][] = $this->serializeRow($r, $viewerId, $replyLiked);
            }
        }

        $comments = [];
        foreach ($rows as $r) {
            $node = $this->serializeRow($r, $viewerId, $likedSet);
            $node['replies'] = $repliesByParent[(int)$r['id']] ?? [];
            $comments[] = $node;
        }

        return [
            'comments' => $comments,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($offset + count($comments)) < $total,
            ],
            'sort' => $sort,
        ];
    }

    /**
     * Get additional replies for a single thread (used by "show more replies").
     */
    public function listReplies(int $parentId, int $afterId = 0): array {
        // The parent must itself be shown. listForVideo() never emits a
        // moderator-hidden top-level comment (nor its first page of
        // replies), so this endpoint must not hand them out either when
        // called with a guessed/remembered parent id. Soft-deleted parents
        // stay: they still render (as "[deleted]") with their thread, which
        // is the whole point of soft delete. Enforced with an inner JOIN on
        // the parent row so it is a single query.
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.user_id, c.parent_id, c.body, c.status,
                    c.like_count, c.edited_at, c.created_at,
                    u.username, u.display_name, u.role
             FROM video_comments c
             JOIN video_comments p ON p.id = c.parent_id AND p.status <> 'hidden'
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.parent_id = ? AND c.id > ? AND c.status <> 'hidden'
             ORDER BY c.created_at ASC
             LIMIT " . self::REPLY_PAGE_SIZE,
            [$parentId, $afterId]
        );

        $viewerId = $this->context->currentId();
        $likedSet = $this->fetchLikedSet($rows, $viewerId);

        $replies = array_map(
            fn($r) => $this->serializeRow($r, $viewerId, $likedSet),
            $rows
        );
        return ['replies' => $replies];
    }

    // =====================================================
    // WRITE
    // =====================================================

    /**
     * Post a new comment or reply. Returns the serialized new comment
     * row. Throws RuntimeException with a user-facing message on any
     * validation/limit failure.
     */
    public function post(string $archiveId, string $body, ?int $parentId = null): array {
        $user = $this->requireAccount();

        $body = self::sanitizeBody($body);
        $this->validateBody($body);
        $this->enforceRateLimit((int)$user['id']);

        if ($parentId !== null) {
            // Walk to the top-level parent. Replies to replies attach
            // to the top-level so the thread stays flat.
            $parent = $this->db->fetchOne(
                "SELECT id, parent_id, archive_id, status FROM video_comments WHERE id = ?",
                [$parentId]
            );
            if (!$parent || $parent['status'] === 'deleted' || $parent['archive_id'] !== $archiveId) {
                throw new RuntimeException("Reply target not found");
            }
            $parentId = $parent['parent_id'] ? (int)$parent['parent_id'] : (int)$parent['id'];
        }

        $newId = $this->db->insert('video_comments', [
            'archive_id' => $archiveId,
            'user_id' => (int)$user['id'],
            'parent_id' => $parentId,
            'body' => $body,
            'status' => 'visible',
        ]);

        if ($parentId !== null) {
            $this->db->query(
                "UPDATE video_comments SET reply_count = reply_count + 1 WHERE id = ?",
                [$parentId]
            );
        }

        $row = $this->db->fetchOne(
            "SELECT c.id, c.user_id, c.parent_id, c.body, c.status,
                    c.like_count, c.reply_count, c.edited_at, c.created_at,
                    u.username, u.display_name, u.role
             FROM video_comments c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.id = ?",
            [$newId]
        );
        return $this->serializeRow($row, (int)$user['id'], []);
    }

    /**
     * Edit own comment. Sets edited_at. Admins can edit any comment.
     */
    public function edit(int $commentId, string $body): array {
        $user = $this->requireAccount();
        $row = $this->db->fetchOne(
            "SELECT id, user_id, parent_id, status FROM video_comments WHERE id = ?",
            [$commentId]
        );
        if (!$row || $row['status'] === 'deleted') {
            throw new RuntimeException("Comment not found");
        }
        if (!$this->canModerate($user) && (int)$row['user_id'] !== (int)$user['id']) {
            throw new RuntimeException("Not allowed", 403);
        }

        $body = self::sanitizeBody($body);
        $this->validateBody($body);

        $this->db->update(
            'video_comments',
            ['body' => $body, 'edited_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$commentId]
        );

        $fresh = $this->db->fetchOne(
            "SELECT c.id, c.user_id, c.parent_id, c.body, c.status,
                    c.like_count, c.reply_count, c.edited_at, c.created_at,
                    u.username, u.display_name, u.role
             FROM video_comments c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.id = ?",
            [$commentId]
        );
        return $this->serializeRow($fresh, (int)$user['id'], []);
    }

    /**
     * Soft-delete a comment. The row stays so reply threads keep their
     * structure; the body is wiped and status='deleted'.
     */
    public function delete(int $commentId): void {
        $user = $this->requireAccount();
        $row = $this->db->fetchOne(
            "SELECT id, user_id, parent_id, status FROM video_comments WHERE id = ?",
            [$commentId]
        );
        if (!$row || $row['status'] === 'deleted') {
            throw new RuntimeException("Comment not found");
        }
        if (!$this->canModerate($user) && (int)$row['user_id'] !== (int)$user['id']) {
            throw new RuntimeException("Not allowed", 403);
        }

        $this->db->update(
            'video_comments',
            ['body' => '', 'status' => 'deleted'],
            'id = ?',
            [$commentId]
        );

        if (!empty($row['parent_id'])) {
            $this->db->query(
                "UPDATE video_comments SET reply_count = GREATEST(0, reply_count - 1) WHERE id = ?",
                [(int)$row['parent_id']]
            );
        }
    }

    // =====================================================
    // LIKES
    // =====================================================

    /**
     * Toggle a like on a comment for the current user. Returns the new
     * { liked, like_count } state.
     */
    public function toggleLike(int $commentId): array {
        $user = $this->requireAccount();
        $row = $this->db->fetchOne(
            "SELECT id, status FROM video_comments WHERE id = ?",
            [$commentId]
        );
        if (!$row || $row['status'] === 'deleted') {
            throw new RuntimeException("Comment not found");
        }

        $existing = $this->db->fetchOne(
            "SELECT 1 FROM comment_likes WHERE comment_id = ? AND user_id = ?",
            [$commentId, (int)$user['id']]
        );

        if ($existing) {
            $this->db->delete(
                'comment_likes',
                'comment_id = ? AND user_id = ?',
                [$commentId, (int)$user['id']]
            );
            $this->db->query(
                "UPDATE video_comments SET like_count = GREATEST(0, like_count - 1) WHERE id = ?",
                [$commentId]
            );
            $liked = false;
        } else {
            try {
                $this->db->insert('comment_likes', [
                    'comment_id' => $commentId,
                    'user_id' => (int)$user['id'],
                ]);
                $this->db->query(
                    "UPDATE video_comments SET like_count = like_count + 1 WHERE id = ?",
                    [$commentId]
                );
            } catch (Throwable $e) {
                // Duplicate-key race: someone else's request beat us.
                // The like already exists, so just report the current state.
            }
            $liked = true;
        }

        $count = (int)$this->db->fetchColumn(
            "SELECT like_count FROM video_comments WHERE id = ?",
            [$commentId]
        );
        return ['liked' => $liked, 'like_count' => $count];
    }

    // =====================================================
    // REPORTS
    // =====================================================

    public function report(int $commentId, ?string $reason): void {
        $user = $this->requireAccount();
        $reason = $reason ? ApiController::sanitizeText($reason, 255) : null;

        try {
            $this->db->insert('comment_reports', [
                'comment_id' => $commentId,
                'reporter_user_id' => (int)$user['id'],
                'reason' => $reason,
            ]);
        } catch (Throwable $e) {
            // Already reported by this user — that's fine, treat as success.
        }
    }

    // =====================================================
    // MODERATION (admin/editor only)
    // =====================================================

    public function moderate(int $commentId, string $action): void {
        $user = $this->context->current();
        if (!$this->canModerate($user)) {
            throw new RuntimeException("Admin access required", 403);
        }

        switch ($action) {
            case 'hide':
                $this->db->update('video_comments', ['status' => 'hidden'], 'id = ?', [$commentId]);
                break;
            case 'restore':
                $this->db->update('video_comments', ['status' => 'visible'], 'id = ?', [$commentId]);
                break;
            case 'delete': {
                // Match self-service delete(): when soft-deleting a reply, also
                // decrement the parent's reply_count so the displayed count and
                // "show more replies" don't over-report. Read the prior status
                // first and guard on it so a repeated moderate('delete') on an
                // already-deleted comment can't double-decrement.
                $target = $this->db->fetchOne(
                    "SELECT parent_id, status FROM video_comments WHERE id = ?",
                    [$commentId]
                );
                $this->db->update('video_comments', ['body' => '', 'status' => 'deleted'], 'id = ?', [$commentId]);
                if ($target && $target['status'] !== 'deleted' && !empty($target['parent_id'])) {
                    $this->db->query(
                        "UPDATE video_comments SET reply_count = GREATEST(0, reply_count - 1) WHERE id = ?",
                        [(int)$target['parent_id']]
                    );
                }
                break;
            }
            default:
                throw new RuntimeException("Invalid moderation action");
        }
    }

    // =====================================================
    // HELPERS
    // =====================================================

    /**
     * Sanitize comment body: strip all HTML tags, normalize whitespace,
     * collapse 3+ blank lines, trim. Plain text only — links are turned
     * into anchors at render time on the client.
     */
    public static function sanitizeBody(string $body): string {
        $body = strip_tags($body);
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace("/\n{3,}/", "\n\n", $body);
        $body = trim($body);
        if (mb_strlen($body) > self::MAX_BODY) {
            $body = mb_substr($body, 0, self::MAX_BODY);
        }
        return $body;
    }

    private function validateBody(string $body): void {
        $len = mb_strlen($body);
        if ($len < self::MIN_BODY) {
            throw new RuntimeException("Comment cannot be empty");
        }
        if ($len > self::MAX_BODY) {
            throw new RuntimeException("Comment is too long (max " . self::MAX_BODY . " characters)");
        }
    }

    private function enforceRateLimit(int $userId): void {
        $recent = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM video_comments
             WHERE user_id = ? AND created_at > (NOW() - INTERVAL ? SECOND)",
            [$userId, self::RATE_LIMIT_WINDOW]
        );
        if ($recent >= self::RATE_LIMIT_MAX) {
            throw new RuntimeException(
                "You're commenting too quickly. Please wait a moment and try again.",
                429
            );
        }
    }

    private function requireAccount(): array {
        $user = $this->context->current();
        if (!empty($user['is_guest'])) {
            throw new RuntimeException("Sign in to comment", 401);
        }
        return $user;
    }

    private function canModerate(?array $user): bool {
        if (!$user) return false;
        $role = $user['role'] ?? 'guest';
        return $role === 'admin' || $role === 'editor';
    }

    /**
     * Build a {commentId => true} set of which rows the viewer has liked.
     */
    private function fetchLikedSet(array $rows, int $viewerId): array {
        if ($viewerId <= 0 || empty($rows)) return [];
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;
        $params[] = $viewerId;
        $liked = $this->db->fetchAll(
            "SELECT comment_id FROM comment_likes
             WHERE comment_id IN ($in) AND user_id = ?",
            $params
        );
        $set = [];
        foreach ($liked as $l) $set[(int)$l['comment_id']] = true;
        return $set;
    }

    private function serializeRow(array $r, int $viewerId, array $likedSet): array {
        $isDeleted = $r['status'] === 'deleted';
        // The author may have deleted their account — user_id is SET NULL by the
        // FK and the LEFT JOIN leaves the user columns NULL. Render a neutral
        // placeholder instead of a blank name, and never treat a NULL author as
        // the current viewer or an admin.
        $authorMissing = ($r['user_id'] === null);
        $authorName = $authorMissing ? '[deleted]' : ($r['display_name'] ?: $r['username']);
        $isOwn = !$authorMissing && ($viewerId === (int)$r['user_id']);
        $viewerCanModerate = $this->canModerate($this->context->current());
        return [
            'id' => (int)$r['id'],
            'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'body' => $isDeleted ? '' : $r['body'],
            'status' => $r['status'],
            'is_deleted' => $isDeleted,
            'like_count' => (int)$r['like_count'],
            'reply_count' => isset($r['reply_count']) ? (int)$r['reply_count'] : 0,
            'created_at' => $r['created_at'],
            'edited_at' => $r['edited_at'],
            'liked' => isset($likedSet[(int)$r['id']]),
            'author' => [
                'id' => $authorMissing ? null : (int)$r['user_id'],
                'username' => $r['username'],
                'display_name' => $authorName,
                'role' => $r['role'],
                'is_admin' => in_array($r['role'] ?? '', ['admin', 'editor'], true),
                'is_viewer' => $isOwn,
            ],
            'can_edit' => $isOwn && !$isDeleted,
            'can_delete' => ($isOwn || $viewerCanModerate) && !$isDeleted,
            'can_moderate' => $viewerCanModerate,
        ];
    }
}
