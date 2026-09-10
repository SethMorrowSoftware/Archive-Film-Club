/**
 * CollectionPicker
 *
 * A modal dialog that lets the signed-in user add a video to (or remove
 * it from) one of their collections, or create a brand-new collection
 * on the spot.
 *
 * Usage:
 *   import { CollectionPicker } from './components/CollectionPicker.js';
 *   CollectionPicker.open({
 *     video: { identifier, title, creator, thumbnail },
 *     onChange: () => { ... }  // called after any mutation
 *   });
 *
 * The picker renders lazily on first use and reuses a single DOM root.
 */

import { CollectionService } from '../services/CollectionService.js';
import { AuthService } from '../services/AuthService.js';
import { trapFocus } from '../utils/focusTrap.js';

let rootEl = null;
let releaseTrap = null; // set by trapFocus() while the dialog is open
let state = {
  video: null,
  onChange: null,
  collections: [],
  containing: new Set(),
  loading: false,
  creating: false,
  error: null,
};

function ensureRoot() {
  if (rootEl) return rootEl;

  rootEl = document.createElement('div');
  rootEl.className = 'collection-picker';
  rootEl.setAttribute('role', 'dialog');
  rootEl.setAttribute('aria-modal', 'true');
  rootEl.innerHTML = `
    <div class="collection-picker-backdrop" data-close></div>
    <div class="collection-picker-card" role="document">
      <header class="collection-picker-header">
        <h2 class="collection-picker-title">Save to collection</h2>
        <button type="button" class="collection-picker-close" data-close aria-label="Close">
          &times;
        </button>
      </header>
      <div class="collection-picker-body">
        <div class="collection-picker-alert" data-error hidden></div>
        <ul class="collection-picker-list" data-list></ul>
        <div class="collection-picker-empty" data-empty hidden>
          You don't have any collections yet.
        </div>
      </div>
      <footer class="collection-picker-footer">
        <form class="collection-picker-create" data-create-form>
          <input
            type="text"
            name="name"
            placeholder="New collection name"
            maxlength="150"
            required
            class="collection-picker-input"
          />
          <label class="collection-picker-public">
            <input type="checkbox" name="is_public"> Public
          </label>
          <button type="submit" class="collection-picker-create-btn">Create</button>
        </form>
      </footer>
    </div>
  `;

  rootEl.addEventListener('click', handleClick);
  rootEl.querySelector('[data-create-form]').addEventListener('submit', handleCreate);

  // Tab cycling, scroll lock and focus restore come from utils/focusTrap.js
  // (shared with the share dialog and collection.php's modals); only
  // Escape is handled here.
  document.addEventListener('keydown', (e) => {
    if (!rootEl || !rootEl.hasAttribute('data-open')) return;
    if (e.key === 'Escape') close();
  });

  document.body.appendChild(rootEl);
  return rootEl;
}

function handleClick(e) {
  if (e.target.closest('[data-close]')) {
    close();
    return;
  }
  const toggle = e.target.closest('[data-collection-toggle]');
  if (toggle) {
    const collectionId = parseInt(toggle.dataset.id, 10);
    toggleMembership(collectionId);
  }
}

async function handleCreate(e) {
  e.preventDefault();
  if (state.creating) return;
  const form = e.currentTarget;
  const fd = new FormData(form);
  const name = String(fd.get('name') || '').trim();
  if (!name) return;

  state.creating = true;
  state.error = null;
  render();

  try {
    const collection = await CollectionService.create({
      name,
      is_public: fd.get('is_public') === 'on',
    });
    form.reset();
    // Immediately add the current video to the new collection
    await CollectionService.addItem(collection.id, videoToPayload(state.video));
    await refresh();
    if (state.onChange) state.onChange();
  } catch (err) {
    state.error = err.message || 'Could not create collection';
  } finally {
    state.creating = false;
    render();
  }
}

function videoToPayload(video) {
  return {
    archive_id: video.identifier || video.id,
    title: video.title || '',
    creator: video.creator || '',
    thumbnail: video.thumbnail || `https://archive.org/services/img/${video.identifier || video.id}`,
  };
}

