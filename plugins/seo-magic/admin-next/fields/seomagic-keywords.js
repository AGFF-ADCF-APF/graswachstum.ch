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

class SeomagicKeywords extends HTMLElement {
    _field = null;
    _value = '';
    _loading = true;
    _autoKeywords = [];

    set field(v) {
        this._field = v;
        this._render();
    }

    get field() {
        return this._field;
    }

    set value(v) {
        this._value = v || '';
        this._render();
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
            const rawKeywords = json.data?.rawdata?.head?.meta?.keywords;
            if (rawKeywords && typeof rawKeywords === 'string') {
                this._autoKeywords = rawKeywords.split(',').map(k => k.trim()).filter(Boolean);
            } else if (Array.isArray(rawKeywords)) {
                this._autoKeywords = rawKeywords.map(k => String(k).trim()).filter(Boolean);
            }
        } catch (e) {
            this._autoKeywords = [];
        }

        this._loading = false;
        this._render();
    }

    _getTags() {
        if (!this._value || typeof this._value !== 'string') return [];
        return this._value.split(',').map(t => t.trim()).filter(Boolean);
    }

    _setTags(tags) {
        this._value = tags.join(', ');
        this._render();
        this.dispatchEvent(new CustomEvent('change', {
            detail: { value: this._value },
            bubbles: true,
        }));
    }

    _addTag(text) {
        const cleaned = text.trim();
        if (!cleaned) return;
        const tags = this._getTags();
        if (tags.some(t => t.toLowerCase() === cleaned.toLowerCase())) return;
        tags.push(cleaned);
        this._setTags(tags);
    }

    _removeTag(index) {
        const tags = this._getTags();
        tags.splice(index, 1);
        this._setTags(tags);
    }

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    _render() {
        const tags = this._getTags();

        const tagPills = tags.map((t, i) => `
            <span class="seomagic-kw-tag" style="
                display: inline-flex;
                align-items: center;
                gap: 4px;
                background: var(--secondary, #f4f4f5);
                color: var(--secondary-foreground, #18181b);
                padding: 1px 8px;
                border-radius: 6px;
                font-size: 11px;
                font-weight: 500;
                line-height: 1.6;
                cursor: default;
                white-space: nowrap;
            ">
                ${this._escapeHtml(t)}
                <span
                    class="seomagic-kw-remove"
                    data-index="${i}"
                    style="
                        cursor: pointer;
                        font-size: 10px;
                        opacity: 0.5;
                        line-height: 1;
                        padding: 0 1px;
                        font-weight: 600;
                    "
                    title="Remove"
                >&times;</span>
            </span>
        `).join('');

        const autoSection = this._loading
            ? `<div style="
                    display: flex;
                    align-items: center;
                    gap: 6px;
                    color: var(--muted-foreground, #71717a);
                    font-size: 12px;
                    margin-top: 8px;
                ">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: seomagic-kw-spin 1s linear infinite;">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                    </svg>
                    Loading auto-generated keywords...
                </div>`
            : this._autoKeywords.length > 0
                ? `<div style="margin-top: 8px;">
                        <div style="
                            font-size: 11px;
                            color: var(--muted-foreground, #71717a);
                            margin-bottom: 4px;
                            font-weight: 500;
                            text-transform: uppercase;
                            letter-spacing: 0.5px;
                        ">Auto-Generated</div>
                        <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                            ${this._autoKeywords.map(k => `
                                <span style="
                                    display: inline-flex;
                                    align-items: center;
                                    background: color-mix(in srgb, var(--primary, #3b82f6) 10%, transparent);
                                    color: var(--primary, #3b82f6);
                                    padding: 1px 8px;
                                    border-radius: 6px;
                                    font-size: 11px;
                                    font-weight: 500;
                                    line-height: 1.6;
                                    cursor: default;
                                    white-space: nowrap;
                                ">${this._escapeHtml(k)}</span>
                            `).join('')}
                        </div>
                    </div>`
                : '';

        this.innerHTML = `
            <style>
                @keyframes seomagic-kw-spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
                .seomagic-kw-remove:hover {
                    opacity: 1 !important;
                }
            </style>
            <div>
                <div
                    id="seomagic-kw-container"
                    style="
                        border: 1px solid var(--border, #e4e4e7);
                        border-radius: 6px;
                        padding: 6px 8px;
                        min-height: 38px;
                        display: flex;
                        flex-wrap: wrap;
                        gap: 4px;
                        align-items: center;
                        cursor: text;
                        background: var(--background, #fff);
                        box-sizing: border-box;
                    "
                >
                    ${tagPills}
                    <input
                        id="seomagic-kw-input"
                        type="text"
                        placeholder="${tags.length === 0 ? 'Add keywords...' : ''}"
                        style="
                            border: none;
                            outline: none;
                            flex-grow: 1;
                            min-width: 80px;
                            font-size: 13px;
                            font-family: inherit;
                            background: transparent;
                            color: var(--foreground, #18181b);
                            padding: 2px 0;
                            line-height: 1.5;
                        "
                    />
                </div>
                ${autoSection}
            </div>
        `;

        this._bindEvents();
    }

    _bindEvents() {
        const input = this.querySelector('#seomagic-kw-input');
        const container = this.querySelector('#seomagic-kw-container');

        if (container) {
            container.addEventListener('click', () => {
                input?.focus();
            });
        }

        if (input) {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    const val = input.value.replace(/,/g, '').trim();
                    if (val) {
                        this._addTag(val);
                    }
                }

                if (e.key === 'Backspace' && input.value === '') {
                    const tags = this._getTags();
                    if (tags.length > 0) {
                        this._removeTag(tags.length - 1);
                    }
                }
            });

            // Handle paste of comma-separated values
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasted = (e.clipboardData || window.clipboardData).getData('text');
                const parts = pasted.split(',').map(p => p.trim()).filter(Boolean);
                if (parts.length > 0) {
                    const tags = this._getTags();
                    parts.forEach(p => {
                        if (!tags.some(t => t.toLowerCase() === p.toLowerCase())) {
                            tags.push(p);
                        }
                    });
                    this._setTags(tags);
                }
            });
        }

        this.querySelectorAll('.seomagic-kw-remove').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const index = parseInt(btn.dataset.index, 10);
                this._removeTag(index);
            });
        });
    }
}

customElements.define(TAG, SeomagicKeywords);
