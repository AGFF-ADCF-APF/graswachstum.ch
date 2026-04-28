const TAG = window.__GRAV_PANEL_TAG;

class RevisionsProPanel extends HTMLElement {
	static get observedAttributes() {
		return ['route', 'lang', 'type'];
	}

	constructor() {
		super();
		this.attachShadow({ mode: 'open' });
		this._route = '';
		this._lang = '';
		this._type = 'page';
		this._revisions = [];
		this._view = 'list'; // 'list' | 'preview' | 'compare' | 'trash'
		this._selectedId = null;
		this._previewData = null;
		this._diffData = null;
		this._loading = false;
		this._error = null;
		this._confirmAction = null; // { type: 'restore'|'delete', id, date }
		this._compareDirection = 'current'; // 'current' | 'previous' | 'next'
		// Plugin config (fetched on connect)
		this._config = {
			compare_mode: 'current',
			show_revision_count: true,
			enable_trash: true,
			trash_count: 0,
		};
		this._configLoaded = false;
		// Trash state
		this._trashItems = [];
		this._trashError = null;
		this._trashRestoreItem = null; // item currently being restored (opens dialog)
		this._trashRestoreOptions = { mode: 'original', overwrite: false, parent_route: '', slug: '', folder_name: '' };
		this._trashConfirmAction = null; // { type: 'delete'|'empty', id?, title? }
	}

	connectedCallback() {
		this._route = this.getAttribute('route') || '';
		this._lang = this.getAttribute('lang') || '';
		this._type = this.getAttribute('type') || 'page';
		this._render();
		// Fetch config first so compare_mode and enable_trash are available
		this._fetchConfig().then(() => {
			if (this._route && this._view !== 'trash') this._fetchRevisions();
		});
	}

	attributeChangedCallback(name, oldVal, newVal) {
		if (oldVal === newVal) return;
		if (name === 'route') this._route = newVal || '';
		if (name === 'lang') this._lang = newVal || '';
		if (name === 'type') this._type = newVal || 'page';
		// Skip fetch if not yet connected (connectedCallback handles initial load)
		if (!this.isConnected) return;
		// If in trash view, stay there — trash is context-independent
		if (this._view === 'trash') return;
		// Reset view and refetch
		this._view = 'list';
		this._selectedId = null;
		this._previewData = null;
		this._diffData = null;
		this._error = null;
		this._confirmAction = null;
		if (this._route) this._fetchRevisions();
		else this._render();
	}

	// ─── API helpers ─────────────────────────────

	_apiUrl(path) {
		return (window.__GRAV_API_SERVER_URL || '') +
			(window.__GRAV_API_PREFIX || '/api/v1') + path;
	}

	_headers(json = false) {
		const h = {};
		const token = window.__GRAV_API_TOKEN;
		if (token) h['Authorization'] = `Bearer ${token}`;
		if (json) h['Content-Type'] = 'application/json';
		return h;
	}

	async _api(method, path, body) {
		const opts = { method, headers: this._headers(!!body) };
		if (body) opts.body = JSON.stringify(body);
		const resp = await fetch(this._apiUrl(path), opts);
		const json = await resp.json();
		if (!resp.ok) throw new Error(json.detail || json.message || 'Request failed');
		return json.data ?? json;
	}

	// ─── Data fetching ───────────────────────────

	async _fetchConfig() {
		try {
			const cfg = await this._api('GET', '/revisions-pro/config');
			this._config = { ...this._config, ...cfg };
			// Seed the default compare direction from user config
			this._compareDirection = this._config.compare_mode || 'current';
		} catch (e) {
			// Non-fatal — fall back to defaults
			console.warn('[RevisionsPro] Failed to load config:', e.message);
		}
		this._configLoaded = true;
		this._render();
	}

	async _fetchRevisions() {
		this._loading = true;
		this._error = null;
		this._render();
		try {
			const params = `?route=${encodeURIComponent(this._route)}&lang=${encodeURIComponent(this._lang)}&type=${encodeURIComponent(this._type)}`;
			this._revisions = await this._api('GET', `/revisions-pro/revisions${params}`);
			this._emitBadge(this._revisions.length);
		} catch (e) {
			this._error = e.message;
			this._revisions = [];
		}
		this._loading = false;
		this._render();
	}

	async _fetchPreview(id) {
		this._loading = true;
		this._render();
		try {
			this._previewData = await this._api('GET', `/revisions-pro/revisions/${id}`);
			this._selectedId = id;
			this._view = 'preview';
		} catch (e) {
			this._error = e.message;
		}
		this._loading = false;
		this._render();
	}

	async _fetchDiff(id, compare = 'current') {
		this._loading = true;
		this._compareDirection = compare;
		this._render();
		try {
			this._diffData = await this._api('GET', `/revisions-pro/revisions/${id}/diff?compare=${encodeURIComponent(compare)}`);
			this._selectedId = id;
			this._view = 'compare';
		} catch (e) {
			this._error = e.message;
		}
		this._loading = false;
		this._render();
	}

	async _restoreRevision(id) {
		this._loading = true;
		this._confirmAction = null;
		this._render();
		try {
			await this._api('POST', `/revisions-pro/revisions/${id}/restore`);
			window.dispatchEvent(new Event('grav:revisions:changed'));
			this._view = 'list';
			this._selectedId = null;
			this._previewData = null;
			this._diffData = null;
			await this._fetchRevisions();
		} catch (e) {
			this._error = e.message;
			this._loading = false;
			this._render();
		}
	}

	async _deleteRevision(id) {
		this._loading = true;
		this._confirmAction = null;
		this._render();
		try {
			await this._api('DELETE', `/revisions-pro/revisions/${id}`);
			window.dispatchEvent(new Event('grav:revisions:changed'));
			this._view = 'list';
			this._selectedId = null;
			this._previewData = null;
			this._diffData = null;
			await this._fetchRevisions();
		} catch (e) {
			this._error = e.message;
			this._loading = false;
			this._render();
		}
	}

