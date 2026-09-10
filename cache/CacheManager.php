<?php
/**
 * Unified Cache Manager
 *
 * Coordinates all caching operations for the application
 * Enhanced for permanent local storage to minimize Archive.org API usage
 */

require_once __DIR__ . '/../db/Database.php';

class CacheManager {
    private $db;
    private $config;

    // Cache TTL settings (in seconds). With permanent caching on (the
    // default), the TTL only governs when a row is marked STALE and queued
    // for background refresh — never when it's deleted.
    private $searchTTL = 1800;      // 30 minutes → stale-after for search
    private $metadataTTL = 86400;   // 24 hours
    private $thumbnailTTL = 604800; // 7 days
    private $settingsTTL = 3600;    // 1 hour

    // Permanent storage settings. The DB schema (migration 002) already
    // tracks is_permanent / is_stale on both metadata and search rows, so
    // turning this on means we cache "forever" with stale-while-revalidate
    // semantics rather than ever throwing data away.
    private $permanentCaching = true;
    private $staleAfterDays = 30;   // Consider data stale after this many days

    // Thumbnail idle retention. Previously hard-coded to 30 days in
    // cleanExpiredCache() — set to 0 to disable the sweep entirely (truly
    // permanent), or any positive number to expire idle thumbnails after
    // that many days. Overridable via the `thumbnailRetentionDays` site
    // setting.
    private $thumbnailRetentionDays = 0; // 0 = never delete

    public function __construct() {
        $this->db = Database::getInstance();
        $this->config = $this->db->getConfig();

        // Load TTL settings from config
        if (isset($this->config['cache'])) {
            $this->searchTTL = $this->config['cache']['search_ttl'] ?? $this->searchTTL;
            $this->metadataTTL = $this->config['cache']['metadata_ttl'] ?? $this->metadataTTL;
            $this->thumbnailTTL = $this->config['cache']['thumbnail_ttl'] ?? $this->thumbnailTTL;
            $this->settingsTTL = $this->config['cache']['settings_ttl'] ?? $this->settingsTTL;
        }

        // Load permanent caching settings
        $this->loadPermanentCacheSettings();
    }

    /**
     * Load permanent caching settings from database
     */
    private function loadPermanentCacheSettings(): void {
        try {
            $setting = $this->db->fetchOne(
                "SELECT setting_value FROM site_settings WHERE setting_key = 'cacheMetadataPermanently'"
            );
            if ($setting) {
                $this->permanentCaching = (bool)$setting['setting_value'];
            }

            $staleSetting = $this->db->fetchOne(
                "SELECT setting_value FROM site_settings WHERE setting_key = 'refreshStaleAfterDays'"
            );
            if ($staleSetting) {
                $this->staleAfterDays = (int)$staleSetting['setting_value'];
            }

            $thumbRetention = $this->db->fetchOne(
                "SELECT setting_value FROM site_settings WHERE setting_key = 'thumbnailRetentionDays'"
            );
            if ($thumbRetention !== null) {
                $this->thumbnailRetentionDays = (int)$thumbRetention['setting_value'];
            }
        } catch (Exception $e) {
            // Use defaults if settings don't exist yet
        }
    }

    /**
     * Check if permanent caching is enabled
     */
    public function isPermanentCachingEnabled(): bool {
        return $this->permanentCaching;
    }

    /**
     * Generate a cache key from parameters
     */
    public function generateKey(array $params): string {
        ksort($params); // Ensure consistent key generation
        return hash('sha256', json_encode($params));
    }

    // =====================================================
    // SEARCH CACHE
    // =====================================================

