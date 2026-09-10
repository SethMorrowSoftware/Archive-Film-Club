<?php
/**
 * 404 / 403 error page. Wired up via ErrorDocument in .htaccess.
 *
 * Apache 2.4 doesn't preserve REQUEST_URI when invoking an ErrorDocument
 * via internal sub-request, so we read REDIRECT_URL to show the missing
 * path (escaped) when available.
 */
require_once __DIR__ . '/bootstrap.php';

// Make sure search engines drop the not-found URL, and respond with the
// right status code (Apache passes the original status via REDIRECT_STATUS).
$status = (int)($_SERVER['REDIRECT_STATUS'] ?? 404);
if ($status !== 404 && $status !== 403) {
    $status = 404;
}
http_response_code($status);

try {
    $settingsService = new SettingsService();
    $site_settings = array_merge(
        ['siteName' => 'Archive Film Club', 'brandColor' => '#ff0000', 'accentColor' => '#065fd4', 'defaultTheme' => 'dark'],
        $settingsService->getSettings() ?: []
    );
} catch (Throwable $e) {
    $site_settings = ['siteName' => 'Archive Film Club', 'brandColor' => '#ff0000', 'accentColor' => '#065fd4', 'defaultTheme' => 'dark'];
}
$initialTheme = ($site_settings['defaultTheme'] ?? 'dark') === 'system' ? 'dark' : $site_settings['defaultTheme'];

// Brand colours go inside a <style> block where HTML escaping is no
// protection; validate as hex (guarded in case bootstrap's helper is absent).
$brandColor = function_exists('afc_css_color')
    ? afc_css_color((string)($site_settings['brandColor'] ?? ''), '#ff0000')
    : (string)($site_settings['brandColor'] ?? '#ff0000');
$accentColor = function_exists('afc_css_color')
    ? afc_css_color((string)($site_settings['accentColor'] ?? ''), '#065fd4')
    : (string)($site_settings['accentColor'] ?? '#065fd4');

$missing = $_SERVER['REDIRECT_URL'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex" />
  <title>Not found · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('styles.css'), ENT_QUOTES) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('auth-styles.css'), ENT_QUOTES) ?>">
  <style>
    :root {
      --brand-color: <?= htmlspecialchars($brandColor, ENT_QUOTES) ?>;
      --accent-color: <?= htmlspecialchars($accentColor, ENT_QUOTES) ?>;
    }
    .err-wrap {
      min-height: 70vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 2rem 1rem;
    }
    .err-code {
      font-size: clamp(4rem, 14vw, 8rem);
      font-weight: 800;
      line-height: 1;
      margin: 0 0 .5rem;
      color: var(--brand-color, #ff0000);
    }
    .err-title { font-size: 1.4rem; margin: 0 0 .5rem; }
    .err-msg { color: var(--color-text-secondary, #8a8a92); max-width: 32rem; margin: 0 auto 1.25rem; }
    .err-actions { display: flex; gap: .75rem; flex-wrap: wrap; justify-content: center; }
  </style>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>
  <main class="err-wrap" role="main">
    <h1 class="err-code"><?= $status ?></h1>
    <h2 class="err-title"><?= $status === 403 ? 'You can\'t access this page' : 'We couldn\'t find that page' ?></h2>
    <p class="err-msg">
      <?php if ($missing !== ''): ?>
        The link <code><?= htmlspecialchars($missing, ENT_QUOTES) ?></code> doesn't lead anywhere we know about. It may have moved, or never existed.
      <?php else: ?>
        That page doesn't exist or has been moved.
      <?php endif; ?>
    </p>
    <div class="err-actions">
      <a href="index.php" class="btn btn-primary">Back to home</a>
      <button type="button" class="btn btn-secondary" id="errBackBtn">Go back</button>
    </div>
  </main>
  <script>
    (function() {
      var b = document.getElementById('errBackBtn');
      if (!b) return;
      b.addEventListener('click', function() {
        if (history.length > 1) history.back();
        else window.location.href = 'index.php';
      });
    })();
  </script>
</body>
</html>
