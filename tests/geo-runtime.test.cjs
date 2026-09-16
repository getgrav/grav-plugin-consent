const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/consent.js'), 'utf8');
const url = 'https://api.country.is/';
const key = 'grav-consent-country:' + url;

function boot(options = {}) {
    const config = {
        version: 1, cookie: { name: 'consent' }, honorGpc: true,
        categories: [{ id: 'necessary', required: true }, { id: 'analytics', required: false }],
        geo: { mode: 'eu', provider: 'country_is', url, countries: ['DE', 'GB', 'CH'] },
        ...options.config
    };
    const panel = () => ({ hidden: true, querySelector: () => null, querySelectorAll: () => [] });
    const banner = panel(), prefs = panel(), badge = panel();
    const root = {
        querySelector: selector => ({
            '[data-consent-payload]': { textContent: JSON.stringify(config) },
            '[data-consent-panel="banner"]': banner,
            '[data-consent-panel="preferences"]': prefs,
            '[data-consent-badge]': badge
        })[selector] || null,
        querySelectorAll: () => []
    };
    const listeners = {};
    const events = [];
    const document = {
        cookie: options.cookie || '', activeElement: null,
        documentElement: { style: {} },
        querySelector: selector => selector === '[data-consent-banner]' ? root : null,
        querySelectorAll: () => [],
        addEventListener: (type, fn) => { (listeners[type] ||= []).push(fn); },
        dispatchEvent: event => events.push(event)
    };
    if (options.cookiesBlocked) {
        Object.defineProperty(document, 'cookie', { get: () => '', set: () => {} });
    }
    const storage = options.storage || new Map();
    const timers = new Map();
    let resolve, reject, timerId = 0;
    const calls = [];
    const context = {
        document, navigator: { globalPrivacyControl: !!options.gpc },
        location: { hostname: 'example.test', pathname: '/' },
        sessionStorage: {
            getItem: name => { if (options.storageBlocked) throw Error(); return storage.get(name) || null; },
            setItem: (name, value) => { if (options.storageBlocked) throw Error(); storage.set(name, value); }
        },
        setTimeout: (fn, ms) => { timers.set(++timerId, { fn, ms }); return timerId; },
        clearTimeout: id => timers.delete(id),
        AbortController: options.noAbort ? undefined : AbortController,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; } },
        fetch: options.noFetch ? undefined : (requestUrl, init) => {
            calls.push({ url: requestUrl, options: init });
            if (options.throwFetch) throw Error('blocked');
            return new Promise((yes, no) => { resolve = yes; reject = no; });
        }
    };
    context.window = context;
    vm.runInNewContext(source, context);
    const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
    return {
        banner, prefs, badge, document, storage, calls, events, api: context.gravConsent,
        respond: async (country, ok = true) => { resolve({ ok, json: async () => ({ ip: '192.0.2.1', country }) }); await flush(); },
        malformed: async () => { resolve({ ok: true, json: async () => { throw Error('invalid JSON'); } }); await flush(); },
        fail: async () => { reject(Error('network')); await flush(); },
        timeout: () => { const timer = [...timers.values()][0]; assert.equal(timer.ms, 3000); timer.fn(); },
        dismiss: () => listeners.keydown.forEach(fn => fn({ key: 'Escape' }))
    };
}

const saved = version => 'consent=' + encodeURIComponent(JSON.stringify({ v: version, c: ['necessary'], t: 1, r: 'test' }));

test('default/everyone mode shows immediately with no lookup', () => {
    for (const geo of [undefined, { mode: 'all' }, { mode: 'invalid' }]) {
        const page = boot({ config: { geo } });
        assert.equal(page.banner.hidden, false);
        assert.equal(page.calls.length, 0);
    }
});

test('matching country prompts, without consenting or retaining the IP', async () => {
    const page = boot();
    assert.equal(page.banner.hidden, true);
    assert.equal(page.api.granted('analytics'), false);
    await page.respond(' de ');
    assert.equal(page.banner.hidden, false);
    assert.equal(page.api.state(), null);
    assert.equal(page.document.cookie.startsWith('consent='), false);
    assert.equal(page.api.granted('analytics'), false);
    assert.equal(JSON.parse(page.storage.get(key)).country, 'DE');
    assert.equal(page.storage.get(key).includes('192.0.2.1'), false);
    assert.equal(page.calls[0].options.credentials, 'omit');
    assert.equal(page.calls[0].options.referrerPolicy, 'no-referrer');
    assert.equal(page.calls[0].options.cache, 'no-store');
});

test('outside scope can stay blocked and allow manual consent', async () => {
    const page = boot({ config: { geo: { mode: 'eu', url, countries: ['DE'], outsideScope: 'deny' } } });
    await page.respond('US');
    assert.equal(page.banner.hidden, true);
    assert.equal(page.badge.hidden, false);
    assert.equal(page.api.granted('analytics'), false);
    page.api.open();
    assert.equal(page.prefs.hidden, false);
    page.api.acceptAll();
    assert.equal(page.api.granted('analytics'), true);
});