    /**
     * Get cached search results.
     *
     * With permanent caching on (the default), we return the cached row
     * even when it's past its "fresh" window — and mark it stale so the
     * background queue refreshes it. This way archive.org gets hit at most
     * once per query per staleAfterDays window, instead of once every 30
     * minutes the way the old TTL-only code worked. The caller can inspect
     * the `_is_stale` flag to set shorter HTTP cache headers if it wants
     * the browser to come back sooner.
     */
    public function getSearchCache(string $cacheKey): ?array {
        // Fast path: fresh, non-expired hit.
        $result = $this->db->fetchOne(
            "SELECT response_data, hit_count FROM search_cache
             WHERE cache_key = ? AND (expires_at IS NULL OR expires_at > NOW())",
            [$cacheKey]
        );

        if ($result) {
            $this->db->query(
                "UPDATE search_cache SET hit_count = hit_count + 1, last_accessed = NOW()
                 WHERE cache_key = ?",
                [$cacheKey]
            );
            $decoded = json_decode($result['response_data'], true);
            return $decoded;
        }

        // Permanent-cache path: row exists but is past expires_at. Return
        // it anyway, mark stale, queue for background refresh. This is the
        // key behavior that prevents archive.org from being hit on every
        // page view of a popular query.
        if ($this->permanentCaching) {
            $stale = $this->db->fetchOne(
                "SELECT response_data, query_params FROM search_cache WHERE cache_key = ?",
                [$cacheKey]
            );
            if ($stale) {
                $this->db->query(
                    "UPDATE search_cache
                     SET hit_count = hit_count + 1, last_accessed = NOW(), is_stale = 1
                     WHERE cache_key = ?",
                    [$cacheKey]
                );
                $decoded = json_decode($stale['response_data'], true);
                if (is_array($decoded)) {
                    $decoded['_is_stale'] = true;
                }
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Set search cache.
     *
     * With permanent caching on (default), expires_at is set to the stale
     * threshold rather than a hard delete deadline. The cleanup cron leaves
     * permanent rows alone; expires_at just controls when the next read
     * marks the row stale and queues a refresh.
     */
    public function setSearchCache(string $cacheKey, array $params, array $data, int $resultCount = 0): void {
        $expiresAt = date('Y-m-d H:i:s', time() + $this->searchTTL);
        $isPermanent = $this->permanentCaching ? 1 : 0;

        $this->db->query(
            "INSERT INTO search_cache
                (cache_key, query_params, response_data, result_count, expires_at, is_permanent, is_stale, last_refreshed)
             VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE
                response_data = VALUES(response_data),
                result_count = VALUES(result_count),
                expires_at = VALUES(expires_at),
                is_permanent = VALUES(is_permanent),
                is_stale = 0,
                last_refreshed = NOW(),
                hit_count = 0,
                last_accessed = NOW()",
            [
                $cacheKey,
                json_encode($params),
                json_encode($data),
                $resultCount,
                $expiresAt,
                $isPermanent,
            ]
        );
    }

    /**
     * Get search cache rows currently marked stale, so the background
     * queue / cron warmer can refresh them. Ordered by most-recently
     * accessed so we re-warm what users actually care about first.
     */
    public function getStaleSearches(int $limit = 50): array {
        return $this->db->fetchAll(
            "SELECT cache_key, query_params, last_accessed FROM search_cache
             WHERE is_stale = 1
             ORDER BY last_accessed DESC
             LIMIT " . (int)$limit,
            []
        );
    }

    // =====================================================
    // METADATA CACHE (Enhanced for Permanent Storage)
    // =====================================================

    /**
     * Get cached video metadata
     * With permanent caching, returns data even if stale (marks for refresh)
     */
    public function getMetadataCache(string $archiveId): ?array {
        // First try to get non-stale data
        $result = $this->db->fetchOne(
            "SELECT * FROM video_metadata_cache
             WHERE archive_id = ? AND (expires_at IS NULL OR expires_at > NOW())",
            [$archiveId]
        );

        if ($result) {
            // Parse files JSON if present
            if (!empty($result['files_json'])) {
                $result['files'] = json_decode($result['files_json'], true);
            }
            if (!empty($result['collection_json'])) {
                $result['collection'] = json_decode($result['collection_json'], true);
            }
            if (!empty($result['raw_metadata_json'])) {
                $result['raw_metadata'] = json_decode($result['raw_metadata_json'], true);
            }
            unset($result['files_json'], $result['collection_json'], $result['raw_metadata_json']);

            // Update access tracking in registry
            $this->updateCacheAccess($archiveId);

            return $result;
        }

        // If permanent caching is enabled, also return stale data
        if ($this->permanentCaching) {
            $staleResult = $this->db->fetchOne(
                "SELECT * FROM video_metadata_cache WHERE archive_id = ?",
                [$archiveId]
            );

            if ($staleResult) {
                // Mark as stale for background refresh — once. Every hit on
                // a stale row used to re-run the UPDATE plus the cache_queue
                // upsert; when the row is already flagged there is nothing
                // to change, so skip the two writes.
                if (empty($staleResult['is_stale'])) {
                    $this->markMetadataStale($archiveId);
                }

                // Parse JSON fields
                if (!empty($staleResult['files_json'])) {
                    $staleResult['files'] = json_decode($staleResult['files_json'], true);
                }
                if (!empty($staleResult['collection_json'])) {
                    $staleResult['collection'] = json_decode($staleResult['collection_json'], true);
                }
                unset($staleResult['files_json'], $staleResult['collection_json'], $staleResult['raw_metadata_json']);

                $staleResult['_is_stale'] = true;

                // Update access tracking
                $this->updateCacheAccess($archiveId);

                return $staleResult;
            }
        }

        return null;
    }

    /**
     * Project a video_metadata_cache row (as returned by getMetadataCache())
     * onto the public metadata shape ArchiveOrgService::normalizeMetadata()
     * produces on a cache MISS, so /api/metadata.php and
     * /api/metadata-batch.php answer with one shape regardless of hit/miss.
     *
     * A raw row differs from the miss shape in three ways this fixes:
     *   - it has `archive_id` / `license_url` / a comma-joined `subject`
     *     string instead of `identifier` / `licenseurl` / a `subject` array,
     *   - it lacks `identifier` and `thumbnail`,
     *   - it carries internal columns (id, expires_at, is_permanent,
     *     is_stale, last_refreshed, refresh_count, thumbnail_cached,
     *     raw_metadata, _is_stale) that the client has no business seeing.
     *
     * Idempotent: an already-normalized array passes through unchanged, so
     * callers can apply it uniformly. getMetadataCache() itself is left
     * alone — index.php and player.php read its raw columns directly.
     */
    public static function normalizeMetadataRow(array $row): array {
        $archiveId = (string)($row['identifier'] ?? $row['archive_id'] ?? '');

        $subject = $row['subject'] ?? [];
        if (is_string($subject)) {
            // Stored as implode(', ', ...) by setMetadataCache().
            $subject = $subject === ''
                ? []
                : array_values(array_filter(array_map('trim', explode(',', $subject)), 'strlen'));
        } elseif (!is_array($subject)) {
            $subject = [];
        }

        $title = $row['title'] ?? null;
        if ($title === null || $title === '') {
            $title = $archiveId;
        }

        return [
            'identifier' => $archiveId,
            'title' => $title,
            'description' => $row['description'] ?? null,
            'creator' => $row['creator'] ?? null,
            'date' => $row['date'] ?? null,
            'runtime' => $row['runtime'] ?? null,
            'mediatype' => $row['mediatype'] ?? null,
            'downloads' => (int)($row['downloads'] ?? 0),
            'licenseurl' => $row['licenseurl'] ?? ($row['license_url'] ?? null),
            'subject' => $subject,
            'collection' => is_array($row['collection'] ?? null) ? $row['collection'] : [],
            'files' => is_array($row['files'] ?? null) ? $row['files'] : [],
            'thumbnail' => $row['thumbnail'] ?? "https://archive.org/services/img/{$archiveId}",
        ];
    }

    /**
     * Set metadata cache with permanent storage support
     */
    public function setMetadataCache(string $archiveId, array $metadata, ?array $rawMetadata = null): void {
        // Calculate expiration based on permanent caching setting
        $expiresAt = $this->permanentCaching
            ? null  // No expiration for permanent caching
            : date('Y-m-d H:i:s', time() + $this->metadataTTL);

        $filesJson = null;
        if (isset($metadata['files'])) {
            $filesJson = json_encode($metadata['files']);
        }

        $subject = null;
        if (isset($metadata['subject'])) {
            $subject = is_array($metadata['subject'])
                ? implode(', ', $metadata['subject'])
                : $metadata['subject'];
        }

        $collectionJson = null;
        if (isset($metadata['collection'])) {
            $collectionJson = json_encode(
                is_array($metadata['collection']) ? $metadata['collection'] : [$metadata['collection']]
            );
        }

        $rawJson = $rawMetadata ? json_encode($rawMetadata) : null;

        $this->db->query(
            "INSERT INTO video_metadata_cache
             (archive_id, title, description, creator, date, runtime, mediatype, downloads,
              license_url, subject, files_json, collection_json, raw_metadata_json,
              expires_at, is_permanent, is_stale, last_refreshed, refresh_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), 0)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                creator = VALUES(creator),
                date = VALUES(date),
                runtime = VALUES(runtime),
                mediatype = VALUES(mediatype),
                downloads = VALUES(downloads),
                license_url = VALUES(license_url),
                subject = VALUES(subject),
                files_json = VALUES(files_json),
                collection_json = VALUES(collection_json),
                raw_metadata_json = VALUES(raw_metadata_json),
                expires_at = VALUES(expires_at),
                is_permanent = VALUES(is_permanent),
                is_stale = 0,
                last_refreshed = NOW(),
                refresh_count = refresh_count + 1,
                updated_at = NOW()",
            [
                $archiveId,
                $this->extractValue($metadata, 'title'),
                $this->extractValue($metadata, 'description'),
                $this->extractValue($metadata, 'creator'),
                $this->extractValue($metadata, 'date'),
                $this->extractValue($metadata, 'runtime'),
                $this->extractValue($metadata, 'mediatype'),
                (int)($metadata['downloads'] ?? 0),
                $this->extractValue($metadata, 'licenseurl'),
                $subject,
                $filesJson,
                $collectionJson,
                $rawJson,
                $expiresAt,
                $this->permanentCaching ? 1 : 0
            ]
        );

        // Update cached items registry
        $this->updateCacheRegistry($archiveId, ['has_metadata' => 1]);
    }

    /**
     * Mark metadata as stale (for background refresh)
     */
    public function markMetadataStale(string $archiveId): void {
        $this->db->query(
            "UPDATE video_metadata_cache SET is_stale = 1 WHERE archive_id = ?",
            [$archiveId]
        );

        // Queue for background refresh
        $this->queueForCaching($archiveId, 'metadata', 5);
    }

    /**
     * Get list of stale items for background refresh
     */
    public function getStaleMetadata(int $limit = 50): array {
        return $this->db->fetchAll(
            "SELECT archive_id, title, last_refreshed FROM video_metadata_cache
             WHERE is_stale = 1
                OR (is_permanent = 1 AND (last_refreshed IS NULL OR last_refreshed < DATE_SUB(NOW(), INTERVAL " . (int)$this->staleAfterDays . " DAY)))
             ORDER BY last_refreshed ASC
             LIMIT " . (int)$limit,
            []
        );
    }

    /**
     * Extract value from metadata (handles arrays)
     */
    private function extractValue(array $data, string $key): ?string {
        if (!isset($data[$key])) {
            return null;
        }
        return is_array($data[$key]) ? $data[$key][0] : $data[$key];
    }

    /**
     * Update cache access tracking in registry
     */
    private function updateCacheAccess(string $archiveId): void {
        try {
            $this->db->query(
                "INSERT INTO cached_items_registry (archive_id, last_accessed, access_count)
                 VALUES (?, NOW(), 1)
                 ON DUPLICATE KEY UPDATE
                    last_accessed = NOW(),
                    access_count = access_count + 1",
                [$archiveId]
            );
        } catch (Exception $e) {
            // Silently fail - registry is not critical
        }
    }

    /**
     * Update cached items registry
     */
    public function updateCacheRegistry(string $archiveId, array $fields): void {
        try {
            $setClauses = [];
            $values = [$archiveId];

            foreach ($fields as $key => $value) {
                if (in_array($key, ['has_metadata', 'has_thumbnail', 'has_files_list', 'total_size_bytes', 'source'])) {
                    $setClauses[] = "$key = ?";
                    $values[] = $value;
                }
            }

            if (empty($setClauses)) return;

            $values[] = $archiveId; // for the WHERE clause in UPDATE

            $this->db->query(
                "INSERT INTO cached_items_registry (archive_id, " . implode(', ', array_keys($fields)) . ")
                 VALUES (?, " . implode(', ', array_fill(0, count($fields), '?')) . ")
                 ON DUPLICATE KEY UPDATE " . implode(', ', $setClauses),
                array_merge([$archiveId], array_values($fields), array_values($fields))
            );
        } catch (Exception $e) {
            // Silently fail - registry is not critical
        }
    }

    /**
     * Queue an item for background caching
     */
    public function queueForCaching(string $archiveId, string $cacheType, int $priority = 5): void {
        try {
            $this->db->query(
                "INSERT INTO cache_queue (archive_id, cache_type, priority, status)
                 VALUES (?, ?, ?, 'pending')
                 ON DUPLICATE KEY UPDATE
                    priority = LEAST(priority, VALUES(priority)),
                    status = IF(status = 'failed', 'pending', status)",
                [$archiveId, $cacheType, $priority]
            );
        } catch (Exception $e) {
            // Silently fail - queue is not critical
        }
    }

    /**
     * Get pending items from cache queue
     */
    public function getPendingCacheItems(string $cacheType, int $limit = 20): array {
        return $this->db->fetchAll(
            "SELECT * FROM cache_queue
             WHERE cache_type = ? AND status = 'pending' AND attempts < max_attempts
             ORDER BY priority ASC, created_at ASC
             LIMIT " . (int)$limit,
            [$cacheType]
        );
    }

    /**
     * Atomically claim a queued item for processing.
     *
     * Returns true only if THIS call won the row (flipped pending →
     * processing). Two overlapping cron runs can SELECT the same pending rows
     * via getPendingCacheItems(); the `AND status = 'pending'` guard plus
     * rowCount() means exactly one claims each row, so the loser skips it
     * instead of double-fetching from Archive.org. `processed_at` is stamped
     * here so the stuck-item reaper can tell how long a row has been held.
     */
    public function markQueueItemProcessing(int $id): bool {
        $stmt = $this->db->query(
            "UPDATE cache_queue
                SET status = 'processing', attempts = attempts + 1, processed_at = NOW()
              WHERE id = ? AND status = 'pending'",
            [$id]
        );
        return $stmt->rowCount() === 1;
    }

    /**
     * Re-queue items left stuck in 'processing'.
     *
     * A cron run killed mid-item (shared hosts enforce a short
     * max_execution_time, and the queue runs every few minutes) leaves its
     * claimed rows in 'processing' forever — getPendingCacheItems() only
     * selects 'pending', so they would never retry. This resets any row whose
     * claim is older than $staleMinutes (or that predates the
     * processed_at-on-claim change, hence NULL) back to 'pending', or to
     * 'failed' once it has exhausted its attempts. Returns the rows reaped.
     */
    public function reapStuckQueueItems(int $staleMinutes = 15): int {
        $minutes = max(1, $staleMinutes);
        // INTERVAL can't take a bound ?; interpolate an (int) cast (C1 pattern).
        $stmt = $this->db->query(
            "UPDATE cache_queue
                SET status = IF(attempts >= max_attempts, 'failed', 'pending'),
                    error_message = IF(attempts >= max_attempts,
                        CONCAT('Reaped after ', attempts, ' attempt(s) stuck in processing'),
                        error_message)
              WHERE status = 'processing'
                AND (processed_at IS NULL
                     OR processed_at < (NOW() - INTERVAL " . (int)$minutes . " MINUTE))"
        );
        return $stmt->rowCount();
    }

    /**
     * Acquire the advisory lock that serializes cache-queue processing.
     *
     * Uses MySQL GET_LOCK, which is scoped to this DB connection and released
     * automatically if the process dies — so a crashed cron can't wedge the
     * queue (unlike a stale lock file). Returns true if acquired (caller should
     * run), false if another run already holds it (caller should exit). If
     * advisory locks aren't available we proceed anyway: the atomic claim above
     * still prevents double-processing.
     */
    public function acquireQueueLock(int $timeoutSeconds = 0): bool {
        try {
            $got = $this->db->fetchColumn(
                "SELECT GET_LOCK(?, ?)",
                [$this->queueLockName(), max(0, $timeoutSeconds)]
            );
            return (int)$got === 1;
        } catch (Throwable $e) {
            return true;
        }
    }

    /** Release the cache-queue advisory lock (best-effort). */
    public function releaseQueueLock(): void {
        try {
            $this->db->query("SELECT RELEASE_LOCK(?)", [$this->queueLockName()]);
        } catch (Throwable $e) {
            // Best-effort; the lock also auto-releases when the connection closes.
        }
    }

    /**
     * Name of the GET_LOCK used to serialize queue processing, scoped to this
     * database. GET_LOCK names are server-wide (not per-database), so two
     * installs sharing one MySQL server on shared hosting would otherwise
     * serialize each other's crons. Hash keeps it within the 64-char limit.
     */
    private function queueLockName(): string {
        $db = (string)($this->config['database'] ?? 'default');
        return 'afc_cq_' . substr(md5($db), 0, 16);
    }

    /**
     * Mark cache queue item as completed
     */
    public function markQueueItemCompleted(int $id): void {
        $this->db->query(
            "UPDATE cache_queue SET status = 'completed', processed_at = NOW() WHERE id = ?",
            [$id]
        );
    }

    /**
     * Mark cache queue item as failed
     */
    public function markQueueItemFailed(int $id, string $error): void {
        $this->db->query(
            "UPDATE cache_queue SET status = 'failed', error_message = ? WHERE id = ?",
            [$error, $id]
        );
    }

    // =====================================================
    // THUMBNAIL CACHE
    // =====================================================

    /**
     * Get cached thumbnail path
     */
    public function getThumbnailPath(string $archiveId): ?string {
        $result = $this->db->fetchOne(
            "SELECT local_path FROM thumbnail_cache WHERE archive_id = ?",
            [$archiveId]
        );

        if ($result && file_exists($result['local_path'])) {
            // Update access count and time
            $this->db->query(
                "UPDATE thumbnail_cache SET access_count = access_count + 1, last_accessed = NOW()
                 WHERE archive_id = ?",
                [$archiveId]
            );

            return $result['local_path'];
        }

        return null;
    }

    /**
     * Cache a thumbnail
     */
    public function cacheThumbnail(string $archiveId, string $localPath, string $originalUrl, array $imageInfo = []): void {
        $this->db->query(
            "INSERT INTO thumbnail_cache (archive_id, original_url, local_path, file_size, width, height, mime_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                local_path = VALUES(local_path),
                file_size = VALUES(file_size),
                width = VALUES(width),
                height = VALUES(height),
                access_count = access_count + 1,
                last_accessed = NOW()",
            [
                $archiveId,
                $originalUrl,
                $localPath,
                $imageInfo['size'] ?? 0,
                $imageInfo['width'] ?? 0,
                $imageInfo['height'] ?? 0,
                $imageInfo['mime'] ?? 'image/jpeg'
            ]
        );

        // Update cached items registry
        $this->updateCacheRegistry($archiveId, [
            'has_thumbnail' => 1,
            'total_size_bytes' => $imageInfo['size'] ?? 0
        ]);

        // Also update video_metadata_cache if exists
        try {
            $this->db->query(
                "UPDATE video_metadata_cache SET thumbnail_cached = 1 WHERE archive_id = ?",
                [$archiveId]
            );
        } catch (Exception $e) {
            // Ignore if table doesn't have the column yet
        }
    }

    /**
     * Check if a thumbnail is cached locally
     */
    public function isThumbnailCached(string $archiveId): bool {
        $result = $this->db->fetchOne(
            "SELECT local_path FROM thumbnail_cache WHERE archive_id = ?",
            [$archiveId]
        );

        return $result && file_exists($result['local_path']);
    }

    // =====================================================
    // CACHE CLEANUP
    // =====================================================

    /**
     * Clean up expired cache entries.
     *
     * Permanent rows (is_permanent=1) are NEVER deleted — that would defeat
     * the entire point of permanent caching by re-hitting archive.org on
     * every cron run. We only sweep:
     *   - search/metadata rows whose is_permanent=0 AND expires_at has passed
     *   - thumbnails idle for `thumbnailRetentionDays` days (default: never)
     *
     * Previously this sweep was the silent reason a "permanently cached"
     * site would re-hit archive.org every 30 minutes for popular searches:
     * setSearchCache() always wrote expires_at=NOW()+30min, and this query
     * happily deleted those rows every hour.
     */
    public function cleanExpiredCache(): array {
        $deleted = [
            'search' => 0,
            'metadata' => 0,
            'thumbnails' => 0,
        ];

        // Search cache: only drop non-permanent expired rows.
        $stmt = $this->db->query(
            "DELETE FROM search_cache
             WHERE (is_permanent IS NULL OR is_permanent = 0)
               AND expires_at IS NOT NULL
               AND expires_at < NOW()"
        );
        $deleted['search'] = $stmt->rowCount();

        // Metadata cache: same guard — only drop non-permanent expired rows.
        $stmt = $this->db->query(
            "DELETE FROM video_metadata_cache
             WHERE (is_permanent IS NULL OR is_permanent = 0)
               AND expires_at IS NOT NULL
               AND expires_at < NOW()"
        );
        $deleted['metadata'] = $stmt->rowCount();

        // Thumbnails: only sweep if the operator opts in. Default 0 = never.
        if ($this->thumbnailRetentionDays > 0) {
            $days = (int)$this->thumbnailRetentionDays;
            $oldThumbnails = $this->db->fetchAll(
                "SELECT local_path FROM thumbnail_cache
                 WHERE last_accessed < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );

            foreach ($oldThumbnails as $thumb) {
                if (!empty($thumb['local_path']) && file_exists($thumb['local_path'])) {
                    @unlink($thumb['local_path']);
                }
            }

            $stmt = $this->db->query(
                "DELETE FROM thumbnail_cache
                 WHERE last_accessed < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );
            $deleted['thumbnails'] = $stmt->rowCount();
        }

        return $deleted;
    }

    // =====================================================
    // CACHE STATISTICS
    // =====================================================

    /**
     * Get cache statistics
     */
    public function getStats(): array {
        $stats = [];

        // Search cache stats
        $searchStats = $this->db->fetchOne(
            "SELECT COUNT(*) as total, SUM(hit_count) as total_hits
             FROM search_cache WHERE expires_at IS NULL OR expires_at > NOW()"
        );
        $stats['search'] = [
            'entries' => (int)($searchStats['total'] ?? 0),
            'total_hits' => (int)($searchStats['total_hits'] ?? 0),
        ];

        // Metadata cache stats (include permanent entries)
        $metadataStats = $this->db->fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN is_permanent = 1 THEN 1 ELSE 0 END) as permanent_count,
                SUM(CASE WHEN is_stale = 1 THEN 1 ELSE 0 END) as stale_count,
                SUM(CASE WHEN thumbnail_cached = 1 THEN 1 ELSE 0 END) as with_thumbnails
             FROM video_metadata_cache"
        );
        $stats['metadata'] = [
            'entries' => (int)($metadataStats['total'] ?? 0),
            'permanent' => (int)($metadataStats['permanent_count'] ?? 0),
            'stale' => (int)($metadataStats['stale_count'] ?? 0),
            'with_thumbnails' => (int)($metadataStats['with_thumbnails'] ?? 0),
        ];

        // Thumbnail cache stats
        $thumbStats = $this->db->fetchOne(
            "SELECT COUNT(*) as total, SUM(file_size) as total_size, SUM(access_count) as total_access
             FROM thumbnail_cache"
        );
        $stats['thumbnails'] = [
            'entries' => (int)($thumbStats['total'] ?? 0),
            'total_size_bytes' => (int)($thumbStats['total_size'] ?? 0),
            'total_accesses' => (int)($thumbStats['total_access'] ?? 0),
        ];

        // Cache queue stats
        try {
            $queueStats = $this->db->fetchOne(
                "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                 FROM cache_queue"
            );
            $stats['queue'] = [
                'total' => (int)($queueStats['total'] ?? 0),
                'pending' => (int)($queueStats['pending'] ?? 0),
                'processing' => (int)($queueStats['processing'] ?? 0),
                'failed' => (int)($queueStats['failed'] ?? 0),
            ];
        } catch (Exception $e) {
            $stats['queue'] = ['total' => 0, 'pending' => 0, 'processing' => 0, 'failed' => 0];
        }

        // Cached items registry stats
        try {
            $registryStats = $this->db->fetchOne(
                "SELECT
                    COUNT(*) as total_items,
                    SUM(has_metadata) as with_metadata,
                    SUM(has_thumbnail) as with_thumbnail,
                    SUM(total_size_bytes) as total_storage
                 FROM cached_items_registry"
            );
            $stats['registry'] = [
                'total_items' => (int)($registryStats['total_items'] ?? 0),
                'with_metadata' => (int)($registryStats['with_metadata'] ?? 0),
                'with_thumbnail' => (int)($registryStats['with_thumbnail'] ?? 0),
                'total_storage_bytes' => (int)($registryStats['total_storage'] ?? 0),
            ];
        } catch (Exception $e) {
            $stats['registry'] = ['total_items' => 0, 'with_metadata' => 0, 'with_thumbnail' => 0, 'total_storage_bytes' => 0];
        }

        return $stats;
    }

