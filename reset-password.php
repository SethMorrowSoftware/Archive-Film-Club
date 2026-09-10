<?php
/**
 * Password reset page (landing page for links emailed out by
 * api/auth/forgot-password.php).
 *
 * Takes ?token=... from the query string and POSTs it alongside the new
 * password to /api/auth/reset-password.
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
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <meta name="referrer" content="no-referrer" />
  <?php include __DIR__ . '/partials/head-common.php'; ?>
  <title>Set new password · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
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
        <h1>Choose a new password</h1>
      </div>

      <?php if ($token === ''): ?>
        <div class="auth-alert auth-alert--error" data-visible="true">
          This reset link is missing a token. Please request a new reset email.
        </div>
        <div class="auth-links">
          <a href="forgot-password.php">Request new link</a>
        </div>
      <?php else: ?>
        <div class="auth-alert auth-alert--error" data-error role="alert"></div>
        <div class="auth-alert auth-alert--success" data-success role="status"></div>

        <form class="auth-form" data-reset-form novalidate>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
          <div class="auth-field auth-field-password">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
            <button type="button" class="auth-password-toggle" data-password-toggle="password" aria-label="Show password" title="Show password">
              <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
            <div class="field-hint">At least 8 characters.</div>
          </div>
          <div class="auth-field auth-field-password">
            <label for="confirm">Confirm password</label>
            <input id="confirm" name="confirm" type="password" autocomplete="new-password" minlength="8" required>
            <button type="button" class="auth-password-toggle" data-password-toggle="confirm" aria-label="Show password" title="Show password">
              <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <button type="submit" class="auth-submit">Set password</button>
        </form>

        <div class="auth-links">
          <a href="login.php">Back to sign in</a>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <?php if ($token !== ''): ?>
  <!-- Password visibility toggle -->
  <script>
    (function() {
      var toggles = document.querySelectorAll('[data-password-toggle]');
      toggles.forEach(function(btn) {
        btn.addEventListener('click', function() {
          var id = btn.getAttribute('data-password-toggle');
          var input = document.getElementById(id);
          if (!input) return;
          var revealed = input.type === 'text';
          input.type = revealed ? 'password' : 'text';
          btn.setAttribute('data-revealed', revealed ? 'false' : 'true');
          btn.setAttribute('aria-label', revealed ? 'Show password' : 'Hide password');
          btn.setAttribute('title', revealed ? 'Show password' : 'Hide password');
        });
      });
    })();
  </script>

  <script type="module">
    import { AuthService } from './src/js/services/AuthService.js';

    const form = document.querySelector('[data-reset-form]');
    const errorBox = document.querySelector('[data-error]');
    const successBox = document.querySelector('[data-success]');
    const submit = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      errorBox.removeAttribute('data-visible');
      successBox.removeAttribute('data-visible');

      const fd = new FormData(form);
      const password = String(fd.get('password') || '');
      const confirm = String(fd.get('confirm') || '');

      if (password !== confirm) {
        errorBox.textContent = "Passwords don't match.";
        errorBox.setAttribute('data-visible', 'true');
        return;
      }

      submit.disabled = true;
      try {
        await AuthService.resetPassword({
          token: String(fd.get('token') || ''),
          password,
        });
        successBox.textContent = 'Password set. Redirecting to sign in…';
        successBox.setAttribute('data-visible', 'true');
        setTimeout(() => { window.location.href = 'login.php'; }, 1200);
      } catch (err) {
        errorBox.textContent = err.message || 'Reset failed';
        errorBox.setAttribute('data-visible', 'true');
        submit.disabled = false;
      }
    });
  </script>
  <?php endif; ?>
</body>
</html>
