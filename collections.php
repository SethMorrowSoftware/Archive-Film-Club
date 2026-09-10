<?php
/**
 * collections.php — the current user's collections dashboard.
 *
 * Server-renders the initial list so there's no flash of empty state,
 * then hands interactivity over to a small inline script for
 * create/delete/edit via CollectionService.js.
 */

require_once __DIR__ . '/bootstrap.php';

$context = new UserContext();
$current = $context->current();

if (!$current || !empty($current['is_guest'])) {
    header('Location: login.php?next=collections.php');
    exit;
}

$service = new CollectionService();
$collections = $service->listForUser((int)$current['id']);

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

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= esc($initialTheme) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="robots" content="noindex,nofollow" />
  <?php include __DIR__ . '/partials/head-common.php'; ?>
  <title>My Collections · <?= esc($site_settings['siteName']) ?></title>
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
    <div class="collection-page-header" style="flex-direction:column; gap:var(--space-2);">
      <h1 class="collection-page-title">My Collections</h1>
      <p class="collection-page-meta">
        Curate your own themed lists of videos and share the public ones with friends.
      </p>
    </div>

    <section class="account-section" style="margin-bottom:var(--space-6);">
      <h2>Create a new collection</h2>
      <div class="auth-alert auth-alert--error" data-create-error role="alert"></div>
      <form class="auth-form" data-create-form novalidate style="max-width:520px;">
        <div class="auth-field">
          <label for="new-name">Name</label>
          <input id="new-name" name="name" type="text" maxlength="150" required>
        </div>
        <div class="auth-field">
          <label for="new-description">Description <span style="color:var(--color-text-tertiary); font-weight:normal;">(optional)</span></label>
          <input id="new-description" name="description" type="text" maxlength="2000">
        </div>
        <label class="auth-checkbox">
          <input type="checkbox" name="is_public" value="1">
          Make this collection public (shareable)
        </label>
        <button type="submit" class="auth-submit">Create collection</button>
      </form>
    </section>

    <section>
      <h2 style="color:var(--color-text-primary); font-size:var(--font-size-xl); margin-bottom:var(--space-4);">
        Your collections
      </h2>
      <div id="collectionsGrid" class="collection-grid">
        <?php if (!$collections): ?>
          <p style="grid-column:1/-1; color:var(--color-text-secondary);">
            You haven't created any collections yet.
          </p>
        <?php else: ?>
          <?php foreach ($collections as $c): ?>
            <?php
              $publicUrl = $c['is_public']
                  ? 'collection.php?u=' . urlencode($currentUser['username']) . '&s=' . urlencode($c['slug'])
                  : null;
              $cover = 'og-default.png';
              if (!empty($c['cover_thumbnail'])
                  && is_string($c['cover_thumbnail'])
                  && preg_match('#^https?://[^\s"\'\\\\<>)]+$#i', $c['cover_thumbnail'])) {
                $cover = $c['cover_thumbnail'];
              }
            ?>
            <a class="collection-card" href="collection.php?id=<?= (int)$c['id'] ?>">
              <div class="collection-card-cover" data-cover="<?= esc($cover) ?>"></div>
              <div class="collection-card-body">
                <h3 class="collection-card-name"><?= esc($c['name']) ?></h3>
                <div class="collection-card-meta">
                  <?= (int)$c['item_count'] ?> item<?= (int)$c['item_count'] === 1 ? '' : 's' ?>
                  &middot; <?= $c['is_public'] ? 'Public' : 'Private' ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <script>
    // See collection.php for the rationale -- materialize background images
    // from data-cover in JS so CSS-injection via quotes in URLs is moot.
    (function(){
      var nodes = document.querySelectorAll('[data-cover]');
      for (var i = 0; i < nodes.length; i++) {
        var url = nodes[i].getAttribute('data-cover');
        if (!url) continue;
        nodes[i].style.backgroundImage = 'url("' + url.replace(/"/g, '%22') + '")';
      }
    })();
  </script>

  <script type="module">
    import { CollectionService } from './src/js/services/CollectionService.js';

    const form = document.querySelector('[data-create-form]');
    const errorBox = document.querySelector('[data-create-error]');
    const submit = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      errorBox.removeAttribute('data-visible');
      submit.disabled = true;
      try {
        const fd = new FormData(form);
        const collection = await CollectionService.create({
          name: String(fd.get('name') || '').trim(),
          description: String(fd.get('description') || '').trim(),
          is_public: fd.get('is_public') === '1',
        });
        window.location.href = `collection.php?id=${collection.id}`;
      } catch (err) {
        errorBox.textContent = err.message || 'Could not create collection';
        errorBox.setAttribute('data-visible', 'true');
        submit.disabled = false;
      }
    });
  </script>
  <?php include __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