test('custom country list controls prompting', async () => {
    const page = boot({ config: { geo: { mode: 'custom', url, countries: ['US'] } } });
    await page.respond('US');
    assert.equal(page.banner.hidden, false);
});

test('header lookup uses the configured same-site endpoint', async () => {
    const page = boot({ config: { geo: { mode: 'eu', url: '/sub/_consent/country', countries: ['DE'] } } });
    assert.equal(page.calls[0].url, '/sub/_consent/country');
    await page.respond('DE');
    assert.equal(page.banner.hidden, false);
});

for (const country of [null, '', 'XX', 'T1', 'ZZ', 'USA', {}, 42]) {
    test('unknown/malformed country prompts: ' + JSON.stringify(country), async () => {
        const page = boot();
        await page.respond(country);
        assert.equal(page.banner.hidden, false);
        assert.equal(page.storage.size, 0);
    });
}

for (const failure of ['http', 'json', 'network', 'timeout']) {
    test(failure + ' failure prompts', async () => {
        const page = boot();
        if (failure === 'http') await page.respond('US', false);
        if (failure === 'json') await page.malformed();
        if (failure === 'network') await page.fail();
        if (failure === 'timeout') page.timeout();
        assert.equal(page.banner.hidden, false);
        assert.equal(page.api.granted('analytics'), false);
    });
}

for (const options of [{ noFetch: true }, { throwFetch: true }]) {
    test('missing/blocked fetch prompts', () => {
        assert.equal(boot(options).banner.hidden, false);
    });
}

test('late response after timeout cannot cache or dismiss the fallback', async () => {
    const page = boot({ noAbort: true });
    page.timeout();
    await page.respond('US');
    assert.equal(page.banner.hidden, false);
    assert.equal(page.storage.size, 0);
});

test('saved decisions avoid lookup; a stale policy triggers it', () => {
    const page = boot({ cookie: saved(1) });
    assert.equal(page.calls.length, 0);
    assert.equal(page.banner.hidden, true);
    assert.equal(page.api.granted('analytics'), false);
    assert.equal(boot({ cookie: saved(0) }).calls.length, 1);
});

for (const action of ['open', 'rejectAll', 'acceptAll', 'reset', 'dismiss']) {
    test('late lookup respects manual action: ' + action, async () => {
        const page = boot();
        if (action === 'dismiss') { page.api.open(); page.dismiss(); }
        else page.api[action]();
        await page.respond('DE');
        assert.equal(page.banner.hidden, action !== 'reset');
        assert.equal(page.prefs.hidden, action !== 'open');
    });
}

test('cached countries reuse the lookup but re-evaluate the selected countries', async () => {
    const first = boot();
    await first.respond('US');
    const page = boot({ storage: first.storage, config: { geo: { mode: 'custom', url, countries: ['US'] } } });
    assert.equal(page.calls.length, 0);
    assert.equal(page.banner.hidden, false);
});

test('expired/corrupt cache is ignored, and disabled storage works', async () => {
    for (const value of ['{', JSON.stringify({ country: 'US', expires: Date.now() - 1 }), JSON.stringify({ country: 'XX', expires: Date.now() + 1000 })]) {
        assert.equal(boot({ storage: new Map([[key, value]]) }).calls.length, 1);
    }
    const page = boot({ storageBlocked: true });
    await page.respond('DE');
    assert.equal(page.banner.hidden, false);
});

test('GPC never grants optional services outside selected countries', async () => {
    const page = boot({ gpc: true });
    await page.respond('US');
    assert.equal(page.api.granted('analytics'), false);
    assert.equal(page.document.cookie.startsWith('consent='), false);
});


test('outside selected countries allows automatically by default without a consent decision', async () => {
    const page = boot();
    await page.respond('US');
    assert.equal(page.banner.hidden, true);
    assert.equal(page.api.granted('analytics'), true);
    assert.equal(page.api.granted('nonexistent'), false);
    assert.equal(page.api.state(), null);
    assert.equal(page.document.cookie.startsWith('consent_country='), true);
    assert.equal(page.events.at(-1).detail.geographic, true);
    page.api.rejectAll();
    assert.equal(page.api.granted('analytics'), false);
    assert.equal(page.events.at(-1).detail.changed.includes('analytics'), true);
    assert.equal(page.events.at(-1).detail.first, true);
});

test('dynamic mode falls back to prompting if country cookies are blocked', async () => {
    const page = boot({ cookiesBlocked: true, config: { mode: 'dynamic' } });
    await page.respond('US');
    assert.equal(page.banner.hidden, false);
    assert.equal(page.api.granted('analytics'), false);
});

test('dynamic header lookup ignores a country cached before a network change', async () => {
    const headerUrl = '/_consent/country';
    const page = boot({
        storage: new Map([['grav-consent-country:' + headerUrl, JSON.stringify({ country: 'US', expires: Date.now() + 100000 })]]),
        config: { mode: 'dynamic', geo: { mode: 'eu', provider: 'header', url: headerUrl, countries: ['DE'] } }
    });
    assert.equal(page.calls.length, 1);
    await page.respond('DE');
    assert.equal(page.banner.hidden, false);
    assert.equal(page.api.granted('analytics'), false);
});
