/**
 * FeaturedSectionsManager Component
 * Manages multiple featured content sections on the homepage
 */

import { escapeHtml, extractValue, formatRuntime, getThumbnailUrl, mapWithConcurrency } from '../utils/helpers.js';
import { ICONS } from '../utils/icons.js';

// Cap on parallel archive.org metadata fallback fetches. Section configs
// can list dozens of ids; firing them all at once trips archive.org rate
// limits and starves the page's own requests.
const FALLBACK_CONCURRENCY = 6;

export class FeaturedSectionsManager {
  constructor(app) {
    this.app = app;
    this.config = this.loadConfig();
    this.sections = [];
    this.container = document.getElementById('featuredSectionsContainer');

    // One delegated listener for every Hide/Show button, wired once. The
    // section id comes from a data attribute, never from an inline
    // onclick string, so an admin-supplied id can't inject script.
    if (this.container) {
      this.container.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-section-toggle]');
        if (!btn || !this.container.contains(btn)) return;
        this.toggleSection(btn.dataset.sectionToggle);
      });
    }
  }

  loadConfig() {
    const configEl = document.getElementById('featuredSectionsConfig');
    if (configEl) {
      try {
        return JSON.parse(configEl.textContent);
      } catch (e) {
        console.warn('Failed to parse featured sections config:', e);
      }
    }
    return { sections: [] };
  }

  async init() {
    if (!this.config.sections || this.config.sections.length === 0) {
      return;
    }

    const enabledSections = this.config.sections
      .filter(s => s.enabled !== false && s.videos && s.videos.length > 0);

    if (enabledSections.length === 0) {
      return;
    }

    // Collect all unique IDs across sections so we only fetch each once,
    // even when shared between sections.
    const allIds = new Set();
    for (const section of enabledSections) {
      for (const v of section.videos) {
        if (v.id) allIds.add(v.id);
      }
    }
    const idList = [...allIds];

    // Use whatever the server prefetched (may be empty/partial)
    const prefetched = this.loadPrefetchedMetadata() || {};
    const missing = idList.filter(id => !prefetched[id]);

    // Render immediately with prefetched data + stubs for missing items.
    this.sections = enabledSections
      .map(section => ({
        ...section,
        loadedVideos: this.buildVideosFromMap(section.videos, prefetched),
      }))
      .filter(s => s.loadedVideos.length > 0);
    this.render();

    // Background-fetch missing items and re-render only if there are any
    if (missing.length > 0) {
      this.fetchMissingAndUpdate(enabledSections, missing, prefetched);
    }
  }

  async fetchMissingAndUpdate(enabledSections, missingIds, currentMap) {
    const fetched = await this.fetchBatchMetadata(missingIds);

    // No new data — skip the re-render to avoid thumbnail flicker
    if (!fetched || Object.keys(fetched).length === 0) {
      return;
    }

    const merged = { ...currentMap, ...fetched };

    this.sections = enabledSections
      .map(section => ({
        ...section,
        loadedVideos: this.buildVideosFromMap(section.videos, merged),
      }))
      .filter(s => s.loadedVideos.length > 0);

    this.render();
  }

  loadPrefetchedMetadata() {
    const el = document.getElementById('featuredMetadataPrefetch');
    if (!el || !el.textContent.trim()) return null;
    try {
      const data = JSON.parse(el.textContent);
      return data && typeof data === 'object' ? data : null;
    } catch {
      return null;
    }
  }

  buildVideosFromMap(items, metadataMap) {
    const out = [];
    for (const item of items) {
      const meta = metadataMap[item.id];
      if (meta) {
        out.push({ ...meta, identifier: item.id, adminNote: item.note });
      } else {
        // Fallback to admin-supplied title/creator so the card still renders
        out.push({
          identifier: item.id,
          title: item.title || item.id,
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

    const missing = ids.filter(id => !metadataMap[id]);
    if (missing.length > 0) {
      const fallbacks = await mapWithConcurrency(missing, FALLBACK_CONCURRENCY, async (id) => {
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
    if (!this.container || this.sections.length === 0) {
      return;
    }

    // Create HTML for all sections
    const sectionsHTML = this.sections.map(section => this.createSection(section)).join('');
    this.container.innerHTML = sectionsHTML;

    // Add scroll buttons and event listeners for each section
    this.sections.forEach((section, index) => {
      const sectionEl = this.findSection(section.id);
      if (sectionEl) {
        const grid = sectionEl.querySelector('.featured-section-grid');
        if (grid) {
          this.addScrollButtons(grid, sectionEl);
          this.attachEventListeners(grid, section);
        }
      }
    });
  }

  /** Locate a rendered section by id without building a selector from it. */
  findSection(sectionId) {
    if (!this.container) return null;
    const wanted = String(sectionId);
    return Array.from(this.container.querySelectorAll('[data-section-id]'))
      .find(el => el.dataset.sectionId === wanted) || null;
  }

  createSection(section) {
    const hidden = this.isHidden(section.id);
    const safeId = escapeHtml(String(section.id));

    // A hidden section collapses to just its header so the Show button
    // stays reachable — display:none on the whole section used to take the
    // toggle with it, so "Show" could never actually be clicked.
    return `
      <section class="featured-section${hidden ? ' is-collapsed' : ''}" data-section-id="${safeId}">
        <div class="featured-section-header">
          <div>
            <h2 class="featured-section-title">
              ${escapeHtml(section.title)}
            </h2>
            ${section.description ? `<p class="featured-section-description">${escapeHtml(section.description)}</p>` : ''}
          </div>
          <button type="button" class="btn btn-ghost" data-section-toggle="${safeId}"
                  aria-expanded="${hidden ? 'false' : 'true'}"
                  aria-label="${hidden ? 'Show' : 'Hide'} ${escapeHtml(section.title)} section">
            ${hidden ? 'Show' : 'Hide'}
          </button>
        </div>
        <div class="featured-section-scroll-container">
          <div class="featured-section-grid">
            ${section.loadedVideos.map(video => this.createCard(video)).join('')}
          </div>
        </div>
      </section>
    `;
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
      <article class="featured-card" data-identifier="${escapeHtml(video.identifier)}">
        <div class="featured-card-thumb">
          <img src="${thumbUrl}"
               alt="${escapeHtml(title)}"
               loading="lazy"
               decoding="async"
               onerror="this.style.display='none';this.parentNode.classList.add('thumb-missing')"/>
          ${runtime ? `<span class="runtime-badge">${runtime}</span>` : ''}
          <div class="featured-card-overlay">
            <span class="play-btn">${ICONS.play}</span>
          </div>
        </div>
        <div class="featured-card-content">
          <h3 class="featured-card-title"><a class="card-link" href="${playerUrl}">${escapeHtml(title)}</a></h3>
          <p class="featured-card-creator">${escapeHtml(creator)}</p>
          ${video.adminNote ? `<span class="featured-card-note">${ICONS.star} ${escapeHtml(video.adminNote)}</span>` : ''}
        </div>
      </article>
    `;
  }

  addScrollButtons(grid, container) {
    if (container.querySelector('.scroll-btn')) return;

    container.querySelector('.featured-section-scroll-container').classList.add('has-scroll-buttons');

    const leftBtn = document.createElement('button');
    leftBtn.className = 'scroll-btn scroll-btn-left';
    leftBtn.innerHTML = ICONS.chevronLeft;
    leftBtn.setAttribute('aria-label', 'Scroll left');

    const rightBtn = document.createElement('button');
    rightBtn.className = 'scroll-btn scroll-btn-right';
    rightBtn.innerHTML = ICONS.chevronRight;
    rightBtn.setAttribute('aria-label', 'Scroll right');

    container.querySelector('.featured-section-scroll-container').appendChild(leftBtn);
    container.querySelector('.featured-section-scroll-container').appendChild(rightBtn);

    const updateButtonStates = () => {
      const scrollLeft = grid.scrollLeft;
      const scrollWidth = grid.scrollWidth;
      const clientWidth = grid.clientWidth;

      leftBtn.classList.toggle('disabled', scrollLeft <= 0);
      rightBtn.classList.toggle('disabled', scrollLeft >= scrollWidth - clientWidth - 10);
    };

    leftBtn.addEventListener('click', () => {
      grid.scrollBy({ left: -400, behavior: 'smooth' });
    });

    rightBtn.addEventListener('click', () => {
      grid.scrollBy({ left: 400, behavior: 'smooth' });
    });

    grid.addEventListener('scroll', updateButtonStates);
    updateButtonStates();
  }

  attachEventListeners(grid, section) {
    grid.querySelectorAll('.featured-card').forEach(card => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('a')) return;

        const id = card.dataset.identifier;
        this.app.navigateToPlayer(id);
      });
    });
  }

  isHidden(sectionId) {
    try {
      const hidden = localStorage.getItem(`hideFeaturedSection_${sectionId}`);
      return hidden === 'true';
    } catch (e) {
      return false;
    }
  }

  toggleSection(sectionId) {
    const section = this.findSection(sectionId);
    if (!section) return;

    const nowHidden = !section.classList.contains('is-collapsed');
    section.classList.toggle('is-collapsed', nowHidden);

    try {
      if (nowHidden) {
        localStorage.setItem(`hideFeaturedSection_${sectionId}`, 'true');
      } else {
        localStorage.removeItem(`hideFeaturedSection_${sectionId}`);
      }
    } catch (e) {}

    // Keep the button's label in step with the state it now represents.
    const btn = section.querySelector('[data-section-toggle]');
    if (btn) {
      const titleEl = section.querySelector('.featured-section-title');
      const title = titleEl ? titleEl.textContent.trim() : '';
      btn.textContent = nowHidden ? 'Show' : 'Hide';
      btn.setAttribute('aria-expanded', nowHidden ? 'false' : 'true');
      btn.setAttribute('aria-label', `${nowHidden ? 'Show' : 'Hide'} ${title} section`.replace(/\s+/g, ' '));
    }
  }
}

export default FeaturedSectionsManager;
