<?php
/**
 * Common <head> snippet — print the CSRF meta tag and the global JS
 * config needed by the early-loading scripts. Include this inside <head>
 * on every page that loads app/admin/auth JS.
 *
 * Caller is responsible for everything else (title, OG tags, stylesheets).
 */
if (function_exists('csrf_meta_tag')) {
    echo csrf_meta_tag() . "\n";
}
?>
<script>
  // First-load disclaimer modal. Self-contained so it works on every page
  // that includes this partial (index, player, login, register, admin,
  // collection(s), account, password flows). Bump the storage key when the
  // wording materially changes so returning users see the new version.
  (function () {
    var STORAGE_KEY = 'afc_disclaimer_ack_v1';
    try {
      if (localStorage.getItem(STORAGE_KEY)) return;
    } catch (e) {
      // localStorage blocked (private mode / disabled cookies). Showing the
      // modal every load is the safer default than silently skipping it.
    }

    function build() {
      if (document.getElementById('afcDisclaimer')) return;
      var overlay = document.createElement('div');
      overlay.id = 'afcDisclaimer';
      overlay.className = 'afc-disclaimer-overlay';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-labelledby', 'afcDisclaimerTitle');
      overlay.innerHTML = ''
        + '<div class="afc-disclaimer">'
        +   '<div class="afc-disclaimer-icon" aria-hidden="true">'
        +     '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        +       '<circle cx="12" cy="12" r="10"/>'
        +       '<line x1="12" y1="8" x2="12" y2="12"/>'
        +       '<line x1="12" y1="16" x2="12.01" y2="16"/>'
        +     '</svg>'
        +   '</div>'
        +   '<h2 id="afcDisclaimerTitle" class="afc-disclaimer-title">Before you watch</h2>'
        +   '<div class="afc-disclaimer-body">'
        +     '<p>This site is an independent front-end for the public collection at '
        +       '<a href="https://archive.org" target="_blank" rel="noopener">archive.org</a>. '
        +       '<strong>No videos are hosted here</strong> &mdash; every title is streamed '
        +       'directly from the Internet Archive.</p>'
        +     '<p>If you encounter inappropriate, illegal, or infringing material, please report '
        +       'it directly to the Internet Archive. They are the only party able to remove or '
        +       'moderate the content.</p>'
        +     '<div class="afc-disclaimer-links">'
        +       '<a href="https://help.archive.org/help/problems-or-errors/" target="_blank" rel="noopener">Report a problem &rarr;</a>'
        +       '<a href="https://help.archive.org/help/how-do-i-request-to-remove-something-from-archive-org/" target="_blank" rel="noopener">Request removal / DMCA &rarr;</a>'
        +       '<a href="https://archive.org/about/terms.php" target="_blank" rel="noopener">Archive.org Terms of Use &rarr;</a>'
        +     '</div>'
        +   '</div>'
        +   '<button type="button" class="afc-disclaimer-ack" data-disclaimer-ack>I understand</button>'
        + '</div>';

      var ack = overlay.querySelector('[data-disclaimer-ack]');

      // Modal contract: lock page scroll and keep Tab / Shift+Tab inside
      // the dialog until it's acknowledged. Deliberately NO Escape — this
      // one is meant to be read and dismissed explicitly. Self-contained
      // (classic script, no module import) because this partial runs on
      // every page, including ones that never load the app bundle.
      var prevOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';

      function focusables() {
        var nodes = overlay.querySelectorAll('a[href], button, [tabindex]:not([tabindex="-1"])');
        var out = [];
        for (var i = 0; i < nodes.length; i++) {
          if (!nodes[i].disabled && nodes[i].offsetParent !== null) out.push(nodes[i]);
        }
        return out;
      }
      function onKey(e) {
        if (e.key !== 'Tab') return;
        var items = focusables();
        if (!items.length) { e.preventDefault(); return; }
        var first = items[0];
        var last = items[items.length - 1];
        var active = document.activeElement;
        if (e.shiftKey && (active === first || !overlay.contains(active))) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && (active === last || !overlay.contains(active))) {
          e.preventDefault();
          first.focus();
        }
      }
      document.addEventListener('keydown', onKey);

      ack.addEventListener('click', function () {
        try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
        document.removeEventListener('keydown', onKey);
        document.body.style.overflow = prevOverflow;
        overlay.remove();
      });

      document.body.appendChild(overlay);
      // Focus the acknowledgment button so keyboard users can dismiss with Enter.
      setTimeout(function () { ack.focus(); }, 0);
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', build);
    } else {
      build();
    }
  })();
</script>
<?php
