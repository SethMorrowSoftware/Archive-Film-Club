<?php
/**
 * Archive Film Club - PHP Backend for Social Media Meta Tags
 * Fetches video metadata and injects Open Graph tags for sharing
 *
 * Now supports MySQL database with JSON fallback
 */

// Route through the app bootstrap so .env, autoloader, and the hardened
// session cookie (secure/httponly/samesite + install-scoped path) are all
// set up before anything else runs. Without this, the first hit of the
// session gets the PHP default cookie path on the very first pageview.
require_once __DIR__ . '/bootstrap.php';

// Default settings
$site_settings = [
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
    'defaultSort' => 'downloads'
];

// Track if database is available
$useDatabase = false;

// Try to load settings from database first (autoloaded by bootstrap.php).
try {
    $settingsService = new SettingsService();
    $dbSettings = $settingsService->getSettings();
    if (!empty($dbSettings)) {
        $site_settings = array_merge($site_settings, $dbSettings);
        $useDatabase = true;
    }
} catch (Throwable $e) {
    // Database not configured or error - fall back to JSON
    error_log("Database settings load failed, using JSON fallback: " . $e->getMessage());
}

// Fallback: Load from JSON file if database not available
if (!$useDatabase) {
    $settings_file = __DIR__ . '/site-settings.json';
    if (file_exists($settings_file)) {
        $content = file_get_contents($settings_file);
        $data = json_decode($content, true);
        if ($data) {
            $site_settings = array_merge($site_settings, $data);
        }
    }
}

// Initialize default values
$ogTitle = $site_settings['siteName'];
$ogDescription = $site_settings['tagline'];
$ogImage = null;
$ogUrl = null;
$ogType = 'website';
$pageTitle = $site_settings['siteName'];
$useVideoThumbnail = false;

// Only try to fetch video thumbnail if we have a specific video parameter.
// Prefer the local cache (instant) over a blocking fetch from archive.org.
if (isset($_GET['video']) && !empty($_GET['video'])) {
    $videoId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['video']);

    // Real browsers are bounced straight to the player. app.js would only
    // location.replace() there anyway, so the blocking metadata fetch
    // below (up to 2s against archive.org) bought them nothing but a
    // slower redirect. Crawlers still get the rendered OG tags, which is
    // what this code path exists for. Only the sanitized id plus the
    // numeric deep-link extras app.js used to forward are carried over.
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isCrawler = $ua === ''
        || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|twitterbot|linkedinbot|whatsapp|telegrambot|discordbot/i', $ua);
    if (!empty($videoId) && !$isCrawler && !headers_sent()) {
        $playerUrl = 'player.php?video=' . rawurlencode($videoId);
        if (isset($_GET['track']) && ctype_digit((string)$_GET['track'])) {
            $playerUrl .= '&track=' . (int)$_GET['track'];
        }
        if (isset($_GET['t']) && ctype_digit((string)$_GET['t'])) {
            $playerUrl .= '&t=' . (int)$_GET['t'];
        }
        header('Location: ' . $playerUrl, true, 302);
        exit;
    }

    if (!empty($videoId)) {
        $metadata = null;

        // 1. Try the local metadata cache first (instant)
        if (class_exists('CacheManager')) {
            try {
                $cm = new CacheManager();
                $cached = $cm->getMetadataCache($videoId);
                if ($cached) {
                    $metadata = [
                        'title' => $cached['title'] ?? null,
                        'description' => $cached['description'] ?? null,
                        'creator' => $cached['creator'] ?? null,
                    ];
                }
            } catch (Throwable $e) {
                // Cache table missing — fall through to direct fetch
            }
        }

        // 2. Cache miss — short blocking fetch with tight timeout
        if ($metadata === null) {
            $metadataUrl = "https://archive.org/metadata/{$videoId}";
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'user_agent' => 'Mozilla/5.0 (compatible; ArchiveFilmClub/1.0)',
                    'ignore_errors' => true
                ]
            ]);

            $response = @file_get_contents($metadataUrl, false, $context);
            if ($response !== false) {
                $data = json_decode($response, true);
                if ($data && isset($data['metadata'])) {
                    $metadata = $data['metadata'];
                }
            }
        }

        if (is_array($metadata)) {
            if (isset($metadata['title'])) {
                $title = is_array($metadata['title']) ? $metadata['title'][0] : $metadata['title'];
                $ogTitle = $title;
                $pageTitle = $title . " - " . $site_settings['siteName'];
            }

            if (isset($metadata['description'])) {
                $desc = is_array($metadata['description']) ? $metadata['description'][0] : $metadata['description'];
                $desc = strip_tags($desc);
                $ogDescription = strlen($desc) > 200 ? substr($desc, 0, 197) . '...' : $desc;
            }

            if (isset($metadata['creator'])) {
                $creator = is_array($metadata['creator']) ? $metadata['creator'][0] : $metadata['creator'];
                if (!empty($creator)) {
                    $ogDescription = "By " . $creator . " - " . $ogDescription;
                }
            }

            // OG image must be fully-qualified for social-media crawlers
            $ogImage = "https://archive.org/services/img/{$videoId}";
            $useVideoThumbnail = true;
            $ogType = 'video.other';
        }
    }
}

