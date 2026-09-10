/**
 * RecommendedManager Component
 * Manages the Staff Picks / Recommended videos section
 */

import { escapeHtml, extractValue, formatRuntime, getThumbnailUrl, mapWithConcurrency } from '../utils/helpers.js';
import { ICONS } from '../utils/icons.js';

// Cap on parallel archive.org metadata fallback fetches (see
// FeaturedSectionsManager for the rationale).
const FALLBACK_CONCURRENCY = 6;

export class RecommendedManager {
  constructor(app) {
    this.app = app;
    this.config = this.loadConfig();
    this.videos = [];
    this.section = document.getElementById('recommendedSection');
    this.grid = document.getElementById('recommendedGrid');
    this.hideBtn = document.getElementById('hideRecommended');

    const hiddenVal = localStorage.getItem('hideRecommended');
    this.isHidden = hiddenVal === 'true';

    this.setupEventListeners();
  }

  loadConfig() {
    const configEl = document.getElementById('recommendedConfig');
    if (configEl) {
      try {
        return JSON.parse(configEl.textContent);
      } catch (e) {
        console.warn('Failed to parse recommended config:', e);
      }
    }
    return { enabled: false, videos: [] };
  }

  setupEventListeners() {
    if (this.hideBtn) {
      this.hideBtn.addEventListener('click', () => this.hide());
    }
  }

  async init() {
    if (!this.config.enabled || !this.config.videos?.length || this.isHidden) {
      return;
    }

    const ids = this.config.videos.map(v => v.id).filter(Boolean);
    if (ids.length === 0) return;

    // Use server-prefetched metadata as a starting point — it's whatever
    // we already had cached server-side, may be empty or partial.
    const prefetched = this.loadPrefetchedMetadata() || {};
    const missing = ids.filter(id => !prefetched[id]);

    // Render immediately with whatever we have (prefetched or stub).
    // This is the critical fast path: cached homepages render in <50ms
    // with no JS-side network wait.
    this.videos = this.buildVideosFromMap(ids, prefetched);
    this.render();

    // If anything was missing from the prefetch, fetch it in the
    // background and update only the affected cards.
    if (missing.length > 0) {
      this.fetchMissingAndUpdate(missing, prefetched);
    }
  }

  async fetchMissingAndUpdate(missingIds, currentMap) {
    const fetched = await this.fetchBatchMetadata(missingIds);

    // Bail out if the fetch returned nothing useful — no point re-rendering
    // identical cards.
    if (!fetched || Object.keys(fetched).length === 0) {
      return;
    }

    const merged = { ...currentMap, ...fetched };
    const ids = this.config.videos.map(v => v.id).filter(Boolean);
    this.videos = this.buildVideosFromMap(ids, merged);

    // Re-render only if the section is still visible — user might have
    // hidden it in the meantime.
    if (this.section && this.section.style.display !== 'none') {
      this.render();
    }
  }

  loadPrefetchedMetadata() {
    const el = document.getElementById('recommendedMetadataPrefetch');
    if (!el || !el.textContent.trim()) return null;
    try {
      const data = JSON.parse(el.textContent);
      return data && typeof data === 'object' ? data : null;
    } catch {
      return null;
    }
  }

  buildVideosFromMap(ids, metadataMap) {
    const configById = new Map(this.config.videos.map(v => [v.id, v]));
    const out = [];
    for (const id of ids) {
      const meta = metadataMap[id];
      const item = configById.get(id) || {};
      if (meta) {
        out.push({ ...meta, identifier: id, adminNote: item.note });
      } else {
        // Fallback stub - at least we have title/creator from config
        out.push({
          identifier: id,
          title: item.title || id,
          creator: item.creator || 'Unknown',
          adminNote: item.note,
        });
      }
    }
    return out;
  }

  async fetchBatchMetadata(ids) {
    if (ids.length === 0) return {};

    let metadataMap = {};
    try {
      const url = `api/metadata-batch.php?ids=${encodeURIComponent(ids.join(','))}`;
      const response = await fetch(url, { credentials: 'same-origin' });
      if (response.ok) {
        const payload = await response.json();
        metadataMap = payload?.data || {};
      }
    } catch (e) {
      console.warn('Batch metadata fetch failed, falling back to direct archive.org:', e);
    }

    // Direct archive.org fallback for any IDs the batch endpoint didn't return
    const stillMissing = ids.filter(id => !metadataMap[id]);
    if (stillMissing.length > 0) {
      const fallbacks = await mapWithConcurrency(stillMissing, FALLBACK_CONCURRENCY, async (id) => {
        try {
          const response = await fetch(`https://archive.org/metadata/${encodeURIComponent(id)}`);
          if (!response.ok) return [id, null];
          const data = await response.json();
          return [id, data?.metadata ? { ...data.metadata, identifier: id } : null];
        } catch {
          return [id, null];
        }
      });
      for (const entry of fallbacks) {
        if (!entry) continue;
        const [id, meta] = entry;
        if (meta) metadataMap[id] = meta;
      }
    }

    return metadataMap;
  }

