<?php
/**
 * Archive.org API Service
 *
 * Handles all communication with Archive.org API
 * with caching support for improved performance
 * Enhanced to proactively cache thumbnails and store raw metadata
 */

require_once __DIR__ . '/../cache/SearchCache.php';
require_once __DIR__ . '/../cache/MetadataCache.php';
require_once __DIR__ . '/../cache/ThumbnailCache.php';
require_once __DIR__ . '/../db/Database.php';

class ArchiveOrgService {
    private $searchCache;
    private $metadataCache;
    private $thumbnailCache;
    private $db;
    private $config;

    // API settings
    const API_BASE_URL = 'https://archive.org';
    const API_TIMEOUT = 15;
    const USER_AGENT = 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)';
    /** Cap on a buffered archive.org response (memory guard, not a quota). */
    const MAX_RESPONSE_BYTES = 52428800; // 50 MB

    // Proactive caching settings
    private $proactiveThumbnailCaching = true;
    private $storeRawMetadata = true;

    public function __construct() {
        $this->searchCache = new SearchCache();
        $this->metadataCache = new MetadataCache();
        $this->thumbnailCache = new ThumbnailCache();
        $this->db = Database::getInstance();
        $this->config = $this->db->getConfig();

        // Load caching settings
        $this->loadCachingSettings();
    }

    /**
     * Load caching settings from database
     */
    private function loadCachingSettings(): void {
        try {
            $setting = $this->db->fetchOne(
                "SELECT setting_value FROM site_settings WHERE setting_key = 'cacheThumbnailsOnView'"
            );
            if ($setting) {
                $this->proactiveThumbnailCaching = (bool)$setting['setting_value'];
            }
        } catch (Exception $e) {
            // Use defaults if settings don't exist yet
        }
    }

    // How old (seconds) a permanent search row has to be before we'll do
    // a background refresh against archive.org. Defaults to 30 days
    // matching the `staleAfterDays` setting for metadata. The point of
    // this is to bound archive.org traffic to ~1 request per query per
    // month, no matter how many users hit the cached row.
    const SEARCH_REFRESH_AFTER_SECONDS = 30 * 86400;

    /**
     * Search the Archive.org collection.
     *
     * Caching contract:
     *  - First-time queries hit archive.org once and store the result.
     *  - Repeat queries serve from cache and do NOT hit archive.org.
     *  - Past the stale window, the cached row is returned immediately
     *    (`stale=true` in the response) and exactly one background refresh
     *    is fired via register_shutdown_function() — so archive.org sees
     *    at most one refresh per query per stale window, regardless of
     *    concurrent traffic.
     *  - Permanent rows are never deleted by the cleanup cron.
     */
    public function search(array $params): array {
        $startTime = microtime(true);
        $cacheHit = false;
        $isStale = false;

        // Normalize parameters
        $searchParams = $this->normalizeSearchParams($params);

        // Try cache first (gracefully handle if tables don't exist)
        $cached = null;
        try {
            $cached = $this->searchCache->get($searchParams);
        } catch (Exception $e) {
            // Cache unavailable, will fetch from API
            error_log("Search cache unavailable: " . $e->getMessage());
        }

        if ($cached !== null) {
            $cacheHit = true;
            $isStale = !empty($cached['_is_stale']);
            // Strip the internal flag before sending to client.
            unset($cached['_is_stale']);

            $result = [
                'success' => true,
                'cached' => true,
                'stale' => $isStale,
                'cache_age' => 0,
                'data' => $cached,
            ];

            // Bounded background refresh. Defer the upstream call until after
            // the response is sent so the user never waits on archive.org.
            // Only one refresh fires per stale window per query — the SQL
            // guard checks last_refreshed before doing the work, so even
            // concurrent stale hits won't stampede the upstream.
            if ($isStale) {
                $this->scheduleBackgroundSearchRefresh($searchParams);
            }
        } else {
            // Fetch from Archive.org
            $apiResult = $this->fetchFromArchive($searchParams);

            if ($apiResult['success']) {
                // Try to cache the results (gracefully handle failure)
                try {
                    $this->searchCache->set(
                        $searchParams,
                        $apiResult['data'],
                        $apiResult['data']['response']['numFound'] ?? 0
                    );
                } catch (Exception $e) {
                    // Caching failed, but we still have results
                    error_log("Failed to cache search results: " . $e->getMessage());
                }

                $result = [
                    'success' => true,
                    'cached' => false,
                    'data' => $apiResult['data'],
                ];

                // Queue caching of search result items (thumbnails primarily)
                if ($this->proactiveThumbnailCaching && isset($apiResult['data']['response']['docs'])) {
                    $this->queueSearchResultsCaching($apiResult['data']['response']['docs']);
                }
            } else {
                $result = [
                    'success' => false,
                    'error' => $apiResult['error'],
                ];
            }
        }

        // Log API usage if enabled (gracefully handle failure)
        if ($this->config['features']['api_logging'] ?? false) {
            try {
                $this->logApiUsage('search', $cacheHit, microtime(true) - $startTime);
            } catch (Exception $e) {
                // Logging failed, ignore
            }
        }

        // Track popular searches (gracefully handle failure)
        if (isset($params['q']) && !empty($params['q'])) {
            try {
                $this->trackPopularSearch($params['q']);
            } catch (Exception $e) {
                // Tracking failed, ignore
            }
        }

        return $result;
    }

    /**
     * Defer an upstream archive.org refresh for a stale search row until
     * AFTER the current response is sent.
     *
     * Guards against stampede: re-checks last_refreshed inside the shutdown
     * handler so concurrent stale hits coalesce into a single upstream
     * call. If another worker already refreshed the row in the meantime,
     * we no-op.
     */
    private function scheduleBackgroundSearchRefresh(array $searchParams): void {
        // Don't pile up multiple shutdown handlers for the same query in
        // one PHP-FPM worker — once per request is enough.
        static $scheduled = [];
        $key = $this->searchCache->generateKey($searchParams);
        if (isset($scheduled[$key])) return;
        $scheduled[$key] = true;

        register_shutdown_function(function () use ($searchParams, $key) {
            try {
                // Re-check: did anything else just refresh this row?
                $row = $this->db->fetchOne(
                    "SELECT last_refreshed FROM search_cache WHERE cache_key = ?",
                    [$key]
                );
                if ($row && !empty($row['last_refreshed'])) {
                    $age = time() - strtotime($row['last_refreshed']);
                    if ($age < self::SEARCH_REFRESH_AFTER_SECONDS) {
                        // Too recently refreshed by someone else; skip.
                        return;
                    }
                }

                $apiResult = $this->fetchFromArchive($searchParams);
                if (!empty($apiResult['success'])) {
                    $this->searchCache->set(
                        $searchParams,
                        $apiResult['data'],
                        $apiResult['data']['response']['numFound'] ?? 0
                    );
                }
            } catch (Throwable $e) {
                // Background refresh failure is non-fatal — the cached
                // row stays put and we'll try again next stale window.
                error_log('[bg search refresh] ' . $e->getMessage());
            }
        });
    }

    /**
     * Queue caching of search result items
     * Only queues thumbnails for items not already cached
     */
    private function queueSearchResultsCaching(array $docs): void {
        try {
            // Limit to first 20 items to avoid overwhelming the queue
            $docs = array_slice($docs, 0, 20);

            foreach ($docs as $doc) {
                $archiveId = $doc['identifier'] ?? null;
                if (!$archiveId) continue;

                // Queue thumbnail caching with lower priority (these are just search results)
                $this->db->query(
                    "INSERT IGNORE INTO cache_queue (archive_id, cache_type, priority, status)
                     VALUES (?, 'thumbnail', 7, 'pending')",
                    [$archiveId]
                );
            }
        } catch (Exception $e) {
            // Silently fail - don't break main request
            error_log("Failed to queue search results caching: " . $e->getMessage());
        }
    }

    /**
     * Get video metadata
     * Enhanced to proactively cache thumbnails and store raw metadata
     * Resilient: falls back to direct API if caching fails
     */
    public function getMetadata(string $archiveId): array {
        $startTime = microtime(true);
        $cacheHit = false;
        $isStale = false;

        // Try cache first (gracefully handle if tables don't exist)
        $cached = null;
        try {
            $cached = $this->metadataCache->get($archiveId);
        } catch (Exception $e) {
            // Cache unavailable, will fetch from API
            error_log("Metadata cache unavailable: " . $e->getMessage());
        }

        if ($cached !== null) {
            $cacheHit = true;
            $isStale = isset($cached['_is_stale']) && $cached['_is_stale'];

            $result = [
                'success' => true,
                'cached' => true,
                'stale' => $isStale,
                'data' => $cached,
            ];

            // Proactively cache thumbnail if not already cached
            if ($this->proactiveThumbnailCaching) {
                $this->queueThumbnailCaching($archiveId);
            }
        } else {
            // Fetch from Archive.org
            $url = self::API_BASE_URL . "/metadata/{$archiveId}";
            $response = $this->httpGet($url);

            if ($response['success'] && !empty($response['data'])) {
                $rawData = json_decode($response['data'], true);

                if ($rawData && isset($rawData['metadata'])) {
                    // Extract and normalize metadata
                    $metadata = $this->normalizeMetadata($archiveId, $rawData);

                    // Try to cache it (gracefully handle failure)
                    try {
                        $this->metadataCache->set($archiveId, $metadata, $this->storeRawMetadata ? $rawData : null);
                    } catch (Exception $e) {
                        error_log("Failed to cache metadata: " . $e->getMessage());
                    }

                    $result = [
                        'success' => true,
                        'cached' => false,
                        'data' => $metadata,
                    ];

                    // Proactively cache thumbnail
                    if ($this->proactiveThumbnailCaching) {
                        $this->queueThumbnailCaching($archiveId);
                    }
                } else {
                    $result = [
                        'success' => false,
                        'error' => 'Invalid metadata response',
                    ];
                }
            } else {
                $result = [
                    'success' => false,
                    'error' => $response['error'] ?? 'Failed to fetch metadata',
                ];
            }
        }

        // Log API usage
        if ($this->config['features']['api_logging'] ?? false) {
            $this->logApiUsage('metadata', $cacheHit, microtime(true) - $startTime);
        }

        return $result;
    }

    /**
     * Queue thumbnail for caching (non-blocking)
     */
    private function queueThumbnailCaching(string $archiveId): void {
        try {
            // Check if already cached
            $existing = $this->db->fetchOne(
                "SELECT id FROM thumbnail_cache WHERE archive_id = ?",
                [$archiveId]
            );

            if ($existing) {
                return; // Already cached
            }

            // Check queue size
            $queueSize = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM cache_queue WHERE status = 'pending' AND cache_type = 'thumbnail'"
            );

            $pendingCount = (int)($queueSize['count'] ?? 0);

            if ($pendingCount < 5) {
                // Low queue, cache immediately in background
                $this->cacheThumbnailAsync($archiveId);
            } else {
                // Add to queue for later processing
                $this->db->query(
                    "INSERT INTO cache_queue (archive_id, cache_type, priority, status)
                     VALUES (?, 'thumbnail', 3, 'pending')
                     ON DUPLICATE KEY UPDATE
                        priority = LEAST(priority, VALUES(priority)),
                        status = IF(status = 'failed', 'pending', status)",
                    [$archiveId]
                );
            }
        } catch (Exception $e) {
            // Silently fail - don't break main request
            error_log("Failed to queue thumbnail caching for {$archiveId}: " . $e->getMessage());
        }
    }

    /**
     * Cache thumbnail asynchronously (best effort)
     */
    private function cacheThumbnailAsync(string $archiveId): void {
        try {
            // Use register_shutdown_function to cache after response is sent
            register_shutdown_function(function() use ($archiveId) {
                try {
                    $this->thumbnailCache->cache($archiveId);
                } catch (Exception $e) {
                    error_log("Async thumbnail cache failed for {$archiveId}: " . $e->getMessage());
                }
            });
        } catch (Exception $e) {
            // Fallback: queue it
            $this->db->query(
                "INSERT IGNORE INTO cache_queue (archive_id, cache_type, priority, status)
                 VALUES (?, 'thumbnail', 5, 'pending')",
                [$archiveId]
            );
        }
    }

    /**
     * Normalize search parameters
     */
    private function normalizeSearchParams(array $params): array {
        $normalized = [
            'q' => $params['q'] ?? '',
            'page' => max(1, (int)($params['page'] ?? 1)),
            'rows' => min(50, max(1, (int)($params['rows'] ?? 20))),
            'sort' => $params['sort'] ?? 'downloads desc',
        ];

        // Build collection filter
        $collection = $params['collection'] ?? 'all_videos';
        if ($collection && $collection !== 'all_videos') {
            $normalized['collection'] = $collection;
        }

        // Note: mediatype filtering is handled by the frontend query builder
        // (SearchService.buildSearchQuery) which constructs OR clauses to include
        // both video types and collections. Do not override it here.

        return $normalized;
    }

    /**
     * Fetch search results from Archive.org
     */
    private function fetchFromArchive(array $params): array {
        // Build query
        $query = $params['q'];

        // Add collection filter. Allow-list to the archive.org identifier
        // charset so a caller can't inject Lucene operators (AND/OR, field:
        // filters) and break out of the intended collection scope.
        // http_build_query already URL-encodes the result, so this is not
        // SSRF or DB injection — but it does stop query-semantics tampering.
        if (!empty($params['collection'])) {
            $collection = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)$params['collection']);
            if ($collection !== '') {
                $query .= " AND collection:{$collection}";
            }
        }

        // Add media type filter (same allow-listing — a single mediatype token).
        if (!empty($params['mediatype'])) {
            $mediatype = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)$params['mediatype']);
            if ($mediatype !== '') {
                $query .= " AND mediatype:{$mediatype}";
            }
        }

        // Build API URL
        $apiParams = [
            'q' => $query,
            'fl' => 'identifier,title,creator,description,downloads,date,mediatype,collection,runtime',
            'rows' => $params['rows'],
            'page' => $params['page'],
            'output' => 'json',
        ];

        // Handle sort
        $sortMap = [
            'downloads' => 'downloads desc',
            'date' => 'date desc',
            'title' => 'titleSorter asc',
            'creator' => 'creatorSorter asc',
            'relevance' => '',
        ];
        // Validate sort strictly against the known map. Accept either a known
        // key ('downloads') or an already-mapped value ('downloads desc'); any
        // other value falls back to the default rather than being passed
        // through verbatim (which previously let a caller inject arbitrary
        // sort syntax into the Archive.org query).
        $requestedSort = (string)($params['sort'] ?? 'downloads');
        if (isset($sortMap[$requestedSort])) {
            $sort = $sortMap[$requestedSort];
        } elseif (in_array($requestedSort, $sortMap, true)) {
            $sort = $requestedSort;
        } else {
            $sort = $sortMap['downloads'];
        }
        if (!empty($sort)) {
            $apiParams['sort'] = $sort;
        }

        $url = self::API_BASE_URL . '/advancedsearch.php?' . http_build_query($apiParams);
        $response = $this->httpGet($url);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error'] ?? 'API request failed',
            ];
        }

        $data = json_decode($response['data'], true);

        if (!$data || !isset($data['response'])) {
            return [
                'success' => false,
                'error' => 'Invalid API response',
            ];
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * Normalize metadata from Archive.org response
     */
    private function normalizeMetadata(string $archiveId, array $data): array {
        $metadata = $data['metadata'] ?? [];
        $files = $data['files'] ?? [];

        // Helper to extract array values
        $extract = function($key) use ($metadata) {
            if (!isset($metadata[$key])) return null;
            return is_array($metadata[$key]) ? $metadata[$key][0] : $metadata[$key];
        };

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
                    // Preserve per-file duration so the player playlist
                    // sidebar can render episode length badges. Archive.org
                    // returns this as a "length" field (usually a string
                    // like "1234.56" seconds or "HH:MM:SS"); the JS formatter
                    // handles both.
                    'length' => $file['length'] ?? null,
                    'url' => "https://archive.org/download/{$archiveId}/{$name}",
                ];
            }
        }

        return [
            'identifier' => $archiveId,
            'title' => $extract('title') ?? $archiveId,
            'description' => $extract('description'),
            'creator' => $extract('creator'),
            'date' => $extract('date'),
            'runtime' => $extract('runtime'),
            'mediatype' => $extract('mediatype'),
            'downloads' => (int)($metadata['downloads'] ?? 0),
            'licenseurl' => $extract('licenseurl'),
            'subject' => $metadata['subject'] ?? [],
            'collection' => $metadata['collection'] ?? [],
            'files' => $videoFiles,
            'thumbnail' => "https://archive.org/services/img/{$archiveId}",
        ];
    }

    /**
     * Fetch metadata for many archive IDs in parallel and cache each.
     *
     * The serial version (calling getMetadata() in a foreach) was a major
     * source of perceived "offline" errors: an uncached page with 10
     * missing IDs would take up to 150 seconds wall-clock, and the
     * service worker's 20s timeout would cancel the response and surface
     * `TypeError: Failed to fetch` to the client — which the offline UI
     * mis-classified as "you are offline".
     *
     * Now we issue all uncached metadata requests in parallel via
     * curl_multi (when available) so a batch of 20 IDs returns in roughly
     * the latency of the *slowest* single request rather than the sum.
     *
     * Falls back to the original serial path when cURL isn't installed.
     *
     * Returns: [archiveId => normalizedMetadataArray|null]
     */
    public function getMetadataBatch(array $archiveIds): array {
        $results = [];
        $toFetch = [];

        // First, drain the local cache (cheap).
        foreach ($archiveIds as $id) {
            $cached = null;
            try {
                $cached = $this->metadataCache->get($id);
            } catch (Exception $e) {
                error_log("Metadata cache unavailable for {$id}: " . $e->getMessage());
            }
            if ($cached !== null) {
                $results[$id] = $cached;
            } else {
                $toFetch[] = $id;
            }
        }

        if (empty($toFetch)) {
            return $results;
        }

        // Parallel fetch path (cURL multi). Falls back to serial getMetadata()
        // if curl_multi isn't available (rare, but seen on stripped shared hosting).
        if (function_exists('curl_multi_init')) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($toFetch as $id) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => self::API_BASE_URL . "/metadata/{$id}",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => self::API_TIMEOUT,
                    CURLOPT_USERAGENT => self::USER_AGENT,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$id] = $ch;
            }

            // Drive the multi-handle until done.
            $active = null;
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 0.5);
                }
            } while ($active && $status === CURLM_OK);

            foreach ($handles as $id => $ch) {
                $body = curl_multi_getcontent($ch);
                $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($body === false || $httpStatus >= 400 || empty($body)) {
                    $results[$id] = null;
                    continue;
                }
                $rawData = json_decode($body, true);
                if (!$rawData || !isset($rawData['metadata'])) {
                    $results[$id] = null;
                    continue;
                }

                $metadata = $this->normalizeMetadata($id, $rawData);
                try {
                    $this->metadataCache->set($id, $metadata, $this->storeRawMetadata ? $rawData : null);
                } catch (Exception $e) {
                    // Cache write failure is non-fatal.
                    error_log("Failed to cache metadata for {$id}: " . $e->getMessage());
                }

                if ($this->proactiveThumbnailCaching) {
                    $this->queueThumbnailCaching($id);
                }

                $results[$id] = $metadata;
            }
            curl_multi_close($mh);
        } else {
            // Fallback: serial. Slow but functional.
            foreach ($toFetch as $id) {
                $r = $this->getMetadata($id);
                $results[$id] = (!empty($r['success']) && !empty($r['data'])) ? $r['data'] : null;
            }
        }

        return $results;
    }

    /**
     * Make HTTP GET request (tries cURL first, then file_get_contents)
     */
    private function httpGet(string $url): array {
        return self::fetchUrl($url);
    }

    /**
     * Shared outbound GET: cURL first (works on allow_url_fopen=Off hosts),
     * file_get_contents fallback, both capped at MAX_RESPONSE_BYTES. Static
     * so LocalStorageService (and any other caller) can reuse it without
     * constructing this service — which would drag in the cache layer and a
     * settings read just to make one HTTP call.
     *
     * Returns ['success' => bool, 'data' => string] or ['success' => false, 'error' => string].
     */
    public static function fetchUrl(string $url): array {
        $data = null;
        $status = 0;

        // Try cURL first (more reliable on shared hosting)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => self::API_TIMEOUT,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_SSL_VERIFYPEER => true,
                // Generous memory guard — real archive.org JSON responses are
                // far smaller; only honored when Content-Length is sent.
                CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
            ]);
            $data = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($data === false || !empty($curlError)) {
                $data = null;
            }
        }

        // Fallback to file_get_contents
        if ($data === null && ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::API_TIMEOUT,
                    'user_agent' => self::USER_AGENT,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $data = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES);

            // Check HTTP status
            $status = 200;
            if (isset($http_response_header[0])) {
                preg_match('/HTTP\/[\d.]+\s+(\d+)/', $http_response_header[0], $matches);
                $status = (int)($matches[1] ?? 200);
            }
        }

        if ($data === null || $data === false) {
            return [
                'success' => false,
                'error' => 'Network request failed',
            ];
        }

        if ($status >= 400) {
            return [
                'success' => false,
                'error' => "HTTP error: $status",
            ];
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * Log API usage
     */
    private function logApiUsage(string $endpoint, bool $cacheHit, float $duration): void {
        try {
            $this->db->insert('api_usage_log', [
                'endpoint' => $endpoint,
                'cache_hit' => $cacheHit ? 1 : 0,
                'response_time_ms' => (int)($duration * 1000),
            ]);
        } catch (Exception $e) {
            // Silently fail - logging shouldn't break the request
            error_log("Failed to log API usage: " . $e->getMessage());
        }
    }

    /**
     * Track popular searches
     */
    private function trackPopularSearch(string $query): void {
        try {
            $queryHash = hash('sha256', strtolower(trim($query)));

            $this->db->query(
                "INSERT INTO popular_searches (query, query_hash, search_count, last_searched)
                 VALUES (?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE
                    search_count = search_count + 1,
                    last_searched = NOW()",
                [$query, $queryHash]
            );
        } catch (Exception $e) {
            // Silently fail
            error_log("Failed to track popular search: " . $e->getMessage());
        }
    }

    /**
     * Get popular searches
     */
    public function getPopularSearches(int $limit = 10): array {
        return $this->db->fetchAll(
            "SELECT query, search_count FROM popular_searches
             ORDER BY search_count DESC LIMIT " . (int)$limit,
            []
        );
    }
}
