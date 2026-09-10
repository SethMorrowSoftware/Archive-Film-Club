<?php
/**
 * Thumbnail Cache - Downloads and caches Archive.org thumbnails locally
 */

require_once __DIR__ . '/CacheManager.php';
require_once __DIR__ . '/../db/Database.php';

class ThumbnailCache {
    private $cacheManager;
    private $thumbnailDir;

    // Thumbnail settings
    const MAX_WIDTH = 480;
    const QUALITY = 85;

    public function __construct() {
        $this->cacheManager = new CacheManager();
        $config = Database::getInstance()->getConfig();
        $this->thumbnailDir = $config['paths']['thumbnails'] ?? dirname(__DIR__) . '/thumbnails';

        // Normalize the path if possible
        $realDir = realpath($this->thumbnailDir);
        if ($realDir !== false) {
            $this->thumbnailDir = $realDir;
        }

        // Ensure thumbnail directory exists
        if (!is_dir($this->thumbnailDir)) {
            @mkdir($this->thumbnailDir, 0755, true);
        }
    }

    /**
     * Get the local path for a cached thumbnail
     * Returns null if not cached
     */
    public function getPath(string $archiveId): ?string {
        return $this->cacheManager->getThumbnailPath($archiveId);
    }

    /**
     * Get URL to serve the cached thumbnail
     */
    public function getUrl(string $archiveId): ?string {
        $path = $this->getPath($archiveId);
        if ($path && file_exists($path)) {
            // Convert file path to URL
            return str_replace(__DIR__ . '/..', '', $path);
        }
        return null;
    }

    /**
     * Download and cache a thumbnail
     */
    public function cache(string $archiveId): ?string {
        // Check if already cached
        $existing = $this->getPath($archiveId);
        if ($existing) {
            return $existing;
        }

        // Download from Archive.org
        $sourceUrl = "https://archive.org/services/img/{$archiveId}";
        $imageData = $this->downloadImage($sourceUrl);

        if (!$imageData) {
            return null;
        }

        // Process and save the image
        $localPath = $this->processAndSave($archiveId, $imageData);

        if ($localPath) {
            // Get image info
            $imageInfo = @getimagesize($localPath);
            $info = [
                'size' => filesize($localPath),
                'width' => $imageInfo[0] ?? 0,
                'height' => $imageInfo[1] ?? 0,
                'mime' => $imageInfo['mime'] ?? 'image/jpeg',
            ];

            // Store in database
            $this->cacheManager->cacheThumbnail($archiveId, $localPath, $sourceUrl, $info);
        }

        return $localPath;
    }

    /**
     * Download image from URL (tries cURL first, then file_get_contents)
     */
    private function downloadImage(string $url): ?string {
        $data = null;

        // Upstream responses bigger than this are not thumbnails. The cap
        // bounds both the buffered download and — more importantly — the GD
        // decode in processAndSave(), which is the expensive step for an
        // oversized or crafted image. Same guards as api/thumbnail.php.
        $maxBytes = 10 * 1024 * 1024;

        // Try cURL first (more reliable on shared hosting)
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_MAXFILESIZE => $maxBytes, // honored when Content-Length is sent
            ]);
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($data === false || $httpCode !== 200) {
                $data = null;
            }
        }

        // Fallback to file_get_contents if cURL failed
        if ($data === null && ini_get('allow_url_fopen')) {
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

            // maxlen reads one byte past the cap so the size check below can
            // tell "exactly at the cap" from "truncated".
            $data = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        }

        if ($data === null || $data === false || strlen($data) < 100) {
            return null;
        }
        if (strlen($data) > $maxBytes) {
            return null; // too large to be a thumbnail — don't hand it to GD
        }

        // Verify it's an image
        if (!class_exists('finfo')) {
            return null; // fileinfo extension not available
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data);

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            return null;
        }

        return $data;
    }

    /**
     * Process and save the image
     */
    private function processAndSave(string $archiveId, string $imageData): ?string {
        // Create image from data
        $image = @imagecreatefromstring($imageData);

        if (!$image) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Resize if too large
        if ($width > self::MAX_WIDTH) {
            $newWidth = self::MAX_WIDTH;
            $newHeight = (int)(($newWidth / $width) * $height);

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            // Preserve transparency for PNG
            imagealphablending($resized, false);
            imagesavealpha($resized, true);

            imagecopyresampled(
                $resized, $image,
                0, 0, 0, 0,
                $newWidth, $newHeight, $width, $height
            );

            imagedestroy($image);
            $image = $resized;
        }

        // Generate safe filename
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $archiveId);
        $localPath = $this->thumbnailDir . '/' . $safeId . '.jpg';

        // Save as JPEG
        $success = imagejpeg($image, $localPath, self::QUALITY);
        imagedestroy($image);

        return $success ? $localPath : null;
    }

    /**
     * Serve a thumbnail (with caching headers)
     */
    public function serve(string $archiveId): void {
        // Try to get cached thumbnail
        $localPath = $this->getPath($archiveId);

        // If not cached, try to cache it
        if (!$localPath) {
            $localPath = $this->cache($archiveId);
        }

        if ($localPath && file_exists($localPath)) {
            // Serve the local file
            $this->serveFile($localPath);
        } else {
            // Redirect to Archive.org
            header("Location: https://archive.org/services/img/{$archiveId}", true, 302);
            exit;
        }
    }

    /**
     * Serve a file with proper headers
     */
    private function serveFile(string $path): void {
        $mime = mime_content_type($path);
        $size = filesize($path);
        $mtime = filemtime($path);
        $etag = md5($path . $mtime);

        // Check for If-None-Match header
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        // Send headers
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Cache-Control: public, max-age=604800'); // 7 days
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        // Output file
        readfile($path);
        exit;
    }

    /**
     * Delete a cached thumbnail
     */
    public function delete(string $archiveId): bool {
        $path = $this->getPath($archiveId);

        if ($path && file_exists($path)) {
            // @-suppress: a permission error on the file shouldn't emit a
            // warning or block clearing the DB row below.
            @unlink($path);
        }

        $db = Database::getInstance();
        $db->delete('thumbnail_cache', 'archive_id = ?', [$archiveId]);

        return true;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array {
        $db = Database::getInstance();

        $stats = $db->fetchOne(
            "SELECT
                COUNT(*) as count,
                SUM(file_size) as total_size,
                SUM(access_count) as total_accesses
             FROM thumbnail_cache"
        );

        // Get actual disk usage
        $diskUsage = 0;
        if (is_dir($this->thumbnailDir)) {
            $files = glob($this->thumbnailDir . '/*.jpg');
            foreach ($files as $file) {
                $diskUsage += filesize($file);
            }
        }

        return [
            'cached_count' => (int)($stats['count'] ?? 0),
            'db_size_bytes' => (int)($stats['total_size'] ?? 0),
            'disk_size_bytes' => $diskUsage,
            'total_accesses' => (int)($stats['total_accesses'] ?? 0),
        ];
    }
}
