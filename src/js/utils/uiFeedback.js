/**
 * UI Feedback Utilities
 * Handles loading states, error messages, and user notifications
 */

import { escapeHtml } from './helpers.js';

/**
 * UIFeedback class for managing loading states and messages
 */
export class UIFeedback {
  constructor(elements = {}) {
    this.elements = elements;
  }

  /**
   * Set the elements to use for feedback
   */
  setElements(elements) {
    this.elements = { ...this.elements, ...elements };
  }

  /**
   * Show loading state
   */
  showLoading() {
    const { loading, searchBtn, results } = this.elements;

    if (loading) {
      loading.hidden = false;
      loading.style.display = 'flex';
    }

    // aria-busy tells assistive tech the grid is mid-update, so a screen
    // reader doesn't announce a half-rendered or stale list.
    if (results) results.setAttribute('aria-busy', 'true');

    if (searchBtn) {
      searchBtn.disabled = true;
      searchBtn.innerHTML = `
        <svg class="btn-spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2V6M12 18V22M4.93 4.93L7.76 7.76M16.24 16.24L19.07 19.07M2 12H6M18 12H22M4.93 19.07L7.76 16.24M16.24 7.76L19.07 4.93" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        Searching...
      `;
    }
  }

  /**
   * Hide loading state
   */
  hideLoading() {
    const { loading, searchBtn, results } = this.elements;

    if (loading) {
      loading.hidden = true;
      loading.style.display = 'none';
    }

    if (results) results.removeAttribute('aria-busy');

    if (searchBtn) {
      searchBtn.disabled = false;
      searchBtn.innerHTML = `
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 21L15 15M17 10C17 13.866 13.866 17 10 17C6.13401 17 3 13.866 3 10C3 6.13401 6.13401 3 10 3C13.866 3 17 6.13401 17 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        Search
      `;
    }
  }

  /**
   * Show error message
   */
  showError(msg) {
    const { error } = this.elements;
    if (!error) return;

    error.innerHTML = `<strong>Error:</strong> ${escapeHtml(msg)}`;
    error.hidden = false;
  }

  /**
   * Hide error message
   */
  hideError() {
    const { error } = this.elements;
    if (error) error.hidden = true;
  }

  /**
   * Show no results message
   */
  showNoResults(query, collectionName) {
    const { results } = this.elements;
    if (!results) return;

    const hasQuery = !!(query && query.trim());
    const heading = hasQuery
      ? `No results for "${escapeHtml(query)}"`
      : 'Nothing here yet';
    const subline = hasQuery
      ? `We couldn't find any matches in ${escapeHtml(collectionName)}.`
      : `This collection is empty.`;

    results.innerHTML = `
      <div class="no-results">
        <svg class="no-results-icon" width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="7"/>
          <path d="m21 21-4.3-4.3"/>
        </svg>
        <h3>${heading}</h3>
        <p>${subline}</p>
        <ul class="no-results-tips">
          <li>Check the spelling, or try a shorter or more general phrase.</li>
          <li>Switch the collection above to <strong>All Videos</strong>.</li>
          <li>Browse the featured rows on the <a href="index.php">home page</a>.</li>
        </ul>
      </div>`;
  }

  /**
   * Show fallback message when search is unavailable
   */
  showFallbackMessage() {
    const { results } = this.elements;
    if (!results) return;

    results.innerHTML = `
      <div class="fallback-message">
        <h3>Search temporarily unavailable</h3>
        <p>Archive.org may be experiencing issues. Please try again shortly.</p>
      </div>`;
  }

  /**
   * Update search statistics display
   */
  updateStats(numFound, currentPage, itemsPerPage, collectionName) {
    const { searchStats } = this.elements;
    if (!searchStats) return;

    if (!numFound) {
      searchStats.innerHTML = 'No results found';
      return;
    }

    const start = (currentPage - 1) * itemsPerPage + 1;
    const end = Math.min(currentPage * itemsPerPage, numFound);

    searchStats.innerHTML = `
      Results ${start.toLocaleString()}–${end.toLocaleString()} of ${numFound.toLocaleString()}<br>
      Collection: ${escapeHtml(collectionName)}`;
  }

  /**
   * Clear results area
   */
  clearResults() {
    const { results } = this.elements;
    if (results) results.innerHTML = '';
  }

  /**
   * Clear pagination
   */
  clearPagination() {
    const { pagination } = this.elements;
    if (pagination) pagination.innerHTML = '';
  }

  /**
   * Clear stats
   */
  clearStats() {
    const { searchStats } = this.elements;
    if (searchStats) searchStats.innerHTML = 'Ready to search...';
  }
}

/**
 * Create loading skeleton HTML for result cards
 */
export function createLoadingSkeletons(count = 8) {
  let html = '';
  for (let i = 0; i < count; i++) {
    html += `
      <div class="result-card skeleton">
        <div class="skeleton-thumbnail"></div>
        <div class="skeleton-content">
          <div class="skeleton-title"></div>
          <div class="skeleton-meta"></div>
          <div class="skeleton-actions"></div>
        </div>
      </div>`;
  }
  return html;
}

export default UIFeedback;
