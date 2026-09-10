<?php
/**
 * Local Storage Service
 *
 * Handles proactive caching of metadata and thumbnails from Archive.org
 * to minimize API usage and create local backups of all viewed content.
 */

require_once __DIR__ . '/../cache/CacheManager.php';
require_once __DIR__ . '/../cache/ThumbnailCache.php';
require_once __DIR__ . '/../db/Database.php';
// For ArchiveOrgService::fetchUrl() — explicit require because the cron
// scripts load this file directly, without bootstrap.php's autoloader.
require_once __DIR__ . '/ArchiveOrgService.php';

class LocalStorageService {
    private $cacheManager;
    private $thumbnailCache;
    private $db;
    private $config;

    // API settings
    const API_BASE_URL = 'https://archive.org';
    const API_TIMEOUT = 15;
    const USER_AGENT = 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)';

    public function __construct() {
        $this->cacheManager = new CacheManager();
        $this->thumbnailCache = new ThumbnailCache();
        $this->db = Database::getInstance();
        $this->config = $this->db->getConfig();
    }

    /**
     * Proactively cache an item's metadata and thumbnail
     * Called when a user views a video or when processing search results
     */
    public function cacheItem(string $archiveId, array $metadata = null, bool $cacheThumbnail = true): array {
        $result = [
            'archive_id' => $archiveId,
            'metadata_cached' => false,
            'thumbnail_cached' => false,
            'from_cache' => false,
        ];

        // Check if already cached
        $existing = $this->cacheManager->getMetadataCache($archiveId);

        if ($existing && !isset($existing['_is_stale'])) {
            $result['metadata_cached'] = true;
            $result['from_cache'] = true;
            $result['metadata'] = $existing;
        } else {
            // If metadata provided, use it; otherwise fetch
            if ($metadata) {
                $this->cacheManager->setMetadataCache($archiveId, $metadata);
                $result['metadata_cached'] = true;
                $result['metadata'] = $metadata;
            } else {
                // Fetch from Archive.org
                $fetchResult = $this->fetchAndCacheMetadata($archiveId);
                if ($fetchResult['success']) {
                    $result['metadata_cached'] = true;
                    $result['metadata'] = $fetchResult['metadata'];
                }
            }
        }

        // Cache thumbnail if enabled
        if ($cacheThumbnail) {
            if ($this->cacheManager->isThumbnailCached($archiveId)) {
                $result['thumbnail_cached'] = true;
            } else {
                // Queue thumbnail for caching (or cache immediately if low load)
                $this->queueOrCacheThumbnail($archiveId);
                $result['thumbnail_queued'] = true;
            }
        }

        return $result;
    }

    /**
     * Fetch metadata from Archive.org and cache it
     */
    public function fetchAndCacheMetadata(string $archiveId): array {
        $url = self::API_BASE_URL . "/metadata/{$archiveId}";
        $response = $this->httpGet($url);

        if (!$response['success'] || empty($response['data'])) {
            return [
                'success' => false,
                'error' => $response['error'] ?? 'Failed to fetch metadata',
            ];
        }

        $rawData = json_decode($response['data'], true);

        if (!$rawData || !isset($rawData['metadata'])) {
            return [
                'success' => false,
                'error' => 'Invalid metadata response',
            ];
        }

        // Normalize metadata
        $metadata = $this->normalizeMetadata($archiveId, $rawData);

        // Store in cache with raw data
        $this->cacheManager->setMetadataCache($archiveId, $metadata, $rawData);

        return [
            'success' => true,
            'metadata' => $metadata,
        ];
    }

    /**
     * Queue or immediately cache a thumbnail based on system load
     */
    private function queueOrCacheThumbnail(string $archiveId): void {
        // Check queue size to determine if we should cache immediately
        $queueSize = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM cache_queue WHERE status = 'pending' AND cache_type = 'thumbnail'"
        );

        $pendingCount = (int)($queueSize['count'] ?? 0);

        if ($pendingCount < 10) {
            // Low queue, cache immediately
            $this->thumbnailCache->cache($archiveId);
        } else {
            // High queue, add to background queue
            $this->cacheManager->queueForCaching($archiveId, 'thumbnail', 3);
        }
    }

    /**
     * Batch cache items from search results
     * Called after displaying search results to proactively cache viewed items
     */
    public function batchCacheFromSearch(array $searchResults, bool $immediate = false): array {
        $results = [
            'queued' => 0,
            'cached' => 0,
            'already_cached' => 0,
            'items' => [],
        ];

        foreach ($searchResults as $item) {
            $archiveId = $item['identifier'] ?? null;
            if (!$archiveId) continue;

            // Check if already cached
            $existing = $this->cacheManager->getMetadataCache($archiveId);

            if ($existing && !isset($existing['_is_stale'])) {
                $results['already_cached']++;
                continue;
            }

            if ($immediate) {
                // Cache immediately (use for small result sets)
                $cacheResult = $this->cacheItem($archiveId, $this->normalizeSearchItem($item));
                $results['cached']++;
                $results['items'][$archiveId] = $cacheResult;
            } else {
                // Queue for background caching
                $this->cacheManager->queueForCaching($archiveId, 'metadata', 5);
                $this->cacheManager->queueForCaching($archiveId, 'thumbnail', 6);
                $results['queued']++;
            }
        }

        return $results;
    }

    /**
     * Normalize search result item to metadata format
     */
    private function normalizeSearchItem(array $item): array {
        return [
            'identifier' => $item['identifier'] ?? '',
            'title' => $this->extractValue($item, 'title') ?? $item['identifier'],
            'description' => $this->extractValue($item, 'description'),
            'creator' => $this->extractValue($item, 'creator'),
            'date' => $this->extractValue($item, 'date'),
            'runtime' => $this->extractValue($item, 'runtime'),
            'mediatype' => $this->extractValue($item, 'mediatype'),
            'downloads' => (int)($item['downloads'] ?? 0),
            'collection' => $item['collection'] ?? [],
            'thumbnail' => "https://archive.org/services/img/{$item['identifier']}",
        ];
    }

    /**
     * Normalize full metadata from Archive.org response
     */
    private function normalizeMetadata(string $archiveId, array $data): array {
        $metadata = $data['metadata'] ?? [];
        $files = $data['files'] ?? [];

        // Find video files
        $videoFiles = [];
        foreach ($files as $file) {
            $name = $file['name'] ?? '';
            $format = strtolower($file['format'] ?? '');

            // Check for video formats
            if (preg_match('/\.(mp4|ogv|webm|avi|mkv|mov)$/i', $name) ||
                in_array($format, ['h.264', 'mpeg4', 'ogg video', 'webm', '512kb mpeg4'])) {
                $videoFiles[] = [
                    'name' => $name,
                    'format' => $format,
                    'size' => $file['size'] ?? 0,
                    'url' => "https://archive.org/download/{$archiveId}/{$name}",
                ];
            }
        }

        return [
            'identifier' => $archiveId,
            'title' => $this->extractValue($metadata, 'title') ?? $archiveId,
            'description' => $this->extractValue($metadata, 'description'),
            'creator' => $this->extractValue($metadata, 'creator'),
            'date' => $this->extractValue($metadata, 'date'),
            'runtime' => $this->extractValue($metadata, 'runtime'),
            'mediatype' => $this->extractValue($metadata, 'mediatype'),
            'downloads' => (int)($metadata['downloads'] ?? 0),
            'licenseurl' => $this->extractValue($metadata, 'licenseurl'),
            'subject' => $metadata['subject'] ?? [],
            'collection' => $metadata['collection'] ?? [],
            'files' => $videoFiles,
            'thumbnail' => "https://archive.org/services/img/{$archiveId}",
        ];
    }

    /**
     * Extract value from array (handles array values)
     */
    private function extractValue(array $data, string $key): ?string {
        if (!isset($data[$key])) {
            return null;
        }
        return is_array($data[$key]) ? ($data[$key][0] ?? null) : $data[$key];
    }

    /**
     * Process background cache queue
     * Called by cron job or during low-traffic periods
     */
    public function processQueue(int $limit = 20): array {
        $results = [
            'metadata_processed' => 0,
            'thumbnails_processed' => 0,
            'reaped' => 0,
            'errors' => [],
        ];

        // Re-queue anything a previous run left stuck in 'processing' (killed
        // mid-item by a short max_execution_time) before we claim new work.
        $results['reaped'] = $this->cacheManager->reapStuckQueueItems();

        // Process metadata queue
        $metadataItems = $this->cacheManager->getPendingCacheItems('metadata', $limit);
        foreach ($metadataItems as $item) {
            // Atomic claim — skip if another run grabbed this row first.
            if (!$this->cacheManager->markQueueItemProcessing($item['id'])) {
                continue;
            }

            try {
                $fetchResult = $this->fetchAndCacheMetadata($item['archive_id']);
                if ($fetchResult['success']) {
                    $this->cacheManager->markQueueItemCompleted($item['id']);
                    $results['metadata_processed']++;
                } else {
                    $this->cacheManager->markQueueItemFailed($item['id'], $fetchResult['error']);
                    $results['errors'][] = "Metadata {$item['archive_id']}: {$fetchResult['error']}";
                }
            } catch (Exception $e) {
                $this->cacheManager->markQueueItemFailed($item['id'], $e->getMessage());
                $results['errors'][] = "Metadata {$item['archive_id']}: {$e->getMessage()}";
            }
        }

        // Process thumbnail queue
        $thumbnailItems = $this->cacheManager->getPendingCacheItems('thumbnail', $limit);
        foreach ($thumbnailItems as $item) {
            // Atomic claim — skip if another run grabbed this row first.
            if (!$this->cacheManager->markQueueItemProcessing($item['id'])) {
                continue;
            }

            try {
                $path = $this->thumbnailCache->cache($item['archive_id']);
                if ($path) {
                    $this->cacheManager->markQueueItemCompleted($item['id']);
                    $results['thumbnails_processed']++;
                } else {
                    $this->cacheManager->markQueueItemFailed($item['id'], 'Failed to download thumbnail');
                    $results['errors'][] = "Thumbnail {$item['archive_id']}: Failed to download";
                }
            } catch (Exception $e) {
                $this->cacheManager->markQueueItemFailed($item['id'], $e->getMessage());
                $results['errors'][] = "Thumbnail {$item['archive_id']}: {$e->getMessage()}";
            }
        }

        return $results;
    }

    /**
     * Refresh stale metadata
     * Called periodically to keep cached data up to date
     */
    public function refreshStaleData(int $limit = 10): array {
        $results = [
            'refreshed' => 0,
            'errors' => [],
        ];

        $staleItems = $this->cacheManager->getStaleMetadata($limit);

        foreach ($staleItems as $item) {
            try {
                $fetchResult = $this->fetchAndCacheMetadata($item['archive_id']);
                if ($fetchResult['success']) {
                    $results['refreshed']++;
                } else {
                    $results['errors'][] = "{$item['archive_id']}: {$fetchResult['error']}";
                }

                // Add small delay to be polite to Archive.org
                usleep(100000); // 100ms
            } catch (Exception $e) {
                $results['errors'][] = "{$item['archive_id']}: {$e->getMessage()}";
            }
        }

        return $results;
    }

    /**
     * Get caching statistics
     */
    public function getStats(): array {
        return $this->cacheManager->getDetailedStats();
    }

    /**
     * Make HTTP GET request.
     *
     * Delegates to the one hardened fetch path (cURL-first so it works on
     * allow_url_fopen=Off hosts, response size capped). This used to be a
     * file_get_contents-only duplicate with no size cap, so the background
     * queue silently failed on exactly the shared hosts it was built for.
     */
    private function httpGet(string $url): array {
        return ArchiveOrgService::fetchUrl($url);
    }
}
