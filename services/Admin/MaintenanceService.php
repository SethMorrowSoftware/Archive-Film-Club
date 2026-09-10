<?php
/**
 * MaintenanceService — database backup / refresh / content-reset engine
 * for the admin "System" panel.
 *
 * Design constraints (cPanel / shared hosting):
 *   - NO shell-out. `mysqldump` is frequently absent and exec()/shell_exec()
 *     are commonly disabled, so backup/restore are pure-PHP over PDO.
 *   - Bounded memory. Table dumps page through rows with an interpolated
 *     (int) LIMIT/OFFSET — never a bound `?` in LIMIT, which throws under the
 *     app's native-prepares PDO (see audit C1) — so a huge cache table can't
 *     blow the memory limit.
 *   - Best-effort time budget. Long actions call set_time_limit(0); callers
 *     should still expect shared-host ceilings on very large databases.
 *
 * Every destructive method is the *service* half only. Auth (strict admin),
 * CSRF, and type-to-confirm live in api/admin/maintenance.php.
 */
class MaintenanceService
{
    /** @var Database */
    private $db;
    /** @var PDO */
    private $pdo;
    /** @var string */
    private $dbName;
    /** @var resource|null When set, dump output is written here instead of echoed to the client. */
    private $sink = null;

    /**
     * First-line marker written into every backup. Restore refuses any file
     * that doesn't carry it, so a stray .sql can't be executed by accident.
     */
    private const BACKUP_MARKER = 'Archive Film Club SQL backup';

    /** Thrown when another request holds the maintenance lock. */
    private const LOCK_BUSY_MESSAGE = 'Another maintenance operation (backup, restore, or reset) is already running — wait for it to finish and try again.';

    /**
     * Cap on the decompressed size of an uploaded .sql.gz restore. The whole
     * script is held in memory for statement splitting, so an oversized (or
     * corrupt) archive must fail with a clean error instead of an opaque
     * out-of-memory 500 partway through.
     */
    private const MAX_RESTORE_SQL_BYTES = 268435456; // 256 MB

    /**
     * Cache tables hold only regenerable data (re-fetched from Archive.org).
     * They dominate backup size and are the bulk of a content reset.
     */
    private const CACHE_TABLES = [
        'search_cache',
        'video_metadata_cache',
        'collection_metadata_cache',
        'thumbnail_cache',
        'cache_queue',
        'cached_items_registry',
        'cache_statistics',
        'api_usage_log',
    ];

    /**
     * User-generated / community data removed by a "content reset". Schema,
     * user accounts, settings, branding, staff picks and featured sections
     * are all left intact.
     */
    private const CONTENT_TABLES = [
        'video_comments',
        'comment_likes',
        'comment_reports',
        'user_bookmarks',
        'user_watch_history',
        'user_collections',
        'user_collection_items',
        'search_history',
        'popular_searches',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getPdo();
        $cfg = $this->db->getConfig();
        $this->dbName = $cfg['database'] ?? '';
    }

    // =====================================================
    // STATUS
    // =====================================================

