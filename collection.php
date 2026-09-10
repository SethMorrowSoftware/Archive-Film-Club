<?php
/**
 * collection.php — view a single collection.
 *
 * Two access modes:
 *   ?id=N             → owner view (requires auth, loads owner's own collection)
 *   ?u=user&s=slug    → public shareable view (anyone, collection must be public)
 *
 * Server-renders the initial payload so the page is crawlable and has
 * no flash of empty state.
 */

require_once __DIR__ . '/bootstrap.php';

$service = new CollectionService();
$context = new UserContext();
$current = $context->current();
$currentUser = (!empty($current) && empty($current['is_guest'])) ? $current : null;

$collection = null;
$items = [];
$ownerMode = false;
$owner = null;
$notFound = false;

if (isset($_GET['id'])) {
    if (!$currentUser) {
        header('Location: login.php?next=' . urlencode('collection.php?id=' . (int)$_GET['id']));
        exit;
    }
    $collection = $service->getForUser((int)$currentUser['id'], (int)$_GET['id']);
    if ($collection) {
        $items = $service->getItems((int)$collection['id']);
        $ownerMode = true;
        $owner = [
            'username' => $currentUser['username'],
            'display_name' => $currentUser['display_name'] ?: $currentUser['username'],
        ];
    } else {
        $notFound = true;
    }
} elseif (isset($_GET['u']) && isset($_GET['s'])) {
    $collection = $service->getPublicBySlug((string)$_GET['u'], (string)$_GET['s']);
    if ($collection) {
        $items = $service->getItems((int)$collection['id']);
        $service->trackView((int)$collection['id']);
        $owner = [
            'username' => $collection['owner_username'],
            'display_name' => $collection['owner_display_name'] ?: $collection['owner_username'],
        ];
        $ownerMode = $currentUser
            && (int)$currentUser['id'] === (int)$collection['user_id'];
    } else {
        $notFound = true;
    }
} else {
    $notFound = true;
}

if ($notFound) {
    // Real 404 status — otherwise crawlers index the error page as a 200.
    http_response_code(404);
}

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

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pageTitle = $collection
    ? $collection['name'] . ' · ' . $site_settings['siteName']
    : 'Collection · ' . $site_settings['siteName'];

