/*
 * Consent — decision log.
 *
 * The page that answers "can you show me consent was given?", which is what
 * GDPR Article 7(1) actually asks of a controller. A summary strip for the
 * shape of recent decisions, the records themselves, CSV for whoever asked by
 * email, and a way to clear it that says plainly what is lost.
 */
const TAG = window.__GRAV_PAGE_TAG;

class ConsentPage extends HTMLElement {
    constructor() {
        super();
        this._stats = null;
        this._records = [];
        this._total = 0;
        this._page = 1;
        this._perPage = 25;
        this._method = '';
        this._loading = true;
        this._error = null;
        this._config = { log_enabled: true, retain_days: 400 };
        this.attachShadow({ mode: 'open' });
    }

    connectedCallback() {
        this._render();
        this._loadAll();
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

    // ── api ────────────────────────────────────────────────────────────────

    _apiUrl(path) {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }

    _headers(json = false) {
        const h = {};
        // X-API-Token, not Authorization: Bearer — FastCGI setups can strip
        // Authorization before it reaches PHP.
        if (window.__GRAV_API_TOKEN) h['X-API-Token'] = window.__GRAV_API_TOKEN;
        if (json) h['Content-Type'] = 'application/json';
        return h;
    }

    async _api(method, path) {
        const resp = await fetch(this._apiUrl(path), { method, headers: this._headers() });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const json = await resp.json();
        return { data: json.data ?? json, meta: json.meta };
    }

    async _loadAll() {
        this._loading = true;
        this._error = null;
        this._render();

        try {
            const [config, stats, log] = await Promise.all([
                this._api('GET', '/consent/config'),
                this._api('GET', '/consent/stats?days=30'),
                this._api('GET', this._logPath()),
            ]);
            this._config = { ...this._config, ...config.data };
            this._stats = stats.data;
            this._records = log.data || [];
            this._total = log.meta?.total ?? this._records.length;
        } catch (e) {
            this._error = e.message;
        }

        this._loading = false;
        this._render();
    }

    _logPath() {
        const params = new URLSearchParams({ page: String(this._page), per_page: String(this._perPage) });
        if (this._method) params.set('method', this._method);
        return '/consent/log?' + params.toString();
    }

    async _reloadLog() {
        try {
            const log = await this._api('GET', this._logPath());
            this._records = log.data || [];
            this._total = log.meta?.total ?? this._records.length;
        } catch (e) {
            window.__GRAV_TOAST?.error(e.message);
        }
        this._render();
    }

    async _export() {
        try {
            const resp = await fetch(this._apiUrl('/consent/log/export'), { headers: this._headers() });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const blob = await resp.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'consent-log-' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } catch (e) {
            window.__GRAV_TOAST?.error(e.message);
        }
    }

    async _purge() {
        // Never native confirm() — admin-next has its own dialog, and this is
        // exactly the destructive case it exists for.
        const ok = await window.__GRAV_DIALOGS?.confirm({
            title: this._t('PLUGIN_CONSENT.ADMIN.PURGE_TITLE', 'Clear the consent log?'),
            message: this._t('PLUGIN_CONSENT.ADMIN.PURGE_BODY', 'Every record goes. You lose the ability to demonstrate that consent was given, and it cannot be undone.'),
            confirmLabel: this._t('PLUGIN_CONSENT.ADMIN.PURGE_CONFIRM', 'Clear the log'),
            variant: 'destructive',
        });
        if (!ok) return;

        try {
            const resp = await fetch(this._apiUrl('/consent/log'), { method: 'DELETE', headers: this._headers() });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            window.__GRAV_TOAST?.success(this._t('PLUGIN_CONSENT.ADMIN.PURGED', 'Consent log cleared.'));
            this._page = 1;
            await this._loadAll();
        } catch (e) {
            window.__GRAV_TOAST?.error(e.message);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

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

    _methodLabel(method) {
        const map = {
            accept_all: 'Accepted all',
            reject_all: 'Rejected all',
            save_preferences: 'Chose specific',
            gpc_auto: 'Browser signal',
            api: 'Set by code',
        };
        return this._t('PLUGIN_CONSENT.ADMIN.METHOD_' + String(method || '').toUpperCase(), map[method] || method || '—');
    }

    _when(ts) {
        if (!ts) return '—';
        // One date format everywhere on the page: the viewer's locale, short.
        return new Date(ts * 1000).toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
        });
    }

    _escape(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    _styles() {
        return `
            :host { display: block; padding: 4px 0 32px; font-size: 13px; color: var(--foreground); }
            .muted { color: var(--muted-foreground); }
            .bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 16px; }
            .bar .spacer { margin-inline-start: auto; }
            button {
                font: inherit; padding: 6px 12px; border-radius: 6px; cursor: pointer;
                border: 1px solid var(--border); background: var(--background); color: var(--foreground);
            }
            button:hover { background: var(--accent); }
            button.danger { color: var(--destructive); border-color: var(--destructive); }
            button.danger:hover { background: var(--destructive); color: var(--background); }
            select { font: inherit; padding: 6px 10px; border-radius: 6px; border: 1px solid var(--input); background: var(--background); color: var(--foreground); }
            .stats { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; }
            .stat { flex: 1 1 150px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 8px; background: var(--muted); }
            .stat-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted-foreground); }
            .stat-value { font-size: 22px; font-weight: 600; font-variant-numeric: tabular-nums; }
            .rates { display: flex; flex-direction: column; gap: 6px; margin-top: 6px; }
            .rate-row { display: flex; align-items: center; gap: 8px; font-size: 12px; }
            .rate-track { flex: 1 1 auto; height: 6px; border-radius: 999px; background: var(--accent); overflow: hidden; }
            .rate-fill { height: 100%; background: var(--primary); }
            .rate-value { font-variant-numeric: tabular-nums; min-width: 42px; text-align: end; }
            .card { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
            .table-scroll { overflow-x: auto; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 8px 14px; text-align: start; vertical-align: top; border-bottom: 1px solid var(--border); white-space: nowrap; }
            th { font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted-foreground); }
            tr:last-child td { border-bottom: 0; }
            .cats { white-space: normal; display: flex; flex-wrap: wrap; gap: 4px; }
            .chip { padding: 1px 7px; border-radius: 999px; font-size: 11px; background: var(--accent); }
            code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; }
            .empty { padding: 34px 14px; text-align: center; }
            .empty-title { font-weight: 600; margin-bottom: 4px; }
            .notice { padding: 10px 14px; margin-bottom: 16px; border: 1px solid var(--border); border-radius: 8px; background: var(--muted); }
            .pager { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
        `;
    }

    _render() {
        const s = this.shadowRoot;

        if (this._loading) {
            s.innerHTML = `<style>${this._styles()}</style><p class="muted">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.LOADING', 'Loading…'))}</p>`;
            return;
        }

        if (this._error) {
            s.innerHTML = `<style>${this._styles()}</style>
                <div class="card"><div class="empty">
                    <p class="empty-title">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.LOAD_FAILED', 'Could not load the consent log.'))}</p>
                    <p class="muted">${this._escape(this._error)}</p>
                </div></div>`;
            this._wire();
            return;
        }

        const stats = this._stats || {};
        const labels = stats.category_labels || {};
        const rates = stats.category_rates || {};

        const rateRows = Object.keys(rates).map((id) => `
            <div class="rate-row">
                <span>${this._escape(labels[id] || id)}</span>
                <span class="rate-track"><span class="rate-fill" style="width:${Math.min(100, rates[id])}%"></span></span>
                <span class="rate-value">${rates[id]}%</span>
            </div>`).join('');

        const statsBlock = `
            <div class="stats">
                <div class="stat">
                    <span class="stat-label">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.DECISIONS_30D', 'Decisions, last 30 days'))}</span>
                    <span class="stat-value">${stats.total || 0}</span>
                </div>
                <div class="stat">
                    <span class="stat-label">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.ACCEPT_ALL_RATE', 'Accepted everything'))}</span>
                    <span class="stat-value">${stats.accept_all_rate != null ? stats.accept_all_rate + '%' : '—'}</span>
                </div>
                <div class="stat" style="flex: 2 1 320px;">
                    <span class="stat-label">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.ALLOWED_BY_CATEGORY', 'Allowed by category'))}</span>
                    ${rateRows || `<p class="muted" style="margin:6px 0 0;">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.NO_DECISIONS_WINDOW', 'No decisions in this window.'))}</p>`}
                </div>
            </div>`;

        const notice = this._config.log_enabled
            ? ''
            : `<div class="notice">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.LOG_OFF_NOTE', 'Recording is switched off, so nothing new is being written here. Without a record there is no way to demonstrate that consent was given, which is what GDPR Article 7 asks for.'))}</div>`;

        const rows = this._records.map((r) => `
            <tr>
                <td>${this._escape(this._when(r.created))}</td>
                <td>${this._escape(this._methodLabel(r.method))}</td>
                <td><span class="cats">${(r.categories || []).map((c) => `<span class="chip">${this._escape(labels[c] || c)}</span>`).join('')}</span></td>
                <td><code>${this._escape(r.url || '/')}</code></td>
                <td class="muted">${this._escape(r.version || '—')}</td>
                <td class="muted">${r.gpc ? 'GPC' : ''}</td>
            </tr>`).join('');

        const table = this._records.length
            ? `<div class="card"><div class="table-scroll"><table>
                    <thead><tr>
                        <th>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.COLUMN_WHEN', 'When'))}</th><th>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.COLUMN_CHOICE', 'Choice'))}</th><th>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.COLUMN_CATEGORIES', 'Allowed'))}</th><th>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.COLUMN_PAGE', 'Page'))}</th><th>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.COLUMN_VERSION', 'Version'))}</th><th></th>
                    </tr></thead>
                    <tbody>${rows}</tbody>
               </table></div></div>`
            : `<div class="card"><div class="empty">
                    <p class="empty-title">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.NO_RECORDS', 'No decisions recorded yet.'))}</p>
                    <p class="muted">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.NO_RECORDS_HELP', 'Records appear here once visitors start answering the banner.'))}</p>
               </div></div>`;

        const pages = Math.max(1, Math.ceil(this._total / this._perPage));
        const pager = this._total > this._perPage
            ? `<div class="pager">
                    <button id="prev" ${this._page <= 1 ? 'disabled' : ''}>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.PREVIOUS', 'Previous'))}</button>
                    <span class="muted">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.PAGE_OF', 'Page {page} of {pages} · {total} records', { page: this._page, pages: pages, total: this._total }))}</span>
                    <button id="next" ${this._page >= pages ? 'disabled' : ''}>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.NEXT', 'Next'))}</button>
               </div>`
            : '';

        s.innerHTML = `<style>${this._styles()}</style>
            ${notice}
            ${statsBlock}
            <div class="bar">
                <select id="method">
                    <option value="">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.FILTER_ALL', 'All decisions'))}</option>
                    <option value="accept_all" ${this._method === 'accept_all' ? 'selected' : ''}>${this._escape(this._methodLabel('accept_all'))}</option>
                    <option value="reject_all" ${this._method === 'reject_all' ? 'selected' : ''}>${this._escape(this._methodLabel('reject_all'))}</option>
                    <option value="save_preferences" ${this._method === 'save_preferences' ? 'selected' : ''}>${this._escape(this._methodLabel('save_preferences'))}</option>
                </select>
                <button id="refresh">${this._escape(this._t('PLUGIN_CONSENT.ADMIN.REFRESH', 'Refresh'))}</button>
                <span class="spacer"></span>
                <button id="export" ${this._total ? '' : 'disabled'}>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.EXPORT', 'Export CSV'))}</button>
                <button id="purge" class="danger" ${this._total ? '' : 'disabled'}>${this._escape(this._t('PLUGIN_CONSENT.ADMIN.PURGE', 'Clear log'))}</button>
            </div>
            ${table}
            ${pager}
            <p class="muted" style="margin-top:14px;">${this._escape(this._t(
                'PLUGIN_CONSENT.ADMIN.RETENTION_NOTE',
                'Records older than {days} days are removed automatically. IP addresses and browser strings are hashed with a site-specific salt, never stored as they arrived.',
                { days: this._config.retain_days }
            ))}</p>`;

        this._wire();
    }

    _wire() {
        const s = this.shadowRoot;
        s.getElementById('refresh')?.addEventListener('click', () => this._loadAll());
        s.getElementById('export')?.addEventListener('click', () => this._export());
        s.getElementById('purge')?.addEventListener('click', () => this._purge());
        s.getElementById('prev')?.addEventListener('click', () => { this._page--; this._reloadLog(); });
        s.getElementById('next')?.addEventListener('click', () => { this._page++; this._reloadLog(); });
        s.getElementById('method')?.addEventListener('change', (e) => {
            this._method = e.target.value;
            this._page = 1;
            this._reloadLog();
        });
    }
}

customElements.define(TAG, ConsentPage);
