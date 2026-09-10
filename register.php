<?php
/**
 * Registration page.
 *
 * GET renders the form. The form POSTs to /api/auth/register.php via fetch().
 * If the visitor has guest data (bookmarks, history) we show a prompt asking
 * whether to merge it into the new account before submitting.
 */

require_once __DIR__ . '/bootstrap.php';

$context = new UserContext();
$current = $context->current();
if ($current && empty($current['is_guest'])) {
    header('Location: index.php');
    exit;
}

// Guest data preview, so we can show an honest merge prompt
$guestBookmarks = 0;
$guestHistory = 0;
if ($current && !empty($current['is_guest'])) {
    try {
        $db = Database::getInstance();
        $guestBookmarks = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM user_bookmarks WHERE user_id = ?",
            [(int)$current['id']]
        );
        $guestHistory = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM user_watch_history WHERE user_id = ?",
            [(int)$current['id']]
        );
    } catch (Throwable $e) {
        // Fine — prompt just won't appear
    }
}
$hasGuestData = ($guestBookmarks + $guestHistory) > 0;

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
  <title>Create account · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
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
      if (theme === 'system') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="auth-wrapper">
    <div class="auth-card">
      <div class="auth-header">
        <h1>Create your account</h1>
        <p>Keep your bookmarks and history synced across devices.</p>
      </div>

      <div class="auth-alert auth-alert--error" data-error role="alert"></div>

      <?php if ($hasGuestData): ?>
        <div class="auth-merge-box" data-merge-box>
          <strong>We noticed you have some saved data.</strong>
          <div>
            <?= $guestBookmarks ?> bookmark<?= $guestBookmarks === 1 ? '' : 's' ?>
            and <?= $guestHistory ?> video<?= $guestHistory === 1 ? '' : 's' ?> in watch history.
          </div>
          <label class="auth-checkbox" style="margin-top:12px;">
            <input type="checkbox" data-merge-checkbox checked>
            Move these into my new account
          </label>
        </div>
      <?php endif; ?>

      <form class="auth-form" data-register-form novalidate>
        <div class="auth-field">
          <label for="username">Username</label>
          <input id="username" name="username" type="text" autocomplete="username"
                 minlength="3" maxlength="50" required
                 pattern="[a-zA-Z0-9_\-]+">
          <div class="field-hint">Letters, numbers, underscore and hyphen only.</div>
        </div>

        <div class="auth-field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="email" required>
        </div>

        <div class="auth-field">
          <label for="display_name">Display name <span style="color:var(--color-text-tertiary); font-weight:normal;">(optional)</span></label>
          <input id="display_name" name="display_name" type="text" autocomplete="nickname" maxlength="100">
        </div>

        <div class="auth-field auth-field-password">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="new-password"
                 minlength="8" required>
          <button type="button" class="auth-password-toggle" data-password-toggle="password" aria-label="Show password" title="Show password">
            <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
          <div class="field-hint">At least 8 characters.</div>
          <div class="password-strength" data-password-strength aria-live="polite">
            <div class="password-strength-bar"><span data-strength-fill></span></div>
            <span class="password-strength-label" data-strength-label>Enter a password</span>
          </div>
        </div>

        <button type="submit" class="auth-submit">Create account</button>
      </form>

      <div class="auth-links">
        <span>Already have an account?</span>
        <a href="login.php">Sign in</a>
      </div>
    </div>
  </main>

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

  <!-- Password strength meter -->
  <script>
    (function() {
      var input = document.getElementById('password');
      var meter = document.querySelector('[data-password-strength]');
      if (!input || !meter) return;
      var fill = meter.querySelector('[data-strength-fill]');
      var label = meter.querySelector('[data-strength-label]');

      function score(pw) {
        if (!pw) return { value: 0, label: 'Enter a password', tone: 'idle' };
        var s = 0;
        if (pw.length >= 8) s++;
        if (pw.length >= 12) s++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++;
        if (/\d/.test(pw)) s++;
        if (/[^A-Za-z0-9]/.test(pw)) s++;
        if (pw.length < 8) {
          return { value: 1, label: 'Too short — needs 8+ characters', tone: 'weak' };
        }
        if (s <= 2) return { value: 2, label: 'Weak', tone: 'weak' };
        if (s === 3) return { value: 3, label: 'Fair', tone: 'fair' };
        if (s === 4) return { value: 4, label: 'Good', tone: 'good' };
        return { value: 5, label: 'Strong', tone: 'strong' };
      }

      input.addEventListener('input', function() {
        var r = score(input.value);
        var pct = (r.value / 5) * 100;
        fill.style.width = pct + '%';
        meter.setAttribute('data-tone', r.tone);
        label.textContent = r.label;
      });
    })();
  </script>

  <script type="module">
    import { AuthService } from './src/js/services/AuthService.js';

    const form = document.querySelector('[data-register-form]');
    const errorBox = document.querySelector('[data-error]');
    const submit = form.querySelector('button[type="submit"]');
    const mergeCheckbox = document.querySelector('[data-merge-checkbox]');

    function showError(msg) {
      errorBox.textContent = msg;
      errorBox.setAttribute('data-visible', 'true');
    }
    function showFieldErrors(errors) {
      const parts = [];
      if (errors && typeof errors === 'object') {
        for (const [field, msg] of Object.entries(errors)) {
          parts.push(`${field}: ${msg}`);
        }
      }
      showError(parts.length ? parts.join('  ') : 'Validation failed');
    }
    function clearError() {
      errorBox.removeAttribute('data-visible');
      errorBox.textContent = '';
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearError();
      submit.disabled = true;
      submit.textContent = 'Creating account…';

      const fd = new FormData(form);
      const payload = {
        username: String(fd.get('username') || '').trim(),
        email: String(fd.get('email') || '').trim(),
        display_name: String(fd.get('display_name') || '').trim(),
        password: String(fd.get('password') || ''),
        mergeGuest: mergeCheckbox ? mergeCheckbox.checked : true
      };

      try {
        await AuthService.register(payload);
        window.location.href = 'index.php';
      } catch (err) {
        if (err && err.errors) {
          showFieldErrors(err.errors);
        } else {
          showError(err.message || 'Registration failed');
        }
        submit.disabled = false;
        submit.textContent = 'Create account';
      }
    });
  </script>
</body>
</html>
