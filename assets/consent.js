/*
 * Consent — client runtime
 *
 * No dependencies, no build step. Everything it needs arrives in the
 * <script type="application/json" data-consent-payload> block the plugin
 * renders alongside the banner.
 *
 * The cookie is written here rather than by the server, which is what makes a
 * grant take effect the moment somebody clicks: scripts activate and embeds
 * load in place, with no round trip and no reload. The server endpoint is only
 * told about the decision afterwards, so it can write the audit record and let
 * other plugins clean up cookies JavaScript is not allowed to touch.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-consent-banner]');
    if (!root) {
        return;
    }

    var payloadEl = root.querySelector('[data-consent-payload]');
    var config;
    try {
        config = JSON.parse(payloadEl ? payloadEl.textContent : '{}');
    } catch (e) {
        return;
    }
    if (!config || !config.categories) {
        return;
    }

    var COOKIE = config.cookie || {};
    var CATEGORIES = config.categories;
    var SERVICES = config.services || [];
    var STRINGS = config.strings || {};

    var requiredIds = CATEGORIES.filter(function (c) { return c.required; }).map(function (c) { return c.id; });
    var allIds = CATEGORIES.map(function (c) { return c.id; });

    var banner = root.querySelector('[data-consent-panel="banner"]');
    var prefs = root.querySelector('[data-consent-panel="preferences"]');
    var backdrop = root.querySelector('[data-consent-backdrop]');
    var badge = root.querySelector('[data-consent-badge]');

    var state = readState();
    var lastFocus = null;

    // ── storage ─────────────────────────────────────────────────────────────

    function readRawCookie(name) {
        var parts = document.cookie ? document.cookie.split('; ') : [];
        for (var i = 0; i < parts.length; i++) {
            var eq = parts[i].indexOf('=');
            if (eq > -1 && parts[i].slice(0, eq) === name) {
                return parts[i].slice(eq + 1);
            }
        }
        return null;
    }

    function readState() {
        var raw = readRawCookie(COOKIE.name);
        if (!raw) {
            return null;
        }
        var data;
        try {
            data = JSON.parse(decodeURIComponent(raw));
        } catch (e) {
            return null;
        }
        if (!data || !Array.isArray(data.c)) {
            return null;
        }
        // A decision made against a different inventory is not a decision
        // about this one — the banner comes back rather than assuming.
        if (Number(data.v) !== Number(config.version)) {
            return null;
        }
        return { categories: data.c, version: Number(data.v), time: Number(data.t) || 0, id: String(data.r || '') };
    }

    function writeState(next) {
        var value = encodeURIComponent(JSON.stringify({
            v: next.version,
            t: next.time,
            c: next.categories,
            r: next.id
        }));

        var parts = [
            COOKIE.name + '=' + value,
            'path=' + (COOKIE.path || '/'),
            'max-age=' + ((COOKIE.days || 180) * 86400),
            'SameSite=' + (COOKIE.sameSite || 'Lax')
        ];
        if (COOKIE.domain) {
            parts.push('domain=' + COOKIE.domain);
        }
        if (COOKIE.secure) {
            parts.push('Secure');
        }

        document.cookie = parts.join('; ');
        state = next;
    }

    function clearCookie(name) {
        var bases = [
            name + '=; max-age=0; path=' + (COOKIE.path || '/'),
            name + '=; max-age=0; path=/'
        ];
        // A third-party script very often sets its cookie on the registrable
        // domain rather than the exact host, so clearing the host-only copy
        // alone leaves it in place. Walk the domain upwards and clear each.
        var host = location.hostname.split('.');
        while (host.length > 1) {
            bases.push(name + '=; max-age=0; path=/; domain=.' + host.join('.'));
            host.shift();
        }
        for (var i = 0; i < bases.length; i++) {
            document.cookie = bases[i];
        }
    }

    function matchesPattern(name, pattern) {
        if (pattern.indexOf('*') === -1) {
            return name === pattern;
        }
        var escaped = pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
        return new RegExp('^' + escaped + '$').test(name);
    }

    // ── consent questions ───────────────────────────────────────────────────

    function granted(category) {
        if (requiredIds.indexOf(category) > -1) {
            return true;
        }
        return !!state && state.categories.indexOf(category) > -1;
    }

    function gpcActive() {
        if (!config.honorGpc) {
            return false;
        }
        return navigator.globalPrivacyControl === true || config.gpcSignal === true;
    }

    // ── activation ──────────────────────────────────────────────────────────

    /**
     * Re-create a script element so the browser actually runs it.
     *
     * Flipping `type` on an existing <script> does nothing — the browser has
     * already decided not to execute it. A fresh node is the only way.
     */
    function activateScript(el) {
        var next = document.createElement('script');

        for (var i = 0; i < el.attributes.length; i++) {
            var attr = el.attributes[i];
            if (attr.name === 'type' || attr.name === 'data-consent-src' || attr.name === 'data-consent-inline') {
                continue;
            }
            next.setAttribute(attr.name, attr.value);
        }

        var src = el.getAttribute('data-consent-src');
        if (src) {
            next.src = src;
        } else {
            next.textContent = el.textContent;
        }

        el.parentNode.replaceChild(next, el);
    }

    function activateFrame(el) {
        var src = el.getAttribute('data-consent-src');
        if (!src) {
            return;
        }
        el.removeAttribute('data-consent-frame');
        el.removeAttribute('data-consent-src');
        el.src = src;

        var note = el.previousElementSibling;
        if (note && note.hasAttribute && note.hasAttribute('data-consent-autoplaceholder')) {
            note.parentNode.removeChild(note);
        }
    }

    function activatePixel(el) {
        var src = el.getAttribute('data-consent-src');
        if (!src) {
            return;
        }
        el.removeAttribute('data-consent-pixel');
        el.removeAttribute('data-consent-src');
        el.src = src;
    }

    /**
     * Swap a {% consent %} placeholder for the markup it was holding.
     *
     * Scripts inside a <template> do not run when the fragment is cloned in,
     * so they are re-created on the way through — the same trick as above.
     */
    function activatePlaceholder(el) {
        var template = el.querySelector('template[data-consent-content]');
        if (!template) {
            // Dynamic render mode: the content was never sent, so the only way
            // to show it is to fetch the page again now that consent exists.
            location.reload();
            return;
        }

        var fragment = template.content.cloneNode(true);
        var scripts = fragment.querySelectorAll('script');
        for (var i = 0; i < scripts.length; i++) {
            var old = scripts[i];
            var next = document.createElement('script');
            for (var a = 0; a < old.attributes.length; a++) {
                next.setAttribute(old.attributes[a].name, old.attributes[a].value);
            }
            next.textContent = old.textContent;
            old.parentNode.replaceChild(next, old);
        }

        el.parentNode.replaceChild(fragment, el);
    }

    /**
     * Put an explanation in front of an auto-blocked iframe.
     *
     * Auto-blocking works on finished HTML, so there is no server-side
     * placeholder to render — without this, a blocked YouTube embed would just
     * be an invisible gap with no way to find out why.
     */
    function describeBlockedFrame(el) {
        if (el.previousElementSibling && el.previousElementSibling.hasAttribute
            && el.previousElementSibling.hasAttribute('data-consent-autoplaceholder')) {
            return;
        }

        var serviceId = el.getAttribute('data-consent-service');
        var service = findService(serviceId);
        var category = el.getAttribute('data-consent-category');

        var wrap = document.createElement('div');
        wrap.className = 'consent-root consent-placeholder';
        wrap.setAttribute('data-consent-autoplaceholder', '');
        wrap.setAttribute('data-consent-appearance', root.getAttribute('data-consent-appearance') || 'auto');

        var inner = document.createElement('div');
        inner.className = 'consent-placeholder-inner';

        var title = document.createElement('p');
        title.className = 'consent-placeholder-title';
        title.textContent = STRINGS.placeholderTitle || 'This content is blocked';

        var body = document.createElement('p');
        body.className = 'consent-placeholder-body';
        body.textContent = (service ? service.name + '. ' : '') + (STRINGS.placeholderBody || '');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'consent-btn consent-btn-solid';
        button.setAttribute('data-consent-allow', category || '');
        button.textContent = STRINGS.placeholderButton || 'Allow and load';

        inner.appendChild(title);
        inner.appendChild(body);
        inner.appendChild(button);
        wrap.appendChild(inner);

        el.parentNode.insertBefore(wrap, el);
    }

    function findService(id) {
        for (var i = 0; i < SERVICES.length; i++) {
            if (SERVICES[i].id === id) {
                return SERVICES[i];
            }
        }
        return null;
    }

    /**
     * Walk the page and bring everything into line with the current decision.
     *
     * Safe to call repeatedly — activation removes the marker attributes it
     * keys off, so nothing is ever activated twice.
     */
    function apply() {
        var i;

        // Placeholders go first. Their markup lives in a <template>, which
        // querySelectorAll cannot see into, so anything the auto-blocker
        // neutralised inside one is invisible until the swap has happened —
        // and would otherwise sit blocked until the next decision.
        var placeholders = document.querySelectorAll('[data-consent-placeholder]');
        for (i = 0; i < placeholders.length; i++) {
            if (granted(placeholders[i].getAttribute('data-consent-category'))) {
                activatePlaceholder(placeholders[i]);
            }
        }

        var scripts = document.querySelectorAll('script[data-consent-src], script[data-consent-inline]');
        for (i = 0; i < scripts.length; i++) {
            if (granted(scripts[i].getAttribute('data-consent-category'))) {
                activateScript(scripts[i]);
            }
        }

        var frames = document.querySelectorAll('iframe[data-consent-frame]');
        for (i = 0; i < frames.length; i++) {
            if (granted(frames[i].getAttribute('data-consent-category'))) {
                activateFrame(frames[i]);
            } else {
                describeBlockedFrame(frames[i]);
            }
        }

        var pixels = document.querySelectorAll('img[data-consent-pixel]');
        for (i = 0; i < pixels.length; i++) {
            if (granted(pixels[i].getAttribute('data-consent-category'))) {
                activatePixel(pixels[i]);
            }
        }
    }

    /** Load any managed script whose element is not on the page yet. */
    function runManagedScripts() {
        var scripts = config.scripts || [];
        for (var i = 0; i < scripts.length; i++) {
            var script = scripts[i];
            if (!granted(script.category)) {
                continue;
            }
            var marker = 'consent-managed-' + script.service + '-' + i;
            if (document.querySelector('[data-consent-managed="' + marker + '"]')) {
                continue;
            }
            // Only needed when the server did not already emit a tag for this
            // service — in cached mode it did, and apply() has activated it.
            // Scoped to <script> on purpose: a {% consent %} placeholder can
            // carry the same service id, and that is not the same thing.
            if (document.querySelector('script[data-consent-service="' + script.service + '"]')) {
                continue;
            }

            var el = document.createElement('script');
            el.setAttribute('data-consent-managed', marker);
            if (script.async) { el.async = true; }
            if (script.defer) { el.defer = true; }
            if (script.attrs) {
                for (var name in script.attrs) {
                    if (Object.prototype.hasOwnProperty.call(script.attrs, name)) {
                        el.setAttribute(name, script.attrs[name]);
                    }
                }
            }
            if (script.src) { el.src = script.src; } else { el.textContent = script.inline || ''; }
            document.head.appendChild(el);
        }
    }

    /** Clear what a service owns when its category is no longer granted. */
    function cleanUp(deniedCategories) {
        for (var i = 0; i < SERVICES.length; i++) {
            var service = SERVICES[i];
            if (deniedCategories.indexOf(service.category) === -1) {
                continue;
            }

            var patterns = service.revoke_cookies || [];
            if (patterns.length) {
                var names = document.cookie.split('; ').map(function (part) {
                    return part.split('=')[0];
                });
                for (var n = 0; n < names.length; n++) {
                    for (var p = 0; p < patterns.length; p++) {
                        if (matchesPattern(names[n], patterns[p])) {
                            clearCookie(names[n]);
                        }
                    }
                }
            }

            var keys = service.revoke_storage || [];
            for (var k = 0; k < keys.length; k++) {
                try { localStorage.removeItem(keys[k]); } catch (e) { /* private mode */ }
                try { sessionStorage.removeItem(keys[k]); } catch (e) { /* private mode */ }
            }
        }
    }

    // ── Google Consent Mode ─────────────────────────────────────────────────

    function pushConsentMode() {
        var mode = config.consentMode;
        if (!mode || !mode.enabled) {
            return;
        }

        var update = {};
        for (var category in mode.mapping) {
            if (!Object.prototype.hasOwnProperty.call(mode.mapping, category)) {
                continue;
            }
            var value = granted(category) ? 'granted' : 'denied';
            var signals = mode.mapping[category];
            for (var i = 0; i < signals.length; i++) {
                update[signals[i]] = value;
            }
        }

        window.dataLayer = window.dataLayer || [];
        if (typeof window.gtag !== 'function') {
            window.gtag = function () { window.dataLayer.push(arguments); };
        }
        window.gtag('consent', 'update', update);
    }

    // ── reporting ───────────────────────────────────────────────────────────

    function report(method) {
        if (!config.endpoint) {
            return;
        }

        var body = JSON.stringify({
            categories: state ? state.categories : [],
            method: method,
            url: location.pathname,
            gpc: gpcActive()
        });

        // keepalive so a decision made on the way out of the page still gets
        // recorded — clicking Accept and immediately navigating is common.
        try {
            fetch(config.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                credentials: 'same-origin',
                keepalive: true
            }).catch(function () { /* the decision already applies locally */ });
        } catch (e) { /* ignore */ }
    }

    // ── decisions ───────────────────────────────────────────────────────────

    function decide(categories, method) {
        var previous = state ? state.categories.slice() : [];

        var next = requiredIds.slice();
        for (var i = 0; i < categories.length; i++) {
            if (allIds.indexOf(categories[i]) > -1 && next.indexOf(categories[i]) === -1) {
                next.push(categories[i]);
            }
        }

        writeState({
            categories: next,
            version: Number(config.version),
            time: Math.floor(Date.now() / 1000),
            id: randomId()
        });

        var denied = allIds.filter(function (id) { return next.indexOf(id) === -1; });
        var changed = allIds.filter(function (id) {
            return (previous.indexOf(id) > -1) !== (next.indexOf(id) > -1);
        });

        cleanUp(denied);
        apply();
        runManagedScripts();
        pushConsentMode();
        report(method);
        closeAll();
        syncBadge();

        emit({ granted: next, denied: denied, changed: changed, first: previous.length === 0 });
    }

    function emit(detail) {
        document.dispatchEvent(new CustomEvent('consent:changed', { detail: detail }));
    }

    function randomId() {
        var crypto = window.crypto || window.msCrypto;
        if (crypto && typeof crypto.getRandomValues === 'function') {
            var bytes = new Uint8Array(8);
            crypto.getRandomValues(bytes);
            return Array.prototype.map.call(bytes, function (b) {
                return ('0' + b.toString(16)).slice(-2);
            }).join('');
        }

        // The id only ties a cookie to its log entry — it is not a secret and
        // nothing is authorised by it. Failing to record a decision because an
        // old browser has no crypto would be the worse outcome.
        var out = '';
        while (out.length < 16) {
            out += Math.floor(Math.random() * 16).toString(16);
        }
        return out;
    }

    // ── panels ──────────────────────────────────────────────────────────────

    function showBanner() {
        if (!banner) {
            return;
        }
        banner.hidden = false;
        if (backdrop) {
            backdrop.hidden = false;
        }
        if (config.blocking) {
            document.documentElement.style.overflow = 'hidden';
        }
        var heading = banner.querySelector('.consent-title');
        if (heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus({ preventScroll: true });
        }
    }

    function openPrefs() {
        if (!prefs) {
            return;
        }
        lastFocus = document.activeElement;
        syncToggles();
        prefs.hidden = false;
        if (banner) {
            banner.hidden = true;
        }
        document.documentElement.style.overflow = 'hidden';

        var first = prefs.querySelector('input, button');
        if (first) {
            first.focus({ preventScroll: true });
        }
    }

    function closeAll() {
        if (prefs) {
            prefs.hidden = true;
        }
        if (banner) {
            banner.hidden = true;
        }
        if (backdrop) {
            backdrop.hidden = true;
        }
        document.documentElement.style.overflow = '';
        if (lastFocus && lastFocus.focus) {
            lastFocus.focus({ preventScroll: true });
            lastFocus = null;
        }
    }

    /** Dismissed without answering. Not consent — the banner comes back. */
    function dismiss() {
        if (config.blocking) {
            return;
        }
        closeAll();
        syncBadge();
    }

    function syncToggles() {
        var toggles = prefs ? prefs.querySelectorAll('[data-consent-toggle]') : [];
        var gpc = gpcActive();

        for (var i = 0; i < toggles.length; i++) {
            var id = toggles[i].getAttribute('data-consent-toggle');
            if (requiredIds.indexOf(id) > -1) {
                toggles[i].checked = true;
                continue;
            }
            if (state) {
                toggles[i].checked = state.categories.indexOf(id) > -1;
            } else if (gpc) {
                // A browser asking not to be tracked starts everything
                // optional switched off, whatever the site pre-ticked.
                toggles[i].checked = false;
            } else {
                toggles[i].checked = isDefault(id);
            }
        }
    }

    function isDefault(id) {
        for (var i = 0; i < CATEGORIES.length; i++) {
            if (CATEGORIES[i].id === id) {
                return !!CATEGORIES[i].default;
            }
        }
        return false;
    }

    function selectedCategories() {
        var out = requiredIds.slice();
        var toggles = prefs ? prefs.querySelectorAll('[data-consent-toggle]') : [];
        for (var i = 0; i < toggles.length; i++) {
            if (toggles[i].checked) {
                var id = toggles[i].getAttribute('data-consent-toggle');
                if (out.indexOf(id) === -1) {
                    out.push(id);
                }
            }
        }
        return out;
    }

    /**
     * Show the corner badge only when the theme has not provided its own way
     * back. Withdrawal has to be as easy as consent, so a theme that has done
     * nothing is still covered, and one with a footer link stays uncluttered.
     */
    function syncBadge() {
        if (!badge) {
            return;
        }
        var mode = config.reopen || 'auto';
        if (mode === 'none') {
            badge.hidden = true;
            return;
        }
        if (mode === 'badge') {
            badge.hidden = false;
            return;
        }
        var themeLink = document.querySelector('[data-consent-open]:not([data-consent-badge])');
        badge.hidden = !!themeLink;
    }

    // ── wiring ──────────────────────────────────────────────────────────────

    document.addEventListener('click', function (event) {
        var open = event.target.closest ? event.target.closest('[data-consent-open]') : null;
        if (open) {
            event.preventDefault();
            openPrefs();
            return;
        }

        var allow = event.target.closest ? event.target.closest('[data-consent-allow]') : null;
        if (allow) {
            event.preventDefault();
            var category = allow.getAttribute('data-consent-allow');
            var next = state ? state.categories.slice() : requiredIds.slice();
            if (next.indexOf(category) === -1) {
                next.push(category);
            }
            decide(next, 'save_preferences');
            return;
        }

        var action = event.target.closest ? event.target.closest('[data-consent-action]') : null;
        if (!action) {
            return;
        }

        event.preventDefault();
        switch (action.getAttribute('data-consent-action')) {
            case 'accept':
                decide(allIds.slice(), 'accept_all');
                break;
            case 'reject':
                decide(requiredIds.slice(), 'reject_all');
                break;
            case 'save':
                decide(selectedCategories(), 'save_preferences');
                break;
            case 'preferences':
                openPrefs();
                break;
            case 'close':
                dismiss();
                break;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        if (prefs && !prefs.hidden) {
            dismiss();
        }
    });

    var FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select, textarea, summary, [tabindex]:not([tabindex="-1"])';

    function focusableIn(panel) {
        return Array.prototype.filter.call(
            panel.querySelectorAll(FOCUSABLE),
            function (el) { return el.offsetParent !== null || el === document.activeElement; }
        );
    }

    /**
     * Keep Tab inside the preferences panel while it is modal.
     *
     * Wrapping both ways rather than always jumping to the first control:
     * Shift+Tab off the first element should reach the last one, which is what
     * anyone driving this from the keyboard expects.
     */
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab' || !prefs || prefs.hidden) {
            return;
        }

        var items = focusableIn(prefs);
        if (items.length === 0) {
            return;
        }

        var first = items[0];
        var last = items[items.length - 1];

        if (event.shiftKey && (document.activeElement === first || !prefs.contains(document.activeElement))) {
            event.preventDefault();
            last.focus({ preventScroll: true });
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus({ preventScroll: true });
        }
    });

    // A click elsewhere on the page can still move focus out; pull it back.
    document.addEventListener('focusin', function (event) {
        if (!prefs || prefs.hidden || prefs.contains(event.target)) {
            return;
        }
        var items = focusableIn(prefs);
        if (items.length) {
            items[0].focus({ preventScroll: true });
        }
    });

    // ── public API ──────────────────────────────────────────────────────────

    window.gravConsent = {
        granted: granted,
        state: function () { return state ? JSON.parse(JSON.stringify(state)) : null; },
        categories: function () { return CATEGORIES.slice(); },
        services: function () { return SERVICES.slice(); },
        acceptAll: function () { decide(allIds.slice(), 'accept_all'); },
        rejectAll: function () { decide(requiredIds.slice(), 'reject_all'); },
        accept: function (categories) { decide(categories || [], 'api'); },
        open: openPrefs,
        reset: function () {
            clearCookie(COOKIE.name);
            state = null;
            syncToggles();
            showBanner();
            syncBadge();
        }
    };

    // ── boot ────────────────────────────────────────────────────────────────

    apply();
    runManagedScripts();
    pushConsentMode();

    if (!state) {
        var notices = root.querySelectorAll('[data-consent-gpc]');
        for (var i = 0; i < notices.length; i++) {
            notices[i].hidden = !gpcActive();
        }
        syncToggles();
        showBanner();
    }

    syncBadge();

    // Fired on load as well as on change, so a script that arrives late never
    // misses the decision it needs.
    emit({
        granted: state ? state.categories.slice() : [],
        denied: allIds.filter(function (id) { return !granted(id); }),
        changed: [],
        first: false,
        initial: true
    });
})();
