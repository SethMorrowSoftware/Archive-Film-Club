<?php
/**
 * Cache Cleanup Cron Job
 *
 * Run this hourly to clean up expired cache entries
 * cPanel: Add to Cron Jobs with: php /home/yourusername/public_html/videos/cron/cache_cleanup.php
 */

// Prevent web access - CLI only for security. Some cron daemons invoke PHP
// under a cgi-fcgi SAPI rather than 'cli'; defined('STDIN') is true for any
// real command-line invocation, so accept that too (a web request never has it).
if (php_sapi_name() !== 'cli' && !defined('STDIN')) {
    http_response_code(403);
    die('This script must be run from the command line');
}

require_once __DIR__ . '/../cache/CacheManager.php';

echo "Cache Cleanup Started: " . date('Y-m-d H:i:s') . "\n";

try {
    $cacheManager = new CacheManager();
    $deleted = $cacheManager->cleanExpiredCache();

    echo "Cleanup Results:\n";
    echo "  - Search cache entries: {$deleted['search']}\n";
    echo "  - Metadata cache entries: {$deleted['metadata']}\n";
    echo "  - Thumbnail files: {$deleted['thumbnails']}\n";

    // Guest reaper. UserContext creates a `users` row for every visitor
    // session (so guests can bookmark / keep watch progress) and nothing
    // ever removed them, so the table grew by one row per browser session
    // forever. Drop guests idle for 30+ days; their bookmarks, history,
    // search history and tokens go with them via ON DELETE CASCADE. Batched
    // so a first run on a big table doesn't hold one giant lock. Bounded by
    // an upper batch count so a runaway table can't pin the cron open.
    $guestRetentionDays = 30;
    $batchSize = 1000;
    $maxBatches = 200;
    $reaped = 0;
    try {
        $db = Database::getInstance();
        for ($i = 0; $i < $maxBatches; $i++) {
            // LIMIT is interpolated (never bound — see MaintenanceService's
            // note on native prepares) and both values are trusted ints.
            $stmt = $db->query(
                "DELETE FROM users
                 WHERE is_guest = 1
                   AND last_seen < DATE_SUB(NOW(), INTERVAL " . (int)$guestRetentionDays . " DAY)
                 LIMIT " . (int)$batchSize
            );
            $n = $stmt->rowCount();
            $reaped += $n;
            if ($n < $batchSize) {
                break;
            }
        }
        echo "  - Idle guest users (>{$guestRetentionDays}d): {$reaped}\n";
    } catch (Throwable $e) {
        // users.is_guest arrives with migration 003; before that there is
        // nothing to reap. Log and carry on with the stats below.
        echo "  - Idle guest users: skipped (" . $e->getMessage() . ")\n";
        error_log('[cache_cleanup] guest reaper: ' . $e->getMessage());
    }

    // Get current stats
    $stats = $cacheManager->getStats();
    echo "\nCurrent Cache Stats:\n";
    echo "  - Active search entries: {$stats['search']['entries']}\n";
    echo "  - Active metadata entries: {$stats['metadata']['entries']}\n";
    echo "  - Cached thumbnails: {$stats['thumbnails']['entries']}\n";

    echo "\nCache Cleanup Completed: " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
