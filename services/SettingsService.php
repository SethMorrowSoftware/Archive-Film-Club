<?php
/**
 * Settings Service
 *
 * Handles site settings, recommendations, and featured sections
 */

require_once __DIR__ . '/../db/Database.php';

class SettingsService {
    private $db;

    // Default settings
    private $defaults = [
        'siteName' => 'Archive Film Club',
        'tagline' => 'Discover classic films from Archive.org',
        'brandColor' => '#ff0000',
        'accentColor' => '#065fd4',
        'defaultTheme' => 'dark',
        'enableThemeToggle' => true,
        'cardStyle' => 'modern',
        'showDownloadCount' => true,
        'showCreator' => true,
        'showDate' => true,
        'enableBookmarks' => true,
        'enableWatchHistory' => true,
        'defaultCollection' => 'all_videos',
        'defaultSort' => 'downloads',
    ];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // =====================================================
    // SITE SETTINGS
    // =====================================================

    /**
     * Get all site settings
     */
    public function getSettings(): array {
        $settings = $this->defaults;

        try {
            $rows = $this->db->fetchAll("SELECT setting_key, setting_value, setting_type FROM site_settings");

            foreach ($rows as $row) {
                $value = $this->castValue($row['setting_value'], $row['setting_type']);
                $settings[$row['setting_key']] = $value;
            }
        } catch (Exception $e) {
            // Return defaults if database fails
            error_log("Failed to load settings: " . $e->getMessage());
        }

        // The two colors are interpolated into inline <style> blocks by every
        // page. The API validates them on write, but a row written any other
        // way (install.php JSON import, a restore, a manual UPDATE) is not
        // covered, so sanitize on the way OUT too — every consumer is then
        // safe regardless of what the table holds.
        foreach (['brandColor', 'accentColor'] as $colorKey) {
            $settings[$colorKey] = self::safeCssColor(
                (string)($settings[$colorKey] ?? ''),
                (string)$this->defaults[$colorKey]
            );
        }

        return $settings;
    }

    /**
     * Hex-color-or-fallback. Uses bootstrap.php's afc_css_color() when the
     * app is bootstrapped; the cron scripts load this class directly, so the
     * same rule is inlined as a fallback.
     */
    private static function safeCssColor(string $value, string $fallback): string {
        if (function_exists('afc_css_color')) {
            return afc_css_color($value, $fallback);
        }
        return preg_match('/^#[0-9a-f]{3,8}$/i', $value) ? $value : $fallback;
    }

    /**
     * Get a single setting
     */
    public function getSetting(string $key, $default = null) {
        try {
            $row = $this->db->fetchOne(
                "SELECT setting_value, setting_type FROM site_settings WHERE setting_key = ?",
                [$key]
            );

            if ($row) {
                return $this->castValue($row['setting_value'], $row['setting_type']);
            }
        } catch (Exception $e) {
            error_log("Failed to get setting: " . $e->getMessage());
        }

        return $default ?? ($this->defaults[$key] ?? null);
    }