	// ─── Trash ───────────────────────────────────

	_openTrash() {
		this._view = 'trash';
		this._trashError = null;
		this._trashRestoreItem = null;
		this._trashConfirmAction = null;
		this._fetchTrashItems();
	}

	_closeTrash() {
		this._view = 'list';
		this._trashItems = [];
		this._trashError = null;
		this._trashRestoreItem = null;
		this._trashConfirmAction = null;
		// Refetch revisions since we left the view
		if (this._route) this._fetchRevisions();
		else this._render();
	}

	async _fetchTrashItems() {
		this._loading = true;
		this._trashError = null;
		this._render();
		try {
			this._trashItems = await this._api('GET', '/revisions-pro/trash');
			this._config.trash_count = this._trashItems.length;
		} catch (e) {
			this._trashError = e.message;
			this._trashItems = [];
		}
		this._loading = false;
		this._render();
	}

	async _restoreTrashItem(id, options) {
		this._loading = true;
		this._trashRestoreItem = null;
		this._render();
		try {
			const result = await this._api('POST', `/revisions-pro/trash/${id}/restore`, options);
			this._config.trash_count = result.count ?? 0;
			window.dispatchEvent(new Event('grav:revisions:changed'));
			window.dispatchEvent(new Event('grav:trash:changed'));
			await this._fetchTrashItems();
		} catch (e) {
			this._trashError = e.message;
			this._loading = false;
			this._render();
		}
	}

	async _deleteTrashItem(id) {
		this._loading = true;
		this._trashConfirmAction = null;
		this._render();
		try {
			const result = await this._api('DELETE', `/revisions-pro/trash/${id}`);
			this._config.trash_count = result.count ?? 0;
			window.dispatchEvent(new Event('grav:trash:changed'));
			await this._fetchTrashItems();
		} catch (e) {
			this._trashError = e.message;
			this._loading = false;
			this._render();
		}
	}

	async _emptyTrash() {
		this._loading = true;
		this._trashConfirmAction = null;
		this._render();
		try {
			const result = await this._api('DELETE', '/revisions-pro/trash');
			this._config.trash_count = result.count ?? 0;
			window.dispatchEvent(new Event('grav:trash:changed'));
			await this._fetchTrashItems();
		} catch (e) {
			this._trashError = e.message;
			this._loading = false;
			this._render();
		}
	}

	// ─── Events ──────────────────────────────────

	_close() {
		this.dispatchEvent(new CustomEvent('close'));
	}

	_emitBadge(count) {
		// Respect the show_revision_count setting — always emit 0 when disabled
		// so the host clears any existing badge.
		const effective = this._config.show_revision_count ? count : 0;
		this.dispatchEvent(new CustomEvent('badge', { detail: { count: effective } }));
	}

	// ─── Formatting ──────────────────────────────

	_formatDate(dateStr) {
		try {
			const d = new Date(dateStr.replace(' ', 'T'));
			return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
				' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
		} catch {
			return dateStr;
		}
	}

	_formatTimestamp(ts) {
		return this._formatDate(new Date(ts * 1000).toISOString());
	}

	// ─── Rendering ───────────────────────────────

	_render() {
		try { this._doRender(); } catch(e) {
			console.error('[RevisionsPro] Render error:', e);
			this.shadowRoot.innerHTML = `<div style="padding:20px;color:#ef4444;">Render error: ${e.message}</div>`;
		}
	}

	_doRender() {
		const s = this.shadowRoot;
		s.innerHTML = '';

		const style = document.createElement('style');
		style.textContent = this._styles();
		s.appendChild(style);

		// Trash view takes over the entire panel
		if (this._view === 'trash') {
			const container = document.createElement('div');
			container.className = 'panel-container';
			container.appendChild(this._renderTrash());
			s.appendChild(container);

			this.dispatchEvent(new CustomEvent('resize', { detail: { width: 560 } }));

			if (this._trashRestoreItem) {
				s.appendChild(this._renderTrashRestoreDialog());
			}
			if (this._trashConfirmAction) {
				s.appendChild(this._renderTrashConfirm());
			}
			return;
		}

		const hasDetail = this._view === 'preview' || this._view === 'compare';

		const container = document.createElement('div');
		container.className = 'panel-container' + (hasDetail ? ' has-detail' : '');

		// Detail panel on the LEFT (only when active)
		if (hasDetail) {
			const detailPanel = this._view === 'preview'
				? this._renderPreview()
				: this._renderCompare();
			container.appendChild(detailPanel);
		}

		// History list on the RIGHT (always visible, flush against window edge)
		const historyPanel = this._renderHistory();
		container.appendChild(historyPanel);

		s.appendChild(container);

		// Tell the host to resize the panel
		this.dispatchEvent(new CustomEvent('resize', {
			detail: { width: hasDetail ? 920 : 380 }
		}));

		// Confirm dialog overlay
		if (this._confirmAction) {
			s.appendChild(this._renderConfirm());
		}
	}

