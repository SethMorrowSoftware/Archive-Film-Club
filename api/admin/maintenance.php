<?php
/**
 * Admin database-maintenance API
 *
 * GET  ?action=status                 → DB snapshot (tables, sizes, limits)
 *
 * POST { action: 'backup', include_caches }          → streams a .sql download
 * POST { action: 'download-thumbnails' }             → streams a .zip download
 * POST { action: 'refresh-schema' }                  → run pending migrations
 * POST { action: 'refresh-cache' }                   → flush + re-warm caches
 * POST { action: 'refresh-metadata', limit }         → re-fetch stale metadata
 * POST { action: 'content-reset', confirm }          → wipe community + cache data
 * POST (multipart) action=restore, backup=<file>,     → restore DB from an
 *      confirm[, skip_safety]                            uploaded .sql/.sql.gz backup
 *
 * ALL actions require a FULL admin (role === 'admin'). requireAdmin() also
 * admits 'editor' for comment moderation, so this file gates strictly on top
 * of it — the database surface is far too powerful for the curation role.
 * Destructive actions additionally require a typed confirmation.
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

if ($api->isPost()) {
    $api->requireCsrf();
}

$admin = $api->requireAdmin();
if (($admin['role'] ?? '') !== 'admin') {
    $api->error('This area manages the database and is restricted to full administrators.', 403);
}
// The break-glass (ADMIN_PASSWORD) session exists precisely because the
// database is unreachable — there is nothing here for it to maintain, and a
// synthetic id-0 admin must never drive backup/restore/reset. Live DB only.
if (!empty($admin['break_glass'])) {
    $api->error('Database maintenance requires a real administrator account on a live database.', 403);
}

/**
 * One audit line per maintenance action. There is no dedicated audit table
 * yet, so this goes to the PHP error log — enough to answer "who reset the
 * site and when". (A persistent admin_actions table is a sensible follow-up.)
 */
$audit = function (string $action, array $extra = []) use ($admin) {
    $who = ($admin['username'] ?? ('id#' . ($admin['id'] ?? '?')));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    error_log('[admin-maintenance] user=' . $who . ' ip=' . $ip
        . ' action=' . $action
        . ($extra ? ' ' . json_encode($extra) : ''));
};

// Friendly messages for PHP's upload error codes (file too large, etc.).
$uploadError = function (int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'The backup file is larger than this server allows for uploads '
                . '(upload_max_filesize / post_max_size). Use a "minus caches" backup or raise the limit.';
        case UPLOAD_ERR_PARTIAL:
            return 'The upload was interrupted — please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No backup file was selected.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not store the uploaded file (temp directory issue).';
        default:
            return 'The backup upload failed (error code ' . $code . ').';
    }
};

$svc = new MaintenanceService();

// ---- GET: status (read-only) ----
if ($api->isGet()) {
    $action = (string)$api->query('action', 'status');
    if ($action === 'status') {
        header('Cache-Control: private, no-store');
        $api->ok(['status' => $svc->databaseStatus()]);
    }
    $api->error('Invalid action', 400);
}

// ---- POST (multipart): restore from an uploaded backup file ----
// Handled before jsonBody() because a file upload arrives as multipart, not
// JSON. CSRF still rides in the X-CSRF-Token header (already verified above).
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'multipart/form-data') !== false) {
    if (($_POST['action'] ?? '') !== 'restore') {
        $api->error('Invalid action', 400);
    }
    try {
        if (!isset($_FILES['backup'])) {
            $api->error('No backup file uploaded.', 400);
        }
        $upload = $_FILES['backup'];
        $err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $api->error($uploadError($err), 400);
        }

        // Type-to-confirm (site name) — same contract as content-reset.
        $expected = 'Archive Film Club';
        try {
            $settings = (new SettingsService())->getSettings();
            if (!empty($settings['siteName'])) {
                $expected = (string)$settings['siteName'];
            }
        } catch (Throwable $e) { /* default name */ }

        $confirm = trim((string)($_POST['confirm'] ?? ''));
        if (strcasecmp($confirm, trim($expected)) !== 0) {
            $api->error('Confirmation text did not match the site name. Type "' . $expected . '" to confirm.', 400);
        }

        $skipSafety = ApiController::sanitizeBool($_POST['skip_safety'] ?? false);
        $audit('restore', [
            'file' => $upload['name'] ?? '?',
            'size' => $upload['size'] ?? 0,
            'skip_safety' => $skipSafety,
        ]);

        $api->ok(['result' => $svc->restoreFromUpload($upload, $skipSafety)]);
    } catch (RuntimeException $e) {
        $api->error($e->getMessage(), 400);
    } catch (Throwable $e) {
        error_log('[api/admin/maintenance] restore: ' . $e->getMessage());
        $api->error('Internal error during restore.', 500);
    }
}

// ---- POST (JSON): actions ----
$body = $api->jsonBody();
$action = $body['action'] ?? '';

try {
    switch ($action) {

        case 'backup': {
            $includeCaches = array_key_exists('include_caches', $body)
                ? ApiController::sanitizeBool($body['include_caches'])
                : true;

            // Serialize against restore/reset BEFORE any download headers go
            // out, so a busy lock still returns a clean JSON error. A dump
            // taken mid-restore would capture a half-restored database.
            if (!$svc->acquireMaintenanceLock()) {
                $api->error('Another maintenance operation is already running — try again in a moment.', 409);
            }

            $audit('backup', ['include_caches' => $includeCaches]);

            $fname = 'afc-backup-' . gmdate('Ymd-His')
                . ($includeCaches ? '' : '-nocache') . '.sql';

            // Override the JSON content-type the controller set in its ctor.
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');

            try {
                $svc->streamBackup($includeCaches);
            } finally {
                $svc->releaseMaintenanceLock();
            }
            exit;
        }

        case 'download-thumbnails': {
            $path = $svc->buildThumbnailsZip();
            if ($path === null) {
                // No headers sent yet → safe to return a JSON error.
                $api->error('No cached thumbnails to download (or the zip extension is unavailable).', 400);
            }
            $audit('download-thumbnails');

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="afc-thumbnails-' . gmdate('Ymd-His') . '.zip"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');

            readfile($path);
            @unlink($path);
            exit;
        }

        case 'refresh-schema': {
            $audit('refresh-schema');
            $api->ok(['result' => $svc->runMigrations()]);
        }

        case 'refresh-cache': {
            $audit('refresh-cache');
            $api->ok(['result' => $svc->refreshCaches()]);
        }

        case 'refresh-metadata': {
            $limit = (int)($body['limit'] ?? 25);
            $limit = max(1, min(200, $limit));
            $audit('refresh-metadata', ['limit' => $limit]);
            $api->ok(['result' => $svc->refetchMetadata($limit)]);
        }

        case 'content-reset': {
            // Type-to-confirm: the supplied phrase must match the site name.
            $expected = 'Archive Film Club';
            try {
                $settings = (new SettingsService())->getSettings();
                if (!empty($settings['siteName'])) {
                    $expected = (string)$settings['siteName'];
                }
            } catch (Throwable $e) { /* fall back to default name */ }

            $confirm = trim((string)($body['confirm'] ?? ''));
            if (strcasecmp($confirm, trim($expected)) !== 0) {
                $api->error('Confirmation text did not match the site name. Type "' . $expected . '" to confirm.', 400);
            }

            $audit('content-reset');
            $api->ok(['result' => $svc->contentReset()]);
        }

        default:
            $api->error('Invalid action', 400);
    }
} catch (RuntimeException $e) {
    $api->error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[api/admin/maintenance] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $api->error('Internal error while performing maintenance action.', 500);
}
