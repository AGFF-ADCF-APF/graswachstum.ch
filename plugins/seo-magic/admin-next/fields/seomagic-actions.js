const TAG = window.__GRAV_FIELD_TAG;

class SeomagicActions extends HTMLElement {
    _field = null;
    _value = null;
    _regenerating = false;
    _deleting = false;
    _message = null;

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
    }

    _getApiConfig() {
        const baseUrl = window.__GRAV_API_SERVER_URL + (window.__GRAV_API_PREFIX || '/api/v1');
        const token = window.__GRAV_API_TOKEN;
        const headers = { 'Content-Type': 'application/json' };
        if (token) headers['X-API-Token'] = token;
        return { baseUrl, headers };
    }

    async _regenerate() {
        if (this._regenerating) return;

        this._regenerating = true;
        this._message = null;
        this._render();

        const { baseUrl, headers } = this._getApiConfig();

        try {
            const res = await fetch(`${baseUrl}/seo-magic/crawl`, {
                method: 'POST',
                headers,
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            // Brief poll to confirm the crawl started
            await new Promise(r => setTimeout(r, 1000));
            const statusRes = await fetch(`${baseUrl}/seo-magic/status`, { headers });
            if (statusRes.ok) {
                const statusJson = await statusRes.json();
                this._message = { type: 'success', text: 'SEO data regeneration started successfully.' };
            } else {
                this._message = { type: 'success', text: 'Regeneration request sent.' };
            }
        } catch (e) {
            this._message = { type: 'error', text: `Failed to regenerate: ${e.message}` };
        }

        this._regenerating = false;
        this._render();
    }

    async _delete() {
        if (this._deleting) return;

        const confirmed = await (window.__GRAV_DIALOGS?.confirm({
            title: 'Delete all SEO data?',
            message: 'This will permanently delete every generated SEO entry. This cannot be undone.',
            confirmLabel: 'Delete SEO data',
            variant: 'destructive',
        }) ?? Promise.resolve(window.confirm('Are you sure? This will delete all SEO data.')));
        if (!confirmed) return;

        this._deleting = true;
        this._message = null;
        this._render();

        const { baseUrl, headers } = this._getApiConfig();

        try {
            const res = await fetch(`${baseUrl}/seo-magic/delete-data`, {
                method: 'POST',
                headers,
            });

            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            this._message = { type: 'success', text: 'All SEO data has been deleted.' };
        } catch (e) {
            this._message = { type: 'error', text: `Failed to delete: ${e.message}` };
        }

        this._deleting = false;
        this._render();
    }

    _render() {
        const spinnerSvg = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation: seomagic-actions-spin 1s linear infinite;">
                <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
            </svg>
        `;

        const regenerateIcon = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                <path d="m15 5 4 4"/>
            </svg>
        `;

        const deleteIcon = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 6h18"/>
                <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
                <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
                <line x1="10" x2="10" y1="11" y2="17"/>
                <line x1="14" x2="14" y1="11" y2="17"/>
            </svg>
        `;

        let messageHtml = '';
        if (this._message) {
            const isError = this._message.type === 'error';
            const bgColor = isError
                ? 'color-mix(in srgb, var(--destructive, #ef4444) 12%, transparent)'
                : 'color-mix(in srgb, #22c55e 12%, transparent)';
            const textColor = isError ? 'var(--destructive, #ef4444)' : '#16a34a';
            const iconPath = isError
                ? '<circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/>'
                : '<path d="M20 6 9 17l-5-5"/>';

            messageHtml = `
                <div style="
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    margin-top: 10px;
                    padding: 8px 12px;
                    border-radius: 6px;
                    background: ${bgColor};
                    color: ${textColor};
                    font-size: 13px;
                    line-height: 1.4;
                ">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;">
                        ${iconPath}
                    </svg>
                    ${this._message.text}
                </div>
            `;
        }

        this.innerHTML = `
            <style>
                @keyframes seomagic-actions-spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
            </style>
            <div style="display: flex; flex-direction: column;">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <button
                        id="seomagic-regenerate-btn"
                        ${this._regenerating ? 'disabled' : ''}
                        style="
                            display: inline-flex;
                            align-items: center;
                            gap: 6px;
                            padding: 8px 16px;
                            border-radius: 8px;
                            border: none;
                            background: var(--primary, #3b82f6);
                            color: #fff;
                            font-size: 13px;
                            font-weight: 500;
                            cursor: ${this._regenerating ? 'not-allowed' : 'pointer'};
                            opacity: ${this._regenerating ? '0.7' : '1'};
                            transition: opacity 0.15s ease;
                            font-family: inherit;
                        "
                    >
                        ${this._regenerating ? spinnerSvg : regenerateIcon}
                        ${this._regenerating ? 'Regenerating...' : 'Regenerate SEO Data'}
                    </button>
                    <button
                        id="seomagic-delete-btn"
                        ${this._deleting ? 'disabled' : ''}
                        style="
                            display: inline-flex;
                            align-items: center;
                            gap: 6px;
                            padding: 8px 16px;
                            border-radius: 8px;
                            border: none;
                            background: var(--destructive, #ef4444);
                            color: #fff;
                            font-size: 13px;
                            font-weight: 500;
                            cursor: ${this._deleting ? 'not-allowed' : 'pointer'};
                            opacity: ${this._deleting ? '0.7' : '1'};
                            transition: opacity 0.15s ease;
                            font-family: inherit;
                        "
                    >
                        ${this._deleting ? spinnerSvg : deleteIcon}
                        ${this._deleting ? 'Deleting...' : 'Delete SEO Data'}
                    </button>
                </div>
                ${messageHtml}
            </div>
        `;

        this.querySelector('#seomagic-regenerate-btn')
            ?.addEventListener('click', () => this._regenerate());
        this.querySelector('#seomagic-delete-btn')
            ?.addEventListener('click', () => this._delete());
    }
}

customElements.define(TAG, SeomagicActions);
