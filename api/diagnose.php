<?php
/**
 * Server Diagnostic Script
 *
 * Exposes infrastructure info (paths, DB name, extension list, etc.) so it
 * MUST be kept behind admin auth. DELETE THIS FILE once the site is stable
 * on the target host.
 *
 * Access: https://yourdomain.com/api/diagnose.php (admin login required)
 */

require_once __DIR__ . '/../bootstrap.php';

// Access control: ALWAYS admin-only. Previous versions of this file
// allowed access pre-install as a troubleshooting escape hatch, but on a
// fresh upload that meant anyone on the internet could enumerate the
// install path, DB name, and loaded extensions for a window between
// upload and lock-down. The .htaccess at the root also denies this file
// by default; this in-script gate is belt-and-suspenders.
$currentUser = null;
if (class_exists('UserAuthService')) {
    try { $currentUser = (new UserAuthService())->currentUser(); } catch (Throwable $e) {}
}
if (!$currentUser && class_exists('AdminAuthService')) {
    try { $currentUser = (new AdminAuthService())->validateSession(); } catch (Throwable $e) {}
}
// FULL admins only. Editors curate content; the install path, DB name and
// extension list are operator information.
$role = $currentUser['role'] ?? null;
if ($role !== 'admin') {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Forbidden</title><meta name="robots" content="noindex,nofollow"></head><body style="font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px">';
    echo '<h1>403 Forbidden</h1>';
    echo '<p>The server diagnostic page is restricted to full administrators.</p>';
    echo '<p><a href="../admin.php">Admin login</a></p>';
    echo '</body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Server Diagnostics - Archive Film Club</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #00d4ff; }
        h2 { color: #ffd700; margin-top: 30px; }
        .check { padding: 10px; margin: 10px 0; border-radius: 5px; }
        .pass { background: #1e4d2b; border-left: 4px solid #4caf50; }
        .fail { background: #4d1e1e; border-left: 4px solid #f44336; }
        .warn { background: #4d3d1e; border-left: 4px solid #ff9800; }
        code { background: #333; padding: 2px 6px; border-radius: 3px; font-size: 14px; }
        pre { background: #333; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .action { background: #1e3d4d; padding: 15px; margin: 15px 0; border-radius: 5px; border-left: 4px solid #00d4ff; }
    </style>
</head>
<body>
    <h1>Archive Film Club - Server Diagnostics</h1>
    <p>This script checks your server configuration for the caching system.</p>

    <h2>1. PHP Configuration</h2>
    <?php
    $phpVersion = phpversion();
    $phpOk = version_compare($phpVersion, '7.4', '>=');
    ?>
    <div class="check <?php echo $phpOk ? 'pass' : 'fail'; ?>">
        <strong>PHP Version:</strong> <?php echo $phpVersion; ?>
        <?php if (!$phpOk): ?><br>Requires PHP 7.4 or higher<?php endif; ?>
    </div>

    <?php
    $requiredExtensions = ['pdo', 'pdo_mysql', 'gd', 'json', 'fileinfo'];
    foreach ($requiredExtensions as $ext):
        $loaded = extension_loaded($ext);
    ?>
    <div class="check <?php echo $loaded ? 'pass' : 'fail'; ?>">
        <strong><?php echo strtoupper($ext); ?> Extension:</strong> <?php echo $loaded ? 'Loaded' : 'NOT LOADED'; ?>
    </div>
    <?php endforeach; ?>

    <h2>2. Directory Structure</h2>
    <?php
    $baseDir = dirname(__DIR__);
    $requiredDirs = [
        'api' => __DIR__,
        'db' => $baseDir . '/db',
        'cache' => $baseDir . '/cache',
        'services' => $baseDir . '/services',
        'thumbnails' => $baseDir . '/thumbnails',
    ];

    foreach ($requiredDirs as $name => $path):
        $exists = is_dir($path);
    ?>
    <div class="check <?php echo $exists ? 'pass' : 'fail'; ?>">
        <strong>/<?php echo htmlspecialchars($name, ENT_QUOTES); ?>/:</strong>
        <?php if ($exists): ?>
            Found at <code><?php echo htmlspecialchars($path, ENT_QUOTES); ?></code>
        <?php else: ?>
            NOT FOUND - Expected at <code><?php echo htmlspecialchars($path, ENT_QUOTES); ?></code>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <h2>3. Required PHP Files</h2>
    <?php
    $requiredFiles = [
        'db/Database.php',
        'db/config.php',
        'cache/CacheManager.php',
        'cache/ThumbnailCache.php',
        'services/LocalStorageService.php',
        'api/search.php',
        'api/cache.php',
        'api/thumbnail.php',
    ];

    foreach ($requiredFiles as $file):
        $fullPath = $baseDir . '/' . $file;
        $exists = file_exists($fullPath);
    ?>
    <div class="check <?php echo $exists ? 'pass' : 'fail'; ?>">
        <strong><?php echo $file; ?>:</strong> <?php echo $exists ? 'Found' : 'NOT FOUND'; ?>
    </div>
    <?php endforeach; ?>

    <h2>4. Environment Configuration</h2>
    <?php
    $envFile = $baseDir . '/.env';
    $envExists = file_exists($envFile);
    ?>
    <div class="check <?php echo $envExists ? 'pass' : 'fail'; ?>">
        <strong>.env File:</strong> <?php echo $envExists ? 'Found' : 'NOT FOUND'; ?>
        <?php if (!$envExists): ?>
        <br><small>Copy <code>.env.example</code> to <code>.env</code> and configure your database credentials</small>
        <?php endif; ?>
    </div>

    <?php if ($envExists): ?>
    <h2>5. Database Connection</h2>
    <?php
    try {
        require_once $baseDir . '/db/Database.php';
        $db = Database::getInstance();
        $config = $db->getConfig();

        // Test connection
        $result = $db->fetchOne("SELECT 1 as test");
        $connected = ($result && $result['test'] == 1);
        ?>
        <div class="check pass">
            <strong>Database Connection:</strong> SUCCESS
            <br><small>Connected to: <?php echo htmlspecialchars($config['database'] ?? '', ENT_QUOTES); ?></small>
        </div>

        <?php
        // Check tables
        $tables = ['search_cache', 'video_metadata_cache', 'thumbnail_cache', 'cache_queue'];
        foreach ($tables as $table):
            try {
                $exists = $db->fetchOne("SHOW TABLES LIKE ?", [$table]);
                $tableExists = !empty($exists);
            } catch (Exception $e) {
                $tableExists = false;
            }
        ?>
        <div class="check <?php echo $tableExists ? 'pass' : 'fail'; ?>">
            <strong>Table '<?php echo $table; ?>':</strong> <?php echo $tableExists ? 'Exists' : 'NOT FOUND - Run migrations'; ?>
        </div>
        <?php endforeach; ?>

    <?php
    } catch (Exception $e) {
        ?>
        <div class="check fail">
            <strong>Database Connection:</strong> FAILED
            <br><small>Error: <?php echo htmlspecialchars($e->getMessage()); ?></small>
        </div>
        <?php
    }
    endif;
    ?>

    <h2>6. Thumbnail Directory Permissions</h2>
    <?php
    $thumbDir = $baseDir . '/thumbnails';
    $thumbDirExists = is_dir($thumbDir);
    $thumbDirWritable = $thumbDirExists && is_writable($thumbDir);
    ?>
    <div class="check <?php echo $thumbDirWritable ? 'pass' : ($thumbDirExists ? 'warn' : 'fail'); ?>">
        <strong>Thumbnails Directory:</strong>
        <?php if (!$thumbDirExists): ?>
            NOT FOUND
        <?php elseif (!$thumbDirWritable): ?>
            EXISTS but NOT WRITABLE
        <?php else: ?>
            OK (writable)
        <?php endif; ?>
    </div>

    <?php if (!$thumbDirWritable && $thumbDirExists): ?>
    <div class="action">
        <strong>Action Required:</strong> Set permissions on thumbnails directory:
        <pre>chmod 755 <?php echo $thumbDir; ?></pre>
        Or via cPanel File Manager: Right-click thumbnails folder → Change Permissions → Set to 755
    </div>
    <?php endif; ?>

    <h2>7. Test Thumbnail Download</h2>
    <?php
    // Check allow_url_fopen
    $allowUrlFopen = ini_get('allow_url_fopen');
    $curlAvailable = function_exists('curl_init');
    ?>
    <div class="check <?php echo $allowUrlFopen ? 'pass' : 'warn'; ?>">
        <strong>allow_url_fopen:</strong> <?php echo $allowUrlFopen ? 'Enabled' : 'DISABLED'; ?>
    </div>
    <div class="check <?php echo $curlAvailable ? 'pass' : 'fail'; ?>">
        <strong>cURL Extension:</strong> <?php echo $curlAvailable ? 'Available' : 'NOT AVAILABLE'; ?>
    </div>

    <?php
    if ($thumbDirWritable):
        $testUrl = 'https://archive.org/services/img/night_of_the_living_dead';
        $testData = false;
        $downloadMethod = '';
        $downloadError = '';

        // Try cURL first (more reliable on shared hosting)
        if ($curlAvailable) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $testUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)',
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $testData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($testData !== false && strlen($testData) > 100 && $httpCode === 200) {
                $downloadMethod = 'cURL';
            } else {
                $testData = false;
                $downloadError = "cURL: HTTP $httpCode" . ($curlError ? " - $curlError" : '');
            }
        }

        // Fallback to file_get_contents if cURL failed
        if ($testData === false && $allowUrlFopen) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'user_agent' => 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)',
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $testData = @file_get_contents($testUrl, false, $context);
            if ($testData !== false && strlen($testData) > 100) {
                $downloadMethod = 'file_get_contents';
            } else {
                $testData = false;
                $downloadError .= ($downloadError ? '; ' : '') . 'file_get_contents failed';
                if (isset($http_response_header)) {
                    $downloadError .= ' (' . $http_response_header[0] . ')';
                }
            }
        }

        $downloadOk = ($testData !== false && strlen($testData) > 100);
    ?>
    <div class="check <?php echo $downloadOk ? 'pass' : 'fail'; ?>">
        <strong>Download from Archive.org:</strong>
        <?php if ($downloadOk): ?>
            SUCCESS (<?php echo strlen($testData); ?> bytes via <?php echo $downloadMethod; ?>)
        <?php else: ?>
            FAILED<?php echo $downloadError ? " - $downloadError" : ''; ?>
        <?php endif; ?>
    </div>

    <?php if (!$downloadOk && !$curlAvailable && !$allowUrlFopen): ?>
    <div class="action">
        <strong>Problem:</strong> Your server cannot make outbound HTTP requests.
        <ul>
            <li>cURL extension is not available</li>
            <li>allow_url_fopen is disabled</li>
        </ul>
        <strong>Solution:</strong> Contact your hosting provider to enable cURL or allow_url_fopen.<br>
        In cPanel, go to <strong>Select PHP Version → Extensions</strong> and enable <strong>curl</strong>.
    </div>
    <?php endif; ?>

    <?php if ($downloadOk):
        $testPath = $thumbDir . '/test_diagnostic.jpg';
        $image = @imagecreatefromstring($testData);
        $saveOk = false;
        $saveError = '';
        if ($image) {
            $saveOk = @imagejpeg($image, $testPath, 85);
            if (!$saveOk) {
                $saveError = error_get_last()['message'] ?? 'Unknown error';
            }
            imagedestroy($image);
            if ($saveOk) {
                @unlink($testPath); // Clean up
            }
        } else {
            $saveError = 'Could not create image from downloaded data';
        }
    ?>
    <div class="check <?php echo $saveOk ? 'pass' : 'fail'; ?>">
        <strong>Save Thumbnail File:</strong>
        <?php echo $saveOk ? 'SUCCESS' : "FAILED - $saveError"; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <h2>8. Current Paths</h2>
    <pre>
Document Root: <?php echo htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? '', ENT_QUOTES); ?>

Script Path: <?php echo htmlspecialchars(__FILE__, ENT_QUOTES); ?>

Base Directory: <?php echo htmlspecialchars($baseDir, ENT_QUOTES); ?>

Thumbnails Path: <?php echo htmlspecialchars($thumbDir, ENT_QUOTES); ?>
    </pre>

    <h2>Summary</h2>
    <div class="action">
        <strong>If you see 404 errors for /api/* endpoints:</strong>
        <ol>
            <li>Verify the <code>api/</code> folder was uploaded to your server</li>
            <li>Check that files are in the correct location relative to your domain root</li>
            <li>For <code>https://sethmorrow.com/api/search.php</code>, files should be at:<br>
                <code>public_html/api/search.php</code> (or similar based on your cPanel setup)</li>
            <li>Ensure all PHP files and folders (api, db, cache, services) are uploaded</li>
        </ol>
    </div>

    <div class="action">
        <strong style="color: #f44336;">DELETE THIS FILE</strong> after diagnosing - it exposes server information!
    </div>

    <p style="margin-top: 40px; color: #888; font-size: 12px;">
        Generated: <?php echo date('Y-m-d H:i:s'); ?> | PHP <?php echo phpversion(); ?>
    </p>
</body>
</html>
