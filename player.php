<?php
/**
 * Archive Film Club - Dedicated Video Player Page
 * Immersive cinema-like player with rich playlist support
 */

// Route through the app bootstrap so .env, autoloader, and the hardened
// session cookie (secure/httponly/samesite + install-scoped path) are set
// up before anything else runs on cold player-page visits.
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

$useDatabase = false;

try {
    // SettingsService is autoloaded via bootstrap.php.
    $settingsService = new SettingsService();
    $dbSettings = $settingsService->getSettings();
    if (!empty($dbSettings)) {
        $site_settings = array_merge($site_settings, $dbSettings);
        $useDatabase = true;
    }
} catch (Throwable $e) {
    error_log("Database settings load failed: " . $e->getMessage());
}

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

// Initialize OG defaults
$ogTitle = $site_settings['siteName'];
$ogDescription = $site_settings['tagline'];
$ogImage = null;
$ogUrl = null;
$ogType = 'website';
$pageTitle = $site_settings['siteName'];
$useVideoThumbnail = false;

// Fetch video metadata for OG tags. Prefer the local cache (instant) and
// only fall back to a quick blocking fetch from archive.org if we have no
// cached copy at all. This used to do an unconditional 5-second blocking
// fetch on every player page load.
if (isset($_GET['video']) && !empty($_GET['video'])) {
    $videoId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['video']);

    if (!empty($videoId)) {
        $metadata = null;

        // 1. Try the local metadata cache first (instant)
        if (class_exists('CacheManager')) {
            try {
                $cm = new CacheManager();
                $cached = $cm->getMetadataCache($videoId);
                if ($cached) {
                    // The cache stores a flat shape; rebuild a "metadata"
                    // dictionary that mimics archive.org's response so the
                    // existing extract logic below works unchanged.
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

        // 2. Cache miss — make a short blocking fetch to archive.org. Keep
        //    the timeout tight so a flaky upstream can't blow up TTFB.
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

                    // Best-effort: stash this in the local cache so the next
                    // page load skips the blocking call entirely.
                    if (class_exists('LocalStorageService')) {
                        try {
                            $lss = new LocalStorageService();
                            $lss->cacheItem($videoId, null, true);
                        } catch (Throwable $e) {
                            // Silent — we already have the data we need
                        }
                    }
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

            // OG image must be a fully-qualified URL for social-media
            // crawlers. Hot-link to archive.org here — the local proxy is
            // for visitor-facing img tags, not for crawlers.
            $ogImage = "https://archive.org/services/img/{$videoId}";
            $useVideoThumbnail = true;
            $ogType = 'video.other';
        }
    }
}

// Canonical / OG URL. Same Host-header-poisoning reasoning as index.php:
// safe_host() pulls from APP_URL → SERVER_NAME, never HTTP_HOST.
$protocol = is_https() ? 'https' : 'http';
$canonicalHost = safe_host();

$reqUri = $_SERVER['REQUEST_URI'] ?? '/';
$reqParts = explode('?', $reqUri, 2);
$reqPath = $reqParts[0];
$keptQuery = '';
if (!empty($reqParts[1])) {
    parse_str($reqParts[1], $qs);
    $keepKeys = ['video', 'list', 'index'];
    $kept = array_intersect_key($qs, array_flip($keepKeys));
    if ($kept) $keptQuery = '?' . http_build_query($kept);
}
$ogUrl = $protocol . '://' . $canonicalHost . $reqPath . $keptQuery;

if ($useVideoThumbnail && $ogImage) {
    // Use video thumbnail
} else {
    $ogImage = $protocol . '://' . $canonicalHost . rtrim(app_cookie_path(), '/') . '/og-default.png';
}

function escapeAttr($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

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

// The colours land inside a <style> block where htmlspecialchars() can't
// neutralise CSS syntax; only a hex colour is ever allowed through.
if (function_exists('afc_css_color')) {
    $site_settings['brandColor'] = afc_css_color((string)$site_settings['brandColor'], '#ff0000');
    $site_settings['accentColor'] = afc_css_color((string)$site_settings['accentColor'], '#065fd4');
} else {
    foreach (['brandColor' => '#ff0000', 'accentColor' => '#065fd4'] as $k => $fallback) {
        if (!preg_match('/^#[0-9a-f]{3,8}$/i', (string)$site_settings[$k])) $site_settings[$k] = $fallback;
    }
}
$brandColorDark = darkenColor($site_settings['brandColor']);
$accentColorDark = darkenColor($site_settings['accentColor']);
$initialTheme = in_array($site_settings['defaultTheme'], ['dark', 'light'], true) ? $site_settings['defaultTheme'] : 'dark';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= escapeAttr($initialTheme) ?>">
<head>
  <meta charset="UTF-8" />
  <!-- viewport-fit=cover lets env(safe-area-inset-*) return real notch
       insets on iOS, so the fixed player header dodges the camera cutout
       in PWA standalone mode. -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover" />
  <?php include __DIR__ . '/partials/head-common.php'; ?>
  <title><?= escapeAttr($pageTitle) ?></title>
  <meta name="description" content="<?= escapeAttr($ogDescription) ?>" />
  <meta name="theme-color" content="<?= escapeAttr($site_settings['brandColor']) ?>" />
  <!-- Per-scheme browser chrome (brand-color above is the fallback). Matches
       the page background tokens so light mode isn't tinted. -->
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

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzIiIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHBhdGggZD0iTTQgOEw0IDE2QzQgMTcuMTA0NiA0Ljg5NTQzIDE4IDYgMThMMTggMThDMTkuMTA0NiAxOCAyMCAxNy4xMDQ2IDIwIDE2VjhDMjAgNi44OTU0MyAxOS4xMDQ2IDYgMTggNkw2IDZDNC44OTU0MyA2IDQgNi44OTU0MyA0IDhaIiBzdHJva2U9IiNmZjAwMDAiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIi8+CjxwYXRoIGQ9Ik0xMCAxMkwxNCAxMk0xMiAxMEwxMiAxNCIgc3Ryb2tlPSIjZmYwMDAwIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIvPgo8L3N2Zz4K" />
  <link rel="alternate icon" href="favicon.ico" sizes="any">
  <link rel="apple-touch-icon" href="apple-touch-icon.png">
  <link rel="manifest" href="manifest.webmanifest">

  <link rel="stylesheet" href="<?= escapeAttr(asset_url('styles.css')) ?>">
  <link rel="stylesheet" href="<?= escapeAttr(asset_url('player-styles.css')) ?>">
  <link rel="stylesheet" href="<?= escapeAttr(asset_url('auth-styles.css')) ?>">

  <style>
    :root {
      --brand-color: <?= escapeAttr($site_settings['brandColor']) ?>;
      --brand-color-dark: <?= escapeAttr($brandColorDark) ?>;
      --accent-color: <?= escapeAttr($site_settings['accentColor']) ?>;
      --accent-color-dark: <?= escapeAttr($accentColorDark) ?>;
    }
  </style>

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
<body class="player-page">
  <a class="skip-link" href="#playerCinema">Skip to player</a>
  <?php
    $noscriptUrl = !empty($videoId)
      ? 'https://archive.org/details/' . rawurlencode($videoId)
      : 'https://archive.org/details/movies';
  ?>
  <noscript>
    <div class="noscript-banner" role="alert">
      <h2>JavaScript is required to play videos</h2>
      <p>This player streams films directly from the Internet Archive and needs JavaScript to run. Please enable JavaScript and reload the page.</p>
      <p>You can also watch this title directly at <a href="<?= htmlspecialchars($noscriptUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">archive.org</a>.</p>
    </div>
  </noscript>

  <!-- Player Header -->
  <header class="player-header" id="playerHeader">
    <div class="player-header-content">
      <a href="index.php" class="player-back-btn" title="Back to search" aria-label="Back to search">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M19 12H5M5 12L12 19M5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span class="player-back-text">Back to search</span>
      </a>

      <a href="index.php" class="player-logo" title="Go to homepage">
        <div class="player-logo-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 8L4 16C4 17.1046 4.89543 18 6 18L18 18C19.1046 18 20 17.1046 20 16V8C20 6.89543 19.1046 6 18 6L6 6C4.89543 6 4 6.89543 4 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M10 12L14 12M12 10L12 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <span class="player-logo-text"><?= escapeAttr($site_settings['siteName']) ?></span>
      </a>

      <div class="player-header-actions">
        <div class="header-auth" data-auth-nav></div>
        <button id="bookmarkBtn" class="player-action-btn" title="Bookmark" aria-label="Bookmark this video">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M5 5C5 3.89543 5.89543 3 7 3H17C18.1046 3 19 3.89543 19 5V21L12 17.5L5 21V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <button id="saveToCollectionBtn" class="player-action-btn" title="Save to collection" aria-label="Save to collection">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M4 6H20M4 12H14M4 18H14M18 15V21M15 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        <?php if ($site_settings['enableThemeToggle']): ?>
        <button id="themeToggle" class="player-action-btn theme-toggle" aria-label="Toggle theme" title="Toggle light/dark mode">
          <svg class="sun-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
          <svg class="moon-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
          </svg>
        </button>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Layout:
         .player-layout            one column, or [video column | playlist rail]
           .player-primary         cinema + everything below it
             .player-cinema
             .player-content
           .player-sidebar         playlist rail (shown only for multi-part items;
                                   JS adds body.has-playlist) -->
  <main class="player-main">
   <div class="player-layout" id="playerLayout">
   <div class="player-primary">
    <div class="player-cinema" id="playerCinema">
      <div id="videoWrapper" class="player-video-wrapper">
        <div id="playerLoader" class="player-loader" role="status" aria-busy="true">
          <div class="loading-spinner" aria-hidden="true">
            <div class="spinner-ring"></div>
          </div>
          <span class="loading-text">Loading video...</span>
        </div>
      </div>

      <!-- Controls Overlay Bar (bottom of cinema) -->
      <div class="player-controls-bar" id="controlsBar">
        <div class="controls-bar-left">
          <button id="prevEpisodeBtn" class="pctl-btn" title="Previous episode (Shift+P)" aria-label="Previous episode" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
          </button>
          <button id="nextEpisodeBtn" class="pctl-btn" title="Next episode (Shift+N)" aria-label="Next episode" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 18h2V6h-2zM6 18l8.5-6L6 6z"/></svg>
          </button>
          <span id="episodeIndicator" class="episode-indicator" style="display:none;"></span>
        </div>
        <div class="controls-bar-right">
          <div id="speedSelector" class="quality-selector">
            <button id="speedBtn" class="pctl-btn quality-btn" title="Playback speed" aria-label="Playback speed">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
              </svg>
              <span id="speedLabel" class="quality-label">1x</span>
            </button>
            <div id="speedMenu" class="quality-menu"></div>
          </div>
          <button id="captionsBtn" class="pctl-btn captions-btn" title="Subtitles / captions (c)" aria-label="Subtitles / captions" aria-pressed="false" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="2" y="5" width="20" height="14" rx="2"/>
              <path d="M7 15h3M14 15h3M7 11h3M14 11h3"/>
            </svg>
            <span class="captions-underline" aria-hidden="true"></span>
          </button>
          <button id="pipBtn" class="pctl-btn" title="Picture in picture (i)" aria-label="Picture in picture" style="display:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="2" y="4" width="20" height="14" rx="2"/>
              <rect x="12" y="10" width="8" height="6" rx="1" fill="currentColor"/>
            </svg>
          </button>
          <div id="qualitySelector" class="quality-selector" style="display:none;">
            <button id="qualityBtn" class="pctl-btn quality-btn" title="Video quality" aria-label="Video quality">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 15V17M12 7V13M8 3H16L21 8V16L16 21H8L3 16V8L8 3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span id="qualityLabel" class="quality-label">HD</span>
            </button>
            <div id="qualityMenu" class="quality-menu"></div>
          </div>
        </div>
      </div>

      <!-- Buffering indicator — appears mid-playback when the network stalls. -->
      <div id="bufferingIndicator" class="player-buffering" aria-hidden="true">
        <div class="loading-spinner" aria-hidden="true">
          <div class="spinner-ring"></div>
        </div>
      </div>

      <!-- Keyboard Shortcut Indicator (kept inside cinema so it shows in fullscreen) -->
      <div id="shortcutIndicator" class="shortcut-indicator" aria-hidden="true"></div>

      <!-- Up Next overlay (kept inside cinema so it shows in fullscreen + autoplay countdown) -->
      <div id="upNextOverlay" class="up-next-overlay" role="dialog" aria-label="Playing next">
        <div class="up-next-card">
          <div class="up-next-eyebrow">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polygon points="5 3 19 12 5 21 5 3"/>
            </svg>
            Up next
          </div>
          <div class="up-next-body">
            <div class="up-next-thumb">
              <img id="upNextThumb" src="" alt="" hidden />
            </div>
            <div class="up-next-info">
              <div id="upNextTitle" class="up-next-title">Next episode</div>
              <div id="upNextCountdown" class="up-next-countdown">Playing in 8…</div>
            </div>
          </div>
          <div class="up-next-actions">
            <button id="upNextCancel" type="button" class="up-next-btn up-next-btn-secondary">Cancel</button>
            <button id="upNextPlay" type="button" class="up-next-btn up-next-btn-primary">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M5 3L19 12L5 21V3Z"/>
              </svg>
              Play now
            </button>
          </div>
          <div class="up-next-progress" aria-hidden="true"><span></span></div>
        </div>
      </div>
    </div>

    <!-- Content Below Video -->
    <div class="player-content" id="playerContent">
      <div class="player-content-main">
        <!-- Video Info -->
        <section id="videoInfo" class="player-video-info">
          <h1 id="videoTitle" class="player-title">Loading...</h1>
          <div class="player-meta-row">
            <span id="videoCreator" class="player-creator"></span>
            <span id="videoDate" class="player-date"></span>
          </div>
          <div id="videoMetaPills" class="player-meta-pills" style="display:none;"></div>
          <div id="videoActions" class="player-video-actions">
            <a id="archiveLink" href="#" target="_blank" class="player-pill-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M18 13V19C18 20.1046 17.1046 21 16 21H5C3.89543 21 3 20.1046 3 19V8C3 6.89543 3.89543 6 5 6H11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 3H21V9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Archive.org
            </a>
            <button id="shareBtn" class="player-pill-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 12V20C4 21.1046 4.89543 22 6 22H18C19.1046 22 20 21.1046 20 20V12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="16 6 12 2 8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="12" y1="2" x2="12" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Share
            </button>
            <button id="downloadBtn" class="player-pill-btn">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L7 10M12 15L17 10M3 17V19C3 20.1046 3.89543 21 5 21H19C20.1046 21 21 20.1046 21 19V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              Download
            </button>
            <button id="reportBtn" class="player-pill-btn" title="Report this video to Archive.org">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
              Report
            </button>
          </div>
          <div id="videoTagsRow" class="player-tags-row" style="display:none;"></div>
        </section>

        <!-- Description -->
        <section id="descriptionSection" class="player-description-section" style="display: none;">
          <button id="descriptionToggle" class="player-description-toggle">
            <span>Description</span>
            <svg class="toggle-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 9L12 15L18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <div id="descriptionContent" class="player-description-content"></div>
        </section>

        <!-- Comments (members-only, site-local — never posted to archive.org) -->
        <section id="commentsSection" class="player-comments-section" style="display: none;" aria-label="Member comments"></section>

        <!-- Downloads Panel -->
        <section id="downloadsPanel" class="player-downloads-panel" style="display: none;">
          <div class="player-downloads-header">
            <h3>Download Options</h3>
            <button id="closeDownloads" class="player-close-panel-btn">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
          <div id="downloadLinks" class="player-download-links"></div>
        </section>
      </div>
    </div><!-- /.player-content -->
   </div><!-- /.player-primary -->

      <!-- Sidebar: Playlist -->
      <aside id="playlistSidebar" class="player-sidebar" style="display: none;" data-density="comfortable">
        <div class="player-sidebar-header">
          <div class="sidebar-header-info">
            <h3 id="playlistTitle">Episodes</h3>
            <span id="playlistCount" class="player-sidebar-count"></span>
          </div>
          <div class="sidebar-header-nav">
            <button id="sidebarPrevBtn" class="sidebar-nav-btn" disabled title="Previous episode (Shift+P)" aria-label="Previous episode">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button id="sidebarNextBtn" class="sidebar-nav-btn" disabled title="Next episode (Shift+N)" aria-label="Next episode">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
        </div>
        <div id="playlistItems" class="player-sidebar-items"></div>
      </aside>
   </div><!-- /.player-layout -->
  </main>

  <!-- Keyboard Shortcuts Help (triggered by `?`) -->
  <div id="shortcutsHelp" class="shortcuts-help" role="dialog" aria-modal="true" aria-label="Keyboard shortcuts" hidden>
    <div class="shortcuts-help-panel">
      <div class="shortcuts-help-header">
        <h3>Keyboard shortcuts</h3>
        <button type="button" id="shortcutsHelpClose" class="shortcuts-help-close" aria-label="Close shortcuts">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>
      <div class="shortcuts-help-grid">
        <div class="shortcut-row"><kbd>Space</kbd><kbd>K</kbd><span>Play / pause</span></div>
        <div class="shortcut-row"><kbd>F</kbd><span>Fullscreen</span></div>
        <div class="shortcut-row"><kbd>T</kbd><span>Theater mode</span></div>
        <div class="shortcut-row"><kbd>I</kbd><span>Picture in picture</span></div>
        <div class="shortcut-row"><kbd>C</kbd><span>Subtitles / captions</span></div>
        <div class="shortcut-row"><kbd>M</kbd><span>Mute / unmute</span></div>
        <div class="shortcut-row"><kbd>J</kbd><span>Back 10s</span></div>
        <div class="shortcut-row"><kbd>L</kbd><span>Forward 10s</span></div>
        <div class="shortcut-row"><kbd>&larr;</kbd><kbd>&rarr;</kbd><span>Seek &plusmn;5s</span></div>
        <div class="shortcut-row"><kbd>&uarr;</kbd><kbd>&darr;</kbd><span>Volume</span></div>
        <div class="shortcut-row"><kbd>&lt;</kbd><kbd>&gt;</kbd><span>Slower / faster</span></div>
        <div class="shortcut-row"><kbd>Shift</kbd> + <kbd>N</kbd><span>Next episode</span></div>
        <div class="shortcut-row"><kbd>Shift</kbd> + <kbd>P</kbd><span>Previous episode</span></div>
        <div class="shortcut-row"><kbd>?</kbd><span>This menu</span></div>
      </div>
    </div>
  </div>

  <!-- Resume Prompt (non-blocking) -->
  <div id="resumePrompt" class="resume-prompt" role="dialog" aria-label="Resume playback" style="display: none;">
    <div class="resume-prompt-content">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/></svg>
      <span id="resumeText">Resume from 0:00?</span>
      <button id="resumeBtn" class="resume-btn">Resume</button>
      <button id="resumeDismiss" class="resume-dismiss" aria-label="Dismiss">&times;</button>
    </div>
  </div>

  <!-- Site Settings (JSON_HEX_TAG prevents `</script>` injection breakout) -->
  <script id="siteSettingsConfig" type="application/json"><?= json_encode($site_settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

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

      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        var savedTheme = localStorage.getItem('theme');
        if (!savedTheme || savedTheme === 'system') {
          setTheme(e.matches ? 'dark' : 'light');
        }
      });
    })();
  </script>

  <?php include __DIR__ . '/partials/footer.php'; ?>

  <script>
  /* App-load watchdog (non-module) — see index.php for rationale. player.js
     sets window.__afcReady as soon as it runs; if that hasn't happened within
     the window we reveal a recovery message instead of a blank player. */
  (function () {
    setTimeout(function () {
      if (window.__afcReady) return;
      var msg = "We couldn’t load the player. Check your connection and try again, or open this item on archive.org.";
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

  <script type="module" src="player.js"></script>
</body>
</html>