async function toggleMembership(collectionId) {
  const video = state.video;
  if (!video) return;

  const inIt = state.containing.has(collectionId);
  try {
    if (inIt) {
      await CollectionService.removeItem(collectionId, video.identifier || video.id);
      state.containing.delete(collectionId);
    } else {
      await CollectionService.addItem(collectionId, videoToPayload(video));
      state.containing.add(collectionId);
    }
    if (state.onChange) state.onChange();
  } catch (err) {
    state.error = err.message || 'Could not update collection';
  }
  render();
}

async function refresh() {
  state.loading = true;
  state.error = null;
  render();

  try {
    const [collections, containing] = await Promise.all([
      CollectionService.listMine(),
      CollectionService.findContaining(state.video.identifier || state.video.id),
    ]);
    state.collections = collections;
    state.containing = new Set(containing.map(c => c.id));
  } catch (err) {
    state.error = err.message || 'Failed to load collections';
  } finally {
    state.loading = false;
    render();
  }
}

function render() {
  if (!rootEl) return;

  const list = rootEl.querySelector('[data-list]');
  const empty = rootEl.querySelector('[data-empty]');
  const errBox = rootEl.querySelector('[data-error]');
  const createBtn = rootEl.querySelector('.collection-picker-create-btn');

  // Error state
  if (state.error) {
    errBox.textContent = state.error;
    errBox.hidden = false;
  } else {
    errBox.hidden = true;
  }

  // List
  if (state.loading && !state.collections.length) {
    list.innerHTML = '<li class="collection-picker-row collection-picker-loading">Loading…</li>';
    empty.hidden = true;
  } else if (!state.collections.length) {
    list.innerHTML = '';
    empty.hidden = false;
  } else {
    empty.hidden = true;
    list.innerHTML = state.collections
      .map(c => renderRow(c, state.containing.has(c.id)))
      .join('');
  }

  createBtn.disabled = state.creating;
  createBtn.textContent = state.creating ? 'Creating…' : 'Create';
}

function escapeHtml(s) {
  return String(s || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function renderRow(collection, isMember) {
  const name = escapeHtml(collection.name);
  const count = Number(collection.item_count || 0);
  const publicBadge = collection.is_public
    ? '<span class="collection-picker-badge">Public</span>'
    : '';
  return `
    <li class="collection-picker-row">
      <button
        type="button"
        class="collection-picker-toggle ${isMember ? 'is-member' : ''}"
        data-collection-toggle
        data-id="${collection.id}"
      >
        <span class="collection-picker-checkmark" aria-hidden="true">${isMember ? '✓' : ''}</span>
        <span class="collection-picker-name">${name}</span>
        <span class="collection-picker-meta">${count} item${count === 1 ? '' : 's'}</span>
        ${publicBadge}
      </button>
    </li>
  `;
}

function open({ video, onChange = null } = {}) {
  if (!video) return;
  ensureRoot();

  // Require auth — if not signed in, redirect to login.
  if (!AuthService.isAuthenticated()) {
    const next = encodeURIComponent(window.location.pathname + window.location.search);
    window.location.href = `login.php?next=${next}`;
    return;
  }

  state = {
    video,
    onChange,
    collections: [],
    containing: new Set(),
    loading: false,
    creating: false,
    error: null,
  };

  rootEl.setAttribute('data-open', 'true');
  render();
  refresh();

  // Locks body scroll, moves focus to the close button and, on release,
  // returns it to the control that opened the dialog.
  if (releaseTrap) releaseTrap();
  releaseTrap = trapFocus(rootEl, {
    initialFocus: rootEl.querySelector('.collection-picker-close'),
  });
}

function close() {
  if (!rootEl) return;
  rootEl.removeAttribute('data-open');

  if (releaseTrap) {
    releaseTrap();
    releaseTrap = null;
  }
}

export const CollectionPicker = {
  open,
  close,
};

export default CollectionPicker;
