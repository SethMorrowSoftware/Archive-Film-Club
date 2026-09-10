/**
 * BookmarkManager Service
 *
 * Manages the user's bookmarked videos with a write-through localStorage
 * cache and backend sync.
 *
 *  - The backend is the source of truth for guests AND signed-in users:
 *    api/bookmarks.php resolves the current guest (cookie) or account, and
 *    UserAuthService::mergeGuest() folds guest rows into the account on
 *    login/signup. Gating writes on isAuthenticated() used to mean guest
 *    bookmarks never reached the server, so there was nothing to merge.
 *  - localStorage is a mirror for instant reads and offline fallback. On
 *    pull we MERGE (local entries the server lacks get pushed up) rather
 *    than replace, and the mirror is cleared on logout so the next person
 *    on this browser doesn't inherit the previous user's list.
 *
 * The public API stays synchronous so the existing call sites in app.js
 * don't need to await every interaction. Network writes are fire-and-forget.
 */

import { CONFIG } from '../config.js';
import { safeParseJSON, extractValue } from '../utils/helpers.js';
import { ApiService } from './ApiService.js';
import { AuthService } from './AuthService.js';

const STORAGE_KEY = 'bookmarks';

export class BookmarkManager {
  constructor() {
    this.bookmarks = safeParseJSON(localStorage.getItem(STORAGE_KEY)) || [];
    this._syncInFlight = false;
    this._listeners = new Set();
    // Whether the previous auth notification carried a user, so we can
    // tell a real logout (user → null) from the initial "not fetched yet".
    this._hadUser = false;

    // Whenever the auth state changes, pull the server-side list.
    // This also runs once synchronously with the current state.
    AuthService.onChange(({ user, guest }) => {
      if (user) {
        this._hadUser = true;
        this._pullFromServer();
        return;
      }
      if (this._hadUser) {
        // Logout: drop the previous account's mirror.
        this._hadUser = false;
        this.bookmarks = [];
        this._persist();
        this._emit();
        return;
      }
      // Guest with a confirmed server identity — sync with the guest row
      // so bookmarks made on another tab/session of this guest show up.
      if (guest) {
        this._pullFromServer();
      }
    });

    // First load: trigger a me.php fetch (if not already cached). The
    // resulting onChange callback will pull the server bookmarks.
    AuthService.fetchMe().catch(() => { /* ignore */ });
  }

  // ----- Subscriptions --------------------------------------------------
  onChange(fn) {
    this._listeners.add(fn);
    try { fn(this.bookmarks); } catch (_) {}
    return () => this._listeners.delete(fn);
  }

  _emit() {
    for (const fn of this._listeners) {
      try { fn(this.bookmarks); } catch (_) {}
    }
  }

  // ----- Reads (sync) ---------------------------------------------------
  isBookmarked(id) {
    return this.bookmarks.some(b => b.id === id);
  }

  getAll() {
    return this.bookmarks;
  }

  // ----- Writes ---------------------------------------------------------
  add(video) {
    if (this.isBookmarked(video.identifier)) return false;

    const bookmark = {
      id: video.identifier,
      title: extractValue(video.title),
      creator: extractValue(video.creator),
      thumbnail: video.thumbnail || `https://archive.org/services/img/${video.identifier}`,
      timestamp: Date.now(),
    };

    this.bookmarks.unshift(bookmark);

    if (this.bookmarks.length > CONFIG.MAX_BOOKMARKS) {
      this.bookmarks = this.bookmarks.slice(0, CONFIG.MAX_BOOKMARKS);
    }

    this._persist();
    this._emit();

    // Fire-and-forget server write. Guests are resolved server-side via
    // their cookie, so this is safe (and necessary) when signed out too.
    this._pushOne(bookmark);

    return true;
  }

  remove(id) {
    const before = this.bookmarks.length;
    this.bookmarks = this.bookmarks.filter(b => b.id !== id);
    if (this.bookmarks.length === before) return;

    this._persist();
    this._emit();

    ApiService.removeBookmark(id)
      .catch(err => console.warn('[BookmarkManager] remove sync failed:', err));
  }

  clear() {
    this.bookmarks = [];
    this._persist();
    this._emit();

    ApiService.syncBookmarks([])
      .catch(err => console.warn('[BookmarkManager] clear sync failed:', err));
  }

  // ----- Internals ------------------------------------------------------
  _persist() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.bookmarks));
    } catch (e) {
      console.warn('[BookmarkManager] localStorage save failed:', e);
    }
  }

  _pushOne(bookmark) {
    ApiService.addBookmark({
      id: bookmark.id,
      title: bookmark.title,
      creator: bookmark.creator,
      thumbnail: bookmark.thumbnail,
    }).catch(err => console.warn('[BookmarkManager] add sync failed:', err));
  }

  /**
   * Pull the bookmark list from the server and merge it with the local
   * mirror. Used right after login, on first page load, and for guests
   * once me.php has confirmed their identity.
   *
   * Anything local that the server doesn't have (a write that failed
   * offline, or a guest bookmark from before the API round-trip landed)
   * is kept and pushed up, rather than silently dropped.
   */
  async _pullFromServer() {
    if (this._syncInFlight) return;
    this._syncInFlight = true;
    try {
      const res = await ApiService.getBookmarks();
      const list = Array.isArray(res?.data) ? res.data : [];

      // Normalize to our local shape. BookmarkService returns
      // {id, title, creator, thumbnail, created_at}.
      const serverList = list.map(row => ({
        id: row.id,
        title: row.title || '',
        creator: row.creator || '',
        thumbnail: row.thumbnail || `https://archive.org/services/img/${row.id}`,
        timestamp: row.created_at ? new Date(row.created_at).getTime() : Date.now(),
      }));

      const serverIds = new Set(serverList.map(b => b.id));
      const localOnly = this.bookmarks.filter(b => b && b.id && !serverIds.has(b.id));

      // Local-only entries are the newest (they were added on this device
      // most recently), so they lead; the server list keeps its own order.
      this.bookmarks = [...localOnly, ...serverList].slice(0, CONFIG.MAX_BOOKMARKS);

      this._persist();
      this._emit();

      for (const bookmark of localOnly) {
        this._pushOne(bookmark);
      }
    } catch (e) {
      console.warn('[BookmarkManager] server pull failed:', e);
    } finally {
      this._syncInFlight = false;
    }
  }
}

export default BookmarkManager;
