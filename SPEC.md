# Consent — design spec

A first-party cookie consent plugin for Grav 2.0. Built because every one of the seven third-party options is either a JSON-config port of a JS library, has no multilang story, or gives other plugins no way to participate.

Repo `grav-plugin-consent` · slug `consent` · namespace `Grav\Plugin\Consent` · CSS `--consent-*` / `.consent-*` · lang `PLUGIN_CONSENT.*` · events `onConsent*`.

## The one idea

**The cookie inventory is assembled at runtime, not hand-maintained.** Site config holds categories, copy and appearance. The list of what actually stores data on the device comes from three sources merged at request time:

1. Services the site owner declares in plugin config.
2. Services other plugins register through `onConsentRegisterServices`.
3. Services the auto-blocker detects in the rendered output (optional).

Install a plugin that sets a cookie and the banner lists it, with the right category, duration and provider, without anybody editing a config file. That is the whole reason this exists.

## Vocabulary

- **Category** — a consent bucket the visitor toggles. `necessary`, `functional`, `analytics`, `marketing` ship by default; the site owner can rename, reorder, add or remove any except `necessary`.
- **Service** — one named thing that stores or reads data on the device, belonging to exactly one category. "Google Analytics 4", "KahunaCart wishlist", "YouTube embeds".
- **Cookie** — the individual named item a service sets. A service declares zero or more; the names are what the visitor sees in the details table.
- **Decision** — the visitor's stored answer: which categories are granted, when, against which policy version.

---

## 1. Consent storage

One first-party cookie. Name configurable, default `consent`.

```
consent={"v":3,"t":1756800000,"c":["necessary","analytics"],"r":"9f3ac1d0b4e27a51"}
```

| Key | Meaning |
|---|---|
| `v` | Policy version in force when the decision was made |
| `t` | Unix timestamp of the decision |
| `c` | Granted category ids |
| `r` | Record id, linking to the audit log entry |

Value is `encodeURIComponent(JSON.stringify(...))`, so PHP and JS read it identically.

Attributes: `path=/`, `SameSite=Lax`, `Secure` when the request is HTTPS, **not** `HttpOnly` (the client script must read it), `Max-Age` from `cookie.lifetime_days`.

**Default lifetime 180 days.** CNIL's guidance is to re-ask at a reasonable interval and six months is the commonly cited figure; 365 is also defensible and is a one-field change. Help text says so.

A decision is stale — and the banner reappears — when the cookie is absent, unparseable, or its `v` is lower than the current policy version.

### Policy version

`policy_version` is an integer in config. The *effective* version is:

```
effective = policy_version                                    (auto_version: false)
effective = policy_version + crc32(sorted category ids + sorted service ids)   (auto_version: true, the default)
```

Auto-versioning means installing a plugin that registers a new service automatically re-asks everyone, which is the legally correct behavior and something no existing plugin does. It deliberately hashes **only the ids**, not labels or descriptions, so a wording fix or a translation update does not invalidate anybody's consent.

**Auto-blocked vendors are excluded from the hash.** They vary by page — one page has a YouTube embed, another does not — so folding them in would make the policy version differ page by page: a visitor who accepted on the home page would be asked again the moment they opened a page with a video, and the decision endpoint, which renders no page and so detects nothing, would record a version matching nobody's cookie. Adding a vendor only auto-blocking knows about and wanting everyone re-asked is what `policy_version` is for.

---

## 2. Rendering and cache safety

The rendered HTML must be byte-identical regardless of the visitor's consent state, or a full-page cache (Cloudflare, Varnish, a Grav page-cache plugin) will serve one visitor's consent state to everybody. Two modes:

### `render_mode: cached` (default)

- The banner and preferences markup are always injected, identical for everyone. JS decides visibility from the cookie.
- Managed scripts are always emitted as inert `<script type="text/plain" data-consent-src="…">`. JS rewrites them to live `<script>` on grant.
- `{% consent %}` blocks always emit the placeholder **and** the real content inside an inert `<template>`. JS swaps them.

Safe behind any cache. Grants take effect immediately without a reload, which is a real UX win — the visitor clicks Accept and the video is there.

### `render_mode: dynamic`