    /**
     * A snapshot for the panel: per-table row counts + sizes, totals,
     * migration files on disk, and the host's relevant PHP limits +
     * capabilities (so the UI can hide features the host can't do).
     */
    public function databaseStatus(): array
    {
        $tables = [];
        $totalRows = 0;
        $totalBytes = 0;

        // Sizes come from information_schema (cheap, one query). Row counts
        // are taken exactly via COUNT(*) — information_schema.table_rows is
        // only an estimate for InnoDB and admins expect a real number.
        $sizes = [];
        $rows = $this->db->fetchAll(
            "SELECT table_name AS name, (data_length + index_length) AS size_bytes
               FROM information_schema.tables
              WHERE table_schema = ?",
            [$this->dbName]
        );
        foreach ($rows as $r) {
            $sizes[$r['name']] = (int)$r['size_bytes'];
        }

        foreach ($this->listTables() as $table) {
            $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $bytes = $sizes[$table] ?? 0;
            $totalRows += $count;
            $totalBytes += $bytes;
            $tables[] = [
                'name' => $table,
                'rows' => $count,
                'size_bytes' => $bytes,
                'is_cache' => in_array($table, self::CACHE_TABLES, true),
            ];
        }

        $migrationFiles = [];
        foreach ((array)glob(dirname(__DIR__, 2) . '/db/migrations/*.sql') as $f) {
            $migrationFiles[] = basename($f);
        }
        sort($migrationFiles);

        return [
            'database' => $this->dbName,
            'table_count' => count($tables),
            'total_rows' => $totalRows,
            'total_size_bytes' => $totalBytes,
            'tables' => $tables,
            'migrations' => $migrationFiles,
            'limits' => [
                'max_execution_time' => (int)ini_get('max_execution_time'),
                'memory_limit' => ini_get('memory_limit'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
            ],
            'capabilities' => [
                'zip' => class_exists('ZipArchive'),
                'gzip' => function_exists('gzencode'),
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    // =====================================================
    // BACKUP (streamed SQL)
    // =====================================================

    /**
     * Stream a self-contained SQL dump to php://output. The caller is
     * responsible for sending the Content-Type / Content-Disposition
     * headers BEFORE calling this.
     *
     * @param bool $includeCaches keep the regenerable *_cache tables in the dump
     */
    public function streamBackup(bool $includeCaches = true): void
    {
        @set_time_limit(0);

        $tables = $this->listTables();
        if (!$includeCaches) {
            $tables = array_values(array_filter(
                $tables,
                function ($t) { return !in_array($t, self::CACHE_TABLES, true); }
            ));
        }

        // Header marker — restore validates the first line before executing,
        // so a stray .sql can't be fed in by accident.
        $this->out('-- ' . self::BACKUP_MARKER . "\n");
        $this->out('-- generated: ' . gmdate('c') . "\n");
        $this->out('-- database: ' . $this->dbName . "\n");
        $this->out('-- tables: ' . count($tables) . ($includeCaches ? '' : ' (caches excluded)') . "\n");
        $this->out("-- NOTE: restoring this file overwrites the listed tables.\n\n");
        $this->out("SET NAMES utf8mb4;\n");
        $this->out("SET FOREIGN_KEY_CHECKS=0;\n\n");

        // Dump from a single REPEATABLE READ snapshot (what mysqldump
        // --single-transaction does). Without it, the paged SELECTs in
        // dumpTable() each see live data: a write landing between pages can
        // duplicate or skip rows, and tables dumped seconds apart can be
        // mutually inconsistent (e.g. a comment whose user row is missing).
        // Best-effort — if the host refuses, dump the old way.
        $snapshot = false;
        try {
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $snapshot = true;
        } catch (Throwable $e) {
            // Non-InnoDB tables / restricted host — proceed unsnapshotted.
        }

        try {
            foreach ($tables as $table) {
                $this->dumpTable($table);
            }
        } finally {
            if ($snapshot) {
                try { $this->pdo->exec('COMMIT'); } catch (Throwable $e) {}
            }
        }

        $this->out("\nSET FOREIGN_KEY_CHECKS=1;\n");
        $this->flush();
    }

    /**
     * Write a full SQL dump to a file (used for the pre-restore safety
     * snapshot). Returns false if the file can't be opened. Reuses the exact
     * same dump routine as streamBackup() via the output sink.
     */
    public function backupToFile(string $path, bool $includeCaches = true): bool
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            return false;
        }
        $this->sink = $fh;
        try {
            $this->streamBackup($includeCaches);
        } finally {
            fclose($fh);
            $this->sink = null;
        }
        return true;
    }

    /**
     * Dump one table: DROP + CREATE, then paged INSERTs.
     */
    private function dumpTable(string $table): void
    {
        $createRow = $this->pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? ($createRow['Create View'] ?? null);
        if ($createSql === null) {
            return; // not a base table we can recreate
        }

        $this->out("-- ----------------------------\n");
        $this->out("-- Table: {$table}\n");
        $this->out("-- ----------------------------\n");
        $this->out("DROP TABLE IF EXISTS `{$table}`;\n");
        $this->out($createSql . ";\n");

        // Page in primary-key order. LIMIT/OFFSET without ORDER BY has no
        // guaranteed row order in MySQL, so consecutive pages could overlap
        // or leave gaps — a silently corrupt backup. PK order makes paging
        // deterministic (the consistent snapshot in streamBackup() makes it
        // point-in-time stable as well).
        $orderBy = '';
        try {
            $pk = [];
            $keys = $this->pdo
                ->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'")
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($keys as $key) {
                $pk[(int)$key['Seq_in_index']] = '`' . str_replace('`', '``', (string)$key['Column_name']) . '`';
            }
            if ($pk) {
                ksort($pk);
                $orderBy = ' ORDER BY ' . implode(', ', $pk);
            }
        } catch (Throwable $e) {
            // No readable PK info — fall back to unordered paging.
        }

        $batch = 200;
        $offset = 0;
        $columns = null;

        do {
            // Interpolated (int) LIMIT/OFFSET — bound `?` here throws under
            // native prepares (audit C1). The casts make this injection-safe.
            $rows = $this->pdo
                ->query("SELECT * FROM `{$table}`{$orderBy} LIMIT {$batch} OFFSET {$offset}")
                ->fetchAll(PDO::FETCH_ASSOC);

            if ($rows) {
                if ($columns === null) {
                    $columns = array_map(
                        function ($c) { return '`' . str_replace('`', '``', $c) . '`'; },
                        array_keys($rows[0])
                    );
                }
                $valueGroups = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $v) {
                        $vals[] = $v === null ? 'NULL' : $this->pdo->quote((string)$v);
                    }
                    $valueGroups[] = '(' . implode(',', $vals) . ')';
                }
                $this->out(
                    'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ") VALUES\n"
                    . implode(",\n", $valueGroups) . ";\n"
                );
                $this->flush();
            }

            $offset += $batch;
        } while (count($rows) === $batch);

        $this->out("\n");
    }

