<?php
/**
 * Archive Film Club - Admin Panel (thin entrypoint).
 *
 * Historically this file was a ~3,300-line monolith that owned auth,
 * data loading, all CSS, all JS, and every view. As part of the Phase 1
 * modular refactor it is now a skeleton that:
 *
 *   1. Delegates auth + data loading to admin/controllers/AdminBootstrap.php
 *   2. Renders admin/views/login.php OR admin/views/layout.php
 *   3. Loads static admin/assets/admin.css + admin/assets/admin.js
 *
 * All UI state that previously lived in inline <?= json_encode(...) ?>
 * calls is now written once into window.ADMIN_BOOTSTRAP, which admin.js
 * hydrates on load.
 */

require_once __DIR__ . '/admin/controllers/AdminBootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <?php include __DIR__ . '/partials/head-common.php'; ?>
    <title>Admin Panel - <?= htmlspecialchars($site_settings['siteName'] ?? 'Archive Film Club') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin/assets/admin.css">
</head>
<body>
    <?php if (!$is_logged_in): ?>
        <?php include __DIR__ . '/admin/views/login.php'; ?>
    <?php else: ?>
        <?php include __DIR__ . '/admin/views/layout.php'; ?>

        <?php
        // JSON_HEX_* so a stored value containing `</script>` (a staff-pick
        // title, the tagline) can't break out of this block — same flags
        // index.php uses for its siteSettingsConfig embed.
        $adminJsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        ?>
        <script>
            window.ADMIN_BOOTSTRAP = {
                recommendations: <?= json_encode($current_recommendations, $adminJsonFlags) ?>,
                siteSettings: <?= json_encode($site_settings, $adminJsonFlags) ?>,
                featuredSections: <?= json_encode($featured_sections, $adminJsonFlags) ?>
            };
        </script>
        <script src="admin/assets/admin.js"></script>
        <script src="admin/assets/admin-metrics.js"></script>
        <?php if (!empty($canMaintain)): ?>
        <script src="admin/assets/admin-maintenance.js"></script>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