	_renderHistory() {
		const panel = document.createElement('div');
		panel.className = 'history-panel';

		// Header
		const header = document.createElement('div');
		header.className = 'panel-header';
		const trashBtnHtml = this._config.enable_trash ? `
			<button class="btn-icon btn-trash-toggle" title="Page Trash">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
				${this._config.trash_count > 0 ? `<span class="trash-count-badge">${this._config.trash_count}</span>` : ''}
			</button>
		` : '';
		header.innerHTML = `
			<div class="header-title">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>
				<span>Revision History</span>
			</div>
			<div class="header-actions">
				${trashBtnHtml}
				<button class="close-btn" title="Close">&times;</button>
			</div>
		`;
		header.querySelector('.close-btn').addEventListener('click', () => this._close());
		const trashToggle = header.querySelector('.btn-trash-toggle');
		if (trashToggle) {
			trashToggle.addEventListener('click', () => this._openTrash());
		}
		panel.appendChild(header);

		// Content
		const content = document.createElement('div');
		content.className = 'history-content';

		if (this._loading && this._revisions.length === 0) {
			content.innerHTML = '<div class="loading">Loading revisions...</div>';
		} else if (this._error && this._revisions.length === 0) {
			content.innerHTML = `<div class="error">${this._escHtml(this._error)}</div>`;
		} else if (this._revisions.length === 0) {
			content.innerHTML = '<div class="empty">No revisions found for this page.</div>';
		} else {
			const total = this._revisions.length;
			this._revisions.forEach((rev, idx) => {
				const revNum = total - idx;
				const card = document.createElement('div');
				card.className = 'revision-card' + (this._selectedId === rev.id ? ' selected' : '');

				const isFirst = idx === 0;

				card.innerHTML = `
					<div class="rev-row">
						<div class="rev-left">
							<span class="rev-badge">${revNum}</span>
							<div class="rev-info">
								<span class="rev-date">${this._escHtml(this._formatDate(rev.date))}${isFirst ? ' <span class="rev-current" title="Current version">&#9733;</span>' : ''}</span>
								<span class="rev-user">${this._escHtml(rev.user)}</span>
							</div>
						</div>
						<div class="rev-actions">
							<button class="btn-icon btn-view" title="View">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
							</button>
							<button class="btn-icon btn-compare" title="Compare">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/></svg>
							</button>
							<button class="btn-icon btn-restore" title="Restore" ${isFirst ? 'disabled' : ''}>
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
							</button>
							<button class="btn-icon btn-delete" title="Delete">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
							</button>
						</div>
					</div>
				`;

				// Wire up action buttons
				card.querySelector('.btn-view').addEventListener('click', () => this._fetchPreview(rev.id));
				card.querySelector('.btn-compare').addEventListener('click', () => {
					// Respect the user's configured compare_mode preference.
					// The "current" revision can't compare against itself — force 'previous' in that case.
					let mode = this._config.compare_mode || 'current';
					if (isFirst && mode === 'current') mode = 'previous';
					// If compare_mode is 'next' but we're at the oldest revision, fall back to 'previous'
					if (mode === 'next' && idx === total - 1) mode = 'previous';
					this._fetchDiff(rev.id, mode);
				});
				const restoreBtn = card.querySelector('.btn-restore');
				if (!isFirst) {
					restoreBtn.addEventListener('click', () => {
						this._confirmAction = { type: 'restore', id: rev.id, date: rev.date };
						this._render();
					});
				}
				card.querySelector('.btn-delete').addEventListener('click', () => {
					this._confirmAction = { type: 'delete', id: rev.id, date: rev.date };
					this._render();
				});

				content.appendChild(card);
			});
		}

		panel.appendChild(content);
		return panel;
	}

