/**
 * VideoProgressTracker Service
 *
 * Tracks each video's watch progress for resume support. Works for both
 * guests and authenticated users:
 *
 *   Guest    → localStorage only.
 *   Signed-in → localStorage + debounced sync to /api/history.php.
 *
 * The public API is synchronous to avoid tangling the playback loop.
 * saveProgress() is called very frequently (once per second), so the
 * server writes are throttled to once every UPLOAD_INTERVAL_MS.
 */

import { CONFIG } from '../config.js';
import { safeParseJSON } from '../utils/helpers.js';
import { ApiService } from './ApiService.js';
import { AuthService } from './AuthService.js';

const STORAGE_KEY = 'videoProgress';
const UPLOAD_INTERVAL_MS = 5000; // throttle: one server write per 5s per video

export class VideoProgressTracker {
  constructor() {
    this.progress = safeParseJSON(localStorage.getItem(STORAGE_KEY)) || {};
    this._lastUploadAt = {};   // videoId → epoch ms
    this._pendingFlush = null; // setTimeout handle for final flush on stop
    this.cleanupOldProgress();

    // On login, we refetch progress rows from the server for any videos
    // the user starts watching — but we don't bulk-pull up front because
    // the history can be large. Instead, getProgress() makes a one-shot
    // async server lookup and caches the result locally.
    AuthService.fetchMe().catch(() => {});
  }

  /**
   * Save progress to localStorage immediately and, when authed, to the
   * server at most once every UPLOAD_INTERVAL_MS.
   *
   * The optional `meta` (`{ title, creator }`) is stored alongside the
   * timestamps so the homepage Continue Watching row can render a card
   * for the entry without re-fetching Archive.org metadata for every
   * stale row.
   */
  saveProgress(videoId, currentTime, duration, meta = null) {
    if (!videoId || !duration) return;

    const previous = this.progress[videoId] || {};
    this.progress[videoId] = {
      currentTime,
      duration,
      percentage: (currentTime / duration) * 100,
      timestamp: Date.now(),
      // Preserve previously-captured title/creator unless the caller
      // supplied a fresher copy.
      title: (meta && meta.title) || previous.title || null,
      creator: (meta && meta.creator) || previous.creator || null,
      // Zero-based episode index for multi-part items (null for single
      // videos) so the player can resume the right part of a series.
      track: meta && Number.isInteger(meta.track)
        ? meta.track
        : (Number.isInteger(previous.track) ? previous.track : null),
    };

    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.progress));
    } catch (e) {
      console.warn('[VideoProgressTracker] save failed:', e);
    }

    if (!AuthService.isAuthenticated()) return;

    const now = Date.now();
    const last = this._lastUploadAt[videoId] || 0;

    if (now - last >= UPLOAD_INTERVAL_MS) {
      this._lastUploadAt[videoId] = now;
      ApiService.updateProgress(videoId, currentTime, duration)
        .catch(() => { /* silenced inside ApiService */ });
    }

    // Always arm a short trailing flush so the *final* position (pause,
    // tab close, end of video) hits the server too.
    if (this._pendingFlush) clearTimeout(this._pendingFlush);
    this._pendingFlush = setTimeout(() => {
      this._pendingFlush = null;
      if (!AuthService.isAuthenticated()) return;
      ApiService.updateProgress(videoId, currentTime, duration)
        .catch(() => {});
    }, 2000);
  }

  /**
   * Synchronous read from local cache. If the caller needs authoritative
   * server state (e.g. first visit from a new device), use fetchProgress().
   */
  getProgress(videoId) {
    return this.progress[videoId] || null;
  }

  /**
   * Async fetch — prefers server state when logged in and updates the
   * local cache. Returns the progress object, or null if not found.
   */
  async fetchProgress(videoId) {
    if (AuthService.isAuthenticated()) {
      try {
        const res = await ApiService.getVideoProgress(videoId);
        const row = res?.data;
        if (row && row.playback_position != null && row.duration) {
          const merged = {
            currentTime: Number(row.playback_position),
            duration: Number(row.duration),
            percentage: Number(row.progress_percent || 0),
            timestamp: Date.now(),
          };
          this.progress[videoId] = merged;
          try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.progress));
          } catch (_) {}
          return merged;
        }
      } catch (e) {
        console.warn('[VideoProgressTracker] server fetch failed:', e);
      }
    }
    return this.getProgress(videoId);
  }

  cleanupOldProgress() {
    const cutoff = Date.now() - (CONFIG.VIDEO_PROGRESS_CLEANUP_DAYS * 24 * 60 * 60 * 1000);
    let cleaned = false;

    Object.keys(this.progress).forEach(id => {
      const entry = this.progress[id];
      if (!entry || !entry.timestamp || entry.timestamp < cutoff) {
        delete this.progress[id];
        cleaned = true;
      }
    });

    if (cleaned) {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(this.progress));
      } catch (e) {
        console.warn('[VideoProgressTracker] cleanup failed:', e);
      }
    }
  }

  /**
   * Return entries that are partially watched, newest first. The
   * Continue Watching row uses this to decide which videos to surface
   * on the homepage. Excludes near-finished items (>= 95%) so finished
   * videos don't clutter the row.
   */
  getResumable({ limit = 12, minPercent = 1, maxPercent = 95 } = {}) {
    return Object.entries(this.progress)
      .map(([id, entry]) => ({ id, ...entry }))
      .filter(e => e && e.duration > 0 && e.percentage > minPercent && e.percentage < maxPercent)
      .sort((a, b) => (b.timestamp || 0) - (a.timestamp || 0))
      .slice(0, limit);
  }

  clearProgress(videoId) {
    if (videoId) {
      delete this.progress[videoId];
    } else {
      this.progress = {};
      if (AuthService.isAuthenticated()) {
        ApiService.clearWatchHistory()
          .catch(err => console.warn('[VideoProgressTracker] server clear failed:', err));
      }
    }
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.progress));
    } catch (e) {
      console.warn('[VideoProgressTracker] clear failed:', e);
    }
  }
}

export default VideoProgressTracker;
