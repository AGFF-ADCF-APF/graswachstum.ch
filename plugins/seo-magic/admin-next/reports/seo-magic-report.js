/**
 * SEO-Magic Report — Web Component for admin-next reports page.
 *
 * Matches the style of the problems-report component: Shadow DOM,
 * status-bar with color-mix theming, detail rows for stats,
 * and a link to the full dashboard.
 */
const TAG = window.__GRAV_REPORT_TAG || 'grav-seo-magic--seo-magic-report';

class SeoMagicReportElement extends HTMLElement {
    #report = null;

    set report(val) {
        this.#report = val;
        this.render();
    }

    get report() {
        return this.#report;
    }

    connectedCallback() {
        if (this.#report) this.render();
    }

    render() {
        const report = this.#report;
        if (!report) return;

        const items = report.items || {};
        const pages = items.pages ?? 0;
        const avg = items.avg ?? 0;
        const issuesPages = items.issues_pages ?? 0;
        const brokenLinks = items.broken_links ?? 0;
        const brokenImages = items.broken_images ?? 0;
        const hasIssues = issuesPages > 0 || brokenLinks > 0 || brokenImages > 0;

        const style = document.createElement('style');
        style.textContent = `
            :host {
                display: block;
                font-family: inherit;
            }
            .status-bar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                padding: 8px 16px;
                font-size: 13px;
                font-weight: 500;
                border-bottom: 1px solid var(--border, #e5e7eb);
            }
            .status-bar.success {
                background: color-mix(in srgb, #22c55e 12%, transparent);
                color: color-mix(in srgb, #16a34a 80%, var(--foreground, #1f2937));
            }
            .status-bar.warning {
                background: color-mix(in srgb, #eab308 12%, transparent);
                color: color-mix(in srgb, #a16207 80%, var(--foreground, #1f2937));
            }
            .status-bar .msg {
                flex: 1;
            }
            .status-bar .msg strong {
                font-weight: 700;
            }
            .dashboard-link {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                color: var(--muted-foreground, #6b7280);
                text-decoration: none;
                font-size: 11px;
                font-weight: 500;
                padding: 2px 8px;
                border-radius: 4px;
                border: 1px solid var(--border, #e5e7eb);
                background: var(--card, #fff);
                white-space: nowrap;
                cursor: pointer;
                transition: border-color 0.15s;
            }
            .dashboard-link:hover {
                border-color: var(--foreground, #1f2937);
                color: var(--foreground, #1f2937);
            }
            .dashboard-link svg {
                width: 12px;
                height: 12px;
            }
            .detail-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 7px 16px;
                font-size: 13px;
                color: var(--foreground, #1f2937);
                border-bottom: 1px solid var(--border, #e5e7eb);
            }
            .detail-item:last-child {
                border-bottom: none;
            }
            .detail-label {
                color: var(--muted-foreground, #6b7280);
            }
            .detail-label strong {
                font-weight: 600;
                margin-right: 2px;
                color: var(--foreground, #1f2937);
            }
            .detail-value {
                font-weight: 600;
                font-variant-numeric: tabular-nums;
            }
            .detail-value.success { color: color-mix(in srgb, #22c55e 85%, var(--foreground, #1f2937)); }
            .detail-value.warning { color: color-mix(in srgb, #eab308 85%, var(--foreground, #1f2937)); }
            .detail-value.muted { color: var(--muted-foreground, #6b7280); }
        `;

        const shadow = this.shadowRoot || this.attachShadow({ mode: 'open' });
        shadow.innerHTML = '';
        shadow.appendChild(style);

        // Status bar
        const bar = document.createElement('div');
        bar.className = `status-bar ${hasIssues ? 'warning' : 'success'}`;

        const msgSpan = document.createElement('span');
        msgSpan.className = 'msg';
        msgSpan.innerHTML = `<strong>SEO-Magic:</strong> ${pages} pages crawled, ${avg}% average score${hasIssues ? `, ${issuesPages} with issues` : ''}`;
        bar.appendChild(msgSpan);

        const link = document.createElement('button');
        link.className = 'dashboard-link';
        link.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg> Dashboard`;
        link.addEventListener('click', () => {
            const base = window.location.pathname.replace(/\/tools.*$/, '');
            window.location.href = base + '/plugin/seo-magic';
        });
        bar.appendChild(link);

        shadow.appendChild(bar);

        // Detail rows
        const details = [
            { label: 'Pages crawled', value: pages, cls: 'muted' },
            { label: 'Average score', value: `${avg}%`, cls: avg >= 80 ? 'success' : 'warning' },
            { label: 'Pages with issues', value: issuesPages, cls: issuesPages > 0 ? 'warning' : 'success' },
            { label: 'Broken links', value: brokenLinks, cls: brokenLinks > 0 ? 'warning' : 'muted' },
            { label: 'Broken images', value: brokenImages, cls: brokenImages > 0 ? 'warning' : 'muted' },
        ];

        for (const d of details) {
            const row = document.createElement('div');
            row.className = 'detail-item';
            row.innerHTML = `
                <span class="detail-label">${d.label}</span>
                <span class="detail-value ${d.cls}">${d.value}</span>
            `;
            shadow.appendChild(row);
        }
    }
}

customElements.define(TAG, SeoMagicReportElement);
