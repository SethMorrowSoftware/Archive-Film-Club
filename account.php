<?php
/**
 * Account settings page.
 *
 * Requires an authenticated (non-guest) user. Server-side redirects guests
 * to login.php?next=account.php.
 */

require_once __DIR__ . '/bootstrap.php';

$context = new UserContext();
$current = $context->current();

if (!$current || !empty($current['is_guest'])) {
    header('Location: login.php?next=account.php');
    exit;
}

// Basic stats
$db = Database::getInstance();
$bookmarkCount = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM user_bookmarks WHERE user_id = ?",
    [(int)$current['id']]
);
$historyCount = (int)$db->fetchColumn(
    "SELECT COUNT(*) FROM user_watch_history WHERE user_id = ?",
    [(int)$current['id']]
);

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
$currentUser = $current;

$roleLabel = ucfirst($current['role'] ?? 'viewer');
$createdAt = !empty($current['created_at']) ? date('M j, Y', strtotime($current['created_at'])) : '—';
$lastSeen = !empty($current['last_seen']) ? date('M j, Y g:i A', strtotime($current['last_seen'])) : '—';
$verified = !empty($current['email_verified_at']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($initialTheme, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <?php include __DIR__ . '/partials/head-common.php'; ?>
  <title>Account · <?= htmlspecialchars($site_settings['siteName'], ENT_QUOTES) ?></title>
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

  <main class="account-layout">
    <header class="account-hero">
      <div class="account-hero-avatar"><?= htmlspecialchars(strtoupper(mb_substr($current['display_name'] ?: $current['username'], 0, 1)), ENT_QUOTES) ?></div>
      <div class="account-hero-info">
        <h1><?= htmlspecialchars($current['display_name'] ?: $current['username'], ENT_QUOTES) ?></h1>
        <p>@<?= htmlspecialchars($current['username'], ENT_QUOTES) ?> &middot; <?= htmlspecialchars($roleLabel, ENT_QUOTES) ?></p>
      </div>
    </header>

    <section class="account-stats" aria-label="Account stats">
      <div class="account-stat">
        <div class="account-stat-label">Bookmarks</div>
        <div class="account-stat-value"><?= $bookmarkCount ?></div>
      </div>
      <div class="account-stat">
        <div class="account-stat-label">Watch history</div>
        <div class="account-stat-value"><?= $historyCount ?></div>
      </div>
      <div class="account-stat">
        <div class="account-stat-label">Member since</div>
        <div class="account-stat-value" style="font-size:var(--font-size-base); font-weight:var(--font-weight-semibold);"><?= htmlspecialchars($createdAt, ENT_QUOTES) ?></div>
      </div>
    </section>

    <section class="account-section">
      <h2>Profile</h2>
      <dl class="account-meta">
        <dt>Username</dt><dd><?= htmlspecialchars($current['username'], ENT_QUOTES) ?></dd>
        <dt>Display name</dt><dd><?= htmlspecialchars($current['display_name'] ?: $current['username'], ENT_QUOTES) ?></dd>
        <dt>Email</dt>
        <dd>
          <?= htmlspecialchars($current['email'] ?: '—', ENT_QUOTES) ?>
          <?php if ($verified): ?>
            <span class="badge" style="background:var(--color-success-bg); color:var(--color-success); margin-left:8px;">✓ Verified</span>
          <?php else: ?>
            <span class="badge" style="background:var(--color-warning-bg); color:var(--color-warning); margin-left:8px;">Unverified</span>
          <?php endif; ?>
        </dd>
        <dt>Role</dt><dd><?= htmlspecialchars($roleLabel, ENT_QUOTES) ?></dd>
        <dt>Last active</dt><dd><?= htmlspecialchars($lastSeen, ENT_QUOTES) ?></dd>
      </dl>
    </section>

    <section class="account-section">
      <h2>Update profile</h2>
      <div class="auth-alert auth-alert--error" data-profile-error role="alert"></div>
      <div class="auth-alert auth-alert--success" data-profile-success role="status"></div>

      <form class="auth-form" data-profile-form novalidate>
        <div class="auth-field">
          <label for="display_name">Display name</label>
          <input id="display_name" name="display_name" type="text" maxlength="100"
                 value="<?= htmlspecialchars($current['display_name'] ?: '', ENT_QUOTES) ?>">
        </div>
        <div class="auth-field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email"
                 value="<?= htmlspecialchars($current['email'] ?: '', ENT_QUOTES) ?>">
        </div>
        <button type="submit" class="auth-submit">Save changes</button>
      </form>
    </section>

    <section class="account-section">
      <h2>Change password</h2>
      <div class="auth-alert auth-alert--error" data-pw-error role="alert"></div>
      <div class="auth-alert auth-alert--success" data-pw-success role="status"></div>

      <form class="auth-form" data-password-form novalidate>
        <div class="auth-field auth-field-password">
          <label for="oldPassword">Current password</label>
          <input id="oldPassword" name="oldPassword" type="password" autocomplete="current-password" required>
          <button type="button" class="auth-password-toggle" data-password-toggle="oldPassword" aria-label="Show password" title="Show password">
            <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
        <div class="auth-field auth-field-password">
          <label for="newPassword">New password</label>
          <input id="newPassword" name="newPassword" type="password" autocomplete="new-password" minlength="8" required>
          <button type="button" class="auth-password-toggle" data-password-toggle="newPassword" aria-label="Show password" title="Show password">
            <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
          <div class="field-hint">At least 8 characters.</div>
        </div>
        <button type="submit" class="auth-submit">Change password</button>
      </form>
    </section>

    <section class="account-section account-danger">
      <h2>Sign out</h2>
      <p style="color:var(--color-text-secondary); font-size:var(--font-size-sm); margin-bottom:var(--space-4); line-height:1.6;">
        Sign out on this device. Your data stays on the server and will be available when you sign back in.
      </p>
      <button type="button" class="auth-submit" style="background:var(--color-error); box-shadow: 0 6px 18px color-mix(in srgb, var(--color-error) 35%, transparent);" data-logout-btn>
        Sign out
      </button>
    </section>
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

    function wire(formSelector, errorSelector, successSelector, submitHandler) {
      const form = document.querySelector(formSelector);
      const errorBox = document.querySelector(errorSelector);
      const successBox = document.querySelector(successSelector);
      if (!form) return;
      const submit = form.querySelector('button[type="submit"]');

      const clear = () => {
        errorBox.removeAttribute('data-visible');
        successBox.removeAttribute('data-visible');
      };
      const showErr = (m) => { errorBox.textContent = m; errorBox.setAttribute('data-visible', 'true'); };
      const showOk = (m) => { successBox.textContent = m; successBox.setAttribute('data-visible', 'true'); };

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clear();
        submit.disabled = true;
        try {
          const msg = await submitHandler(new FormData(form));
          showOk(msg || 'Saved');
        } catch (err) {
          showErr(err.message || 'Request failed');
        } finally {
          submit.disabled = false;
        }
      });
    }

    wire('[data-profile-form]', '[data-profile-error]', '[data-profile-success]', async (fd) => {
      await AuthService.updateProfile({
        display_name: String(fd.get('display_name') || '').trim(),
        email: String(fd.get('email') || '').trim(),
      });
      return 'Profile updated';
    });

    wire('[data-password-form]', '[data-pw-error]', '[data-pw-success]', async (fd) => {
      await AuthService.changePassword({
        oldPassword: String(fd.get('oldPassword') || ''),
        newPassword: String(fd.get('newPassword') || ''),
      });
      document.querySelector('[data-password-form]').reset();
      return 'Password changed';
    });

    document.querySelector('[data-logout-btn]')?.addEventListener('click', async () => {
      try {
        await AuthService.logout();
      } finally {
        window.location.href = 'index.php';
      }
    });
  </script>
  <?php include __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