	_renderPreview() {
		const panel = document.createElement('div');
		panel.className = 'detail-panel';

		const data = this._previewData;
		if (!data) return panel;

		// Header
		const header = document.createElement('div');
		header.className = 'panel-header detail-header';
		header.innerHTML = `
			<div class="header-title">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
				<span>Revision Preview</span>
			</div>
			<button class="close-btn" title="Close preview">&times;</button>
		`;
		header.querySelector('.close-btn').addEventListener('click', () => {
			this._view = 'list';
			this._selectedId = null;
			this._previewData = null;
			this._render();
		});
		panel.appendChild(header);

		// File info bar
		const info = document.createElement('div');
		info.className = 'info-bar';
		info.innerHTML = `
			<div class="info-left">
				<span class="info-filename">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
					${this._escHtml(data.filename || 'unknown')}
				</span>
			</div>
			<div class="info-right">
				<span class="info-date">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
					${this._escHtml(this._formatDate(data.date))}
				</span>
				<span class="info-user">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
					${this._escHtml(data.user)}
				</span>
				<button class="btn btn-outline btn-copy" title="Copy to clipboard">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
					Copy
				</button>
			</div>
		`;
		info.querySelector('.btn-copy').addEventListener('click', (e) => {
			navigator.clipboard.writeText(data.content || '').then(() => {
				const btn = e.currentTarget;
				btn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Copied`;
				setTimeout(() => {
					btn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy`;
				}, 2000);
			});
		});
		panel.appendChild(info);

		// Content area with line numbers
		const content = document.createElement('div');
		content.className = 'preview-content';
		const lines = (data.content || '').split('\n');
		content.innerHTML = lines.map((line, i) =>
			`<div class="preview-line"><span class="line-num">${i + 1}</span><span class="line-text">${this._escHtml(line) || ' '}</span></div>`
		).join('');
		panel.appendChild(content);

		return panel;
	}

	_renderCompare() {
		const panel = document.createElement('div');
		panel.className = 'detail-panel';

		const data = this._diffData;
		if (!data) return panel;

		const rev = data.revision;
		const selectedIdx = this._revisions.findIndex(r => r.id === this._selectedId);
		const isFirst = selectedIdx === 0;
		const isLast = selectedIdx === this._revisions.length - 1;

		// Header
		const header = document.createElement('div');
		header.className = 'panel-header detail-header';
		header.innerHTML = `
			<div class="header-title">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/></svg>
				<span>Comparing Changes</span>
			</div>
			<button class="close-btn" title="Close compare">&times;</button>
		`;
		header.querySelector('.close-btn').addEventListener('click', () => {
			this._view = 'list';
			this._selectedId = null;
			this._diffData = null;
			this._render();
		});
		panel.appendChild(header);

		// Navigation bar
		const nav = document.createElement('div');
		nav.className = 'diff-nav';

		// Compare direction buttons
		const navBtns = document.createElement('div');
		navBtns.className = 'nav-buttons';

		if (isFirst) {
			// Current revision: can only compare against previous
			navBtns.innerHTML = `
				<button class="btn btn-sm btn-active">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
					Previous
				</button>
			`;
		} else {
			// Non-current: buttons for Current, Previous, Next
			const dir = this._compareDirection;
			navBtns.innerHTML = `
				<button class="btn btn-sm btn-nav-current ${dir === 'current' ? 'btn-active' : ''}">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
					Current
				</button>
				<button class="btn btn-sm btn-nav-previous ${dir === 'previous' ? 'btn-active' : ''}">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
					Previous
				</button>
				${!isLast && selectedIdx < this._revisions.length - 1 ? `
				<button class="btn btn-sm btn-nav-next ${dir === 'next' ? 'btn-active' : ''}">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
					Next
				</button>` : ''}
			`;
		}
		nav.appendChild(navBtns);

		// Legend
		const legend = document.createElement('div');
		legend.className = 'diff-legend';
		legend.innerHTML = `
			<span class="legend-added"><span class="legend-dot added-dot"></span> Added</span>
			<span class="legend-removed"><span class="legend-dot removed-dot"></span> Removed</span>
		`;
		nav.appendChild(legend);

		// Wire navigation buttons
		const currentBtn = nav.querySelector('.btn-nav-current');
		const prevBtn = nav.querySelector('.btn-nav-previous');
		const nextBtn = nav.querySelector('.btn-nav-next');
		if (currentBtn) currentBtn.addEventListener('click', () => this._fetchDiff(this._selectedId, 'current'));
		if (prevBtn) prevBtn.addEventListener('click', () => this._fetchDiff(this._selectedId, 'previous'));
		if (nextBtn) nextBtn.addEventListener('click', () => this._fetchDiff(this._selectedId, 'next'));

		panel.appendChild(nav);

		// Metadata
		const meta = document.createElement('div');
		meta.className = 'diff-meta';
		meta.innerHTML = `
			<span class="meta-item">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
				${this._escHtml(this._formatDate(rev.date))}
			</span>
			<span class="meta-item">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
				${this._escHtml(rev.user)}
			</span>
			<span class="meta-item">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/></svg>
				Comparing w/${this._escHtml(data.compareLabel)}
			</span>
		`;
		panel.appendChild(meta);

		// Diff content
		const content = document.createElement('div');
		content.className = 'diff-content';
		const lines = data.lines || [];

		if (lines.length === 0) {
			content.innerHTML = '<div class="diff-no-changes">No content available</div>';
		} else {
			content.innerHTML = lines.map(l => {
				const cls = l.type === 'added' ? 'diff-added' : l.type === 'removed' ? 'diff-removed' : 'diff-context';
				const marker = l.type === 'added' ? '+' : l.type === 'removed' ? '-' : ' ';
				const oldNum = l.oldNum != null ? l.oldNum : '';
				const newNum = l.newNum != null ? l.newNum : '';
				return `<div class="diff-line ${cls}"><span class="diff-marker">${marker}</span><span class="diff-old-num">${oldNum}</span><span class="diff-new-num">${newNum}</span><span class="diff-text">${this._escHtml(l.content) || ' '}</span></div>`;
			}).join('');
		}
		panel.appendChild(content);

		return panel;
	}

	_renderConfirm() {
		const overlay = document.createElement('div');
		overlay.className = 'confirm-overlay';

		const action = this._confirmAction;
		const isRestore = action.type === 'restore';

		overlay.innerHTML = `
			<div class="confirm-dialog">
				<div class="confirm-title">${isRestore ? 'Restore Revision' : 'Delete Revision'}</div>
				<p class="confirm-message">
					${isRestore
						? `Restore the revision from <strong>${this._escHtml(this._formatDate(action.date))}</strong>? The current content will be backed up as a new revision.`
						: `Permanently delete the revision from <strong>${this._escHtml(this._formatDate(action.date))}</strong>? This cannot be undone.`
					}
				</p>
				<div class="confirm-actions">
					<button class="btn btn-outline btn-cancel">Cancel</button>
					<button class="btn ${isRestore ? 'btn-restore' : 'btn-delete'} btn-confirm-action">
						${isRestore ? 'Restore' : 'Delete'}
					</button>
				</div>
			</div>
		`;

		overlay.querySelector('.btn-cancel').addEventListener('click', () => {
			this._confirmAction = null;
			this._render();
		});
		overlay.querySelector('.btn-confirm-action').addEventListener('click', () => {
			if (isRestore) this._restoreRevision(action.id);
			else this._deleteRevision(action.id);
		});
		// Click outside to cancel
		overlay.addEventListener('click', (e) => {
			if (e.target === overlay) {
				this._confirmAction = null;
				this._render();
			}
		});

		return overlay;
	}

	// ─── Trash rendering ────────────────────────

	_renderTrash() {
		const panel = document.createElement('div');
		panel.className = 'trash-panel';

		// Header
		const header = document.createElement('div');
		header.className = 'panel-header';
		header.innerHTML = `
			<div class="header-title">
				<button class="back-btn" title="Back to history">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
				</button>
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
				<span>Page Trash</span>
			</div>
			<button class="close-btn" title="Close">&times;</button>
		`;
		header.querySelector('.back-btn').addEventListener('click', () => this._closeTrash());
		header.querySelector('.close-btn').addEventListener('click', () => this._close());
		panel.appendChild(header);

		// Content
		const content = document.createElement('div');
		content.className = 'trash-content';

		if (this._loading && this._trashItems.length === 0) {
			content.innerHTML = '<div class="loading">Loading trashed pages...</div>';
		} else if (this._trashError && this._trashItems.length === 0) {
			content.innerHTML = `<div class="error">${this._escHtml(this._trashError)}</div>`;
		} else if (this._trashItems.length === 0) {
			content.innerHTML = '<div class="empty">The trash is empty.</div>';
		} else {
			if (this._trashError) {
				const err = document.createElement('div');
				err.className = 'error inline-error';
				err.textContent = this._trashError;
				content.appendChild(err);
			}
			this._trashItems.forEach((item) => {
				const card = document.createElement('div');
				card.className = 'trash-card';
				const deletedAt = item.deleted_at
					? this._formatTimestamp(item.deleted_at)
					: '—';
				card.innerHTML = `
					<div class="trash-main">
						<div class="trash-title">${this._escHtml(item.title || 'Untitled')}</div>
						<div class="trash-route">${this._escHtml(item.route || '')}</div>
						<div class="trash-meta">
							<span class="trash-date">
								<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
								${this._escHtml(deletedAt)}
							</span>
							<span class="trash-user">
								<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
								${this._escHtml(item.deleted_by || 'unknown')}
							</span>
							${item.language ? `<span class="trash-lang">${this._escHtml(item.language)}</span>` : ''}
						</div>
					</div>
					<div class="trash-actions">
						<button class="btn-icon btn-restore" title="Restore">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
						</button>
						<button class="btn-icon btn-delete" title="Delete permanently">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
						</button>
					</div>
				`;
				card.querySelector('.btn-restore').addEventListener('click', () => {
					this._trashRestoreItem = item;
					this._trashRestoreOptions = {
						mode: 'original',
						overwrite: false,
						parent_route: item.parent_route || '/',
						slug: item.slug || '',
						folder_name: item.folder || '',
					};
					this._render();
				});
				card.querySelector('.btn-delete').addEventListener('click', () => {
					this._trashConfirmAction = { type: 'delete', id: item.id, title: item.title || 'Untitled' };
					this._render();
				});
				content.appendChild(card);
			});
		}

		panel.appendChild(content);

		// Footer with empty trash button
		if (this._trashItems.length > 0) {
			const footer = document.createElement('div');
			footer.className = 'trash-footer';
			footer.innerHTML = `
				<button class="btn btn-danger btn-empty-trash">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
					Empty Trash
				</button>
			`;
			footer.querySelector('.btn-empty-trash').addEventListener('click', () => {
				this._trashConfirmAction = { type: 'empty' };
				this._render();
			});
			panel.appendChild(footer);
		}

		return panel;
	}

	_renderTrashRestoreDialog() {
		const overlay = document.createElement('div');
		overlay.className = 'confirm-overlay';

		const item = this._trashRestoreItem;
		const opts = this._trashRestoreOptions;

		const dialog = document.createElement('div');
		dialog.className = 'confirm-dialog trash-restore-dialog';
		dialog.innerHTML = `
			<div class="confirm-title">Restore "${this._escHtml(item.title || 'Untitled')}"</div>
			<p class="confirm-message">Choose where to restore this page.</p>
			<div class="restore-options">
				<label class="restore-option">
					<input type="radio" name="restore-mode" value="original" ${opts.mode === 'original' ? 'checked' : ''}>
					<div class="restore-option-text">
						<strong>Original location</strong>
						<code>${this._escHtml(item.route || '/')}</code>
					</div>
				</label>
				<label class="restore-option">
					<input type="radio" name="restore-mode" value="custom" ${opts.mode === 'custom' ? 'checked' : ''}>
					<div class="restore-option-text">
						<strong>Custom location</strong>
					</div>
				</label>
				<div class="restore-custom-fields" style="display:${opts.mode === 'custom' ? 'block' : 'none'};">
					<label class="field-label">Parent Route
						<input type="text" class="field-input" data-field="parent_route" value="${this._escHtml(opts.parent_route)}" placeholder="/parent">
					</label>
					<label class="field-label">Slug
						<input type="text" class="field-input" data-field="slug" value="${this._escHtml(opts.slug)}" placeholder="my-page">
					</label>
					<label class="field-label">Folder Name
						<input type="text" class="field-input" data-field="folder_name" value="${this._escHtml(opts.folder_name)}" placeholder="01.mypage">
					</label>
				</div>
				<label class="restore-overwrite">
					<input type="checkbox" ${opts.overwrite ? 'checked' : ''}>
					<span>Overwrite existing page if one is present</span>
				</label>
			</div>
			<div class="confirm-actions">
				<button class="btn btn-outline btn-cancel">Cancel</button>
				<button class="btn btn-restore btn-confirm-restore">Restore</button>
			</div>
		`;

		// Wire up mode radios
		dialog.querySelectorAll('input[name="restore-mode"]').forEach((radio) => {
			radio.addEventListener('change', (e) => {
				opts.mode = e.target.value;
				const customFields = dialog.querySelector('.restore-custom-fields');
				if (customFields) customFields.style.display = opts.mode === 'custom' ? 'block' : 'none';
			});
		});

		// Wire up text inputs
		dialog.querySelectorAll('.field-input').forEach((input) => {
			input.addEventListener('input', (e) => {
				const field = e.target.getAttribute('data-field');
				opts[field] = e.target.value;
			});
		});

		// Wire up overwrite checkbox
		dialog.querySelector('.restore-overwrite input').addEventListener('change', (e) => {
			opts.overwrite = e.target.checked;
		});

		// Buttons
		dialog.querySelector('.btn-cancel').addEventListener('click', () => {
			this._trashRestoreItem = null;
			this._render();
		});
		dialog.querySelector('.btn-confirm-restore').addEventListener('click', () => {
			const payload = {
				mode: opts.mode,
				overwrite: opts.overwrite,
			};
			if (opts.mode === 'custom') {
				payload.parent_route = opts.parent_route;
				payload.slug = opts.slug;
				payload.folder_name = opts.folder_name;
			}
			this._restoreTrashItem(item.id, payload);
		});

		overlay.appendChild(dialog);

		// Click outside to cancel
		overlay.addEventListener('click', (e) => {
			if (e.target === overlay) {
				this._trashRestoreItem = null;
				this._render();
			}
		});

		return overlay;
	}

	_renderTrashConfirm() {
		const overlay = document.createElement('div');
		overlay.className = 'confirm-overlay';

		const action = this._trashConfirmAction;
		const isEmpty = action.type === 'empty';

		overlay.innerHTML = `
			<div class="confirm-dialog">
				<div class="confirm-title">${isEmpty ? 'Empty Trash' : 'Delete Permanently'}</div>
				<p class="confirm-message">
					${isEmpty
						? `Permanently delete all <strong>${this._trashItems.length}</strong> trash item(s)? This cannot be undone.`
						: `Permanently delete <strong>${this._escHtml(action.title)}</strong>? This cannot be undone.`
					}
				</p>
				<div class="confirm-actions">
					<button class="btn btn-outline btn-cancel">Cancel</button>
					<button class="btn btn-delete btn-confirm-action">
						${isEmpty ? 'Empty Trash' : 'Delete'}
					</button>
				</div>
			</div>
		`;

		overlay.querySelector('.btn-cancel').addEventListener('click', () => {
			this._trashConfirmAction = null;
			this._render();
		});
		overlay.querySelector('.btn-confirm-action').addEventListener('click', () => {
			if (isEmpty) this._emptyTrash();
			else this._deleteTrashItem(action.id);
		});
		overlay.addEventListener('click', (e) => {
			if (e.target === overlay) {
				this._trashConfirmAction = null;
				this._render();
			}
		});

		return overlay;
	}

	_escHtml(str) {
		const div = document.createElement('div');
		div.textContent = str || '';
		return div.innerHTML;
	}

	// ─── Styles ──────────────────────────────────

	_styles() {
		return `
			:host {
				display: flex;
				flex-direction: column;
				height: 100%;
				font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
				font-size: 13px;
				color: var(--foreground, #e2e8f0);
				--panel-bg: var(--background, #0f172a);
				--panel-border: var(--border, #334155);
				--panel-muted: var(--muted, #1e293b);
				--panel-muted-fg: var(--muted-foreground, #94a3b8);
				--panel-primary: var(--primary, #6366f1);
				--panel-accent: var(--accent, #1e293b);
			}

			.panel-container {
				display: flex;
				height: 100%;
				overflow: hidden;
			}

			/* History panel - RIGHT side, fixed width, flush against window edge */
			.history-panel {
				width: 380px;
				min-width: 380px;
				display: flex;
				flex-direction: column;
				background: var(--panel-bg);
			}

			/* Border separator when detail panel is active */
			.has-detail .history-panel {
				border-left: 1px solid var(--panel-border);
			}

			/* Detail panel - LEFT side, only when active */
			.detail-panel {
				flex: 1;
				display: flex;
				flex-direction: column;
				background: var(--panel-bg);
				overflow: hidden;
			}

			/* Headers */
			.panel-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 12px 16px;
				border-bottom: 1px solid var(--panel-border);
				background: var(--panel-muted);
				flex-shrink: 0;
			}

			.header-title {
				display: flex;
				align-items: center;
				gap: 8px;
				font-weight: 600;
				font-size: 14px;
			}

			.close-btn {
				background: none;
				border: none;
				color: var(--panel-muted-fg);
				font-size: 20px;
				cursor: pointer;
				padding: 2px 6px;
				border-radius: 4px;
				line-height: 1;
			}
			.close-btn:hover {
				background: var(--panel-accent);
				color: var(--foreground, #e2e8f0);
			}

			/* Scrollable history content */
			.history-content {
				flex: 1;
				overflow-y: auto;
				padding: 8px;
			}

			/* Revision cards */
			.revision-card {
				padding: 8px 10px;
				margin-bottom: 4px;
				border-radius: 8px;
				border: 1px solid var(--panel-border);
				background: var(--panel-muted);
				transition: border-color 0.15s;
			}
			.revision-card:hover {
				border-color: var(--panel-primary);
			}
			.revision-card.selected {
				border-color: var(--panel-primary);
				box-shadow: 0 0 0 1px var(--panel-primary);
			}

			.rev-row {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 8px;
			}

			.rev-left {
				display: flex;
				align-items: center;
				gap: 8px;
				min-width: 0;
			}

			.rev-info {
				display: flex;
				flex-direction: column;
				min-width: 0;
			}

			.rev-date {
				font-weight: 500;
				font-size: 12px;
				white-space: nowrap;
			}

			.rev-current {
				color: #eab308;
				font-size: 13px;
			}

			.rev-user {
				color: var(--panel-muted-fg);
				font-size: 11px;
			}

			.rev-badge {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-width: 22px;
				height: 22px;
				padding: 0 6px;
				border-radius: 11px;
				background: var(--panel-primary);
				color: white;
				font-size: 11px;
				font-weight: 600;
				flex-shrink: 0;
			}

			/* Action icon buttons */
			.rev-actions {
				display: flex;
				gap: 2px;
				flex-shrink: 0;
			}

			.btn-icon {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 28px;
				height: 28px;
				border-radius: 6px;
				border: 1px solid transparent;
				background: transparent;
				color: var(--panel-muted-fg);
				cursor: pointer;
				transition: all 0.15s;
			}
			.btn-icon:hover:not(:disabled) {
				background: var(--panel-accent);
				color: var(--foreground, #e2e8f0);
				border-color: var(--panel-border);
			}
			.btn-icon:disabled {
				opacity: 0.25;
				cursor: not-allowed;
			}
			.btn-icon.btn-restore {
				color: var(--panel-primary);
			}
			.btn-icon.btn-restore:hover:not(:disabled) {
				background: rgba(99, 102, 241, 0.15);
				color: var(--panel-primary);
				border-color: var(--panel-primary);
			}
			.btn-icon.btn-delete {
				color: #ef4444;
			}
			.btn-icon.btn-delete:hover:not(:disabled) {
				background: rgba(239, 68, 68, 0.15);
				border-color: #ef4444;
			}

			/* Buttons (nav, preview actions) */
			.btn {
				display: inline-flex;
				align-items: center;
				gap: 4px;
				padding: 4px 10px;
				border-radius: 6px;
				border: 1px solid var(--panel-border);
				background: transparent;
				color: var(--foreground, #e2e8f0);
				font-size: 12px;
				cursor: pointer;
				transition: all 0.15s;
				white-space: nowrap;
			}
			.btn:hover:not(:disabled) {
				background: var(--panel-accent);
			}
			.btn-outline {
				border-color: var(--panel-border);
			}
			.btn.btn-restore {
				background: var(--panel-primary);
				border-color: var(--panel-primary);
				color: white;
			}
			.btn.btn-delete {
				background: #ef4444;
				border-color: #ef4444;
				color: white;
			}
			.btn-sm {
				padding: 3px 8px;
				font-size: 11px;
			}
			.btn-active {
				background: var(--panel-primary);
				border-color: var(--panel-primary);
				color: white;
			}

			/* Info bar */
			.info-bar {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 8px 16px;
				border-bottom: 1px solid var(--panel-border);
				background: var(--panel-muted);
				gap: 12px;
				flex-shrink: 0;
				flex-wrap: wrap;
			}

			.info-left, .info-right {
				display: flex;
				align-items: center;
				gap: 12px;
			}

			.info-filename {
				display: flex;
				align-items: center;
				gap: 4px;
				font-weight: 500;
			}

			.info-date, .info-user {
				display: flex;
				align-items: center;
				gap: 4px;
				color: var(--panel-muted-fg);
				font-size: 12px;
			}

			.btn-copy {
				padding: 3px 8px;
				font-size: 11px;
			}

			/* Preview content */
			.preview-content {
				flex: 1;
				overflow: auto;
				font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
				font-size: 12px;
				line-height: 1.6;
			}

			.preview-line {
				display: flex;
				padding: 0 16px 0 0;
			}
			.preview-line:hover {
				background: var(--panel-accent);
			}

			.line-num {
				display: inline-block;
				min-width: 48px;
				padding: 0 12px;
				text-align: right;
				color: var(--panel-muted-fg);
				user-select: none;
				flex-shrink: 0;
				border-right: 1px solid var(--panel-border);
			}

			.line-text {
				padding-left: 12px;
				white-space: pre;
			}

			/* Diff navigation */
			.diff-nav {
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 8px 16px;
				border-bottom: 1px solid var(--panel-border);
				background: var(--panel-muted);
				flex-shrink: 0;
				gap: 12px;
				flex-wrap: wrap;
			}

			.nav-buttons {
				display: flex;
				gap: 4px;
			}

			.diff-legend {
				display: flex;
				gap: 12px;
				font-size: 11px;
				color: var(--panel-muted-fg);
			}

			.legend-dot {
				display: inline-block;
				width: 8px;
				height: 8px;
				border-radius: 50%;
				margin-right: 4px;
			}
			.added-dot { background: #22c55e; }
			.removed-dot { background: #ef4444; }

			.legend-added, .legend-removed {
				display: flex;
				align-items: center;
			}

			/* Diff metadata */
			.diff-meta {
				display: flex;
				align-items: center;
				gap: 16px;
				padding: 8px 16px;
				border-bottom: 1px solid var(--panel-border);
				font-size: 12px;
				color: var(--panel-muted-fg);
				flex-shrink: 0;
				flex-wrap: wrap;
			}

			.meta-item {
				display: flex;
				align-items: center;
				gap: 4px;
			}

			/* Diff content */
			.diff-content {
				flex: 1;
				overflow: auto;
				font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
				font-size: 12px;
				line-height: 1.6;
			}

			.diff-line {
				display: flex;
				padding: 0 16px 0 0;
			}

			.diff-marker {
				display: inline-block;
				width: 20px;
				text-align: center;
				flex-shrink: 0;
				user-select: none;
				font-weight: 600;
			}

			.diff-old-num, .diff-new-num {
				display: inline-block;
				min-width: 36px;
				padding: 0 4px;
				text-align: right;
				color: var(--panel-muted-fg);
				user-select: none;
				flex-shrink: 0;
				font-size: 11px;
			}
			.diff-old-num {
				border-right: 1px solid var(--panel-border);
			}
			.diff-new-num {
				border-right: 1px solid var(--panel-border);
			}

			.diff-text {
				padding-left: 12px;
				white-space: pre;
				flex: 1;
			}

			.diff-context {
				/* default styling */
			}
			.diff-context:hover {
				background: var(--panel-accent);
			}

			.diff-added {
				background: rgba(34, 197, 94, 0.1);
			}
			.diff-added .diff-marker { color: #22c55e; }
			.diff-added .diff-text { color: #22c55e; }

			.diff-removed {
				background: rgba(239, 68, 68, 0.1);
			}
			.diff-removed .diff-marker { color: #ef4444; }
			.diff-removed .diff-text { color: #ef4444; }

			.diff-no-changes {
				padding: 40px;
				text-align: center;
				color: var(--panel-muted-fg);
			}

			/* States */
			.loading, .error, .empty {
				padding: 40px 16px;
				text-align: center;
				color: var(--panel-muted-fg);
			}
			.error {
				color: #ef4444;
			}

			/* Confirm overlay */
			.confirm-overlay {
				position: absolute;
				inset: 0;
				background: rgba(0, 0, 0, 0.5);
				display: flex;
				align-items: center;
				justify-content: center;
				z-index: 100;
			}

			.confirm-dialog {
				background: var(--panel-bg);
				border: 1px solid var(--panel-border);
				border-radius: 12px;
				padding: 24px;
				max-width: 400px;
				width: 90%;
				box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
			}

			.confirm-title {
				font-size: 16px;
				font-weight: 600;
				margin-bottom: 12px;
			}

			.confirm-message {
				color: var(--panel-muted-fg);
				margin-bottom: 20px;
				line-height: 1.5;
			}
			.confirm-message strong {
				color: var(--foreground, #e2e8f0);
			}

			.confirm-actions {
				display: flex;
				justify-content: flex-end;
				gap: 8px;
			}

			/* Header actions */
			.header-actions {
				display: flex;
				align-items: center;
				gap: 4px;
			}

			/* Trash toggle badge */
			.btn-trash-toggle {
				position: relative;
			}
			.trash-count-badge {
				position: absolute;
				top: -2px;
				right: -2px;
				min-width: 16px;
				height: 16px;
				padding: 0 4px;
				background: #ef4444;
				color: white;
				border-radius: 8px;
				font-size: 10px;
				font-weight: 600;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				line-height: 1;
			}

			/* Trash panel */
			.trash-panel {
				flex: 1;
				display: flex;
				flex-direction: column;
				background: var(--panel-bg);
				overflow: hidden;
				width: 100%;
			}

			.back-btn {
				background: none;
				border: none;
				color: var(--panel-muted-fg);
				cursor: pointer;
				padding: 2px;
				border-radius: 4px;
				display: inline-flex;
				align-items: center;
				justify-content: center;
			}
			.back-btn:hover {
				background: var(--panel-accent);
				color: var(--foreground, #e2e8f0);
			}

			.trash-content {
				flex: 1;
				overflow-y: auto;
				padding: 8px;
			}

			.trash-content .inline-error {
				padding: 10px 12px;
				margin-bottom: 8px;
				background: rgba(239, 68, 68, 0.1);
				border: 1px solid rgba(239, 68, 68, 0.3);
				border-radius: 6px;
				color: #ef4444;
				text-align: left;
			}

			.trash-card {
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 12px;
				padding: 12px;
				margin-bottom: 6px;
				border-radius: 8px;
				border: 1px solid var(--panel-border);
				background: var(--panel-muted);
				transition: border-color 0.15s;
			}
			.trash-card:hover {
				border-color: var(--panel-primary);
			}

			.trash-main {
				flex: 1;
				min-width: 0;
			}

			.trash-title {
				font-weight: 600;
				font-size: 13px;
				margin-bottom: 2px;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}

			.trash-route {
				font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, monospace;
				font-size: 11px;
				color: var(--panel-muted-fg);
				margin-bottom: 6px;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}

			.trash-meta {
				display: flex;
				align-items: center;
				gap: 10px;
				font-size: 11px;
				color: var(--panel-muted-fg);
				flex-wrap: wrap;
			}

			.trash-meta > span {
				display: inline-flex;
				align-items: center;
				gap: 3px;
			}

			.trash-lang {
				padding: 1px 6px;
				background: var(--panel-accent);
				border-radius: 4px;
				text-transform: uppercase;
				font-weight: 600;
			}

			.trash-actions {
				display: flex;
				gap: 2px;
				flex-shrink: 0;
			}

			.trash-footer {
				padding: 10px 16px;
				border-top: 1px solid var(--panel-border);
				background: var(--panel-muted);
				display: flex;
				justify-content: flex-end;
			}

			.btn-danger {
				background: #ef4444;
				border-color: #ef4444;
				color: white;
			}
			.btn-danger:hover:not(:disabled) {
				background: #dc2626;
				border-color: #dc2626;
			}

			/* Trash restore dialog */
			.trash-restore-dialog {
				max-width: 480px;
			}

			.restore-options {
				margin-bottom: 20px;
			}

			.restore-option {
				display: flex;
				align-items: flex-start;
				gap: 10px;
				padding: 10px;
				border: 1px solid var(--panel-border);
				border-radius: 6px;
				margin-bottom: 6px;
				cursor: pointer;
				transition: border-color 0.15s;
			}
			.restore-option:hover {
				border-color: var(--panel-primary);
			}
			.restore-option input[type="radio"] {
				margin-top: 2px;
				accent-color: var(--panel-primary);
			}

			.restore-option-text {
				flex: 1;
				min-width: 0;
			}
			.restore-option-text strong {
				display: block;
				font-size: 13px;
				color: var(--foreground, #e2e8f0);
				margin-bottom: 2px;
			}
			.restore-option-text code {
				display: block;
				font-family: 'SF Mono', Monaco, monospace;
				font-size: 11px;
				color: var(--panel-muted-fg);
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}

			.restore-custom-fields {
				margin: 0 0 8px 28px;
				padding: 12px;
				background: var(--panel-muted);
				border-radius: 6px;
			}

			.field-label {
				display: block;
				font-size: 11px;
				color: var(--panel-muted-fg);
				margin-bottom: 10px;
				font-weight: 500;
			}
			.field-label:last-child {
				margin-bottom: 0;
			}

			.field-input {
				display: block;
				width: 100%;
				margin-top: 4px;
				padding: 6px 8px;
				background: var(--panel-bg);
				border: 1px solid var(--panel-border);
				border-radius: 4px;
				color: var(--foreground, #e2e8f0);
				font-family: 'SF Mono', Monaco, monospace;
				font-size: 12px;
				box-sizing: border-box;
			}
			.field-input:focus {
				outline: none;
				border-color: var(--panel-primary);
			}

			.restore-overwrite {
				display: flex;
				align-items: center;
				gap: 8px;
				padding: 10px;
				font-size: 12px;
				color: var(--panel-muted-fg);
				cursor: pointer;
			}
			.restore-overwrite input[type="checkbox"] {
				accent-color: var(--panel-primary);
			}

			/* Scrollbar styling */
			::-webkit-scrollbar {
				width: 6px;
			}
			::-webkit-scrollbar-track {
				background: transparent;
			}
			::-webkit-scrollbar-thumb {
				background: var(--panel-border);
				border-radius: 3px;
			}
			::-webkit-scrollbar-thumb:hover {
				background: var(--panel-muted-fg);
			}
		`;
	}
}

customElements.define(TAG, RevisionsProPanel);
