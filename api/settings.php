<?php
/**
 * Settings API Endpoint
 *
 * GET  → public site settings (projected through the allow-list below)
 * POST → update settings (full admin only; partial updates allowed)
 *
 * Validation rules (previously in save-settings.php) live here so there's
 * exactly one path for admin settings writes.
 */

require_once __DIR__ . '/../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

// Allow-list of settings with validation rules.
//
// This doubles as the PUBLIC projection for GET: the site_settings table also
// holds operational knobs (cacheMetadataPermanently, refreshStaleAfterDays,
// thumbnailRetentionDays, maxThumbnailCacheMB, backgroundCacheEnabled, ...)
// that only server-side code reads. Anything not listed here never leaves
// the server. The keys the front end actually consumes (app.js / player.js:
// siteName, showCreator/showDate/showDownloadCount, enableBookmarks,
// defaultCollection/defaultSort, cardStyle, theme + colors) are all here.
$schema = [
    'siteName' => ['type' => 'string', 'default' => 'Archive Film Club', 'maxLength' => 100],
    'tagline' => ['type' => 'string', 'default' => 'Discover classic films from Archive.org', 'maxLength' => 200],
    'brandColor' => ['type' => 'color', 'default' => '#ff0000'],
    'accentColor' => ['type' => 'color', 'default' => '#065fd4'],
    'defaultTheme' => ['type' => 'enum', 'default' => 'dark', 'values' => ['dark', 'light', 'system']],
    'enableThemeToggle' => ['type' => 'bool', 'default' => true],
    'headerStyle' => ['type' => 'enum', 'default' => 'default', 'values' => ['default', 'minimal', 'centered']],
    'cardStyle' => ['type' => 'enum', 'default' => 'modern', 'values' => ['modern', 'classic', 'compact']],
    'showDownloadCount' => ['type' => 'bool', 'default' => true],
    'showCreator' => ['type' => 'bool', 'default' => true],
    'showDate' => ['type' => 'bool', 'default' => true],
    'enableBookmarks' => ['type' => 'bool', 'default' => true],
    'enableWatchHistory' => ['type' => 'bool', 'default' => true],
    'defaultCollection' => ['type' => 'string', 'default' => 'all_videos', 'maxLength' => 50],
    'defaultSort' => ['type' => 'enum', 'default' => 'downloads', 'values' => ['downloads', 'date', 'title', 'relevance', 'creator']],
];

/** Coerce one value through its schema rule (shared by GET projection and POST). */
$sanitize = function (array $rule, $value) {
    switch ($rule['type']) {
        case 'string':
            return ApiController::sanitizeText($value, $rule['maxLength'] ?? 200);
        case 'color':
            return ApiController::sanitizeHexColor($value, $rule['default']);
        case 'bool':
            return ApiController::sanitizeBool($value);
        case 'enum':
            return ApiController::sanitizeEnum($value, $rule['values'], $rule['default']);
        default:
            return $rule['default'];
    }
};

// JSON recovery file. The DB is the source of truth; this file is only
// written when the DB write fails and only read when the DB can't be reached.
$jsonPath = base_path('site-settings.json');
$readJsonFallback = function () use ($jsonPath): array {
    if (!is_readable($jsonPath)) return [];
    $decoded = json_decode((string)@file_get_contents($jsonPath), true);
    return is_array($decoded) ? $decoded : [];
};

// The service needs a live connection. A dead DB must NOT 500 here: GET
// serves the recovery file / defaults, and POST has to reach the
// file_put_contents fallback below — which it never could while
// `new SettingsService()` threw before any of that code ran.
$settingsService = null;
try {
    $settingsService = new SettingsService();
} catch (Throwable $e) {
    error_log('[api/settings] database unavailable, using JSON fallback: ' . $e->getMessage());
}

if ($api->isGet()) {
    // Settings are public read but vary per install; short TTL so updates
    // are visible quickly. No `private` here -- settings are not user-specific.
    header('Cache-Control: public, max-age=300');

    $all = null;
    if ($settingsService) {
        try {
            $all = $settingsService->getSettings();
        } catch (Throwable $e) {
            error_log('[api/settings] read failed: ' . $e->getMessage());
        }
    }
    if ($all === null) {
        $all = $readJsonFallback();
    }

    $public = [];
    foreach ($schema as $key => $rule) {
        $public[$key] = array_key_exists($key, $all) ? $sanitize($rule, $all[$key]) : $rule['default'];
    }
    if (isset($all['updated']) && is_string($all['updated'])) {
        $public['updated'] = $all['updated'];
    }
    $api->data($public);
}

// POST
$api->requireCsrf();
$admin = $api->requireAdmin();
// Site-wide settings (branding, defaults, feature toggles) are a full-admin
// concern. requireAdmin() also admits 'editor' so that role can curate staff
// picks / sections; it must not be able to rebrand the site.
if (($admin['role'] ?? '') !== 'admin') {
    $api->error('Only administrators can change site settings.', 403);
}
$body = $api->jsonBody();

// Partial update: only keys present in the body are validated and written.
// Previously every omitted key was silently reset to its default, so any
// client that posted a subset (or a future admin panel that saves one
// section at a time) wiped the rest.
$clean = [];
foreach ($schema as $key => $rule) {
    if (!array_key_exists($key, $body)) {
        continue;
    }
    $clean[$key] = $sanitize($rule, $body[$key]);
}
if (empty($clean)) {
    $api->error('No recognised settings in request body', 400);
}
$clean['updated'] = date('c');

// Persist to database. The DB is the source of truth; JSON is only used
// as a recovery file when the DB write fails (e.g., transient connection
// drop or schema not yet migrated on a fresh install).
$dbSaveSuccess = false;
if ($settingsService) {
    try {
        $dbSaveSuccess = $settingsService->updateSettings($clean);
    } catch (Throwable $e) {
        error_log('[api/settings] DB save failed: ' . $e->getMessage());
    }
}

if (!$dbSaveSuccess) {
    // DB save failed — write JSON as a recovery file so the admin's edits
    // aren't lost. Merge over whatever the file already holds so a partial
    // POST doesn't drop keys it didn't mention. LOCK_EX guards against
    // concurrent admin saves.
    $merged = array_merge($readJsonFallback(), $clean);
    @file_put_contents($jsonPath, json_encode($merged, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($jsonPath, 0644);

    if (!file_exists($jsonPath)) {
        $api->error('Failed to save settings', 500);
    }
}

$api->ok([
    'message' => 'Settings saved successfully',
    'data' => $clean,
    'database' => $dbSaveSuccess,
]);
