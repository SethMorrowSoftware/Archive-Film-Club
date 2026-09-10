<?php
/**
 * Forgot password page.
 * Collects email, POSTs to /api/auth/forgot-password.
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
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <?php include __DIR__ . '/partials/head-common.php'; ?>
  <title>Forgot password · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
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
        <h1>Reset your password</h1>
        <p>Enter your email and we'll send you a link to create a new password.</p>
      </div>

      <div class="auth-alert auth-alert--error" data-error role="alert"></div>
      <div class="auth-alert auth-alert--success" data-success role="status"></div>

      <form class="auth-form" data-forgot-form novalidate>
        <div class="auth-field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="email" required>
        </div>
        <button type="submit" class="auth-submit">Send reset link</button>
      </form>

      <div class="auth-links">
        <a href="login.php">Back to sign in</a>
      </div>
    </div>
  </main>

  <script type="module">
    import { AuthService } from './src/js/services/AuthService.js';

    const form = document.querySelector('[data-forgot-form]');
    const errorBox = document.querySelector('[data-error]');
    const successBox = document.querySelector('[data-success]');
    const submit = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      errorBox.removeAttribute('data-visible');
      successBox.removeAttribute('data-visible');
      submit.disabled = true;
      try {
        const fd = new FormData(form);
        const res = await AuthService.forgotPassword(String(fd.get('email') || '').trim());
        successBox.textContent = res.message || 'Check your email for a reset link.';
        successBox.setAttribute('data-visible', 'true');
        form.reset();
      } catch (err) {
        errorBox.textContent = err.message || 'Request failed';
        errorBox.setAttribute('data-visible', 'true');
      } finally {
        submit.disabled = false;
      }
    });
  </script>
</body>
</html>
