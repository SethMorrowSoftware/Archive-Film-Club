/**
 * ArchiveVideoSearch - Enhanced Version with Modular Architecture
 * Version: 4.0.0
 * Search/browse page - video playback moved to dedicated player.php
 */

// Import configuration
import { CONFIG, COLLECTIONS } from './src/js/config.js';

// Import utilities
import { ICONS } from './src/js/utils/icons.js';
import {
  safeParseJSON,
  escapeHtml,
  extractValue,
  formatRuntime,
  getThumbnailUrl
} from './src/js/utils/helpers.js';
import { UIFeedback } from './src/js/utils/uiFeedback.js';
import { UrlManager } from './src/js/utils/urlManager.js';
import { trapFocus } from './src/js/utils/focusTrap.js';

// Import services
import { SearchService } from './src/js/services/SearchService.js';
import { VideoProgressTracker } from './src/js/services/VideoProgressTracker.js';
import { BookmarkManager } from './src/js/services/BookmarkManager.js';
import { OfflineHandler } from './src/js/services/OfflineHandler.js';
import { BackgroundCacheService } from './src/js/services/BackgroundCacheService.js';

// Import components
import { SearchSuggestions } from './src/js/components/SearchSuggestions.js';
import { RecommendedManager } from './src/js/components/RecommendedManager.js';
import { ContinueWatchingManager } from './src/js/components/ContinueWatchingManager.js';
import { FeaturedSectionsManager } from './src/js/components/FeaturedSectionsManager.js';
import { Toast } from './src/js/components/Toast.js';
import { AuthNav } from './src/js/components/AuthNav.js';

// Mount auth nav as early as possible so the header doesn't flash empty
AuthNav.mount();

// Main Application Class
class ArchiveVideoSearch {
  constructor() {
    // Core properties
    this.currentPage = 1;
    this.currentQuery = '';
    this.totalResults = 0;
    this.searchDebounceTimer = null;
    // Monotonic token + AbortController so a slow older search can't
    // overwrite a newer rendered result, and timers/listeners stop work
    // for stale requests.
    this._searchToken = 0;
    this._searchAbort = null;
    // Set while a popstate is being replayed so performSearch() doesn't
    // write the URL we're restoring FROM back into history (which turned
    // Back into a trap: every Back press pushed a fresh entry).
    this._restoringFromHistory = false;

    // Load site settings from admin panel
    this.siteSettings = this.loadSiteSettings();

    // Feature flags (now driven by admin settings)
    this.enableBookmarks = this.siteSettings.enableBookmarks ?? false;

    // Initialize services
    this.searchService = new SearchService();
    this.progressTracker = new VideoProgressTracker();
    this.bookmarkManager = new BookmarkManager();
    this.offlineHandler = new OfflineHandler();
    this.backgroundCacheService = new BackgroundCacheService();
    this.toast = new Toast();
    this.uiFeedback = new UIFeedback();

    // User preferences
    this.userPreferences = safeParseJSON(localStorage.getItem('userPrefs')) || {};

    // Initialize DOM and event listeners
    this.initializeElements();
    this.setupUIFeedback();
    this.setupEventListeners();
    this.populateCollections();
    this.loadUserPreferences();
    this.setupSearchSuggestions();

    // The server-side bookmark list arrives asynchronously (after login /
    // me.php resolves). Cards rendered before that would keep showing the
    // stale guest state, so re-sync the icons whenever the list changes.
    this.bookmarkManager.onChange(() => this.syncBookmarkButtons());

    // Initialize recommended section and featured sections.
    // These render independently from the search results — there's no
    // ordering constraint, so kick them off in parallel WITH the search
    // rather than blocking the search behind them. Previously the search
    // was gated behind a Promise.race with a 3s timeout, which meant the
    // perceived load time of the page was always >= 3s (or as long as
    // archive.org's metadata endpoint took, whichever was less). With
    // server-side prefetch the sections render almost instantly anyway.
    this.recommendedManager = new RecommendedManager(this);
    this.featuredSectionsManager = new FeaturedSectionsManager(this);
    this.continueWatchingManager = new ContinueWatchingManager(this, this.progressTracker);

    // Continue Watching is purely local-storage-driven, so it can render
    // synchronously before the network-backed sections.
    try {
      this.continueWatchingManager.init();
    } catch (err) {
      console.error('Failed to init Continue Watching:', err);
    }

    this.recommendedManager.init().catch(err => {
      console.error('Failed to init recommended:', err);
    });
    this.featuredSectionsManager.init().catch(err => {
      console.error('Failed to init featured sections:', err);
    });

    // Search runs immediately, in parallel with sections
    this.handleUrlParameters();

    // Setup offline handler callbacks. Debounce because some networks flap
    // online/offline rapidly (VPN reconnect, captive portal handshake) and
    // we don't want to fire a refetch storm. The OfflineHandler itself only
    // flips state after a probe confirms reachability, so this is a second
    // layer of guard.
    this._onlineRefetchTimer = null;
    this.offlineHandler.onStatusChange((isOnline) => {
      if (isOnline && this.currentQuery) {
        clearTimeout(this._onlineRefetchTimer);
        this._onlineRefetchTimer = setTimeout(() => {
          this.showMessage('Back online! Refreshing results...', 'success');
          this.performSearch();
        }, 750);
      }
    });

    this.updatePageTitle();

    console.log('ArchiveVideoSearch initialized successfully');
  }

