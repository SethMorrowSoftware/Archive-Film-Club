<?php
/**
 * Featured Sections API Endpoint
 *
 * GET  → featured sections
 * POST → update featured sections (admin only)
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

$jsonPath = base_path('featured-sections.json');

// The service needs a live connection. A dead DB must not 500 here: GET
// serves the JSON recovery file and POST has to reach the file_put_contents
// fallback below — which it never could while `new SettingsService()` threw
// before any of that code ran.
$settingsService = null;
try {
    $settingsService = new SettingsService();
} catch (Throwable $e) {
    error_log('[api/sections] database unavailable, using JSON fallback: ' . $e->getMessage());
}

if ($api->isGet()) {
    header('Cache-Control: public, max-age=300');
    if ($settingsService) {
        try {
            $api->data($settingsService->getFeaturedSections());
        } catch (Throwable $e) {
            error_log('[api/sections] read failed: ' . $e->getMessage());
        }
    }
    $fallback = ['sections' => []];
    if (is_readable($jsonPath)) {
        $decoded = json_decode((string)@file_get_contents($jsonPath), true);
        if (is_array($decoded) && isset($decoded['sections']) && is_array($decoded['sections'])) {
            $fallback['sections'] = $decoded['sections'];
        }
    }
    $api->data($fallback);
}

// POST
$api->requireCsrf();
$api->requireAdmin();
$body = $api->jsonBody();

if (!isset($body['sections']) || !is_array($body['sections'])) {
    $api->error('Invalid data structure', 400);
}

$sections = [];
foreach ($body['sections'] as $section) {
    if (!is_array($section) || !isset($section['id'], $section['title'])) {
        continue;
    }

    $sectionIdClean = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$section['id']);
    // Reject sections whose id is empty after sanitizing -- otherwise a
    // hostile/malformed payload would silently collapse multiple sections
    // into a single empty-id row via the unique key.
    if ($sectionIdClean === '') {
        continue;
    }

    $clean = [
        'id' => $sectionIdClean,
        'title' => ApiController::sanitizeText($section['title'], 255),
        'description' => isset($section['description']) ? ApiController::sanitizeText($section['description'], 1000) : '',
        'enabled' => ApiController::sanitizeBool($section['enabled'] ?? true),
        'videos' => [],
    ];

    if (isset($section['videos']) && is_array($section['videos'])) {
        foreach ($section['videos'] as $video) {
            if (!is_array($video) || empty($video['id'])) continue;
            $cleanVideo = [
                'id' => ApiController::sanitizeArchiveId($video['id']),
                'title' => ApiController::sanitizeText($video['title'] ?? '', 500),
                'creator' => ApiController::sanitizeText($video['creator'] ?? '', 255),
            ];
            if (isset($video['note'])) {
                $cleanVideo['note'] = ApiController::sanitizeText($video['note'], 500);
            }
            $clean['videos'][] = $cleanVideo;
        }
    }

    $clean['updated'] = date('c');
    $sections[] = $clean;
}

$output = [
    'sections' => $sections,
    'updated' => date('c'),
];

$dbSaveSuccess = false;
if ($settingsService) {
    try {
        $dbSaveSuccess = $settingsService->updateFeaturedSections($output);
    } catch (Throwable $e) {
        error_log('[api/sections] DB save failed: ' . $e->getMessage());
    }
}

if (!$dbSaveSuccess) {
    // DB save failed — write JSON as a recovery file. LOCK_EX guards
    // against concurrent admin saves writing a half-formed file.
    @file_put_contents($jsonPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($jsonPath, 0644);

    if (!file_exists($jsonPath)) {
        $api->error('Failed to save featured sections', 500);
    }
}

$api->ok([
    'message' => 'Featured sections saved successfully',
    'sections_count' => count($sections),
    'database' => $dbSaveSuccess,
]);