- Managed scripts are omitted from the HTML entirely when not granted.
- `{% consent %}` renders the real content directly when granted, so no swap flicker.
- The response gets `Vary: Cookie`, emitted directly because Grav builds page headers from the page object and offers no hook for an arbitrary one; its own emitter appends rather than replaces, so this survives.
- `system.pages.never_cache_twig` is forced on. Grav caches the Twig-processed content of a page, so without it the first visitor to arrive decides what everyone else sees — whoever renders before consenting bakes the placeholder in for good. This was a live bug before it was a paragraph.

Nothing about a blocked service appears in the source. Not CDN-friendly. Offered for people who want the tag absent rather than inert.

**Both modes are equally compliant.** An inert `type="text/plain"` script does not execute and stores nothing; regulators care about execution and storage, not about a URL string being present in markup. `dynamic` buys you flicker-free embeds and tidier source, not legality.

### Grav 2.0 gates

Three things about Grav 2.0 shape what works where, and all three were found by running the plugin rather than reading about it:

- **Twig in page content is sandboxed.** A custom tag not on the sandbox allowlist soft-fails, so an author wrapping a YouTube embed would get nothing and no reason why. The plugin answers `onBuildTwigSandboxPolicy` to register `consent`, `consent_granted`, `consent_link` and the `TwigProxy` methods. `consent_banner()` is deliberately left off — placing the banner is a theme's job.
- **`pages.markdown.gfm.tagfilter` escapes raw `<script>` and `<iframe>` in content**, so auto-blocking usually has nothing to find there. It is aimed at snippets pasted into templates, which is where they actually live.
- **Twig in content runs after markdown**, so a block-level tag on its own line lands inside the `<p>` markdown wrapped it in. Browsers cope; `twig_first: true` renders it cleanly.

### Grav caching notes

Grav's core cache is a *content* cache (markdown → HTML per page), not a full-response cache, and `onOutputGenerated` runs on every request — so injection and server-side asset decisions are re-evaluated per request on a stock install. The one trap is `{% consent %}` used inside **page content** with `process.twig` on: that output is cached with the page. Set `never_cache_twig: true` on the page (or site-wide) if you do that. Using the tag in **templates** is always safe — Twig's template cache is compiled code, not rendered output.

---

## 3. Injection

`onOutputGenerated` inserts the rendered banner markup immediately before `</body>`. This is theme-agnostic — no theme edit, no required Twig block, works with Quark 2, kahuna-site, and anything else.

If the output has no `</body>` (a JSON response, a feed, a partial for htmx) nothing is injected. Injection is also skipped for:

- non-HTML content types
- admin routes
- URIs matching `exclude_routes` (glob patterns)
- responses where a `[data-consent-banner]` element already exists, so a theme that wants to place the banner itself can render `{{ consent_banner() }}` in its own template and the automatic injection stands down.

---

## 4. PHP API

`Grav\Plugin\Consent\Consent` — a static facade over a per-request singleton.

```php
Consent::granted(string $category): bool     // false before a decision is made
Consent::grantedService(string $id): bool    // resolves the service's category
Consent::decided(): bool
Consent::state(): ConsentState               // categories, version, timestamp, record id
Consent::categories(): CategoryRegistry
Consent::services(): ServiceRegistry
Consent::enabled(): bool                     // plugin on and not excluded for this request
```

`Consent::granted()` returning `false` before any decision is the whole point: default-deny.

---

## 5. Events

### `onConsentRegisterCategories`

Rarely needed. Lets a plugin add a category the site owner did not define.

```php
$event['categories']->add('social', [
    'label'       => 'PLUGIN_MYPLUGIN.CONSENT.SOCIAL',
    'description' => 'PLUGIN_MYPLUGIN.CONSENT.SOCIAL_DESC',
    'default'     => false,
    'priority'    => 40,
]);
```

### `onConsentRegisterServices`

The main integration point.

```php
public function onConsentRegisterServices(Event $event): void
{
    $event['services']->add('kahunacart-wishlist', [
        'category'    => 'functional',
        'name'        => 'PLUGIN_KAHUNACART.CONSENT.WISHLIST',
        'description' => 'PLUGIN_KAHUNACART.CONSENT.WISHLIST_DESC',
        'provider'    => 'self',                       // 'self' renders as the site name
        'privacy_url' => null,
        'cookies'     => [
            ['name' => 'kahunacart_wishlist', 'duration' => '1 year', 'type' => 'cookie'],
        ],
        'on_revoke'   => ['cookies' => ['kahunacart_wishlist']],
    ]);
}
```