  initializeElements() {
    this.searchForm = document.getElementById('searchForm');
    this.searchInput = document.getElementById('searchInput');
    this.searchBtn = document.getElementById('searchBtn');
    this.clearSearchBtn = document.getElementById('clearSearchBtn');
    this.collection = document.getElementById('collection');
    this.sortBy = document.getElementById('sortBy');
    this.clearFilters = document.getElementById('clearFilters');
    this.loading = document.getElementById('loading');
    this.error = document.getElementById('error');
    this.results = document.getElementById('results');
    this.pagination = document.getElementById('pagination');
    this.searchStats = document.getElementById('searchStats');
    this.publicDomain = document.getElementById('publicDomain');
    this.collectionsOnly = document.getElementById('collectionsOnly');
    this.sidebar = document.querySelector('.sidebar');
    this.mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    this.mobileOverlay = document.querySelector('.mobile-overlay');
    this.mobileCloseBtn = document.querySelector('.mobile-close-btn');

    const criticalElements = [
      'searchForm', 'searchInput', 'collection', 'results'
    ];

    for (const elementName of criticalElements) {
      if (!this[elementName]) {
        console.error(`Critical element missing: ${elementName}`);
      }
    }
  }

  setupUIFeedback() {
    this.uiFeedback.setElements({
      loading: this.loading,
      error: this.error,
      results: this.results,
      pagination: this.pagination,
      searchStats: this.searchStats,
      searchBtn: this.searchBtn
    });
  }

  setupEventListeners() {
    // Logo click - go home
    const logoSection = document.querySelector('.logo-section');
    if (logoSection) {
      logoSection.addEventListener('click', (e) => {
        e.preventDefault();
        this.goHome();
      });
    }

    if (this.searchForm) {
      this.searchForm.addEventListener('submit', e => {
        e.preventDefault();
        this.currentPage = 1;
        this.performSearch({ pushHistory: true, recordHistory: true });
      });
    }

    if (this.searchInput) {
      this.searchInput.addEventListener('input', () => {
        this.debounceSearch(() => {
          // Search on ANY change (deleting back to 1–2 chars used to leave
          // the previous results on screen). Skip only when the effective
          // query is unchanged, e.g. trailing whitespace.
          const value = this.searchInput.value.trim();
          if ((value || '*') !== this.currentQuery) {
            this.currentPage = 1;
            this.performSearch();
          }
        });

        if (this.clearSearchBtn) {
          this.clearSearchBtn.style.display = this.searchInput.value ? 'flex' : 'none';
        }
      });
    }

    if (this.clearSearchBtn) {
      this.clearSearchBtn.addEventListener('click', () => {
        this.searchInput.value = '';
        this.clearSearchBtn.style.display = 'none';
        this.searchInput.focus();
        this.currentPage = 1;
        this.performSearch();
      });
    }

    if (this.collection) {
      this.collection.addEventListener('change', () => {
        this.currentPage = 1;
        this.performSearch();
        this.saveUserPreferences();
      });
    }

    if (this.sortBy) {
      this.sortBy.addEventListener('change', () => {
        // No hasActiveSearch() gate: the default "All Videos" listing is
        // sortable too, and silently ignoring the change looked broken.
        this.currentPage = 1;
        this.performSearch();
        this.saveUserPreferences();
      });
    }

    [this.publicDomain, this.collectionsOnly].forEach(cb => {
      if (cb) cb.addEventListener('change', () => {
        this.currentPage = 1;
        this.performSearch();
      });
    });

    if (this.clearFilters) {
      this.clearFilters.addEventListener('click', () => this.clearAllFilters());
    }

    window.addEventListener('popstate', () => {
      // performSearch() writes the URL synchronously before its first
      // await, so the flag only needs to cover the synchronous part.
      this._restoringFromHistory = true;
      try {
        this.handleUrlParameters();
      } finally {
        this._restoringFromHistory = false;
      }
    });

    this.setupMobileMenu();
  }

