<?php
/**
 * UserService (Facade)
 *
 * This used to be a 367-line god-object containing session handling,
 * bookmarks, watch history, search history, and preferences.
 *
 * It's now a thin facade that delegates to:
 *   - UserContext          → "who is this request?"
 *   - UserRepository       → users table CRUD
 *   - BookmarkService      → user_bookmarks
 *   - WatchHistoryService  → user_watch_history
 *   - SearchHistoryService → search_history
 *
 * The public API is unchanged so existing callers keep working. New
 * code should use the focused services directly instead of this facade.
 */
class UserService {
    private $context;
    private $repo;
    private $bookmarks;
    private $history;
    private $searches;

    public function __construct() {
        $this->context = new UserContext();
        $this->repo = new UserRepository();
        $this->bookmarks = new BookmarkService();
        $this->history = new WatchHistoryService();
        $this->searches = new SearchHistoryService();
    }

    // =====================================================
    // SESSION (kept for back-compat; new code uses UserContext)
    // =====================================================

    public function getOrCreateUser(): int {
        return $this->context->currentId();
    }

    public function getUserId(): ?int {
        // Old callers expected ?int; return current id (guest or account).
        return $this->context->currentId();
    }

    // =====================================================
    // PREFERENCES
    // =====================================================

    public function getPreferences(): array {
        $user = $this->context->current();
        return $user['preferences'] ?? [];
    }

    public function setPreferences(array $prefs): bool {
        if ($this->context->isTransient()) return false;
        $this->repo->setPreferences($this->context->currentId(), $prefs);
        $this->context->refresh();
        return true;
    }

    public function setPreference(string $key, $value): bool {
        $prefs = $this->getPreferences();
        $prefs[$key] = $value;
        return $this->setPreferences($prefs);
    }

    // =====================================================
    // BOOKMARKS
    // =====================================================

    public function getBookmarks(): array {
        return $this->bookmarks->getAll($this->context->currentId());
    }

    // Write paths no-op for a transient (crawler) guest — there is no users
    // row for user_id 0 to own the data, and the FK would reject the INSERT.
    // Reads are fine: user_id 0 simply matches nothing.

    public function addBookmark(string $archiveId, array $metadata = []): bool {
        if ($this->context->isTransient()) return false;
        return $this->bookmarks->add($this->context->currentId(), $archiveId, $metadata);
    }

    public function removeBookmark(string $archiveId): bool {
        if ($this->context->isTransient()) return false;
        $this->bookmarks->remove($this->context->currentId(), $archiveId);
        return true;
    }

    public function isBookmarked(string $archiveId): bool {
        return $this->bookmarks->exists($this->context->currentId(), $archiveId);
    }

    public function syncBookmarks(array $bookmarks): bool {
        if ($this->context->isTransient()) return false;
        return $this->bookmarks->sync($this->context->currentId(), $bookmarks);
    }

    // =====================================================
    // WATCH HISTORY
    // =====================================================

    public function getWatchHistory(int $limit = 50): array {
        return $this->history->recent($this->context->currentId(), $limit);
    }

    public function updateProgress(string $archiveId, float $currentTime, float $duration): bool {
        if ($this->context->isTransient()) return false;
        $this->history->updateProgress($this->context->currentId(), $archiveId, $currentTime, $duration);
        return true;
    }

    public function getProgress(string $archiveId): ?array {
        return $this->history->getProgress($this->context->currentId(), $archiveId);
    }

    public function clearWatchHistory(): bool {
        if ($this->context->isTransient()) return false;
        $this->history->clear($this->context->currentId());
        return true;
    }

    // =====================================================
    // SEARCH HISTORY
    // =====================================================

    public function addSearchHistory(string $query, array $filters = [], int $resultCount = 0): void {
        if ($this->context->isTransient()) return;
        $this->searches->record($this->context->currentId(), $query, $filters, $resultCount);
    }

    public function getRecentSearches(int $limit = 10): array {
        return $this->searches->recent($this->context->currentId(), $limit);
    }

    public function clearSearchHistory(): bool {
        if ($this->context->isTransient()) return false;
        $this->searches->clear($this->context->currentId());
        return true;
    }
}
