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

class SeomagicOpengraph extends HTMLElement {
    _field = null;
    _value = null;
    _loading = true;
    _pageData = null;

    set field(v) {
        this._field = v;
        this._render();
    }

    get field() {
        return this._field;
    }

    set value(v) {
        this._value = v;
    }

    get value() {
        return this._value;
    }

    connectedCallback() {
        this._render();
        this._fetchPageData();
    }

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
            this._pageData = null;
        }

        this._loading = false;
        this._render();
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    _extractDomain(url) {
        if (!url) return '';
        try {
            const parsed = new URL(url);
            return parsed.hostname;
        } catch {
            return url.replace(/^https?:\/\//, '').split('/')[0];
        }
    }

    _render() {
        if (this._loading) {
            this.innerHTML = `
                <div style="
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    padding: 8px 0;
                    color: var(--muted-foreground, #71717a);
                    font-size: 13px;
                ">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: seomagic-og-spin 1s linear infinite;">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                    </svg>
                    Loading OpenGraph preview...
                </div>
                <style>
                    @keyframes seomagic-og-spin {
                        from { transform: rotate(0deg); }
                        to { transform: rotate(360deg); }
                    }
                </style>
            `;
            return;
        }

        if (!this._pageData) {
            this.innerHTML = `
                <div style="
                    padding: 16px;
                    color: var(--muted-foreground, #71717a);
                    font-size: 13px;
                    text-align: center;
                ">No page data available. Save the page to generate OpenGraph preview.</div>
            `;
            return;
        }

        const metadata = this._pageData.metadata || {};
        const rawdata = this._pageData.rawdata || {};
        const head = rawdata.head || {};
        const meta = head.meta || {};

        const title = metadata['og:title'] || meta['og:title'] || head.title || 'Untitled Page';
        const description = metadata['og:description'] || meta['og:description'] || meta.description || '';
        const image = metadata['og:image'] || meta['og:image'] || meta.image || '';
        const url = head.canonical || meta['og:url'] || ('/' + this._getPageRoute());
        const domain = this._extractDomain(url) || window.location.hostname;

        const imageHtml = image
            ? `<div style="
                    aspect-ratio: 2 / 1; width: 100%;
                    background: #f3f4f6 url('${this._escapeHtml(image)}') center / cover no-repeat;
                "></div>`
            : `<div style="
                    aspect-ratio: 2 / 1; width: 100%;
                    background: #f3f4f6;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #9ca3af;
                ">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>
                        <circle cx="9" cy="9" r="2"/>
                        <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>
                    </svg>
                </div>`;

        const descriptionHtml = description
            ? `<div style="
                    font-size: 13px;
                    color: var(--muted-foreground, #71717a);
                    margin-top: 4px;
                    display: -webkit-box;
                    -webkit-line-clamp: 2;
                    -webkit-box-orient: vertical;
                    overflow: hidden;
                    line-height: 1.4;
                ">${this._escapeHtml(description)}</div>`
            : '';

        this.innerHTML = `
            <div style="
                max-width: 500px;
                border: 1px solid var(--border, #e4e4e7);
                border-radius: 8px;
                overflow: hidden;
                background: var(--background, #fff);
                font-family: inherit;
            ">
                ${imageHtml}
                <div style="padding: 12px 16px;">
                    <div style="
                        font-size: 11px;
                        color: var(--muted-foreground, #71717a);
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                        line-height: 1.4;
                    ">${this._escapeHtml(domain)}</div>
                    <div style="
                        font-size: 15px;
                        font-weight: 600;
                        color: var(--foreground, #18181b);
                        margin-top: 4px;
                        display: -webkit-box;
                        -webkit-line-clamp: 2;
                        -webkit-box-orient: vertical;
                        overflow: hidden;
                        line-height: 1.35;
                    ">${this._escapeHtml(title)}</div>
                    ${descriptionHtml}
                </div>
            </div>
        `;
    }
}

customElements.define(TAG, SeomagicOpengraph);
