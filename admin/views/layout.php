<?php
/**
 * Admin layout — the authenticated view shell.
 *
 * Expected globals (populated by AdminBootstrap.php):
 *   $useDatabase, $admin_user, $site_settings,
 *   $current_recommendations, $recommendations_data, $featured_sections
 */
?>
    <!-- Admin Panel -->
    <div class="admin-wrapper">
        <!-- Sidebar Overlay (mobile) -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

        <?php include __DIR__ . '/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="admin-main">
            <?php include __DIR__ . '/header.php'; ?>

            <div class="admin-content">
                <!-- Dashboard Panel -->
                <div class="panel active" id="panel-dashboard" role="tabpanel" aria-labelledby="tab-dashboard">
                    <?php include __DIR__ . '/panels/dashboard.php'; ?>
                </div>

                <!-- Staff Picks Panel -->
                <div class="panel" id="panel-staff-picks" role="tabpanel" aria-labelledby="tab-staff-picks">
                    <?php include __DIR__ . '/panels/staff-picks.php'; ?>
                </div>

                <!-- Site Settings Panel -->
                <div class="panel" id="panel-site-settings" role="tabpanel" aria-labelledby="tab-site-settings">
                    <?php include __DIR__ . '/panels/site-settings.php'; ?>
                </div>

                <!-- Appearance Panel -->
                <div class="panel" id="panel-appearance" role="tabpanel" aria-labelledby="tab-appearance">
                    <?php include __DIR__ . '/panels/appearance.php'; ?>
                </div>

                <!-- Display Options Panel -->
                <div class="panel" id="panel-display" role="tabpanel" aria-labelledby="tab-display">
                    <?php include __DIR__ . '/panels/display.php'; ?>
                </div>

                <!-- Featured Sections Panel -->
                <div class="panel" id="panel-sections" role="tabpanel" aria-labelledby="tab-sections">
                    <?php include __DIR__ . '/panels/sections.php'; ?>
                </div>

                <!-- Metrics Panel -->
                <div class="panel" id="panel-metrics" role="tabpanel" aria-labelledby="tab-metrics">
                    <?php include __DIR__ . '/panels/metrics.php'; ?>
                </div>

                <!-- Users Panel -->
                <div class="panel" id="panel-users" role="tabpanel" aria-labelledby="tab-users">
                    <?php include __DIR__ . '/panels/users.php'; ?>
                </div>

                <!-- Comments Moderation Panel -->
                <div class="panel" id="panel-comments-mod" role="tabpanel" aria-labelledby="tab-comments-mod">
                    <?php include __DIR__ . '/panels/comments-mod.php'; ?>
                </div>

                <?php if (!empty($canMaintain)): ?>
                <!-- Maintenance / Database Panel (full admins only) -->
                <div class="panel" id="panel-maintenance" role="tabpanel" aria-labelledby="tab-maintenance">
                    <?php include __DIR__ . '/panels/maintenance.php'; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
