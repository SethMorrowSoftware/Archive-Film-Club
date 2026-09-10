/**
 * Archive Film Club - Dedicated Player Page v2.0
 * Immersive video player with rich playlist, quality selector, theater mode
 */

import { VideoService } from './src/js/services/VideoService.js';
import { PlaylistService } from './src/js/services/PlaylistService.js';
import { VideoProgressTracker } from './src/js/services/VideoProgressTracker.js';
import { BookmarkManager } from './src/js/services/BookmarkManager.js';
import { Toast } from './src/js/components/Toast.js';
import { AuthNav } from './src/js/components/AuthNav.js';
import { CollectionPicker } from './src/js/components/CollectionPicker.js';
import { PlayerUI } from './src/js/player/PlayerUI.js';
import { PlayerPlaylist } from './src/js/player/PlayerPlaylist.js';
import { PlayerComments } from './src/js/player/PlayerComments.js';

// Mount auth nav early
AuthNav.mount();
import {
  escapeHtml,
  sanitizeHtml,
  extractValue,
  formatRuntime,
  formatTime,
  formatFileSize
} from './src/js/utils/helpers.js';

class VideoPlayer {
  constructor() {
    this.videoService = new VideoService();
    this.playlistService = new PlaylistService(this.videoService);
    this.progressTracker = new VideoProgressTracker();
    this.bookmarkManager = new BookmarkManager();
    this.toast = new Toast();
    this.ui = new PlayerUI();
    this.playlist = new PlayerPlaylist(this.videoService, this.playlistService);
    this.comments = new PlayerComments({ toast: this.toast });
    const commentsEl = document.getElementById('commentsSection');
    if (commentsEl) this.comments.mount(commentsEl);

    this.siteSettings = this.loadSiteSettings();
    this.videoId = null;
    this.trackIndex = null;
    this.metadata = null;
    this.videoFiles = [];
    this.allVideoFiles = []; // All files including quality variants
    this.currentFileName = null;
    this.descriptionExpanded = true;
    // Tracks playlist indices that have failed to play in this session,
    // so cascading auto-skip never re-tries a known-bad item.
    this.failedPlaylistIndices = new Set();
    // True once the CURRENT source has reached a playable state (canplay /
    // playing). Reset on every (re)load. Drives two behaviours:
    //   1. the mid-playback buffering spinner only appears after this is
    //      true, so it never stacks on the browser's own first-frame
    //      spinner (half of the "double spinner" bug);
    //   2. the <video> error handler only auto-skips / falls back when a
    //      source FAILED TO LOAD (never became playable). A transient error
    //      while seeking an already-playing video must NOT skip the episode.
    this._sourceCanPlay = false;

    this.initElements();
    this.setupEventListeners();
    this.parseUrlAndLoad();
  }

  loadSiteSettings() {
    const el = document.getElementById('siteSettingsConfig');
    if (el) {
      try { return JSON.parse(el.textContent); } catch (e) {}
    }
    return {};
  }

  initElements() {
    this.videoWrapper = document.getElementById('videoWrapper');
    this.playerLoader = document.getElementById('playerLoader');
    this.videoTitle = document.getElementById('videoTitle');
    this.videoCreator = document.getElementById('videoCreator');
    this.videoDate = document.getElementById('videoDate');
    this.videoMetaPills = document.getElementById('videoMetaPills');
    this.videoTagsRow = document.getElementById('videoTagsRow');
    this.archiveLink = document.getElementById('archiveLink');
    this.descriptionSection = document.getElementById('descriptionSection');
    this.descriptionToggle = document.getElementById('descriptionToggle');
    this.descriptionContent = document.getElementById('descriptionContent');
    this.downloadsPanel = document.getElementById('downloadsPanel');
    this.downloadLinks = document.getElementById('downloadLinks');
    this.shareBtn = document.getElementById('shareBtn');
    this.bookmarkBtn = document.getElementById('bookmarkBtn');
    this.saveToCollectionBtn = document.getElementById('saveToCollectionBtn');
    this.downloadBtn = document.getElementById('downloadBtn');
    this.reportBtn = document.getElementById('reportBtn');
    this.closeDownloads = document.getElementById('closeDownloads');
    this.upNextOverlay = document.getElementById('upNextOverlay');
    this.upNextThumb = document.getElementById('upNextThumb');
    if (this.upNextThumb) {
      this.upNextThumb.addEventListener('error', () => {
        this.upNextThumb.hidden = true;
      });
      this.upNextThumb.addEventListener('load', () => {
        this.upNextThumb.hidden = false;
      });
    }
    this.upNextTitle = document.getElementById('upNextTitle');
    this.upNextCountdown = document.getElementById('upNextCountdown');
    this.upNextPlay = document.getElementById('upNextPlay');
    this.upNextCancel = document.getElementById('upNextCancel');
    this.playerCinema = document.getElementById('playerCinema');
    this.bufferingIndicator = document.getElementById('bufferingIndicator');
    this.pipBtn = document.getElementById('pipBtn');
    this.captionsBtn = document.getElementById('captionsBtn');
    this.speedBtn = document.getElementById('speedBtn');
    this.speedMenu = document.getElementById('speedMenu');
    this.speedLabel = document.getElementById('speedLabel');
    this.shortcutsHelp = document.getElementById('shortcutsHelp');
    this.shortcutsHelpClose = document.getElementById('shortcutsHelpClose');
    this._upNextTimer = null;

    this.playbackRate = this._loadStoredNumber('playerPlaybackRate', 1);
    this.preferredQualityLabel = this._loadStoredString('playerQualityLabel', null);
    this.captionsEnabled = this._loadStoredString('playerCaptionsOn', '') === '1';
  }

  _loadStoredNumber(key, fallback) {
    try {
      const n = parseFloat(localStorage.getItem(key));
      return Number.isFinite(n) && n > 0 ? n : fallback;
    } catch (e) { return fallback; }
  }
  _loadStoredString(key, fallback) {
    try {
      const s = localStorage.getItem(key);
      return s || fallback;
    } catch (e) { return fallback; }
  }