Every string prop is run through Grav's `|t`, so a lang key or a literal both work.

`type` is `cookie` (default), `local_storage`, `session_storage`, `indexed_db` or `pixel` — the details table labels them, because "cookie banner" is a misnomer and ePrivacy covers terminal-equipment storage generally.

`on_revoke.cookies` accepts `*` wildcards and is honored client-side without the plugin writing any JS. Most integrations need nothing beyond this.

**Managed scripts** — a service can hand the plugin the scripts it needs, and they are emitted inert and activated on grant:

```php
'scripts' => [
    ['src' => 'https://www.googletagmanager.com/gtag/js?id=G-XXXX', 'async' => true],
    ['inline' => "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-XXXX');"],
],
```

### `onConsentChanged`

Fired server-side when the browser reports a new decision, before the log record is written. Carries `granted`, `denied`, `previous`, `state`. This is where a plugin expires an `HttpOnly` cookie its own JS cannot touch. Response `Set-Cookie` headers from handlers are passed through.

### `onConsentBannerConfig`

Last chance to mutate the JSON payload handed to the client — extra links, copy overrides, an A/B variant.

---

## 6. Twig integration

**Global** `consent`:

```twig
{% if consent.granted('analytics') %}…{% endif %}
{{ consent.decided }}
{% for category in consent.categories %}…{% endfor %}
```

**Functions**

- `consent_granted('analytics')`
- `consent_link('Cookie settings', {class: 'footer-link'})` → a `<button data-consent-open>`
- `consent_banner()` → renders the banner inline and suppresses automatic injection

**Tag** — a real token parser, because this is the syntax worth having:

```twig
{% consent 'marketing' %}
    <iframe src="https://www.youtube.com/embed/…"></iframe>
{% else %}
    <p>Custom fallback copy.</p>
{% endconsent %}
```

The `{% else %}` branch is optional; without it the plugin renders its standard placeholder — service name, one line of explanation, and a button that grants just that category and loads the content in place. That placeholder is the single highest-value thing in the plugin, because YouTube and Maps embeds are where every site owner gets stuck.

Templates live in `templates/partials/` and are overridable by any theme in the usual Grav way.

---

## 7. Client API

`assets/consent.js` — vanilla, no dependencies, no build step.

```js
window.gravConsent.granted('analytics')   // bool
window.gravConsent.state()                // { categories, version, time, id } | null
window.gravConsent.acceptAll()
window.gravConsent.rejectAll()
window.gravConsent.accept(['analytics'])  // exact set; necessary is always included
window.gravConsent.open()                 // open the preferences panel
window.gravConsent.reset()                // forget the decision, re-show the banner

document.addEventListener('consent:changed', (e) => {
    e.detail.granted;   // ['necessary','analytics']
    e.detail.denied;    // ['functional','marketing']
    e.detail.changed;   // categories whose state differs from before
    e.detail.first;     // true on the visitor's first decision
});
```

`consent:changed` fires on load too (with the stored state) so late-loading scripts never miss it.

### Withdrawal

Withdrawing consent must be as easy as giving it. `reopen` controls the way back:

- `auto` (default) — on load, if the page contains no `[data-consent-open]` element, the plugin shows a small unobtrusive corner badge. If the theme provides a footer link, no badge appears.
- `badge` — always show the badge.
- `none` — the theme takes full responsibility.

`auto` means a theme that has done nothing is still compliant, and a theme that has added a footer link is not cluttered.

---

## 8. Auto-blocking

Off by default, because rewriting output is invasive. On, it is the thirty-second path for someone who pasted a GA snippet into their theme.

`onOutputGenerated` scans for `<script src>`, `<iframe src>` and `<img src>` whose host matches the bundled vendor catalog, neutralizes them (`type="text/plain" data-consent-src`, and `data-consent-frame` for iframes), and auto-registers the matched vendor as a service so the banner lists it accurately.

