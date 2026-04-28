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

class SeomagicBanner extends HTMLElement {
    _field = null;
    _value = null;
    _loading = true;
    _pageData = null;
    _error = null;

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
        const path = window.location.pathname;
        const match = path.match(/\/pages\/edit\/(.+)/);
        return match ? match[1] : '';
    }

    _getContentLang() {
        // Admin-next exposes the active language on window for custom fields.
        // Avoids reaching into admin-next's site-scoped localStorage, which
        // is not resolvable from here on sub-path installs.
        return window.__GRAV_CONTENT_LANG || '';
    }


    _relativeTime(timestamp) {
        const diff = Math.floor(Date.now() / 1000) - timestamp;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
        return Math.floor(diff / 86400) + ' days ago';
    }

    async _fetchPageData() {
        const route = this._getPageRoute();
        if (!route) {
            this._loading = false;
            this._error = 'no-route';
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
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: seomagic-banner-spin 1s linear infinite;">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                    </svg>
                    Loading SEO data...
                </div>
                <style>
                    @keyframes seomagic-banner-spin {
                        from { transform: rotate(0deg); }
                        to { transform: rotate(360deg); }
                    }
                </style>
            `;
            return;
        }

        if (this._error === 'no-route') {
            this.innerHTML = `
                <div style="
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 12px 16px;
                    color: var(--muted-foreground, #71717a);
                    font-size: 13px;
                    line-height: 1.5;
                ">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="4.93" x2="19.07" y1="4.93" y2="19.07"/>
                    </svg>
                    SEO data not available for non-routable pages.
                </div>
            `;
            return;
        }

        const clockIcon = `
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
        `;

        const infoIcon = `
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;">
                <circle cx="12" cy="12" r="10"/>
                <path d="M12 16v-4"/>
                <path d="M12 8h.01"/>
            </svg>
        `;

        const bannerStyle = `
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            background: color-mix(in srgb, var(--primary, #3b82f6) 12%, transparent);
            color: color-mix(in srgb, var(--primary, #3b82f6) 80%, var(--foreground, #1f2937));
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.5;
        `;

        if (this._pageData && this._pageData.updated) {
            const timeAgo = this._relativeTime(this._pageData.updated);
            this.innerHTML = `
                <div style="${bannerStyle}">
                    ${clockIcon}
                    <span>SEO Magic Data was last updated <strong>${timeAgo}</strong>. Click <strong>Save</strong> to regenerate.</span>
                </div>
            `;
        } else {
            this.innerHTML = `
                <div style="${bannerStyle}">
                    ${infoIcon}
                    <span>No SEO data generated yet. Click <strong>Save</strong> to generate.</span>
                </div>
            `;
        }
    }
}

customElements.define(TAG, SeomagicBanner);