$ogDescription = $collection && $collection['description']
    ? mb_substr((string)$collection['description'], 0, 200)
    : 'A curated collection on ' . $site_settings['siteName'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($initialTheme) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <?php include __DIR__ . '/partials/head-common.php'; ?>
  <title><?= esc($pageTitle) ?></title>
  <meta name="description" content="<?= esc($ogDescription) ?>">

  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= esc($collection['name'] ?? 'Collection') ?>">
  <meta property="og:description" content="<?= esc($ogDescription) ?>">
  <?php if ($collection && $collection['cover_thumbnail']): ?>
    <meta property="og:image" content="<?= esc($collection['cover_thumbnail']) ?>">
  <?php endif; ?>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@400;500;600;700&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="<?= esc(asset_url('styles.css')) ?>">
  <link rel="stylesheet" href="<?= esc(asset_url('auth-styles.css')) ?>">
  <style>
    :root {
      --brand-color: <?= esc($brandColor) ?>;
      --accent-color: <?= esc($accentColor) ?>;
    }
  </style>
  <script>
    (function() {
      var saved = localStorage.getItem('theme');
      var theme = saved || '<?= esc($initialTheme) ?>';
      if (theme === 'system') theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme', theme);
    })();
  </script>
</head>
<body>
  <?php include __DIR__ . '/partials/header.php'; ?>

  <main class="collection-page">
    <?php if ($notFound): ?>
      <div class="auth-alert auth-alert--error" data-visible="true">
        This collection doesn't exist or isn't public.
      </div>
      <p><a href="collections.php">Back to your collections</a></p>
    <?php else: ?>

      <?php
        // CSS-injection-safe URL helper. htmlspecialchars(ENT_QUOTES) is
        // decoded by the HTML parser BEFORE the CSS parser sees the value,
        // so a `'` inside the URL would break out of a single-quoted CSS
        // url('...'). We additionally restrict to URLs whose chars can
        // safely sit inside url("...") with no further escaping.
        $coverThumb = '';
        if (!empty($collection['cover_thumbnail'])
            && is_string($collection['cover_thumbnail'])
            && preg_match('#^https?://[^\s"\'\\\\<>)]+$#i', $collection['cover_thumbnail'])) {
            $coverThumb = $collection['cover_thumbnail'];
        }
      ?>
      <header class="collection-page-header">
        <?php if ($coverThumb): ?>
          <div class="collection-page-cover" data-cover="<?= esc($coverThumb) ?>"></div>
        <?php else: ?>
          <div class="collection-page-cover"></div>
        <?php endif; ?>

        <div style="flex:1; min-width:0;">
          <h1 class="collection-page-title"><?= esc($collection['name']) ?></h1>
          <p class="collection-page-meta">
            by <?= esc($owner['display_name']) ?>
            &middot; <?= (int)$collection['item_count'] ?> video<?= (int)$collection['item_count'] === 1 ? '' : 's' ?>
            <?php if ($collection['is_public']): ?>
              &middot; <span style="color:var(--color-success);">Public</span>
              &middot; <?= (int)$collection['view_count'] ?> view<?= (int)$collection['view_count'] === 1 ? '' : 's' ?>
            <?php else: ?>
              &middot; <span style="color:var(--color-text-tertiary);">Private</span>
            <?php endif; ?>
          </p>
          <?php if ($collection['description']): ?>
            <p class="collection-page-description"><?= esc($collection['description']) ?></p>
          <?php endif; ?>

          <?php if ($ownerMode): ?>
            <div style="margin-top:var(--space-3); display:flex; gap:var(--space-2); flex-wrap:wrap;">
              <button type="button" class="btn btn-secondary" data-edit-btn>Edit</button>
              <button type="button" class="btn btn-secondary" data-share-btn>Copy share link</button>
              <button type="button" class="btn btn-secondary" style="color:var(--color-error);" data-delete-btn>Delete collection</button>
            </div>
          <?php elseif ($collection['is_public']): ?>
            <div style="margin-top:var(--space-3);">
              <button type="button" class="btn btn-secondary" data-share-btn>Copy share link</button>
            </div>
          <?php endif; ?>
        </div>
      </header>

      <?php if (!$items): ?>
        <div class="collection-page-meta" style="text-align:center; padding:var(--space-8);">
          <?php if ($ownerMode): ?>
            This collection is empty. Open any video and tap "Save to collection" to add it.
          <?php else: ?>
            This collection is empty.
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="collection-grid">
          <?php foreach ($items as $item): ?>
            <?php
              // Validate stored thumbnail URL; otherwise fall back to the
              // archive.org default. See CSS-injection note above.
              $rawThumb = $item['thumbnail'] ?? '';
              if (is_string($rawThumb) && preg_match('#^https?://[^\s"\'\\\\<>)]+$#i', $rawThumb)) {
                $thumbnail = $rawThumb;
              } else {
                $thumbnail = 'https://archive.org/services/img/' . urlencode($item['id']);
              }
              $playerUrl = 'player.php?video=' . urlencode($item['id']);
            ?>
            <?php
              // The <a> IS the card (display:contents on a link drops it
              // from some accessibility trees). In owner mode a wrapper
              // carries the Remove button alongside, since a button can't
              // sit inside a link.
            ?>
            <div class="collection-card-item">
              <a class="collection-card" href="<?= esc($playerUrl) ?>">
                <div class="collection-card-cover" data-cover="<?= esc($thumbnail) ?>"></div>
                <div class="collection-card-body">
                  <h3 class="collection-card-name"><?= esc($item['title'] ?: $item['id']) ?></h3>
                  <div class="collection-card-meta">
                    <?= esc($item['creator'] ?: '') ?>
                  </div>
                </div>
              </a>
              <?php if ($ownerMode): ?>
                <button type="button" class="btn btn-ghost collection-card-remove"
                        data-remove-item
                        data-archive-id="<?= esc($item['id']) ?>">
                  Remove
                </button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </main>

  <script>
    // Materialize data-cover attributes into background-image styles.
    // Done in JS instead of inline CSS to dodge CSS-injection via URLs
    // containing quotes (htmlspecialchars decodes inside an HTML attribute
    // BEFORE the CSS parser runs).
    (function(){
      var nodes = document.querySelectorAll('[data-cover]');
      for (var i = 0; i < nodes.length; i++) {
        var url = nodes[i].getAttribute('data-cover');
        if (!url) continue;
        // setProperty with a JS string can't escape the CSS context.
        nodes[i].style.backgroundImage = 'url("' + url.replace(/"/g, '%22') + '")';
      }
    })();
  </script>

  <?php if ($collection): ?>
  <script type="module">
    import { CollectionService } from './src/js/services/CollectionService.js';
    import { Toast } from './src/js/components/Toast.js';
    import { trapFocus } from './src/js/utils/focusTrap.js';

    const COLLECTION_ID = <?= (int)$collection['id'] ?>;
    const OWNER_MODE = <?= $ownerMode ? 'true' : 'false' ?>;
    const IS_PUBLIC = <?= $collection['is_public'] ? 'true' : 'false' ?>;
    <?php
      // Compute install base path so share URLs work in subdirectory deployments.
      $installBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    ?>
    const SHARE_URL = <?= json_encode($collection['is_public']
        ? $installBase . '/collection.php?u=' . urlencode($owner['username']) . '&s=' . urlencode($collection['slug'])
        : null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // ---- Modal helpers (replacement for alert/confirm/prompt) ----
    // buildModal wires the focus trap (Tab cycling, scroll lock, focus
    // restore) and the Escape listener, and hands back overlay.destroy()
    // which tears ALL of it down. Every close path must go through
    // destroy(): the Escape listener used to be removed only when Escape
    // itself was the exit, so each button/backdrop close leaked one.
    function buildModal(html, { initialFocus = null, onEscape = null } = {}) {
      const overlay = document.createElement('div');
      overlay.className = 'afc-modal';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.innerHTML = `<div class="afc-modal-card" tabindex="-1">${html}</div>`;
      document.body.appendChild(overlay);

      const release = trapFocus(overlay, {
        initialFocus: initialFocus ? overlay.querySelector(initialFocus) : null,
      });
      const onKey = (e) => { if (e.key === 'Escape' && onEscape) onEscape(); };
      document.addEventListener('keydown', onKey);

      overlay.destroy = () => {
        document.removeEventListener('keydown', onKey);
        release();
        overlay.remove();
      };
      return overlay;
    }

    function escapeHtml(s) {
      return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[c]));
    }

    function showConfirm({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false }) {
      return new Promise(resolve => {
        const overlay = buildModal(`
          <h2 class="afc-modal-title">${escapeHtml(title)}</h2>
          <p class="afc-modal-message">${escapeHtml(message)}</p>
          <div class="afc-modal-actions">
            <button type="button" class="btn btn-secondary" data-cancel>${escapeHtml(cancelLabel)}</button>
            <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-confirm>${escapeHtml(confirmLabel)}</button>
          </div>
        `, { initialFocus: '[data-confirm]', onEscape: () => close(false) });
        const close = (val) => { overlay.destroy(); resolve(val); };
        overlay.querySelector('[data-confirm]').addEventListener('click', () => close(true));
        overlay.querySelector('[data-cancel]').addEventListener('click', () => close(false));
        overlay.addEventListener('click', e => { if (e.target === overlay) close(false); });
      });
    }

    function showEditCollection({ name, isPublic }) {
      return new Promise(resolve => {
        const overlay = buildModal(`
          <h2 class="afc-modal-title">Edit collection</h2>
          <label class="afc-modal-message" for="afcEditName" style="display:block;">Collection name</label>
          <input id="afcEditName" type="text" class="afc-modal-input" value="${escapeHtml(name)}" maxlength="150" autocomplete="off" />
          <label class="afc-modal-checkbox">
            <input id="afcEditPublic" type="checkbox" ${isPublic ? 'checked' : ''} />
            <span>Make this collection public (shareable via link)</span>
          </label>
          <div class="afc-modal-actions">
            <button type="button" class="btn btn-secondary" data-cancel>Cancel</button>
            <button type="button" class="btn btn-primary" data-save>Save changes</button>
          </div>
        `, { initialFocus: '#afcEditName', onEscape: () => close(null) });
        const nameInput = overlay.querySelector('#afcEditName');
        const publicInput = overlay.querySelector('#afcEditPublic');
        const close = (val) => { overlay.destroy(); resolve(val); };
        overlay.querySelector('[data-save]').addEventListener('click', () => {
          const newName = nameInput.value.trim();
          if (!newName) {
            nameInput.focus();
            nameInput.setAttribute('aria-invalid', 'true');
            return;
          }
          close({ name: newName, is_public: publicInput.checked });
        });
        overlay.querySelector('[data-cancel]').addEventListener('click', () => close(null));
        overlay.addEventListener('click', e => { if (e.target === overlay) close(null); });
        setTimeout(() => { nameInput.focus(); nameInput.select(); }, 0);
      });
    }

    function showShareLink(url) {
      const overlay = buildModal(`
        <h2 class="afc-modal-title">Share this collection</h2>
        <p class="afc-modal-message">Copy the link below and share it anywhere.</p>
        <input type="text" class="afc-modal-input" value="${escapeHtml(url)}" readonly />
        <div class="afc-modal-actions">
          <button type="button" class="btn btn-primary" data-close>Close</button>
        </div>
      `, { initialFocus: 'input', onEscape: () => overlay.destroy() });
      overlay.querySelector('input').select();
      overlay.querySelector('[data-close]').addEventListener('click', () => overlay.destroy());
      overlay.addEventListener('click', e => { if (e.target === overlay) overlay.destroy(); });
    }

    // ---- Share button ----
    document.querySelector('[data-share-btn]')?.addEventListener('click', async () => {
      if (!SHARE_URL) {
        Toast.info('This collection is private. Make it public to share.');
        return;
      }
      const url = window.location.origin + SHARE_URL;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(url);
          Toast.success('Share link copied to clipboard');
          return;
        }
      } catch (_) { /* fall through */ }
      showShareLink(url);
    });

    if (OWNER_MODE) {
      // Delete
      document.querySelector('[data-delete-btn]')?.addEventListener('click', async () => {
        const ok = await showConfirm({
          title: 'Delete this collection?',
          message: 'This will permanently remove the collection and all its items. This cannot be undone.',
          confirmLabel: 'Delete',
          cancelLabel: 'Keep it',
          danger: true,
        });
        if (!ok) return;
        try {
          await CollectionService.delete(COLLECTION_ID);
          window.location.href = 'collections.php';
        } catch (err) {
          Toast.error(err.message || 'Delete failed');
        }
      });

      // Edit (name + public toggle)
      document.querySelector('[data-edit-btn]')?.addEventListener('click', async () => {
        const currentName = <?= json_encode($collection['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const result = await showEditCollection({ name: currentName, isPublic: IS_PUBLIC });
        if (!result) return;
        try {
          await CollectionService.update(COLLECTION_ID, result);
          window.location.reload();
        } catch (err) {
          Toast.error(err.message || 'Update failed');
        }
      });

      // Remove item
      document.querySelectorAll('[data-remove-item]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          e.stopPropagation();
          e.preventDefault();
          const archiveId = btn.dataset.archiveId;
          const ok = await showConfirm({
            title: 'Remove this video?',
            message: 'It will be removed from this collection. The video itself stays on Archive.org.',
            confirmLabel: 'Remove',
            cancelLabel: 'Keep',
            danger: true,
          });
          if (!ok) return;
          try {
            await CollectionService.removeItem(COLLECTION_ID, archiveId);
            btn.closest('.collection-card').remove();
            Toast.success('Removed from collection');
          } catch (err) {
            Toast.error(err.message || 'Remove failed');
          }
        });
      });
    }
  </script>
  <?php endif; ?>
  <?php include __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
