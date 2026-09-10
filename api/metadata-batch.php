<?php
/**
 * Batch Video Metadata API Endpoint
 *
 * GET ?ids=id1,id2,id3,... → cached metadata for multiple videos in a single request
 * POST { ids: [...] }      → same, but for longer ID lists
 *
 * Returns: { success: true, data: { id1: {...metadata}, id2: {...}, ... } }
 *
 * Heavily cached: results that come fully from local cache get an aggressive
 * cache header so the browser/SW never re-asks. Designed specifically for
 * staff picks / featured sections where the same handful of IDs are loaded
 * on every homepage hit.
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

// POSTs queue server-side cache fetches against archive.org, same threat
// model as api/cache.php. GET is read-only -- pass through unguarded.
if ($api->isPost()) {
    $api->requireCsrf();
}

// Collect IDs from either query string or JSON body
$ids = [];
if ($api->isGet()) {
    $idsParam = trim((string)$api->query('ids', ''));
    if ($idsParam !== '') {
        $ids = array_filter(array_map('trim', explode(',', $idsParam)));
    }
} else {
    $body = $api->jsonBody();
    if (isset($body['ids']) && is_array($body['ids'])) {
        $ids = $body['ids'];
    }
}

// Sanitize and dedupe
$cleanIds = [];
foreach ($ids as $id) {
    $clean = ApiController::sanitizeArchiveId($id);
    if ($clean !== '') {
        $cleanIds[$clean] = true;
    }
}
$ids = array_keys($cleanIds);

if (empty($ids)) {
    $api->ok(['data' => (object)[]]);
}

// Cap to a sane limit so the endpoint can't be used to hammer Archive.org
$ids = array_slice($ids, 0, 50);

// How many UNCACHED ids one request may fetch from archive.org synchronously.
// The endpoint is public (the homepage calls it for guests), so without this
// a single anonymous request could fan out 50 upstream fetches. Anything past
// the cap is handed to the background cache queue instead and answered with
// a stub this time round; the short MISS cache header below brings the
// client back once the cron/queue has filled the rows in.
$MAX_SYNC_FETCH = 5;

try {
    $archiveService = new ArchiveOrgService();
    $cacheManager = new CacheManager();

    $results = [];
    $allCached = true;
    $needsFetch = [];

    // First pass: pull everything we already have from local cache
    foreach ($ids as $id) {
        $cached = null;
        try {
            $cached = $cacheManager->getMetadataCache($id);
        } catch (Throwable $e) {
            // Cache table missing — fall back to fetching
        }

        if ($cached !== null) {
            // Same public shape as a miss (drops internal columns too).
            $results[$id] = CacheManager::normalizeMetadataRow($cached);
        } else {
            $needsFetch[] = $id;
            $allCached = false;
        }
    }

    // Bound the synchronous upstream work; queue the rest.
    $deferred = [];
    if (count($needsFetch) > $MAX_SYNC_FETCH) {
        $deferred = array_slice($needsFetch, $MAX_SYNC_FETCH);
        $needsFetch = array_slice($needsFetch, 0, $MAX_SYNC_FETCH);
    }
    foreach ($deferred as $id) {
        // queueForCaching() is best-effort and swallows its own DB errors.
        $cacheManager->queueForCaching($id, 'metadata', 4);
        $results[$id] = [
            'identifier' => $id,
            'title' => $id,
            'thumbnail' => "https://archive.org/services/img/{$id}",
            '_unavailable' => true,
            '_queued' => true,
        ];
    }

    // Second pass: fetch anything that wasn't cached, IN PARALLEL.
    //
    // The previous version did this serially, which (with the 15s per-call
    // upstream timeout) could take up to 15 * N seconds. The service worker's
    // 20s fetch timeout would then kill the response and the client would
    // get a "Failed to fetch" that got mis-shown as "you are offline".
    //
    // getMetadataBatch() drives curl_multi so a batch of 20 IDs returns
    // in roughly the latency of the slowest single request.
    if (!empty($needsFetch)) {
        $batchResult = $archiveService->getMetadataBatch($needsFetch);
        foreach ($needsFetch as $id) {
            $meta = $batchResult[$id] ?? null;
            if ($meta) {
                $results[$id] = CacheManager::normalizeMetadataRow($meta);
            } else {
                // Minimal stub so the client can still render a card.
                $results[$id] = [
                    'identifier' => $id,
                    'title' => $id,
                    'thumbnail' => "https://archive.org/services/img/{$id}",
                    '_unavailable' => true,
                ];
            }
        }
    }

    // Cache headers: server already caches metadata permanently against
    // archive.org, so when everything is locally cached we can be very
    // aggressive — repeat homepage loads should reuse the browser copy
    // for a full day before even re-asking the server.
    if ($allCached) {
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
    } else {
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
    }

    $api->data($results);
} catch (Throwable $e) {
    error_log('[api/metadata-batch] ' . $e->getMessage());
    $api->error('Failed to fetch batch metadata', 500);
}
