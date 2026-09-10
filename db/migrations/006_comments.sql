-- =====================================================
-- Migration 006: Member comments (site-local)
-- =====================================================
--
-- Comments live ONLY in this database. They are NEVER posted to
-- archive.org. archive_id is the foreign reference into archive.org's
-- item identifier (e.g. "prelinger_films"), used purely as a key to
-- group local comments by video.
--
-- Threading: one level deep. Top-level comments have parent_id = NULL.
-- Replies have parent_id pointing at a top-level comment id. Replies
-- to replies still attach to the top-level parent_id (flat thread).
--
-- Soft delete: status='deleted' keeps the row so reply chains and like
-- counts stay consistent. Body is wiped client-side when status is
-- not 'visible'.

CREATE TABLE IF NOT EXISTS video_comments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    archive_id VARCHAR(255) NOT NULL,
    user_id INT NULL,
    parent_id INT NULL,
    body TEXT NOT NULL,
    status ENUM('visible', 'hidden', 'deleted') NOT NULL DEFAULT 'visible',
    like_count INT NOT NULL DEFAULT 0,
    reply_count INT NOT NULL DEFAULT 0,
    edited_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_archive_top (archive_id, parent_id, status, created_at),
    INDEX idx_archive_recent (archive_id, status, created_at),
    INDEX idx_parent (parent_id, status, created_at),
    INDEX idx_user (user_id, created_at),
    -- SET NULL (not CASCADE): deleting a user must NOT delete their comments,
    -- because the parent_id CASCADE below would then also wipe OTHER users'
    -- replies under those threads. A deleted account's comments are kept and
    -- rendered as "[deleted]". Both FKs are named so migration 007 can find
    -- and repair them by name instead of guessing MySQL's auto-generated
    -- `video_comments_ibfk_N` (which differs between fresh and upgraded
    -- installs, and between MySQL and MariaDB).
    CONSTRAINT fk_video_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_video_comments_parent FOREIGN KEY (parent_id) REFERENCES video_comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_likes (
    comment_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (comment_id, user_id),
    INDEX idx_user (user_id),
    FOREIGN KEY (comment_id) REFERENCES video_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comment_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    comment_id INT NOT NULL,
    reporter_user_id INT NOT NULL,
    reason VARCHAR(255) NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolved_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_unresolved (resolved_at, created_at),
    INDEX idx_comment (comment_id),
    UNIQUE KEY uk_one_per_user (comment_id, reporter_user_id),
    FOREIGN KEY (comment_id) REFERENCES video_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