  setupMobileMenu() {
    if (!this.mobileMenuBtn || !this.sidebar || !this.mobileOverlay || !this.mobileCloseBtn) return;

    const openMenu = () => {
      this.sidebar.classList.add('open');
      this.mobileOverlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    };

    this.mobileMenuBtn.addEventListener('click', openMenu);
    this.mobileOverlay.addEventListener('click', () => this.closeMobileMenu());
    this.mobileCloseBtn.addEventListener('click', () => this.closeMobileMenu());

    [this.collection, this.sortBy, this.publicDomain, this.collectionsOnly].forEach(el => {
      if (el) {
        el.addEventListener('change', () => {
          if (window.innerWidth <= 768 && this.sidebar.classList.contains('open')) {
            setTimeout(() => this.closeMobileMenu(), 200);
          }
        });
      }
    });

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && this.sidebar.classList.contains('open')) {
        this.closeMobileMenu();
      }
    });
  }

  setupSearchSuggestions() {
    if (this.searchInput) {
      this.searchSuggestions = new SearchSuggestions(
        this.searchInput,
        () => {
          this.currentPage = 1;
          this.performSearch({ pushHistory: true, recordHistory: true });
        }
      );
    }
  }

  debounceSearch(callback, delay = CONFIG.DEBOUNCE_DELAY) {
    clearTimeout(this.searchDebounceTimer);
    this.searchDebounceTimer = setTimeout(callback, delay);
  }

  // ========================================
  // User Preferences
  // ========================================

  saveUserPreferences() {
    const prefs = {
      collection: this.collection?.value,
      sortBy: this.sortBy?.value,
      lastSearch: this.searchInput?.value,
      timestamp: Date.now()
    };
    try {
      localStorage.setItem('userPrefs', JSON.stringify(prefs));
    } catch (e) {
      console.warn('Failed to save preferences:', e);
    }
  }

  loadUserPreferences() {
    const collections = this.searchService.getCollections();

    // Use user preferences if available, otherwise fall back to admin settings
    if (this.userPreferences?.collection && collections[this.userPreferences.collection] && this.collection) {
      this.collection.value = this.userPreferences.collection;
    } else if (this.siteSettings.defaultCollection && this.collection) {
      this.collection.value = this.siteSettings.defaultCollection;
    }

    if (this.userPreferences?.sortBy && this.sortBy) {
      this.sortBy.value = this.userPreferences.sortBy;
    } else if (this.siteSettings.defaultSort && this.sortBy) {
      this.sortBy.value = this.siteSettings.defaultSort;
    }
  }

  loadSiteSettings() {
    const configEl = document.getElementById('siteSettingsConfig');
    if (configEl) {
      try {
        return JSON.parse(configEl.textContent);
      } catch (e) {
        console.warn('Failed to parse site settings config:', e);
      }
    }
    // Return defaults if no config found
    return {
      siteName: 'Archive Film Club',
      showDownloadCount: true,
      showCreator: true,
      showDate: true,
      enableBookmarks: false,
      enableWatchHistory: true,
      cardStyle: 'modern'
    };
  }

  // ========================================
  // Page & URL Management
  // ========================================

  updatePageTitle(suffix = '') {
    let title = 'Archive Film Club';
    if (suffix) {
      title = `${suffix} - ${title}`;
    } else if (this.totalResults > 0) {
      title = `(${this.totalResults.toLocaleString()}) ${title}`;
    }
    document.title = title;
  }

  closeMobileMenu() {
    if (this.sidebar) this.sidebar.classList.remove('open');
    if (this.mobileOverlay) this.mobileOverlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  hasActiveSearch() {
    return (this.collection?.value !== 'all_videos') ||
           (this.searchInput?.value.trim()) ||
           (this.collectionsOnly?.checked);
  }

  populateCollections() {
    if (!this.collection) return;

    this.collection.innerHTML = '';
    const sortedCollections = this.searchService.getSortedCollections();

    sortedCollections.forEach(([id, label]) => {
      const opt = document.createElement('option');
      opt.value = id;
      opt.textContent = label;
      this.collection.appendChild(opt);
    });
    this.collection.value = 'all_videos';
  }

  handleUrlParameters() {
    const urlState = UrlManager.parseUrlState();

    // Redirect video URLs to dedicated player page
    if (urlState.videoId) {
      let playerUrl = `player.php?video=${encodeURIComponent(urlState.videoId)}`;
      if (urlState.track !== null) playerUrl += `&track=${urlState.track + 1}`;
      if (urlState.timestamp) playerUrl += `&t=${urlState.timestamp}`;
      window.location.replace(playerUrl);
      return;
    }

    // Mirror the URL into the controls unconditionally. On first load this
    // is a no-op; on popstate it's what makes Back actually go back (the
    // input used to keep the newer query, so the "restored" search re-ran
    // the current one).
    if (this.searchInput) {
      this.searchInput.value = urlState.search || '';
      if (this.clearSearchBtn) {
        this.clearSearchBtn.style.display = this.searchInput.value ? 'flex' : 'none';
      }
    }

    if (urlState.search || urlState.collection) {
      const collections = this.searchService.getCollections();
      if (this.collection) {
        this.collection.value = (urlState.collection && collections[urlState.collection])
          ? urlState.collection
          : 'all_videos';
      }
      this.currentPage = urlState.page > 0 ? urlState.page : 1;
      this.performSearch();
    } else {
      this.loadInitialSearch();
    }
  }

  loadInitialSearch() {
    // Admin defaults only fill in when the user has no saved preference of
    // their own — loadUserPreferences() already applied those, and
    // stomping them here meant the sidebar quietly reset on every visit.
    const collections = this.searchService.getCollections();
    const prefCollection = this.userPreferences?.collection;
    const prefSort = this.userPreferences?.sortBy;

    if (this.collection) {
      this.collection.value = (prefCollection && collections[prefCollection])
        ? prefCollection
        : (this.siteSettings.defaultCollection || 'all_videos');
    }
    if (this.sortBy) {
      this.sortBy.value = prefSort || this.siteSettings.defaultSort || 'downloads';
    }
    this.currentPage = 1;
    this.performSearch();
  }

  /**
   * Navigate to the dedicated player page
   */
  navigateToPlayer(id, track = null) {
    let url = `player.php?video=${encodeURIComponent(id)}`;
    if (track !== null && track !== undefined) {
      url += `&track=${track + 1}`;
    }
    window.location.href = url;
  }

  // ========================================
  // Search & Results
  // ========================================

  /**
   * @param {Object}  [opts]
   * @param {boolean} [opts.pushHistory=false]   Add a Back stop. Only for
   *        explicit navigations (submit, pagination, opening a collection);
   *        typing and filter tweaks replace the current entry instead.
   * @param {boolean} [opts.recordHistory=false] Save the term to the
   *        suggestions history. Only on submit / suggestion pick, so the
   *        debounced partials ("s", "st", "sta"...) don't pollute it.
   */
  async performSearch({ pushHistory = false, recordHistory = false } = {}) {
    // Cancel any in-flight search so a slow/failed older request can't
    // overwrite a newer successful one (race when user types quickly).
    if (this._searchAbort) {
      try { this._searchAbort.abort(); } catch {}
    }
    this._searchAbort = new AbortController();
    const mySignal = this._searchAbort.signal;
    const myToken = ++this._searchToken;

    const term = this.searchInput?.value.trim() || '';
    this.currentQuery = term || '*';

    if (recordHistory && term && this.searchSuggestions) {
      this.searchSuggestions.addToHistory(term);
    }

    // Never touch the URL while replaying a popstate — we'd be rewriting
    // the very entry the user just navigated to.
    if (!this._restoringFromHistory) {
      const urlParams = UrlManager.buildSearchUrl({
        search: term || undefined,
        collection: (this.collection?.value !== 'all_videos') ? this.collection.value : undefined,
        page: this.currentPage > 1 ? String(this.currentPage) : undefined
      });
      UrlManager.updateUrl(urlParams, pushHistory);
    }

    this.uiFeedback.showLoading();
    this.uiFeedback.hideError();

    try {
      const data = await this.searchService.searchArchive({
        query: this.currentQuery,
        page: this.currentPage,
        collection: this.collection?.value,
        sortBy: this.sortBy?.value,
        publicDomain: this.publicDomain?.checked,
        collectionsOnly: this.collectionsOnly?.checked,
        signal: mySignal,
      });

      // Bail out silently if this search was superseded — a newer request
      // is already on the wire (or has rendered) and owns the UI.
      if (myToken !== this._searchToken) return;

      if (!data || !data.response) throw new Error('Invalid response from Archive.org');

      const resp = data.response;
      this.totalResults = resp.numFound || 0;

      this.displayResults(resp.docs || []);
      this.updatePagination(resp.numFound || 0);
      this.uiFeedback.updateStats(
        resp.numFound || 0,
        this.currentPage,
        CONFIG.ITEMS_PER_PAGE,
        this.searchService.getCollectionDisplayName(this.collection?.value || 'all_videos')
      );
      this.updatePageTitle();

    } catch (err) {
      // Aborts are intentional — never surface as errors.
      if (err && (err.name === 'AbortError' || mySignal.aborted)) return;
      if (myToken !== this._searchToken) return;

      console.error('Search error:', err);

      // Distinguish "definitely offline" (probe-confirmed) from generic
      // failure so the user gets a useful message instead of the same
      // "you are offline" splash on every transient hiccup.
      if (this.offlineHandler.isDefinitelyOffline?.()) {
        this.uiFeedback.showError('You appear to be offline. Search will retry when you reconnect.');
      } else if (err && err.name === 'TimeoutError') {
        this.uiFeedback.showError('Search timed out. Archive.org may be slow right now — try again.');
      } else {
        this.uiFeedback.showError(`Search failed: ${err.message || 'Unknown error'}`);
      }
      this.uiFeedback.showFallbackMessage();
    } finally {
      if (myToken === this._searchToken) {
        this.uiFeedback.hideLoading();
      }
    }
  }

  displayResults(docs) {
    this.uiFeedback.hideLoading();
    if (!docs || !docs.length) {
      return this.uiFeedback.showNoResults(
        this.currentQuery,
        this.searchService.getCollectionDisplayName(this.collection?.value || 'all_videos')
      );
    }

    if (!this.results) return;

    const resultsHtml = docs.map(d => this.createResultCard(d)).join('');
    this.results.innerHTML = resultsHtml;
    this.attachCardEventListeners();

    // Queue displayed items for background caching (thumbnails and metadata)
    // This helps build up local cache as users browse
    if (this.backgroundCacheService) {
      this.backgroundCacheService.queueSearchResults(docs);
    }
  }

  attachCardEventListeners() {
    if (!this.results) return;

    this.results.querySelectorAll('.result-card').forEach(card => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('button, a')) return;

        const id = card.dataset.identifier;
        const mediatype = card.dataset.mediatype;

        if (mediatype === 'collection') {
          this.openCollection(card, id);
        } else {
          this.navigateToPlayer(id);
        }
      });
    });

    this.results.querySelectorAll('.btn-play, .btn-primary-action').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const card = e.target.closest('.result-card');
        const id = card.dataset.identifier;
        const mediatype = card.dataset.mediatype;

        if (mediatype === 'collection') {
          this.openCollection(card, id);
        } else {
          this.navigateToPlayer(id);
        }
      });
    });

    this.results.querySelectorAll('.btn-share').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const card = e.target.closest('.result-card');
        const id = card.dataset.identifier;
        this.shareVideo(id);
      });
    });

    this.results.querySelectorAll('.btn-bookmark').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const card = e.target.closest('.result-card');
        const id = card.dataset.identifier;
        const title = card.querySelector('.result-title').textContent;
        const creatorEl = card.querySelector('.result-creator');
        const creator = creatorEl ? creatorEl.textContent : 'Unknown';

        const video = { identifier: id, title, creator };

        if (this.bookmarkManager.isBookmarked(id)) {
          this.bookmarkManager.remove(id);
          btn.classList.remove('bookmarked');
          btn.innerHTML = ICONS.bookmark;
          this.showMessage('Removed from bookmarks', 'info');
        } else {
          this.bookmarkManager.add(video);
          btn.classList.add('bookmarked');
          btn.innerHTML = ICONS.bookmarkFilled;
          this.showMessage('Added to bookmarks!', 'success');
        }
      });
    });
  }

  /**
   * Re-sync every rendered card's bookmark toggle with the manager's list.
   * Called from bookmarkManager.onChange, so cards rendered before the
   * server list arrived (or before a login/logout) catch up.
   */
  syncBookmarkButtons() {
    if (!this.results) return;
    this.results.querySelectorAll('.result-card').forEach(card => {
      const btn = card.querySelector('.btn-bookmark');
      if (!btn) return;
      const on = this.bookmarkManager.isBookmarked(card.dataset.identifier);
      if (btn.classList.contains('bookmarked') === on) return;
      btn.classList.toggle('bookmarked', on);
      btn.innerHTML = on ? ICONS.bookmarkFilled : ICONS.bookmark;
    });
  }

  openCollection(card, id) {
    const collections = this.searchService.getCollections();
    if (!collections[id]) {
      const title = card.querySelector('.result-title').textContent;
      this.searchService.addCollection(id, title);
      this.populateCollections();
    }

    if (this.collection) this.collection.value = id;
    if (this.searchInput) this.searchInput.value = '';
    this.currentPage = 1;

    if (this.collectionsOnly) {
      this.collectionsOnly.checked = false;
    }

    this.performSearch({ pushHistory: true });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  createResultCard(item) {
    const title = extractValue(item.title) || 'Untitled';
    const creator = extractValue(item.creator) || 'Unknown';
    // Force en-US so date formatting doesn't vary by visitor locale on an
    // otherwise English-only UI. Wrap in a try because new Date() of an
    // invalid string returns 'Invalid Date' on some engines.
    const rawDate = extractValue(item.date);
    let date = '';
    if (rawDate) {
      try {
        const d = new Date(rawDate);
        if (!isNaN(d.getTime())) date = d.toLocaleDateString('en-US');
      } catch (_) { /* leave date empty */ }
    }
    const downloads = Number(item.downloads || 0).toLocaleString('en-US');
    const runtime = formatRuntime(item.runtime);
    // Archive identifier comes from the API and is matched [a-zA-Z0-9_.-] in
    // sanitizeArchiveId on the server, but the search endpoint returns the
    // raw archive.org payload -- defense in depth, URL-encode for use in
    // attribute contexts.
    const safeId = encodeURIComponent(item.identifier);
    const href = `https://archive.org/details/${safeId}`;
    const thumbUrl = getThumbnailUrl(item.identifier);
    const license = extractValue(item.licenseurl) || '';
    const subject = extractValue(item.subject) || '';
    const isPD = license.includes('publicdomain') || subject.toLowerCase().includes('public domain');
    const mediatype = extractValue(item.mediatype) || 'movies';
    const isBookmarked = this.bookmarkManager.isBookmarked(item.identifier);

    // Get display settings from admin config
    const showCreator = this.siteSettings.showCreator !== false;
    const showDate = this.siteSettings.showDate !== false;
    const showDownloadCount = this.siteSettings.showDownloadCount !== false;

    const progress = this.progressTracker.getProgress(item.identifier);
    const progressBar = progress ? `
      <div class="progress-indicator" style="width: ${progress.percentage}%"></div>
    ` : '';

    let actionButtonHtml;
    if (mediatype === 'collection') {
      actionButtonHtml = `<button class="btn btn-secondary btn-primary-action"><span class="btn-icon">${ICONS.folder}</span> Open Collection</button>`;
    } else {
      actionButtonHtml = `<button class="btn btn-play btn-primary-action"><span class="btn-icon">${ICONS.play}</span> ${progress ? 'Resume' : 'Play'}</button>`;
    }

    // Build meta items based on admin settings
    let metaItems = [];
    if (showCreator) {
      metaItems.push(`<span class="result-creator"><span class="meta-icon">${ICONS.user}</span> ${escapeHtml(creator)}</span>`);
    }
    if (showDate && date) {
      // date is already a locale-formatted Date string but escape defensively.
      metaItems.push(`<span><span class="meta-icon">${ICONS.calendar}</span> ${escapeHtml(date)}</span>`);
    }
    if (showDownloadCount && downloads) {
      metaItems.push(`<span><span class="meta-icon">${ICONS.download}</span> ${escapeHtml(downloads)}</span>`);
    }

    return `
      <article class="result-card" data-identifier="${escapeHtml(item.identifier)}" data-mediatype="${escapeHtml(mediatype)}">
        <div class="result-thumbnail">
          <img src="${thumbUrl}"
               alt="Thumbnail for ${escapeHtml(title)}"
               class="result-thumb"
               loading="lazy"
               decoding="async"
               onerror="this.style.display='none';this.parentNode.classList.add('thumb-missing')"/>
          ${runtime && mediatype !== 'collection' ? `<span class="runtime-badge">${runtime}</span>` : ''}
          ${isPD ? `<span class="license-badge">Public Domain</span>` : ''}
          ${progressBar}
          ${mediatype !== 'collection' ? `<div class="thumb-play-overlay"><span class="play-circle">${ICONS.play}</span></div>` : ''}
        </div>
        <div class="result-content">
          <div class="result-header">
            <h3 class="result-title">${escapeHtml(title)}</h3>
            <div class="result-meta">
              ${metaItems.join('\n              ')}
            </div>
          </div>
          <div class="result-description"></div>
          <div class="result-actions">
            ${actionButtonHtml}
            <a href="${href}" target="_blank" class="btn btn-archive">Archive</a>
            <button class="btn btn-share" title="Share video">${ICONS.link}</button>
            ${this.enableBookmarks && mediatype !== 'collection' ? `
              <button class="btn btn-bookmark ${isBookmarked ? 'bookmarked' : ''}" title="Bookmark">
                ${isBookmarked ? ICONS.bookmarkFilled : ICONS.bookmark}
              </button>
            ` : ''}
          </div>
        </div>
      </article>`;
  }

  // ========================================
  // Video Navigation (opens player page)
  // ========================================

  // ========================================
  // Sharing
  // ========================================

  shareVideo(id) {
    const basePath = window.location.pathname.replace(/\/[^/]*$/, '/');
    const link = `${window.location.origin}${basePath}player.php?video=${encodeURIComponent(id)}`;

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(link)
        .then(() => this.showMessage('Link copied to clipboard!', 'success'))
        .catch(() => this.showShareFallback(link));
    } else {
      this.showShareFallback(link);
    }
  }

  showShareFallback(url) {
    const overlay = document.createElement('div');
    overlay.className = 'share-modal-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Share link');
    overlay.innerHTML = `
      <div class="share-modal">
        <h3>Share this video</h3>
        <input type="text" class="share-modal-input" readonly />
        <button type="button" class="share-modal-close">Close</button>
      </div>`;
    const input = overlay.querySelector('input');
    input.value = url;
    document.body.appendChild(overlay);
    // trapFocus keeps Tab inside the dialog, locks body scroll, and hands
    // focus back to the Share button on release.
    const releaseTrap = trapFocus(overlay, { initialFocus: input });
    input.select();
    // close() always tears down BOTH the overlay and the document-level
    // keydown listener — otherwise dismissing via the X button or a
    // backdrop click would leak the Escape handler for the rest of the
    // session.
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const close = () => {
      document.removeEventListener('keydown', onKey);
      releaseTrap();
      overlay.remove();
    };
    overlay.querySelector('.share-modal-close').onclick = close;
    overlay.onclick = (e) => { if (e.target === overlay) close(); };
    document.addEventListener('keydown', onKey);
  }

  // ========================================
  // Messages & Notifications
  // ========================================

  showMessage(msg, type = 'info', duration = 3000) {
    this.toast.show(msg, type, duration);
  }

  // ========================================
  // Pagination
  // ========================================

  updatePagination(numFound) {
    if (!this.pagination) return;

    const paginationInfo = this.searchService.getPaginationInfo(numFound, this.currentPage);
    if (paginationInfo.totalPages <= 1) {
      this.pagination.innerHTML = '';
      return;
    }

    let html = '';
    if (paginationInfo.hasPrevious) {
      html += `<button data-page="${this.currentPage - 1}">&larr; Previous</button>`;
    }

    if (this.currentPage > 3) {
      html += `<button data-page="1">1</button>`;
      if (this.currentPage > 4) html += `<span>...</span>`;
    }

    const start = Math.max(1, this.currentPage - 2);
    const end = Math.min(paginationInfo.totalPages, this.currentPage + 2);
    for (let i = start; i <= end; i++) {
      const isCurrent = i === this.currentPage;
      html += `<button class="${isCurrent ? 'active' : ''}" data-page="${i}" aria-label="Page ${i}"${isCurrent ? ' aria-current="page"' : ''}>${i}</button>`;
    }

    if (this.currentPage < paginationInfo.totalPages - 2) {
      if (this.currentPage < paginationInfo.totalPages - 3) html += `<span>...</span>`;
      html += `<button data-page="${paginationInfo.totalPages}">${paginationInfo.totalPages}</button>`;
    }

    if (paginationInfo.hasNext) {
      html += `<button data-page="${this.currentPage + 1}">Next &rarr;</button>`;
    }

    this.pagination.innerHTML = html;
    this.pagination.querySelectorAll('button[data-page]').forEach(btn => {
      btn.addEventListener('click', () => {
        this.currentPage = parseInt(btn.dataset.page, 10);
        this.performSearch({ pushHistory: true });
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    });
  }

  // ========================================
  // Filter & Navigation
  // ========================================

  clearAllFilters() {
    if (this.collection) this.collection.value = 'all_videos';
    if (this.sortBy) this.sortBy.value = 'downloads';
    if (this.searchInput) {
      this.searchInput.value = '';
      if (this.clearSearchBtn) this.clearSearchBtn.style.display = 'none';
    }
    if (this.publicDomain) this.publicDomain.checked = false;
    if (this.collectionsOnly) this.collectionsOnly.checked = false;
    this.currentPage = 1;
    UrlManager.clearUrl();
    // Re-run the default listing rather than leaving the page empty
    // (mirrors goHome()).
    this.performSearch();
  }

  goHome() {
    if (this.collection) this.collection.value = 'all_videos';
    if (this.sortBy) this.sortBy.value = 'downloads';
    if (this.searchInput) {
      this.searchInput.value = '';
      if (this.clearSearchBtn) this.clearSearchBtn.style.display = 'none';
    }
    if (this.publicDomain) this.publicDomain.checked = false;
    if (this.collectionsOnly) this.collectionsOnly.checked = false;

    this.currentPage = 1;
    this.currentQuery = '';

    UrlManager.clearUrl();

    this.updatePageTitle();

    if (this.recommendedManager && !this.recommendedManager.isHidden) {
      this.recommendedManager.show();
    }
    if (this.continueWatchingManager) {
      // Re-read localStorage in case the player page wrote new progress.
      this.continueWatchingManager.refresh();
    }

    this.performSearch();

    window.scrollTo({ top: 0, behavior: 'smooth' });

    this.closeMobileMenu();
  }
}

// Initialize the application when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  // Disarm the inline app-load watchdog (index.php): the module loaded and is
  // about to initialize. Init errors below are handled by the catch; the
  // watchdog only fires when this module never executes at all.
  window.__afcReady = true;
  try {
    window.archiveSearch = new ArchiveVideoSearch();
    console.log('Application loaded successfully');
  } catch (error) {
    console.error('Failed to initialize application:', error);

    const errorMessage = document.createElement('div');
    errorMessage.style.cssText = `
      position: fixed; top: 20px; left: 20px; right: 20px;
      background: #ff4444; color: white; padding: 1rem;
      border-radius: 8px; z-index: 10000;
    `;
    errorMessage.innerHTML = `
      <strong>Application Error:</strong> Failed to load. Please refresh the page.
      <button onclick="location.reload()" style="margin-left: 1rem; padding: 0.5rem; background: white; color: #ff4444; border: none; border-radius: 4px; cursor: pointer;">Refresh</button>
    `;
    document.body.appendChild(errorMessage);
  }
});

export default ArchiveVideoSearch;

// Service worker registration.
// Using a relative URL ('sw.js') means a /films/ subdirectory install registers
// /films/sw.js with scope /films/ automatically. Do not change to a leading-slash
// path -- that would break subdirectory deployments.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch((err) => {
      console.warn('[SW] Registration failed:', err);
    });
  });
}
