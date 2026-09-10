/**
 * AuthNav component
 *
 * Renders the auth controls (Sign in / Sign up, or avatar dropdown) into
 * a mount point on pages that have `data-auth-nav` in the header.
 *
 * The same logic is used by any page that imports this module. Pages that
 * render auth state server-side (partials/header.php) don't need it —
 * this exists for pages like index.php and player.php which build their
 * headers without a PHP-side user lookup.
 *
 * Usage (in HTML):
 *   <div data-auth-nav></div>
 *
 * Usage (in JS):
 *   import { AuthNav } from './components/AuthNav.js';
 *   AuthNav.mount();
 */

import { AuthService } from '../services/AuthService.js';

function initial(user) {
  const name = (user && (user.display_name || user.username)) || '?';
  return String(name).charAt(0).toUpperCase();
}

function renderSignedOut() {
  return `
    <a href="login.php" class="header-auth-link">Sign in</a>
    <a href="register.php" class="header-auth-link header-auth-link--primary">Sign up</a>
  `;
}

/**
 * Neutral filler shown until the first /me.php round-trip settles. Without
 * it a signed-in user sees "Sign in / Sign up" flash for a beat on every
 * page load before the avatar swaps in.
 */
function renderPlaceholder() {
  return `<span class="header-auth-placeholder" aria-hidden="true"></span>`;
}

function renderSignedIn(user) {
  const name = user.display_name || user.username;
  const isAdmin = user.role === 'admin' || user.role === 'editor';
  return `
    <div class="header-auth-avatar" data-auth-menu>
      <button type="button" class="header-auth-avatar-btn" aria-haspopup="true" aria-expanded="false" data-auth-menu-toggle>
        <span class="header-auth-avatar-circle">${escapeHtml(initial(user))}</span>
        <span>${escapeHtml(name)}</span>
      </button>
      <div class="header-auth-menu" role="menu" data-auth-menu-panel>
        <div class="header-auth-menu-label">Signed in as</div>
        <div class="header-auth-menu-label" style="color:var(--color-text-primary); text-transform:none; letter-spacing:0;">
          ${escapeHtml(user.email || user.username)}
        </div>
        <div class="header-auth-menu-divider"></div>
        <a href="account.php" role="menuitem">Account</a>
        <a href="collections.php" role="menuitem">My collections</a>
        <a href="index.php" role="menuitem">Home</a>
        ${isAdmin ? `<a href="admin.php" role="menuitem">Admin</a>` : ''}
        <div class="header-auth-menu-divider"></div>
        <button type="button" role="menuitem" data-auth-logout>Sign out</button>
      </div>
    </div>
  `;
}

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Wire the avatar dropdown. Returns an unwire() that removes the
 * document-level listeners — render() runs on every auth change, and
 * without cleanup each pass stacked another click + keydown handler on
 * `document` for the life of the page.
 */
function wireMenu(root) {
  const toggle = root.querySelector('[data-auth-menu-toggle]');
  const panel = root.querySelector('[data-auth-menu-panel]');
  if (!toggle || !panel) return () => {};

  const close = () => {
    panel.removeAttribute('data-open');
    toggle.setAttribute('aria-expanded', 'false');
  };
  const open = () => {
    panel.setAttribute('data-open', 'true');
    toggle.setAttribute('aria-expanded', 'true');
  };

  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    panel.hasAttribute('data-open') ? close() : open();
  });

  const onDocClick = (e) => {
    if (!root.contains(e.target)) close();
  };
  const onDocKey = (e) => {
    if (e.key === 'Escape') close();
  };
  document.addEventListener('click', onDocClick);
  document.addEventListener('keydown', onDocKey);

  const logoutBtn = root.querySelector('[data-auth-logout]');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      try { await AuthService.logout(); }
      finally { window.location.href = 'index.php'; }
    });
  }

  return () => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onDocKey);
  };
}

export const AuthNav = {
  mount() {
    const mounts = document.querySelectorAll('[data-auth-nav]');
    if (!mounts.length) return;

    // Document-level listeners from the previous render, torn down before
    // the next one so they don't accumulate.
    let unwireAll = [];
    // Flips once the first fetchMe() resolves (or fails). Until then a
    // null user means "don't know yet", not "signed out".
    let settled = !!AuthService.getUser();

    const render = ({ user }) => {
      unwireAll.forEach(fn => { try { fn(); } catch (_) {} });
      unwireAll = [];

      mounts.forEach(mount => {
        if (!user && !settled) {
          mount.innerHTML = renderPlaceholder();
          return;
        }
        mount.innerHTML = user ? renderSignedIn(user) : renderSignedOut();
        if (user) unwireAll.push(wireMenu(mount));
      });
    };

    // Subscribe (fires immediately with cached state, may be null on first load)
    AuthService.onChange(render);

    // Kick off the fetch (no-op if already cached). Once it settles, render
    // whatever we now know — this is what replaces the placeholder with
    // "Sign in" for genuine guests.
    AuthService.fetchMe()
      .catch(() => {})
      .then(() => {
        settled = true;
        render({ user: AuthService.getUser() });
      });
  },
};

export default AuthNav;