The catalog (`classes/VendorCatalog.php`) maps host patterns to `{ id, name, provider, category, privacy_url, cookies }` for the common vendors: Google Analytics / Tag Manager / Ads / Maps / reCAPTCHA / Fonts, YouTube, Vimeo, Meta Pixel and embeds, LinkedIn, X, TikTok, Pinterest, Snapchat, Reddit, Microsoft Clarity and UET, Hotjar, Matomo, Plausible, Fathom, Segment, Mixpanel, Amplitude, Sentry, Intercom, Crisp, HubSpot, Mailchimp, Disqus, Twitch, SoundCloud, Spotify, Instagram, Yandex, Baidu, Adobe.

Vendors that are genuinely strictly necessary — Stripe, Cloudflare Turnstile — are catalogued as `necessary` so auto-blocking never breaks a checkout or a captcha.

Escape hatches: `data-consent-ignore` on any element skips it; `autoblock.allow` in config is a host allowlist.

---

## 9. Google Consent Mode v2

Optional, off by default; mandatory in practice for anyone running Google Ads in the EEA.

When on, the plugin emits the default-denied signal in `<head>` before anything else, then updates on every decision:

```js
gtag('consent', 'default', {
  ad_storage:'denied', ad_user_data:'denied', ad_personalization:'denied',
  analytics_storage:'denied', functionality_storage:'denied',
  personalization_storage:'denied', security_storage:'granted',
  wait_for_update: 500
});
```

Category → signal mapping is configurable. Defaults:

| Category | Signals |
|---|---|
| `necessary` | `security_storage` (always granted) |
| `functional` | `functionality_storage`, `personalization_storage` |
| `analytics` | `analytics_storage` |
| `marketing` | `ad_storage`, `ad_user_data`, `ad_personalization` |

---

## 10. Global Privacy Control

`honor_gpc` (default on). When `navigator.globalPrivacyControl` is true, every non-necessary category defaults to off in the preferences panel, and the banner shows a one-line note saying the browser's signal was respected. The visitor can still opt in explicitly.

GPC is legally binding in several US states and is never wrong to honor elsewhere.

---

## 11. Geo scoping

`geo.mode`: `all` (default) | `eu` | `custom`.

No IP database is bundled. `geo.provider` selects `header` (default) or the optional `country_is` browser lookup at `https://api.country.is/`. Header lookup reads an uncached endpoint under `log.endpoint`, with `CF-IPCountry` by default and common country headers/environment variables as fallbacks. Country data never enters shared page HTML. `all` makes no lookup. **If no country can be determined, or lookup takes more than three seconds, the banner shows.** Successful lookups cache only the country and expiry in session storage for up to one hour. country.is also writes a short-lived country cookie for PHP and dynamic rendering, separate from the consent decision. country.is receives the visitor IP before consent; the request sends no credentials or referrer.

`eu` covers the EEA plus the UK and Switzerland. `custom` takes an explicit country list. These are geographic presets, not legal determinations. `geo.outside_scope` selects `allow` (default) or `deny`. Outside the selected countries, optional services run automatically with `allow`, unless a saved decision or GPC says otherwise; `deny` requires explicit consent everywhere. Automatic allowance never writes a consent decision or audit record. Manual preferences remain available. Dynamic geographic responses are private and not cacheable. A late lookup must not reopen a dismissed prompt or interrupt preferences.

---

## 12. Consent log

GDPR Art. 7(1) requires being able to demonstrate that consent was given. This is where the third-party plugins uniformly fall down.

The client POSTs each decision to `log.endpoint` (default `/_consent`, a plain Grav route — **no dependency on the API plugin**). The handler validates, fires `onConsentChanged`, and appends a record.

```json
{
  "id": "9f3ac1d0b4e27a51",
  "created": 1756800000,
  "version": 3,
  "categories": ["necessary", "analytics"],
  "services": ["ga4", "kahunacart-wishlist"],
  "method": "accept_all",
  "ip_hash": "sha256:…",
  "ua_hash": "sha256:…",
  "lang": "en",
  "url": "/shop/checkout",
  "gpc": false
}
```

`method` is one of `accept_all`, `reject_all`, `save_preferences`, `gpc_auto`, `api`.

**The raw IP and user agent are never stored.** Each is hashed with a per-site salt generated on first run and kept at `user/data/consent/.salt`, mode 0600 — beside the log rather than in `user/config/`, so exporting or committing plugin settings never carries it along. Hashing is what makes the log itself defensible — the record proves a decision happened without keeping an identifier. `log.hash_ip` can be turned off entirely, at the cost of weaker proof.

