const TAG = window.__GRAV_FIELD_TAG;

if (!window.__seomagicPageDataFetch) {
    const cache = new Map();
    window.__seomagicPageDataFetch = (baseUrl, route, lang, headers) => {
        const key = `${route}|${lang || ''}`;
        if (!cache.has(key)) {
            const promise = fetch(`${baseUrl}/seo-magic/page-data/${route}?lang=${lang || ''}`, { headers })
                .then(res => {
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    return res.json();
                })
                .catch(err => { cache.delete(key); throw err; });
            cache.set(key, promise);
        }
        return cache.get(key);
    };
}

class SeomagicReport extends HTMLElement {
    _field = null;
    _value = null;
    _loading = true;
    _error = null;
    _pageData = null;
    _collapsedSections = new Set();
    _serpMode = 'desktop';
    _activeNavPill = null;

    set field(v) { this._field = v; this._render(); }
    get field() { return this._field; }
    set value(v) { this._value = v; }
    get value() { return this._value; }

    connectedCallback() {
        this.attachShadow({ mode: 'open' });
        this._render();
        this._fetchPageData();
    }

    // --------------- API ---------------

    _getApiConfig() {
        const baseUrl = window.__GRAV_API_SERVER_URL + (window.__GRAV_API_PREFIX || '/api/v1');
        const token = window.__GRAV_API_TOKEN;
        const headers = { 'Content-Type': 'application/json' };
        if (token) headers['X-API-Token'] = token;
        return { baseUrl, headers };
    }

    _getPageRoute() {
        const match = window.location.pathname.match(/\/pages\/edit\/(.+)/);
        return match ? match[1] : '';
    }

    _getContentLang() {
        // Admin-next exposes the active language on window for custom fields.
        // Avoids reaching into admin-next's site-scoped localStorage, which
        // is not resolvable from here on sub-path installs.
        return window.__GRAV_CONTENT_LANG || '';
    }

    async _fetchPageData() {
        const route = this._getPageRoute();
        if (!route) {
            this._loading = false;
            this._render();
            return;
        }

        const { baseUrl, headers } = this._getApiConfig();

        try {
            const json = await window.__seomagicPageDataFetch(baseUrl, route, this._getContentLang(), headers);
            this._pageData = json.data || null;
        } catch (e) {
            this._error = e.message || 'Failed to load SEO data';
            this._pageData = null;
        }

        this._loading = false;
        this._render();
    }

    // --------------- Helpers ---------------

