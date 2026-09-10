<?php
/**
 * Sign in page.
 *
 * GET renders the form. The form POSTs to /api/auth/login.php via fetch()
 * and the JS redirects on success.
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Whitelist a user-supplied "next" redirect target. Only same-origin
 * relative paths are allowed — anything that could redirect off-site
 * (protocol-relative //host, absolute https://, or backslashes that
 * some browsers treat as slashes) collapses to index.php.
 */
function afc_safe_next(?string $next): string {
    if ($next === null || $next === '') return 'index.php';
    // Strip whitespace that could trick some browsers.
    $next = trim($next);
    if ($next === '') return 'index.php';
    // Reject protocol-relative //host and absolute URLs.
    if (strpos($next, '//') === 0) return 'index.php';
    if (strpos($next, '\\\\') === 0) return 'index.php';
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $next)) return 'index.php';
    // Reject backslashes anywhere (some browsers normalize to /).
    if (strpos($next, '\\') !== false) return 'index.php';
    // Only allow URL-safe characters in paths/queries/fragments. Use `~`
    // as the delimiter so `#` can appear unescaped inside the character
    // class (for URL fragments).
    if (!preg_match('~^/?[A-Za-z0-9_\-./?=&\#%]+$~', $next)) return 'index.php';
    return $next;
}

// If already logged in, bounce to the "next" URL (or home)
$context = new UserContext();
$current = $context->current();
if ($current && empty($current['is_guest'])) {
    $next = afc_safe_next($_GET['next'] ?? null);
    header('Location: ' . $next);
    exit;
}

// Guest data preview, so we can show an honest merge prompt on login too
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
        // non-fatal
    }
}
$hasGuestData = ($guestBookmarks + $guestHistory) > 0;

// Load site settings for branding
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
  <title>Sign in · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
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
        <h1>Welcome back</h1>
        <p>Sign in to sync your bookmarks and watch history.</p>
      </div>

      <div class="auth-alert auth-alert--error" data-error role="alert"></div>

      <?php if ($hasGuestData): ?>
        <div class="auth-merge-box">
          <strong>We noticed you have some saved data on this device.</strong>
          <div>
            <?= $guestBookmarks ?> bookmark<?= $guestBookmarks === 1 ? '' : 's' ?>
            and <?= $guestHistory ?> video<?= $guestHistory === 1 ? '' : 's' ?> in watch history.
          </div>
          <label class="auth-checkbox" style="margin-top:12px;">
            <input type="checkbox" data-merge-checkbox checked>
            Move these into my account when I sign in
          </label>
        </div>
      <?php endif; ?>

      <form class="auth-form" data-login-form novalidate>
        <div class="auth-field">
          <label for="identifier">Username or email</label>
          <input id="identifier" name="identifier" type="text" autocomplete="username" required>
        </div>

        <div class="auth-field auth-field-password">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
          <button type="button" class="auth-password-toggle" data-password-toggle="password" aria-label="Show password" title="Show password">
            <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>

        <label class="auth-checkbox">
          <input type="checkbox" name="remember" value="1" checked>
          Remember me for 30 days
        </label>

        <button type="submit" class="auth-submit">Sign in</button>
      </form>

      <div class="auth-links">
        <a href="register.php">Create account</a>
        <a href="forgot-password.php">Forgot password?</a>
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

  <script type="module">
    import { AuthService } from './src/js/services/AuthService.js';

    const form = document.querySelector('[data-login-form]');
    const errorBox = document.querySelector('[data-error]');
    const submit = form.querySelector('button[type="submit"]');
    const mergeCheckbox = document.querySelector('[data-merge-checkbox]');

    function showError(msg) {
      errorBox.textContent = msg;
      errorBox.setAttribute('data-visible', 'true');
    }
    function clearError() {
      errorBox.removeAttribute('data-visible');
      errorBox.textContent = '';
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearError();
      submit.disabled = true;
      submit.textContent = 'Signing in…';

      try {
        const fd = new FormData(form);
        await AuthService.login({
          identifier: String(fd.get('identifier') || '').trim(),
          password: String(fd.get('password') || ''),
          remember: fd.get('remember') === '1',
          mergeGuest: mergeCheckbox ? mergeCheckbox.checked : true,
        });

        // Redirect to ?next or index, but only if ?next is a safe
        // same-origin relative path (mirrors afc_safe_next() server-side
        // to prevent open-redirect attacks).
        const params = new URLSearchParams(window.location.search);
        const rawNext = params.get('next') || '';
        const safeNext = (function(n) {
          if (!n) return 'index.php';
          n = n.trim();
          if (!n) return 'index.php';
          if (n.startsWith('//') || n.startsWith('\\\\')) return 'index.php';
          if (/^[a-z][a-z0-9+.\-]*:/i.test(n)) return 'index.php';
          if (n.indexOf('\\') !== -1) return 'index.php';
          if (!/^\/?[a-z0-9_\-./?=&#%]+$/i.test(n)) return 'index.php';
          return n;
        })(rawNext);
        window.location.href = safeNext;
      } catch (err) {
        showError(err.message || 'Sign in failed');
        submit.disabled = false;
        submit.textContent = 'Sign in';
      }
    });
  </script>
</body>
</html>
