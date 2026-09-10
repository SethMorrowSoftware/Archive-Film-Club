/**
 * focusTrap — the modal keyboard contract in one place.
 *
 * aria-modal="true" promises assistive tech that nothing behind the dialog
 * is reachable; without a trap, Tab walks straight into the page behind and
 * the promise is a lie. Every modal on the browse side (share fallback,
 * collection picker, collection.php confirm/edit/share dialogs) used to
 * hand-roll a subset of this — or none of it — so they're consolidated here.
 *
 * Usage:
 *   const release = trapFocus(overlayEl, { initialFocus: inputEl });
 *   ...
 *   release(); // drops the keydown listener, unlocks scroll, restores focus
 *
 * `release()` is idempotent, so callers can wire it to every close path
 * (button, backdrop, Escape) without double-restoring.
 */

const FOCUSABLE_SELECTOR =
  'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

/** Tabbable elements currently visible inside `root`. */
export function focusableItems(root) {
  if (!root) return [];
  return Array.from(root.querySelectorAll(FOCUSABLE_SELECTOR))
    .filter((el) => !el.disabled && el.offsetParent !== null);
}

/**
 * Keep Tab / Shift+Tab cycling inside `root`, lock body scroll, and move
 * focus into the dialog. Returns a release function.
 *
 * @param {HTMLElement} root            The dialog / overlay element.
 * @param {Object}      [opts]
 * @param {HTMLElement} [opts.initialFocus]   Element to focus on open
 *                                            (defaults to the first tabbable).
 * @param {boolean}     [opts.restoreFocus=true]  Return focus to the opener.
 * @param {boolean}     [opts.lockScroll=true]    Set body overflow: hidden.
 */
export function trapFocus(root, { initialFocus = null, restoreFocus = true, lockScroll = true } = {}) {
  if (!root) return () => {};

  const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  const prevOverflow = document.body.style.overflow;
  if (lockScroll) document.body.style.overflow = 'hidden';

  const onKey = (e) => {
    if (e.key !== 'Tab') return;
    const items = focusableItems(root);
    if (!items.length) {
      e.preventDefault();
      return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;
    if (e.shiftKey && (active === first || !root.contains(active))) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && (active === last || !root.contains(active))) {
      e.preventDefault();
      first.focus();
    }
  };
  document.addEventListener('keydown', onKey);

  const target = initialFocus || focusableItems(root)[0] || null;
  if (target) target.focus();

  let released = false;
  return function release() {
    if (released) return;
    released = true;
    document.removeEventListener('keydown', onKey);
    if (lockScroll) document.body.style.overflow = prevOverflow;
    if (restoreFocus && opener && document.contains(opener)) {
      opener.focus();
    }
  };
}

export default trapFocus;
