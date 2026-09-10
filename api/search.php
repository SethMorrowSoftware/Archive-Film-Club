<?php
/**
 * Search API Endpoint
 *
 * GET ?q=...&page=N&rows=N&collection=X&sort=Y
 * Proxies Archive.org search with caching.
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod('GET');

$query = trim((string)$api->query('q', ''));
$page = max(1, (int)$api->query('page', 1));
$rows = min(50, max(1, (int)$api->query('rows', 20)));
$collection = (string)$api->query('collection', 'all_videos');
$sort = (string)$api->query('sort', 'downloads');

if ($query === '') {
    echo json_encode([
        'success' => true,
        'cached' => false,
        'data' => [
            'response' => [
                'numFound' => 0,
                'docs' => [],
            ],
        ],
    ]);
    exit;
}

// NOTE: Do not apply htmlspecialchars here — the query is sent to
// Archive.org's API (via http_build_query, URL-encoded), not rendered
// as HTML. htmlspecialchars would corrupt quotes and ampersands.

try {
    $archiveService = new ArchiveOrgService();
    $result = $archiveService->search([
        'q' => $query,
        'page' => $page,
        'rows' => $rows,
        'collection' => $collection,
        'sort' => $sort,
    ]);

    // Record the search for the account's "recent searches" and the admin
    // search metrics — nothing else writes search_history. Non-fatal: history
    // must never break search. First page only, so paging through one result
    // set doesn't count as N searches; crawlers resolve to a transient guest
    // with no users row and are skipped so nothing is ever written for them.
    if (!empty($result['success']) && $page === 1) {
        try {
            $context = new UserContext();
            if (!$context->isTransient()) {
                $resultCount = (int)($result['data']['response']['numFound'] ?? 0);
                $history = new SearchHistoryService();
                $history->record(
                    $context->currentId(),
                    mb_substr($query, 0, 500),
                    ['collection' => $collection, 'sort' => $sort],
                    $resultCount
                );
            }
        } catch (Throwable $e) {
            error_log('[api/search] history: ' . $e->getMessage());
        }
    }

    // HTTP cache headers — these govern how often the BROWSER and the
    // service worker come back to the server, separate from how often
    // the server comes back to archive.org (which is now ~once per query
    // per 30-day stale window thanks to permanent caching).
    //
    // Fresh DB hit: serve aggressively. The data won't change soon and
    //   any change archive.org makes will be picked up by background
    //   refresh — the user benefits from instant repeat searches.
    // Stale DB hit: short window so the browser comes back soon and
    //   picks up the result of the background refresh that's already
    //   in flight.
    // Cache miss: short window — we'll have the result on the next
    //   request and want the browser to come back for it.
    if (!empty($result['stale'])) {
        header('X-Cache: STALE');
        header('Cache-Control: public, max-age=60, stale-while-revalidate=3600');
    } elseif ($result['cached'] ?? false) {
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=1800, stale-while-revalidate=86400');
    } else {
        header('X-Cache: MISS');
        header('Cache-Control: public, max-age=300, stale-while-revalidate=3600');
    }

    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    error_log('[api/search] ' . $e->getMessage());
    $api->error('Search failed. Please try again.', 500);
}
