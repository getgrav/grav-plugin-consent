/*
 * consent-inventory — read-only view of everything this site stores on a
 * visitor's device.
 *
 * The payoff of assembling the inventory at runtime: this answers "what does my
 * site actually set?" without anybody maintaining a list. Rows come from the
 * site's own config, from plugins answering onConsentRegisterServices, and from
 * whatever auto-blocking matched on the last page render — and the `Registered
 * by` column says which, so an unexpected entry is traceable.
 */
const TAG = window.__GRAV_FIELD_TAG;

class ConsentInventoryField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._data = null;
        this._error = null;
        this._loading = true;
        this.attachShadow({ mode: 'open' });
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }

    // Read-only: it never changes the form's value, but admin-next still sets
    // and reads the property, so both have to exist.
    set value(v) { this._value = v; }
    get value() { return this._value; }

    connectedCallback() {
        this._render();
        this._load();
        // The translation dictionary lands after first paint, and the admin can
        // switch language without a reload — either way, re-render.
        const i18n = window.__GRAV_I18N;
        if (i18n && typeof i18n.subscribe === 'function') {
            this._i18nUnsub = i18n.subscribe(() => this._render());
        }
    }

    disconnectedCallback() {
        if (this._i18nUnsub) {
            try { this._i18nUnsub(); } catch (e) { /* already gone */ }
            this._i18nUnsub = null;
        }
    }

    _apiUrl(path) {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }

    async _load() {
        try {
            const headers = {};
            // X-API-Token rather than Authorization: Bearer — some FastCGI
            // setups strip the Authorization header before PHP ever sees it.
            if (window.__GRAV_API_TOKEN) headers['X-API-Token'] = window.__GRAV_API_TOKEN;
            const resp = await fetch(this._apiUrl('/consent/inventory'), { headers });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const json = await resp.json();
            this._data = json.data || json;
        } catch (e) {
            this._error = e.message;
        }
        this._loading = false;
        this._render();
    }

    /**
     * Translate, or fall back to the English shipped in this file.
     *
     * `has()` before `t()` matters: admin-next's `t()` humanises an unknown key
     * rather than returning it, so `PLUGIN_CONSENT.ADMIN.FILTER_ALL` becomes
     * "Filter All" — plausible enough to ship by accident. The dictionary also
     * arrives after the first paint, which is why the component subscribes and
     * re-renders rather than reading it once.
     */
    _t(key, fallback, params) {
        const i18n = window.__GRAV_I18N;
        if (i18n && typeof i18n.has === 'function' && typeof i18n.t === 'function' && i18n.has(key)) {
            return i18n.t(key, params);
        }
        // The fallback carries the same {placeholders}, so a missing key still
        // renders a sentence rather than a template.
        if (params) {
            return String(fallback).replace(/\{(\w+)[^}]*\}/g, (m, name) => (name in params ? params[name] : m));
        }
        return fallback;
    }

    _sourceLabel(source) {
        switch (source) {
            case 'config': return this._t('PLUGIN_CONSENT.ADMIN.SOURCE_CONFIG', 'Your config');
            case 'autoblock': return this._t('PLUGIN_CONSENT.ADMIN.SOURCE_AUTOBLOCK', 'Auto-blocking');
            default: return this._t('PLUGIN_CONSENT.ADMIN.SOURCE_PLUGIN', 'A plugin');
        }
    }

    _escape(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    _styles() {
        return `
            :host { display: block; font-size: 13px; color: var(--foreground); }
            .muted { color: var(--muted-foreground); }
            .summary {
                display: flex; flex-wrap: wrap; gap: 20px;
                padding: 12px 14px; margin-bottom: 14px;
                border: 1px solid var(--border); border-radius: 8px;
                background: var(--muted);
            }
            .stat-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted-foreground); }
            .stat-value { font-size: 18px; font-weight: 600; font-variant-numeric: tabular-nums; }
            .group { margin-bottom: 16px; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
            .group-head {
                display: flex; align-items: center; gap: 8px;
                padding: 10px 14px; background: var(--muted);
                border-bottom: 1px solid var(--border);
            }
            .group-title { font-weight: 600; }
            .pill {
                padding: 1px 7px; border-radius: 999px; font-size: 11px;
                background: var(--background); border: 1px solid var(--border); color: var(--muted-foreground);
            }
            .count { margin-inline-start: auto; font-size: 12px; color: var(--muted-foreground); font-variant-numeric: tabular-nums; }
            .table-scroll { overflow-x: auto; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 8px 14px; text-align: start; vertical-align: top; border-bottom: 1px solid var(--border); }
            th { font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted-foreground); }
            tr:last-child td { border-bottom: 0; }
            .name { font-weight: 600; }
            .desc { margin-top: 2px; max-width: 46ch; }
            code {
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 11.5px; padding: 1px 4px; border-radius: 4px;
                background: var(--muted); word-break: break-all;
            }
            .stores { display: flex; flex-direction: column; gap: 3px; }
            .store-row { display: flex; flex-wrap: wrap; gap: 6px; align-items: baseline; }
            .kind { font-size: 10.5px; padding: 0 4px; border-radius: 3px; background: var(--accent); color: var(--muted-foreground); }
            .empty { padding: 22px 14px; text-align: center; }
            .empty-title { font-weight: 600; margin-bottom: 4px; }
            a { color: inherit; }
        `;
    }

    _render() {
        if (this._loading) {
            this.shadowRoot.innerHTML = `<style>${this._styles()}</style><p class="muted">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.LOADING', 'Loading…'))}</p>`;
            return;
        }

        if (this._error) {
            this.shadowRoot.innerHTML = `<style>${this._styles()}</style>
                <div class="group"><div class="empty">
                    <p class="empty-title">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.INVENTORY_FAILED', 'Could not read the inventory'))}</p>
                    <p class="muted">${this._escape(this._error)}</p>
                </div></div>`;
            return;
        }

        const data = this._data || {};
        const groups = data.categories || [];
        const totals = data.totals || {};

        // An empty inventory is a normal state on a fresh install, not an
        // error — say what will fill it rather than showing a bare zero.
        if (!totals.services) {
            this.shadowRoot.innerHTML = `<style>${this._styles()}</style>
                <div class="group"><div class="empty">
                    <p class="empty-title">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.NO_SERVICES', 'Nothing registered yet.'))}</p>
                    <p class="muted">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.NO_SERVICES_HELP', 'Services show up here as soon as a plugin registers one, or you add one on the Services tab.'))}</p>
                </div></div>`;
            return;
        }

        const summary = `
            <div class="summary">
                <div><span class="stat-label">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.INVENTORY_SERVICES', 'Services'))}</span><span class="stat-value">${totals.services || 0}</span></div>
                <div><span class="stat-label">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.INVENTORY_STORED', 'Stored items'))}</span><span class="stat-value">${totals.cookies || 0}</span></div>
                <div><span class="stat-label">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.INVENTORY_CATEGORIES', 'Categories'))}</span><span class="stat-value">${groups.length}</span></div>
                <div><span class="stat-label">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.INVENTORY_VERSION', 'Policy version'))}</span><span class="stat-value">${data.version || 0}</span></div>
            </div>`;

        const body = groups.map((group) => {
            const rows = (group.services || []).map((service) => {
                const stores = (service.cookies || []).length
                    ? `<div class="stores">${service.cookies.map((c) => `
                        <div class="store-row">
                            <code>${this._escape(c.name)}</code>
                            ${c.type && c.type !== 'cookie' ? `<span class="kind">${this._escape(c.type.replace('_', ' '))}</span>` : ''}
                            ${c.duration ? `<span class="muted">${this._escape(c.duration)}</span>` : ''}
                        </div>`).join('')}</div>`
                    : `<span class="muted">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.NOTHING_STORED', 'Nothing stored'))}</span>`;

                return `
                    <tr>
                        <td>
                            <div class="name">${this._escape(service.name)}</div>
                            ${service.description ? `<div class="desc muted">${this._escape(service.description)}</div>` : ''}
                            ${service.privacy_url ? `<div class="desc"><a href="${this._escape(service.privacy_url)}" target="_blank" rel="noopener noreferrer">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.PRIVACY_POLICY', 'Privacy policy'))}</a></div>` : ''}
                        </td>
                        <td>${service.provider ? this._escape(service.provider) : '<span class="muted">—</span>'}</td>
                        <td>${stores}</td>
                        <td><span class="muted">${this._escape(this._sourceLabel(service.source))}</span></td>
                    </tr>`;
            }).join('');

            const inner = rows
                ? `<div class="table-scroll"><table>
                        <thead><tr>
                            <th>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.COLUMN_SERVICE', 'Service'))}</th><th>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.COLUMN_RUN_BY', 'Run by'))}</th><th>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.COLUMN_STORES', 'Stores'))}</th><th>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.COLUMN_SOURCE', 'Registered by'))}</th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                   </table></div>`
                : `<div class="empty muted">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.CATEGORY_EMPTY', 'Nothing on this site uses this yet.'))}</div>`;

            return `
                <div class="group">
                    <div class="group-head">
                        <span class="group-title">${this._escape(group.label)}</span>
                        ${group.required ? `<span class="pill">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.ALWAYS_ON', 'Always on'))}</span>` : ''}
                        <span class="count">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.SERVICE_COUNT', '{n} services · {stored} stored', { n: (group.services || []).length, stored: group.cookie_count || 0 }))}</span>
                    </div>
                    ${inner}
                </div>`;
        }).join('');

        const note = data.autoblock
            ? ''
            : `<p class="muted">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.AUTOBLOCK_OFF_NOTE', 'Auto-blocking is off, so this lists only what your config and your plugins declare — a script pasted into a template will not appear.'))}</p>`;

        this.shadowRoot.innerHTML = `<style>${this._styles()}</style>${summary}${body}${note}`;
    }
}

customElements.define(TAG, ConsentInventoryField);
