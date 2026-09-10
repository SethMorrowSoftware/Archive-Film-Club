        <aside class="admin-sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="index.php" target="_blank" class="sidebar-logo">
                    <div class="sidebar-logo-icon">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    </div>
                    <span class="sidebar-logo-text"><?= htmlspecialchars($site_settings['siteName'] ?? 'Film Club') ?></span>
                </a>
            </div>

            <nav class="sidebar-nav">
                <div role="tablist" aria-label="Admin panels">
                <div class="nav-section">
                    <div class="nav-section-title">Overview</div>
                    <button type="button" class="nav-item active" data-panel="dashboard" role="tab" id="tab-dashboard" aria-controls="panel-dashboard" aria-selected="true">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span>
                        Dashboard
                    </button>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Community</div>
                    <button type="button" class="nav-item" data-panel="metrics" role="tab" id="tab-metrics" aria-controls="panel-metrics" aria-selected="false">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span>
                        Metrics
                    </button>
                    <button type="button" class="nav-item" data-panel="users" role="tab" id="tab-users" aria-controls="panel-users" aria-selected="false">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg></span>
                        Users
                        <span class="nav-item-badge" id="navUserCount">—</span>
                    </button>
                    <button type="button" class="nav-item" data-panel="comments-mod" role="tab" id="tab-comments-mod" aria-controls="panel-comments-mod" aria-selected="false">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg></span>
                        Comments
                        <span class="nav-item-badge" id="navReportsCount" style="display:none;">0</span>
                    </button>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Content</div>
                    <button type="button" class="nav-item" data-panel="staff-picks" role="tab" id="tab-staff-picks" aria-controls="panel-staff-picks" aria-selected="false">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>
                        Staff Picks
                        <span class="nav-item-badge" id="navVideoCount"><?= count($current_recommendations) ?></span>
                    </button>
                    <button type="button" class="nav-item" data-panel="sections" role="tab" id="tab-sections" aria-controls="panel-sections" aria-selected="false">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg></span>
                        Featured Sections
                    </button>
                </div>

                <div class="nav-section">
                    <div class="nav-section-title">Configuration</div>
                    <button type="button" class="nav-item" data-panel="site-settings" role="tab" id="tab-site-settings" aria-controls="panel-site-settings" aria-selected="false">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg></span>
                        Site Settings
                    </button>
                    <button type="button" class="nav-item" data-panel="appearance" role="tab" id="tab-appearance" aria-controls="panel-appearance" aria-selected="false">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="13.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="10.5" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/><circle cx="6.5" cy="12" r="2.5"/><path d="M12 2C6.49 2 2 6.49 2 12s4.49 10 10 10c.55 0 1-.45 1-1v-.53a1 1 0 011-1h1.03c2.76 0 5-2.24 5-5 0-4.97-4.49-8.47-8.03-8.47z"/></svg></span>
                        Appearance
                    </button>
                    <button type="button" class="nav-item" data-panel="display" role="tab" id="tab-display" aria-controls="panel-display" aria-selected="false">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></span>
                        Display Options
                    </button>
                </div>

                <?php if (!empty($canMaintain)): ?>
                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <button type="button" class="nav-item" data-panel="maintenance" role="tab" id="tab-maintenance" aria-controls="panel-maintenance" aria-selected="false">
                        <span class="nav-item-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg></span>
                        Maintenance
                    </button>
                </div>
                <?php endif; ?>
                </div>
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-db-status">
                    <span class="db-status-dot <?= $useDatabase ? 'connected' : 'disconnected' ?>"></span>
                    <?= $useDatabase ? 'MySQL Connected' : 'JSON Fallback' ?>
                </div>
                <a href="index.php" target="_blank" class="btn btn-secondary btn-full btn-sm">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    View Site
                </a>
                <form method="POST" action="admin.php" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
                    <input type="hidden" name="logout" value="1">
                    <button type="submit" class="btn btn-ghost btn-full btn-sm">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Sign Out
                    </button>
                </form>
            </div>
        </aside>
