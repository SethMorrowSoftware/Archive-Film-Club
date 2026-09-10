<?php
/**
 * Email verification landing page (link target for the verification
 * email sent by MailService::sendEmailVerification()).
 *
 * Takes ?token=... from the query string, hands it to
 * UserAuthService::completeEmailVerification(), and renders a friendly
 * confirmation.
 */

require_once __DIR__ . '/bootstrap.php';

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
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';

$state = 'missing'; // missing | success | failed
if ($token !== '') {
    try {
        $auth = new UserAuthService();
        $ok = $auth->completeEmailVerification($token);
        $state = $ok ? 'success' : 'failed';
    } catch (Throwable $e) {
        error_log('[verify-email] ' . $e->getMessage());
        $state = 'failed';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <meta name="referrer" content="no-referrer" />
  <?php include __DIR__ . '/partials/head-common.php'; ?>
  <title>Verify email · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('styles.css'), ENT_QUOTES) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('auth-styles.css'), ENT_QUOTES) ?>">
  <style>
    :root {
      --brand-color: <?= htmlspecialchars($brandColor, ENT_QUOTES) ?>;
      --accent-color: <?= htmlspecialchars($accentColor, ENT_QUOTES) ?>;
    }
  </style>
  <script>
    (function() {
      var saved = localStorage.getItem('theme');
      var theme = saved || '<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>';
      if (theme === 'system') theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-header">
        <h1>Email verification</h1>
      </div>

      <?php if ($state === 'success'): ?>
        <div class="auth-alert auth-alert--success" data-visible="true">
          Your email address has been verified. Thanks!
        </div>
        <div class="auth-links">
          <a href="index.php">Back to home</a>
        </div>
      <?php elseif ($state === 'failed'): ?>
        <div class="auth-alert auth-alert--error" data-visible="true">
          This verification link is invalid or has expired. Request a new one from your account page.
        </div>
        <div class="auth-links">
          <a href="account.php">Go to account</a>
        </div>
      <?php else: ?>
        <div class="auth-alert auth-alert--error" data-visible="true">
          This link is missing a verification token. Please use the link from your email.
        </div>
        <div class="auth-links">
          <a href="index.php">Back to home</a>
        </div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
