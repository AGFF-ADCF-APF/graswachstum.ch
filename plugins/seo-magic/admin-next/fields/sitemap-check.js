const TAG = window.__GRAV_FIELD_TAG;

class SitemapCheck extends HTMLElement {
    _field = null;
    _value = null;
    _status = null;

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
        this._checkStatus();
    }

    async _checkStatus() {
        const baseUrl = window.__GRAV_API_SERVER_URL + (window.__GRAV_API_PREFIX || '/api/v1');
        const token = window.__GRAV_API_TOKEN;
        const headers = { 'Content-Type': 'application/json' };
        if (token) headers['X-API-Token'] = token;

        try {
            const res = await fetch(`${baseUrl}/seo-magic/sitemap-status`, { headers });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            this._status = json.data;
        } catch (e) {
            this._status = { installed: false, enabled: false, error: e.message };
        }

        this._render();
    }

    _render() {
        if (!this._status) {
            this.innerHTML = `
                <div style="
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    padding: 8px 0;
                    color: var(--muted-foreground, #71717a);
                    font-size: 13px;
                ">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: seomagic-spin 1s linear infinite;">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                    </svg>
                    Checking sitemap status...
                </div>
                <style>
                    @keyframes seomagic-spin {
                        from { transform: rotate(0deg); }
                        to { transform: rotate(360deg); }
                    }
                </style>
            `;
            return;
        }

        const ok = this._status.installed && this._status.enabled;

        if (ok) {
            this.innerHTML = `
                <div style="
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 4px 10px;
                    border-radius: 6px;
                    background: color-mix(in srgb, #22c55e 12%, transparent);
                    color: #16a34a;
                    font-size: 13px;
                    font-weight: 500;
                ">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"/>
                    </svg>
                    Sitemap OK
                </div>
            `;
            return;
        }

        this.innerHTML = `
            <div style="
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 12px 16px;
                border-radius: 8px;
                background: var(--destructive, #ef4444);
                color: #fff;
                font-size: 13px;
                line-height: 1.5;
            ">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;">
                    <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
                    <line x1="12" x2="12" y1="9" y2="13"/>
                    <line x1="12" x2="12.01" y1="17" y2="17"/>
                </svg>
                <span>Sitemap plugin is not installed or disabled. SEO Magic requires sitemap to be functional and accurate.</span>
            </div>
        `;
    }
}

customElements.define(TAG, SitemapCheck);
