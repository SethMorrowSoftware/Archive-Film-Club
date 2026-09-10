/**
 * PlayerPlaylist - Rich playlist rendering for the player page
 *
 * Renders a series/episodes sidebar with:
 *   - A series header card (cover, title, stats — total runtime, total size)
 *   - A search input that filters episodes by title
 *   - A view-density toggle (comfortable / compact)
 *   - Per-episode watched indicators and resumable progress bars
 *   - Sticky controls header with prev/next nav
 *
 * Watched + per-track progress are persisted to localStorage under
 * `playlistEpisodeProgress:<videoId>` so per-track resume works without
 * the server schema change that VideoProgressTracker would otherwise need.
 */

import { escapeHtml, extractValue, formatRuntime, formatFileSize } from '../utils/helpers.js';

const PROGRESS_STORAGE_KEY = 'playlistEpisodeProgress';

function readPlaylistProgress(videoId) {
  if (!videoId) return {};
  try {
    const raw = localStorage.getItem(`${PROGRESS_STORAGE_KEY}:${videoId}`);
    return raw ? (JSON.parse(raw) || {}) : {};
  } catch (e) {
    return {};
  }
}

function writePlaylistProgress(videoId, data) {
  if (!videoId) return;
  try {
    localStorage.setItem(`${PROGRESS_STORAGE_KEY}:${videoId}`, JSON.stringify(data));
  } catch (e) { /* quota exceeded — non-fatal */ }
}

function readDensity() {
  try { return localStorage.getItem('playlistDensity') || 'comfortable'; }
  catch (e) { return 'comfortable'; }
}

function writeDensity(d) {
  try { localStorage.setItem('playlistDensity', d); } catch (e) {}
}

export class PlayerPlaylist {
  constructor(videoService, playlistService) {
    this.videoService = videoService;
    this.playlistService = playlistService;

    this.sidebar = document.getElementById('playlistSidebar');
    this.titleEl = document.getElementById('playlistTitle');
    this.countEl = document.getElementById('playlistCount');
    this.itemsEl = document.getElementById('playlistItems');

    this.onItemClick = null;
    this.activeIndex = 0;
    this.filterQuery = '';
    this.density = readDensity();
    this.episodeProgress = {};
    this._injectedToolbar = false;
  }

  /**
   * Initialize and show the playlist
   */
  show(meta, videoFiles, startIndex, onItemClick) {
    this.onItemClick = onItemClick;
    this.activeIndex = startIndex;
    const id = meta.identifier || '';
    const title = extractValue(meta.title) || 'Episodes';
    const creator = extractValue(meta.creator) || '';

    this.playlistService.initPlaylist(id, title, creator, meta, videoFiles, startIndex);

    this.episodeProgress = readPlaylistProgress(id);

    if (this.sidebar) {
      this.sidebar.style.display = 'flex';
      this.sidebar.setAttribute('data-density', this.density);
    }
    // Drives the two-column layout (see "Layout v3" in player-styles.css).
    document.body.classList.add('has-playlist');

    this._injectToolbar();
    this._updateHeaderStats(videoFiles);

    this.render(startIndex, { initial: true });
  }

  /**
   * Hide the rail (single-video items, or navigating from a series to a
   * single video via history) and drop the layout class so the video
   * column spans the full width again.
   */
  hide() {
    if (this.sidebar) this.sidebar.style.display = 'none';
    document.body.classList.remove('has-playlist');
  }

