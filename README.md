# Consent

Cookie consent for Grav 2.0, built the way Grav does things.

Every other option in the ecosystem asks you to hand-maintain a list of every cookie your site sets. That list rots the moment you install anything. This plugin inverts it: **the inventory is assembled at runtime** from your config, from plugins that register with it, and — if you want — from what it finds in the finished page. Install a plugin that sets a cookie, and the banner lists it, in the right category, with the right duration and provider, without you editing a thing.

## Install

```
bin/gpm install consent
```

Or drop the repo into `user/plugins/consent`. There are no dependencies and nothing to build.

Out of the box you get four categories — strictly necessary, preferences, analytics, marketing — a banner that inherits your theme's font, and a decision log. That is usually all the setup there is.

## Country-based prompting

Under **General → Who gets asked**, choose who should automatically see the banner and how to look up their country. The default, **Everyone**, shows the banner without any country lookup. To use [country.is](https://country.is/) with the European preset, set:

```yaml
geo:
  mode: eu
  provider: country_is
  outside_scope: allow
```

`eu` covers the EEA, the UK and Switzerland. For your own list, use `mode: custom` and `countries: [DE, FR, GB, US]` (two-letter country codes). These are geographic selections, not an automatically maintained list of legal requirements; choose the countries appropriate to your site.

The optional country.is provider needs no API key. The visitor's browser calls `https://api.country.is/`, which exposes their IP to that service before consent. No credentials or referrer are sent. The plugin stores only the returned country and an expiry in session storage for up to one hour, never the returned IP. A separate short-lived `<consent-cookie-name>_country` cookie carries the country to PHP integrations and dynamic rendering; it is not a consent decision. If your site uses Content Security Policy, allow `https://api.country.is` in `connect-src`.

Alternatively, keep `provider: header` to use a country header from your server or trusted proxy. `geo.header` defaults to `CF-IPCountry`; the plugin also checks common country headers and `GEOIP_COUNTRY_CODE`. Configure your proxy to overwrite those headers rather than accepting visitor-supplied values. The browser reads the country through an uncached `GET /_consent/country` endpoint (under your configured `log.endpoint`, even with logging disabled), keeping cached page HTML identical between countries. Any CDN rule that forces caching must exclude this endpoint.

Visitors without a current decision see the popup automatically when their country matches. Unknown countries, lookup errors and requests taking more than three seconds show it too. Outside the selection, the popup stays closed, but preferences and the reopen badge remain available. **Outside selected countries** defaults to `allow`: optional services run automatically. Select `deny` to keep them blocked until opt-in everywhere. Saved decisions (including refusals) and Global Privacy Control take precedence over automatic allowance. Location never creates a consent decision or adds an audit entry. Unknown countries keep optional services blocked until consent. In dynamic render mode, a newly allowed embed may reload the page once after country detection so PHP can render it; geographic dynamic responses are private and must not be cached by a CDN.

Previously, header-based scoping could bypass PHP consent checks outside the selected countries while the browser still displayed the banner. The automatic prompt and outside-country policy now work together in both render modes, with explicit saved choices taking precedence.

Run the geographic behavior checks with `node --test tests/geo-runtime.test.cjs` and `php tests/geo.php`.

## For plugin authors

This is the part that matters. Answer one event and your plugin is on the banner:

```php
public static function getSubscribedEvents(): array
{
    return [
        'onConsentRegisterServices' => ['onConsentRegisterServices', 0],
    ];
}

public function onConsentRegisterServices(Event $event): void
{
    $event['services']->add('myplugin-prefs', [
        'category'    => 'functional',
        'name'        => 'PLUGIN_MYPLUGIN.CONSENT.PREFS',
        'description' => 'PLUGIN_MYPLUGIN.CONSENT.PREFS_DESC',
        'provider'    => 'self',
        'privacy_url' => null,
        'cookies'     => [
            ['name' => 'myplugin_prefs', 'duration' => '1 year', 'type' => 'cookie'],
        ],
        'on_revoke'   => ['cookies' => ['myplugin_prefs']],
    ]);
}
```

Every string is passed through Grav's translator, so a lang key or a literal both work. `type` is `cookie` (default), `local_storage`, `session_storage`, `indexed_db` or `pixel` — "cookie banner" is a misnomer, and ePrivacy covers terminal-equipment storage generally.

`on_revoke.cookies` accepts `*` wildcards and is honoured in the browser without you writing a line of JavaScript. Most integrations need nothing else.

The event never fires when this plugin is absent, so subscribing to it costs an uninstalled site nothing.

### Asking, in PHP

```php
use Grav\Plugin\Consent\Consent;

if (Consent::granted('functional')) {
    setcookie('myplugin_prefs', $value, [...]);
}
```

`Consent::granted()` answers **false** for optional categories until the visitor has actually decided, except when geographic scoping permits automatic allowance outside the selected countries. Required categories always remain available. It answers **true** when the plugin is absent or switched off — a site with no consent layer has made no promises, and it should not lose features because a plugin is not installed. Guard the class so your plugin still runs on its own:

```php
if (!class_exists(Consent::class) || Consent::granted('functional')) {
    // …
}
```

### Cleaning up after a withdrawal

`onConsentChanged` fires on the server the moment a visitor changes their mind, which is how you expire an `HttpOnly` cookie that JavaScript is not allowed to touch:

```php
public function onConsentChanged(Event $event): void
{
    if (in_array('functional', (array)$event['granted'], true)) {
        return;
    }

    setcookie('myplugin_token', '', ['expires' => 1, 'path' => '/', 'httponly' => true]);
}
```

The event also carries `denied`, `previous`, `changed`, `first` and `method`.

### Handing over your scripts

A service can give the plugin the scripts it needs and let consent decide when they run:

```php
'scripts' => [
    ['src' => 'https://www.googletagmanager.com/gtag/js?id=G-XXXX', 'async' => true],
    ['inline' => "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('config','G-XXXX');"],
],
```

They are emitted inert and activated the instant the category is granted — no reload.

### Adding a category

Rare, but available via `onConsentRegisterCategories` with the same shape.

## Twig

```twig
{% if consent.granted('analytics') %}…{% endif %}

{% consent 'marketing' %}
    <iframe src="https://www.youtube.com/embed/…"></iframe>
{% else %}
    <p>Optional custom fallback.</p>
{% endconsent %}

{{ consent_link('Cookie settings', {'class': 'footer-link'}) }}
{{ consent_banner() }}   {# place it yourself; suppresses the automatic injection #}
```

Without an `{% else %}` branch the tag renders a placeholder — the service name, one line of explanation, and a button that grants exactly that category and loads the content in place. Embeds are where every site owner gets stuck, so that placeholder is worth more than the banner is.

`{% consent 'marketing' with {service: 'youtube'} %}` names the service the placeholder should describe.

## JavaScript

```js
window.gravConsent.granted('analytics')   // bool
window.gravConsent.state()                // { categories, version, time, id } | null
window.gravConsent.acceptAll()
window.gravConsent.rejectAll()
window.gravConsent.accept(['analytics'])
window.gravConsent.open()                 // preferences panel
window.gravConsent.reset()                // forget the decision, ask again

document.addEventListener('consent:changed', (e) => {
    e.detail.granted;   // ['necessary', 'analytics']
    e.detail.denied;
    e.detail.changed;
    e.detail.first;
    e.detail.initial;   // true on the load-time replay
});
```

`consent:changed` also fires once on load with the stored state, so a script that arrives late never misses the decision it needs.

## The way back

Withdrawing consent has to be as easy as giving it. The `reopen` setting decides how:

- **auto** (default) — a small corner badge appears only if the page has no `[data-consent-open]` element of its own. A theme that has done nothing is still compliant; a theme with a footer link stays uncluttered.
- **badge** — always show it.
- **none** — the theme takes responsibility.

## Render modes

| | `cached` (default) | `dynamic` |
|---|---|---|
| HTML | identical for every visitor | depends on the decision |
| Blocked scripts | present but inert (`type="text/plain"`) | absent |
| Granted embeds | swapped in by the client | rendered directly, no flicker |
| Behind a CDN or page cache | safe | needs `Vary: Cookie`, which it sets |

**Both are equally compliant.** A script held as `text/plain` never executes and never stores anything; regulators care about execution and storage, not about a URL appearing in markup. Dynamic buys you a tidier page and flicker-free embeds, not legality.

Dynamic mode also turns on `system.pages.never_cache_twig` for you — without it Grav's content cache would freeze the first visitor's consent state into the page for everybody.

## Auto-blocking

Off by default, because rewriting your finished HTML is a big hammer. On, it finds known third-party scripts, embeds and pixels, holds them until the right category is granted, and registers the vendor so the banner describes it properly — name, provider, cookie names, durations.

The bundled catalogue covers the usual suspects: Google Analytics / Tag Manager / Ads / Maps / Fonts, YouTube, Vimeo, Meta, LinkedIn, X, TikTok, Pinterest, Snapchat, Reddit, Microsoft Clarity and UET, Hotjar, Matomo, Plausible, Fathom, Segment, Mixpanel, Amplitude, Sentry, Intercom, Crisp, HubSpot, Mailchimp, Disqus, Twitch, SoundCloud, Spotify, Instagram, Yandex, Baidu, Adobe. Stripe, PayPal, reCAPTCHA and Turnstile are catalogued as strictly necessary, so a checkout or a captcha never breaks.

Escape hatches: `data-consent-ignore` on any element, and a host allowlist in the settings.

Vendors it has seen are remembered in `user/data/consent/detected.json`, so the banner on your home page knows about the video three clicks in, and the admin inventory can show you the lot.

## Google Consent Mode v2

A toggle and a category-to-signal mapping. The default-denied call is written before any Google tag loads and updated the moment the visitor decides. Required in practice if you run Google Ads to the EEA.

## Proof of consent

GDPR Article 7(1) requires being able to demonstrate consent was given — the part every third-party plugin skips. Each decision is appended to a monthly JSONL file under `user/data/consent/`, with the policy version, the categories, the services in force, the page, and the method.

**The IP address and browser string are never stored.** Each is hashed with a salt generated once and kept beside the log, out of `user/config/` so exporting or committing your settings never carries it along. That is what makes the record evidence of a decision rather than a log of who visited.

Read it in the admin under **Consent**, or from a terminal:

```
bin/plugin consent log                  # summary plus recent decisions
bin/plugin consent log --csv > out.csv  # everything, as CSV
bin/plugin consent log --prune          # drop records past the retention window
```

## Theming

The banner ships its own CSS, driven end to end by custom properties. Two defaults do most of the work: `--consent-font` is `inherit`, so the banner picks up your theme's body font with no configuration; and `--consent-accent` defaults to the text colour, so buttons read as deliberate next to any palette instead of introducing a colour nobody chose.

Redeclare any token in your own stylesheet and it wins — the plugin's token block is wrapped in `:where()` and carries zero specificity, so load order does not matter:

```css
.consent-root {
    --consent-accent: var(--my-brand);
    --consent-radius: 4px;
}
```

The full set: `--consent-font`, `--consent-font-heading`, `--consent-font-mono`, `--consent-bg`, `--consent-bg-sunken`, `--consent-fg`, `--consent-muted`, `--consent-border`, `--consent-accent`, `--consent-accent-fg`, `--consent-accent-hover`, `--consent-focus`, `--consent-backdrop`, `--consent-track`, `--consent-radius`, `--consent-radius-sm`, `--consent-btn-radius`, `--consent-shadow`, `--consent-pad`, `--consent-gap`, `--consent-max-width`, `--consent-box-width`, `--consent-z`, and the type steps `--consent-size`, `--consent-size-sm`, `--consent-size-xs`.

Light and dark are handled three ways, most specific winning: the OS preference, a `data-theme` attribute on the document (which is how Quark 2 does it, so the banner follows its toggle live), and the plugin's own `appearance` setting, which pins it either way. A light-only theme should set `appearance: light`.

Every template in `templates/partials/` is overridable — copy `consent-banner.html.twig`, `consent-preferences.html.twig` or `consent-placeholder.html.twig` into your theme and it takes over. Keep the `data-consent-*` attributes; the client finds everything through them.

There is also an Appearance tab in the settings with colour pickers, for restyling without touching CSS.

## Grav 2.0 notes

**Twig in page content.** Grav 2.0 gates content Twig behind `security.twig_content.process_enabled` and runs it in a sandbox. This plugin registers its tag and functions with the sandbox automatically, so `{% consent %}` works in a page once that gate is open. In templates there is nothing to do.

**Raw HTML in markdown.** `pages.markdown.gfm.tagfilter` escapes `<script>` and `<iframe>` in page content by default, so there is often nothing for auto-blocking to find there. Auto-blocking is aimed at snippets pasted into templates, which is where they usually live.

**Blocks inside markdown.** Twig in content runs after markdown, so a `{% consent %}` on its own line ends up inside the `<p>` markdown wrapped it in. Browsers cope, but `twig_first: true` renders it cleanly if it bothers you.

## Deliberately not included

- **A cookie scanner.** Every implementation is inaccurate and the plugin would own the false positives. The runtime registry answers the same question properly.
- **IAB TCF.** A certification process and an ad-tech rabbit hole. Wrong audience.
- **A cookie wall.** `blocking` dims the page, but conditioning access on consent is legally fragile in the EU and the docs say so.
- **A database log backend.** JSONL is right for Grav. Subscribe to `onConsentChanged` if you need something else.

## Reject is not optional

Accept and Reject render at the same visual weight, and there is no setting to remove or de-emphasise Reject. You can rename the buttons and reorder them; you cannot make refusal harder than acceptance. That is precisely what the CNIL fined Google and Meta over in January 2022, and a plugin that lets you ship it is a plugin that lets you ship a fine.

## Licence

MIT. Copyright (c) 2026 Grav.
