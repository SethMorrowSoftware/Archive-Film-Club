/**
 * Utility Helper Functions
 * Common utility functions used throughout the application
 */

/**
 * Safely parse JSON with fallback
 */
export function safeParseJSON(str) {
  try {
    return JSON.parse(str);
  } catch {
    return null;
  }
}

/**
 * Escape HTML to prevent XSS
 */
export function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * Sanitize HTML while preserving safe tags.
 *
 * Strict ALLOW-list. Anything not listed is unwrapped (its text survives,
 * the element doesn't); tags whose *contents* are as untrusted as their
 * markup (script, style, iframe, ...) are dropped whole. The only attribute
 * that survives is `href` on <a>, and only when it's http(s) or a bare
 * archive.org-relative path (which we prefix, as before).
 *
 * Parsed with DOMParser rather than innerHTML on a live element: DOMParser
 * builds an inert document, so `<img onerror>` never fires during parsing
 * and nothing in the untrusted string triggers a network request.
 *
 * Self-test — every one of these must come out neutralised (paste into a
 * console on any page that imports helpers.js):
 *   sanitizeHtml('<img src=x onerror=alert(1)>')                    → ''
 *   sanitizeHtml('<a href=" JaVaScRiPt:alert(1)">x</a>')            → '<a>x</a>'
 *   sanitizeHtml('<a href="java\nscript:alert(1)">x</a>')           → '<a>x</a>'
 *   sanitizeHtml('<iframe srcdoc="<script>alert(1)</script>">')     → ''
 *   sanitizeHtml('<svg onload=alert(1)><a href="//x">y</a></svg>')  → ''
 *   sanitizeHtml('<p style="x:url(y)" onclick="1">ok</p>')          → '<p>ok</p>'
 *   sanitizeHtml('<form action="x"><input value="1"></form>')       → ''
 *   sanitizeHtml('<a href="details/foo">f</a>')
 *     → '<a href="https://archive.org/details/foo" target="_blank" rel="noopener noreferrer">f</a>'
 */
const SANITIZE_ALLOWED_TAGS = new Set([
  'p', 'br', 'a', 'b', 'strong', 'i', 'em', 'u', 's', 'ul', 'ol', 'li',
  'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
  'div', 'span', 'hr', 'small', 'sub', 'sup',
]);

// Dropped with their contents. Everything else not on the allow-list is
// merely unwrapped. Foreign-namespace elements (svg/math subtrees) are
// dropped by the namespace check below regardless of this list.
const SANITIZE_DROP_TAGS = new Set([
  'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form',
  'input', 'template', 'textarea', 'select', 'button', 'noscript',
  'link', 'meta', 'base', 'frame', 'frameset',
]);

const XHTML_NS = 'http://www.w3.org/1999/xhtml';

/**
 * Vet an <a href>. Returns the value to set, or null to drop the attribute.
 */
function sanitizeHref(raw) {
  const href = String(raw).trim();
  if (!href) return null;
  // Browsers strip ASCII control chars, tabs and newlines out of URLs
  // before resolving the scheme, so "java\nscript:" is live to them.
  // Run the scheme check on the same compacted form they'd see.
  const probe = href.replace(/[\u0000-\u0020\u007f]+/g, '').toLowerCase();
  if (probe.startsWith('http://') || probe.startsWith('https://')) return href;
  // Any other explicit scheme (javascript:, data:, vbscript:, file:, ...)
  // and protocol-relative URLs are out.
  if (/^[a-z][a-z0-9+.-]*:/.test(probe) || probe.startsWith('//')) return null;
  // Same-page fragments / bare query strings mean nothing on our page.
  if (probe.startsWith('#') || probe.startsWith('?')) return null;
  // Relative path — archive.org descriptions link to their own site.
  const path = href.replace(/^\/+/, '');
  return path ? `https://archive.org/${path}` : null;
}

function sanitizeChildren(parent) {
  for (const child of Array.from(parent.childNodes)) {
    if (child.nodeType === Node.TEXT_NODE) continue;
    if (child.nodeType !== Node.ELEMENT_NODE) {
      // Comments, processing instructions — nothing worth keeping.
      child.remove();
      continue;
    }
    const tag = child.localName;
    if (SANITIZE_DROP_TAGS.has(tag) || child.namespaceURI !== XHTML_NS) {
      child.remove();
      continue;
    }
    if (!SANITIZE_ALLOWED_TAGS.has(tag)) {
      // Unwrap: clean its subtree in place, then hoist the children up.
      sanitizeChildren(child);
      while (child.firstChild) parent.insertBefore(child.firstChild, child);
      child.remove();
      continue;
    }
    // Allowed element: strip every attribute, then re-add a vetted href.
    const rawHref = tag === 'a' ? child.getAttribute('href') : null;
    for (const attr of Array.from(child.attributes)) {
      child.removeAttribute(attr.name);
    }
    if (rawHref !== null) {
      const safeHref = sanitizeHref(rawHref);
      if (safeHref) {
        child.setAttribute('href', safeHref);
        child.setAttribute('target', '_blank');
        child.setAttribute('rel', 'noopener noreferrer');
      }
    }
    sanitizeChildren(child);
  }
}

export function sanitizeHtml(html) {
  if (!html) return '';

  try {
    const doc = new DOMParser().parseFromString(String(html), 'text/html');
    const body = doc.body;
    if (!body) return '';
    sanitizeChildren(body);
    return body.innerHTML;
  } catch (err) {
    console.error('Error sanitizing HTML:', err);
    return escapeHtml(html);
  }
}

/**
 * Extract first value from array or return value
 */
export function extractValue(field) {
  return Array.isArray(field) ? field[0] : field;
}

/**
 * Format runtime from seconds to readable format
 */
export function formatRuntime(runtime) {
  if (!runtime) return '';
  const s = runtime.toString();
  if (s.includes(':')) return s;
  const secs = parseInt(s, 10);
  if (isNaN(secs)) return '';
  const mins = Math.floor(secs / 60);
  const remSecs = secs % 60;
  if (mins < 60) {
    return `${mins}:${remSecs.toString().padStart(2, '0')}`;
  }
  const hrs = Math.floor(mins / 60);
  const remM = mins % 60;
  return `${hrs}:${remM.toString().padStart(2, '0')}:${remSecs.toString().padStart(2, '0')}`;
}

/**
 * Format time from seconds
 */
export function formatTime(seconds) {
  if (isNaN(seconds)) return '0:00';
  const hours = Math.floor(seconds / 3600);
  const mins = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);

  if (hours > 0) {
    return `${hours}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  }
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

/**
 * Format file size to human readable
 */
export function formatFileSize(bytes) {
  if (!bytes || bytes < 0) return '';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  // Clamp the index so sub-1-byte values (log < 0) and very large values
  // (log beyond the last unit) don't index past the array into `undefined`.
  const i = Math.min(units.length - 1, Math.max(0, Math.floor(Math.log(bytes) / Math.log(1024))));
  return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + units[i];
}

/**
 * Debounce function execution
 */
export function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

/**
 * Throttle function execution
 */
export function throttle(func, limit) {
  let inThrottle;
  return function(...args) {
    if (!inThrottle) {
      func.apply(this, args);
      inThrottle = true;
      setTimeout(() => inThrottle = false, limit);
    }
  };
}

/**
 * Run `worker` over `items` with at most `limit` in flight at once.
 * Resolves to an array of results in input order. Never rejects — a worker
 * that throws leaves `undefined` in its slot so one bad item can't sink
 * the batch. Used to cap the archive.org metadata fallback fan-out.
 */
export async function mapWithConcurrency(items, limit, worker) {
  const results = new Array(items.length);
  let next = 0;
  const laneCount = Math.max(1, Math.min(limit, items.length));
  const lanes = Array.from({ length: laneCount }, async () => {
    while (next < items.length) {
      const i = next++;
      try {
        results[i] = await worker(items[i], i);
      } catch (_) {
        results[i] = undefined;
      }
    }
  });
  await Promise.all(lanes);
  return results;
}

/**
 * Get thumbnail URL for an archive.org item
 * Uses local caching API when available, falls back to archive.org
 */
let useLocalThumbnails = true; // Enabled by default - use local caching API for performance

export function getThumbnailUrl(identifier) {
  if (!identifier) return '';

  // Use local caching API if enabled
  if (useLocalThumbnails) {
    return `api/thumbnail.php?id=${encodeURIComponent(identifier)}`;
  }

  // Use archive.org directly (most reliable)
  return `https://archive.org/services/img/${identifier}`;
}

/**
 * Enable local thumbnail API
 */
export function enableLocalThumbnails() {
  useLocalThumbnails = true;
}

/**
 * Disable local thumbnail API (called when local API fails)
 */
export function disableLocalThumbnails() {
  useLocalThumbnails = false;
}

/**
 * Check if local thumbnail API is enabled
 */
export function isLocalThumbnailsEnabled() {
  return useLocalThumbnails;
}

export default {
  safeParseJSON,
  escapeHtml,
  sanitizeHtml,
  extractValue,
  formatRuntime,
  formatTime,
  formatFileSize,
  debounce,
  throttle,
  mapWithConcurrency,
  getThumbnailUrl,
  enableLocalThumbnails,
  disableLocalThumbnails,
  isLocalThumbnailsEnabled
};
