const TAG = window.__GRAV_FIELD_TAG;

class SeomagicToggle extends HTMLElement {
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
        this._value = this._normalize(v);
        this._render();
    }

    get value() {
        return this._value;
    }

    connectedCallback() {
        this._render();
    }

    _normalize(v) {
        if (v === true || v === 1 || v === '1' || v === 'true') return true;
        if (v === false || v === 0 || v === '0' || v === 'false') return false;
        return !!v;
    }

    _select(val) {
        this._value = val;
        this._render();
        this.dispatchEvent(new CustomEvent('change', {
            detail: val,
            bubbles: true,
        }));
    }

    _render() {
        const isOn = !!this._value;
        const source = this._field?.source || '';

        // Match the admin-next ToggleField segmented control style
        const options = [
            { value: true, label: this._field?.options?.[0]?.label || 'Enabled' },
            { value: false, label: this._field?.options?.[1]?.label || 'Disabled' },
        ];

        // Resolve option labels from field.options if available (format: [{value, label}])
        if (this._field?.options && Array.isArray(this._field.options)) {
            const fo = this._field.options;
            if (fo.length >= 2) {
                options[0].label = fo[0].label || 'Enabled';
                options[0].value = this._normalize(fo[0].value);
                options[1].label = fo[1].label || 'Disabled';
                options[1].value = this._normalize(fo[1].value);
            }
        }

        const activeIdx = isOn === options[0].value ? 0 : 1;

        const buttons = options.map((opt, i) => {
            const active = i === activeIdx;
            return `<button
                type="button"
                data-idx="${i}"
                style="
                    position: relative;
                    z-index: 1;
                    padding: 6px 16px;
                    font-size: 13px;
                    font-weight: 500;
                    font-family: inherit;
                    border: none;
                    background: none;
                    cursor: pointer;
                    border-radius: 6px;
                    transition: color 0.2s ease;
                    color: ${active ? '#fff' : 'var(--muted-foreground, #71717a)'};
                    white-space: nowrap;
                "
            >${this._escapeHtml(opt.label)}</button>`;
        }).join('');

        const sliderLeft = activeIdx === 0
            ? 'calc(0% + 2px)'
            : 'calc(50% + 2px)';
        const isHighlighted = isOn === this._normalize(this._field?.highlight ?? 1);
        const sliderBg = isHighlighted
            ? 'var(--primary, #3b82f6)'
            : 'var(--muted-foreground, #6b7280)';

        this.innerHTML = `
            <div style="display: flex; flex-direction: column; gap: 6px;">
                <div style="
                    position: relative;
                    display: inline-grid;
                    grid-template-columns: 1fr 1fr;
                    border-radius: 8px;
                    border: 1px solid var(--input, #e4e4e7);
                    background: color-mix(in srgb, var(--muted, #f4f4f5) 30%, transparent);
                    padding: 2px;
                    width: fit-content;
                ">
                    <div style="
                        position: absolute;
                        top: 2px;
                        bottom: 2px;
                        left: ${sliderLeft};
                        width: calc(50% - 4px);
                        border-radius: 6px;
                        background: ${sliderBg};
                        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
                        transition: left 0.2s ease-out;
                    "></div>
                    ${buttons}
                </div>
                ${source ? `
                    <span style="
                        font-size: 12px;
                        color: var(--muted-foreground, #71717a);
                        font-style: italic;
                        line-height: 1.4;
                    ">Source: ${this._escapeHtml(source)}</span>
                ` : ''}
            </div>
        `;

        this.querySelectorAll('button[data-idx]').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.idx);
                this._select(options[idx].value);
            });
        });
    }

    _escapeHtml(str) {
        if (typeof str !== 'string') return String(str ?? '');
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

customElements.define(TAG, SeomagicToggle);