    _esc(str) {
        if (str == null) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    _grade(score) {
        const s = Math.round(score ?? 0);
        if (s >= 90) return { letter: 'A', color: '#22c55e' };
        if (s >= 80) return { letter: 'B', color: '#84cc16' };
        if (s >= 70) return { letter: 'C', color: '#eab308' };
        if (s >= 60) return { letter: 'D', color: '#f97316' };
        return { letter: 'F', color: '#ef4444' };
    }

    _gaugeColor(score) {
        if (score >= 80) return '#22c55e';
        if (score >= 50) return '#eab308';
        return '#ef4444';
    }

    _badge(score) {
        const g = this._grade(score);
        return `<span class="grade-badge" style="background:${g.color}">${g.letter}</span>`;
    }

    _niceBytes(bytes) {
        if (bytes == null) return 'N/A';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    _niceMs(seconds) {
        if (seconds == null) return 'N/A';
        return (seconds * 1000).toFixed(3) + ' ms';
    }

    _formatDate(ts) {
        if (!ts) return '';
        const d = new Date(ts * 1000);
        return d.toLocaleString();
    }

    _toggleSection(id) {
        if (this._collapsedSections.has(id)) {
            this._collapsedSections.delete(id);
        } else {
            this._collapsedSections.add(id);
        }
        this._render();
    }

    _expandAll() {
        this._collapsedSections.clear();
        this._render();
    }

    _collapseAll() {
        const ids = ['summary', 'serp', 'url', 'head', 'content', 'images', 'links'];
        ids.forEach(id => this._collapsedSections.add(id));
        this._render();
    }

    _scrollTo(id) {
        const el = this.shadowRoot.getElementById(id);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    _get(obj, path) {
        return path.split('.').reduce((o, k) => (o && o[k] != null) ? o[k] : null, obj);
    }

    // --------------- Rendering ---------------

    _render() {
        const shadow = this.shadowRoot;
        if (!shadow) return;

        shadow.innerHTML = '';
        shadow.appendChild(this._buildStyles());

        if (this._loading) {
            shadow.innerHTML += `
                <div class="state-msg">
                    <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                    Loading SEO report...
                </div>`;
            return;
        }

        if (this._error && !this._pageData) {
            shadow.innerHTML += `
                <div class="state-msg error">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
                    ${this._esc(this._error)}
                </div>`;
            return;
        }

        const score = this._pageData?.score;
        if (!score || score.score == null) {
            shadow.innerHTML += `
                <div class="state-msg">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                    No SEO data. Save the page to generate a report.
                </div>`;
            return;
        }

        const raw = this._pageData.rawdata || {};
        const container = document.createElement('div');
        container.className = 'report';

        container.innerHTML = [
            this._renderHeader(),
            this._renderGauge(score.score),
            this._renderCategoryCards(score.items),
            this._renderTimingBar(raw.timings),
            this._renderNavPills(),
            this._renderSection('summary', 'Summary Information', this._renderSummary(raw)),
            this._renderSection('serp', 'SERP Preview', this._renderSerp(raw)),
            this._renderSection('url', 'Page URL', this._renderUrlSection(score.items)),
            this._renderSection('head', 'Head Elements', this._renderHeadSection(score.items, raw)),
            this._renderSection('content', 'Content Elements', this._renderContentSection(score.items, raw)),
            this._renderSection('images', 'Content Images', this._renderImagesSection(score.items, raw)),
            this._renderSection('links', 'Content Links', this._renderLinksSection(score.items, raw)),
        ].join('');

        shadow.appendChild(container);
        this._bindEvents();
    }

    _buildStyles() {
        const style = document.createElement('style');
        style.textContent = `
            :host {
                display: block;
                font-family: inherit;
                color: var(--foreground, #18181b);
                line-height: 1.5;
            }
            *, *::before, *::after { box-sizing: border-box; }

            .state-msg {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 24px 16px;
                font-size: 13px;
                color: var(--muted-foreground, #71717a);
                justify-content: center;
            }
            .state-msg.error {
                color: #ef4444;
            }

            .spinner {
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }

            .report {
                display: flex;
                flex-direction: column;
                gap: 24px;
            }

            /* Header */
            .report-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 16px;
                flex-wrap: wrap;
            }
            .report-header h1 {
                margin: 0;
                font-size: 20px;
                font-weight: 700;
                color: var(--foreground, #18181b);
            }
            .report-header .url {
                font-size: 13px;
                color: var(--primary, #3b82f6);
                word-break: break-all;
                margin-top: 4px;
            }
            .report-header .timestamp {
                font-size: 12px;
                color: var(--muted-foreground, #71717a);
                margin-top: 4px;
            }
            .btn-outline {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 14px;
                font-size: 13px;
                font-weight: 500;
                color: var(--foreground, #18181b);
                background: transparent;
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 6px;
                cursor: pointer;
                transition: background 0.15s, border-color 0.15s;
                white-space: nowrap;
                font-family: inherit;
            }
            .btn-outline:hover {
                background: color-mix(in srgb, var(--muted, #f4f4f5) 60%, transparent);
                border-color: var(--foreground, #18181b);
            }
            .btn-outline svg { width: 14px; height: 14px; }

            /* Gauge */
            .gauge-wrap {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 8px 0;
            }

            /* Category cards */
            .category-cards {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                justify-content: center;
            }
            .cat-card {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 6px;
                cursor: pointer;
                padding: 4px;
                border-radius: 6px;
                transition: background 0.15s;
                min-width: 64px;
            }
            .cat-card:hover {
                background: color-mix(in srgb, var(--muted, #f4f4f5) 60%, transparent);
            }
            .cat-card .grade-sq {
                width: 40px;
                height: 40px;
                border-radius: 6px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                font-weight: 700;
                color: #fff;
            }
            .cat-card .cat-label {
                font-size: 11px;
                color: var(--muted-foreground, #71717a);
                text-align: center;
                line-height: 1.2;
                max-width: 72px;
            }

            /* Timing bar */
            .timing-wrap {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .timing-label {
                font-size: 14px;
                font-weight: 600;
                color: var(--foreground, #18181b);
            }
            .timing-label strong {
                font-weight: 700;
            }
            .timing-bar {
                display: flex;
                height: 28px;
                border-radius: 6px;
                overflow: hidden;
                background: var(--border, #e4e4e7);
            }
            .timing-bar > div {
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 10px;
                font-weight: 600;
                color: #fff;
                white-space: nowrap;
                min-width: 2px;
                overflow: hidden;
            }
            .timing-legend {
                display: flex;
                gap: 16px;
                font-size: 12px;
                color: var(--muted-foreground, #71717a);
                flex-wrap: wrap;
            }
            .timing-legend span {
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }
            .timing-legend .dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                display: inline-block;
            }

            /* Nav pills */
            .nav-pills {
                display: flex;
                align-items: center;
                gap: 6px;
                flex-wrap: wrap;
            }
            .pill {
                display: inline-flex;
                align-items: center;
                padding: 4px 12px;
                font-size: 12px;
                font-weight: 500;
                color: var(--foreground, #18181b);
                background: color-mix(in srgb, var(--muted, #f4f4f5) 60%, transparent);
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 9999px;
                cursor: pointer;
                transition: background 0.15s, border-color 0.15s;
                white-space: nowrap;
                font-family: inherit;
            }
            .pill:hover {
                border-color: var(--primary, #3b82f6);
                color: var(--primary, #3b82f6);
            }
            .nav-spacer { flex: 1; }
            .pill.small {
                font-size: 11px;
                padding: 3px 10px;
                color: var(--muted-foreground, #71717a);
            }

            /* Sections */
            .section {
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 8px;
                overflow: hidden;
            }
            .section-header {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 12px 16px;
                background: color-mix(in srgb, var(--muted, #f4f4f5) 30%, var(--background, #fff));
                cursor: pointer;
                user-select: none;
                border-bottom: 1px solid var(--border, #e4e4e7);
                transition: background 0.15s;
            }
            .section-header:hover {
                background: color-mix(in srgb, var(--muted, #f4f4f5) 60%, var(--background, #fff));
            }
            .section-header .arrow {
                width: 14px;
                height: 14px;
                flex-shrink: 0;
                transition: transform 0.2s ease;
                color: var(--muted-foreground, #71717a);
            }
            .section-header .arrow.collapsed {
                transform: rotate(-90deg);
            }
            .section-header h3 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--foreground, #18181b);
                flex: 1;
            }
            .section-body {
                padding: 16px;
            }
            .section-body.hidden {
                display: none;
            }

            /* Grade badge */
            .grade-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 24px;
                height: 24px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 700;
                color: #fff;
                flex-shrink: 0;
                line-height: 1;
            }

            /* Info table */
            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
            }
            @media (max-width: 640px) {
                .info-grid { grid-template-columns: 1fr; }
            }
            .info-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
                margin-bottom: 12px;
            }
            .info-table:last-child { margin-bottom: 0; }
            .info-table thead th {
                text-align: left;
                padding: 8px 10px;
                font-size: 13px;
                font-weight: 600;
                color: var(--foreground, #18181b);
                border-bottom: 2px solid var(--border, #e4e4e7);
            }
            .info-table thead th[colspan] {
                background: color-mix(in srgb, var(--muted, #f4f4f5) 40%, transparent);
            }
            .info-table td, .info-table tbody th {
                padding: 6px 10px;
                border-bottom: 1px solid color-mix(in srgb, var(--border, #e4e4e7) 50%, transparent);
                vertical-align: top;
            }
            .info-table tbody th {
                font-weight: 500;
                color: var(--muted-foreground, #71717a);
                white-space: nowrap;
                width: 140px;
            }
            .info-table tbody td {
                color: var(--foreground, #18181b);
                word-break: break-all;
            }
            .info-table tr:nth-child(even) td,
            .info-table tr:nth-child(even) th {
                background: color-mix(in srgb, var(--muted, #f4f4f5) 30%, transparent);
            }

            .msg-box {
                padding: 6px 10px;
                border-radius: 4px;
                font-size: 12px;
                line-height: 1.5;
                background: color-mix(in srgb, var(--primary, #3b82f6) 8%, var(--background, #fff));
                color: var(--foreground, #18181b);
                margin-top: 4px;
            }

            /* Sub-section heading */
            .sub-heading {
                font-size: 14px;
                font-weight: 600;
                color: var(--foreground, #18181b);
                padding: 10px 0 6px;
                border-bottom: 1px solid var(--border, #e4e4e7);
                margin-bottom: 4px;
                margin-top: 12px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
            }
            .sub-heading:first-child { margin-top: 0; }

            /* Link */
            a, .link {
                color: var(--primary, #3b82f6);
                text-decoration: none;
            }
            a:hover, .link:hover {
                text-decoration: underline;
            }

            /* SERP */
            .serp-tabs {
                display: flex;
                gap: 4px;
                margin-bottom: 12px;
            }
            .serp-tab {
                padding: 4px 14px;
                font-size: 12px;
                font-weight: 500;
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 6px;
                cursor: pointer;
                background: transparent;
                color: var(--foreground, #18181b);
                font-family: inherit;
                transition: background 0.15s;
            }
            .serp-tab.active {
                background: var(--foreground, #18181b);
                color: var(--background, #fff);
                border-color: var(--foreground, #18181b);
            }
            .serp-preview {
                border: 1px solid #dadce0;
                border-radius: 8px;
                padding: 16px;
                background: #fff;
                color: #202124;
                max-width: 640px;
                font-family: Arial, sans-serif;
            }
            .serp-preview.mobile {
                max-width: 360px;
            }
            .serp-preview .serp-title {
                color: #1a0dab;
                font-size: 18px;
                line-height: 1.3;
                margin-bottom: 4px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .serp-preview .serp-url {
                color: #006621;
                font-size: 14px;
                margin-bottom: 6px;
                word-break: break-all;
            }
            .serp-preview .serp-desc {
                color: #4d5156;
                font-size: 13px;
                line-height: 1.4;
                display: -webkit-box;
                -webkit-line-clamp: 3;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }

            /* Status */
            .status-good { color: #22c55e; }
            .status-ok { color: #eab308; }
            .status-bad { color: #ef4444; }
            .status-na { color: var(--muted-foreground, #71717a); }

            .tag-code {
                display: inline-block;
                font-family: monospace;
                font-size: 12px;
                padding: 1px 6px;
                background: color-mix(in srgb, var(--muted, #f4f4f5) 60%, transparent);
                border-radius: 3px;
                border: 1px solid color-mix(in srgb, var(--border, #e4e4e7) 80%, transparent);
            }

            /* check / x icons inline */
            .icon-check, .icon-x { flex-shrink: 0; }

            /* Data tables */
            .data-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
                table-layout: auto;
            }
            .data-table td, .data-table th {
                padding: 8px 10px;
                border-bottom: 1px solid color-mix(in srgb, var(--border, #e4e4e7) 50%, transparent);
                vertical-align: top;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }
            .data-table thead th {
                text-align: left;
                font-size: 12px;
                font-weight: 600;
                color: var(--muted-foreground, #71717a);
                text-transform: uppercase;
                letter-spacing: 0.3px;
                border-bottom: 2px solid var(--border, #e4e4e7);
                white-space: nowrap;
            }
            .data-table tbody tr:nth-child(even) {
                background: color-mix(in srgb, var(--muted, #f4f4f5) 30%, transparent);
            }
            .data-table .cell-shrink { width: 80px; white-space: nowrap; }
            .data-table .cell-badge {
                width: 40px;
                text-align: center;
                vertical-align: middle;
            }

            .empty-msg {
                padding: 16px;
                font-size: 13px;
                color: var(--muted-foreground, #71717a);
                font-style: italic;
                text-align: center;
            }

            /* Print */
            @media print {
                :host {
                    color: #000 !important;
                    font-size: 11px !important;
                }
                .btn-outline, .nav-pills, .serp-tabs, .cat-card { cursor: default !important; }
                .section-body.hidden { display: block !important; }
                .section { break-inside: avoid; }
                .report { gap: 12px; }
            }
        `;
        return style;
    }

    // --------------- Header ---------------

    _renderHeader() {
        const raw = this._pageData?.rawdata || {};
        const info = raw.info || {};
        const url = info.url || '';
        const updated = this._pageData?.updated;

        return `
            <div class="report-header">
                <div>
                    <h1>SEO-Magic Report</h1>
                    ${url ? `<div class="url">${this._esc(url)}</div>` : ''}
                    ${updated ? `<div class="timestamp">Generated ${this._esc(this._formatDate(updated))}</div>` : ''}
                </div>
                <button class="btn-outline" data-action="print">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                    Export PDF
                </button>
            </div>`;
    }

    // --------------- Gauge ---------------

    _renderGauge(overallScore) {
        const pct = Math.round(overallScore);
        // Same technique as admin-classic:
        // 1) Overall circle = full rainbow conic-gradient (240deg arc)
        // 2) Score overlay = grey conic-gradient covering the unfilled portion
        // 3) Inner circle = background color to create donut hole
        const angle = 240;
        const bgstart = angle + (pct / 100) * angle;
        const bgpercent = ((100 - pct) * angle / 360).toFixed(2);

        const mask = 'radial-gradient(circle at center, transparent 54%, black 55%)';

        return `
            <div class="gauge-wrap" style="display: flex; flex-direction: column; align-items: center; padding: 16px 0 24px;">
                <div style="position: relative; width: 200px; height: 200px; margin: 0 auto;">
                    <!-- Rainbow arc -->
                    <div style="position: absolute; inset: 0; border-radius: 50%;
                        background: conic-gradient(from 240deg, #ef4444, #f97316, #eab308, #a3e635, #22c55e 240deg, transparent 0 360deg);
                        mask: ${mask}; -webkit-mask: ${mask};"></div>
                    <!-- Grey overlay for unfilled portion -->
                    <div style="position: absolute; inset: 0; border-radius: 50%;
                        background: conic-gradient(from ${bgstart}deg, #52525b ${bgpercent}%, transparent 0 360deg);
                        mask: ${mask}; -webkit-mask: ${mask};"></div>
                    <!-- Score number -->
                    <span style="position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
                        font-size: 4rem; font-weight: 700; color: var(--muted-foreground, #a1a1aa);">${pct}</span>
                </div>
            </div>`;
    }

    // --------------- Category Cards ---------------

    _renderCategoryCards(items) {
        if (!items) return '';

        const cards = [
            { key: 'url', label: 'URL', section: 'url' },
            { key: 'head.items.title', label: 'Title', section: 'head' },
            { key: 'head.items.meta', label: 'Meta', section: 'head' },
            { key: 'head.items.canonical', label: 'Canonical', section: 'head' },
            { key: 'head.items.hreflang', label: 'Hreflang', section: 'head' },
            { key: 'head.items.jsonld', label: 'JSON-LD', section: 'head' },
            { key: 'head.items.ogimage', label: 'OG Image', section: 'head' },
            { key: 'content.items.headers', label: 'Headers', section: 'content' },
            { key: 'content.items.links', label: 'Links', section: 'links' },
            { key: 'content.items.images', label: 'Images', section: 'images' },
        ];

        const html = cards.map(c => {
            const item = this._get(items, c.key);
            if (!item) return '';
            const s = Math.round(item.score ?? 0);
            const g = this._grade(s);
            return `
                <div class="cat-card" data-scroll="${c.section}">
                    <div class="grade-sq" style="background:${g.color}">${g.letter}</div>
                    <div class="cat-label">${this._esc(c.label)}</div>
                </div>`;
        }).join('');

        return `<div class="category-cards">${html}</div>`;
    }

    // --------------- Timing Bar ---------------

    _renderTimingBar(timings) {
        if (!timings || !timings.total_time) return '';

        const total = timings.total_time;
        const dns = timings.namelookup_time || 0;
        const connect = (timings.connect_time || 0) - dns;
        const pretransfer = (timings.pretransfer_time || 0) - (timings.connect_time || 0);
        const data = (timings.starttransfer_time || 0) - (timings.pretransfer_time || 0);

        const pDns = Math.max((dns / total) * 100, 0);
        const pConnect = Math.max((connect / total) * 100, 0);
        const pPre = Math.max((pretransfer / total) * 100, 0);
        const pData = Math.max((data / total) * 100, 0);

        return `
            <div class="timing-wrap">
                <div class="timing-label">Page Load Time: <strong>${this._niceMs(total)}</strong></div>
                <div class="timing-bar">
                    <div style="width:${pDns}%; background:#8b5cf6;" title="DNS: ${this._niceMs(dns)}">${pDns > 8 ? 'DNS' : ''}</div>
                    <div style="width:${pConnect}%; background:#f97316;" title="Connect: ${this._niceMs(connect)}">${pConnect > 8 ? 'Connect' : ''}</div>
                    <div style="width:${pPre}%; background:#22c55e;" title="Pre-Transfer: ${this._niceMs(pretransfer)}">${pPre > 8 ? 'Pre-Xfer' : ''}</div>
                    <div style="width:${pData}%; background:#3b82f6;" title="Data Transfer: ${this._niceMs(data)}">${pData > 8 ? 'Data' : ''}</div>
                </div>
                <div class="timing-legend">
                    <span><span class="dot" style="background:#8b5cf6"></span> DNS ${this._niceMs(dns)}</span>
                    <span><span class="dot" style="background:#f97316"></span> Connect ${this._niceMs(connect)}</span>
                    <span><span class="dot" style="background:#22c55e"></span> Pre-Transfer ${this._niceMs(pretransfer)}</span>
                    <span><span class="dot" style="background:#3b82f6"></span> Data ${this._niceMs(data)}</span>
                </div>
            </div>`;
    }

    // --------------- Nav Pills ---------------

    _renderNavPills() {
        const pills = [
            { id: 'summary', label: 'Summary' },
            { id: 'serp', label: 'SERP Preview' },
            { id: 'url', label: 'Page URL' },
            { id: 'head', label: 'Head Elements' },
            { id: 'content', label: 'Content Elements' },
            { id: 'images', label: 'Content Images' },
            { id: 'links', label: 'Content Links' },
        ];

        const pillsHtml = pills.map(p =>
            `<button class="pill" data-scroll="${p.id}">${p.label}</button>`
        ).join('');

        return `
            <div class="nav-pills">
                ${pillsHtml}
                <span class="nav-spacer"></span>
                <button class="pill small" data-action="expand-all">Expand All</button>
                <button class="pill small" data-action="collapse-all">Collapse All</button>
            </div>`;
    }

    // --------------- Collapsible Section ---------------

    _renderSection(id, title, bodyHtml) {
        const collapsed = this._collapsedSections.has(id);
        return `
            <div class="section" id="${id}">
                <div class="section-header" data-toggle="${id}">
                    <svg class="arrow${collapsed ? ' collapsed' : ''}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    <h3>${this._esc(title)}</h3>
                </div>
                <div class="section-body${collapsed ? ' hidden' : ''}">
                    ${bodyHtml}
                </div>
            </div>`;
    }

    // --------------- Summary ---------------

    _renderSummary(raw) {
        const info = raw.info || {};
        const resp = raw.response_headers || {};

        const sslProtected = (info.scheme || '').toLowerCase() === 'https' ? 'Yes' : 'No';
        const httpVersion = info.http_version ? `HTTP/${info.http_version}` : 'N/A';
        const speedMbps = info.speed_download ? ((info.speed_download * 8) / 1000000).toFixed(2) + ' Mbps' : 'N/A';

        return `
            <div class="info-grid">
                <div>
                    <table class="info-table">
                        <thead><tr><th colspan="2">Response Header</th></tr></thead>
                        <tbody>
                            <tr><th>Server</th><td>${this._esc(resp['server'] || 'N/A')}</td></tr>
                            <tr><th>Type</th><td>${this._esc(resp['content-type'] || info.content_type || 'N/A')}</td></tr>
                            <tr><th>Encoding</th><td>${this._esc(resp['content-encoding'] || 'N/A')}</td></tr>
                        </tbody>
                    </table>
                    <table class="info-table">
                        <thead><tr><th colspan="2">Caching Status</th></tr></thead>
                        <tbody>
                            <tr><th>Expires</th><td>${this._esc(resp['expires'] || 'N/A')}</td></tr>
                            <tr><th>Cache-Control</th><td>${this._esc(resp['cache-control'] || 'N/A')}</td></tr>
                            <tr><th>Pragma</th><td>${this._esc(resp['pragma'] || 'N/A')}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <table class="info-table">
                        <thead><tr><th colspan="2">Connection</th></tr></thead>
                        <tbody>
                            <tr><th>External IP</th><td>${this._esc(info.primary_ip || 'N/A')}</td></tr>
                            <tr><th>Local IP</th><td>${this._esc(info.local_ip || 'N/A')}</td></tr>
                            <tr><th>Version</th><td>${this._esc(httpVersion)}</td></tr>
                            <tr><th>SSL Protected</th><td>${this._esc(sslProtected)}</td></tr>
                            ${sslProtected === 'Yes' ? `<tr><th>SSL Verify</th><td>${info.ssl_verifyresult === 0 ? 'Yes' : 'No'}</td></tr>` : ''}
                        </tbody>
                    </table>
                    <table class="info-table">
                        <thead><tr><th colspan="2">Size &amp; Speed</th></tr></thead>
                        <tbody>
                            <tr><th>Header Size</th><td>${this._esc(this._niceBytes(info.header_size))}</td></tr>
                            <tr><th>Request Size</th><td>${this._esc(this._niceBytes(info.request_size))}</td></tr>
                            <tr><th>Page Size</th><td>${this._esc(this._niceBytes(info.size_download))}</td></tr>
                            <tr><th>Download Speed</th><td>${this._esc(speedMbps)}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>`;
    }

    // --------------- SERP Preview ---------------

    _renderSerp(raw) {
        const head = raw.head || {};
        const meta = head.meta || {};
        const info = raw.info || {};

        const title = meta.title || meta['og:title'] || head.title || this._pageData?.page_title || '';
        const desc = meta.description || meta['og:description'] || '';
        const url = info.url || '';
        const isMobile = this._serpMode === 'mobile';

        return `
            <div class="serp-tabs">
                <button class="serp-tab${!isMobile ? ' active' : ''}" data-serp="desktop">Desktop</button>
                <button class="serp-tab${isMobile ? ' active' : ''}" data-serp="mobile">Mobile</button>
            </div>
            <div class="serp-preview${isMobile ? ' mobile' : ''}">
                <div class="serp-title">${this._esc(title)}</div>
                <div class="serp-url">${this._esc(url)}</div>
                <div class="serp-desc">${this._esc(desc)}</div>
            </div>`;
    }

    // --------------- URL Section ---------------

    _renderUrlSection(items) {
        const urlItem = items?.url;
        if (!urlItem) return '<div class="empty-msg">No URL data available.</div>';

        const info = this._pageData?.rawdata?.info || {};

        return `
            <table class="data-table">
                <tbody>
                    <tr>
                        <td style="width:120px;font-weight:600;">Page URL</td>
                        <td>
                            <div><a href="${this._esc(info.url || '')}" target="_blank">${this._esc(info.url || 'N/A')}</a></div>
                            ${urlItem.msg ? `<div class="msg-box" style="margin-top:6px">${this._esc(urlItem.msg)}</div>` : ''}
                        </td>
                        <td class="cell-badge">${this._badge(urlItem.score)}</td>
                    </tr>
                </tbody>
            </table>`;
    }

    // --------------- Head Elements ---------------

    _renderHeadSection(items, raw) {
        const head = items?.head?.items || {};
        const rawHead = raw.head || {};
        const meta = rawHead.meta || {};
        const parts = [];

        // Build a single table for Page Title, Fav Icon, Canonical, Hreflang, JSON-LD, OG Image
        const headItems = [
            { key: 'title', label: 'Page Title', value: rawHead.title || 'N/A', item: head.title },
            { key: 'icon', label: 'Fav Icon', value: rawHead.icon || '', link: true, item: head.icon },
            { key: 'canonical', label: 'Canonical URL', value: rawHead.canonical || '', link: true, item: head.canonical },
            { key: 'hreflang', label: 'Hreflang', value: '', item: head.hreflang },
            { key: 'jsonld', label: 'JSON-LD', value: '', item: head.jsonld },
            { key: 'ogimage', label: 'OG Image', value: meta['og:image'] || '', link: true, item: head.ogimage },
        ];

        let headRows = '';
        for (const hi of headItems) {
            if (!hi.item) continue;
            const val = hi.link && hi.value
                ? `<a href="${this._esc(hi.value)}" target="_blank">${this._esc(hi.value)}</a>`
                : this._esc(hi.value);
            headRows += `
                <tr>
                    <td style="font-weight:600;">${this._esc(hi.label)}</td>
                    <td>${val || ''}</td>
                    <td class="msg-box" style="font-size:12px;">${this._esc(hi.item.msg || '')}</td>
                    <td class="cell-badge">${this._badge(hi.item.score)}</td>
                </tr>`;
        }

        if (headRows) {
            parts.push(`
                <table class="data-table">
                    <thead>
                        <tr><th>Item</th><th>Value</th><th>Message</th><th class="cell-badge">Grade</th></tr>
                    </thead>
                    <tbody>${headRows}</tbody>
                </table>`);
        }

        // Metadata table
        const metaItem = head.meta;
        if (metaItem) {
            let metaRows = '';
            const metaSubs = metaItem.items || {};
            for (const [key, val] of Object.entries(metaSubs)) {
                if (!val || !val.msg) continue;
                const dataValue = meta[key] || '';
                metaRows += `
                    <tr>
                        <td style="white-space:nowrap; font-weight:500; width:120px;">${this._esc(key)}</td>
                        <td style="word-break:break-all;">${this._esc(dataValue)}</td>
                        <td class="msg-box" style="font-size:12px; max-width:300px;">${this._esc(val.msg)}</td>
                        <td class="cell-badge">${this._badge(val.score)}</td>
                    </tr>`;
            }

            if (metaRows) {
                parts.push(`
                    <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="3" style="font-size:14px;font-weight:600;padding:12px 10px 8px;border-bottom:1px solid var(--border, #e4e4e7);">Metadata</td><td class="cell-badge" style="padding-top:12px;">${this._badge(metaItem.score)}</td></tr></tbody>
                        <thead>
                            <tr>
                                <th>Tag</th>
                                <th>Value</th>
                                <th>Message</th>
                                <th class="cell-badge">Grade</th>
                            </tr>
                        </thead>
                        <tbody>${metaRows}</tbody>
                    </table>`);
            }
        }

        // CSS Stylesheets (head links)
        const linksItem = head.links;
        if (linksItem && linksItem.items) {
            const entries = Object.entries(linksItem.items);
            if (entries.length > 0) {
                let linkRows = '';
                for (const [href, val] of entries) {
                    linkRows += `
                        <tr>
                            <td style="word-break:break-all;"><a href="${this._esc(href)}" target="_blank">${this._esc(href)}</a></td>
                            <td class="msg-box" style="font-size:12px;">${this._esc(val.msg || '')}</td>
                            <td class="cell-badge">${this._badge(val.score)}</td>
                        </tr>`;
                }
                parts.push(`
                    <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="2" style="font-size:14px;font-weight:600;padding:12px 10px 8px;border-bottom:1px solid var(--border, #e4e4e7);">CSS Stylesheets (${entries.length})</td><td class="cell-badge" style="padding-top:12px;">${this._badge(linksItem.score)}</td></tr></tbody>
                        <thead>
                            <tr><th>Stylesheet</th><th>Message</th><th class="cell-badge">Grade</th></tr>
                        </thead>
                        <tbody>${linkRows}</tbody>
                    </table>`);
            }
        }

        // JavaScript (head scripts)
        const scriptsItem = head.scripts;
        if (scriptsItem && scriptsItem.items) {
            const entries = Object.entries(scriptsItem.items);
            if (entries.length > 0) {
                let scriptRows = '';
                for (const [src, val] of entries) {
                    scriptRows += `
                        <tr>
                            <td style="word-break:break-all;"><a href="${this._esc(src)}" target="_blank">${this._esc(src)}</a></td>
                            <td class="msg-box" style="font-size:12px;">${this._esc(val.msg || '')}</td>
                            <td class="cell-badge">${this._badge(val.score)}</td>
                        </tr>`;
                }
                parts.push(`
                    <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="2" style="font-size:14px;font-weight:600;padding:12px 10px 8px;border-bottom:1px solid var(--border, #e4e4e7);">JavaScript (${entries.length})</td><td class="cell-badge" style="padding-top:12px;">${this._badge(scriptsItem.score)}</td></tr></tbody>
                        <thead>
                            <tr><th>Script</th><th>Message</th><th class="cell-badge">Grade</th></tr>
                        </thead>
                        <tbody>${scriptRows}</tbody>
                    </table>`);
            }
        }

        return parts.length > 0 ? parts.join('') : '<div class="empty-msg">No head element data available.</div>';
    }

    // --------------- Content Elements ---------------

    _renderContentSection(items, raw) {
        const content = items?.content?.items || {};
        const rawContent = raw.content || {};
        const parts = [];

        // Header Tags
        const headerItem = content.headers;
        if (headerItem) {
            const rawHeaders = rawContent.headers || {};
            const headerSubs = headerItem.items || {};
            let headerRows = '';

            for (const level of ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) {
                const tags = rawHeaders[level] || [];
                const detail = headerSubs[level];
                if (!detail) continue;

                const tagList = tags.length > 0
                    ? tags.map(t => `<div style="margin:2px 0;"><strong>${this._esc(t)}</strong></div>`).join('')
                    : '<em style="color:var(--muted-foreground,#71717a)">None</em>';

                headerRows += `
                    <tr>
                        <td class="cell-shrink"><span class="tag-code">&lt;${level}&gt;</span> (${tags.length})</td>
                        <td>${tagList}</td>
                        <td class="msg-box" style="font-size:12px; max-width:280px;">${this._esc(detail.msg || '')}</td>
                        <td class="cell-badge">${this._badge(detail.score)}</td>
                    </tr>`;
            }

            parts.push(`
                <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="3" style="font-size:14px;font-weight:600;padding:12px 10px 8px;border-bottom:1px solid var(--border, #e4e4e7);">Header Tags</td><td class="cell-badge" style="padding-top:12px;">${this._badge(headerItem.score)}</td></tr></tbody>
                    <thead>
                        <tr><th>Tag</th><th>Content</th><th>Message</th><th class="cell-badge">Grade</th></tr>
                    </thead>
                    <tbody>${headerRows}</tbody>
                </table>`);
        }

        // Good Tags
        const goodTagsItem = content.good_tags;
        if (goodTagsItem && goodTagsItem.items) {
            let goodRows = '';
            const rawGoodTags = rawContent.good_tags || {};

            for (const [tagType, val] of Object.entries(goodTagsItem.items)) {
                const tagData = rawGoodTags[tagType] || {};
                const tagList = Object.entries(tagData).map(([tag, count]) =>
                    `<span class="tag-code">&lt;${this._esc(tag)}&gt;</span> (${count})`
                ).join(' &nbsp; ') || '<em>None</em>';

                goodRows += `
                    <tr>
                        <td>${tagList}</td>
                        <td class="msg-box" style="font-size:12px;">${this._esc(val.msg || '')}</td>
                        <td class="cell-badge">${this._badge(val.score)}</td>
                    </tr>`;
            }

            parts.push(`
                <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="2" style="font-size:14px;font-weight:600;padding:12px 10px 8px;border-bottom:1px solid var(--border, #e4e4e7);">Good HTML Tags</td><td class="cell-badge" style="padding-top:12px;">${this._badge(goodTagsItem.score)}</td></tr></tbody>
                    <thead><tr><th>Tags</th><th>Message</th><th class="cell-badge">Grade</th></tr></thead>
                    <tbody>${goodRows}</tbody>
                </table>`);
        }

        // Bad Tags
        const badTagsItem = content.bad_tags;
        if (badTagsItem && badTagsItem.items) {
            let badRows = '';
            const rawBadTags = rawContent.bad_tags || {};

            for (const [tagType, val] of Object.entries(badTagsItem.items)) {
                const count = rawBadTags[tagType] ?? 0;
                badRows += `
                    <tr>
                        <td><span class="tag-code">&lt;${this._esc(tagType)}&gt;</span> (${count})</td>
                        <td class="msg-box" style="font-size:12px;">${this._esc(val.msg || '')}</td>
                        <td class="cell-badge">${this._badge(val.score)}</td>
                    </tr>`;
            }

            parts.push(`
                <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="2" style="font-size:14px;font-weight:600;padding:12px 10px 8px;border-bottom:1px solid var(--border, #e4e4e7);">Bad HTML Tags</td><td class="cell-badge" style="padding-top:12px;">${this._badge(badTagsItem.score)}</td></tr></tbody>
                    <thead><tr><th>Tag</th><th>Message</th><th class="cell-badge">Grade</th></tr></thead>
                    <tbody>${badRows}</tbody>
                </table>`);
        }

        return parts.length > 0 ? parts.join('') : '<div class="empty-msg">No content element data available.</div>';
    }

    // --------------- Content Images ---------------

    _renderImagesSection(items, raw) {
        const imageItem = this._get(items, 'content.items.images');
        const rawImages = raw.content?.images || [];

        if (!imageItem) return '<div class="empty-msg">No image data available.</div>';

        if (rawImages.length === 0) {
            return `
                <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="4" style="font-size:14px;font-weight:600;padding:12px 10px 8px;">Images</td><td class="cell-badge" style="padding-top:12px;">${this._badge(imageItem.score)}</td></tr><tr><td colspan="4" class="empty-msg">No images found on this page.</td></tr></tbody></table>`;
        }

        const imageSubs = imageItem.items || {};
        let rows = '';

        rawImages.forEach((img, idx) => {
            const detail = imageSubs[idx] || imageSubs[String(idx)] || {};
            const statusCode = img.status || 'N/A';
            let statusClass = 'status-na';
            let statusMsg = 'Image was not checked';

            if (statusCode === 200 || statusCode === '200') {
                statusClass = 'status-good';
                statusMsg = 'Image is functional';
            } else if (statusCode !== 'N/A') {
                statusClass = 'status-bad';
                statusMsg = 'Image is broken or restricted';
            }

            rows += `
                <tr>
                    <td style="word-break:break-all;"><a href="${this._esc(img.src)}" target="_blank">${this._esc(img.src || 'N/A')}</a></td>
                    <td>${this._esc(img.alt || '') || '<em style="color:var(--muted-foreground,#71717a)">None</em>'}</td>
                    <td class="cell-shrink ${statusClass}" title="${this._esc(statusMsg)}">${this._esc(statusCode)}</td>
                    <td class="msg-box" style="font-size:12px;">${this._esc(detail.msg || '')}</td>
                    <td class="cell-badge">${this._badge(detail.score ?? 0)}</td>
                </tr>`;
        });

        return `
            <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="4" style="font-size:14px;font-weight:600;padding:12px 10px 8px;border-bottom:1px solid var(--border, #e4e4e7);">Images (${rawImages.length})</td><td class="cell-badge" style="padding-top:12px;">${this._badge(imageItem.score)}</td></tr></tbody>
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>Alt Tag</th>
                        <th>Status</th>
                        <th>Message</th>
                        <th class="cell-badge">Grade</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;
    }

    // --------------- Content Links ---------------

    _renderLinksSection(items, raw) {
        const linkItem = this._get(items, 'content.items.links');
        const rawLinks = raw.content?.links || {};

        if (!linkItem) return '<div class="empty-msg">No link data available.</div>';

        const entries = Object.entries(rawLinks);
        if (entries.length === 0) {
            return `
                <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="4" style="font-size:14px;font-weight:600;padding:12px 10px 8px;">Links</td><td class="cell-badge" style="padding-top:12px;">${this._badge(linkItem.score)}</td></tr><tr><td colspan="4" class="empty-msg">No links found on this page.</td></tr></tbody></table>`;
        }

        // Count broken links
        let brokenCount = 0;
        entries.forEach(([, attrib]) => {
            if (attrib.status && attrib.status >= 400) brokenCount++;
        });

        const linkSubs = linkItem.items || {};
        let rows = '';

        for (const [href, attrib] of entries) {
            const detail = linkSubs[href] || {};
            const linkType = attrib.external ? 'external' : 'internal';
            const statusCode = attrib.status || 'N/A';
            let statusClass = 'status-na';
            let statusMsg = 'Link was not checked';

            if (statusCode === 'N/A') {
                statusMsg = 'Link Checker not enabled';
            } else if (statusCode >= 200 && statusCode < 300) {
                statusClass = 'status-good';
                statusMsg = 'Link is functional';
            } else if (statusCode >= 300 && statusCode < 400) {
                statusClass = 'status-ok';
                statusMsg = 'Link is redirected' + (attrib.status_msg ? ` (${attrib.status_msg})` : '');
            } else {
                statusClass = 'status-bad';
                statusMsg = 'Link is broken or restricted';
            }

            const linkMsg = detail.msg || `A standard ${linkType} link`;
            const linkIcon = attrib.external
                ? `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align: -1px; flex-shrink:0;"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>`
                : `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="vertical-align: -1px; flex-shrink:0;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>`;

            rows += `
                <tr>
                    <td style="word-break:break-all;">${linkIcon} <a href="${this._esc(href)}" target="_blank">${this._esc(href)}</a></td>
                    <td>${this._esc(attrib.text || '') || '<em style="color:var(--muted-foreground,#71717a)">None</em>'}</td>
                    <td class="cell-shrink ${statusClass}" title="${this._esc(statusMsg)}">${this._esc(String(statusCode))}</td>
                    <td class="msg-box" style="font-size:12px;">${this._esc(linkMsg)}</td>
                    <td class="cell-badge">${this._badge(detail.score ?? 85)}</td>
                </tr>`;
        }

        const brokenAlert = brokenCount > 0
            ? `<div style="padding:8px 12px; border-radius:6px; background:color-mix(in srgb, #ef4444 10%, var(--background,#fff)); color:#ef4444; font-size:13px; font-weight:500; margin-bottom:12px;">Found <strong>${brokenCount}</strong> broken link${brokenCount > 1 ? 's' : ''} on this page.</div>`
            : '';

        return `
            ${brokenAlert}
            <table class="data-table"><tbody><tr class="section-grade-row"><td colspan="4" style="font-size:14px;font-weight:600;padding:12px 10px 8px;border-bottom:1px solid var(--border, #e4e4e7);">Links (${entries.length})</td><td class="cell-badge" style="padding-top:12px;">${this._badge(linkItem.score)}</td></tr></tbody>
                <thead>
                    <tr>
                        <th>Link</th>
                        <th>Text</th>
                        <th>Status</th>
                        <th>Message</th>
                        <th class="cell-badge">Grade</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>`;
    }

    // --------------- Events ---------------

    _bindEvents() {
        const shadow = this.shadowRoot;

        // Section toggles
        shadow.querySelectorAll('.section-header[data-toggle]').forEach(el => {
            el.addEventListener('click', () => this._toggleSection(el.dataset.toggle));
        });

        // Scroll actions (nav pills + category cards)
        shadow.querySelectorAll('[data-scroll]').forEach(el => {
            el.addEventListener('click', () => this._scrollTo(el.dataset.scroll));
        });

        // Expand / Collapse all
        shadow.querySelectorAll('[data-action="expand-all"]').forEach(el => {
            el.addEventListener('click', () => this._expandAll());
        });
        shadow.querySelectorAll('[data-action="collapse-all"]').forEach(el => {
            el.addEventListener('click', () => this._collapseAll());
        });

        // SERP tabs
        shadow.querySelectorAll('[data-serp]').forEach(el => {
            el.addEventListener('click', () => {
                this._serpMode = el.dataset.serp;
                this._render();
            });
        });

        // Print / Export PDF — open a standalone window with just the report
        shadow.querySelectorAll('[data-action="print"]').forEach(el => {
            el.addEventListener('click', () => {
                // Expand all sections, re-render, grab the HTML
                const wasCollapsed = new Set(this._collapsedSections);
                this._collapsedSections.clear();
                this._render();

                requestAnimationFrame(() => {
                    const shadow = this.shadowRoot;
                    // Clone styles and content from shadow DOM
                    const styles = shadow.querySelector('style')?.textContent || '';
                    const content = shadow.querySelector('.report-container')?.innerHTML || shadow.innerHTML;

                    const printWin = window.open('', '_blank', 'width=900,height=700');
                    printWin.document.write(`<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>SEO-Magic Report</title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        margin: 0; padding: 24px;
        color: #18181b; background: #fff;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    a { color: #2563eb; }
    ${styles.replace(/var\(--foreground[^)]*\)/g, '#18181b')
            .replace(/var\(--background[^)]*\)/g, '#fff')
            .replace(/var\(--card[^)]*\)/g, '#fff')
            .replace(/var\(--border[^)]*\)/g, '#e4e4e7')
            .replace(/var\(--muted-foreground[^)]*\)/g, '#71717a')
            .replace(/var\(--muted[^)]*\)/g, '#f4f4f5')
            .replace(/var\(--primary[^)]*\)/g, '#3b82f6')
            .replace(/var\(--input[^)]*\)/g, '#e4e4e7')
            .replace(/var\(--ring[^)]*\)/g, '#3b82f6')}
    .btn-outline, [data-action] { display: none !important; }
    .nav-pills { display: none !important; }
    @media print {
        body { padding: 0; }
    }
</style>
</head><body>${content}</body></html>`);
                    printWin.document.close();
                    // Wait for rendering then print
                    printWin.onload = () => { printWin.print(); };
                    // Fallback if onload doesn't fire
                    setTimeout(() => { printWin.print(); }, 500);

                    // Restore collapsed state
                    this._collapsedSections = wasCollapsed;
                    this._render();
                });
            });
        });
    }
}

customElements.define(TAG, SeomagicReport);
