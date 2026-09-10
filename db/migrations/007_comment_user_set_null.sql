-- =====================================================
-- Migration 007: video_comments.user_id ON DELETE SET NULL
-- =====================================================
--
-- Existing installs created video_comments.user_id as NOT NULL with an
-- ON DELETE CASCADE foreign key (original migration 006). Deleting a user then
-- cascaded away ALL of their comments and — because replies cascade on
-- parent_id — could wipe OTHER users' replies under those threads, destroying
-- conversation history the soft-delete design meant to preserve.
--
-- This converts the column to nullable and the FK to ON DELETE SET NULL, so a
-- deleted account's comments are kept (and rendered as "[deleted]") instead of
-- vanishing and taking other people's replies with them. Fresh installs get
-- this directly from the updated migration 006; this migration brings EXISTING
-- installs in line.
--
-- It is NAME-AGNOSTIC: the original FKs were unnamed and MySQL auto-named them
-- (`video_comments_ibfk_1`, `_2`, ...). Which number lands on which column
-- differs between a fresh install of the current 006 (where user_id is already
-- named) and an upgraded one, so instead of guessing the name we look it up in
-- information_schema and build the ALTER dynamically. Each step is a no-op
-- (`SELECT 1`) when there is nothing to do, so this file is safe to re-run and
-- also REPAIRS installs that ran an earlier version of this migration, which
-- could drop the parent_id cascade FK by mistake.
--
-- NOTE for the runner: PREPARE/EXECUTE cannot go through PDO's server-side
-- prepared-statement protocol. Both migration runners (install.php and the
-- admin "refresh schema" action) send statements with PDO::exec() for this
-- reason. `SET @x := (SELECT ...)` requires the subquery to return at most one
-- row — the LIMIT 1 guarantees that.
--
-- Safe to run on an existing database. Re-runnable.
-- =====================================================

-- 1. Allow NULL (required before a FK can SET NULL on it).
ALTER TABLE video_comments MODIFY user_id INT NULL;

-- 2. Drop whatever FK currently sits on user_id UNLESS it is already the named
--    SET NULL one from the current migration 006.
SET @fk := (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'video_comments'
              AND COLUMN_NAME = 'user_id'
              AND REFERENCED_TABLE_NAME = 'users'
              AND CONSTRAINT_NAME <> 'fk_video_comments_user'
            LIMIT 1);
SET @sql := IF(@fk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE video_comments DROP FOREIGN KEY `', @fk, '`'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. (Re)add the named ON DELETE SET NULL FK, but only when user_id has no FK
--    at all (i.e. step 2 dropped the old one, or it was never created).
SET @fk := (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'video_comments'
              AND COLUMN_NAME = 'user_id'
              AND REFERENCED_TABLE_NAME = 'users'
            LIMIT 1);
SET @sql := IF(@fk IS NOT NULL, 'SELECT 1', 'ALTER TABLE video_comments ADD CONSTRAINT fk_video_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Repair: make sure the reply cascade on parent_id exists. An earlier
--    version of this migration dropped `video_comments_ibfk_1` by name, which
--    on a fresh install was the parent_id FK, not the user_id one.
SET @fk := (SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'video_comments'
              AND COLUMN_NAME = 'parent_id'
              AND REFERENCED_TABLE_NAME = 'video_comments'
            LIMIT 1);
SET @sql := IF(@fk IS NOT NULL, 'SELECT 1', 'ALTER TABLE video_comments ADD CONSTRAINT fk_video_comments_parent FOREIGN KEY (parent_id) REFERENCES video_comments(id) ON DELETE CASCADE');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
