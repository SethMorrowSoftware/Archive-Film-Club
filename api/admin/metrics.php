<?php
/**
 * Admin metrics + moderation API
 *
 * GET  ?action=overview
 * GET  ?action=series&metric=signups|comments|views|searches&days=N
 * GET  ?action=top-videos&days=N&limit=N
 * GET  ?action=top-searches&limit=N
 * GET  ?action=top-commenters&days=N&limit=N
 * GET  ?action=recent-signups&limit=N
 * GET  ?action=recent-comments&limit=N
 * GET  ?action=users&page=N&per_page=N&role=...&search=...
 * GET  ?action=comments-mod&filter=all|reported|hidden|recent&page=N
 *
 * POST { action: 'set-role',    user_id, role }      (admin only)
 * POST { action: 'moderate',    id, op }             (admin only)
 *      op: 'hide' | 'restore' | 'delete'
 * POST { action: 'resolve-reports', id }             (admin only)
 *
 * All endpoints require admin (role='admin' or 'editor').
 */

require_once __DIR__ . '/../../bootstrap.php';

$api = new ApiController();
$api->requireMethod(['GET', 'POST']);

if ($api->isPost()) {
    $api->requireCsrf();
}

$admin = $api->requireAdmin();
$metrics = new MetricsService();

// Email addresses are personal data: only a FULL admin sees them in the
// users list / recent signups. Editors (curation + moderation) get the
// rows without the email column.
$isFullAdmin = (($admin['role'] ?? '') === 'admin');

if ($api->isGet()) {
    header('Cache-Control: private, no-store');
    $action = $api->query('action', 'overview');

    switch ($action) {
        case 'overview':
            $api->ok($metrics->overview());

        case 'series':
            $metric = ApiController::sanitizeEnum(
                $api->query('metric', 'signups'),
                ['signups', 'comments', 'views', 'searches'],
                'signups'
            );
            $days = (int)$api->query('days', 30);
            $api->ok(['series' => $metrics->dailySeries($metric, $days), 'metric' => $metric, 'days' => $days]);

        case 'top-videos':
            $api->ok(['videos' => $metrics->topVideos(
                (int)$api->query('limit', 10),
                (int)$api->query('days', 30)
            )]);

        case 'top-searches':
            $api->ok(['searches' => $metrics->topSearches((int)$api->query('limit', 10))]);

        case 'top-commenters':
            $api->ok(['commenters' => $metrics->topCommenters(
                (int)$api->query('limit', 10),
                (int)$api->query('days', 30)
            )]);

        case 'recent-signups':
            $api->ok(['users' => $metrics->recentSignups((int)$api->query('limit', 10), $isFullAdmin)]);

        case 'recent-comments':
            $api->ok(['comments' => $metrics->recentComments((int)$api->query('limit', 10))]);

        case 'users':
            $api->ok($metrics->listUsers([
                'page' => (int)$api->query('page', 1),
                'per_page' => (int)$api->query('per_page', 25),
                'role' => (string)$api->query('role', 'all'),
                'search' => (string)$api->query('search', ''),
                'include_email' => $isFullAdmin,
            ]));

        case 'comments-mod':
            $api->ok($metrics->listCommentsForModeration([
                'page' => (int)$api->query('page', 1),
                'per_page' => (int)$api->query('per_page', 25),
                'filter' => (string)$api->query('filter', 'all'),
            ]));
    }

    $api->error('Invalid action', 400);
}

// POST actions
$body = $api->jsonBody();
$action = $body['action'] ?? '';

try {
    switch ($action) {
        case 'set-role': {
            // User-role management is ADMIN-ONLY. requireAdmin() also admits
            // 'editor' (so editors can moderate comments below), so this
            // action must be gated separately — otherwise an editor could
            // POST {role:'admin'} and promote themselves (the self-demote
            // guard below only blocks *lowering* your own role).
            if (($admin['role'] ?? '') !== 'admin') {
                $api->error('Only administrators can change user roles.', 403);
            }
            // Demoting yourself out of admin role would lock you out.
            $userId = (int)$api->required($body, 'user_id');
            $role = ApiController::sanitizeEnum(
                $body['role'] ?? '',
                ['admin', 'editor', 'viewer'],
                ''
            );
            if ($role === '') $api->error('Invalid role', 400);
            if ($userId === (int)$admin['id'] && $role !== 'admin') {
                $api->error("You can't demote your own admin role.", 400);
            }
            $metrics->setRole($userId, $role);
            $api->ok(['message' => 'Role updated']);
        }

        case 'moderate': {
            $id = (int)$api->required($body, 'id');
            $op = ApiController::sanitizeEnum(
                $body['op'] ?? '',
                ['hide', 'restore', 'delete'],
                ''
            );
            if ($op === '') $api->error('Invalid moderation op', 400);
            $commentService = new CommentService();
            $commentService->moderate($id, $op);
            $metrics->resolveReportsFor($id, (int)$admin['id']);
            $api->ok(['message' => 'Moderation applied']);
        }

        case 'resolve-reports': {
            $id = (int)$api->required($body, 'id');
            $metrics->resolveReportsFor($id, (int)$admin['id']);
            $api->ok(['message' => 'Reports resolved']);
        }

        default:
            $api->error('Invalid action', 400);
    }
} catch (RuntimeException $e) {
    $code = $e->getCode();
    $status = ($code === 401 || $code === 403) ? $code : 400;
    $api->error($e->getMessage(), $status);
}