    /**
     * Build a zip of the cached-thumbnail directory into a temp file and
     * return its path (caller streams it then unlinks), or null if zip is
     * unavailable or there is nothing to archive. Building to a temp file
     * (rather than streaming) lets the API return a clean JSON error before
     * any file headers are sent.
     */
    public function buildThumbnailsZip(): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $cfg = $this->db->getConfig();
        $dir = $cfg['paths']['thumbnails'] ?? (dirname(__DIR__, 2) . '/thumbnails');
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return null;
        }

        @set_time_limit(0);
        $tmp = tempnam(sys_get_temp_dir(), 'afc_thumbs_');
        if ($tmp === false) {
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return null;
        }

        $added = 0;
        foreach ((array)scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            // Only ship actual cached images — skip index.html / .htaccess
            // and anything that isn't a plain readable file.
            if (!is_file($path)) continue;
            if (!preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $name)) continue;
            $zip->addFile($path, $name);
            $added++;
        }
        $zip->close();

        if ($added === 0) {
            @unlink($tmp);
            return null;
        }

        return $tmp;
    }

    // =====================================================
    // REFRESH
    // =====================================================

    /**
     * Re-run every migration in db/migrations idempotently — the same logic
     * install.php uses, so a code upgrade that ships a new NNN_*.sql can be
     * applied from the panel. "Already exists" style errors are swallowed.
     */
    public function runMigrations(): array
    {
        @set_time_limit(0);
        $dir = dirname(__DIR__, 2) . '/db/migrations';
        $files = glob($dir . '/*.sql');
        if (!$files) {
            throw new RuntimeException('No migration files found');
        }
        sort($files);

        if (!$this->acquireMaintenanceLock()) {
            throw new RuntimeException(self::LOCK_BUSY_MESSAGE);
        }
        try {
            $applied = 0;
            $skipped = 0;
            $perFile = [];

            foreach ($files as $file) {
                $sql = (string)file_get_contents($file);
                // Quote/comment-aware split — the same splitter restore uses
                // and install.php's runner calls. A naive explode(';') breaks
                // on semicolons inside `--` comments and inside the string
                // literals migration 007 builds its dynamic ALTERs from.
                $statements = self::splitSqlStatements($sql);

                $fileApplied = 0;
                $fileSkipped = 0;
                foreach ($statements as $statement) {
                    try {
                        // exec(), not prepare(): 007's PREPARE/EXECUTE is
                        // refused by the server-side prepared-statement protocol.
                        $this->pdo->exec($statement);
                        $applied++;
                        $fileApplied++;
                    } catch (Throwable $e) {
                        $msg = $e->getMessage();
                        if (!self::isIgnorableMigrationError($msg)) {
                            throw new RuntimeException(
                                'Migration ' . basename($file) . ' failed: ' . $msg
                            );
                        }
                        $skipped++;
                        $fileSkipped++;
                    }
                }
                $perFile[] = [
                    'file' => basename($file),
                    'applied' => $fileApplied,
                    'skipped' => $fileSkipped,
                ];
            }

            return [
                'files' => count($files),
                'statements_applied' => $applied,
                'statements_skipped' => $skipped,
                'detail' => $perFile,
            ];
        } finally {
            $this->releaseMaintenanceLock();
        }
    }

    /**
     * Flush regenerable caches and re-warm the stale ones. Search cache is
     * truncated outright (always rebuildable on the next query); expired
     * metadata/thumbnail rows are pruned; stuck queue items are reaped; then
     * a bounded slice of stale metadata is re-fetched.
     */
    public function refreshCaches(int $rewarmLimit = 10): array
    {
        @set_time_limit(0);
        require_once dirname(__DIR__, 2) . '/cache/CacheManager.php';
        $cacheManager = new CacheManager();

        $before = $cacheManager->getStats();

        // Flush the search cache (FK-free, fully regenerable).
        $searchCleared = 0;
        if ($this->tableExists('search_cache')) {
            $searchCleared = (int)$this->pdo->exec('TRUNCATE TABLE `search_cache`');
        }

        $expired = $cacheManager->cleanExpiredCache();
        $reaped = $cacheManager->reapStuckQueueItems();

        $rewarmed = [];
        if ($rewarmLimit > 0) {
            $localStorage = new LocalStorageService();
            $rewarmed = $localStorage->refreshStaleData($rewarmLimit);
        }

        $after = $cacheManager->getStats();

        return [
            'search_cache_cleared' => true,
            'expired_removed' => $expired,
            'stuck_queue_reaped' => $reaped,
            'rewarmed' => $rewarmed,
            'stats_before' => $before,
            'stats_after' => $after,
        ];
    }

    /**
     * Re-pull stale item metadata (and thumbnails) from Archive.org, bounded
     * by $limit so the request stays within the host's time budget.
     */
    public function refetchMetadata(int $limit = 25): array
    {
        @set_time_limit(0);
        $localStorage = new LocalStorageService();
        $results = $localStorage->refreshStaleData(max(1, $limit));
        return ['limit' => $limit, 'results' => $results];
    }

    // =====================================================
    // CONTENT RESET (wipe — safest mode)
    // =====================================================

    /**
     * Truncate user-generated + cache tables and delete cached thumbnail
     * files. Schema, user accounts, site settings, branding, staff picks and
     * featured sections are preserved. Returns per-table row counts removed.
     */
    public function contentReset(): array
    {
        @set_time_limit(0);

        if (!$this->acquireMaintenanceLock()) {
            throw new RuntimeException(self::LOCK_BUSY_MESSAGE);
        }
        try {
            $targets = array_merge(self::CONTENT_TABLES, self::CACHE_TABLES);
            $cleared = [];
            $totalRows = 0;

            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($targets as $table) {
                    if (!$this->tableExists($table)) {
                        continue;
                    }
                    $count = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
                    $this->pdo->exec("TRUNCATE TABLE `{$table}`");
                    $cleared[$table] = $count;
                    $totalRows += $count;
                }
            } finally {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }

            $thumbsDeleted = $this->clearThumbnailFiles();
        } finally {
            $this->releaseMaintenanceLock();
        }

        return [
            'tables_cleared' => count($cleared),
            'rows_removed' => $totalRows,
            'detail' => $cleared,
            'thumbnail_files_deleted' => $thumbsDeleted,
        ];
    }

    // =====================================================
    // RESTORE
    // =====================================================

    /**
     * Restore the database from an uploaded backup file (.sql or .sql.gz)
     * produced by this tool.
     *
     * Flow: read (+gunzip) → validate the header marker → take a full
     * server-side safety snapshot (unless skipped) → execute every statement
     * with FK checks off → reset OPcache. Because MySQL DDL auto-commits,
     * a restore is NOT atomic — the safety snapshot is the rollback path.
     *
     * @param array $file   a $_FILES entry
     * @param bool  $skipSafety skip the automatic pre-restore snapshot
     * @throws RuntimeException on a bad upload / non-backup file / no rollback
     */
    public function restoreFromUpload(array $file, bool $skipSafety = false): array
    {
        @set_time_limit(0);

        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('No uploaded backup file was found.');
        }

        $sql = file_get_contents($tmp);
        if ($sql === false || $sql === '') {
            throw new RuntimeException('The uploaded backup file is empty or unreadable.');
        }

        // Transparently accept a gzip-compressed dump (magic bytes 1f 8b).
        // The decode is capped so an oversized/corrupt archive fails with a
        // clear message instead of exhausting memory mid-request.
        if (substr($sql, 0, 2) === "\x1f\x8b") {
            $decoded = function_exists('gzdecode')
                ? @gzdecode($sql, self::MAX_RESTORE_SQL_BYTES + 1)
                : false;
            if ($decoded === false) {
                throw new RuntimeException('Could not decompress the gzip backup on this server.');
            }
            if (strlen($decoded) > self::MAX_RESTORE_SQL_BYTES) {
                throw new RuntimeException(
                    'The decompressed backup exceeds ' . (self::MAX_RESTORE_SQL_BYTES >> 20)
                    . ' MB — restore a file this large with the MySQL CLI or phpMyAdmin instead.'
                );
            }
            $sql = $decoded;
        }

        // Refuse anything that isn't one of our backups.
        if (strpos(substr($sql, 0, 1000), self::BACKUP_MARKER) === false) {
            throw new RuntimeException('This file is not an Archive Film Club backup (the header marker is missing).');
        }

        // Serialize against any other maintenance run: two interleaved
        // restores (or a restore racing a content reset) would corrupt the
        // database and each other's safety snapshots.
        if (!$this->acquireMaintenanceLock()) {
            throw new RuntimeException(self::LOCK_BUSY_MESSAGE);
        }
        try {
            $snapshot = null;
            if (!$skipSafety) {
                $snapshot = $this->writeSafetySnapshot();
                if ($snapshot === null) {
                    throw new RuntimeException(
                        'Could not write an automatic safety backup (check write permissions on the backups/ folder). '
                        . 'Download a backup yourself, then re-run with "skip the automatic safety backup".'
                    );
                }
            }

            $statements = self::splitSqlStatements($sql);
            $applied = 0;
            $failed = 0;
            $errors = [];

            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if ($statement === '') {
                        continue;
                    }
                    try {
                        $this->pdo->exec($statement);
                        $applied++;
                    } catch (Throwable $e) {
                        $failed++;
                        if (count($errors) < 10) {
                            $errors[] = substr($e->getMessage(), 0, 200);
                        }
                    }
                }
            } finally {
                // Re-enable FK checks even if a statement threw fatally.
                try { $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) {}
            }
        } finally {
            $this->releaseMaintenanceLock();
        }

        // The bytecode/object cache may reference the pre-restore schema.
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return [
            'statements' => count($statements),
            'applied' => $applied,
            'failed' => $failed,
            'errors' => $errors,
            'safety_snapshot' => $snapshot !== null ? basename($snapshot) : null,
            'gzip' => isset($decoded),
        ];
    }

    /**
     * Is this a "the schema is already in this state" error that a migration
     * re-run may safely ignore? Shared by install.php's runner and
     * runMigrations() so both swallow exactly the same set. The phrases cover
     * both MySQL and MariaDB wording for: table/index already exists,
     * duplicate column/key/FK name (errno 121 is MySQL 5.7's "Can't write;
     * duplicate key in table" on ADD CONSTRAINT), a DROP of an index/FK that
     * is already gone, and MySQL 8's "check that column/key exists" phrasing.
     * Belt and braces: migration 007 avoids all of these by construction.
     */
    public static function isIgnorableMigrationError(string $message): bool
    {
        $ignorable = [
            'already exists',
            'Duplicate column',
            'Duplicate key name',
            'Duplicate foreign key',
            'Multiple primary key',
            'errno: 121',
            "Can't DROP",
            'check that column/key exists',
            'check that it exists',
        ];
        foreach ($ignorable as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Split a SQL script into individual statements on top-level `;`, while
     * correctly skipping semicolons that live inside single/double-quoted
     * strings, backtick identifiers, and `--` / `#` / block comments.
     *
     * A naive explode(';') corrupts any backup whose data contains a
     * semicolon (comment bodies, titles, descriptions), so this is the heart
     * of a correct restore. Pure + static so it is unit-tested with no DB.
     */
    public static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen($sql);
        $inSingle = $inDouble = $inBacktick = false;
        $inLineComment = $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($c === "\n") { $inLineComment = false; $buf .= $c; }
                continue;
            }
            if ($inBlockComment) {
                if ($c === '*' && $next === '/') { $inBlockComment = false; $i++; }
                continue;
            }
            if ($inSingle) {
                $buf .= $c;
                if ($c === '\\' && $next !== '') { $buf .= $next; $i++; }
                elseif ($c === "'") {
                    if ($next === "'") { $buf .= $next; $i++; } // doubled '' escape
                    else { $inSingle = false; }
                }
                continue;
            }
            if ($inDouble) {
                $buf .= $c;
                if ($c === '\\' && $next !== '') { $buf .= $next; $i++; }
                elseif ($c === '"') {
                    if ($next === '"') { $buf .= $next; $i++; }
                    else { $inDouble = false; }
                }
                continue;
            }
            if ($inBacktick) {
                $buf .= $c;
                if ($c === '`') {
                    if ($next === '`') { $buf .= $next; $i++; }
                    else { $inBacktick = false; }
                }
                continue;
            }

            // Top level — comments first.
            if ($c === '-' && $next === '-') {
                $third = $i + 2 < $len ? $sql[$i + 2] : "\n";
                if ($third === ' ' || $third === "\t" || $third === "\n" || $third === "\r") {
                    $inLineComment = true; $i++; continue;
                }
            }
            if ($c === '#') { $inLineComment = true; continue; }
            if ($c === '/' && $next === '*') { $inBlockComment = true; $i++; continue; }

            if ($c === "'") { $inSingle = true; $buf .= $c; continue; }
            if ($c === '"') { $inDouble = true; $buf .= $c; continue; }
            if ($c === '`') { $inBacktick = true; $buf .= $c; continue; }

            if ($c === ';') {
                $stmt = trim($buf);
                if ($stmt !== '') { $statements[] = $stmt; }
                $buf = '';
                continue;
            }

            $buf .= $c;
        }

        $tail = trim($buf);
        if ($tail !== '') { $statements[] = $tail; }
        return $statements;
    }

    /**
     * Dump the current database to a protected backups/ directory and return
     * the path (or null on failure). This is the rollback artifact taken
     * immediately before a restore.
     */
    private function writeSafetySnapshot(): ?string
    {
        $dir = $this->backupsDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $this->ensureBackupsProtected($dir);

        try {
            $name = 'pre-restore-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
        } catch (Throwable $e) {
            $name = 'pre-restore-' . gmdate('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 8) . '.sql';
        }
        $path = $dir . '/' . $name;

        if (!$this->backupToFile($path, true)) {
            return null;
        }
        $this->pruneSnapshots($dir, 5);
        return $path;
    }

    private function backupsDir(): string
    {
        return dirname(__DIR__, 2) . '/backups';
    }

    /**
     * Make sure the backups/ directory is never web-served, mirroring the
     * db/ + services/ deny rules. Re-written defensively on each use.
     */
    private function ensureBackupsProtected(string $dir): void
    {
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents(
                $ht,
                "# Server-side database backups. Never served over HTTP.\n"
                . "Require all denied\n"
                . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n"
            );
        }
        $idx = $dir . '/index.html';
        if (!is_file($idx)) {
            @file_put_contents($idx, "");
        }
    }

    /** Keep only the newest $keep pre-restore-*.sql snapshots. */
    private function pruneSnapshots(string $dir, int $keep): void
    {
        $files = glob($dir . '/pre-restore-*.sql');
        if (!$files || count($files) <= $keep) {
            return;
        }
        // Oldest first by name (timestamped), drop everything past $keep newest.
        sort($files);
        $remove = array_slice($files, 0, count($files) - $keep);
        foreach ($remove as $f) {
            @unlink($f);
        }
    }

    // =====================================================
    // CONCURRENCY GUARD
    // =====================================================

    /**
     * Advisory lock that serializes maintenance operations (restore, content
     * reset, migrations, and the API's backup stream) across concurrent admin
     * requests. Same GET_LOCK pattern as CacheManager::acquireQueueLock():
     * connection-scoped, auto-released if the process dies, and per-database
     * named so co-hosted installs on one MySQL server don't serialize each
     * other. If advisory locks are unavailable we proceed — the guard is
     * best-effort and a degraded host keeps working.
     */
    public function acquireMaintenanceLock(int $timeoutSeconds = 5): bool
    {
        try {
            $got = $this->db->fetchColumn(
                'SELECT GET_LOCK(?, ?)',
                [$this->maintenanceLockName(), max(0, $timeoutSeconds)]
            );
            return (int)$got === 1;
        } catch (Throwable $e) {
            return true;
        }
    }

    /** Release the maintenance advisory lock (best-effort). */
    public function releaseMaintenanceLock(): void
    {
        try {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$this->maintenanceLockName()]);
        } catch (Throwable $e) {
            // The lock also auto-releases when the connection closes.
        }
    }

    /** Per-database lock name, hashed to stay within the 64-char limit. */
    private function maintenanceLockName(): string
    {
        $db = $this->dbName !== '' ? $this->dbName : 'default';
        return 'afc_maint_' . substr(md5($db), 0, 16);
    }

    // =====================================================
    // INTERNALS
    // =====================================================

    /** Base tables in the app's schema, alphabetical. */
    private function listTables(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT table_name AS name
               FROM information_schema.tables
              WHERE table_schema = ? AND table_type = 'BASE TABLE'
              ORDER BY table_name",
            [$this->dbName]
        );
        $out = [];
        foreach ($rows as $r) {
            // Defence-in-depth: every table name is interpolated into
            // backticked SQL below, so reject anything but the identifier
            // charset even though the source is our own information_schema.
            if (preg_match('/^[A-Za-z0-9_]+$/', $r['name'])) {
                $out[] = $r['name'];
            }
        }
        return $out;
    }

    private function tableExists(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    /**
     * Delete cached image files, keeping index.html / .htaccess and any
     * non-image guard files. Returns the count deleted.
     */
    private function clearThumbnailFiles(): int
    {
        $cfg = $this->db->getConfig();
        $dir = $cfg['paths']['thumbnails'] ?? (dirname(__DIR__, 2) . '/thumbnails');
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return 0;
        }
        $deleted = 0;
        foreach ((array)scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            if (!is_file($path)) continue;
            if (!preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $name)) continue;
            if (@unlink($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Write a chunk to the active sink (file) or, by default, the response. */
    private function out(string $chunk): void
    {
        if ($this->sink !== null) {
            fwrite($this->sink, $chunk);
        } else {
            echo $chunk;
        }
    }

    /** Best-effort flush so large *streamed* dumps don't buffer. No-op for a file sink. */
    private function flush(): void
    {
        if ($this->sink !== null) {
            return;
        }
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }
}
