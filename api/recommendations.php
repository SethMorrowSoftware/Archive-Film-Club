<?php
/**
 * Recommendations API Endpoint
 *
 * GET  → staff picks
 * POST → update staff picks (admin only)
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

$jsonPath = base_path('recommendations.json');

// The service needs a live connection. A dead DB must not 500 here: GET
// serves the JSON recovery file and POST has to reach the file_put_contents
// fallback below — which it never could while `new SettingsService()` threw
// before any of that code ran.
$settingsService = null;
try {
    $settingsService = new SettingsService();
} catch (Throwable $e) {
    error_log('[api/recommendations] database unavailable, using JSON fallback: ' . $e->getMessage());
}

if ($api->isGet()) {
    header('Cache-Control: public, max-age=300');
    if ($settingsService) {
        try {
            $api->data($settingsService->getRecommendations());
        } catch (Throwable $e) {
            error_log('[api/recommendations] read failed: ' . $e->getMessage());
        }
    }
    $fallback = ['enabled' => true, 'title' => 'Staff Picks', 'videos' => []];
    if (is_readable($jsonPath)) {
        $decoded = json_decode((string)@file_get_contents($jsonPath), true);
        if (is_array($decoded)) {
            $fallback['enabled'] = ApiController::sanitizeBool($decoded['enabled'] ?? true);
            $fallback['title'] = ApiController::sanitizeText($decoded['title'] ?? 'Staff Picks', 50);
            $fallback['videos'] = (isset($decoded['videos']) && is_array($decoded['videos'])) ? $decoded['videos'] : [];
        }
    }
    $api->data($fallback);
}

// POST
$api->requireCsrf();
$api->requireAdmin();
$body = $api->jsonBody();

if (!isset($body['videos']) || !is_array($body['videos'])) {
    $api->error('Missing videos array', 400);
}

$videos = [];
foreach ($body['videos'] as $video) {
    if (!is_array($video) || empty($video['id']) || !is_string($video['id'])) {
        continue;
    }
    $videos[] = [
        'id' => ApiController::sanitizeArchiveId($video['id']),
        'title' => ApiController::sanitizeText($video['title'] ?? '', 200),
        'creator' => ApiController::sanitizeText($video['creator'] ?? '', 100),
    ];
}

$recommendations = [
    'enabled' => ApiController::sanitizeBool($body['enabled'] ?? true),
    'title' => ApiController::sanitizeText($body['title'] ?? 'Staff Picks', 50),
    'videos' => $videos,
    'updated' => date('c'),
];

$dbSaveSuccess = false;
if ($settingsService) {
    try {
        $dbSaveSuccess = $settingsService->updateRecommendations($recommendations);
    } catch (Throwable $e) {
        error_log('[api/recommendations] DB save failed: ' . $e->getMessage());
    }
}

if (!$dbSaveSuccess) {
    // DB save failed — write JSON as a recovery file. LOCK_EX guards
    // against concurrent admin saves writing a half-formed file.
    @file_put_contents($jsonPath, json_encode($recommendations, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($jsonPath, 0644);

    if (!file_exists($jsonPath)) {
        $api->error('Failed to save recommendations', 500);
    }
}

$api->ok([
    'message' => 'Saved ' . count($videos) . ' videos',
    'data' => $recommendations,
    'database' => $dbSaveSuccess,
]);
