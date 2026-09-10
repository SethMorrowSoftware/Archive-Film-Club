        // State — hydrated from window.ADMIN_BOOTSTRAP written inline by admin.php
        const __bootstrap = (typeof window !== 'undefined' && window.ADMIN_BOOTSTRAP) || {};
        let selectedVideos = Array.isArray(__bootstrap.recommendations) ? __bootstrap.recommendations : [];
        let currentPage = 1;
        let currentQuery = '';
        let totalPages = 1;
        let currentPanel = 'dashboard';
        let siteSettings = __bootstrap.siteSettings || {};
        let draggedItem = null;
        let featuredSections = Array.isArray(__bootstrap.featuredSections) ? __bootstrap.featuredSections : [];
        let currentEditingSection = null;
        let dirtyPanels = new Set();
        let modalLastFocused = null;   // element to return focus to when the video search modal closes
        let modalKeydownHandler = null;
        let modalSearch = { sectionId: null, page: 1, totalPages: 1 };

        // Panels that have something to save. Every other panel (dashboard,
        // metrics, users, comments-mod, maintenance) is read-only or saves
        // through its own module, so the global Save button and the
        // unsaved-changes tracking are restricted to these.
        const SAVEABLE_PANELS = new Set(['staff-picks', 'site-settings', 'appearance', 'display', 'sections']);
        // Inline status element under each saveable panel's form.
        const PANEL_STATUS_IDS = {
            'staff-picks': 'staffPicksStatus',
            'site-settings': 'siteSettingsStatus',
            'appearance': 'appearanceStatus',
            'display': 'displayStatus',
            'sections': 'sectionsStatus'
        };

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            renderSelectedList();
            renderFeaturedSections();
            setupNavigation();
            setupColorPickers();
            setupDragAndDrop();
            setupSectionVideoDragAndDrop();
            setupChangeTracking();
            updateSaveButtonVisibility();

            // Warn before the page is unloaded with unsaved edits.
            window.addEventListener('beforeunload', (e) => {
                if (dirtyPanels.size === 0) return;
                e.preventDefault();
                e.returnValue = '';
            });

            // Enter key to search
            document.getElementById('searchInput').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') searchVideos();
            });

            // Escape key to close modals
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeVideoSearchModal();
                    closeSidebar();
                }
            });
        });

        // Toast notification system
        function showToast(message, type = 'info', duration = 3500) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;

            const icons = {
                success: '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
                error: '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                info: '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
                saving: '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="animation: spin 0.8s linear infinite;"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>'
            };

            // Icon markup is static; the message is caller-supplied (API error
            // strings etc.), so it goes in via textContent.
            toast.innerHTML = icons[type] || icons.info;
            const text = document.createElement('span');
            text.textContent = message;
            toast.appendChild(text);
            container.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('removing');
                setTimeout(() => toast.remove(), 250);
            }, duration);
        }

        // Change tracking (per-panel, uses event delegation for dynamic elements).
        // Only inputs that live inside a saveable panel count: the search /
        // filter controls on Users and Metrics also use .form-input but have
        // nothing to save.
        function setupChangeTracking() {
            const content = document.querySelector('.admin-content');
            const onEdit = (e) => {
                if (!e.target.matches('.form-input, .form-select, .color-picker, .toggle input')) return;
                const panelEl = e.target.closest('.panel');
                const panel = panelEl ? panelEl.id.replace(/^panel-/, '') : currentPanel;
                markUnsaved(panel);
            };
            content.addEventListener('input', onEdit);
            content.addEventListener('change', onEdit);
        }

        function markUnsaved(panel = currentPanel) {
            if (!SAVEABLE_PANELS.has(panel)) return;
            dirtyPanels.add(panel);
            updateUnsavedIndicator();
        }

        // Clear only the panel(s) that were actually saved. Callers name the
        // panels explicitly because a save can finish after the user has
        // already switched to a different panel.
        function clearUnsaved(...panels) {
            panels.forEach(p => dirtyPanels.delete(p));
            updateUnsavedIndicator();
        }

        function updateUnsavedIndicator() {
            const dot = document.getElementById('unsavedDot');
            dot.classList.toggle('visible', dirtyPanels.size > 0);
        }

        function updateSaveButtonVisibility() {
            const btn = document.getElementById('globalSaveBtn');
            btn.style.display = SAVEABLE_PANELS.has(currentPanel) ? 'inline-flex' : 'none';
        }

        // Inline save result under the panel's form, in addition to the toast
        // (which disappears after a few seconds). Uses the .save-status styles.
        function setSaveStatus(panel, message, type) {
            const el = document.getElementById(PANEL_STATUS_IDS[panel] || '');
            if (!el) return;
            clearTimeout(el._hideTimer);
            el.className = 'save-status ' + type;
            el.setAttribute('role', type === 'error' ? 'alert' : 'status');
            el.textContent = message;
            if (type === 'success') {
                el._hideTimer = setTimeout(() => {
                    el.className = '';
                    el.textContent = '';
                }, 8000);
            }
        }

        function closeSidebar() {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('open', 'visible');
        }

        // Navigation
        function setupNavigation() {
            document.querySelectorAll('.nav-item[data-panel]').forEach(item => {
                item.addEventListener('click', () => {
                    const panel = item.dataset.panel;
                    switchPanel(panel);
                });
            });
        }

        function switchPanel(panel) {
            // Offer to save before leaving a panel with unsaved edits. The
            // edits stay in the (hidden) panel either way; the beforeunload
            // guard above covers reloads.
            if (panel !== currentPanel && dirtyPanels.has(currentPanel)) {
                if (confirm('You have unsaved changes on this panel. Save them before switching?')) {
                    saveCurrentPanel();
                }
            }

            // Update nav items (they are ARIA tabs, so keep aria-selected in sync)
            document.querySelectorAll('.nav-item[data-panel]').forEach(item => {
                const isActive = item.dataset.panel === panel;
                item.classList.toggle('active', isActive);
                item.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            // Update panels
            document.querySelectorAll('.panel').forEach(p => {
                p.classList.toggle('active', p.id === `panel-${panel}`);
            });

            // Update title
            const titles = {
                'dashboard': 'Dashboard',
                'metrics': 'Metrics',
                'users': 'Users',
                'comments-mod': 'Comments',
                'staff-picks': 'Staff Picks',
                'site-settings': 'Site Settings',
                'appearance': 'Appearance',
                'display': 'Display Options',
                'sections': 'Featured Sections',
                'maintenance': 'Maintenance & Database'
            };
            // Notify other modules (admin-metrics.js) that a panel was opened
            // so they can lazy-load their data on first reveal.
            document.dispatchEvent(new CustomEvent('admin:panel-shown', { detail: panel }));
            document.getElementById('panelTitle').textContent = titles[panel] || panel;
            currentPanel = panel;
            updateSaveButtonVisibility();

            // Close mobile sidebar
            closeSidebar();
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
            // Delay adding visible class for transition
            if (overlay.classList.contains('open')) {
                requestAnimationFrame(() => overlay.classList.add('visible'));
            } else {
                overlay.classList.remove('visible');
            }
        }

        // Color Pickers
        function setupColorPickers() {
            const brandColor = document.getElementById('brandColor');
            const accentColor = document.getElementById('accentColor');

            brandColor.addEventListener('input', (e) => {
                document.getElementById('brandColorValue').textContent = e.target.value;
            });

            accentColor.addEventListener('input', (e) => {
                document.getElementById('accentColorValue').textContent = e.target.value;
            });
        }

        // Drag and Drop (Staff Picks list). Bound once: #selectedList is a
        // permanent container whose innerHTML is re-rendered, so delegated
        // listeners survive every render without stacking.
        function setupDragAndDrop() {
            const list = document.getElementById('selectedList');
            if (!list || list.dataset.listenersBound) return;
            list.dataset.listenersBound = '1';

            // Remove button (no inline handler; the id is read from data-id)
            list.addEventListener('click', (e) => {
                const btn = e.target.closest('.selected-item-remove');
                if (!btn) return;
                e.stopPropagation();
                const item = btn.closest('.selected-item');
                if (item) removeVideo(item.dataset.id);
            });

            list.addEventListener('dragstart', (e) => {
                if (e.target.classList.contains('selected-item')) {
                    draggedItem = e.target;
                    e.target.classList.add('dragging');
                }
            });

            list.addEventListener('dragend', (e) => {
                if (e.target.classList.contains('selected-item')) {
                    e.target.classList.remove('dragging');
                    draggedItem = null;
                    updateVideoOrder();
                }
            });

            list.addEventListener('dragover', (e) => {
                e.preventDefault();
                const afterElement = getDragAfterElement(list, e.clientY);
                if (draggedItem) {
                    if (afterElement == null) {
                        list.appendChild(draggedItem);
                    } else {
                        list.insertBefore(draggedItem, afterElement);
                    }
                }
            });
        }

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.selected-item:not(.dragging)')];

            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }

        function updateVideoOrder() {
            const items = document.querySelectorAll('.selected-item');
            const newOrder = [];
            items.forEach(item => {
                const id = item.dataset.id;
                const video = selectedVideos.find(v => v.id === id);
                if (video) newOrder.push(video);
            });
            selectedVideos = newOrder;
            markUnsaved('staff-picks');
        }

        // Search videos
        async function searchVideos(page = 1) {
            const query = document.getElementById('searchInput').value.trim();
            if (!query) return;

            currentQuery = query;
            currentPage = page;

            const resultsDiv = document.getElementById('searchResults');
            resultsDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Searching Archive.org...</p></div>';

            try {
                const params = new URLSearchParams({
                    q: `${query} AND mediatype:(movies OR video)`,
                    output: 'json',
                    rows: '24',
                    page: String(page)
                });

                ['identifier', 'title', 'creator', 'year', 'downloads'].forEach(f => {
                    params.append('fl[]', f);
                });

                params.append('sort[]', 'downloads desc');

                const response = await fetch(`https://archive.org/advancedsearch.php?${params}`);
                const data = await response.json();

                if (data.response && data.response.docs) {
                    renderResults(data.response.docs);
                    totalPages = Math.ceil((data.response.numFound || 0) / 24);
                    renderPagination();
                }
            } catch (error) {
                resultsDiv.innerHTML = `<div class="empty-state"><div class="empty-state-icon">❌</div><div class="empty-state-title">Search failed</div><p class="empty-state-text">${escapeHtml(error.message)}</p></div>`;
            }
        }

        // Render search results
        function renderResults(docs) {
            const resultsDiv = document.getElementById('searchResults');

            if (!docs.length) {
                resultsDiv.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🎬</div><div class="empty-state-title">No videos found</div><p class="empty-state-text">Try a different search term</p></div>';
                return;
            }

            resultsDiv.innerHTML = '<div class="results-grid">' + docs.map(doc => {
                const id = doc.identifier;
                const title = Array.isArray(doc.title) ? doc.title[0] : (doc.title || 'Untitled');
                const creator = Array.isArray(doc.creator) ? doc.creator[0] : (doc.creator || 'Unknown');
                const year = doc.year || '';
                const isSelected = selectedVideos.some(v => v.id === id);
                const thumb = `https://archive.org/services/img/${id}`;

                return `
                    <div class="video-card ${isSelected ? 'selected' : ''}" data-id="${escapeAttribute(id)}" data-title="${escapeAttribute(title)}" data-creator="${escapeAttribute(creator)}">
                        <div class="video-card-thumb">
                            <img src="${thumb}" alt="${escapeHtml(title)}" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 9%22><rect fill=%22%23242428%22 width=%2216%22 height=%229%22/><text x=%228%22 y=%225%22 fill=%22%2371717a%22 text-anchor=%22middle%22 font-size=%222%22>🎬</text></svg>'">
                            ${isSelected ? '<div class="video-card-badge"><span>✓</span> Added</div>' : ''}
                        </div>
                        <div class="video-card-content">
                            <div class="video-card-title">${escapeHtml(title)}</div>
                            <div class="video-card-meta">${escapeHtml(creator)}${year ? ' · ' + year : ''}</div>
                        </div>
                    </div>
                `;
            }).join('') + '</div>';

            resultsDiv.querySelectorAll('.video-card').forEach(card => {
                card.addEventListener('click', () => {
                    toggleVideo(card.dataset.id, card.dataset.title, card.dataset.creator);
                });
            });
        }

        // Render pagination
        function renderPagination() {
            const paginationDiv = document.getElementById('pagination');

            if (totalPages <= 1) {
                paginationDiv.style.display = 'none';
                return;
            }

            paginationDiv.style.display = 'flex';
            paginationDiv.innerHTML = `
                <button onclick="searchVideos(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}>← Previous</button>
                <button disabled style="opacity: 0.7;">Page ${currentPage} of ${totalPages}</button>
                <button onclick="searchVideos(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}>Next →</button>
            `;
        }

        // Toggle video selection
        function toggleVideo(id, title, creator) {
            const index = selectedVideos.findIndex(v => v.id === id);

            if (index > -1) {
                selectedVideos.splice(index, 1);
            } else {
                selectedVideos.push({ id, title, creator });
            }

            renderSelectedList();
            updateSearchCards();
            markUnsaved('staff-picks');
        }

        function updateSearchCards() {
            document.querySelectorAll('.video-card').forEach(card => {
                const cardId = card.dataset.id;
                if (!cardId) return;
                const isSelected = selectedVideos.some(v => v.id === cardId);
                card.classList.toggle('selected', isSelected);

                const badge = card.querySelector('.video-card-badge');
                if (isSelected && !badge) {
                    card.querySelector('.video-card-thumb').insertAdjacentHTML('beforeend', '<div class="video-card-badge"><span>✓</span> Added</div>');
                } else if (!isSelected && badge) {
                    badge.remove();
                }
            });
        }

        // Remove video from selection
        function removeVideo(id) {
            const video = selectedVideos.find(v => v.id === id);
            if (!video) return;
            if (!confirm(`Remove "${video.title || video.id}" from Staff Picks?`)) return;

            selectedVideos = selectedVideos.filter(v => v.id !== id);
            renderSelectedList();
            updateSearchCards();
            markUnsaved('staff-picks');
        }

        // Render selected videos list
        function renderSelectedList() {
            const listDiv = document.getElementById('selectedList');
            const countSpan = document.getElementById('selectedCount');
            const navCount = document.getElementById('navVideoCount');

            countSpan.textContent = selectedVideos.length;
            navCount.textContent = selectedVideos.length;

            if (!selectedVideos.length) {
                listDiv.innerHTML = '<div class="empty-state"><div class="empty-state-icon">📋</div><div class="empty-state-title">No videos selected</div><p class="empty-state-text">Search and click videos to add them</p></div>';
                return;
            }

            listDiv.innerHTML = selectedVideos.map(video => `
                <div class="selected-item" data-id="${escapeAttribute(video.id)}" draggable="true">
                    <div class="selected-item-drag">⋮⋮</div>
                    <div class="selected-item-thumb">
                        <img src="https://archive.org/services/img/${encodeURIComponent(video.id)}" alt="${escapeAttribute(video.title)}">
                    </div>
                    <div class="selected-item-info">
                        <div class="selected-item-title">${escapeHtml(video.title)}</div>
                        <div class="selected-item-id">${escapeHtml(video.id)}</div>
                    </div>
                    <button type="button" class="selected-item-remove" title="Remove" aria-label="Remove ${escapeAttribute(video.title)}">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4l8 8M12 4l-8 8"/>
                        </svg>
                    </button>
                </div>
            `).join('');
        }

        // Save current panel
        function saveCurrentPanel() {
            switch(currentPanel) {
                case 'dashboard':
                    return; // Nothing to save
                case 'staff-picks':
                    saveStaffPicks();
                    break;
                case 'site-settings':
                case 'appearance':
                case 'display':
                    saveSiteSettings();
                    break;
                case 'sections':
                    saveFeaturedSections();
                    break;
            }
        }

        // Save Staff Picks
        async function saveStaffPicks() {
            const title = document.getElementById('sectionTitle').value.trim() || 'Staff Picks';
            const enabled = document.getElementById('enabledToggle').checked;

            showToast('Saving staff picks...', 'saving', 2000);

            try {
                const response = await fetch('api/recommendations.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: (function() {
                        const h = { 'Content-Type': 'application/json' };
                        const m = document.querySelector('meta[name="csrf-token"]');
                        if (m) h['X-CSRF-Token'] = m.getAttribute('content') || '';
                        return h;
                    })(),
                    body: JSON.stringify({
                        enabled: enabled,
                        title: title,
                        videos: selectedVideos
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showToast('Staff picks saved successfully', 'success');
                    setSaveStatus('staff-picks', `Staff picks saved at ${new Date().toLocaleTimeString()}`, 'success');
                    clearUnsaved('staff-picks');
                    refreshPreview();
                } else {
                    showToast(`Error: ${result.error || 'Unknown error'}`, 'error', 5000);
                    setSaveStatus('staff-picks', `Save failed: ${result.error || 'Unknown error'}`, 'error');
                }
            } catch (error) {
                showToast(`Error: ${error.message}`, 'error', 5000);
                setSaveStatus('staff-picks', `Save failed: ${error.message}`, 'error');
            }
        }

        // Save Site Settings
        async function saveSiteSettings() {
            // Three panels share this endpoint; report inline on the one the
            // save was started from.
            const originPanel = ['site-settings', 'appearance', 'display'].includes(currentPanel) ? currentPanel : 'site-settings';
            const settings = {
                siteName: document.getElementById('siteName')?.value || 'Archive Film Club',
                tagline: document.getElementById('siteTagline')?.value || '',
                brandColor: document.getElementById('brandColor')?.value || '#ff0000',
                accentColor: document.getElementById('accentColor')?.value || '#065fd4',
                defaultTheme: document.getElementById('defaultTheme')?.value || 'dark',
                enableThemeToggle: document.getElementById('enableThemeToggle')?.checked ?? true,
                cardStyle: document.getElementById('cardStyle')?.value || 'modern',
                showDownloadCount: document.getElementById('showDownloadCount')?.checked ?? true,
                showCreator: document.getElementById('showCreator')?.checked ?? true,
                showDate: document.getElementById('showDate')?.checked ?? true,
                enableBookmarks: document.getElementById('enableBookmarks')?.checked ?? false,
                enableWatchHistory: document.getElementById('enableWatchHistory')?.checked ?? true,
                defaultCollection: document.getElementById('defaultCollection')?.value || 'all_videos',
                defaultSort: document.getElementById('defaultSort')?.value || 'downloads'
            };

            showToast('Saving settings...', 'saving', 2000);

            try {
                const response = await fetch('api/settings.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: (function() {
                        const h = { 'Content-Type': 'application/json' };
                        const m = document.querySelector('meta[name="csrf-token"]');
                        if (m) h['X-CSRF-Token'] = m.getAttribute('content') || '';
                        return h;
                    })(),
                    body: JSON.stringify(settings)
                });

                const result = await response.json();

                if (result.success) {
                    showToast('Settings saved successfully', 'success');
                    setSaveStatus(originPanel, `Settings saved at ${new Date().toLocaleTimeString()}`, 'success');
                    clearUnsaved('site-settings', 'appearance', 'display');
                    refreshPreview();
                } else {
                    showToast(`Error: ${result.error || 'Unknown error'}`, 'error', 5000);
                    setSaveStatus(originPanel, `Save failed: ${result.error || 'Unknown error'}`, 'error');
                }
            } catch (error) {
                showToast(`Error: ${error.message}`, 'error', 5000);
                setSaveStatus(originPanel, `Save failed: ${error.message}`, 'error');
            }
        }

        function refreshPreview() {
            const frame = document.getElementById('previewFrame');
            if (frame) {
                frame.src = frame.src;
            }
        }

        // ===== FEATURED SECTIONS MANAGEMENT =====

        function renderFeaturedSections() {
            const container = document.getElementById('sectionsList');
            if (!container) return;

            if (!featuredSections || featuredSections.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📂</div>
                        <div class="empty-state-title">No featured sections yet</div>
                        <p class="empty-state-text">Create sections to organize and showcase content on your homepage</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = featuredSections.map((section, index) => `
                <div class="card" style="margin-bottom: 16px;" data-section-id="${escapeAttribute(section.id)}">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">${escapeHtml(section.title)}</h3>
                            <p class="card-subtitle">${section.videos?.length || 0} videos &middot; ${section.enabled ? '<span style="color:var(--success)">Enabled</span>' : '<span style="color:var(--text-tertiary)">Disabled</span>'}</p>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn btn-secondary btn-sm" onclick="editSection(${escapeJsArg(section.id)})">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteSection(${escapeJsArg(section.id)})">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                Delete
                            </button>
                        </div>
                    </div>
                    ${section.description ? `
                        <div class="card-body">
                            <p style="color: var(--text-secondary); font-size: 14px;">${escapeHtml(section.description)}</p>
                        </div>
                    ` : ''}
                </div>
            `).join('');
        }

        function addNewSection() {
            // Discard any half-finished edit first (this also drops a previous
            // unsaved "New Section" so a later save can't persist it).
            if (currentEditingSection) cancelEditSection();

            const newSection = {
                id: 'section-' + Date.now(),
                title: 'New Section',
                description: '',
                enabled: true,
                videos: [],
                _isNew: true // client-only: cleared on save, section dropped on cancel
            };

            featuredSections.push(newSection);
            renderFeaturedSections();
            editSection(newSection.id);
        }

        function editSection(sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            currentEditingSection = sectionId;

            const container = document.getElementById('sectionsList');
            const sectionCard = container.querySelector(`[data-section-id="${CSS.escape(sectionId)}"]`);
            if (!sectionCard) return;

            sectionCard.innerHTML = `
                <div class="card-header">
                    <h3 class="card-title">Edit Section</h3>
                    <button class="btn btn-ghost btn-sm" onclick="cancelEditSection()">Cancel</button>
                </div>
                <div class="card-body">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label">Section Title</label>
                        <input type="text" class="form-input" id="editSectionTitle" value="${escapeHtml(section.title)}" placeholder="e.g., Classic Westerns">
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label">Description (Optional)</label>
                        <input type="text" class="form-input" id="editSectionDescription" value="${escapeHtml(section.description || '')}" placeholder="Brief description of this section">
                    </div>

                    <div class="toggle-wrapper" style="margin-bottom: 24px;">
                        <div>
                            <div class="toggle-label">Show on homepage</div>
                            <div class="toggle-description">Display this section to visitors</div>
                        </div>
                        <label class="toggle">
                            <input type="checkbox" id="editSectionEnabled" ${section.enabled ? 'checked' : ''}>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="divider"></div>

                    <div style="margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <label class="form-label" style="margin: 0;">Videos in this section</label>
                            <button class="btn btn-primary btn-sm" onclick="openVideoSearchModal(${escapeJsArg(sectionId)})">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Videos
                            </button>
                        </div>
                        <div id="sectionVideosList" class="selected-list" style="max-height: 300px;">
                            ${renderSectionVideoItems(section)}
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 20px;">
                        <button class="btn btn-success btn-sm" onclick="saveEditSection(${escapeJsArg(sectionId)})">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                            Save Section
                        </button>
                        <button class="btn btn-secondary btn-sm" onclick="cancelEditSection()">
                            Cancel
                        </button>
                    </div>
                </div>
            `;
        }

        // Markup for the videos inside the section editor. Shared by
        // editSection(), removeVideoFromSection() and toggleVideoForSection().
        function renderSectionVideoItems(section) {
            const videos = section.videos || [];
            if (!videos.length) {
                return '<div class="empty-state"><div class="empty-state-icon">🎬</div><div class="empty-state-title">No videos yet</div><p class="empty-state-text">Add videos to this section</p></div>';
            }
            return videos.map(video => `
                <div class="selected-item" data-video-id="${escapeAttribute(video.id)}" draggable="true">
                    <div class="selected-item-drag">⋮⋮</div>
                    <div class="selected-item-thumb">
                        <img src="https://archive.org/services/img/${encodeURIComponent(video.id)}" alt="${escapeAttribute(video.title)}">
                    </div>
                    <div class="selected-item-info">
                        <div class="selected-item-title">${escapeHtml(video.title)}</div>
                        <div class="selected-item-id">${escapeHtml(video.id)}</div>
                    </div>
                    <button type="button" class="selected-item-remove" title="Remove" aria-label="Remove ${escapeAttribute(video.title)}">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4l8 8M12 4l-8 8"/>
                        </svg>
                    </button>
                </div>
            `).join('');
        }

        // Drag and drop + remove-button clicks for the section editor's video
        // list. #sectionVideosList is re-created on every render, so the
        // listeners are delegated from the permanent #sectionsList container
        // and bound exactly once (they used to be re-attached on every render,
        // stacking handlers).
        function setupSectionVideoDragAndDrop() {
            const container = document.getElementById('sectionsList');
            if (!container || container.dataset.listenersBound) return;
            container.dataset.listenersBound = '1';

            let draggedItem = null;
            const listOf = (e) => (e.target && e.target.closest) ? e.target.closest('#sectionVideosList') : null;

            container.addEventListener('click', (e) => {
                const btn = e.target.closest('.selected-item-remove');
                if (!btn || !listOf(e)) return;
                e.stopPropagation();
                const item = btn.closest('.selected-item');
                if (item && currentEditingSection) removeVideoFromSection(currentEditingSection, item.dataset.videoId);
            });

            container.addEventListener('dragstart', (e) => {
                if (listOf(e) && e.target.classList.contains('selected-item')) {
                    draggedItem = e.target;
                    e.target.classList.add('dragging');
                }
            });

            container.addEventListener('dragend', (e) => {
                if (listOf(e) && e.target.classList.contains('selected-item')) {
                    e.target.classList.remove('dragging');
                    draggedItem = null;
                    updateSectionVideoOrder();
                }
            });

            container.addEventListener('dragover', (e) => {
                const list = listOf(e);
                if (!list) return;
                e.preventDefault();
                const afterElement = getDragAfterElement(list, e.clientY);
                if (draggedItem) {
                    if (afterElement == null) {
                        list.appendChild(draggedItem);
                    } else {
                        list.insertBefore(draggedItem, afterElement);
                    }
                }
            });
        }

        function updateSectionVideoOrder() {
            if (!currentEditingSection) return;

            const section = featuredSections.find(s => s.id === currentEditingSection);
            if (!section) return;

            const items = document.querySelectorAll('#sectionVideosList .selected-item');
            const newOrder = [];

            items.forEach(item => {
                const videoId = item.dataset.videoId;
                const video = section.videos.find(v => v.id === videoId);
                if (video) newOrder.push(video);
            });

            section.videos = newOrder;
            markUnsaved('sections');
        }

        function saveEditSection(sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            const title = document.getElementById('editSectionTitle')?.value.trim();
            const description = document.getElementById('editSectionDescription')?.value.trim();
            const enabled = document.getElementById('editSectionEnabled')?.checked;

            if (!title) {
                showToast('Section title is required', 'error');
                document.getElementById('editSectionTitle')?.focus();
                return;
            }

            section.title = title;
            section.description = description;
            section.enabled = enabled;
            section.updated = new Date().toISOString();
            delete section._isNew;

            currentEditingSection = null;
            renderFeaturedSections();

            // Auto-save to server
            saveFeaturedSections();
        }

        function cancelEditSection() {
            // A section created via "Add Section" that was never saved is a
            // phantom: drop it instead of leaving "New Section" behind for the
            // next auto-save to persist.
            const editing = featuredSections.find(s => s.id === currentEditingSection);
            if (editing && editing._isNew) {
                featuredSections = featuredSections.filter(s => s !== editing);
            }
            currentEditingSection = null;
            renderFeaturedSections();
        }

        function deleteSection(sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            if (!confirm(`Delete "${section.title}"? This cannot be undone.`)) {
                return;
            }

            featuredSections = featuredSections.filter(s => s.id !== sectionId);
            renderFeaturedSections();

            // Auto-save to server
            saveFeaturedSections();
        }

        function removeVideoFromSection(sectionId, videoId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            section.videos = section.videos.filter(v => v.id !== videoId);
            markUnsaved('sections');

            // Re-render the video list
            const videosList = document.getElementById('sectionVideosList');
            if (videosList) {
                videosList.innerHTML = renderSectionVideoItems(section);
            }
        }

        function openVideoSearchModal(sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            modalLastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

            // Create modal overlay
            const modal = document.createElement('div');
            modal.id = 'videoSearchModal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('aria-labelledby', 'videoSearchModalTitle');
            modal.style.cssText = `
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.7);
                backdrop-filter: blur(8px);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
                padding: 20px;
                animation: modalFadeIn 0.2s ease;
            `;
            // Add modal animation keyframes if not already present
            if (!document.getElementById('modalStyles')) {
                const style = document.createElement('style');
                style.id = 'modalStyles';
                style.textContent = `
                    @keyframes modalFadeIn { from { opacity: 0; } to { opacity: 1; } }
                    @keyframes modalSlideIn { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }
                `;
                document.head.appendChild(style);
            }

            modal.innerHTML = `
                <div class="card" style="width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto; animation: modalSlideIn 0.25s ease;">
                    <div class="card-header">
                        <h3 class="card-title" id="videoSearchModalTitle">Add Videos to ${escapeHtml(section.title)}</h3>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="closeVideoSearchModal()">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Close
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="search-box">
                            <div class="search-input-wrapper">
                                <svg class="search-icon" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="M21 21l-4.35-4.35"/>
                                </svg>
                                <input type="text" class="search-input" id="modalSearchInput" placeholder="Search movies, shows, documentaries...">
                            </div>
                            <button type="button" class="btn btn-primary" id="modalSearchBtn">
                                Search
                            </button>
                        </div>
                        <div id="modalSearchResults">
                            <div class="empty-state">
                                <div class="empty-state-icon">🔍</div>
                                <div class="empty-state-title">Search for videos</div>
                                <p class="empty-state-text">Click on videos to add them to this section</p>
                            </div>
                        </div>
                        <div id="modalPagination" class="pagination" style="display: none;"></div>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);

            const searchInput = document.getElementById('modalSearchInput');
            document.getElementById('modalSearchBtn').addEventListener('click', () => searchVideosForSection(sectionId));

            // Enter key to search
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') searchVideosForSection(sectionId);
            });

            // Close on backdrop click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeVideoSearchModal();
            });

            // Keep Tab / Shift+Tab cycling inside the dialog while it is open
            // (aria-modal="true" promises this to assistive tech).
            modalKeydownHandler = (e) => {
                if (e.key === 'Tab') trapModalFocus(modal, e);
            };
            document.addEventListener('keydown', modalKeydownHandler);

            // Move focus into the dialog
            searchInput.focus();
        }

        function trapModalFocus(modal, e) {
            const items = Array.from(modal.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            )).filter(el => !el.disabled && el.offsetParent !== null);
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            const active = document.activeElement;
            if (e.shiftKey && (active === first || !modal.contains(active))) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && (active === last || !modal.contains(active))) {
                e.preventDefault();
                first.focus();
            }
        }

        function closeVideoSearchModal() {
            const modal = document.getElementById('videoSearchModal');
            if (!modal) return;
            modal.remove();

            if (modalKeydownHandler) {
                document.removeEventListener('keydown', modalKeydownHandler);
                modalKeydownHandler = null;
            }
            modalSearch = { sectionId: null, page: 1, totalPages: 1 };

            // Return focus to the control that opened the dialog (if still on page).
            if (modalLastFocused && document.contains(modalLastFocused)) {
                modalLastFocused.focus();
            }
            modalLastFocused = null;
        }

        async function searchVideosForSection(sectionId, page = 1) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            const query = document.getElementById('modalSearchInput').value.trim();
            if (!query) return;

            modalSearch.sectionId = sectionId;
            modalSearch.page = page;

            const resultsDiv = document.getElementById('modalSearchResults');
            resultsDiv.innerHTML = '<div class="loading"><div class="spinner"></div><p>Searching Archive.org...</p></div>';

            try {
                const params = new URLSearchParams({
                    q: `${query} AND mediatype:(movies OR video)`,
                    output: 'json',
                    rows: '24',
                    page: String(page)
                });

                ['identifier', 'title', 'creator', 'year', 'downloads'].forEach(f => {
                    params.append('fl[]', f);
                });

                params.append('sort[]', 'downloads desc');

                const response = await fetch(`https://archive.org/advancedsearch.php?${params}`);
                const data = await response.json();

                if (data.response && data.response.docs) {
                    renderModalResults(data.response.docs, sectionId);
                    modalSearch.totalPages = Math.ceil((data.response.numFound || 0) / 24);
                    renderModalPagination(sectionId);
                    if (page > 1) resultsDiv.scrollIntoView({ block: 'start' });
                }
            } catch (error) {
                modalSearch.totalPages = 1;
                renderModalPagination(sectionId);
                resultsDiv.innerHTML = `<div class="empty-state"><div class="empty-state-icon">❌</div><div class="empty-state-title">Search failed</div><p class="empty-state-text">${escapeHtml(error.message)}</p></div>`;
            }
        }

        // Modal pagination: same pattern as renderPagination() for Staff
        // Picks, but wired with listeners so the section id never travels
        // through an inline handler string.
        function renderModalPagination(sectionId) {
            const paginationDiv = document.getElementById('modalPagination');
            if (!paginationDiv) return;

            if (modalSearch.totalPages <= 1) {
                paginationDiv.style.display = 'none';
                paginationDiv.innerHTML = '';
                return;
            }

            const current = modalSearch.page;
            const total = modalSearch.totalPages;
            paginationDiv.style.display = 'flex';
            paginationDiv.innerHTML = `
                <button type="button" data-page="${current - 1}" ${current <= 1 ? 'disabled' : ''}>← Previous</button>
                <button type="button" disabled style="opacity: 0.7;">Page ${current} of ${total}</button>
                <button type="button" data-page="${current + 1}" ${current >= total ? 'disabled' : ''}>Next →</button>
            `;
            paginationDiv.querySelectorAll('button[data-page]').forEach(btn => {
                btn.addEventListener('click', () => {
                    searchVideosForSection(sectionId, parseInt(btn.dataset.page, 10) || 1);
                });
            });
        }

        function renderModalResults(docs, sectionId) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            const resultsDiv = document.getElementById('modalSearchResults');

            if (!docs.length) {
                resultsDiv.innerHTML = '<div class="empty-state"><div class="empty-state-icon">🎬</div><div class="empty-state-title">No videos found</div><p class="empty-state-text">Try a different search term</p></div>';
                return;
            }

            resultsDiv.innerHTML = '<div class="results-grid">' + docs.map(doc => {
                const id = doc.identifier;
                const title = Array.isArray(doc.title) ? doc.title[0] : (doc.title || 'Untitled');
                const creator = Array.isArray(doc.creator) ? doc.creator[0] : (doc.creator || 'Unknown');
                const year = doc.year || '';
                const isSelected = section.videos.some(v => v.id === id);
                const thumb = `https://archive.org/services/img/${id}`;

                return `
                    <div class="video-card ${isSelected ? 'selected' : ''}" data-id="${escapeAttribute(id)}" data-title="${escapeAttribute(title)}" data-creator="${escapeAttribute(creator)}">
                        <div class="video-card-thumb">
                            <img src="${thumb}" alt="${escapeHtml(title)}" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 9%22><rect fill=%22%23242428%22 width=%2216%22 height=%229%22/><text x=%228%22 y=%225%22 fill=%22%2371717a%22 text-anchor=%22middle%22 font-size=%222%22>🎬</text></svg>'">
                            ${isSelected ? '<div class="video-card-badge"><span>✓</span> Added</div>' : ''}
                        </div>
                        <div class="video-card-content">
                            <div class="video-card-title">${escapeHtml(title)}</div>
                            <div class="video-card-meta">${escapeHtml(creator)}${year ? ' · ' + year : ''}</div>
                        </div>
                    </div>
                `;
            }).join('') + '</div>';

            resultsDiv.querySelectorAll('.video-card').forEach(card => {
                card.addEventListener('click', () => {
                    toggleVideoForSection(sectionId, card.dataset.id, card.dataset.title, card.dataset.creator);
                });
            });
        }

        function toggleVideoForSection(sectionId, videoId, title, creator) {
            const section = featuredSections.find(s => s.id === sectionId);
            if (!section) return;

            const index = section.videos.findIndex(v => v.id === videoId);

            if (index > -1) {
                section.videos.splice(index, 1);
            } else {
                section.videos.push({ id: videoId, title: title, creator: creator });
            }
            markUnsaved('sections');

            // Update the modal view
            const cards = document.querySelectorAll('#modalSearchResults .video-card');
            cards.forEach(card => {
                if (card.dataset.id !== videoId) return;
                const isSelected = section.videos.some(v => v.id === videoId);
                card.classList.toggle('selected', isSelected);

                const badge = card.querySelector('.video-card-badge');
                if (isSelected && !badge) {
                    card.querySelector('.video-card-thumb').insertAdjacentHTML('beforeend', '<div class="video-card-badge"><span>✓</span> Added</div>');
                } else if (!isSelected && badge) {
                    badge.remove();
                }
            });

            // Update the section videos list in the background
            const videosList = document.getElementById('sectionVideosList');
            if (videosList && currentEditingSection === sectionId) {
                videosList.innerHTML = renderSectionVideoItems(section);
            }
        }

        async function saveFeaturedSections() {
            showToast('Saving sections...', 'saving', 2000);

            try {
                const response = await fetch('api/sections.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: (function() {
                        const h = { 'Content-Type': 'application/json' };
                        const m = document.querySelector('meta[name="csrf-token"]');
                        if (m) h['X-CSRF-Token'] = m.getAttribute('content') || '';
                        return h;
                    })(),
                    // Strip the client-only _isNew marker before persisting.
                    body: JSON.stringify({ sections: featuredSections.map(({ _isNew, ...section }) => section) })
                });

                const result = await response.json();

                if (result.success) {
                    showToast('Featured sections saved successfully', 'success');
                    setSaveStatus('sections', `Sections saved at ${new Date().toLocaleTimeString()}`, 'success');
                    clearUnsaved('sections');
                    refreshPreview();
                } else {
                    showToast(`Error: ${result.error || 'Unknown error'}`, 'error', 5000);
                    setSaveStatus('sections', `Save failed: ${result.error || 'Unknown error'}`, 'error');
                }
            } catch (error) {
                showToast(`Error: ${error.message}`, 'error', 5000);
                setSaveStatus('sections', `Save failed: ${error.message}`, 'error');
            }
        }

        // Escape for HTML text AND attribute contexts: & < > " ' are all
        // encoded, so call sites inside alt="…", value="…" and data-* are safe
        // too (mirrors escapeHtml() in admin-metrics.js).
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[c]);
        }

        // Kept as an alias for existing call sites.
        function escapeAttribute(text) {
            return escapeHtml(text);
        }

        // A JS string argument inside an inline on*="…" handler: JSON.stringify
        // quotes/escapes it for JavaScript, escapeHtml for the attribute. The
        // HTML parser decodes the entities back before the handler runs, so it
        // sees a well-formed string literal whatever the id contains.
        function escapeJsArg(value) {
            return escapeHtml(JSON.stringify(String(value === null || value === undefined ? '' : value)));
        }
