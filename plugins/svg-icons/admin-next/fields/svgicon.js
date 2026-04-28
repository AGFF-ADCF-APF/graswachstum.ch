const TAG = window.__GRAV_FIELD_TAG;

/**
 * admin-next custom field for the `svgicon` field type (svg-icons plugin).
 *
 * Storage format: "<set>/<name>.svg" — e.g. "tabler/arrow-right.svg".
 * Empty string means no icon selected.
 *
 * API endpoints consumed (see classes/Api/SvgIconsController.php):
 *   GET /svg-icons/sets               → { sets: [{id, label, count}, ...] }
 *   GET /svg-icons/icons?set=&q=&...  → { icons: [{name, value}], total, offset, limit, sets? }
 *
 * Icon SVGs are served as static assets from /user/plugins/svg-icons/icons/<set>/<name>.svg.
 */
class SvgIconField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = '';
        this._open = false;
        this._sets = [];
        this._activeSet = 'tabler';
        this._search = '';
        this._items = [];
        this._offset = 0;
        this._limit = 96;
        this._total = 0;
        this._loading = false;
        this._loadingMore = false;
        this._searchTimer = null;
        this._scrollHandler = null;
        this._keyHandler = null;
        this._outsideHandler = null;
    }

    set field(v) { this._field = v; this._render(); }
    get field() { return this._field; }

    set value(v) {
        const next = typeof v === 'string' ? v : '';
        if (next !== this._value) {
            this._value = next;
            this._render();
        }
    }
    get value() { return this._value; }

    connectedCallback() {
        this._render();
    }

    disconnectedCallback() {
        this._teardownModal();
    }

    // ─── API helpers ──────────────────────────────────────────────────────

    _apiBase() {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1');
    }

    _headers() {
        const h = {};
        const token = window.__GRAV_API_TOKEN;
        if (token) h['X-API-Token'] = token;
        return h;
    }

    _iconSrc(path) {
        // Static asset path — cookies/headers not required for public svgs.
        const base = window.__GRAV_API_SERVER_URL || '';
        return `${base}/user/plugins/svg-icons/icons/${path}`;
    }

    async _fetchJSON(url) {
        const resp = await fetch(url, { headers: this._headers() });
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        const json = await resp.json();
        return json.data ?? json;
    }

    async _loadSets() {
        try {
            const data = await this._fetchJSON(`${this._apiBase()}/svg-icons/sets`);
            this._sets = Array.isArray(data.sets) ? data.sets : [];
        } catch (err) {
            console.warn('[svgicon] Failed to load sets:', err.message);
            this._sets = [];
        }
    }

    async _loadIcons({ append = false } = {}) {
        if (append) {
            this._loadingMore = true;
        } else {
            this._loading = true;
            this._offset = 0;
            this._items = [];
        }
        this._renderModal();

        const params = new URLSearchParams({
            set: this._activeSet,
            offset: String(this._offset),
            limit: String(this._limit),
        });
        if (this._search) params.set('q', this._search);

        try {
            const data = await this._fetchJSON(`${this._apiBase()}/svg-icons/icons?${params.toString()}`);
            const incoming = Array.isArray(data.icons) ? data.icons : [];
            this._items = append ? this._items.concat(incoming) : incoming;
            this._total = typeof data.total === 'number' ? data.total : this._items.length;
            this._offset = this._items.length;
            if (!this._sets.length && Array.isArray(data.sets)) {
                this._sets = data.sets;
            }
            if (data.set && data.set !== this._activeSet) {
                // Server may have corrected to a valid set
                this._activeSet = data.set;
            }
        } catch (err) {
            console.warn('[svgicon] Failed to load icons:', err.message);
            if (!append) this._items = [];
        } finally {
            this._loading = false;
            this._loadingMore = false;
            this._renderModal();
        }
    }

    // ─── Value parsing ────────────────────────────────────────────────────

    _parseValue() {
        const raw = (this._value || '').toString();
        if (!raw) return { set: '', name: '', path: '' };
        const file = raw.includes('/') ? raw.split('/').pop() : raw;
        const name = file.endsWith('.svg') ? file.slice(0, -4) : file;
        const set = raw.includes('/') ? raw.slice(0, raw.lastIndexOf('/')) : (this._field?.default_set || 'tabler');
        return { set, name, path: raw };
    }

    _filterAllowedSets(sets) {
        const allowed = this._field?.allowed_sets;
        if (!Array.isArray(allowed) || allowed.length === 0) return sets;
        const allowSet = new Set(allowed);
        return sets.filter((s) => allowSet.has(s.id || s.name || s));
    }

    // ─── Main field render ────────────────────────────────────────────────

    _render() {
        const { path } = this._parseValue();
        const placeholder = this._field?.placeholder || 'No icon selected';
        const chooseLabel = this._field?.choose_label || 'Choose Icon';
        const clearLabel = this._field?.clear_label || 'Clear';

        this.innerHTML = `
            <style>
                :host { display: block; }
                .svgicon-wrap {
                    display: flex;
                    align-items: stretch;
                    gap: 0.5rem;
                    width: 100%;
                }
                .svgicon-preview {
                    flex: 1 1 auto;
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                    padding: 0.5rem 0.75rem;
                    min-height: 2.5rem;
                    border: 1px solid var(--border, hsl(214.3 31.8% 91.4%));
                    border-radius: 0.5rem;
                    background: var(--background, #fff);
                    color: var(--foreground, inherit);
                    font-size: 0.875rem;
                }
                .svgicon-preview.empty {
                    color: var(--muted-foreground, #71717a);
                    font-style: italic;
                }
                .svgicon-mask {
                    display: inline-block;
                    flex: 0 0 auto;
                    background-color: currentColor;
                    -webkit-mask-position: center;
                            mask-position: center;
                    -webkit-mask-repeat: no-repeat;
                            mask-repeat: no-repeat;
                    -webkit-mask-size: contain;
                            mask-size: contain;
                }
                .svgicon-preview .svgicon-mask {
                    width: 1.25rem;
                    height: 1.25rem;
                }
                .svgicon-actions { display: flex; gap: 0.5rem; flex: 0 0 auto; }
                .svgicon-btn {
                    padding: 0 0.875rem;
                    min-height: 2.5rem;
                    font-size: 0.8125rem;
                    font-weight: 500;
                    border: 1px solid var(--border, hsl(214.3 31.8% 91.4%));
                    border-radius: 0.5rem;
                    background: var(--background, #fff);
                    color: var(--foreground, inherit);
                    cursor: pointer;
                    transition: background 0.15s;
                }
                .svgicon-btn:hover:not(:disabled):not(.primary) {
                    background: var(--muted, hsl(240 4.8% 95.9%));
                    border-color: var(--muted-foreground, #71717a);
                }
                .svgicon-btn:disabled { opacity: 0.5; cursor: not-allowed; }
                .svgicon-btn.primary {
                    background: var(--primary, hsl(221 83% 53%));
                    color: var(--primary-foreground, #fff);
                    border-color: transparent;
                }
                .svgicon-btn.primary:hover:not(:disabled) {
                    background: var(--primary, hsl(221 83% 53%));
                    filter: brightness(1.1);
                }
            </style>
            <div class="svgicon-wrap">
                <div class="svgicon-preview ${path ? '' : 'empty'}" part="preview">
                    ${path
                        ? `<span class="svgicon-mask" style="-webkit-mask-image: url('${this._iconSrc(path)}'); mask-image: url('${this._iconSrc(path)}');" aria-hidden="true"></span><span>${this._escape(path)}</span>`
                        : `<span>${this._escape(placeholder)}</span>`}
                </div>
                <div class="svgicon-actions">
                    <button type="button" class="svgicon-btn primary" data-action="choose">${this._escape(chooseLabel)}</button>
                    <button type="button" class="svgicon-btn" data-action="clear" ${path ? '' : 'disabled'}>${this._escape(clearLabel)}</button>
                </div>
            </div>
        `;

        this.querySelector('[data-action="choose"]')?.addEventListener('click', () => this._openModal());
        this.querySelector('[data-action="clear"]')?.addEventListener('click', () => this._setValue(''));
    }

    // ─── Modal ────────────────────────────────────────────────────────────

    async _openModal() {
        if (this._open) return;
        this._open = true;

        // Seed active set from current value (falling back to blueprint default)
        const { set } = this._parseValue();
        if (set) this._activeSet = set;
        else if (this._field?.default_set) this._activeSet = this._field.default_set;

        this._modal = document.createElement('div');
        this._modal.className = 'svgicon-modal-root';
        this._modal.setAttribute('data-svgicon-modal', '');
        this._modal.innerHTML = this._modalShell();
        document.body.appendChild(this._modal);

        this._keyHandler = (e) => {
            if (e.key === 'Escape') this._closeModal();
        };
        this._outsideHandler = (e) => {
            const backdrop = this._modal?.querySelector('.svgicon-modal-backdrop');
            if (e.target === backdrop) this._closeModal();
        };
        document.addEventListener('keydown', this._keyHandler);
        this._modal.addEventListener('click', this._outsideHandler);

        await this._loadSets();
        await this._loadIcons();
        // Focus search input after first paint
        setTimeout(() => {
            this._modal?.querySelector('.svgicon-search-input')?.focus();
        }, 0);
    }

    _closeModal() {
        if (!this._open) return;
        this._open = false;
        this._teardownModal();
    }

    _teardownModal() {
        if (this._keyHandler) {
            document.removeEventListener('keydown', this._keyHandler);
            this._keyHandler = null;
        }
        if (this._modal) {
            this._modal.remove();
            this._modal = null;
        }
        if (this._searchTimer) {
            clearTimeout(this._searchTimer);
            this._searchTimer = null;
        }
    }

    _renderModal() {
        if (!this._modal) return;
        const body = this._modal.querySelector('.svgicon-modal-body');
        if (body) body.innerHTML = this._modalBody();
        const tabs = this._modal.querySelector('.svgicon-set-tabs');
        if (tabs) tabs.innerHTML = this._modalTabs();
        this._wireModalEvents();
    }

    _modalShell() {
        return `
            <style>
                .svgicon-modal-backdrop {
                    position: fixed; inset: 0;
                    background: rgba(0, 0, 0, 0.5);
                    display: flex; align-items: center; justify-content: center;
                    z-index: 9999;
                    animation: svgicon-fade-in 0.15s ease-out;
                }
                @keyframes svgicon-fade-in { from { opacity: 0; } to { opacity: 1; } }
                .svgicon-modal {
                    background: var(--background, #fff);
                    color: var(--foreground, inherit);
                    border-radius: 0.75rem;
                    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.25);
                    width: min(900px, 92vw);
                    max-height: 85vh;
                    display: flex; flex-direction: column;
                    overflow: hidden;
                    border: 1px solid var(--border, hsl(214.3 31.8% 91.4%));
                }
                .svgicon-modal-header {
                    display: flex; align-items: center; justify-content: space-between;
                    padding: 1rem 1.25rem;
                    border-bottom: 1px solid var(--border, hsl(214.3 31.8% 91.4%));
                }
                .svgicon-modal-header h3 {
                    margin: 0; font-size: 1rem; font-weight: 600;
                }
                .svgicon-modal-close {
                    background: transparent; border: 0; cursor: pointer;
                    padding: 0.25rem 0.5rem; font-size: 1.25rem; line-height: 1;
                    color: var(--muted-foreground, #71717a); border-radius: 0.25rem;
                }
                .svgicon-modal-close:hover {
                    background: var(--accent, hsl(210 40% 96%));
                    color: var(--foreground, inherit);
                }
                .svgicon-modal-toolbar {
                    padding: 0.75rem 1.25rem;
                    border-bottom: 1px solid var(--border, hsl(214.3 31.8% 91.4%));
                    display: flex; flex-direction: column; gap: 0.75rem;
                }
                .svgicon-search-input {
                    width: 100%;
                    padding: 0.5rem 0.75rem;
                    font-size: 0.875rem;
                    border: 1px solid var(--border, hsl(214.3 31.8% 91.4%));
                    border-radius: 0.5rem;
                    background: var(--background, #fff);
                    color: var(--foreground, inherit);
                    box-sizing: border-box;
                }
                .svgicon-search-input:focus {
                    outline: 2px solid var(--ring, hsl(222.2 47.4% 11.2%));
                    outline-offset: 1px;
                }
                .svgicon-set-tabs {
                    display: flex; flex-wrap: wrap; gap: 0.375rem;
                }
                .svgicon-set-tab {
                    padding: 0.375rem 0.75rem;
                    font-size: 0.8125rem; font-weight: 500;
                    border: 1px solid var(--border, hsl(214.3 31.8% 91.4%));
                    border-radius: 9999px;
                    background: var(--background, #fff);
                    color: var(--foreground, inherit);
                    cursor: pointer;
                    transition: all 0.15s;
                    white-space: nowrap;
                }
                .svgicon-set-tab:hover { background: var(--accent, hsl(210 40% 96%)); }
                .svgicon-set-tab.active {
                    background: var(--primary, hsl(222.2 47.4% 11.2%));
                    color: var(--primary-foreground, #fff);
                    border-color: transparent;
                }
                .svgicon-set-tab .count {
                    margin-left: 0.375rem; opacity: 0.7; font-weight: 400;
                }
                .svgicon-modal-body {
                    flex: 1 1 auto;
                    overflow-y: auto;
                    padding: 1rem 1.25rem;
                }
                .svgicon-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
                    gap: 0.5rem;
                }
                .svgicon-cell {
                    display: flex; flex-direction: column; align-items: center;
                    gap: 0.375rem;
                    padding: 0.625rem 0.375rem;
                    border: 1px solid var(--border, hsl(214.3 31.8% 91.4%));
                    border-radius: 0.5rem;
                    background: transparent;
                    color: var(--foreground, inherit);
                    cursor: pointer;
                    transition: background-color 0.12s, border-color 0.12s, box-shadow 0.12s;
                    min-height: 72px;
                    position: relative;
                }
                .svgicon-cell:hover {
                    background: var(--muted, hsl(240 4.8% 95.9%));
                    border-color: var(--muted-foreground, #71717a);
                }
                .svgicon-cell.selected {
                    background: var(--muted, hsl(240 4.8% 95.9%));
                    border-color: var(--primary, hsl(221 83% 53%));
                    box-shadow: 0 0 0 2px var(--primary, hsl(221 83% 53%));
                }
                .svgicon-mask {
                    display: inline-block;
                    flex: 0 0 auto;
                    background-color: currentColor;
                    -webkit-mask-position: center;
                            mask-position: center;
                    -webkit-mask-repeat: no-repeat;
                            mask-repeat: no-repeat;
                    -webkit-mask-size: contain;
                            mask-size: contain;
                }
                .svgicon-cell .svgicon-mask {
                    width: 1.5rem;
                    height: 1.5rem;
                }
                .svgicon-cell .name {
                    font-size: 0.6875rem;
                    line-height: 1.2;
                    text-align: center;
                    word-break: break-word;
                    color: var(--muted-foreground, #71717a);
                }
                .svgicon-empty, .svgicon-loading {
                    padding: 3rem 1rem;
                    text-align: center;
                    color: var(--muted-foreground, #71717a);
                    font-size: 0.875rem;
                }
                .svgicon-more {
                    text-align: center;
                    padding: 0.75rem 0;
                    color: var(--muted-foreground, #71717a);
                    font-size: 0.8125rem;
                }
            </style>
            <div class="svgicon-modal-backdrop">
                <div class="svgicon-modal" role="dialog" aria-modal="true" aria-label="Choose SVG icon">
                    <div class="svgicon-modal-header">
                        <h3>Choose Icon</h3>
                        <button type="button" class="svgicon-modal-close" aria-label="Close">&times;</button>
                    </div>
                    <div class="svgicon-modal-toolbar">
                        <input type="search" class="svgicon-search-input" placeholder="Search icons..." autocomplete="off" value="${this._escape(this._search)}" />
                        <div class="svgicon-set-tabs">${this._modalTabs()}</div>
                    </div>
                    <div class="svgicon-modal-body">${this._modalBody()}</div>
                </div>
            </div>
        `;
    }

    _modalTabs() {
        const sets = this._filterAllowedSets(this._sets);
        if (!sets.length) return '';
        return sets.map((s) => {
            const id = s.id || s.name || s;
            const label = s.label || s.title || this._formatSetName(id);
            const count = s.count != null ? `<span class="count">${s.count}</span>` : '';
            const active = id === this._activeSet ? ' active' : '';
            return `<button type="button" class="svgicon-set-tab${active}" data-set="${this._escape(id)}">${this._escape(label)}${count}</button>`;
        }).join('');
    }

    _modalBody() {
        if (this._loading && !this._items.length) {
            return `<div class="svgicon-loading">Loading icons…</div>`;
        }
        if (!this._items.length) {
            return `<div class="svgicon-empty">${this._search ? 'No icons match your search.' : 'No icons in this set.'}</div>`;
        }
        const { path: currentPath } = this._parseValue();
        const cells = this._items.map((item) => {
            const selected = item.value === currentPath ? ' selected' : '';
            const src = this._iconSrc(item.value);
            return `
                <button type="button" class="svgicon-cell${selected}" data-value="${this._escape(item.value)}" title="${this._escape(item.name)}">
                    <span class="svgicon-mask" style="-webkit-mask-image: url('${src}'); mask-image: url('${src}');" aria-hidden="true"></span>
                    <span class="name">${this._escape(item.name)}</span>
                </button>
            `;
        }).join('');
        const more = this._items.length < this._total
            ? `<div class="svgicon-more">${this._loadingMore ? 'Loading…' : `Showing ${this._items.length} of ${this._total}`}</div>`
            : '';
        return `<div class="svgicon-grid">${cells}</div>${more}`;
    }

    _wireModalEvents() {
        if (!this._modal) return;

        this._modal.querySelector('.svgicon-modal-close')
            ?.addEventListener('click', () => this._closeModal());

        const searchInput = this._modal.querySelector('.svgicon-search-input');
        if (searchInput && !searchInput.__wired) {
            searchInput.__wired = true;
            searchInput.addEventListener('input', (e) => {
                const q = e.target.value;
                if (this._searchTimer) clearTimeout(this._searchTimer);
                this._searchTimer = setTimeout(() => {
                    this._search = q;
                    this._loadIcons();
                }, 200);
            });
        }

        this._modal.querySelectorAll('.svgicon-set-tab').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-set');
                if (id && id !== this._activeSet) {
                    this._activeSet = id;
                    this._loadIcons();
                }
            });
        });

        this._modal.querySelectorAll('.svgicon-cell').forEach((btn) => {
            btn.addEventListener('click', () => {
                const value = btn.getAttribute('data-value') || '';
                this._setValue(value);
                this._closeModal();
            });
        });

        // Infinite scroll — wire once to the modal body
        const body = this._modal.querySelector('.svgicon-modal-body');
        if (body && !body.__scrollWired) {
            body.__scrollWired = true;
            body.addEventListener('scroll', () => {
                if (this._loading || this._loadingMore) return;
                if (this._items.length >= this._total) return;
                const threshold = 200;
                if (body.scrollTop + body.clientHeight + threshold >= body.scrollHeight) {
                    this._loadIcons({ append: true });
                }
            });
        }
    }

    // ─── Value dispatch ──────────────────────────────────────────────────

    _setValue(next) {
        if (next === this._value) return;
        this._value = next;
        this._render();
        this.dispatchEvent(new CustomEvent('change', {
            detail: next,
            bubbles: true,
        }));
    }

    // ─── Utils ────────────────────────────────────────────────────────────

    _escape(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    _formatSetName(id) {
        return String(id)
            .replace(/[/_-]+/g, ' ')
            .replace(/\b\w/g, (c) => c.toUpperCase());
    }
}

customElements.define(TAG, SvgIconField);
