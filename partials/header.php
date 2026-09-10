<?php
/**
 * Shared site header partial.
 *
 * Expects:
 *   $site_settings (array) — resolved elsewhere (usually before include)
 *   $currentUser (array|null) — optional; if not provided, resolved via UserContext
 *   $hideSearch (bool) — optional; defaults to false
 *
 * Only renders header markup. The caller is still responsible for <html>, <head>,
 * stylesheet includes, etc.
 */

if (!isset($site_settings) || !is_array($site_settings)) {
    $site_settings = ['siteName' => 'Archive Film Club'];
}

if (!isset($currentUser)) {
    try {
        $ctx = new UserContext();
        $resolved = $ctx->current();
        $currentUser = (!empty($resolved) && empty($resolved['is_guest'])) ? $resolved : null;
    } catch (Throwable $e) {
        $currentUser = null;
    }
}

$hideSearch = $hideSearch ?? false;

$avatarInitial = '?';
if ($currentUser) {
    $name = $currentUser['display_name'] ?: $currentUser['username'];
    $avatarInitial = strtoupper(mb_substr($name, 0, 1));
}

// Safe escape helper (same behavior as index.php::escapeAttr)
if (!function_exists('escapeAttr')) {
    function escapeAttr($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
?>
<header class="site-header">
  <div class="header-content">
    <a href="index.php" class="logo-section" title="Go to homepage">
      <div class="logo-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M4 8L4 16C4 17.1046 4.89543 18 6 18L18 18C19.1046 18 20 17.1046 20 16V8C20 6.89543 19.1046 6 18 6L6 6C4.89543 6 4 6.89543 4 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <path d="M10 12L14 12M12 10L12 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>
      <span class="logo-text"><?= escapeAttr($site_settings['siteName'] ?? 'Archive Film Club') ?></span>
    </a>

    <?php if (!$hideSearch): ?>
    <form id="searchForm" class="header-search-form" role="search" action="index.php" method="get">
      <div class="header-search-input-wrapper">
        <input name="search" type="search" class="header-search-input" placeholder="Search videos, creators, collections..." autocomplete="off" aria-label="Search videos" />
      </div>
      <button type="submit" class="search-submit-btn" aria-label="Search">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M21 21L15 15M17 10C17 13.866 13.866 17 10 17C6.13401 17 3 13.866 3 10C3 6.13401 6.13401 3 10 3C13.866 3 17 6.13401 17 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
    </form>
    <?php endif; ?>

    <div class="header-end">
      <div class="header-auth">
        <?php if ($currentUser): ?>
          <div class="header-auth-avatar" data-auth-menu>
            <button type="button" class="header-auth-avatar-btn" aria-haspopup="true" aria-expanded="false" data-auth-menu-toggle>
              <span class="header-auth-avatar-circle"><?= escapeAttr($avatarInitial) ?></span>
              <span><?= escapeAttr($currentUser['display_name'] ?: $currentUser['username']) ?></span>
            </button>
            <div class="header-auth-menu" role="menu" data-auth-menu-panel>
              <div class="header-auth-menu-label">Signed in as</div>
              <div class="header-auth-menu-label" style="color:var(--color-text-primary); text-transform:none; letter-spacing:0;">
                <?= escapeAttr($currentUser['email'] ?: $currentUser['username']) ?>
              </div>
              <div class="header-auth-menu-divider"></div>
              <a href="account.php" role="menuitem">Account</a>
              <a href="collections.php" role="menuitem">My collections</a>
              <?php if (in_array($currentUser['role'] ?? '', ['admin', 'editor'], true)): ?>
                <a href="admin.php" role="menuitem">Admin</a>
              <?php endif; ?>
              <div class="header-auth-menu-divider"></div>
              <button type="button" role="menuitem" data-auth-logout>Sign out</button>
            </div>
          </div>
        <?php else: ?>
          <a href="login.php" class="header-auth-link">Sign in</a>
          <a href="register.php" class="header-auth-link header-auth-link--primary">Sign up</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>
<?php
// Service-worker registration shim.
//
// app.js and player.js also register 'sw.js' for the homepage + player,
// but auxiliary pages (login, register, account, collections, etc.) don't
// load those modules. Without this block, a user who cold-landed on, say,
// /login.php would leave without an installed SW. The browser de-dupes
// repeat registrations by URL, so it's safe to ship from multiple places.
//
// Path is deliberately relative ('sw.js'), NOT leading-slash: a
// subdirectory install (/films/) must register /films/sw.js so scope
// tracks the install dir. Do not change this to '/sw.js'.
?>
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function (err) {
        console.warn('[SW] Registration failed:', err);
      });
    });
  }

  // Wire the server-rendered auth menu (toggle + logout). Pages that mount
  // <AuthNav /> via JS get this wiring from there; pages that include this
  // partial directly (account.php, collections.php, auth pages) need it
  // here too -- without this the dropdown toggle and sign-out button
  // would be dead.
  (function() {
    var root = document.querySelector('[data-auth-menu]');
    if (!root) return;
    var toggle = root.querySelector('[data-auth-menu-toggle]');
    var panel = root.querySelector('[data-auth-menu-panel]');
    if (toggle && panel) {
      var close = function() {
        panel.removeAttribute('data-open');
        toggle.setAttribute('aria-expanded', 'false');
      };
      var open = function() {
        panel.setAttribute('data-open', 'true');
        toggle.setAttribute('aria-expanded', 'true');
      };
      toggle.addEventListener('click', function(e) {
        e.stopPropagation();
        panel.hasAttribute('data-open') ? close() : open();
      });
      document.addEventListener('click', function(e) {
        if (!root.contains(e.target)) close();
      });
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') close();
      });
    }

    var logoutBtn = root.querySelector('[data-auth-logout]');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') || '' : '';
        // Compute install base so subdirectory deployments work.
        var here = window.location.pathname.replace(/\/[^/]*$/, '/');
        logoutBtn.disabled = true;
        fetch(here + 'api/auth/logout.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: token ? { 'X-CSRF-Token': token } : {},
        }).then(function(res) {
          if (res.ok) {
            window.location.href = here + 'index.php';
            return;
          }
          // CSRF or session expired -- reload the page so the user gets
          // a fresh token and can try again. Silently redirecting to
          // index.php would leave the user signed in but believe they
          // logged out.
          logoutBtn.disabled = false;
          window.location.reload();
        }).catch(function() {
          // Network failure: leave the user where they are and re-enable
          // the button so they can retry.
          logoutBtn.disabled = false;
        });
      });
    }
  })();
</script>