    /**
     * Get comprehensive cache statistics including savings
     */
    public function getDetailedStats(): array {
        $stats = $this->getStats();

        // Calculate API calls saved
        $apiSavings = $this->db->fetchOne(
            "SELECT
                SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) as hits,
                SUM(CASE WHEN cache_hit = 0 THEN 1 ELSE 0 END) as misses,
                COUNT(*) as total
             FROM api_usage_log
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        $stats['api_savings'] = [
            'calls_saved_30d' => (int)($apiSavings['hits'] ?? 0),
            'cache_misses_30d' => (int)($apiSavings['misses'] ?? 0),
            'hit_rate_30d' => $apiSavings['total'] > 0
                ? round(($apiSavings['hits'] / $apiSavings['total']) * 100, 2)
                : 0,
        ];

        // Storage breakdown
        $stats['storage'] = [
            'thumbnails_bytes' => $stats['thumbnails']['total_size_bytes'],
            'thumbnails_formatted' => $this->formatBytes($stats['thumbnails']['total_size_bytes']),
        ];

        return $stats;
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    /**
     * Record daily cache statistics
     */
    public function recordDailyStats(): void {
        $stats = $this->getStats();

        try {
            $apiStats = $this->db->fetchOne(
                "SELECT
                    SUM(cache_hit) as hits,
                    COUNT(*) - SUM(cache_hit) as misses
                 FROM api_usage_log
                 WHERE DATE(created_at) = CURDATE()"
            );

            $this->db->query(
                "INSERT INTO cache_statistics
                 (stat_date, metadata_cached, thumbnails_cached, total_storage_bytes, cache_hit_count, cache_miss_count)
                 VALUES (CURDATE(), ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    metadata_cached = VALUES(metadata_cached),
                    thumbnails_cached = VALUES(thumbnails_cached),
                    total_storage_bytes = VALUES(total_storage_bytes),
                    cache_hit_count = VALUES(cache_hit_count),
                    cache_miss_count = VALUES(cache_miss_count),
                    updated_at = NOW()",
                [
                    $stats['metadata']['entries'],
                    $stats['thumbnails']['entries'],
                    $stats['thumbnails']['total_size_bytes'],
                    (int)($apiStats['hits'] ?? 0),
                    (int)($apiStats['misses'] ?? 0)
                ]
            );
        } catch (Exception $e) {
            error_log("Failed to record daily stats: " . $e->getMessage());
        }
    }

    /**
     * Get cache hit rate from API usage log
     */
    public function getHitRate(string $period = '24 hours'): array {
        // Whitelist allowed period values to prevent SQL injection
        $allowedPeriods = [
            '1 hour', '6 hours', '12 hours', '24 hours',
            '48 hours', '7 days', '30 days'
        ];
        if (!in_array($period, $allowedPeriods, true)) {
            $period = '24 hours';
        }

        return $this->db->fetchAll(
            "SELECT
                endpoint,
                COUNT(*) as total_requests,
                SUM(cache_hit) as cache_hits,
                ROUND(AVG(response_time_ms), 2) as avg_response_time,
                ROUND(SUM(cache_hit) / COUNT(*) * 100, 2) as hit_rate
             FROM api_usage_log
             WHERE created_at > DATE_SUB(NOW(), INTERVAL {$period})
             GROUP BY endpoint"
        );
    }
}