// Canonical / OG URL. We DO NOT use $_SERVER['HTTP_HOST'] here because it's
// client-controlled (the Host header) and a forged value would let an
// attacker poison the social-media card image to point at a hostile URL
// served under our domain in feeds. safe_host() prefers APP_URL / SERVER_NAME.
$protocol = is_https() ? 'https' : 'http';
$canonicalHost = safe_host();

// Normalize REQUEST_URI -- preserve `?video=`, `?search=` (the app's own
// key) and legacy `?q=` for canonical identity, but drop ephemeral
// pagination so search-engine canonical URLs don't fragment by page number.
$reqUri = $_SERVER['REQUEST_URI'] ?? '/';
$reqParts = explode('?', $reqUri, 2);
$reqPath = $reqParts[0];
$keptQuery = '';
if (!empty($reqParts[1])) {
    parse_str($reqParts[1], $qs);
    $keepKeys = ['video', 'search', 'q', 'collection', 'sort', 'u', 's'];
    $kept = array_intersect_key($qs, array_flip($keepKeys));
    if ($kept) $keptQuery = '?' . http_build_query($kept);
}
$ogUrl = $protocol . '://' . $canonicalHost . $reqPath . $keptQuery;

// Determine which image to use
if ($useVideoThumbnail && $ogImage) {
    // We have a specific video - use its thumbnail from Archive.org
} else {
    // Homepage, search results, or failed video fetch - use local default.
    // Build via the install base path so this works in subdirectory
    // deployments (e.g. /films/) without the string-replace fragility.
    $ogImage = $protocol . '://' . $canonicalHost . rtrim(app_cookie_path(), '/') . '/og-default.png';
}