**Backends**: `file` (default) — monthly-rotated JSONL at `user/data/consent/YYYY-MM.jsonl`; or `none`. Simple, greppable, no dependency. Retention `log.retain_days` defaults to 400 — just over the thirteen-month re-consent cycle, with audit headroom. Files older than retention are pruned on write, which costs a `glob` and nothing else.

The endpoint requires a same-origin `Origin` or `Sec-Fetch-Site: same-origin` header. A forged consent is a low-severity nuisance in the browser, but a forged *log record* would poison the proof, which is the one thing the log exists to provide.

---

## 13. Configuration

`blueprints.yaml`, tabbed:

| Tab | Contents |
|---|---|
| **General** | enabled, policy version, auto-version, cookie name and lifetime, render mode, exclude routes, GPC, geo |
| **Appearance** | layout (`bar` / `box` / `center`), position, blocking backdrop, reopen mode, appearance (`auto` / `light` / `dark`), color and radius overrides, custom CSS |
| **Content** | title, body, buttons, links to privacy and cookie policy pages — every field passed through `\|t` |
| **Categories** | list field: id, label, description, required, default-on, priority |
| **Services** | list field, same shape as the event registration, for services the site owner declares directly |
| **Inventory** | read-only `consent-inventory` field — everything registered by plugins and the auto-blocker, grouped by category, with cookie names, durations and providers |
| **Consent Mode** | toggle plus category → signal mapping |
| **Log** | backend, endpoint path, retention, IP/UA hashing |

### Multilang

Every content field is passed through Grav's `|t`. Plugin defaults are lang keys (`PLUGIN_CONSENT.BANNER_TITLE`), so out of the box the banner is translated into every language the plugin ships. A site owner who types literal prose gets that prose verbatim. A site owner who types `MYSITE.COOKIE_TITLE` gets it resolved from `user/languages/`. One field, three behaviors, no parallel JSON structure.

Lang file ships both a top-level `PLUGIN_CONSENT:` block and an `ICU.PLUGIN_CONSENT:` block, so the plugin works on Grav 1.7 and reads correctly in Admin Next.

---

## 14. Styling

Self-contained CSS driven end to end by custom properties. **`--consent-font` defaults to `inherit`**, so the banner picks up Inter under Quark 2 and Albert Sans under kahuna-site with zero configuration — the single most important default in the file.

```
--consent-font           inherit
--consent-font-heading   inherit
--consent-bg             --consent-fg           --consent-muted
--consent-border         --consent-accent       --consent-accent-fg
--consent-focus          --consent-backdrop
--consent-radius         --consent-btn-radius   --consent-shadow
--consent-pad            --consent-gap          --consent-max-width
--consent-z
```

Prefix is `--consent-*` and not `--kc-*` or anything shorter, deliberately: kahuna-site's own notes record a collision where a plugin and a theme both claimed `--kc-`, and the storefront stylesheet silently overwrote the theme's card and mono tokens. A long explicit prefix costs nothing.

**Theming layers**, most specific last:

1. Plugin defaults on `.consent-root`.
2. Dark palette under `@media (prefers-color-scheme: dark)` and `:root[data-theme="dark"]`, each guarded so an explicit light choice wins. Quark 2 stamps `data-theme` on `<html>` before first paint, so the banner tracks its toggle exactly.
3. `appearance: light|dark` in config pins it, beating the media query — which is what kahuna-site needs, being light-only by decision.
4. Config color fields, emitted as an inline `<style>` block. Non-technical restyling without touching CSS.
5. The theme's own stylesheet.

### Cascade

Two needs pull in opposite directions: the plugin's component rules have to beat a theme's bare `button` and `table` resets, while a theme's token overrides have to beat the plugin's defaults. Order alone cannot do both, so:

- The stylesheet is injected **last in `<head>`**, after the theme's own. Pico — which Quark 2 builds on — paints a coloured focus ring on every `button`, and at equal specificity the later rule wins.
- Component rules carry `.consent-root .thing`, which outranks any element selector.
- The **token block is wrapped in `:where()`**, giving it zero specificity, so `.consent-root { --consent-accent: … }` in a theme wins outright no matter what loaded when.
- Admin colour choices are emitted with `!important`, because they must also beat the plugin's own light/dark blocks, which necessarily carry more specificity than a plain token declaration can.

