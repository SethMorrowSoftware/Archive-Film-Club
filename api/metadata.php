<?php
/**
 * Video Metadata API Endpoint
 *
 * GET ?id=X → cached video metadata from Archive.org
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod('GET');

$archiveId = ApiController::sanitizeArchiveId($api->query('id', ''));
if ($archiveId === '') {
    $api->error('Missing or invalid video ID', 400);
}

try {
    $archiveService = new ArchiveOrgService();
    $result = $archiveService->getMetadata($archiveId);

    // A cache HIT hands back the raw video_metadata_cache row, a MISS the
    // normalized archive.org shape. Project the hit onto the miss shape so
    // the client always sees identifier/thumbnail/licenseurl and never the
    // internal columns (is_stale, refresh_count, raw_metadata, ...).
    if (is_array($result['data'] ?? null)) {
        $result['data'] = CacheManager::normalizeMetadataRow($result['data']);
    }

    // public/cacheable — metadata is not user-bound and the server caches
    // it permanently against archive.org. The headers below govern how
    // often the BROWSER comes back to the server; the server doesn't
    // re-hit archive.org just because the browser re-asks.
    //
    // Fresh DB hit: 24-hour browser cache + 7-day SWR. Metadata for an
    //   archive.org item virtually never changes.
    // Stale DB hit: short window so the browser picks up the background
    //   refresh result on its next request.
    // Cache miss: medium window — next page load reuses the just-cached row.
    $stale = $result['stale'] ?? false;
    if ($stale) {
        header('X-Cache: STALE');
        header('Cache-Control: public, max-age=300, stale-while-revalidate=86400');
    } elseif ($result['cached'] ?? false) {
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
    } else {
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
    }

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('[api/metadata] ' . $e->getMessage());
    $api->error('Failed to fetch metadata. Please try again.', 500);
}