  setupEventListeners() {
    // Share
    if (this.shareBtn) this.shareBtn.addEventListener('click', () => this.shareVideo());

    // Report (links out to archive.org — we host no content)
    if (this.reportBtn) this.reportBtn.addEventListener('click', () => this.openReportDialog());

    // Bookmark
    if (this.bookmarkBtn) this.bookmarkBtn.addEventListener('click', () => this.toggleBookmark());

    // Save to collection
    if (this.saveToCollectionBtn) {
      this.saveToCollectionBtn.addEventListener('click', () => this.openCollectionPicker());
    }

    // Downloads
    if (this.downloadBtn) this.downloadBtn.addEventListener('click', () => this.toggleDownloads());
    if (this.closeDownloads) this.closeDownloads.addEventListener('click', () => this.hideDownloads());

    // Description
    if (this.descriptionToggle) this.descriptionToggle.addEventListener('click', () => this.toggleDescription());

    // Keyboard
    document.addEventListener('keydown', (e) => this.handleKeyboard(e));

    // After a MOUSE click on one of the player's own buttons, drop focus
    // from it. Otherwise the button keeps focus, Space re-activates it
    // (e.g. "Next episode" fires again) instead of pausing, and the arrow
    // keys never reach the video shortcuts. Keyboard activation (detail=0)
    // keeps focus so Tab navigation is unaffected.
    document.addEventListener('click', (e) => {
      if (!e.detail) return;
      const btn = e.target && e.target.closest
        ? e.target.closest('.pctl-btn, .sidebar-nav-btn, .player-pill-btn, .player-action-btn, .up-next-btn, .density-btn, .resume-btn, .resume-dismiss, .playlist-item')
        : null;
      if (btn && typeof btn.blur === 'function') btn.blur();
    });

    // Popstate
    window.addEventListener('popstate', () => this.parseUrlAndLoad());

    // Episode navigation buttons
    if (this.ui.prevEpisodeBtn) {
      this.ui.prevEpisodeBtn.addEventListener('click', () => this.playPreviousEpisode());
    }
    if (this.ui.nextEpisodeBtn) {
      this.ui.nextEpisodeBtn.addEventListener('click', () => this.playNextEpisode());
    }
    if (this.ui.sidebarPrevBtn) {
      this.ui.sidebarPrevBtn.addEventListener('click', () => this.playPreviousEpisode());
    }
    if (this.ui.sidebarNextBtn) {
      this.ui.sidebarNextBtn.addEventListener('click', () => this.playNextEpisode());
    }

    // Up Next overlay
    if (this.upNextPlay) this.upNextPlay.addEventListener('click', () => this.confirmUpNext());
    if (this.upNextCancel) this.upNextCancel.addEventListener('click', () => this.cancelUpNext());

    // Mirror fullscreen state on the page so CSS can adapt the layout.
    // Triggered by the native <video> fullscreen button or the `f` keyboard shortcut.
    document.addEventListener('fullscreenchange', () => this._syncFullscreenState());
    document.addEventListener('webkitfullscreenchange', () => this._syncFullscreenState());

    // Playback speed menu
    if (this.speedBtn) {
      this.speedBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        this._toggleSpeedMenu();
      });
    }
    if (this.speedMenu) {
      this.speedMenu.addEventListener('click', (e) => e.stopPropagation());
    }
    document.addEventListener('click', () => this._closeSpeedMenu());

    // Picture-in-picture
    if (this.pipBtn) {
      this.pipBtn.addEventListener('click', () => this.togglePictureInPicture());
    }

    // Captions toggle
    if (this.captionsBtn) {
      this.captionsBtn.addEventListener('click', () => this.toggleCaptions());
    }

    // Keyboard shortcuts help overlay
    if (this.shortcutsHelpClose) {
      this.shortcutsHelpClose.addEventListener('click', () => this._hideShortcutsHelp());
    }
    if (this.shortcutsHelp) {
      this.shortcutsHelp.addEventListener('click', (e) => {
        if (e.target === this.shortcutsHelp) this._hideShortcutsHelp();
      });
    }
  }

  /**
   * Fullscreen target is the cinema container, NOT the bare <video>:
   *   - keeps the Up Next overlay + shortcut indicator visible across episodes
   *   - keeps fullscreen alive when the playlist auto-advances, because
   *     VideoService now reuses the <video> element instead of replacing
   *     the wrapper's innerHTML.
   */
  toggleFullscreen() {
    const target = this.playerCinema || this.videoWrapper;
    if (!target) return;
    const fsEl = document.fullscreenElement || document.webkitFullscreenElement;

    if (fsEl) {
      const exit = document.exitFullscreen || document.webkitExitFullscreen;
      if (exit) exit.call(document);
      return;
    }

    const req = target.requestFullscreen || target.webkitRequestFullscreen;
    if (!req) return;
    const result = req.call(target);
    if (result && typeof result.catch === 'function') {
      result.catch(err => {
        console.warn('[player] fullscreen request rejected:', err && err.message);
      });
    }
  }

  _syncFullscreenState() {
    const fs = document.fullscreenElement || document.webkitFullscreenElement;
    document.body.classList.toggle('player-is-fullscreen', !!fs);

    // If the user entered fullscreen via the <video>'s native button
    // (common on mobile/touch), fullscreen lives on the bare <video> and
    // NOT on the cinema container. That breaks two things:
    //   1) The Up Next overlay + shortcut indicator are siblings of the
    //      video, so they're invisible in fullscreen.
    //   2) Swapping the <source> for the next episode forces the browser
    //      to drop fullscreen, so auto-advance kicks the user back out.
    // Promote fullscreen to the cinema container — browsers allow
    // re-requesting fullscreen on a different element while already in
    // fullscreen, and the transition is seamless.
    if (fs && this.playerCinema && fs !== this.playerCinema) {
      this._promoteFullscreenToCinema();
    }
  }

  // ========================================
  // Picture-in-Picture
  // ========================================

  _currentVideoMeta() {
    const meta = this.metadata && (this.metadata.metadata || this.metadata);
    if (!meta) return null;
    const out = {
      title: extractValue(meta.title) || null,
      creator: extractValue(meta.creator) || null,
    };
    // Remember which part of a series is playing so the next visit (and
    // the homepage's Continue Watching row) can resume the right episode.
    if (this.playlistService.getPlaylist()) {
      out.track = this.playlistService.getCurrentIndex();
    }
    return out;
  }

  // ========================================
  // Resume / seek helpers
  // ========================================

  /**
   * Seek to `seconds` as soon as the CURRENT source knows its duration.
   * The <video> element is reused across episodes, so a token guards
   * against a stale listener seeking the *next* source if the user
   * changes track before this one's metadata arrives.
   */
  _seekWhenReady(video, seconds) {
    if (!video || !Number.isFinite(seconds) || seconds < 0) return;
    const token = (this._seekToken = {});
    const apply = () => {
      if (this._seekToken !== token) return;
      const dur = video.duration;
      const upper = Number.isFinite(dur) && dur > 0 ? Math.max(0, dur - 1) : Infinity;
      try { video.currentTime = Math.min(seconds, upper); } catch (e) { /* not seekable yet */ }
    };
    if (video.readyState >= 1) apply();
    else video.addEventListener('loadedmetadata', apply, { once: true });
  }

  /** A saved position worth offering: past the intro, not at the end. */
  _isResumable(progress) {
    if (!progress || !progress.duration) return false;
    const t = Number(progress.currentTime) || 0;
    const pct = progress.percentage != null
      ? Number(progress.percentage)
      : (t / Number(progress.duration)) * 100;
    return t > 10 && pct < 95;
  }

  /** Single-video resume from the item-level tracker (local, then server). */
  _offerResume(video) {
    const show = (progress) => {
      if (!this._isResumable(progress)) return;
      // Don't interrupt a viewer who already scrubbed somewhere.
      if (video.currentTime > 10) return;
      this.ui.showResumePrompt(formatTime(progress.currentTime), () => {
        this._seekWhenReady(video, progress.currentTime);
      });
    };
    show(this.progressTracker.getProgress(this.videoId));
    // Signed-in viewers may have progress from another device; the server
    // copy arrives asynchronously and only re-prompts when it's newer.
    const id = this.videoId;
    this.progressTracker.fetchProgress(id).then((remote) => {
      if (id !== this.videoId || !remote) return;
      const local = this.progressTracker.getProgress(id);
      if (!local || Math.abs((remote.currentTime || 0) - (local.currentTime || 0)) > 5) show(remote);
    }).catch(() => {});
  }

  /** Per-episode resume from the playlist's own progress store. */
  _offerTrackResume(video, index) {
    const progress = this.playlist.getTrackProgress(index);
    if (!progress || progress.watched || !this._isResumable(progress)) return;
    this.ui.showResumePrompt(formatTime(progress.currentTime), () => {
      this._seekWhenReady(video, progress.currentTime);
    });
  }

  // ========================================
  // Media Session (lock screen / hardware keys / OS media overlay)
  // ========================================

  _updateMediaSession(title, creator) {
    if (!('mediaSession' in navigator)) return;
    try {
      navigator.mediaSession.metadata = new MediaMetadata({
        title: title || this.videoId || '',
        artist: creator || '',
        album: this.siteSettings.siteName || 'Archive Film Club',
        artwork: this.videoId
          ? [{ src: `https://archive.org/services/img/${this.videoId}`, type: 'image/jpeg' }]
          : [],
      });
    } catch (e) { /* older browsers */ }
    this._setupMediaSessionHandlers();
  }

  _setupMediaSessionHandlers() {
    if (!('mediaSession' in navigator) || this._mediaSessionWired) return;
    this._mediaSessionWired = true;
    const video = () => this.videoWrapper?.querySelector('video');
    const handlers = {
      play: () => { const v = video(); if (v) v.play().catch(() => {}); },
      pause: () => { const v = video(); if (v) v.pause(); },
      seekbackward: (d) => this._seekBy(video(), -((d && d.seekOffset) || 10)),
      seekforward: (d) => this._seekBy(video(), (d && d.seekOffset) || 10),
      seekto: (d) => { const v = video(); if (v && d && Number.isFinite(d.seekTime)) v.currentTime = d.seekTime; },
      previoustrack: () => this.playPreviousEpisode(),
      nexttrack: () => this.playNextEpisode(),
    };
    for (const action of Object.keys(handlers)) {
      try { navigator.mediaSession.setActionHandler(action, handlers[action]); } catch (e) { /* unsupported action */ }
    }
  }

  async togglePictureInPicture() {
    const video = this.videoWrapper?.querySelector('video');
    if (!video || !document.pictureInPictureEnabled || video.disablePictureInPicture) {
      this.toast.show('Picture-in-picture is not available', 'info');
      return;
    }
    try {
      if (document.pictureInPictureElement) {
        await document.exitPictureInPicture();
      } else {
        await video.requestPictureInPicture();
      }
    } catch (e) {
      console.warn('[player] PiP toggle failed:', e?.message);
    }
  }

  // ========================================
  // Captions / Subtitles
  // ========================================

  toggleCaptions() {
    const video = this.videoWrapper?.querySelector('video');
    if (!video || !video.textTracks || video.textTracks.length === 0) {
      this.toast.show('No captions available for this video', 'info');
      return;
    }
    this.captionsEnabled = !this.captionsEnabled;
    try { localStorage.setItem('playerCaptionsOn', this.captionsEnabled ? '1' : '0'); } catch (e) {}
    this._applyCaptionState(video);
    this.ui.showShortcutIndicator(this.captionsEnabled ? 'Captions on' : 'Captions off');
  }

  /**
   * Apply the persisted captions preference to whatever text tracks are
   * currently mounted on the <video>. Called on every (re)attach so a
   * fresh element after navigation respects the user's last choice.
   */
  _applyCaptionState(videoEl) {
    if (!videoEl || !videoEl.textTracks) return;
    const tracks = videoEl.textTracks;
    const hasAny = tracks.length > 0;

    if (this.captionsBtn) {
      this.captionsBtn.style.display = hasAny ? '' : 'none';
      this.captionsBtn.classList.toggle('active', !!this.captionsEnabled && hasAny);
      this.captionsBtn.setAttribute('aria-pressed', String(!!this.captionsEnabled && hasAny));
    }

    if (!hasAny) return;
    // Prefer English when available, otherwise the first track.
    let chosen = 0;
    for (let i = 0; i < tracks.length; i++) {
      const lang = (tracks[i].language || '').toLowerCase();
      if (lang.startsWith('en')) { chosen = i; break; }
    }
    for (let i = 0; i < tracks.length; i++) {
      tracks[i].mode = (this.captionsEnabled && i === chosen) ? 'showing' : 'disabled';
    }
  }

  // ========================================
  // Playback Speed
  // ========================================

  _applyPlaybackRate(videoEl) {
    if (!videoEl) return;
    try { videoEl.playbackRate = this.playbackRate || 1; } catch (e) {}
    if (this.speedLabel) {
      this.speedLabel.textContent = this._formatRateLabel(this.playbackRate || 1);
    }
  }

  _formatRateLabel(rate) {
    if (Math.abs(rate - 1) < 0.001) return '1x';
    // Trim trailing zeros: 1.25x, 1.5x, 0.75x
    return `${parseFloat(rate.toFixed(2))}x`;
  }

  setPlaybackRate(rate) {
    this.playbackRate = rate;
    try { localStorage.setItem('playerPlaybackRate', String(rate)); } catch (e) {}
    const video = this.videoWrapper?.querySelector('video');
    this._applyPlaybackRate(video);
    this.ui.showShortcutIndicator(`Speed ${this._formatRateLabel(rate)}`);
    this._renderSpeedMenu();
  }

  _toggleSpeedMenu() {
    if (!this.speedMenu) return;
    const opening = !this.speedMenu.classList.contains('open');
    if (opening) this._renderSpeedMenu();
    this.speedMenu.classList.toggle('open', opening);
  }
  _closeSpeedMenu() {
    if (this.speedMenu) this.speedMenu.classList.remove('open');
  }

  _renderSpeedMenu() {
    if (!this.speedMenu) return;
    const steps = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
    this.speedMenu.innerHTML = steps.map(s => {
      const active = Math.abs(s - this.playbackRate) < 0.001;
      const label = s === 1 ? 'Normal' : this._formatRateLabel(s);
      return `<button class="quality-option ${active ? 'active' : ''}" data-rate="${s}">
        <span>${label}</span>
      </button>`;
    }).join('');
    this.speedMenu.querySelectorAll('[data-rate]').forEach(btn => {
      btn.addEventListener('click', () => {
        this._closeSpeedMenu();
        this.setPlaybackRate(parseFloat(btn.dataset.rate));
      });
    });
  }

  // ========================================
  // Buffering Indicator (mid-playback only)
  // ========================================

  _showBuffering() {
    if (!this.bufferingIndicator) return;
    // The big initial-load / switching loader owns the cinema — never stack
    // the buffering spinner on top of it (this was the "double spinner" bug:
    // both elements render the identical .loading-spinner markup).
    if (this.playerLoader && this.playerLoader.style.display !== 'none') return;
    // Suppress until the current source is actually playable, so this
    // mid-playback spinner never overlaps the browser's own spinner drawn
    // on the <video> while it buffers the very first frames.
    if (!this._sourceCanPlay) return;
    this.bufferingIndicator.classList.add('visible');
  }
  _hideBuffering() {
    if (this.bufferingIndicator) this.bufferingIndicator.classList.remove('visible');
  }

  // ========================================
  // Keyboard Shortcuts Help
  // ========================================

  _toggleShortcutsHelp() {
    if (!this.shortcutsHelp) return;
    if (this.shortcutsHelp.hidden) this._showShortcutsHelp();
    else this._hideShortcutsHelp();
  }
  _showShortcutsHelp() {
    if (!this.shortcutsHelp) return;
    this.shortcutsHelp.hidden = false;
    requestAnimationFrame(() => this.shortcutsHelp.classList.add('visible'));
  }
  _hideShortcutsHelp() {
    if (!this.shortcutsHelp) return;
    this.shortcutsHelp.classList.remove('visible');
    setTimeout(() => { if (this.shortcutsHelp) this.shortcutsHelp.hidden = true; }, 180);
  }

  _promoteFullscreenToCinema() {
    if (this._promotingFullscreen) return;
    const target = this.playerCinema;
    if (!target) return;
    const req = target.requestFullscreen || target.webkitRequestFullscreen;
    if (!req) return;
    this._promotingFullscreen = true;
    try {
      const result = req.call(target);
      if (result && typeof result.then === 'function') {
        result.finally(() => { this._promotingFullscreen = false; });
      } else {
        this._promotingFullscreen = false;
      }
    } catch (e) {
      this._promotingFullscreen = false;
    }
  }

  // ========================================
  // Keyboard Shortcuts
  // ========================================

  handleKeyboard(e) {
    // Returns true when the key was consumed (and preventDefault() called),
    // so the <video> capture listener can stop native controls from acting
    // on the same key. Returns false to let the browser handle it.

    // Global shortcuts that should fire regardless of focus target.
    if (e.key === 'Escape' && this.shortcutsHelp && !this.shortcutsHelp.hidden) {
      e.preventDefault();
      this._hideShortcutsHelp();
      return true;
    }

    const target = e.target || {};
    const tag = target.tagName;
    const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;

    if (e.key === '?' && !typing) {
      e.preventDefault();
      this._toggleShortcutsHelp();
      return true;
    }

    if (typing || tag === 'BUTTON') return false;
    // Never hijack browser/OS chords (Ctrl+F find, Cmd+L, Alt+Left…).
    if (e.ctrlKey || e.metaKey || e.altKey) return false;

    const video = this.videoWrapper?.querySelector('video');
    const playIcon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="white"><path d="M5 3L19 12L5 21V3Z"/></svg>';
    const pauseIcon = '<svg width="28" height="28" viewBox="0 0 24 24" fill="white"><path d="M6 4H10V20H6V4ZM14 4H18V20H14V4Z"/></svg>';

    switch (e.key) {
      case ' ':
      case 'k':
        if (!video) return false;
        e.preventDefault();
        if (video.paused) {
          video.play().catch(() => {});
          this.ui.showShortcutIndicator('Play', playIcon);
        } else {
          video.pause();
          this.ui.showShortcutIndicator('Pause', pauseIcon);
        }
        return true;
      case 'f':
        e.preventDefault();
        this.toggleFullscreen();
        return true;
      case 'm':
        if (!video) return false;
        e.preventDefault();
        video.muted = !video.muted;
        this.ui.showShortcutIndicator(video.muted ? 'Muted' : 'Unmuted');
        return true;
      case 't':
        e.preventDefault();
        this.ui.toggleTheaterMode();
        return true;
      case 'ArrowLeft':
        if (!video) return false;
        e.preventDefault();
        this._seekBy(video, -5);
        this.ui.showShortcutIndicator('-5s');
        return true;
      case 'ArrowRight':
        if (!video) return false;
        e.preventDefault();
        this._seekBy(video, 5);
        this.ui.showShortcutIndicator('+5s');
        return true;
      case 'ArrowUp':
        if (!video) return false;
        e.preventDefault();
        video.volume = Math.min(1, video.volume + 0.1);
        if (video.volume > 0 && video.muted) video.muted = false;
        this.ui.showShortcutIndicator(`Volume ${Math.round(video.volume * 100)}%`);
        return true;
      case 'ArrowDown':
        if (!video) return false;
        e.preventDefault();
        video.volume = Math.max(0, video.volume - 0.1);
        this.ui.showShortcutIndicator(`Volume ${Math.round(video.volume * 100)}%`);
        return true;
      case 'j':
        if (!video) return false;
        e.preventDefault();
        this._seekBy(video, -10);
        this.ui.showShortcutIndicator('-10s');
        return true;
      case 'l':
        if (!video) return false;
        e.preventDefault();
        this._seekBy(video, 10);
        this.ui.showShortcutIndicator('+10s');
        return true;
      case 'N':
        if (!e.shiftKey) return false;
        e.preventDefault();
        this.playNextEpisode();
        return true;
      case 'P':
        if (!e.shiftKey) return false;
        e.preventDefault();
        this.playPreviousEpisode();
        return true;
      case 'i':
        e.preventDefault();
        this.togglePictureInPicture();
        return true;
      case 'c':
        e.preventDefault();
        this.toggleCaptions();
        return true;
      case '>':
      case '.':
        if (!video) return false;
        if (e.key !== '>' && !e.shiftKey) return false;
        e.preventDefault();
        this._bumpPlaybackRate(+1);
        return true;
      case '<':
      case ',':
        if (!video) return false;
        if (e.key !== '<' && !e.shiftKey) return false;
        e.preventDefault();
        this._bumpPlaybackRate(-1);
        return true;
      default:
        return false;
    }
  }

  /**
   * Seek relative to the current position, in seconds (negative rewinds).
   * Before metadata loads, `video.duration` is NaN — clamping to it would
   * snap the video to 0, so an unknown duration is treated as "no upper
   * bound" and the browser clamps to the seekable range itself.
   */
  _seekBy(video, deltaSeconds) {
    if (!video) return;
    const upper = Number.isFinite(video.duration) ? video.duration : Infinity;
    const target = (video.currentTime || 0) + deltaSeconds;
    video.currentTime = Math.max(0, Math.min(upper, target));
  }

  _bumpPlaybackRate(direction) {
    const steps = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
    const currentIdx = steps.findIndex(s => Math.abs(s - this.playbackRate) < 0.001);
    const safeIdx = currentIdx === -1 ? steps.indexOf(1) : currentIdx;
    const nextIdx = Math.max(0, Math.min(steps.length - 1, safeIdx + direction));
    this.setPlaybackRate(steps[nextIdx]);
  }

  // ========================================
  // URL Parsing & Loading
  // ========================================

  parseUrlAndLoad() {
    const params = new URLSearchParams(window.location.search);
    // Sanitize to the same charset the server enforces (sanitizeArchiveId:
    // [a-zA-Z0-9_.-]). The raw param is interpolated into innerHTML in a few
    // places (download links, playlist thumbnails), so stripping HTML-meta
    // characters here closes reflected-XSS via a crafted ?video= link. A
    // "dirty" id would never resolve against archive.org anyway.
    const videoId = (params.get('video') || '').replace(/[^a-zA-Z0-9_.\-]/g, '');
    const track = params.get('track') ? parseInt(params.get('track'), 10) - 1 : null;
    const timestamp = params.get('t') ? parseInt(params.get('t'), 10) : null;

    if (!videoId) {
      this.showError('No video specified. <a href="index.php">Browse videos</a>');
      return;
    }

    this.videoId = videoId;
    this.trackIndex = track;
    this.requestedTimestamp = timestamp;
    this.loadVideo();
  }

  async loadVideo() {
    this.showLoader();
    this.failedPlaylistIndices = new Set();

    try {
      const metadata = await this.videoService.getVideoMetadata(this.videoId);
      this.metadata = metadata;

      const meta = metadata.metadata || metadata;
      this.updateVideoInfo(meta);

      // Kick off comments load in the background — never blocks playback.
      if (this.comments && this.videoId) {
        this.comments.load(this.videoId).catch(() => {});
      }

      // Pre-compute the playable + deduplicated file list BEFORE we kick
      // off playback, so multi-episode items load the requested track
      // (or episode 1) instead of whatever the global "best quality"
      // heuristic happens to pick.
      const allFiles = this.videoService.getVideoFiles(
        metadata.metadata ? metadata : { metadata, files: metadata.files }
      );
      const deduplicatedFiles = this.videoService.deduplicateVideoFiles(allFiles);
      const isMultiEpisode = deduplicatedFiles.length > 1
        && this.videoService.hasMultipleUniqueVideos(deduplicatedFiles);

      let initialFile = null;
      let startIndex = 0;
      if (isMultiEpisode) {
        if (this.trackIndex !== null
            && this.trackIndex >= 0
            && this.trackIndex < deduplicatedFiles.length) {
          startIndex = this.trackIndex;
        } else {
          // No explicit ?track= — pick up the series where this device
          // left off (saved alongside the item-level progress) instead of
          // dropping the viewer back on episode 1 every visit.
          const saved = this.progressTracker.getProgress(this.videoId);
          const savedTrack = saved && Number.isInteger(saved.track) ? saved.track : null;
          if (savedTrack !== null && savedTrack >= 0 && savedTrack < deduplicatedFiles.length) {
            startIndex = savedTrack;
          }
        }
        this.allVideoFiles = allFiles;
        initialFile = this._preferredVariant(deduplicatedFiles[startIndex]?.name || null);
      } else if (this.preferredQualityLabel) {
        // Single-video case: honor the user's last picked quality so they
        // don't have to re-select 1080p on every new video.
        const ranked = allFiles.filter(f => (f.name || '').toLowerCase().endsWith('.mp4'));
        const match = ranked.find(f => this.ui.getQualityLabel(f.name) === this.preferredQualityLabel);
        if (match) initialFile = match.name;
      }

      // Load and play
      const videoData = await this.videoService.loadNativeVideo(
        this.videoId,
        metadata,
        this.videoWrapper,
        initialFile,
        this.getSavedVolume()
      );

      this.hideLoader();
      this.currentFileName = videoData.selectedFile?.name;
      this.setupVideoListeners(videoData.videoElement);
      this.ui.hideResumePrompt();

      this.videoFiles = deduplicatedFiles;
      this.allVideoFiles = videoData.videoFiles;

      if (isMultiEpisode) {
        this.setupPlaylist(meta, deduplicatedFiles, startIndex);
        const epTitle = this.videoService.getCleanTitle(initialFile, extractValue(meta.title));
        this._updateMediaSession(epTitle, extractValue(meta.creator));
      } else {
        // Single video — make sure no playlist from a previous load lingers
        // in the service, or the failed-to-load auto-skip would try to
        // "advance to the next episode" of an item we're no longer playing.
        this.playlistService.clearPlaylist();
        this.playlist.hide();
        this._updateMediaSession(extractValue(meta.title), extractValue(meta.creator));
      }

      if (videoData.videoElement) {
        if (this.requestedTimestamp) {
          // ?t=SECONDS deep link. Seek once the duration is known (the old
          // fixed 300ms timer raced the metadata load and often lost).
          this._seekWhenReady(videoData.videoElement, this.requestedTimestamp);
        } else if (isMultiEpisode) {
          this._offerTrackResume(videoData.videoElement, startIndex);
        } else {
          this._offerResume(videoData.videoElement);
        }
      }

      // Quality selector. Single videos offer every encode of the item;
      // series offer the encodes of the episode that is playing.
      this._buildQualityOptions(this.currentFileName);

      // Downloads
      this.buildDownloadLinks(deduplicatedFiles.length > 0 ? deduplicatedFiles : videoData.videoFiles);

      // Bookmark state
      this.updateBookmarkState();

    } catch (err) {
      console.warn('Native load failed, trying iframe:', err.message);
      try {
        this.videoService.loadIframeVideo(this.videoId, this.videoWrapper);
        this.hideLoader();
        if (this.metadata) {
          const meta = this.metadata.metadata || this.metadata;
          this.updateVideoInfo(meta);
        }
      } catch (iframeErr) {
        this.showError('Failed to load video. <a href="index.php">Browse videos</a>');
      }
    }
  }

  // ========================================
  // Video Info
  // ========================================

  updateVideoInfo(meta) {
    const title = extractValue(meta.title) || this.videoId;
    const creator = extractValue(meta.creator) || '';
    const date = extractValue(meta.date) || '';
    const description = extractValue(meta.description) || '';

    document.title = `${title} - ${this.siteSettings.siteName || 'Archive Film Club'}`;

    if (this.videoTitle) this.videoTitle.textContent = title;

    if (this.videoCreator && creator) {
      this.videoCreator.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="2"/><path d="M4 20C4 16.6863 7.58172 14 12 14C16.4183 14 20 16.6863 20 20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> ${escapeHtml(creator)}`;
    }

    if (this.videoDate && date) {
      const formatted = new Date(date).toLocaleDateString();
      this.videoDate.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> ${formatted}`;
    }

    if (this.archiveLink) {
      this.archiveLink.href = `https://archive.org/details/${this.videoId}`;
    }

    this._renderMetaPills(meta);
    this._renderTags(meta);

    if (description && description.length > 10) {
      if (this.descriptionSection) this.descriptionSection.style.display = 'block';
      if (this.descriptionContent) {
        this.descriptionContent.innerHTML = sanitizeHtml(description);
        this.descriptionContent.classList.add('expanded');
      }
      if (this.descriptionToggle) this.descriptionToggle.classList.add('expanded');
    }
  }

  /**
   * Render metadata pills (year, runtime, language, mediatype, license, downloads, rating).
   * Pulls from Archive.org's metadata blob, which is loose JSON — every field
   * may be a string or array, hence the extractValue helper.
   */
  _renderMetaPills(meta) {
    if (!this.videoMetaPills) return;
    const pills = [];

    const date = extractValue(meta.date) || '';
    if (date) {
      const year = String(date).slice(0, 4);
      if (/^\d{4}$/.test(year)) pills.push({ icon: 'calendar', text: year });
    }

    const runtime = formatRuntime(extractValue(meta.runtime));
    if (runtime) pills.push({ icon: 'clock', text: runtime });

    const language = extractValue(meta.language);
    if (language) pills.push({ icon: 'globe', text: this._titleCase(String(language)) });

    const mediatype = extractValue(meta.mediatype);
    if (mediatype && mediatype !== 'movies') {
      pills.push({ icon: 'media', text: this._titleCase(String(mediatype)) });
    }

    const downloads = parseInt(extractValue(meta.downloads), 10);
    if (!isNaN(downloads) && downloads > 0) {
      pills.push({ icon: 'download', text: `${this._formatCount(downloads)} downloads` });
    }

    const reviews = parseInt(extractValue(meta.num_reviews), 10);
    const avg = parseFloat(extractValue(meta.avg_rating));
    if (!isNaN(avg) && avg > 0) {
      const ratingText = !isNaN(reviews) && reviews > 0
        ? `${avg.toFixed(1)} (${reviews})`
        : avg.toFixed(1);
      pills.push({ icon: 'star', text: ratingText, tone: 'star' });
    }

    const license = String(extractValue(meta.licenseurl) || '').toLowerCase();
    if (license.includes('publicdomain')) {
      pills.push({ icon: 'shield', text: 'Public Domain', tone: 'success' });
    } else if (license.includes('creativecommons')) {
      pills.push({ icon: 'shield', text: 'Creative Commons', tone: 'accent' });
    }

    if (!pills.length) {
      this.videoMetaPills.innerHTML = '';
      this.videoMetaPills.style.display = 'none';
      return;
    }

    const icons = {
      calendar: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
      clock: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
      globe: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 0 20a15.3 15.3 0 0 1 0-20z"/></svg>',
      media: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M22 8 16 12 22 16"/></svg>',
      download: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
      star: '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1" stroke-linejoin="round"><polygon points="12 2 15.1 8.6 22 9.6 17 14.4 18.2 21.3 12 18 5.8 21.3 7 14.4 2 9.6 8.9 8.6 12 2"/></svg>',
      shield: '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    };

    this.videoMetaPills.style.display = 'flex';
    this.videoMetaPills.innerHTML = pills.map(p =>
      `<span class="player-meta-pill ${p.tone ? 'tone-' + p.tone : ''}">
        ${icons[p.icon] || ''}
        <span>${escapeHtml(p.text)}</span>
      </span>`
    ).join('');
  }

  /**
   * Render up to 8 subject/topic tags as pills.
   */
  _renderTags(meta) {
    if (!this.videoTagsRow) return;
    const subjects = meta.subject;
    if (!subjects) {
      this.videoTagsRow.innerHTML = '';
      this.videoTagsRow.style.display = 'none';
      return;
    }
    const list = Array.isArray(subjects)
      ? subjects
      : String(subjects).split(/[;,]+/).map(s => s.trim()).filter(Boolean);

    const seen = new Set();
    const dedup = [];
    for (const t of list) {
      const k = t.toLowerCase();
      if (k && !seen.has(k)) { seen.add(k); dedup.push(t); }
      if (dedup.length >= 8) break;
    }

    if (!dedup.length) {
      this.videoTagsRow.innerHTML = '';
      this.videoTagsRow.style.display = 'none';
      return;
    }

    this.videoTagsRow.style.display = 'flex';
    this.videoTagsRow.innerHTML =
      '<span class="player-tags-label">Topics</span>' +
      dedup.map(t =>
        `<a class="player-tag" href="index.php?q=${encodeURIComponent(t)}" title="Search ${escapeHtml(t)}">#${escapeHtml(t)}</a>`
      ).join('');
  }

  _formatCount(n) {
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(n >= 10_000_000 ? 0 : 1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(n >= 10_000 ? 0 : 1) + 'K';
    return String(n);
  }

  _titleCase(s) {
    if (!s) return '';
    return s.charAt(0).toUpperCase() + s.slice(1);
  }

  // ========================================
  // Video Event Listeners
  // ========================================

  setupVideoListeners(videoEl) {
    if (!videoEl) return;

    // Always re-apply the persisted playback rate when (re)attaching to a
    // <video>. The element is reused across episode changes, so the rate
    // survives — but a fresh page load needs to restore it from storage.
    this._applyPlaybackRate(videoEl);
    this._applyCaptionState(videoEl);
    // Show / hide the PiP button based on browser support, evaluated per
    // element to handle cross-origin or capability changes.
    if (this.pipBtn) {
      this.pipBtn.style.display = (document.pictureInPictureEnabled && !videoEl.disablePictureInPicture) ? '' : 'none';
    }

    // Reusing the <video> element across track changes means this gets
    // called multiple times against the same node. Bail early on the
    // second+ call so we don't stack pause/ended/volume listeners.
    if (videoEl.dataset.afcListenersAttached === '1') return;
    videoEl.dataset.afcListenersAttached = '1';

    // Click-to-pause is the browser's job. Every modern engine (Chromium,
    // Firefox, WebKit) already toggles play/pause when a <video controls>
    // element is clicked. A page-level click handler used to toggle it a
    // second time on the same click, undoing the native toggle — the video
    // paused for a frame and then kept playing. Do not add one back.

    // Keyboard: once the user clicks the video it holds focus, and the
    // native controls then handle Space / arrow keys on their own. The
    // page-level shortcut handler (document keydown) ran as well, so Space
    // paused-then-resumed and the arrows seeked twice. Intercept at the
    // element in the capture phase — before the native controls see the
    // event — and route to the single shortcut handler; when it handles
    // the key, stop the event so neither the native controls nor the
    // document listener act on it a second time.
    videoEl.addEventListener('keydown', (e) => {
      if (this.handleKeyboard(e)) e.stopImmediatePropagation();
    }, true);

    // Double-click anywhere on the video toggles fullscreen.
    videoEl.addEventListener('dblclick', (e) => {
      const rect = videoEl.getBoundingClientRect();
      const distFromBottom = rect.bottom - e.clientY;
      if (distFromBottom < 50) return;
      e.preventDefault();
      this.toggleFullscreen();
    });

    // Buffering indicator — mid-playback stalls only. The initial-load
    // spinner is handled separately by showLoader/hideLoader.
    videoEl.addEventListener('waiting', () => this._showBuffering());
    videoEl.addEventListener('stalled', () => this._showBuffering());
    videoEl.addEventListener('playing', () => { this._sourceCanPlay = true; this._hideBuffering(); });
    videoEl.addEventListener('canplay', () => { this._sourceCanPlay = true; this._hideBuffering(); });
    videoEl.addEventListener('pause', () => this._hideBuffering());

    // Reapply rate after any internal reset (some browsers reset rate on
    // src change despite element reuse). Caption tracks are attached
    // synchronously by VideoService but the textTracks list isn't fully
    // populated until the browser has parsed the <track> elements, so
    // re-sync captions on metadata load too.
    videoEl.addEventListener('loadedmetadata', () => {
      this._applyPlaybackRate(videoEl);
      this._applyCaptionState(videoEl);
    });

    // `duration` is Infinity for live/unbounded sources; those can't have a
    // meaningful resume point and would poison the progress store.
    const hasDuration = () => Number.isFinite(videoEl.duration) && videoEl.duration > 0;

    videoEl.addEventListener('pause', () => {
      if (this.videoId && videoEl.currentTime && hasDuration()) {
        this.progressTracker.saveProgress(this.videoId, videoEl.currentTime, videoEl.duration, this._currentVideoMeta());
        const idx = this.playlistService.getCurrentIndex();
        if (this.playlist.isVisible() && this.playlistService.getPlaylist()) {
          this.playlist.saveTrackProgress(idx, videoEl.currentTime, videoEl.duration);
        }
      }
    });

    // Periodically persist per-episode progress to drive playlist progress bars.
    if (this._trackProgressInterval) clearInterval(this._trackProgressInterval);
    this._trackProgressInterval = setInterval(() => {
      if (!videoEl || videoEl.paused || !hasDuration()) return;
      const idx = this.playlistService.getCurrentIndex();
      if (this.playlist.isVisible() && this.playlistService.getPlaylist()) {
        this.playlist.saveTrackProgress(idx, videoEl.currentTime, videoEl.duration);
      }
    }, 5000);

    videoEl.addEventListener('ended', () => {
      if (this.videoId && hasDuration()) {
        this.progressTracker.saveProgress(this.videoId, videoEl.duration, videoEl.duration, this._currentVideoMeta());
      }
      const idx = this.playlistService.getCurrentIndex();
      if (this.playlist.isVisible() && this.playlistService.getPlaylist()) {
        this.playlist.markWatched(idx, videoEl.duration);
      }
      // Up Next overlay (only if there is a next + an actual playlist)
      if (this.playlistService.hasNext()) {
        this.showUpNext();
      }
    });

    videoEl.addEventListener('volumechange', () => {
      try { localStorage.setItem('playerVolume', videoEl.volume); } catch (e) {}
    });

    // Recover from files that FAIL TO LOAD (404, codec mismatch, dead
    // source). In playlist mode we mark the current track bad and auto-skip
    // to the next playable one; in single-video mode we fall back to the
    // Archive.org iframe, which handles codecs the native element can't.
    //
    // Critically, this must NOT fire on the routine error events that the
    // media element emits while *seeking* an already-playing video, or while
    // we swap <source> for the next track — otherwise scrubbing the timeline
    // used to be misread as "this episode failed" and jump the user away.
    videoEl.addEventListener('error', () => {
      const mediaError = videoEl.error;
      // No error object, or no current source (we cleared src mid-swap /
      // teardown): nothing actionable.
      if (!mediaError || !videoEl.currentSrc) return;

      const code = mediaError.code;
      const msg = mediaError.message || '';

      // MEDIA_ERR_ABORTED (1) is fired when an in-flight fetch is deliberately
      // cancelled — we swapped <source> for the next episode, or the browser
      // aborted a range request because the user seeked. Neither is a
      // playback failure, so never skip / fall back on it.
      if (code === 1 /* MEDIA_ERR_ABORTED */) return;

      // If the source already became playable, this is a mid-playback blip
      // (most commonly a transient range-request hiccup while seeking to an
      // un-buffered region), NOT a failure to load. Don't yank the viewer to
      // another episode or the embed — log it and let the native element
      // recover / let the user retry.
      if (this._sourceCanPlay) {
        console.warn('[player] transient media error during playback (ignored)', { code, msg });
        this._hideBuffering();
        return;
      }

      // The source never became playable → genuine "failed to load".
      console.warn('[player] video failed to load', { code, msg, src: videoEl.currentSrc });

      const pl = this.playlistService.getPlaylist();
      if (pl && pl.videoFiles.length > 1) {
        const failedIdx = this.playlistService.getCurrentIndex();
        this.failedPlaylistIndices.add(failedIdx);
        this.toast.show(`Episode ${failedIdx + 1} couldn't be played — skipping…`, 'error');
        const nextIdx = this.playlistService.findNextPlayable(this.failedPlaylistIndices);
        if (nextIdx !== -1) {
          this.playPlaylistItem(nextIdx);
        } else {
          this.toast.show('No more playable episodes in this playlist.', 'error');
        }
      } else {
        this.toast.show('Native playback failed, switching to embed…', 'info');
        try {
          this.videoService.loadIframeVideo(this.videoId, this.videoWrapper);
        } catch (e) {
          this.showError('Unable to play this video. <a href="index.php">Browse videos</a>');
        }
      }
    });
  }

  /**
   * Show the "Up Next" countdown overlay. Auto-advances after 8 seconds
   * unless the user clicks Cancel.
   */
  showUpNext() {
    if (!this.upNextOverlay) {
      // Fallback to immediate auto-play if the overlay markup isn't present.
      const nextIdx = this.playlistService.next();
      if (nextIdx !== -1) this.playPlaylistItem(nextIdx);
      return;
    }
    const pl = this.playlistService.getPlaylist();
    if (!pl) return;
    const nextIdx = this.playlistService.getCurrentIndex() + 1;
    const nextFile = pl.videoFiles[nextIdx];
    if (!nextFile) return;

    const itemTitle = extractValue(pl.metadata?.title) || '';
    const cleanTitle = this.videoService.getCleanTitle(nextFile.name, itemTitle);

    if (this.upNextThumb) {
      this.upNextThumb.hidden = false;
      this.upNextThumb.src = `https://archive.org/services/img/${pl.id}`;
    }
    if (this.upNextTitle) {
      this.upNextTitle.textContent = cleanTitle;
    }

    let remaining = 8;
    if (this.upNextCountdown) {
      this.upNextCountdown.textContent = `Playing in ${remaining}…`;
    }
    this.upNextOverlay.classList.add('visible');

    if (this._upNextTimer) clearInterval(this._upNextTimer);
    this._upNextTimer = setInterval(() => {
      remaining--;
      if (this.upNextCountdown) {
        this.upNextCountdown.textContent = remaining > 0
          ? `Playing in ${remaining}…`
          : 'Playing next…';
      }
      if (remaining <= 0) {
        this.confirmUpNext();
      }
    }, 1000);
  }

  confirmUpNext() {
    this.cancelUpNext();
    if (this.playlistService.hasNext()) {
      const idx = this.playlistService.next();
      this.playPlaylistItem(idx);
    }
  }

  cancelUpNext() {
    if (this._upNextTimer) {
      clearInterval(this._upNextTimer);
      this._upNextTimer = null;
    }
    if (this.upNextOverlay) this.upNextOverlay.classList.remove('visible');
  }

  getSavedVolume() {
    try {
      const vol = parseFloat(localStorage.getItem('playerVolume'));
      return isNaN(vol) ? 1 : vol;
    } catch (e) {
      return 1;
    }
  }

  // ========================================
  // Quality Switching
  // ========================================

  async switchQuality(filename) {
    const video = this.videoWrapper?.querySelector('video');
    const currentTime = video?.currentTime || 0;
    const wasPaused = video?.paused;

    // Remember the picked quality label so the next video defaults to
    // the same tier instead of resetting to "best available".
    try {
      const label = this.ui.getQualityLabel(filename);
      if (label) {
        this.preferredQualityLabel = label;
        localStorage.setItem('playerQualityLabel', label);
      }
    } catch (e) {}

    this.showLoader();

    try {
      const videoData = await this.videoService.loadNativeVideo(
        this.videoId,
        this.metadata,
        this.videoWrapper,
        filename,
        this.getSavedVolume()
      );
      this.hideLoader();
      this.currentFileName = filename;
      this.setupVideoListeners(videoData.videoElement);

      // Restore position after quality switch
      if (videoData.videoElement) {
        videoData.videoElement.addEventListener('loadedmetadata', () => {
          videoData.videoElement.currentTime = currentTime;
          if (!wasPaused) videoData.videoElement.play();
        }, { once: true });
      }

      // Update quality UI
      this._buildQualityOptions(filename);

      this.toast.show('Quality changed', 'info');
    } catch (err) {
      this.hideLoader();
      this.toast.show('Failed to switch quality', 'error');
    }
  }

  /**
   * Every playable encode (quality / container variant) of one episode.
   * Playlist entries are de-duplicated to one file per episode, but the
   * item usually still carries the 512kb / h264 / HD siblings.
   */
  _qualityVariantsFor(fileName) {
    if (!fileName) return [];
    const base = this.videoService.normalizeBaseName(fileName);
    return (this.allVideoFiles || []).filter(f => this.videoService.normalizeBaseName(f.name) === base);
  }

  /** Swap `fileName` for the sibling matching the user's remembered quality, if any. */
  _preferredVariant(fileName) {
    if (!fileName || !this.preferredQualityLabel) return fileName;
    const mp4s = this._qualityVariantsFor(fileName).filter(f => (f.name || '').toLowerCase().endsWith('.mp4'));
    const match = mp4s.find(f => this.ui.getQualityLabel(f.name) === this.preferredQualityLabel);
    return match ? match.name : fileName;
  }

  _buildQualityOptions(fileName) {
    const inPlaylist = !!this.playlistService.getPlaylist();
    const files = inPlaylist ? this._qualityVariantsFor(fileName) : this.allVideoFiles;
    this.ui.buildQualityOptions(files || [], fileName, (fn) => this.switchQuality(fn));
  }

  // ========================================
  // Playlist
  // ========================================

  setupPlaylist(meta, videoFiles, startIndex) {
    this.playlist.show(
      { ...meta, identifier: this.videoId },
      videoFiles,
      startIndex,
      (idx) => this.playPlaylistItem(idx)
    );

    this.ui.showEpisodeControls(startIndex, videoFiles.length);
  }

  async playPlaylistItem(index) {
    const pl = this.playlistService.getPlaylist();
    if (!pl) return;

    const file = pl.videoFiles[index];
    if (!file) return;

    this.cancelUpNext();
    this.playlistService.setCurrentIndex(index);

    // Update URL
    const url = new URL(window.location);
    url.searchParams.set('track', String(index + 1));
    window.history.replaceState({}, '', url);

    this.playlist.render(index);
    this.ui.showEpisodeControls(index, pl.videoFiles.length);
    this.showLoader();

    // Honour the remembered quality tier for this episode's encodes.
    const fileToPlay = this._preferredVariant(file.name);

    try {
      const videoData = await this.videoService.loadNativeVideo(
        pl.id,
        // Full file list (not the de-duplicated playlist) so any encode of
        // the episode can be selected.
        { metadata: pl.metadata, files: this.allVideoFiles && this.allVideoFiles.length ? this.allVideoFiles : pl.videoFiles },
        this.videoWrapper,
        fileToPlay,
        this.getSavedVolume()
      );
      this.hideLoader();
      this.currentFileName = fileToPlay;
      this.setupVideoListeners(videoData.videoElement);
      this.ui.hideResumePrompt();
      this._buildQualityOptions(fileToPlay);

      // Update title (page + tab + OS media overlay)
      const seriesTitle = extractValue(pl.metadata?.title) || '';
      const epTitle = this.videoService.getCleanTitle(file.name, seriesTitle);
      if (this.videoTitle) this.videoTitle.textContent = epTitle;
      const siteName = this.siteSettings.siteName || 'Archive Film Club';
      document.title = seriesTitle && seriesTitle !== epTitle
        ? `${epTitle} · ${seriesTitle} - ${siteName}`
        : `${epTitle} - ${siteName}`;
      this._updateMediaSession(epTitle, extractValue(pl.metadata?.creator));

      // Remember the episode for next visit even before any progress ticks.
      if (videoData.videoElement) {
        this._offerTrackResume(videoData.videoElement, index);
      }

    } catch (err) {
      console.error('Error loading playlist item:', err);
      this.hideLoader();
      this.failedPlaylistIndices.add(index);
      this.toast.show(`Failed to load episode ${index + 1}`, 'error');

      const nextIdx = this.playlistService.findNextPlayable(this.failedPlaylistIndices);
      if (nextIdx !== -1) {
        setTimeout(() => this.playPlaylistItem(nextIdx), 1000);
      } else {
        this.toast.show('No more playable episodes in this playlist.', 'error');
      }
    }
  }

  playNextEpisode() {
    if (this.playlistService.hasNext()) {
      const idx = this.playlistService.next();
      this.playPlaylistItem(idx);
    }
  }

  playPreviousEpisode() {
    if (this.playlistService.hasPrevious()) {
      const idx = this.playlistService.previous();
      this.playPlaylistItem(idx);
    }
  }

  // ========================================
  // Downloads
  // ========================================

  buildDownloadLinks(videoFiles) {
    if (!this.downloadLinks || !videoFiles.length) return;

    const mp4Files = videoFiles.filter(f => (f.name || '').toLowerCase().endsWith('.mp4'));
    const otherFiles = videoFiles.filter(f => !(f.name || '').toLowerCase().endsWith('.mp4'));

    let html = '';

    const createLink = (file) => {
      const url = `https://archive.org/download/${this.videoId}/${encodeURIComponent(file.name)}`;
      const size = formatFileSize(file.size);
      const quality = this.videoService.getQualityLabel(file.name);
      return `
        <a href="${escapeHtml(url)}" target="_blank" download="${escapeHtml(file.name)}" class="download-item">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 3V15M12 15L7 10M12 15L17 10M3 17V19C3 20.1046 3.89543 21 5 21H19C20.1046 21 21 20.1046 21 19V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <div class="download-item-info">
            <span class="download-item-quality">${quality}</span>
            ${size ? `<span class="download-item-size">${size}</span>` : ''}
          </div>
        </a>
      `;
    };

    if (mp4Files.length > 0) {
      html += '<div class="download-group-label">MP4 (Recommended)</div>';
      html += mp4Files.map(createLink).join('');
    }
    if (otherFiles.length > 0) {
      html += '<div class="download-group-label">Other Formats</div>';
      html += otherFiles.map(createLink).join('');
    }

    html += `
      <a href="https://archive.org/download/${escapeHtml(this.videoId)}" target="_blank" class="download-item download-item-all">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M3 6C3 4.89543 3.89543 4 5 4H9L11 6H19C20.1046 6 21 6.89543 21 8V18C21 19.1046 20.1046 20 19 20H5C3.89543 20 3 19.1046 3 18V6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div class="download-item-info">
          <span class="download-item-quality">Browse All Files</span>
          <span class="download-item-size">View complete archive</span>
        </div>
      </a>
    `;

    this.downloadLinks.innerHTML = html;
  }

  toggleDownloads() {
    if (!this.downloadsPanel) return;
    const visible = this.downloadsPanel.style.display !== 'none';
    this.downloadsPanel.style.display = visible ? 'none' : 'block';
  }

  hideDownloads() {
    if (this.downloadsPanel) this.downloadsPanel.style.display = 'none';
  }

  // ========================================
  // Description
  // ========================================

  toggleDescription() {
    this.descriptionExpanded = !this.descriptionExpanded;
    if (this.descriptionContent) this.descriptionContent.classList.toggle('expanded', this.descriptionExpanded);
    if (this.descriptionToggle) this.descriptionToggle.classList.toggle('expanded', this.descriptionExpanded);
  }

  // ========================================
  // Bookmarks
  // ========================================

  toggleBookmark() {
    if (!this.videoId) return;

    if (this.bookmarkManager.isBookmarked(this.videoId)) {
      this.bookmarkManager.remove(this.videoId);
      this.updateBookmarkState();
      this.toast.show('Removed from bookmarks', 'info');
    } else {
      const title = this.videoTitle?.textContent || this.videoId;
      const creator = this.videoCreator?.textContent || 'Unknown';
      this.bookmarkManager.add({ identifier: this.videoId, title, creator });
      this.updateBookmarkState();
      this.toast.show('Added to bookmarks!', 'success');
    }
  }

  updateBookmarkState() {
    if (!this.bookmarkBtn) return;
    const isBookmarked = this.bookmarkManager.isBookmarked(this.videoId);
    this.bookmarkBtn.classList.toggle('active', isBookmarked);
    this.bookmarkBtn.innerHTML = isBookmarked
      ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M5 5C5 3.89543 5.89543 3 7 3H17C18.1046 3 19 3.89543 19 5V21L12 17.5L5 21V5Z"/></svg>'
      : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 5C5 3.89543 5.89543 3 7 3H17C18.1046 3 19 3.89543 19 5V21L12 17.5L5 21V5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }

  // ========================================
  // Collections
  // ========================================

  openCollectionPicker() {
    if (!this.videoId) return;
    const title = this.videoTitle?.textContent || this.videoId;
    const creator = this.videoCreator?.textContent || '';
    CollectionPicker.open({
      video: {
        identifier: this.videoId,
        title,
        creator,
        thumbnail: `https://archive.org/services/img/${this.videoId}`,
      },
      onChange: () => {
        this.toast.show('Collection updated', 'success');
      },
    });
  }

  // ========================================
  // Share
  // ========================================

  shareVideo() {
    const track = this.playlistService.getCurrentIndex();
    const pl = this.playlistService.getPlaylist();
    let link = `${window.location.origin}${window.location.pathname}?video=${this.videoId}`;
    if (pl && track > 0) link += `&track=${track + 1}`;

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(link)
        .then(() => this.toast.show('Link copied to clipboard!', 'success'))
        .catch(() => this.showShareFallback(link));
    } else {
      this.showShareFallback(link);
    }
  }

  showShareFallback(url) {
    const modal = document.createElement('div');
    modal.className = 'share-modal-overlay';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', 'Share link');
    modal.innerHTML = `
      <div class="share-modal">
        <h3>Share this video</h3>
        <input type="text" readonly class="share-modal-input" />
        <button type="button" class="share-modal-close">Close</button>
      </div>`;
    const input = modal.querySelector('input');
    input.value = url;
    document.body.appendChild(modal);
    input.focus();
    input.select();
    // See app.js showShareFallback — close() must tear down the keydown
    // listener no matter how the modal is dismissed.
    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const close = () => {
      document.removeEventListener('keydown', onKey);
      modal.remove();
    };
    modal.querySelector('.share-modal-close').onclick = close;
    modal.onclick = (e) => { if (e.target === modal) close(); };
    document.addEventListener('keydown', onKey);
  }

  // ========================================
  // Report to Archive.org
  // ========================================

  /**
   * Open a dialog that hands the user off to archive.org's report channels
   * for the currently playing item. We host no content, so we link out
   * rather than collect reports ourselves. Archive.org has no public
   * pre-fillable report endpoint, so the best we can do is (1) hand them
   * a copyable archive.org item URL and (2) offer a prefilled mailto.
   */
  openReportDialog() {
    if (!this.videoId) return;
    const itemUrl = `https://archive.org/details/${this.videoId}`;
    const title = this.videoTitle?.textContent || this.videoId;
    const subject = encodeURIComponent(`Report regarding archive.org item: ${this.videoId}`);
    const body = encodeURIComponent(
      `I would like to report the following item on archive.org:\n\n` +
      `Title: ${title}\n` +
      `Identifier: ${this.videoId}\n` +
      `URL: ${itemUrl}\n\n` +
      `Reason for report:\n[please describe the issue]\n\n` +
      `(Reported via a third-party front-end; no content is hosted there.)`
    );
    const mailto = `mailto:info@archive.org?subject=${subject}&body=${body}`;

    const overlay = document.createElement('div');
    overlay.className = 'report-modal-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Report this video to Archive.org');
    overlay.innerHTML = `
      <div class="report-modal">
        <h3>Report this video</h3>
        <p class="report-modal-subtitle">
          This site doesn't host any video files &mdash; everything streams from
          <strong>archive.org</strong>. To report inappropriate, illegal, or
          infringing content, please contact the Internet Archive directly using
          one of the channels below.
        </p>

        <div class="report-modal-url-row">
          <input type="text" class="report-modal-url" readonly aria-label="Archive.org item URL" />
          <button type="button" class="report-modal-copy">Copy</button>
        </div>

        <div class="report-modal-actions">
          <a class="report-modal-action" target="_blank" rel="noopener"
             href="https://help.archive.org/help/problems-or-errors/">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span class="report-modal-action-text">
              <span class="report-modal-action-title">Report a problem on Archive.org</span>
              <span class="report-modal-action-sub">Opens the Internet Archive help center</span>
            </span>
          </a>
          <a class="report-modal-action" target="_blank" rel="noopener"
             href="https://help.archive.org/help/how-do-i-request-to-remove-something-from-archive-org/">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
            <span class="report-modal-action-text">
              <span class="report-modal-action-title">Request removal / DMCA</span>
              <span class="report-modal-action-sub">For copyright or takedown requests</span>
            </span>
          </a>
          <a class="report-modal-action" href="${mailto}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <span class="report-modal-action-text">
              <span class="report-modal-action-title">Email info@archive.org</span>
              <span class="report-modal-action-sub">Opens your mail client with the item details pre-filled</span>
            </span>
          </a>
        </div>

        <div class="report-modal-footer">
          <button type="button" class="report-modal-close">Close</button>
        </div>
      </div>`;

    const input = overlay.querySelector('.report-modal-url');
    input.value = itemUrl;

    document.body.appendChild(overlay);

    const onKey = (e) => { if (e.key === 'Escape') close(); };
    const close = () => {
      document.removeEventListener('keydown', onKey);
      overlay.remove();
    };

    const copyBtn = overlay.querySelector('.report-modal-copy');
    const originalCopyText = copyBtn.textContent;
    copyBtn.addEventListener('click', () => {
      const showCopied = () => {
        copyBtn.textContent = 'Copied!';
        setTimeout(() => { copyBtn.textContent = originalCopyText; }, 1500);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(itemUrl).then(showCopied).catch(() => {
          input.focus(); input.select();
        });
      } else {
        input.focus(); input.select();
        try { document.execCommand('copy'); showCopied(); } catch (e) {}
      }
    });

    overlay.querySelector('.report-modal-close').onclick = close;
    overlay.onclick = (e) => { if (e.target === overlay) close(); };
    document.addEventListener('keydown', onKey);
  }

  // ========================================
  // UI Helpers
  // ========================================

  showLoader() {
    // A fresh load / switch: the new source isn't playable yet and the big
    // loader is about to cover the cinema. Tear down the mid-playback
    // buffering spinner so the two never render at once (double-spinner bug),
    // and reset the playable flag so the buffering spinner stays suppressed
    // until the new source actually starts.
    this._sourceCanPlay = false;
    this._hideBuffering();
    if (this.playerLoader) this.playerLoader.style.display = 'flex';
  }

  hideLoader() {
    if (this.playerLoader) this.playerLoader.style.display = 'none';
  }

  showError(message) {
    this.hideLoader();
    if (this.videoWrapper) {
      this.videoWrapper.innerHTML = `
        <div class="player-error">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
            <path d="M12 8V12M12 16H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <p>${message}</p>
        </div>
      `;
    }
    if (this.videoTitle) this.videoTitle.textContent = 'Video not found';
  }
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
  // Disarm the inline app-load watchdog (player.php): the module loaded.
  window.__afcReady = true;
  try {
    window.videoPlayer = new VideoPlayer();
  } catch (error) {
    console.error('Failed to initialize player:', error);
  }
});

export default VideoPlayer;

// Service worker registration (same rationale as app.js -- relative URL
// so subdirectory installs resolve correctly).
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch((err) => {
      console.warn('[SW] Registration failed:', err);
    });
  });
}
