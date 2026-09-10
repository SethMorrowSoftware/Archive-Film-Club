/**
 * ContinueWatchingManager
 *
 * Renders the "Continue Watching" row on the homepage from
 * VideoProgressTracker. Cards behave like recommended/featured cards:
 *   - Click to resume on the player page (preserves saved position via
 *     the server-side resume prompt that runs on player load).
 *   - Hover reveals a small × button that removes just that entry.
 *   - "Clear all" header button wipes the whole row.
 *
 * Title/creator come from the progress entry first (we snapshot them
 * when saving progress, so a single render usually doesn't need a
 * network round-trip). If the snapshot is missing (older entries) we
 * fall back to a batched /api/metadata-batch.php fetch to fill in the
 * blanks before re-rendering.
 */

import { escapeHtml, getThumbnailUrl, extractValue } from '../utils/helpers.js';
import { ICONS } from '../utils/icons.js';

export class ContinueWatchingManager {
  constructor(app, progressTracker) {
    this.app = app;
    this.tracker = progressTracker;
    this.section = document.getElementById('continueWatchingSection');
    this.grid = document.getElementById('continueWatchingGrid');
    this.clearAllBtn = document.getElementById('clearContinueWatching');
    this.entries = [];

    if (this.clearAllBtn) {
      this.clearAllBtn.addEventListener('click', () => this.clearAll());
    }
  }

  init() {
    if (!this.section || !this.grid || !this.tracker) return;
    this.entries = this.tracker.getResumable({ limit: 12 });
    if (this.entries.length === 0) {
      this.section.style.display = 'none';
      return;
    }
    this.render();

    // Fill in missing titles in the background. Older progress rows
    // saved before the meta snapshot landed won't have a title.
    const missingIds = this.entries
      .filter(e => !e.title)
      .map(e => e.id);
    if (missingIds.length > 0) {
      this._fillMissingMetadata(missingIds);
    }
  }

  async _fillMissingMetadata(ids) {
    let map = {};
    try {
      const url = `api/metadata-batch.php?ids=${encodeURIComponent(ids.join(','))}`;
      const res = await fetch(url, { credentials: 'same-origin' });
      if (res.ok) {
        const payload = await res.json();
        map = payload?.data || {};
      }
    } catch (e) {
      // Non-fatal — cards just keep using their ID as the title.
      return;
    }
    let changed = false;
    for (const entry of this.entries) {
      if (entry.title) continue;
      const meta = map[entry.id];
      if (!meta) continue;
      entry.title = extractValue(meta.title) || null;
      entry.creator = extractValue(meta.creator) || null;
      changed = true;
      // Persist the freshly-resolved title back into the tracker so we
      // don't refetch every page load.
      try {
        this.tracker.saveProgress(
          entry.id,
          entry.currentTime,
          entry.duration,
          { title: entry.title, creator: entry.creator }
        );
      } catch (_) { /* localStorage quota — ignore */ }
    }
    if (changed) this.render();
  }

  render() {
    if (!this.grid || !this.section) return;
    if (this.entries.length === 0) {
      this.section.style.display = 'none';
      return;
    }
    this.section.style.display = 'block';
    this.grid.innerHTML = this.entries.map(e => this._renderCard(e)).join('');

    this.grid.querySelectorAll('.recommended-card').forEach(card => {
      card.addEventListener('click', (e) => {
        // Don't navigate when the user clicked the per-card remove button,
        // and let the title link handle its own clicks (middle-click etc).
        if (e.target.closest('[data-cw-remove]')) return;
        if (e.target.closest('a')) return;
        const id = card.dataset.identifier;
        if (id) this.app.navigateToPlayer(id);
      });
    });

    this.grid.querySelectorAll('[data-cw-remove]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        const id = btn.dataset.cwRemove;
        if (id) this.remove(id);
      });
    });
  }

  _renderCard(entry) {
    const title = entry.title || entry.id;
    const creator = entry.creator || '';
    const thumb = getThumbnailUrl(entry.id);
    const pct = Math.max(2, Math.min(98, entry.percentage || 0));
    const remaining = Math.max(0, (entry.duration || 0) - (entry.currentTime || 0));
    const remainingLabel = this._formatRemaining(remaining);
    const playerUrl = `player.php?video=${encodeURIComponent(entry.id)}`;

    // onerror only hides the <img> and flags the wrapper: replacing the
    // wrapper's innerHTML used to wipe the progress bar and remove button.
    return `
      <article class="recommended-card continue-watching-card" data-identifier="${escapeHtml(entry.id)}">
        <div class="recommended-card-thumb">
          <img src="${thumb}"
               alt="${escapeHtml(title)}"
               loading="lazy"
               decoding="async"
               onerror="this.style.display='none';this.parentNode.classList.add('thumb-missing')"/>
          ${remainingLabel ? `<span class="runtime-badge">${escapeHtml(remainingLabel)} left</span>` : ''}
          <div class="recommended-card-overlay">
            <span class="play-btn">${ICONS.play}</span>
          </div>
          <div class="continue-watching-progress" aria-hidden="true">
            <span style="width:${pct}%"></span>
          </div>
          <button type="button"
                  class="continue-watching-remove"
                  data-cw-remove="${escapeHtml(entry.id)}"
                  title="Remove from Continue Watching"
                  aria-label="Remove ${escapeHtml(title)} from Continue Watching">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
              <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="recommended-card-content">
          <h3 class="recommended-card-title"><a class="card-link" href="${playerUrl}">${escapeHtml(title)}</a></h3>
          ${creator ? `<p class="recommended-card-creator">${escapeHtml(creator)}</p>` : ''}
        </div>
      </article>
    `;
  }

  _formatRemaining(seconds) {
    if (!seconds || seconds < 1) return '';
    const s = Math.round(seconds);
    if (s < 60) return `${s}s`;
    const m = Math.round(s / 60);
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    const mm = m % 60;
    return mm ? `${h}h ${mm}m` : `${h}h`;
  }

  remove(id) {
    // On touch devices the × button is always visible (no hover to reveal
    // it) and sits right on the thumbnail, so a stray tap while scrolling
    // would silently drop the entry. Ask first there; on pointer devices
    // the hover-reveal is already a deliberate two-step.
    if (this._isTouch()) {
      const entry = this.entries.find(e => e.id === id);
      const label = (entry && entry.title) ? `"${entry.title}"` : 'this video';
      if (!window.confirm(`Remove ${label} from Continue Watching?`)) return;
    }
    this.tracker.clearProgress(id);
    this.entries = this.entries.filter(e => e.id !== id);
    this.render();
  }

  _isTouch() {
    try {
      return window.matchMedia('(hover: none)').matches;
    } catch (_) {
      return false;
    }
  }

  clearAll() {
    if (this.entries.length === 0) return;
    const ok = window.confirm('Clear all Continue Watching items?');
    if (!ok) return;
    // Clear locally; the tracker handles the auth'd server-side wipe too.
    this.tracker.clearProgress();
    this.entries = [];
    this.render();
  }

  refresh() {
    this.init();
  }
}

export default ContinueWatchingManager;