### Accessibility

- `role="dialog"`, `aria-labelledby`, `aria-describedby`. `aria-modal` only in blocking mode.
- Focus moves to the banner heading on show. Focus trap only in blocking mode.
- Escape closes the preferences panel; in non-blocking mode it also dismisses the banner as "no decision" (it reappears next visit — dismissal is not consent).
- Visible focus ring on every control via `--consent-focus`.
- `prefers-reduced-motion` removes the slide-in.
- Contrast checked at both ends of the default palette.

### Reject is not optional

Accept and Reject render with equal visual weight and there is no config option to remove or de-emphasize Reject. The CNIL fines against Google and Meta in January 2022 were specifically about making refusal harder than acceptance. A plugin that lets you ship that configuration is a plugin that lets you ship a fine. Button order is configurable; presence and weight are not.

---

## 15. Admin UI

Config lives in the normal plugin settings form. Two additions:

**`consent-inventory` field** (`admin-next/fields/consent-inventory.js`) — read-only, fetches `/consent/inventory`, renders services grouped by category with cookie name, type, duration, provider and where each came from (config / plugin / auto-block). This is the field that answers "what does my site actually set?", and it is the visible payoff of the runtime-registry design.

**Consent Log page** (`admin-next/pages/consent.js`, component mode, sidebar item `fa-cookie-bite`) — a summary strip (decisions in the last 30 days, acceptance rate, per-category grant rate), a filterable table of records, CSV export, and a purge action behind a destructive confirm.

Both follow the Admin Next conventions: `X-API-Token`, `window.__GRAV_DIALOGS` never native `confirm()`, `--foreground` / `--border` / `--muted` host tokens, logical CSS properties for RTL.

**Permissions** (`permissions.yaml`): `api.consent.read` for the inventory, log and stats; `api.consent.write` for purge.

---

## 16. Deliberately out of scope

- **A cookie scanner.** Every implementation is inaccurate and the plugin would own the false positives. The runtime registry is the better answer to the same question.
- **IAB TCF.** A certification process and an ad-tech rabbit hole. Wrong audience.
- **A cookie wall.** `blocking: true` exists for the backdrop, but the docs say plainly that conditioning access on consent is legally fragile in the EU.
- **A database log backend.** JSONL is right for Grav. If someone needs Postgres they can subscribe to `onConsentChanged`.

---

## 17. File layout

```
grav-plugin-consent/
├── blueprints.yaml          consent.php          consent.yaml
├── permissions.yaml         composer.json        LICENSE
├── README.md                CHANGELOG.md         SPEC.md
├── classes/
│   ├── Consent.php              static facade
│   ├── ConsentState.php         a parsed decision
│   ├── Category.php             CategoryRegistry.php
│   ├── Service.php              ServiceRegistry.php
│   ├── Registry.php             builds + caches both registries per request
│   ├── VendorCatalog.php        known third-party hosts
│   ├── AutoBlocker.php          output rewriting
│   ├── ConsentLog.php           JSONL append, prune, query, stats
│   ├── ConsentMode.php          Google Consent Mode v2 mapping
│   ├── Twig/ConsentTokenParser.php
│   ├── Twig/ConsentNode.php
│   └── Api/ConsentApiController.php
├── templates/partials/
│   ├── consent-banner.html.twig
│   ├── consent-preferences.html.twig
│   └── consent-placeholder.html.twig
├── assets/consent.css          assets/consent.js
├── languages/en.yaml
├── admin-next/fields/consent-inventory.js
├── admin-next/pages/consent.js
└── cli/ConsentLogCommand.php    stats, export, prune from the CLI
```

## 18. Verification

Symlinked into two sites that stress different things:

- **grav-api** (Quark 2) — light and dark, the theme toggle tracked live, Pico form controls alongside the banner's own, `{% consent %}` around a YouTube embed, managed-script activation without reload, the `auto` reopen badge appearing when no footer link exists.
- **grav-kahunacart-site** (kahuna-site) — font and color inheritance into a light-only theme with a warm custom palette, `--consent-*` proving it does not collide with `--ks-*` or the storefront's `--kc-*`, and the KahunaCart integration: `kahunacart_wishlist` appearing in the inventory under Functional, the cookie not being set before consent, and being expired server-side through `onConsentChanged` when Functional is revoked.