  render() {
    if (!this.section || !this.grid || this.videos.length === 0) {
      return;
    }

    this.section.style.display = 'block';

    // Create scroll container with navigation buttons
    this.grid.innerHTML = this.videos.map(video => this.createCard(video)).join('');

    // Add scroll buttons
    this.addScrollButtons();

    // Attach event listeners - navigate to dedicated player page
    this.grid.querySelectorAll('.recommended-card').forEach(card => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('a')) return;

        const id = card.dataset.identifier;
        this.app.navigateToPlayer(id);
      });
    });
  }

  addScrollButtons() {
    const container = this.grid.parentElement;
    if (!container || container.querySelector('.scroll-btn')) return;

    container.classList.add('recommended-scroll-container');

    const leftBtn = document.createElement('button');
    leftBtn.className = 'scroll-btn scroll-btn-left';
    leftBtn.innerHTML = ICONS.chevronLeft;
    leftBtn.setAttribute('aria-label', 'Scroll left');

    const rightBtn = document.createElement('button');
    rightBtn.className = 'scroll-btn scroll-btn-right';
    rightBtn.innerHTML = ICONS.chevronRight;
    rightBtn.setAttribute('aria-label', 'Scroll right');

    container.appendChild(leftBtn);
    container.appendChild(rightBtn);

    const updateButtonStates = () => {
      const scrollLeft = this.grid.scrollLeft;
      const scrollWidth = this.grid.scrollWidth;
      const clientWidth = this.grid.clientWidth;

      leftBtn.classList.toggle('disabled', scrollLeft <= 0);
      rightBtn.classList.toggle('disabled', scrollLeft >= scrollWidth - clientWidth - 10);
    };

    leftBtn.addEventListener('click', () => {
      this.grid.scrollBy({ left: -400, behavior: 'smooth' });
    });

    rightBtn.addEventListener('click', () => {
      this.grid.scrollBy({ left: 400, behavior: 'smooth' });
    });

    this.grid.addEventListener('scroll', updateButtonStates);
    updateButtonStates();
  }

  createCard(video) {
    const title = extractValue(video.title) || 'Untitled';
    const creator = extractValue(video.creator) || 'Unknown';
    const thumbUrl = getThumbnailUrl(video.identifier);
    const runtime = formatRuntime(video.runtime);
    const playerUrl = `player.php?video=${encodeURIComponent(video.identifier)}`;

    // The title is a real link so the card is keyboard-operable and
    // middle-click / "open in new tab" work. The card-level click handler
    // ignores clicks that land on the link and lets the browser navigate.
    return `
      <article class="recommended-card" data-identifier="${escapeHtml(video.identifier)}">
        <div class="recommended-card-thumb">
          <img src="${thumbUrl}"
               alt="${escapeHtml(title)}"
               loading="eager"
               decoding="async"
               fetchpriority="high"
               onerror="this.style.display='none';this.parentNode.classList.add('thumb-missing')"/>
          ${runtime ? `<span class="runtime-badge">${runtime}</span>` : ''}
          <div class="recommended-card-overlay">
            <span class="play-btn">${ICONS.play}</span>
          </div>
        </div>
        <div class="recommended-card-content">
          <h3 class="recommended-card-title"><a class="card-link" href="${playerUrl}">${escapeHtml(title)}</a></h3>
          <p class="recommended-card-creator">${escapeHtml(creator)}</p>
          ${video.adminNote ? `<span class="recommended-card-note">${ICONS.star} ${escapeHtml(video.adminNote)}</span>` : ''}
        </div>
      </article>
    `;
  }

  hide() {
    if (this.section) {
      this.section.style.display = 'none';
    }
    this.isHidden = true;
    try {
      localStorage.setItem('hideRecommended', 'true');
    } catch (e) {
      console.warn('Failed to save preference:', e);
    }
  }

  show() {
    this.isHidden = false;
    try {
      localStorage.removeItem('hideRecommended');
    } catch (e) {
      console.warn('Failed to save preference:', e);
    }
    this.init();
  }
}

export default RecommendedManager;
