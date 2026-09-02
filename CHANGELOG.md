# v1.0.0
## 09/02/2026

1. [](#new)
    * First release. Cookie consent for Grav 2.0, with the inventory of what a site stores assembled at runtime instead of hand-maintained.
    * `onConsentRegisterServices` — a plugin declares its own cookies, category, provider and durations, and the banner lists them. `on_revoke` clears them again with no JavaScript from the registering plugin.
    * `onConsentChanged` fires server-side, so a plugin can expire an `HttpOnly` cookie the browser cannot reach.
    * `onConsentRegisterCategories` for plugins that need a category of their own, and `onConsentBannerConfig` for a last pass over the client payload.
    * `Consent::granted()` / `grantedService()` / `decided()` for PHP, a `consent` Twig global, `consent_granted()`, `consent_link()` and `consent_banner()` functions, and a `{% consent %}` block tag with an optional `{% else %}` for embeds.
    * `window.gravConsent` and a `consent:changed` DOM event, replayed once on load so late scripts never miss a decision.
    * Two render modes: `cached` keeps the HTML identical for every visitor and is safe behind a CDN; `dynamic` keeps blocked scripts out of the source and sets `Vary: Cookie`.
    * Auto-blocking, off by default, with a catalogue of ~40 known third parties. Vendors it finds are remembered, so every page's banner describes the whole site.
    * Google Consent Mode v2 signals, with a configurable category mapping.
    * Global Privacy Control honoured by default, from both the `Sec-GPC` header and `navigator.globalPrivacyControl`.
    * Optional geographic scoping, reading a country header rather than bundling an IP database, and failing towards showing the banner.
    * A decision log that can actually demonstrate consent — monthly JSONL, hashed IP and user agent, automatic pruning, CSV export.
    * Admin Next integration: a Consent log page, a read-only inventory field, and a full settings form.
    * `bin/plugin consent log` for the same three things from a terminal.
    * Self-contained CSS driven by `--consent-*` custom properties, inheriting the theme's font by default and following a `data-theme` toggle. Every template is overridable.