  /**
   * Inject the search box, density toggle, and series cover header.
   * Idempotent — safe to call repeatedly.
   */
  _injectToolbar() {
    if (this._injectedToolbar || !this.sidebar) return;

    const playlist = this.playlistService.getPlaylist();
    if (!playlist) return;

    const meta = playlist.metadata || {};
    const id = playlist.id || '';
    const seriesTitle = extractValue(meta.title) || 'Episodes';
    const seriesCreator = extractValue(meta.creator) || '';
    const coverUrl = `https://archive.org/services/img/${id}`;

    const headerEl = this.sidebar.querySelector('.player-sidebar-header');
    if (!headerEl) return;

    // Series cover card — rendered once at the top of the sidebar.
    const seriesCard = document.createElement('div');
    seriesCard.className = 'player-series-card';
    seriesCard.innerHTML = `
      <div class="player-series-cover">
        <img src="${escapeHtml(coverUrl)}" alt="" loading="lazy" onerror="this.style.display='none'" />
      </div>
      <div class="player-series-info">
        <div class="player-series-eyebrow">Now playing</div>
        <h4 class="player-series-title" title="${escapeHtml(seriesTitle)}">${escapeHtml(seriesTitle)}</h4>
        ${seriesCreator ? `<div class="player-series-creator">${escapeHtml(seriesCreator)}</div>` : ''}
        <div class="player-series-stats" data-series-stats></div>
      </div>
    `;
    headerEl.insertAdjacentElement('afterend', seriesCard);

    // Toolbar — search + density toggle.
    const toolbar = document.createElement('div');
    toolbar.className = 'player-sidebar-toolbar';
    toolbar.innerHTML = `
      <div class="player-sidebar-search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="7"/>
          <path d="m21 21-4.3-4.3"/>
        </svg>
        <input type="search" class="player-sidebar-search-input"
               data-playlist-search
               placeholder="Filter episodes…"
               aria-label="Filter episodes">
        <button type="button" class="player-sidebar-search-clear"
                data-playlist-search-clear
                aria-label="Clear filter"
                title="Clear filter"
                hidden>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
            <path d="M18 6 6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>
      <div class="player-sidebar-density" role="group" aria-label="Playlist density">
        <button type="button" class="density-btn ${this.density === 'comfortable' ? 'active' : ''}"
                data-density="comfortable"
                aria-pressed="${this.density === 'comfortable'}"
                title="Comfortable view">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <rect x="3" y="4" width="18" height="6" rx="1.5"/>
            <rect x="3" y="14" width="18" height="6" rx="1.5"/>
          </svg>
        </button>
        <button type="button" class="density-btn ${this.density === 'compact' ? 'active' : ''}"
                data-density="compact"
                aria-pressed="${this.density === 'compact'}"
                title="Compact view">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <line x1="4" y1="6" x2="20" y2="6"/>
            <line x1="4" y1="12" x2="20" y2="12"/>
            <line x1="4" y1="18" x2="20" y2="18"/>
          </svg>
        </button>
      </div>
    `;
    seriesCard.insertAdjacentElement('afterend', toolbar);

    // Filter wiring (debounced search; immediate clear)
    const searchInput = toolbar.querySelector('[data-playlist-search]');
    const clearBtn = toolbar.querySelector('[data-playlist-search-clear]');
    let debounceTimer = null;

    if (searchInput) {
      searchInput.addEventListener('input', () => {
        if (debounceTimer) clearTimeout(debounceTimer);
        const value = searchInput.value;
        clearBtn.hidden = !value;
        debounceTimer = setTimeout(() => {
          this.filterQuery = value.trim().toLowerCase();
          this.render(this.activeIndex);
        }, 120);
      });
    }
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        if (!searchInput) return;
        searchInput.value = '';
        searchInput.focus();
        clearBtn.hidden = true;
        this.filterQuery = '';
        this.render(this.activeIndex);
      });
    }

    // Density toggle wiring
    toolbar.querySelectorAll('[data-density]').forEach(btn => {
      btn.addEventListener('click', () => {
        const next = btn.getAttribute('data-density');
        if (next === this.density) return;
        this.density = next;
        writeDensity(next);
        toolbar.querySelectorAll('[data-density]').forEach(b => {
          const isActive = b.getAttribute('data-density') === next;
          b.classList.toggle('active', isActive);
          b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
        if (this.sidebar) this.sidebar.setAttribute('data-density', next);
      });
    });

    this._injectedToolbar = true;
  }

  /**
   * Update the header count + the series stats line (total runtime, total size).
   */
  _updateHeaderStats(videoFiles) {
    const total = videoFiles.length;
    if (this.countEl) {
      this.countEl.textContent = `${total} ${total === 1 ? 'item' : 'items'}`;
    }

    const statsEl = this.sidebar?.querySelector('[data-series-stats]');
    if (!statsEl) return;

    let totalSeconds = 0;
    let totalBytes = 0;
    for (const f of videoFiles) {
      const len = parseFloat(f.length);
      if (!isNaN(len)) totalSeconds += len;
      const sz = parseFloat(f.size);
      if (!isNaN(sz)) totalBytes += sz;
    }

    const parts = [`<span>${total} episodes</span>`];
    if (totalSeconds > 0) {
      parts.push(`<span>${this._formatTotalRuntime(totalSeconds)}</span>`);
    }
    if (totalBytes > 0) {
      parts.push(`<span>${formatFileSize(totalBytes)}</span>`);
    }
    statsEl.innerHTML = parts.join('<span class="dot">·</span>');
  }

  _formatTotalRuntime(seconds) {
    const total = Math.round(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    if (h && m) return `${h}h ${m}m`;
    if (h) return `${h}h`;
    return `${m}m`;
  }

  /**
   * Mark current episode as watched + persist its position.
   * Called from the host VideoPlayer when a track ends.
   */
  markWatched(index, durationSeconds) {
    const playlist = this.playlistService.getPlaylist();
    if (!playlist) return;
    this.episodeProgress[String(index)] = {
      watched: true,
      currentTime: durationSeconds || 0,
      duration: durationSeconds || 0,
      ts: Date.now(),
    };
    writePlaylistProgress(playlist.id, this.episodeProgress);
    this.render(this.activeIndex);
  }

  /**
   * Persist progress for a track (called periodically while watching).
   */
  saveTrackProgress(index, currentTime, duration) {
    if (!duration || index == null) return;
    const playlist = this.playlistService.getPlaylist();
    if (!playlist) return;
    const existing = this.episodeProgress[String(index)] || {};
    const watched = existing.watched || (duration > 0 && currentTime / duration >= 0.95);
    this.episodeProgress[String(index)] = {
      watched,
      currentTime,
      duration,
      ts: Date.now(),
    };
    writePlaylistProgress(playlist.id, this.episodeProgress);
  }

  getTrackProgress(index) {
    return this.episodeProgress[String(index)] || null;
  }

  /**
   * Render the playlist items, applying the current filter.
   */
  render(activeIndex = 0, { initial = false } = {}) {
    if (!this.itemsEl) return;
    this.activeIndex = activeIndex;

    const playlist = this.playlistService.getPlaylist();
    if (!playlist) return;

    const { id, videoFiles, metadata } = playlist;
    const itemTitle = extractValue(metadata?.title) || '';
    const thumbUrl = `https://archive.org/services/img/${id}`;

    const q = this.filterQuery;
    const visible = videoFiles
      .map((file, i) => ({ file, i }))
      .filter(({ file }) => {
        if (!q) return true;
        const epTitle = (this.videoService.getCleanTitle(file.name, itemTitle) || '').toLowerCase();
        const fileName = (file.name || '').toLowerCase();
        return epTitle.includes(q) || fileName.includes(q);
      });

    if (visible.length === 0) {
      this.itemsEl.innerHTML = `
        <div class="player-sidebar-empty">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/>
            <path d="m21 21-4.3-4.3"/>
          </svg>
          <p>No episodes match "${escapeHtml(q)}"</p>
        </div>
      `;
      return;
    }

    this.itemsEl.innerHTML = visible.map(({ file, i }) => {
      const epTitle = this.videoService.getCleanTitle(file.name, itemTitle);
      const duration = formatRuntime(file.length) || '';
      const size = file.size ? formatFileSize(file.size) : '';
      const isActive = i === activeIndex;
      const progress = this.getTrackProgress(i);
      const watched = progress?.watched;
      const partialPct = progress && progress.duration && !watched
        ? Math.min(100, Math.max(0, (progress.currentTime / progress.duration) * 100))
        : 0;

      const stateClass = isActive ? 'active' : (watched ? 'watched' : '');
      const number = isActive
        ? `<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 3L19 12L5 21V3Z"/></svg>`
        : (watched
            ? `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>`
            : (i + 1));

      const numberLabel = isActive ? 'aria-label="Now playing"'
        : (watched ? 'aria-label="Watched"' : '');

      return `
        <div class="playlist-item ${stateClass}" data-index="${i}" role="button" tabindex="0">
          <span class="playlist-item-number" ${numberLabel}>${number}</span>
          <div class="playlist-item-thumb">
            <img src="${escapeHtml(thumbUrl)}" alt="" loading="lazy" onerror="this.style.display='none'" />
            ${isActive ? `
              <div class="playlist-item-playing-icon">
                <div class="playing-bars"><span></span><span></span><span></span></div>
              </div>
            ` : ''}
            ${duration ? `<span class="playlist-item-duration-badge">${duration}</span>` : ''}
            ${partialPct > 0 ? `<div class="playlist-item-progress" style="width:${partialPct.toFixed(1)}%"></div>` : ''}
          </div>
          <div class="playlist-item-info">
            <span class="playlist-item-title">${escapeHtml(epTitle)}</span>
            <div class="playlist-item-meta">
              <span class="playlist-item-index">Ep ${i + 1}</span>
              ${duration ? `<span class="dot">·</span><span>${duration}</span>` : ''}
              ${size ? `<span class="dot">·</span><span>${size}</span>` : ''}
              ${watched ? `<span class="dot">·</span><span class="watched-tag">Watched</span>` : ''}
              ${!watched && partialPct > 0 ? `<span class="dot">·</span><span class="resume-tag">Resume</span>` : ''}
            </div>
          </div>
        </div>
      `;
    }).join('');

    // Attach click + keyboard listeners
    const trigger = (item) => {
      const idx = parseInt(item.dataset.index, 10);
      if (idx !== activeIndex && this.onItemClick) this.onItemClick(idx);
    };

    this.itemsEl.querySelectorAll('.playlist-item').forEach(item => {
      item.addEventListener('click', () => trigger(item));
      item.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          trigger(item);
        }
      });
    });

    // Keep the active episode in view (only when not filtering).
    if (!q) {
      requestAnimationFrame(() => this._revealActive(initial));
    }
  }

  /**
   * Bring the active item into view without Element.scrollIntoView(),
   * which walks every scrollable ancestor and would drag the whole page.
   *
   *   Desktop: the rail is viewport-bounded and the list scrolls inside
   *            it, so nudge the list's own scrollTop.
   *   Mobile:  the list flows in the page under a sticky video, so the
   *            list itself never scrolls. There we scroll the window —
   *            but only when the playlist is already on screen (the user
   *            is browsing it); never on first paint, and never while
   *            they're reading the description/comments elsewhere.
   */
  _revealActive(initial) {
    const activeItem = this.itemsEl && this.itemsEl.querySelector('.playlist-item.active');
    if (!activeItem) return;
    const container = this.itemsEl;
    const itemRect = activeItem.getBoundingClientRect();

    const listScrolls = container.scrollHeight > container.clientHeight + 1;
    if (listScrolls) {
      const containerRect = container.getBoundingClientRect();
      const above = itemRect.top - containerRect.top;
      const below = itemRect.bottom - containerRect.bottom;
      if (above < 0) container.scrollTop += above;
      else if (below > 0) container.scrollTop += below;
      return;
    }

    if (initial) return;
    const vh = window.innerHeight || document.documentElement.clientHeight;
    const listRect = container.getBoundingClientRect();
    const listOnScreen = listRect.bottom > 0 && listRect.top < vh;
    if (!listOnScreen) return;

    // Space taken by the sticky header + video + playlist header on top.
    const stickyTop = this._stickyOffset();
    if (itemRect.top < stickyTop) {
      window.scrollBy({ top: itemRect.top - stickyTop, behavior: 'smooth' });
    } else if (itemRect.bottom > vh) {
      window.scrollBy({ top: itemRect.bottom - vh + 8, behavior: 'smooth' });
    }
  }

  _stickyOffset() {
    let offset = 0;
    const cinema = document.getElementById('playerCinema');
    if (cinema && getComputedStyle(cinema).position === 'sticky') {
      offset = cinema.getBoundingClientRect().bottom;
    }
    const header = this.sidebar && this.sidebar.querySelector('.player-sidebar-header');
    if (header && getComputedStyle(header).position === 'sticky') {
      offset += header.getBoundingClientRect().height;
    }
    const toolbar = this.sidebar && this.sidebar.querySelector('.player-sidebar-toolbar');
    if (toolbar && getComputedStyle(toolbar).position === 'sticky') {
      offset += toolbar.getBoundingClientRect().height;
    }
    return Math.max(0, offset);
  }

  /**
   * Get current playlist data
   */
  getPlaylist() {
    return this.playlistService.getPlaylist();
  }

  /**
   * Check visibility
   */
  isVisible() {
    return this.sidebar && this.sidebar.style.display !== 'none';
  }
}

export default PlayerPlaylist;
