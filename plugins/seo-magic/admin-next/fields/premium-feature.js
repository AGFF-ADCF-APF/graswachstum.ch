const TAG = window.__GRAV_FIELD_TAG;

class PremiumFeature extends HTMLElement {
    _field = null;
    _value = null;

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

    _escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    _render() {
        const featureName = this._field?.feature_name || 'Premium Feature';
        const description = this._field?.description || 'This feature requires a valid license to use.';

        const lockIcon = `
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;">
                <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        `;

        this.innerHTML = `
            <div style="
                border: 1px solid #d97706;
                border-radius: 8px;
                padding: 16px;
                background: color-mix(in srgb, #d97706 6%, var(--background, #fff));
            ">
                <div style="
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    margin-bottom: 8px;
                ">
                    ${lockIcon}
                    <span style="
                        font-size: 14px;
                        font-weight: 600;
                        color: #d97706;
                        line-height: 1.3;
                    ">${this._escapeHtml(featureName)}</span>
                </div>
                <p style="
                    margin: 0;
                    font-size: 13px;
                    line-height: 1.5;
                    color: var(--muted-foreground, #71717a);
                ">${this._escapeHtml(description)}</p>
            </div>
        `;
    }
}

customElements.define(TAG, PremiumFeature);
