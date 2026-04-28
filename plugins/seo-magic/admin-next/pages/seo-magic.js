const TAG = window.__GRAV_PAGE_TAG;

class SeoMagicDashboard extends HTMLElement {
    // ─── State ───────────────────────────────────────────────────────
    _data = null;       // full dashboard response
    _loading = true;
    _error = null;
    _pollTimer = null;
    _dropdownOpen = false;

    // Query state
    _page = 1;
    _perPage = 25;
    _sort = 'updated';
    _dir = 'desc';
    _query = '';
    _lang = '';
    _mode = 'all';

    // ─── Lifecycle ───────────────────────────────────────────────────
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this._onClickOutside = this._onClickOutside.bind(this);
    }

    connectedCallback() {
        document.addEventListener('click', this._onClickOutside);
        this._onPageAction = (e) => this._handlePageAction(e.detail);
        this.addEventListener('page-action', this._onPageAction);
        this._fetchDashboard();
    }

    disconnectedCallback() {
        this._stopPolling();
        document.removeEventListener('click', this._onClickOutside);
        this.removeEventListener('page-action', this._onPageAction);
    }

    _handlePageAction(detail) {
        switch (detail?.id) {
            case 'recrawl':
                this._startCrawl(false);
                break;
            case 'recrawl-changed':
                this._startCrawl(true);
                break;
            case 'export-csv':
                this._exportCsv();
                break;
        }
    }

    // ─── API helpers ─────────────────────────────────────────────────
    get _baseUrl() {
        return window.__GRAV_API_SERVER_URL + (window.__GRAV_API_PREFIX || '/api/v1');
    }

    get _headers() {
        const h = { 'Content-Type': 'application/json' };
        const token = window.__GRAV_API_TOKEN;
        if (token) h['X-API-Token'] = token;
        return h;
    }

    async _apiFetch(path, opts = {}) {
        const res = await fetch(this._baseUrl + path, { headers: this._headers, ...opts });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res;
    }

    async _apiJson(path, opts) {
        const res = await this._apiFetch(path, opts);
        return res.json();
    }

    // ─── Data fetching ───────────────────────────────────────────────
    async _fetchDashboard() {
        this._loading = true;
        this._error = null;
        this._render();

        try {
            const params = new URLSearchParams({
                page: this._page,
                per_page: this._perPage,
                sort: this._sort,
                dir: this._dir,
                q: this._query,
                lang: this._lang,
                mode: this._mode,
            });
            const json = await this._apiJson(`/seo-magic/dashboard?${params}`);
            this._data = json.data;

            // If a crawl is running, start polling
            if (this._data?.status?.running) {
                this._startPolling();
            } else {
                this._stopPolling();
            }
        } catch (e) {
            this._error = e.message;
        }

        this._loading = false;
        this._render();
    }

    // ─── Polling ─────────────────────────────────────────────────────
    _startPolling() {
        if (this._pollTimer) return;
        this._pollTimer = setInterval(() => this._pollStatus(), 1200);
    }

    _stopPolling() {
        if (this._pollTimer) {
            clearInterval(this._pollTimer);
            this._pollTimer = null;
        }
    }

    async _pollStatus() {
        try {
            const json = await this._apiJson('/seo-magic/status');
            const status = json.data;
            if (this._data) this._data.status = status;

            if (!status?.running) {
                this._stopPolling();
                // Crawl finished, refresh full data
                await this._fetchDashboard();
                return;
            }
            this._render();
        } catch (_) {
            // Silently ignore polling errors
        }
    }

    // ─── Actions ─────────────────────────────────────────────────────
    async _startCrawl(changedOnly = false) {
        try {
            const endpoint = changedOnly ? '/seo-magic/crawl-changed' : '/seo-magic/crawl';
            await this._apiJson(endpoint, { method: 'POST' });
            this._dropdownOpen = false;
            // Start polling immediately
            if (this._data) {
                this._data.status = { running: true, processed: 0, total: 0, mode: changedOnly ? 'changed' : 'full' };
            }
            this._startPolling();
            this._render();
        } catch (e) {
            this._error = `Crawl failed: ${e.message}`;
            this._render();
        }
    }

    async _cancelCrawl() {
        try {
            await this._apiJson('/seo-magic/cancel', { method: 'POST' });
            this._stopPolling();
            if (this._data?.status) this._data.status.running = false;
            this._render();
            // Refresh after short delay
            setTimeout(() => this._fetchDashboard(), 500);
        } catch (_) {}
    }

    async _exportCsv() {
        try {
            const res = await this._apiFetch('/seo-magic/export/csv');
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'seo-magic-report.csv';
            a.click();
            URL.revokeObjectURL(url);
        } catch (e) {
            this._error = `Export failed: ${e.message}`;
            this._render();
        }
    }

    // ─── Filter / Pagination ─────────────────────────────────────────
    _onSearch(value) {
        this._query = value;
        this._page = 1;
        this._fetchDashboard();
    }

    _onModeChange(checked) {
        this._mode = checked ? 'broken' : 'all';
        this._page = 1;
        this._fetchDashboard();
    }

    _onLangChange(value) {
        this._lang = value;
        this._page = 1;
        this._fetchDashboard();
    }

    _onPerPageChange(value) {
        this._perPage = parseInt(value, 10);
        this._page = 1;
        this._fetchDashboard();
    }

    _onSort(column) {
        if (this._sort === column) {
            this._dir = this._dir === 'asc' ? 'desc' : 'asc';
        } else {
            this._sort = column;
            this._dir = column === 'score' ? 'desc' : 'asc';
        }
        this._page = 1;
        this._fetchDashboard();
    }

    _onPage(p) {
        this._page = p;
        this._fetchDashboard();
    }

    // ─── Click outside for dropdown ──────────────────────────────────
    _onClickOutside(e) {
        if (this._dropdownOpen) {
            const dropdown = this.shadowRoot.querySelector('.dropdown-menu');
            const trigger = this.shadowRoot.querySelector('.dropdown-trigger');
            if (dropdown && !dropdown.contains(e.composedPath()[0]) && trigger && !trigger.contains(e.composedPath()[0])) {
                this._dropdownOpen = false;
                this._render();
            }
        }
    }

    // ─── SVG Icons ───────────────────────────────────────────────────
    _icon(name, size = 18) {
        const s = `width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"`;
        const icons = {
            'file-text': `<svg ${s}><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>`,
            'trending-up': `<svg ${s}><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>`,
            'alert-triangle': `<svg ${s}><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>`,
            'link': `<svg ${s}><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>`,
            'image': `<svg ${s}><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>`,
            'refresh-cw': `<svg ${s}><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>`,
            'download': `<svg ${s}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>`,
            'chevron-down': `<svg ${s}><path d="m6 9 6 6 6-6"/></svg>`,
            'chevron-left': `<svg ${s}><path d="m15 18-6-6 6-6"/></svg>`,
            'chevron-right': `<svg ${s}><path d="m9 18 6-6-6-6"/></svg>`,
            'arrow-up': `<svg ${s}><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></svg>`,
            'arrow-down': `<svg ${s}><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>`,
            'search': `<svg ${s}><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>`,
            'x': `<svg ${s}><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>`,
            'spinner': `<svg ${s} style="animation: seo-spin 1s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>`,
            'inbox': `<svg ${s}><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>`,
        };
        return icons[name] || '';
    }

    // ─── Render ──────────────────────────────────────────────────────
    _render() {
        const root = this.shadowRoot;

        // Loading state
        if (this._loading && !this._data) {
            root.innerHTML = `
                ${this._styles()}
                <div class="loading-state">
                    ${this._icon('spinner', 24)}
                    <span>Loading SEO dashboard...</span>
                </div>
            `;
            return;
        }

        // Error state (no data at all)
        if (this._error && !this._data) {
            root.innerHTML = `
                ${this._styles()}
                <div class="error-state">
                    ${this._icon('alert-triangle', 32)}
                    <p>Failed to load dashboard</p>
                    <span class="error-detail">${this._escHtml(this._error)}</span>
                    <button class="btn btn-primary" id="retry-btn">Try Again</button>
                </div>
            `;
            root.querySelector('#retry-btn')?.addEventListener('click', () => this._fetchDashboard());
            return;
        }

        const { summary, listing, languages, status } = this._data;
        const rows = listing?.rows || [];
        const total = listing?.total || 0;
        const totalPages = listing?.pages || 1;
        const isRunning = status?.running === true;
        const isEmpty = total === 0 && !this._query && this._mode === 'all' && !this._lang;

        root.innerHTML = `
            ${this._styles()}
            <div class="dashboard">
                ${this._error ? `<div class="inline-error">${this._icon('alert-triangle', 14)} ${this._escHtml(this._error)}</div>` : ''}
                ${isRunning ? this._renderProgressBar(status) : ''}
                ${this._renderStatsCards(summary)}
                ${isEmpty ? this._renderEmptyState() : `
                    ${this._renderFilterBar(languages)}
                    ${this._renderTable(rows)}
                    ${total > 0 ? this._renderPagination(total, totalPages) : ''}
                `}
            </div>
        `;

        this._attachEvents();
    }

    // ─── Header ──────────────────────────────────────────────────────
    _renderHeader(isRunning) {
        return `
            <div class="header">
                <div class="header-actions">
                    <div class="dropdown">
                        <div class="btn-group">
                            <button class="btn btn-primary" id="crawl-btn" ${isRunning ? 'disabled' : ''}>
                                ${this._icon('refresh-cw', 14)}
                                Re-crawl
                            </button>
                            <button class="btn btn-primary dropdown-trigger" id="dropdown-toggle" ${isRunning ? 'disabled' : ''}>
                                ${this._icon('chevron-down', 14)}
                            </button>
                        </div>
                        ${this._dropdownOpen ? `
                            <div class="dropdown-menu">
                                <button class="dropdown-item" id="crawl-changed-btn">
                                    ${this._icon('refresh-cw', 14)}
                                    Re-crawl (changed only)
                                </button>
                            </div>
                        ` : ''}
                    </div>
                    <button class="btn btn-secondary" id="export-btn">
                        ${this._icon('download', 14)}
                        Export CSV
                    </button>
                </div>
            </div>
        `;
    }

    // ─── Progress bar ────────────────────────────────────────────────
    _renderProgressBar(status) {
        const processed = status.processed || 0;
        const total = status.total || 0;
        const pct = total > 0 ? Math.round((processed / total) * 100) : 0;
        const modeLabel = status.mode === 'changed' ? 'changed ' : '';

        return `
            <div class="progress-section">
                <div class="progress-info">
                    <span class="progress-text">
                        ${this._icon('spinner', 14)}
                        Processing ${processed} of ${total} ${modeLabel}pages...
                    </span>
                    <button class="btn btn-ghost btn-sm" id="cancel-btn">
                        ${this._icon('x', 14)}
                        Cancel
                    </button>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" style="width: ${pct}%"></div>
                </div>
            </div>
        `;
    }

    // ─── Stats cards ─────────────────────────────────────────────────
    _renderStatsCards(summary) {
        if (!summary) return '';

        const cards = [
            { icon: 'file-text', value: summary.pages ?? 0, label: 'Total crawled', title: 'Pages' },
            { icon: 'trending-up', value: `${summary.avg ?? 0}%`, label: 'Across all pages', title: 'Avg Score' },
            { icon: 'alert-triangle', value: summary.issues_pages ?? 0, label: 'Broken links or images', title: 'Pages with Issues' },
            { icon: 'link', value: summary.broken_links ?? 0, label: 'Links failing', title: 'Broken Links' },
            { icon: 'image', value: summary.broken_images ?? 0, label: 'Missing or failing', title: 'Broken Images' },
        ];

        return `
            <div class="stats-grid">
                ${cards.map(c => `
                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-icon">${this._icon(c.icon, 16)}</span>
                            <span class="stat-title">${c.title}</span>
                        </div>
                        <div class="stat-value">${c.value}</div>
                        <div class="stat-label">${c.label}</div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    // ─── Empty state ─────────────────────────────────────────────────
    _renderEmptyState() {
        return `
            <div class="empty-state">
                ${this._icon('inbox', 40)}
                <p class="empty-title">No SEO data</p>
                <p class="empty-desc">Run a crawl to analyze your pages and get SEO scores, broken link reports, and more.</p>
                <button class="btn btn-primary" id="empty-crawl-btn">
                    ${this._icon('refresh-cw', 14)}
                    Start Crawl
                </button>
            </div>
        `;
    }

    // ─── Filter bar ──────────────────────────────────────────────────
    _renderFilterBar(languages) {
        const langOptions = (languages || []).map(l =>
            `<option value="${this._escAttr(l)}" ${l === this._lang ? 'selected' : ''}>${this._escHtml(l)}</option>`
        ).join('');

        return `
            <div class="filter-bar">
                <div class="filter-search">
                    <span class="search-icon">${this._icon('search', 14)}</span>
                    <input type="text" class="filter-input" id="search-input"
                           placeholder="Filter by title or URL"
                           value="${this._escAttr(this._query)}" />
                </div>
                <label class="filter-checkbox">
                    <input type="checkbox" id="broken-only-cb" ${this._mode === 'broken' ? 'checked' : ''} />
                    <span>Only broken</span>
                </label>
                <select class="filter-select" id="lang-select">
                    <option value="">All languages</option>
                    ${langOptions}
                </select>
                <select class="filter-select" id="perpage-select">
                    ${[10, 25, 50, 100].map(n =>
                        `<option value="${n}" ${n === this._perPage ? 'selected' : ''}>${n} per page</option>`
                    ).join('')}
                </select>
            </div>
        `;
    }

    // ─── Data table ──────────────────────────────────────────────────
    _renderTable(rows) {
        if (rows.length === 0) {
            return `
                <div class="no-results">
                    <p>No pages match the current filters.</p>
                </div>
            `;
        }

        const sortArrow = (col) => {
            if (this._sort !== col) return '';
            return this._dir === 'asc' ? this._icon('arrow-up', 12) : this._icon('arrow-down', 12);
        };

        const sortClass = (col) => this._sort === col ? 'th-sorted' : '';

        return `
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="th-sortable ${sortClass('score')}" data-sort="score">
                                <span>Score ${sortArrow('score')}</span>
                            </th>
                            <th class="th-sortable ${sortClass('lang')}" data-sort="lang">
                                <span>Lang ${sortArrow('lang')}</span>
                            </th>
                            <th class="th-sortable ${sortClass('title')}" data-sort="title">
                                <span>Page ${sortArrow('title')}</span>
                            </th>
                            <th class="th-sortable ${sortClass('total_links')}" data-sort="total_links">
                                <span>Links ${sortArrow('total_links')}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows.map(row => this._renderRow(row)).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    _renderRow(row) {
        const score = row.score?.score ?? 0;
        const scoreColor = score >= 80 ? '#22c55e' : score >= 50 ? '#eab308' : '#ef4444';
        const scoreBg = score >= 80 ? 'rgba(34,197,94,0.12)' : score >= 50 ? 'rgba(234,179,8,0.12)' : 'rgba(239,68,68,0.12)';
        const title = row.title || row.route || '(untitled)';
        const route = row.rawroute || row.route || '';
        const lang = row.lang || '';
        const totalLinks = row.total_links ?? 0;

        return `
            <tr class="data-row">
                <td class="td-score">
                    <span class="score-badge" style="color: ${scoreColor}; background: ${scoreBg};">
                        ${score}%
                    </span>
                </td>
                <td class="td-lang">
                    ${lang ? `<span class="lang-tag">${this._escHtml(lang)}</span>` : '<span class="text-muted">--</span>'}
                </td>
                <td class="td-page">
                    <a class="page-title page-link" href="${this._pageEditUrl(route, lang)}">${this._escHtml(title)}</a>
                    <div class="page-route">${this._escHtml(route)}</div>
                </td>
                <td class="td-links">
                    <span class="links-text">Found ${totalLinks} link${totalLinks !== 1 ? 's' : ''}.</span>
                </td>
            </tr>
        `;
    }

    // ─── Pagination ──────────────────────────────────────────────────
    _renderPagination(total, totalPages) {
        const start = ((this._page - 1) * this._perPage) + 1;
        const end = Math.min(this._page * this._perPage, total);

        // Build page number buttons (show up to 7 centered around current)
        const pages = [];
        const maxButtons = 7;
        let startPage = Math.max(1, this._page - Math.floor(maxButtons / 2));
        let endPage = Math.min(totalPages, startPage + maxButtons - 1);
        if (endPage - startPage < maxButtons - 1) {
            startPage = Math.max(1, endPage - maxButtons + 1);
        }

        for (let i = startPage; i <= endPage; i++) {
            pages.push(i);
        }

        return `
            <div class="pagination">
                <span class="pagination-info">Showing ${start}-${end} of ${total}</span>
                <div class="pagination-buttons">
                    <button class="btn btn-ghost btn-sm page-btn" data-page="${this._page - 1}" ${this._page <= 1 ? 'disabled' : ''}>
                        ${this._icon('chevron-left', 14)}
                    </button>
                    ${pages.map(p => `
                        <button class="btn btn-sm page-btn ${p === this._page ? 'btn-page-active' : 'btn-ghost'}" data-page="${p}">
                            ${p}
                        </button>
                    `).join('')}
                    <button class="btn btn-ghost btn-sm page-btn" data-page="${this._page + 1}" ${this._page >= totalPages ? 'disabled' : ''}>
                        ${this._icon('chevron-right', 14)}
                    </button>
                </div>
            </div>
        `;
    }

    // ─── Event binding ───────────────────────────────────────────────
    _attachEvents() {
        const root = this.shadowRoot;

        // Header actions
        root.querySelector('#crawl-btn')?.addEventListener('click', () => this._startCrawl(false));
        root.querySelector('#dropdown-toggle')?.addEventListener('click', (e) => {
            e.stopPropagation();
            this._dropdownOpen = !this._dropdownOpen;
            this._render();
        });
        root.querySelector('#crawl-changed-btn')?.addEventListener('click', () => this._startCrawl(true));
        root.querySelector('#export-btn')?.addEventListener('click', () => this._exportCsv());
        root.querySelector('#cancel-btn')?.addEventListener('click', () => this._cancelCrawl());
        root.querySelector('#empty-crawl-btn')?.addEventListener('click', () => this._startCrawl(false));
        root.querySelector('#retry-btn')?.addEventListener('click', () => this._fetchDashboard());

        // Filters
        let searchTimeout;
        root.querySelector('#search-input')?.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => this._onSearch(e.target.value), 300);
        });
        root.querySelector('#broken-only-cb')?.addEventListener('change', (e) => this._onModeChange(e.target.checked));
        root.querySelector('#lang-select')?.addEventListener('change', (e) => this._onLangChange(e.target.value));
        root.querySelector('#perpage-select')?.addEventListener('change', (e) => this._onPerPageChange(e.target.value));

        // Sort headers
        root.querySelectorAll('.th-sortable').forEach(th => {
            th.addEventListener('click', () => this._onSort(th.dataset.sort));
        });

        // Pagination
        root.querySelectorAll('.page-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const p = parseInt(btn.dataset.page, 10);
                if (p >= 1) this._onPage(p);
            });
        });
    }

    // ─── Utilities ───────────────────────────────────────────────────
    _escHtml(str) {
        const el = document.createElement('span');
        el.textContent = str ?? '';
        return el.innerHTML;
    }

    _escAttr(str) {
        return (str ?? '').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    _pageEditUrl(route, lang) {
        // Route from API is like /en/about — strip language prefix to get page route
        let pageRoute = route || '';
        if (lang && pageRoute.startsWith('/' + lang + '/')) {
            pageRoute = pageRoute.substring(lang.length + 1);
        } else if (lang && pageRoute === '/' + lang) {
            pageRoute = '/';
        }
        return `/pages/edit${pageRoute}#--seomagic_tab--tab_3`;
    }

    // ─── Styles ──────────────────────────────────────────────────────
    _styles() {
        return `<style>
            :host {
                display: block;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                color: var(--foreground, #18181b);
                line-height: 1.5;
                -webkit-font-smoothing: antialiased;
            }

            *, *::before, *::after {
                box-sizing: border-box;
            }

            @keyframes seo-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }

            /* ─── Dashboard layout ────────────────────────── */
            .dashboard {
                display: flex;
                flex-direction: column;
                gap: 16px;
                padding: 0;
            }

            /* ─── Loading / Error / Empty states ──────────── */
            .loading-state {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                padding: 80px 20px;
                color: var(--muted-foreground, #71717a);
                font-size: 14px;
            }

            .error-state {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 10px;
                padding: 80px 20px;
                color: var(--muted-foreground, #71717a);
                text-align: center;
            }

            .error-state p {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--foreground, #18181b);
            }

            .error-detail {
                font-size: 13px;
                color: var(--destructive, #ef4444);
            }

            .inline-error {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 8px 12px;
                border-radius: 8px;
                background: color-mix(in srgb, var(--destructive, #ef4444) 10%, transparent);
                color: var(--destructive, #ef4444);
                font-size: 13px;
            }

            .empty-state {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 12px;
                padding: 60px 20px;
                color: var(--muted-foreground, #71717a);
                text-align: center;
            }

            .empty-title {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--foreground, #18181b);
            }

            .empty-desc {
                margin: 0;
                max-width: 380px;
                font-size: 13px;
                line-height: 1.5;
            }

            .no-results {
                text-align: center;
                padding: 40px 20px;
                color: var(--muted-foreground, #71717a);
                font-size: 14px;
            }

            .no-results p { margin: 0; }

            /* ─── Header ──────────────────────────────────── */
            .header {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 12px;
            }

            .header-actions {
                display: flex;
                align-items: center;
                gap: 8px;
            }

            /* ─── Buttons ─────────────────────────────────── */
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                border: none;
                border-radius: 8px;
                font-family: inherit;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                transition: opacity 0.15s ease, background 0.15s ease;
                white-space: nowrap;
                line-height: 1;
            }

            .btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            .btn:not(:disabled):hover {
                opacity: 0.85;
            }

            .btn-primary {
                background: var(--primary, #3b82f6);
                color: var(--primary-foreground, #fff);
                padding: 8px 14px;
            }

            .btn-secondary {
                background: var(--secondary, #f4f4f5);
                color: var(--secondary-foreground, #18181b);
                padding: 8px 14px;
                border: 1px solid var(--border, #e4e4e7);
            }

            .btn-ghost {
                background: transparent;
                color: var(--foreground, #18181b);
                padding: 6px 8px;
            }

            .btn-ghost:not(:disabled):hover {
                background: var(--accent, #f4f4f5);
                opacity: 1;
            }

            .btn-sm {
                padding: 5px 8px;
                font-size: 12px;
                border-radius: 6px;
            }

            .btn-page-active {
                background: var(--primary, #3b82f6);
                color: var(--primary-foreground, #fff);
                padding: 5px 8px;
                font-size: 12px;
                border-radius: 6px;
            }

            /* ─── Button group for split dropdown ─────────── */
            .btn-group {
                display: inline-flex;
                align-items: stretch;
            }

            .btn-group .btn:first-child {
                border-top-right-radius: 0;
                border-bottom-right-radius: 0;
                border-right: 1px solid rgba(255,255,255,0.2);
            }

            .btn-group .btn:last-child {
                border-top-left-radius: 0;
                border-bottom-left-radius: 0;
                padding: 8px 6px;
            }

            /* ─── Dropdown ────────────────────────────────── */
            .dropdown {
                position: relative;
            }

            .dropdown-menu {
                position: absolute;
                top: calc(100% + 4px);
                right: 0;
                z-index: 50;
                min-width: 200px;
                background: var(--popover, var(--card, #fff));
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 8px;
                padding: 4px;
                box-shadow: 0 4px 16px rgba(0,0,0,0.10), 0 1px 4px rgba(0,0,0,0.06);
            }

            .dropdown-item {
                display: flex;
                align-items: center;
                gap: 8px;
                width: 100%;
                padding: 8px 10px;
                border: none;
                border-radius: 6px;
                background: none;
                color: var(--foreground, #18181b);
                font-family: inherit;
                font-size: 13px;
                cursor: pointer;
                text-align: left;
            }

            .dropdown-item:hover {
                background: var(--accent, #f4f4f5);
            }

            /* ─── Progress bar ────────────────────────────── */
            .progress-section {
                display: flex;
                flex-direction: column;
                gap: 8px;
                padding: 12px 16px;
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 10px;
                background: var(--card, #fff);
            }

            .progress-info {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }

            .progress-text {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 13px;
                color: var(--foreground, #18181b);
                font-weight: 500;
            }

            .progress-track {
                height: 6px;
                border-radius: 3px;
                background: var(--muted, #f4f4f5);
                overflow: hidden;
            }

            .progress-fill {
                height: 100%;
                border-radius: 3px;
                background: var(--primary, #3b82f6);
                transition: width 0.4s ease;
            }

            /* ─── Stats cards ─────────────────────────────── */
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 12px;
            }

            @media (max-width: 900px) {
                .stats-grid {
                    grid-template-columns: repeat(3, 1fr);
                }
            }

            @media (max-width: 600px) {
                .stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }

            .stat-card {
                display: flex;
                flex-direction: column;
                gap: 4px;
                padding: 16px 20px;
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 12px;
                background: var(--card, #fff);
            }

            .stat-header {
                display: flex;
                align-items: center;
                gap: 6px;
                margin-bottom: 4px;
            }

            .stat-icon {
                display: flex;
                color: var(--muted-foreground, #71717a);
            }

            .stat-title {
                font-size: 12px;
                font-weight: 500;
                color: var(--muted-foreground, #71717a);
                text-transform: uppercase;
                letter-spacing: 0.03em;
            }

            .stat-value {
                font-size: 28px;
                font-weight: 700;
                line-height: 1.1;
                color: var(--foreground, #18181b);
                letter-spacing: -0.02em;
            }

            .stat-label {
                font-size: 12px;
                color: var(--muted-foreground, #71717a);
            }

            /* ─── Filter bar ──────────────────────────────── */
            .filter-bar {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .filter-search {
                position: relative;
                flex: 1;
                min-width: 180px;
            }

            .search-icon {
                position: absolute;
                left: 10px;
                top: 50%;
                transform: translateY(-50%);
                color: var(--muted-foreground, #71717a);
                display: flex;
                pointer-events: none;
            }

            .filter-input {
                width: 100%;
                height: 36px;
                padding: 0 12px 0 32px;
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 8px;
                background: var(--background, #fff);
                color: var(--foreground, #18181b);
                font-family: inherit;
                font-size: 13px;
                outline: none;
                transition: border-color 0.15s ease, box-shadow 0.15s ease;
            }

            .filter-input::placeholder {
                color: var(--muted-foreground, #a1a1aa);
            }

            .filter-input:focus {
                border-color: var(--ring, var(--primary, #3b82f6));
                box-shadow: 0 0 0 2px color-mix(in srgb, var(--ring, var(--primary, #3b82f6)) 20%, transparent);
            }

            .filter-checkbox {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 13px;
                color: var(--foreground, #18181b);
                cursor: pointer;
                white-space: nowrap;
                user-select: none;
            }

            .filter-checkbox input[type="checkbox"] {
                appearance: none;
                -webkit-appearance: none;
                width: 1rem;
                height: 1rem;
                border: 1px solid var(--primary, #3b82f6);
                border-radius: 4px;
                background: transparent;
                cursor: pointer;
                flex-shrink: 0;
                transition: all 0.15s;
            }
            .filter-checkbox input[type="checkbox"]:checked {
                background-color: var(--primary, #3b82f6);
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='20 6 9 17 4 12'%3E%3C/polyline%3E%3C/svg%3E");
                background-size: 0.65rem;
                background-position: center;
                background-repeat: no-repeat;
            }

            .filter-select {
                height: 36px;
                padding: 0 28px 0 10px;
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 8px;
                background: var(--background, #fff);
                color: var(--foreground, #18181b);
                font-family: inherit;
                font-size: 13px;
                outline: none;
                cursor: pointer;
                appearance: none;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 8px center;
            }

            .filter-select:focus {
                border-color: var(--ring, var(--primary, #3b82f6));
                box-shadow: 0 0 0 2px color-mix(in srgb, var(--ring, var(--primary, #3b82f6)) 20%, transparent);
            }

            /* ─── Data table ──────────────────────────────── */
            .table-wrapper {
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 10px;
                overflow: hidden;
            }

            .data-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
            }

            .data-table thead {
                background: var(--muted, #f4f4f5);
            }

            .data-table th {
                padding: 10px 14px;
                font-size: 12px;
                font-weight: 600;
                color: var(--muted-foreground, #71717a);
                text-align: left;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                border-bottom: 1px solid var(--border, #e4e4e7);
                white-space: nowrap;
            }

            .th-sortable {
                cursor: pointer;
                user-select: none;
            }

            .th-sortable span {
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }

            .th-sortable:hover {
                color: var(--foreground, #18181b);
            }

            .th-sorted {
                color: var(--foreground, #18181b);
            }

            .data-table td {
                padding: 12px 14px;
                border-bottom: 1px solid var(--border, #e4e4e7);
                vertical-align: middle;
            }

            .data-row:last-child td {
                border-bottom: none;
            }

            .data-row:hover {
                background: color-mix(in srgb, var(--muted, #f4f4f5) 50%, transparent);
            }

            /* Score badge */
            .score-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 48px;
                padding: 4px 10px;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 600;
                font-variant-numeric: tabular-nums;
            }

            /* Lang tag */
            .lang-tag {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 4px;
                background: var(--muted, #f4f4f5);
                color: var(--muted-foreground, #71717a);
                font-size: 12px;
                font-weight: 500;
                text-transform: uppercase;
            }

            .text-muted {
                color: var(--muted-foreground, #a1a1aa);
            }

            /* Page cell */
            .td-page {
                max-width: 360px;
            }

            .page-title {
                font-weight: 600;
                color: var(--foreground, #18181b);
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            a.page-link {
                text-decoration: none;
                display: block;
            }
            a.page-link:hover {
                color: var(--primary, #3b82f6);
            }

            .page-route {
                font-size: 12px;
                color: var(--muted-foreground, #71717a);
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                margin-top: 1px;
            }

            /* Links cell */
            .links-text {
                color: var(--muted-foreground, #71717a);
                font-size: 13px;
            }

            /* ─── Pagination ──────────────────────────────── */
            .pagination {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
            }

            .pagination-info {
                font-size: 13px;
                color: var(--muted-foreground, #71717a);
            }

            .pagination-buttons {
                display: flex;
                align-items: center;
                gap: 2px;
            }

            /* ─── Responsive ──────────────────────────────── */
            @media (max-width: 640px) {
                .header {
                    flex-direction: column;
                    align-items: stretch;
                }

                .header-actions {
                    justify-content: flex-end;
                }

                .filter-bar {
                    flex-direction: column;
                    align-items: stretch;
                }

                .filter-search {
                    min-width: 0;
                }

                .table-wrapper {
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                }

                .data-table {
                    min-width: 540px;
                }

                .pagination {
                    flex-direction: column;
                    align-items: center;
                }
            }
        </style>`;
    }
}

customElements.define(TAG, SeoMagicDashboard);