// Helper function to safely output HTML attributes
function escapeAttr($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Calculate darker brand color
function darkenColor($hex, $percent = 20) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#' . $hex;
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $r = max(0, $r - ($r * $percent / 100));
    $g = max(0, $g - ($g * $percent / 100));
    $b = max(0, $b - ($b * $percent / 100));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

// Brand colours land inside a <style> block, where htmlspecialchars() is no
// protection (the HTML parser hands the raw text straight to the CSS
// parser). Validate as a hex colour and fall back to the defaults. Guarded
// so the page keeps working if bootstrap's helper isn't present.
$brandColor = function_exists('afc_css_color')
    ? afc_css_color((string)($site_settings['brandColor'] ?? ''), '#ff0000')
    : (string)($site_settings['brandColor'] ?? '#ff0000');
$accentColor = function_exists('afc_css_color')
    ? afc_css_color((string)($site_settings['accentColor'] ?? ''), '#065fd4')
    : (string)($site_settings['accentColor'] ?? '#065fd4');
$brandColorDark = darkenColor($brandColor);
$accentColorDark = darkenColor($accentColor);
$initialTheme = $site_settings['defaultTheme'] === 'system' ? 'dark' : $site_settings['defaultTheme'];

// =====================================================
// Load recommendations + featured sections + cache prefetch UP HERE so the
// <head> can emit <link rel="preload"> hints for the first thumbnails. The
// browser then starts those image requests in parallel with HTML parsing
// instead of waiting until app.js runs.
// =====================================================

$recommendations_config = ['enabled' => false, 'title' => 'Staff Picks', 'videos' => []];
if ($useDatabase && isset($settingsService)) {
    try {
        $recommendations_config = $settingsService->getRecommendations();
    } catch (Exception $e) {
        error_log("Failed to load recommendations from database: " . $e->getMessage());
    }
}
// JSON fallback ONLY when the DB is unreachable. An empty DB result is a
// valid state (admin removed all picks); falling back to JSON there would
// resurrect stale picks the admin already deleted. Migration from a JSON
// install is handled by install.php step 4, not at runtime.
if (!$useDatabase) {
    $recommendations_file = __DIR__ . '/recommendations.json';
    if (file_exists($recommendations_file)) {
        $content = file_get_contents($recommendations_file);
        if ($content) {
            $jsonData = json_decode($content, true);
            if ($jsonData) {
                $recommendations_config = $jsonData;
            }
        }
    }
}

$featured_sections_config = ['sections' => []];
if ($useDatabase && isset($settingsService)) {
    try {
        $featured_sections_config = $settingsService->getFeaturedSections();
    } catch (Exception $e) {
        error_log("Failed to load featured sections from database: " . $e->getMessage());
    }
}
// Same rule as recommendations above: only fall back to JSON if the DB
// is unreachable, never on empty-but-valid DB data.
if (!$useDatabase) {
    $featured_sections_file = __DIR__ . '/featured-sections.json';
    if (file_exists($featured_sections_file)) {
        $content = file_get_contents($featured_sections_file);
        if ($content) {
            $jsonData = json_decode($content, true);
            if ($jsonData) {
                $featured_sections_config = $jsonData;
            }
        }
    }
}

// Pull cached metadata only — never fetch from archive.org during page
// render. Anything not cached gets filled in by the JS after the page loads
// (which also caches it for next time).
$recommendedPrefetch = new stdClass();
$featuredPrefetch = new stdClass();

if (class_exists('CacheManager')) {
    try {
        $cacheManager = new CacheManager();

        $recommendedIds = [];
        if (!empty($recommendations_config['videos']) && is_array($recommendations_config['videos'])) {
            foreach ($recommendations_config['videos'] as $v) {
                if (!empty($v['id']) && is_string($v['id'])) {
                    $recommendedIds[] = $v['id'];
                }
            }
        }

        $featuredIds = [];
        if (!empty($featured_sections_config['sections']) && is_array($featured_sections_config['sections'])) {
            foreach ($featured_sections_config['sections'] as $section) {
                if (empty($section['videos']) || !is_array($section['videos'])) continue;
                foreach ($section['videos'] as $v) {
                    if (!empty($v['id']) && is_string($v['id'])) {
                        $featuredIds[] = $v['id'];
                    }
                }
            }
        }

        $loadFromCache = function(array $ids) use ($cacheManager) {
            $out = new stdClass();
            foreach (array_unique($ids) as $id) {
                $cached = null;
                try {
                    $cached = $cacheManager->getMetadataCache($id);
                } catch (Throwable $e) {
                    // Cache table missing — skip
                }
                if ($cached !== null) {
                    unset($cached['raw_metadata'], $cached['_is_stale']);
                    $out->{$id} = $cached;
                }
            }
            return $out;
        };

        if (!empty($recommendedIds)) {
            $recommendedPrefetch = $loadFromCache($recommendedIds);
        }
        if (!empty($featuredIds)) {
            $featuredPrefetch = $loadFromCache($featuredIds);
        }
    } catch (Throwable $e) {
        error_log("Metadata prefetch failed: " . $e->getMessage());
    }
}

// IDs of thumbnails that are above the fold — preload these in <head>.
// Includes staff picks (always rendered first) plus first row of the first
// featured section.
$aboveFoldThumbIds = [];
if (!empty($recommendations_config['enabled']) && !empty($recommendations_config['videos'])) {
    foreach (array_slice($recommendations_config['videos'], 0, 6) as $v) {
        if (!empty($v['id']) && is_string($v['id'])) {
            $aboveFoldThumbIds[] = $v['id'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= escapeAttr($initialTheme) ?>">
<head>
  <meta charset="UTF-8" />
  <!-- viewport-fit=cover lets env(safe-area-inset-*) return real notch
       insets on iOS, so headers/sidebars dodge the camera cutout in PWA
       standalone mode and landscape. -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover" />
  <?php include __DIR__ . '/partials/head-common.php'; ?>
  <title><?= escapeAttr($pageTitle) ?></title>
  <meta name="description" content="<?= escapeAttr($ogDescription) ?>" />
  <meta name="theme-color" content="<?= escapeAttr($brandColor) ?>" />
  <!-- Per-scheme browser chrome. The brand-color above is the fallback for
       browsers that ignore media-scoped theme-color; these match the page
       background tokens (--color-bg-primary) so light mode isn't tinted. -->
  <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0a0a0b" />
  <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff" />

  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="<?= escapeAttr($ogType) ?>" />
  <meta property="og:url" content="<?= escapeAttr($ogUrl) ?>" />
  <meta property="og:title" content="<?= escapeAttr($ogTitle) ?>" />
  <meta property="og:description" content="<?= escapeAttr($ogDescription) ?>" />
  <meta property="og:image" content="<?= escapeAttr($ogImage) ?>" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:site_name" content="<?= escapeAttr($site_settings['siteName']) ?>" />

  <!-- Twitter Card -->
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:url" content="<?= escapeAttr($ogUrl) ?>" />
  <meta name="twitter:title" content="<?= escapeAttr($ogTitle) ?>" />
  <meta name="twitter:description" content="<?= escapeAttr($ogDescription) ?>" />
  <meta name="twitter:image" content="<?= escapeAttr($ogImage) ?>" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://archive.org">
  <link rel="dns-prefetch" href="https://archive.org">

  <!-- Preload critical resources so the browser can fetch them in parallel
       with the HTML parse instead of waiting for the parser to discover them -->
  <link rel="preload" href="<?= escapeAttr(asset_url('styles.css')) ?>" as="style">
  <link rel="preload" href="<?= escapeAttr(asset_url('app.js')) ?>" as="script" crossorigin>

  <?php
  // Preload above-the-fold thumbnails. The browser starts these requests
  // immediately on receiving the head, in parallel with HTML parsing and
  // JS execution, so they're already loaded by the time the JS renders the
  // staff picks cards.
  foreach ($aboveFoldThumbIds as $thumbId):
      $thumbUrl = 'api/thumbnail.php?id=' . urlencode($thumbId);
  ?>
  <link rel="preload" as="image" href="<?= escapeAttr($thumbUrl) ?>" fetchpriority="high">
  <?php endforeach; ?>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Favicons. SVG inline for crisp rendering everywhere; ICO/PNG
       fallbacks for older browsers and search engines. Drop apple/PNG
       files in the install root to override. -->
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTQgOEw0IDE2QzQgMTcuMTA0NiA0Ljg5NTQzIDE4IDYgMThMMTggMThDMTkuMTA0NiAxOCAyMCAxNy4xMDQ2IDIwIDE2VjhDMjAgNi44OTU0MyAxOS4xMDQ2IDYgMTggNkw2IDZDNC44OTU0MyA2IDQgNi44OTU0MyA0IDhaIiBzdHJva2U9IiNmZjAwMDAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIi8+CjxwYXRoIGQ9Ik0xMCAxMkwxNCAxMk0xMiAxMEwxMiAxNCIgc3Ryb2tlPSIjZmYwMDAwIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgo8L3N2Zz4K" />
  <link rel="alternate icon" href="favicon.ico" sizes="any">
  <link rel="apple-touch-icon" href="apple-touch-icon.png">
  <link rel="manifest" href="manifest.webmanifest">

  <link rel="stylesheet" href="<?= escapeAttr(asset_url('styles.css')) ?>">
  <link rel="stylesheet" href="<?= escapeAttr(asset_url('auth-styles.css')) ?>">

  <!-- Custom brand colors from admin settings -->
  <style>
    :root {
      --brand-color: <?= escapeAttr($brandColor) ?>;
      --brand-color-dark: <?= escapeAttr($brandColorDark) ?>;
      --accent-color: <?= escapeAttr($accentColor) ?>;
      --accent-color-dark: <?= escapeAttr($accentColorDark) ?>;
    }
  </style>

  <!-- Theme initialization (before body renders to prevent flash) -->
  <script>
    (function() {
      var savedTheme = localStorage.getItem('theme');
      var defaultTheme = '<?= escapeAttr($site_settings['defaultTheme']) ?>';
      var theme = savedTheme || defaultTheme;

      if (theme === 'system') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }

      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body data-card-style="<?= escapeAttr($site_settings['cardStyle']) ?>">
  <a class="skip-link" href="#mainResults">Skip to results</a>
  <noscript>
    <div class="noscript-banner" role="alert">
      <h2>JavaScript is required</h2>
      <p><?= escapeAttr($site_settings['siteName']) ?> is an interactive front-end for the public collection at the Internet Archive, and needs JavaScript to search and play videos. Please enable JavaScript and reload the page.</p>
      <p>In the meantime, you can browse the same films directly at <a href="https://archive.org/details/movies" target="_blank" rel="noopener">archive.org</a>.</p>
    </div>
  </noscript>
  <header class="site-header">
    <div class="header-content">
      <button class="mobile-menu-btn" aria-label="Open menu">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M3 12H21M3 6H21M3 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>

      <!-- Clickable Logo - Goes Home -->
      <a href="index.php" class="logo-section" title="Go to homepage">
        <div class="logo-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 8L4 16C4 17.1046 4.89543 18 6 18L18 18C19.1046 18 20 17.1046 20 16V8C20 6.89543 19.1046 6 18 6L6 6C4.89543 6 4 6.89543 4 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M10 12L14 12M12 10L12 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <span class="logo-text"><?= escapeAttr($site_settings['siteName']) ?></span>
      </a>

      <!-- Centered Search Bar -->
      <form id="searchForm" class="header-search-form" role="search" aria-label="Search videos">
        <div class="header-search-input-wrapper">
          <input
            id="searchInput"
            type="search"
            class="header-search-input"
            placeholder="Search videos, creators, collections..."
            autocomplete="off"
            aria-label="Search videos"
          />
          <span class="header-search-kbd" aria-hidden="true" data-search-kbd></span>
          <button id="clearSearchBtn" class="clear-search-btn" type="button" style="display: none;" aria-label="Clear search">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
        <button type="submit" class="search-submit-btn" aria-label="Search">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M21 21L15 15M17 10C17 13.866 13.866 17 10 17C6.13401 17 3 13.866 3 10C3 6.13401 6.13401 3 10 3C13.866 3 17 6.13401 17 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
      </form>

      <!-- Right side with theme toggle -->
      <div class="header-end">
        <div class="header-auth" data-auth-nav></div>
        <?php if ($site_settings['enableThemeToggle']): ?>
        <button id="themeToggle" class="theme-toggle" aria-label="Toggle theme" title="Toggle light/dark mode">
          <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="5"></circle>
            <line x1="12" y1="1" x2="12" y2="3"></line>
            <line x1="12" y1="21" x2="12" y2="23"></line>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
            <line x1="1" y1="12" x2="3" y2="12"></line>
            <line x1="21" y1="12" x2="23" y2="12"></line>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
          </svg>
          <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
          </svg>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <div class="mobile-overlay"></div>

  <main class="main-layout">
    <aside class="sidebar" aria-label="Filter videos">
      <button class="mobile-close-btn" aria-label="Close menu">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>

      <section class="filter-section">
        <h2 class="filter-title">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3 7H21M6 12H18M9 17H15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Collections
        </h2>
        <div class="filter-field">
          <label for="collection">Select Collection</label>
          <div class="select-wrapper">
            <select id="collection" class="filter-select"></select>
            <svg class="select-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
        </div>
      </section>

      <section class="filter-section">
        <h2 class="filter-title">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3 6H21M6 12H18M11 18H13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          Sort & Filters
        </h2>

        <div class="filter-field">
          <label for="sortBy">Sort By</label>
          <div class="select-wrapper">
            <select id="sortBy" class="filter-select">
              <option value="relevance">Relevance</option>
              <option value="date">Date (Newest First)</option>
              <option value="downloads" selected>Most Downloaded</option>
              <option value="title">Title (A-Z)</option>
              <option value="creator">Creator (A-Z)</option>
            </select>
            <svg class="select-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
        </div>

        <div class="checkbox-group">
          <input id="publicDomain" type="checkbox" class="checkbox-input" />
          <label for="publicDomain" class="checkbox-label">Public Domain Only</label>
        </div>

        <div class="checkbox-group">
          <input id="collectionsOnly" type="checkbox" class="checkbox-input" />
          <label for="collectionsOnly" class="checkbox-label">Collections Only</label>
        </div>
      </section>

      <button id="clearFilters" class="btn btn-secondary btn-full" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        Reset All Filters
      </button>

      <div id="searchStats" class="stats" aria-live="polite" aria-atomic="true">Ready to search</div>
    </aside>

    <section class="content-area">
      <!-- Continue Watching (resumable videos, populated client-side) -->
      <section id="continueWatchingSection" class="recommended-section continue-watching-section" style="display: none;">
        <div class="recommended-header">
          <h2 class="recommended-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="12" cy="12" r="10"/>
              <polyline points="12 6 12 12 16 14"/>
            </svg>
            Continue Watching
          </h2>
          <button id="clearContinueWatching" class="btn btn-ghost" aria-label="Clear continue watching">Clear all</button>
        </div>
        <div id="continueWatchingGrid" class="recommended-grid"></div>
      </section>

      <!-- Recommended Section (Admin Picks) -->
      <section id="recommendedSection" class="recommended-section" style="display: none;">
        <div class="recommended-header">
          <h2 class="recommended-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
              <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
            </svg>
            Staff Picks
          </h2>
          <button id="hideRecommended" class="btn btn-ghost" aria-label="Hide recommendations">Hide</button>
        </div>
        <div id="recommendedGrid" class="recommended-grid"></div>
      </section>

      <!-- Featured Sections Container -->
      <div id="featuredSectionsContainer"></div>

      <div id="loading" class="loading" role="status" aria-busy="true" hidden>
        <div class="loading-spinner" aria-hidden="true">
          <div class="spinner-ring"></div>
        </div>
        <span class="loading-text">Searching archive...</span>
      </div>

      <div id="error" class="error" role="alert" hidden></div>

      <div id="mainResults" tabindex="-1"></div>
      <div id="results" class="results-grid"></div>

      <nav id="pagination" class="pagination" aria-label="Page navigation"></nav>
    </section>
  </main>

  <!-- Back to top -->
  <button id="backToTop" class="back-to-top" aria-label="Back to top" title="Back to top">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <polyline points="18 15 12 9 6 15"/>
    </svg>
  </button>

  <?php
    // JSON_HEX_TAG escapes "<" so a "</script>" sequence inside cached
    // metadata can't break out of these script blocks. JSON_HEX_AMP /
    // JSON_HEX_AROUND are belt-and-suspenders for older parsers.
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
  ?>
  <!-- Site Settings Configuration -->
  <script id="siteSettingsConfig" type="application/json"><?= json_encode($site_settings, $jsonFlags) ?></script>

  <!-- Admin Recommended Videos Configuration (loaded at top of file) -->
  <script id="recommendedConfig" type="application/json"><?= json_encode($recommendations_config, $jsonFlags) ?></script>

  <!-- Featured Sections Configuration (loaded at top of file) -->
  <script id="featuredSectionsConfig" type="application/json"><?= json_encode($featured_sections_config, $jsonFlags) ?></script>

  <!-- Server-side metadata prefetch (loaded at top of file).
       For staff picks and featured sections, the server hits the local cache
       directly so the JS doesn't need to wait on a network round-trip to
       render cards on repeat visits. -->
  <script id="recommendedMetadataPrefetch" type="application/json"><?= json_encode($recommendedPrefetch, $jsonFlags | JSON_UNESCAPED_SLASHES) ?></script>
  <script id="featuredMetadataPrefetch" type="application/json"><?= json_encode($featuredPrefetch, $jsonFlags | JSON_UNESCAPED_SLASHES) ?></script>

  <!-- Theme Toggle Script -->
  <script>
    (function() {
      var themeToggle = document.getElementById('themeToggle');
      if (!themeToggle) return;

      function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('theme', theme);
      }

      function toggleTheme() {
        var currentTheme = document.documentElement.getAttribute('data-theme');
        var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        setTheme(newTheme);
      }

      themeToggle.addEventListener('click', toggleTheme);

      // Listen for system theme changes
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        var savedTheme = localStorage.getItem('theme');
        if (!savedTheme || savedTheme === 'system') {
          setTheme(e.matches ? 'dark' : 'light');
        }
      });
    })();
  </script>

  <!-- Back-to-top button -->
  <script>
    (function() {
      var btn = document.getElementById('backToTop');
      if (!btn) return;
      // Coalesce scroll events to one class toggle per frame — the raw
      // handler ran on every scroll tick, which is dozens per frame on
      // touch devices.
      var ticking = false;
      var update = function() {
        ticking = false;
        if (window.scrollY > 480) btn.classList.add('visible');
        else btn.classList.remove('visible');
      };
      var scrollHandler = function() {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(update);
      };
      window.addEventListener('scroll', scrollHandler, { passive: true });
      update();
      btn.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    })();
  </script>

  <!-- Search keyboard shortcut (Cmd/Ctrl+K) and platform-aware kbd hint -->
  <script>
    (function() {
      var searchInput = document.getElementById('searchInput');
      var kbdHint = document.querySelector('[data-search-kbd]');
      if (!searchInput) return;

      var isMac = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '');
      if (kbdHint) {
        kbdHint.textContent = isMac ? '⌘ K' : 'Ctrl K';
      }

      document.addEventListener('keydown', function(e) {
        var isShortcut = (e.key === 'k' || e.key === 'K') && (isMac ? e.metaKey : e.ctrlKey);
        if (isShortcut) {
          e.preventDefault();
          searchInput.focus();
          searchInput.select();
        }
        // Forward-slash to focus search (when not typing in another field)
        if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey) {
          var tag = (e.target && e.target.tagName) || '';
          var isEditable = tag === 'INPUT' || tag === 'TEXTAREA' || (e.target && e.target.isContentEditable);
          if (!isEditable) {
            e.preventDefault();
            searchInput.focus();
            searchInput.select();
          }
        }
        // Escape to clear focus from search
        if (e.key === 'Escape' && document.activeElement === searchInput) {
          searchInput.blur();
        }
      });
    })();
  </script>

  <?php include __DIR__ . '/partials/footer.php'; ?>

  <script>
  /* App-load watchdog (non-module). <noscript> only covers DISABLED JS; this
     covers JS that's on but broken: the ES-module entrypoint failing to load
     (a proxy stripping it, a network blip) or a browser too old to parse
     modules. app.js sets window.__afcReady as soon as it runs; if that hasn't
     happened within the window we reveal a recovery message instead of a
     blank page. Tuned to 8s to avoid false positives on slow links. */
  (function () {
    setTimeout(function () {
      if (window.__afcReady) return;
      var msg = "We couldn’t load the app. Check your connection and try again, or browse the collection on archive.org.";
      var el = document.getElementById('error');
      if (el) {
        el.innerHTML = '<p>' + msg + '</p><p>'
          + '<button type="button" onclick="location.reload()">Retry</button> '
          + '<a href="https://archive.org/details/movies" target="_blank" rel="noopener">Open archive.org</a></p>';
        el.hidden = false;
      } else if (document.body) {
        var b = document.createElement('div');
        b.setAttribute('role', 'alert');
        b.style.cssText = 'position:fixed;left:16px;right:16px;top:16px;z-index:10000;background:#1d1d22;color:#f5f5f7;border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:16px;font:14px/1.5 system-ui,-apple-system,sans-serif';
        b.innerHTML = '<strong>Couldn’t load.</strong> ' + msg
          + ' <button type="button" onclick="location.reload()" style="margin-left:8px">Retry</button>'
          + ' <a href="https://archive.org/details/movies" target="_blank" rel="noopener" style="color:#8ab4f8">archive.org</a>';
        document.body.appendChild(b);
      }
    }, 8000);
  })();
  </script>

  <script type="module" src="<?= escapeAttr(asset_url('app.js')) ?>"></script>
</body>
</html>
