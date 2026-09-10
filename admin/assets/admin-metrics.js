/**
 * admin-metrics.js — drives Metrics / Users / Comments-moderation panels
 * and the new user-focused stat cards on the Dashboard.
 *
 * All endpoints live under api/admin/metrics.php. CSRF is read from the
 * <meta name="csrf-token"> tag emitted by csrf_meta_tag() in bootstrap.php.
 */
(function () {
    'use strict';

    const API = 'api/admin/metrics.php';

    function csrfToken() {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') || '' : '';
    }

    // If the admin session expires while the panel is open, every request
    // begins to fail with 401 and the UI shows a wall of "Couldn't load…"
    // toasts. Detect once and send the user back to the login page so they
    // can sign in again -- no point retrying.
    let authLostHandled = false;
    function handleAuthLost() {
        if (authLostHandled) return;
        authLostHandled = true;
        // admin.php without a valid session renders the sign-in form.
        window.location.href = 'admin.php';
    }

    async function apiGet(action, params = {}, opts = {}) {
        const q = new URLSearchParams({ action, ...params });
        const res = await fetch(`${API}?${q}`, { credentials: 'same-origin', signal: opts.signal });
        if (res.status === 401 || res.status === 403) { handleAuthLost(); throw new Error('Session expired'); }
        const json = await res.json().catch(() => ({ success: false, error: 'Bad response' }));
        if (!res.ok || !json.success) throw new Error(json.error || 'Request failed');
        return json;
    }
    async function apiPost(action, body = {}) {
        const res = await fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify({ action, ...body }),
        });
        if (res.status === 401 || res.status === 403) { handleAuthLost(); throw new Error('Session expired'); }
        const json = await res.json().catch(() => ({ success: false, error: 'Bad response' }));
        if (!res.ok || !json.success) throw new Error(json.error || 'Request failed');
        return json;
    }

    // -------- formatters --------
    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }
    function formatCount(n) {
        n = Number(n) || 0;
        if (n < 1000) return String(n);
        if (n < 1_000_000) return (n / 1000).toFixed(n < 10_000 ? 1 : 0).replace(/\.0$/, '') + 'K';
        return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
    }
    function relTime(iso) {
        if (!iso) return '—';
        const then = new Date(String(iso).replace(' ', 'T') + 'Z');
        if (isNaN(then)) return '—';
        const sec = Math.max(1, Math.floor((Date.now() - then) / 1000));
        if (sec < 60) return `${sec}s ago`;
        const min = Math.floor(sec / 60);
        if (min < 60) return `${min}m ago`;
        const hr = Math.floor(min / 60);
        if (hr < 24) return `${hr}h ago`;
        const day = Math.floor(hr / 24);
        if (day < 7) return `${day}d ago`;
        if (day < 30) return `${Math.floor(day / 7)}w ago`;
        if (day < 365) return `${Math.floor(day / 30)}mo ago`;
        return `${Math.floor(day / 365)}y ago`;
    }
    function fmtDate(iso) {
        if (!iso) return '—';
        const d = new Date(String(iso).replace(' ', 'T') + 'Z');
        if (isNaN(d)) return '—';
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function toast(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type || 'info');
        } else {
            console.log(`[${type || 'info'}] ${message}`);
        }
    }

    // ============================================================
    // DASHBOARD: user-focused stat cards + recent activity feeds
    // ============================================================

    async function loadDashboard() {
        try {
            const overview = await apiGet('overview');
            const u = overview.users || {};
            const e = overview.engagement || {};
            setText('dashStatMembers', formatCount(u.accounts));
            setText('dashStatMembersNew', `+${formatCount(u.new_7d)}`);
            setText('dashStatActive', formatCount(u.active_7d));
            setText('dashStatActive24h', formatCount(u.active_24h));
            setText('dashStatComments', formatCount(e.comments_7d));
            setText('dashStatReportsPending', formatCount(e.reports_pending));
            setText('dashStatViews', formatCount(e.watch_7d));
            setText('dashStatViews24h', formatCount(e.watch_24h));

            // Sidebar badges
            setText('navUserCount', formatCount(u.accounts));
            const reports = Number(e.reports_pending) || 0;
            const badge = document.getElementById('navReportsCount');
            if (badge) {
                badge.textContent = formatCount(reports);
                badge.style.display = reports > 0 ? '' : 'none';
                badge.classList.toggle('nav-item-badge--alert', reports > 0);
            }
            const repBadge = document.getElementById('reportedBadge');
            if (repBadge) {
                repBadge.textContent = formatCount(reports);
                repBadge.style.display = reports > 0 ? '' : 'none';
            }
        } catch (err) {
            console.warn('Dashboard overview load failed:', err);
            ['dashStatMembers','dashStatActive','dashStatComments','dashStatViews'].forEach(id => setText(id, '—'));
        }

        // Recent activity feeds
        loadRecentSignups();
        loadRecentComments();
    }

    async function loadRecentSignups() {
        const el = document.getElementById('dashRecentSignups');
        if (!el) return;
        try {
            const res = await apiGet('recent-signups', { limit: 6 });
            const users = res.users || [];
            if (users.length === 0) {
                el.innerHTML = '<li class="dashboard-feed-empty">No signups yet.</li>';
                return;
            }
            el.innerHTML = users.map(u => {
                const name = u.display_name || u.username;
                return `
                    <li class="dashboard-feed-item">
                        <div class="dashboard-feed-avatar">${escapeHtml(initial(name))}</div>
                        <div class="dashboard-feed-main">
                            <div class="dashboard-feed-title">${escapeHtml(name)} <span class="dashboard-feed-meta">${escapeHtml('@' + u.username)}</span></div>
                            <div class="dashboard-feed-sub">${escapeHtml(u.email || '')}</div>
                        </div>
                        <div class="dashboard-feed-time">${escapeHtml(relTime(u.created_at))}</div>
                    </li>
                `;
            }).join('');
        } catch (err) {
            el.innerHTML = '<li class="dashboard-feed-empty">Couldn’t load signups.</li>';
        }
    }

    async function loadRecentComments() {
        const el = document.getElementById('dashRecentComments');
        if (!el) return;
        try {
            const res = await apiGet('recent-comments', { limit: 6 });
            const comments = res.comments || [];
            if (comments.length === 0) {
                el.innerHTML = '<li class="dashboard-feed-empty">No comments yet.</li>';
                return;
            }
            el.innerHTML = comments.map(c => {
                const name = c.display_name || c.username;
                const snippet = (c.body || '').slice(0, 120);
                return `
                    <li class="dashboard-feed-item">
                        <div class="dashboard-feed-avatar">${escapeHtml(initial(name))}</div>
                        <div class="dashboard-feed-main">
                            <div class="dashboard-feed-title">${escapeHtml(name)}
                                <a class="dashboard-feed-meta" href="player.php?video=${encodeURIComponent(c.archive_id)}" target="_blank" rel="noopener noreferrer">on ${escapeHtml(c.archive_id)}</a>
                            </div>
                            <div class="dashboard-feed-sub">${escapeHtml(snippet)}${c.body && c.body.length > 120 ? '…' : ''}</div>
                        </div>
                        <div class="dashboard-feed-time">${escapeHtml(relTime(c.created_at))}</div>
                    </li>
                `;
            }).join('');
        } catch (err) {
            el.innerHTML = '<li class="dashboard-feed-empty">Couldn’t load comments.</li>';
        }
    }

    function initial(name) {
        return String(name || '?').trim().charAt(0).toUpperCase() || '?';
    }
    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    // ============================================================
    // METRICS PANEL: line chart + top lists
    // ============================================================

    const metrics = {
        loaded: false,
        currentMetric: 'signups',
        currentRange: 30,
        lastSeries: null, // last rendered series, for redraws on resize
    };

    async function loadMetrics() {
        const metricSel = document.getElementById('metricsMetric');
        const rangeSel = document.getElementById('metricsRange');
        if (!metricSel || metrics._listenersBound) {
            // First call binds listeners; subsequent calls just refetch.
        } else {
            metricSel.addEventListener('change', () => { metrics.currentMetric = metricSel.value; refreshChart(); });
            rangeSel.addEventListener('change', () => { metrics.currentRange = parseInt(rangeSel.value, 10); refreshChart(); });
            metrics._listenersBound = true;
        }
        await Promise.all([
            refreshChart(),
            loadTopVideos(),
            loadTopSearches(),
            loadTopCommenters(),
        ]);
        metrics.loaded = true;
    }

    async function refreshChart() {
        const svg = document.getElementById('metricsChart');
        const emptyEl = document.getElementById('metricsChartEmpty');
        const summaryEl = document.getElementById('metricsChartSummary');
        if (!svg) return;
        try {
            const res = await apiGet('series', { metric: metrics.currentMetric, days: metrics.currentRange });
            const series = res.series || [];
            const total = series.reduce((s, p) => s + p.count, 0);
            const peak = series.reduce((m, p) => p.count > m ? p.count : m, 0);
            const avg = series.length ? (total / series.length) : 0;

            if (total === 0) {
                svg.innerHTML = '';
                metrics.lastSeries = null;
                emptyEl.style.display = '';
                summaryEl.innerHTML = '';
                return;
            }
            emptyEl.style.display = 'none';
            metrics.lastSeries = series;
            renderLineChart(svg, series);
            summaryEl.innerHTML = `
                <div class="metrics-summary-stat"><span class="metrics-summary-label">Total</span><span class="metrics-summary-value">${formatCount(total)}</span></div>
                <div class="metrics-summary-stat"><span class="metrics-summary-label">Daily avg</span><span class="metrics-summary-value">${avg.toFixed(avg < 10 ? 1 : 0)}</span></div>
                <div class="metrics-summary-stat"><span class="metrics-summary-label">Peak day</span><span class="metrics-summary-value">${formatCount(peak)}</span></div>
            `;
        } catch (err) {
            svg.innerHTML = '';
            metrics.lastSeries = null;
            emptyEl.textContent = 'Couldn’t load chart data.';
            emptyEl.style.display = '';
            summaryEl.innerHTML = '';
        }
    }

    function renderLineChart(svg, points) {
        // Size the drawing to the SVG's real pixel box so one user unit is one
        // CSS pixel and the axis text is never stretched (the chart used to be
        // scaled with preserveAspectRatio="none"). Falls back to 600x220 when
        // the panel is hidden; redrawChart() repaints once it is visible.
        const W = svg.clientWidth || 600, H = svg.clientHeight || 220;
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
        const padL = 36, padR = 12, padT = 16, padB = 28;
        const w = W - padL - padR, h = H - padT - padB;
        const maxY = Math.max(1, ...points.map(p => p.count));
        // round up to a nice gridline number
        const niceMax = niceCeil(maxY);
        const xs = (i) => padL + (i / Math.max(1, points.length - 1)) * w;
        const ys = (v) => padT + h - (v / niceMax) * h;

        // gridlines (4 lines)
        const grid = [];
        for (let i = 0; i <= 4; i++) {
            const yval = niceMax * (i / 4);
            const y = ys(yval);
            grid.push(`<line x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}" class="metrics-chart-grid"/>`);
            grid.push(`<text x="${padL - 6}" y="${y + 4}" class="metrics-chart-label-y">${formatCount(Math.round(yval))}</text>`);
        }

        // path + area
        const linePts = points.map((p, i) => `${xs(i)},${ys(p.count)}`).join(' ');
        const areaPts = `${padL},${ys(0)} ${linePts} ${W - padR},${ys(0)}`;

        // x labels (first, middle, last)
        const xLabels = [];
        const ticks = points.length <= 7 ? points.map((_, i) => i) : [0, Math.floor(points.length / 2), points.length - 1];
        ticks.forEach(i => {
            const d = new Date(points[i].day + 'T00:00:00Z');
            const label = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            xLabels.push(`<text x="${xs(i)}" y="${H - 8}" class="metrics-chart-label-x" text-anchor="middle">${escapeHtml(label)}</text>`);
        });

        // dot for highlight (last point)
        const last = points[points.length - 1];
        const lastX = xs(points.length - 1);
        const lastY = ys(last.count);

        svg.innerHTML = `
            ${grid.join('')}
            <polygon class="metrics-chart-area" points="${areaPts}"/>
            <polyline class="metrics-chart-line" points="${linePts}"/>
            <circle class="metrics-chart-dot" cx="${lastX}" cy="${lastY}" r="3.5"/>
            ${xLabels.join('')}
        `;
    }

    function niceCeil(v) {
        if (v <= 5) return 5;
        if (v <= 10) return 10;
        const mag = Math.pow(10, Math.floor(Math.log10(v)));
        const norm = v / mag;
        let nice;
        if (norm <= 1) nice = 1;
        else if (norm <= 2) nice = 2;
        else if (norm <= 5) nice = 5;
        else nice = 10;
        return nice * mag;
    }

    async function loadTopVideos() {
        const el = document.getElementById('metricsTopVideos');
        if (!el) return;
        try {
            const res = await apiGet('top-videos', { days: 30, limit: 10 });
            const list = res.videos || [];
            if (list.length === 0) {
                el.innerHTML = '<li class="metrics-rank-empty">No watch activity yet.</li>';
                return;
            }
            el.innerHTML = list.map((v, i) => `
                <li class="metrics-rank-item">
                    <span class="metrics-rank-num">${i + 1}</span>
                    <a class="metrics-rank-main" href="player.php?video=${encodeURIComponent(v.archive_id)}" target="_blank">
                        <span class="metrics-rank-title">${escapeHtml(v.title || v.archive_id)}</span>
                        <span class="metrics-rank-meta">${escapeHtml(v.archive_id)}</span>
                    </a>
                    <span class="metrics-rank-stat">
                        <span class="metrics-rank-stat-num">${formatCount(v.unique_viewers)}</span>
                        <span class="metrics-rank-stat-label">viewers</span>
                    </span>
                </li>
            `).join('');
        } catch (err) {
            el.innerHTML = '<li class="metrics-rank-empty">Couldn’t load top videos.</li>';
        }
    }

    async function loadTopSearches() {
        const el = document.getElementById('metricsTopSearches');
        if (!el) return;
        try {
            const res = await apiGet('top-searches', { limit: 10 });
            const list = res.searches || [];
            if (list.length === 0) {
                el.innerHTML = '<li class="metrics-rank-empty">No searches yet.</li>';
                return;
            }
            el.innerHTML = list.map((s, i) => `
                <li class="metrics-rank-item">
                    <span class="metrics-rank-num">${i + 1}</span>
                    <span class="metrics-rank-main">
                        <span class="metrics-rank-title">${escapeHtml(s.query)}</span>
                    </span>
                    <span class="metrics-rank-stat">
                        <span class="metrics-rank-stat-num">${formatCount(s.search_count)}</span>
                        <span class="metrics-rank-stat-label">searches</span>
                    </span>
                </li>
            `).join('');
        } catch (err) {
            el.innerHTML = '<li class="metrics-rank-empty">Couldn’t load top searches.</li>';
        }
    }

    async function loadTopCommenters() {
        const el = document.getElementById('metricsTopCommenters');
        if (!el) return;
        try {
            const res = await apiGet('top-commenters', { days: 30, limit: 10 });
            const list = res.commenters || [];
            if (list.length === 0) {
                el.innerHTML = '<li class="metrics-rank-empty">No comments in this window.</li>';
                return;
            }
            el.innerHTML = list.map((u, i) => {
                const name = u.display_name || u.username;
                return `
                    <li class="metrics-rank-item">
                        <span class="metrics-rank-num">${i + 1}</span>
                        <span class="metrics-rank-main">
                            <span class="metrics-rank-avatar">${escapeHtml(initial(name))}</span>
                            <span>
                                <span class="metrics-rank-title">${escapeHtml(name)}</span>
                                <span class="metrics-rank-meta">@${escapeHtml(u.username)}</span>
                            </span>
                        </span>
                        <span class="metrics-rank-stat">
                            <span class="metrics-rank-stat-num">${formatCount(u.comment_count)}</span>
                            <span class="metrics-rank-stat-label">comments</span>
                        </span>
                    </li>
                `;
            }).join('');
        } catch (err) {
            el.innerHTML = '<li class="metrics-rank-empty">Couldn’t load top commenters.</li>';
        }
    }

    // ============================================================
    // USERS PANEL
    // ============================================================

    const usersState = {
        page: 1,
        perPage: 25,
        role: 'all',
        search: '',
        loaded: false,
        searchDebounce: null,
        inflight: null,         // AbortController for the current request
    };

    async function loadUsers() {
        if (!usersState._listenersBound) {
            const searchInput = document.getElementById('usersSearchInput');
            const roleSelect = document.getElementById('usersRoleFilter');
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    clearTimeout(usersState.searchDebounce);
                    usersState.searchDebounce = setTimeout(() => {
                        usersState.search = searchInput.value.trim();
                        usersState.page = 1;
                        fetchUsers();
                    }, 250);
                });
            }
            if (roleSelect) {
                roleSelect.addEventListener('change', () => {
                    usersState.role = roleSelect.value;
                    usersState.page = 1;
                    fetchUsers();
                });
            }
            usersState._listenersBound = true;
        }
        await fetchUsers();
        usersState.loaded = true;
    }

    async function fetchUsers() {
        const tbody = document.getElementById('usersTableBody');
        if (!tbody) return;
        // Cancel any in-flight users request so a stale (older) response
        // can't overwrite a newer one. Common when the user is still typing.
        if (usersState.inflight) usersState.inflight.abort();
        const ctrl = new AbortController();
        usersState.inflight = ctrl;
        tbody.innerHTML = '<tr><td colspan="7" class="admin-table-empty">Loading…</td></tr>';
        try {
            const res = await apiGet('users', {
                page: usersState.page,
                per_page: usersState.perPage,
                role: usersState.role,
                search: usersState.search,
            }, { signal: ctrl.signal });
            if (ctrl.signal.aborted) return;
            const users = res.users || [];
            const pg = res.pagination || { page: 1, pages: 1, total: 0 };
            if (users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="admin-table-empty">No users match this filter.</td></tr>';
            } else {
                tbody.innerHTML = users.map(u => renderUserRow(u)).join('');
                tbody.querySelectorAll('[data-role-change]').forEach(sel => {
                    sel.addEventListener('change', () => {
                        const userId = parseInt(sel.dataset.userId, 10);
                        const prev = sel.dataset.prevRole;
                        const next = sel.value;
                        if (prev === next) return;
                        if (!window.confirm(`Change role to "${next}"?`)) {
                            sel.value = prev;
                            return;
                        }
                        apiPost('set-role', { user_id: userId, role: next })
                            .then(() => {
                                sel.dataset.prevRole = next;
                                toast('Role updated', 'success');
                            })
                            .catch(err => {
                                sel.value = prev;
                                toast(err.message || 'Could not update role', 'error');
                            });
                    });
                });
            }
            renderPagination('usersPagination', pg, (p) => { usersState.page = p; fetchUsers(); });
        } catch (err) {
            if (err.name === 'AbortError' || ctrl.signal.aborted) return;
            tbody.innerHTML = `<tr><td colspan="7" class="admin-table-empty">Couldn’t load users: ${escapeHtml(err.message || '')}</td></tr>`;
        } finally {
            if (usersState.inflight === ctrl) usersState.inflight = null;
        }
    }

    function renderUserRow(u) {
        const name = u.display_name || u.username;
        const verified = u.email_verified_at
            ? '<span class="user-verified-dot" title="Email verified"></span>'
            : '';
        const roleOptions = ['admin', 'editor', 'viewer'].map(r =>
            `<option value="${r}" ${u.role === r ? 'selected' : ''}>${r}</option>`
        ).join('');
        return `
            <tr>
                <td>
                    <div class="user-cell">
                        <div class="user-cell-avatar">${escapeHtml(initial(name))}</div>
                        <div class="user-cell-text">
                            <div class="user-cell-name">${escapeHtml(name)} ${verified}</div>
                            <div class="user-cell-sub">@${escapeHtml(u.username)} · ${escapeHtml(u.email || 'no email')}</div>
                        </div>
                    </div>
                </td>
                <td>
                    <select class="form-input form-input--inline" data-role-change data-user-id="${escapeHtml(u.id)}" data-prev-role="${escapeHtml(u.role)}">
                        ${roleOptions}
                    </select>
                </td>
                <td>${escapeHtml(fmtDate(u.created_at))}</td>
                <td>${escapeHtml(relTime(u.last_seen))}</td>
                <td class="num">${formatCount(u.comment_count)}</td>
                <td class="num">${formatCount(u.bookmark_count)}</td>
                <td class="num">${formatCount(u.watch_count)}</td>
            </tr>
        `;
    }

    function renderPagination(containerId, pg, onPage) {
        const el = document.getElementById(containerId);
        if (!el) return;
        if (!pg.pages || pg.pages <= 1) {
            el.innerHTML = '';
            return;
        }
        const total = pg.total;
        const start = ((pg.page - 1) * pg.per_page) + 1;
        const end = Math.min(total, pg.page * pg.per_page);
        el.innerHTML = `
            <div class="admin-pagination-text">Showing ${start}–${end} of ${formatCount(total)}</div>
            <div class="admin-pagination-buttons">
                <button type="button" class="btn btn-secondary btn-sm" ${pg.page <= 1 ? 'disabled' : ''} data-page="${pg.page - 1}">Previous</button>
                <span class="admin-pagination-page">Page ${pg.page} of ${pg.pages}</span>
                <button type="button" class="btn btn-secondary btn-sm" ${pg.page >= pg.pages ? 'disabled' : ''} data-page="${pg.page + 1}">Next</button>
            </div>
        `;
        el.querySelectorAll('[data-page]').forEach(btn => {
            btn.addEventListener('click', () => {
                const p = parseInt(btn.dataset.page, 10);
                if (p > 0) onPage(p);
            });
        });
    }

    // ============================================================
    // COMMENTS MODERATION PANEL
    // ============================================================

    const modState = {
        page: 1,
        perPage: 25,
        filter: 'all',
        loaded: false,
    };

    async function loadCommentsMod() {
        if (!modState._listenersBound) {
            document.querySelectorAll('#commentsModFilters [data-filter]').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('#commentsModFilters [data-filter]').forEach(b => {
                        b.classList.toggle('active', b === btn);
                        b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
                    });
                    modState.filter = btn.dataset.filter;
                    modState.page = 1;
                    fetchModerationList();
                });
            });
            modState._listenersBound = true;
        }
        await fetchModerationList();
        modState.loaded = true;
    }

    async function fetchModerationList() {
        const el = document.getElementById('commentsModList');
        if (!el) return;
        el.innerHTML = '<div class="admin-table-empty" style="padding:40px;">Loading…</div>';
        try {
            const res = await apiGet('comments-mod', {
                page: modState.page,
                per_page: modState.perPage,
                filter: modState.filter,
            });
            if (res.unavailable) {
                el.innerHTML = '<div class="admin-table-empty" style="padding:40px;">Comments tables not yet created. Run the migration first (install.php step 2).</div>';
                renderPagination('commentsModPagination', { pages: 0 }, () => {});
                return;
            }
            const comments = res.comments || [];
            const pg = res.pagination || { page: 1, pages: 1, total: 0 };
            if (comments.length === 0) {
                el.innerHTML = `<div class="admin-table-empty" style="padding:40px;">No comments match this filter.</div>`;
            } else {
                el.innerHTML = comments.map(c => renderModerationCard(c)).join('');
                el.querySelectorAll('[data-mod-action]').forEach(btn => {
                    btn.addEventListener('click', () => handleModerationAction(btn));
                });
            }
            renderPagination('commentsModPagination', pg, (p) => { modState.page = p; fetchModerationList(); });
        } catch (err) {
            el.innerHTML = `<div class="admin-table-empty" style="padding:40px;">Couldn’t load comments: ${escapeHtml(err.message || '')}</div>`;
        }
    }

    function renderModerationCard(c) {
        const name = c.display_name || c.username;
        const reportCount = Number(c.report_count) || 0;
        const statusBadge = c.status === 'hidden'
            ? '<span class="badge badge-warning">Hidden</span>'
            : (c.status === 'deleted' ? '<span class="badge">Deleted</span>' : '');
        const reportBadge = reportCount > 0
            ? `<span class="badge badge-danger">${reportCount} report${reportCount !== 1 ? 's' : ''}</span>`
            : '';
        const isReply = c.parent_id !== null;
        return `
            <article class="mod-comment" data-mod-id="${escapeHtml(c.id)}" data-status="${escapeHtml(c.status)}">
                <div class="mod-comment-avatar">${escapeHtml(initial(name))}</div>
                <div class="mod-comment-main">
                    <header class="mod-comment-head">
                        <span class="mod-comment-author">${escapeHtml(name)}</span>
                        <span class="mod-comment-handle">@${escapeHtml(c.username)}</span>
                        ${isReply ? '<span class="badge badge-secondary">Reply</span>' : ''}
                        ${statusBadge}
                        ${reportBadge}
                        <span class="mod-comment-time">${escapeHtml(relTime(c.created_at))}</span>
                    </header>
                    <div class="mod-comment-body">${escapeHtml(c.body || '')}</div>
                    <footer class="mod-comment-foot">
                        <a class="mod-comment-link" href="player.php?video=${encodeURIComponent(c.archive_id)}" target="_blank" rel="noopener noreferrer">
                            View on ${escapeHtml(c.archive_id)}
                        </a>
                        <div class="mod-comment-actions">
                            ${c.status === 'hidden'
                                ? `<button type="button" class="btn btn-secondary btn-sm" data-mod-action="restore">Restore</button>`
                                : `<button type="button" class="btn btn-secondary btn-sm" data-mod-action="hide">Hide</button>`
                            }
                            <button type="button" class="btn btn-danger btn-sm" data-mod-action="delete">Delete</button>
                            ${reportCount > 0
                                ? `<button type="button" class="btn btn-ghost btn-sm" data-mod-action="resolve-reports">Dismiss reports</button>`
                                : ''
                            }
                        </div>
                    </footer>
                </div>
            </article>
        `;
    }

    async function handleModerationAction(btn) {
        const card = btn.closest('[data-mod-id]');
        if (!card) return;
        const id = parseInt(card.dataset.modId, 10);
        const action = btn.dataset.modAction;

        if (action === 'delete' && !window.confirm('Delete this comment? This is reversible only by direct DB edit.')) {
            return;
        }
        btn.disabled = true;
        try {
            if (action === 'resolve-reports') {
                await apiPost('resolve-reports', { id });
                toast('Reports dismissed', 'success');
            } else {
                await apiPost('moderate', { id, op: action });
                toast(`Comment ${action === 'delete' ? 'deleted' : action + 'd'}`, 'success');
            }
            // Invalidate the dashboard cache so next visit re-pulls stats.
            lastDashboardLoad = 0;
            await fetchModerationList();
            // Refresh sidebar badges so the reports counter reflects reality.
            loadDashboardBadgesOnly();
        } catch (err) {
            toast(err.message || 'Action failed', 'error');
        } finally {
            // The button has been removed by fetchModerationList re-render
            // on success; this guards the failure path.
            if (document.body.contains(btn)) btn.disabled = false;
        }
    }

    async function loadDashboardBadgesOnly() {
        try {
            const overview = await apiGet('overview');
            const reports = Number(overview.engagement?.reports_pending) || 0;
            const badge = document.getElementById('navReportsCount');
            if (badge) {
                badge.textContent = formatCount(reports);
                badge.style.display = reports > 0 ? '' : 'none';
            }
            const repBadge = document.getElementById('reportedBadge');
            if (repBadge) {
                repBadge.textContent = formatCount(reports);
                repBadge.style.display = reports > 0 ? '' : 'none';
            }
        } catch (_) { /* ignore */ }
    }

    // ============================================================
    // PANEL LIFECYCLE
    // ============================================================

    // Re-fetching the dashboard every time the user returns to it would
    // hammer the DB; throttle so we refresh at most once per minute unless
    // a moderation action explicitly forces a badge refresh.
    let lastDashboardLoad = 0;
    const DASHBOARD_TTL_MS = 60_000;

    // Redraw the chart at its current pixel size after a resize (debounced)
    // and when the metrics panel is shown again (it may have been resized
    // while hidden, when clientWidth was 0).
    function redrawChart() {
        const svg = document.getElementById('metricsChart');
        if (svg && metrics.lastSeries && svg.clientWidth) renderLineChart(svg, metrics.lastSeries);
    }
    let chartResizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(chartResizeTimer);
        chartResizeTimer = setTimeout(redrawChart, 150);
    });

    document.addEventListener('admin:panel-shown', (e) => {
        const panel = e.detail;
        if (panel === 'metrics' && !metrics.loaded) loadMetrics();
        else if (panel === 'metrics') requestAnimationFrame(redrawChart);
        if (panel === 'users' && !usersState.loaded) loadUsers();
        if (panel === 'comments-mod' && !modState.loaded) loadCommentsMod();
        if (panel === 'dashboard') {
            // Re-pull stats if they've gone stale -- otherwise cards show
            // pre-moderation counts after returning from the mod panel.
            if (Date.now() - lastDashboardLoad > DASHBOARD_TTL_MS) {
                lastDashboardLoad = Date.now();
                loadDashboard();
            }
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        // Always populate dashboard (it's the default active panel).
        lastDashboardLoad = Date.now();
        loadDashboard();
    });
})();
