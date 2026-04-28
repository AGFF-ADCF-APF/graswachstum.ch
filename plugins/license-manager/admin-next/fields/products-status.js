/**
 * Products Installation State — custom web component field for license-manager.
 *
 * Displays the installation and enablement status of each licensed product.
 * Read-only display, no value changes emitted.
 */

const TAG = window.__GRAV_FIELD_TAG;

class ProductsStatus extends HTMLElement {
    constructor() {
        super();
        this._value = null;
        this._field = null;
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }

    set value(v) {
        this._value = v;
        // Re-fetch when licenses change
        if (this.isConnected) {
            this._loadStatus();
        }
    }
    get value() { return this._value; }

    connectedCallback() {
        this._render();
        this._loadStatus();
    }

    _render() {
        this.innerHTML = `
            <style>
                .ps-container { font-family: inherit; text-align: left; }
                .ps-loading {
                    padding: 12px;
                    color: var(--muted-foreground, #6b7280);
                    font-size: 13px;
                }
                .ps-empty {
                    padding: 12px;
                    color: var(--muted-foreground, #6b7280);
                    font-size: 13px;
                    font-style: italic;
                }
                .ps-row {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 8px 0;
                    font-size: 13px;
                    border-bottom: 1px solid var(--border, #e5e7eb);
                }
                .ps-row:last-child { border-bottom: none; }
                .ps-icon {
                    width: 20px;
                    height: 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-shrink: 0;
                    font-size: 14px;
                }
                .ps-icon.enabled { color: #16a34a; }
                .ps-icon.disabled { color: #f59e0b; }
                .ps-icon.not-installed { color: #3b82f6; }
                .ps-icon.unknown { color: #ef4444; }
                .ps-label { color: var(--foreground, #1f2937); }
                .ps-slug { font-weight: 600; }
                .ps-type {
                    text-transform: capitalize;
                    color: var(--muted-foreground, #6b7280);
                }
                .ps-link {
                    color: #3b82f6;
                    text-decoration: none;
                    cursor: pointer;
                }
                .ps-link:hover { text-decoration: underline; }
            </style>
            <div class="ps-container">
                <div class="ps-loading">Loading product status...</div>
            </div>
        `;
    }

    async _loadStatus() {
        const baseUrl = window.__GRAV_API_SERVER_URL + (window.__GRAV_API_PREFIX || '/api/v1');
        const token = window.__GRAV_API_TOKEN;
        const headers = {};
        if (token) headers['X-API-Token'] = token;

        try {
            const resp = await fetch(`${baseUrl}/licenses/products-status`, { headers });
            if (!resp.ok) throw new Error(resp.statusText);
            const json = await resp.json();
            const statuses = json.data || [];
            this._renderStatuses(statuses);
        } catch (err) {
            const container = this.querySelector('.ps-container');
            if (container) {
                container.innerHTML = `<div class="ps-empty">Unable to load product status.</div>`;
            }
        }
    }

    _renderStatuses(statuses) {
        const container = this.querySelector('.ps-container');
        if (!container) return;

        if (!statuses.length) {
            container.innerHTML = `<div class="ps-empty">No licensed products found.</div>`;
            return;
        }

        container.innerHTML = statuses.map(item => {
            let iconHtml, statusText;
            const type = item.type || 'package';

            const configHref = type === 'theme' ? `/themes/${item.slug}` : `/plugins/${item.slug}`;

            switch (item.status) {
                case 'enabled':
                    iconHtml = `<span class="ps-icon enabled"><i class="fa-solid fa-check"></i></span>`;
                    statusText = `<span class="ps-type">${type}</span> <a class="ps-link ps-slug" href="${configHref}">${item.slug}</a> is installed and enabled`;
                    break;
                case 'disabled':
                    iconHtml = `<span class="ps-icon disabled"><i class="fa-solid fa-xmark"></i></span>`;
                    statusText = `<span class="ps-type">${type}</span> <a class="ps-link ps-slug" href="${configHref}">${item.slug}</a> is installed but not enabled`;
                    break;
                case 'installed':
                    iconHtml = `<span class="ps-icon enabled"><i class="fa-solid fa-check"></i></span>`;
                    statusText = `<span class="ps-type">${type}</span> <a class="ps-link ps-slug" href="${configHref}">${item.slug}</a> is installed`;
                    break;
                case 'not_installed':
                    iconHtml = `<span class="ps-icon not-installed"><i class="fa-solid fa-plus"></i></span>`;
                    statusText = `<a class="ps-link" href="/plugins?install=${item.slug}">Install <span class="ps-slug">${item.slug}</span></a>`;
                    break;
                default:
                    iconHtml = `<span class="ps-icon unknown"><i class="fa-solid fa-exclamation"></i></span>`;
                    statusText = `<span class="ps-slug">${item.slug}</span> — unknown status`;
            }

            return `<div class="ps-row">${iconHtml}<span class="ps-label">${statusText}</span></div>`;
        }).join('');
    }
}

customElements.define(TAG, ProductsStatus);