    /**
     * Update a single setting. Public entry point (callable outside a
     * transaction) -- catches its own exceptions and returns bool.
     */
    public function setSetting(string $key, $value): bool {
        try {
            $this->writeSetting($key, $value);
            return true;
        } catch (Exception $e) {
            error_log("Failed to set setting: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Internal helper: actually run the upsert. THROWS on failure so the
     * surrounding transaction in updateSettings() can roll back. Without
     * this split, a write failure inside the loop was silently swallowed
     * and the transaction committed regardless.
     */
    private function writeSetting(string $key, $value): void {
        $type = $this->determineType($value);
        $stringValue = $this->valueToString($value, $type);

        $this->db->query(
            "INSERT INTO site_settings (setting_key, setting_value, setting_type)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                setting_type = VALUES(setting_type)",
            [$key, $stringValue, $type]
        );
    }

    /**
     * Update multiple settings atomically. If any single write fails, the
     * whole batch rolls back. (Previously: setSetting swallowed its own
     * exception so the transaction committed partial state silently.)
     */
    public function updateSettings(array $settings): bool {
        try {
            $this->db->beginTransaction();

            foreach ($settings as $key => $value) {
                $this->writeSetting($key, $value);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            try { $this->db->rollback(); } catch (Throwable $_) { /* nothing to roll back */ }
            error_log("Failed to update settings: " . $e->getMessage());
            return false;
        }
    }

    // =====================================================
    // RECOMMENDATIONS
    // =====================================================

    /**
     * Get recommendations configuration
     */
    public function getRecommendations(): array {
        $config = [
            'enabled' => true,
            'title' => 'Staff Picks',
            'videos' => [],
        ];

        try {
            // Get settings
            $settings = $this->db->fetchOne("SELECT enabled, title FROM recommendations_settings LIMIT 1");
            if ($settings) {
                $config['enabled'] = (bool)$settings['enabled'];
                $config['title'] = $settings['title'];
            }

            // Get videos
            $videos = $this->db->fetchAll(
                "SELECT archive_id as id, title, creator
                 FROM recommended_videos
                 WHERE enabled = 1
                 ORDER BY display_order ASC"
            );
            $config['videos'] = $videos;

        } catch (Exception $e) {
            error_log("Failed to get recommendations: " . $e->getMessage());
        }

        return $config;
    }

    /**
     * Update recommendations
     */
    public function updateRecommendations(array $data): bool {
        try {
            $this->db->beginTransaction();

            // Update settings
            $this->db->query(
                "INSERT INTO recommendations_settings (id, enabled, title)
                 VALUES (1, ?, ?)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), title = VALUES(title)",
                [
                    isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1,
                    $data['title'] ?? 'Staff Picks'
                ]
            );

            // Update videos if provided. Defense-in-depth: sanitize the
            // archive_id here too. The /api/recommendations.php endpoint
            // already runs the same allow-list before calling us, but
            // SettingsService is also reachable from install.php where the
            // JSON data is operator-supplied.
            if (isset($data['videos']) && is_array($data['videos'])) {
                // Remove existing videos
                $this->db->query("DELETE FROM recommended_videos");

                // Add new videos
                foreach ($data['videos'] as $order => $video) {
                    if (!is_array($video) || empty($video['id'])) continue;
                    $archiveId = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)$video['id']);
                    if ($archiveId === '') continue;

                    $this->db->insert('recommended_videos', [
                        'archive_id' => $archiveId,
                        'title' => isset($video['title']) ? mb_substr((string)$video['title'], 0, 500) : null,
                        'creator' => isset($video['creator']) ? mb_substr((string)$video['creator'], 0, 255) : null,
                        'display_order' => (int)$order,
                        'enabled' => 1,
                    ]);
                }
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            try { $this->db->rollback(); } catch (Throwable $_) { /* nothing to roll back */ }
            error_log("Failed to update recommendations: " . $e->getMessage());
            return false;
        }
    }

    // =====================================================
    // FEATURED SECTIONS
    // =====================================================

    /**
     * Get all featured sections
     */
    public function getFeaturedSections(): array {
        $result = ['sections' => []];

        try {
            $sections = $this->db->fetchAll(
                "SELECT id, section_id, title, description, enabled
                 FROM featured_sections
                 ORDER BY display_order ASC"
            );

            foreach ($sections as $section) {
                // Get videos for this section
                $videos = $this->db->fetchAll(
                    "SELECT archive_id as id, title, creator
                     FROM featured_section_videos
                     WHERE section_id = ?
                     ORDER BY display_order ASC",
                    [$section['id']]
                );

                $result['sections'][] = [
                    'id' => $section['section_id'],
                    'title' => $section['title'],
                    'description' => $section['description'],
                    'enabled' => (bool)$section['enabled'],
                    'videos' => $videos,
                ];
            }
        } catch (Exception $e) {
            error_log("Failed to get featured sections: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Update featured sections
     */
    public function updateFeaturedSections(array $data): bool {
        try {
            $this->db->beginTransaction();

            // Get existing section IDs to compare
            $existingIds = $this->db->fetchAll("SELECT section_id FROM featured_sections");
            $existingIds = array_column($existingIds, 'section_id');

            $newIds = [];
            foreach ($data['sections'] ?? [] as $order => $sectionData) {
                $sectionId = $sectionData['id'];
                $newIds[] = $sectionId;

                // Upsert section
                $this->db->query(
                    "INSERT INTO featured_sections (section_id, title, description, enabled, display_order)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        title = VALUES(title),
                        description = VALUES(description),
                        enabled = VALUES(enabled),
                        display_order = VALUES(display_order)",
                    [
                        $sectionId,
                        $sectionData['title'] ?? '',
                        $sectionData['description'] ?? '',
                        isset($sectionData['enabled']) ? ($sectionData['enabled'] ? 1 : 0) : 1,
                        $order
                    ]
                );

                // Get the section's database ID
                $dbSection = $this->db->fetchOne(
                    "SELECT id FROM featured_sections WHERE section_id = ?",
                    [$sectionId]
                );

                if ($dbSection) {
                    // Clear existing videos for this section
                    $this->db->delete('featured_section_videos', 'section_id = ?', [$dbSection['id']]);

                    // Add videos (sanitize defense-in-depth -- see
                    // updateRecommendations() for the reasoning).
                    foreach ($sectionData['videos'] ?? [] as $videoOrder => $video) {
                        if (!is_array($video) || empty($video['id'])) continue;
                        $archiveId = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)$video['id']);
                        if ($archiveId === '') continue;

                        $this->db->insert('featured_section_videos', [
                            'section_id' => $dbSection['id'],
                            'archive_id' => $archiveId,
                            'title' => isset($video['title']) ? mb_substr((string)$video['title'], 0, 500) : null,
                            'creator' => isset($video['creator']) ? mb_substr((string)$video['creator'], 0, 255) : null,
                            'display_order' => (int)$videoOrder,
                        ]);
                    }
                }
            }

            // Delete removed sections
            $removedIds = array_diff($existingIds, $newIds);
            foreach ($removedIds as $removedId) {
                $this->db->delete('featured_sections', 'section_id = ?', [$removedId]);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            try { $this->db->rollback(); } catch (Throwable $_) { /* nothing to roll back */ }
            error_log("Failed to update featured sections: " . $e->getMessage());
            return false;
        }
    }

    // =====================================================
    // HELPER METHODS
    // =====================================================

    /**
     * Cast string value to appropriate type
     */
    private function castValue(string $value, string $type) {
        switch ($type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'number':
                return is_numeric($value) ? (strpos($value, '.') !== false ? (float)$value : (int)$value) : 0;
            case 'json':
                return json_decode($value, true) ?? [];
            default:
                return $value;
        }
    }

    /**
     * Determine value type
     */
    private function determineType($value): string {
        if (is_bool($value)) {
            return 'boolean';
        } elseif (is_int($value) || is_float($value)) {
            return 'number';
        } elseif (is_array($value) || is_object($value)) {
            return 'json';
        }
        return 'string';
    }

    /**
     * Convert value to string for storage
     */
    private function valueToString($value, string $type): string {
        switch ($type) {
            case 'boolean':
                return $value ? '1' : '0';
            case 'number':
                return (string)$value;
            case 'json':
                return json_encode($value);
            default:
                return (string)$value;
        }
    }
}
