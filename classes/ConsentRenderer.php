<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

use Grav\Common\Grav;
use RocketTheme\Toolbox\Event\Event;

/**
 * Everything the plugin puts on the page.
 *
 * The banner, the preferences panel, the inert managed scripts, the Consent
 * Mode bootstrap and the {% consent %} placeholders all come from here, so the
 * cache-safety rule lives in one place: **the HTML must not depend on the
 * visitor's consent state** unless render mode is explicitly `dynamic`. Break
 * that and a full-page cache will hand one visitor's decision to everybody.
 */
final class ConsentRenderer
{
    /**
     * The {% consent %} tag.
     *
     * @param array<string, mixed> $options
     */
    public static function block(string $category, string $body, ?string $fallback, array $options = []): string
    {
        $consent = Consent::instance();

        // With the plugin off, or outside the scope it asks in, the gate is
        // not there at all and the content is simply the content.
        if (!$consent->isEnabled() || !$consent->isInScope()) {
            return $body;
        }

        $category = CategoryRegistry::normaliseId($category);
        $definition = $consent->registry()->categories()->get($category);

        if ($definition !== null && $definition->required) {
            return $body;
        }

        $dynamic = $consent->renderMode() === 'dynamic';
        $granted = Consent::granted($category);

        if ($dynamic && $granted) {
            return $body;
        }

        $serviceId = (string)($options['service'] ?? '');
        $service = $serviceId !== '' ? $consent->registry()->services()->get($serviceId) : null;

        $vars = [
            'category' => $category,
            'appearance' => (string)($consent->config['appearance']['mode'] ?? 'auto'),
            'category_label' => $definition ? Translate::text($definition->label) : $category,
            'service' => $service?->toArray(),
            'label' => Translate::text((string)($options['label'] ?? '')),
            'description' => Translate::text((string)($options['description'] ?? '')),
            'fallback' => $fallback,
            // In cached mode the real markup travels inside an inert
            // <template>, so the response is identical for every visitor and
            // a grant swaps it in without a page load.
            'payload' => $dynamic ? null : $body,
            'granted' => $granted,
        ];

        return self::template('partials/consent-placeholder.html.twig', $vars);
    }

    /**
     * The banner, the preferences panel and the client configuration.
     */
    public static function banner(): string
    {
        $consent = Consent::instance();
        $config = $consent->config;
        $registry = $consent->registry();

        $content = Translate::all([
            'title' => (string)($config['content']['title'] ?? 'PLUGIN_CONSENT.BANNER.TITLE'),
            'body' => (string)($config['content']['body'] ?? 'PLUGIN_CONSENT.BANNER.BODY'),
            'accept' => (string)($config['content']['accept'] ?? 'PLUGIN_CONSENT.BUTTON.ACCEPT'),
            'reject' => (string)($config['content']['reject'] ?? 'PLUGIN_CONSENT.BUTTON.REJECT'),
            'preferences' => (string)($config['content']['preferences'] ?? 'PLUGIN_CONSENT.BUTTON.PREFERENCES'),
            'save' => (string)($config['content']['save'] ?? 'PLUGIN_CONSENT.BUTTON.SAVE'),
            'close' => (string)($config['content']['close'] ?? 'PLUGIN_CONSENT.BUTTON.CLOSE'),
            'prefs_title' => (string)($config['content']['prefs_title'] ?? 'PLUGIN_CONSENT.PREFS.TITLE'),
            'prefs_body' => (string)($config['content']['prefs_body'] ?? 'PLUGIN_CONSENT.PREFS.BODY'),
            'reopen_label' => (string)($config['content']['reopen_label'] ?? 'PLUGIN_CONSENT.BUTTON.REOPEN'),
            'gpc_notice' => (string)($config['content']['gpc_notice'] ?? 'PLUGIN_CONSENT.GPC_NOTICE'),
        ]);

        $vars = [
            'consent_config' => $config,
            'content' => $content,
            'links' => self::links($config),
            'categories' => $registry->categories()->toArray(),
            'services' => $registry->services()->toArray(),
            'layout' => (string)($config['appearance']['layout'] ?? 'bar'),
            'position' => (string)($config['appearance']['position'] ?? 'bottom-right'),
            'appearance' => (string)($config['appearance']['mode'] ?? 'auto'),
            'blocking' => (bool)($config['appearance']['blocking'] ?? false),
            'show_details' => (bool)($config['appearance']['show_details'] ?? true),
            'payload_json' => self::payloadJson(),
            'styles' => self::inlineStyles($config),
        ];

        return self::template('partials/consent-banner.html.twig', $vars);
    }

    /**
     * The client payload, encoded for embedding in a <script> block.
     *
     * JSON_HEX_TAG matters here: a service description containing the literal
     * text `</script>` would otherwise close the block early and spill the rest
     * of the payload into the document as markup.
     */
    public static function payloadJson(): string
    {
        $json = json_encode(
            self::payload(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return $json !== false ? $json : '{}';
    }

    /**
     * The configuration handed to the client, as JSON.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $consent = Consent::instance();
        $config = $consent->config;
        $registry = $consent->registry();
        $categories = $registry->categories();

        $payload = [
            'version' => $registry->effectiveVersion(),
            'mode' => $consent->renderMode(),
            'cookie' => [
                'name' => $consent->cookieName(),
                'days' => $consent->cookieLifetimeDays(),
                'path' => (string)($config['cookie']['path'] ?? '/'),
                'domain' => (string)($config['cookie']['domain'] ?? ''),
                'sameSite' => (string)($config['cookie']['same_site'] ?? 'Lax'),
                'secure' => self::isSecure(),
            ],
            'categories' => $categories->toArray(),
            'services' => $registry->services()->toArray(),
            'scripts' => self::managedScripts(),
            'reopen' => (string)($config['appearance']['reopen'] ?? 'auto'),
            'blocking' => (bool)($config['appearance']['blocking'] ?? false),
            'honorGpc' => (bool)($config['honor_gpc'] ?? true),
            'gpcSignal' => $consent->gpcSignal(),
            'endpoint' => self::endpoint($config),
            'consentMode' => self::consentModePayload($config, $categories),
            'strings' => Translate::all([
                'placeholderTitle' => 'PLUGIN_CONSENT.PLACEHOLDER.TITLE',
                'placeholderBody' => 'PLUGIN_CONSENT.PLACEHOLDER.BODY',
                'placeholderButton' => 'PLUGIN_CONSENT.PLACEHOLDER.BUTTON',
            ]),
        ];

        $event = new Event(['payload' => $payload, 'config' => $config]);
        Grav::instance()->fireEvent('onConsentBannerConfig', $event);

        return (array)$event['payload'];
    }

    /**
     * Scripts a service handed over, held inert until its category is granted.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function managedScripts(): array
    {
        $out = [];

        foreach (Consent::instance()->registry()->services() as $service) {
            foreach ($service->scripts as $script) {
                if (!is_array($script)) {
                    continue;
                }
                $row = [
                    'service' => $service->id,
                    'category' => $service->category,
                ];
                if (!empty($script['src'])) {
                    $row['src'] = (string)$script['src'];
                }
                if (!empty($script['inline'])) {
                    $row['inline'] = (string)$script['inline'];
                }
                if ($row === ['service' => $service->id, 'category' => $service->category]) {
                    continue;
                }
                foreach (['async', 'defer'] as $flag) {
                    if (!empty($script[$flag])) {
                        $row[$flag] = true;
                    }
                }
                if (!empty($script['attrs']) && is_array($script['attrs'])) {
                    $row['attrs'] = $script['attrs'];
                }
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Google Consent Mode v2's default-denied call.
     *
     * Has to be on the page before any Google tag, so it is injected right
     * after <head> rather than with the banner. Emitted unconditionally when
     * the feature is on — the whole point is that the denial is already in the
     * dataLayer before a decision exists.
     */
    public static function consentModeBootstrap(): string
    {
        $consent = Consent::instance();
        $config = (array)($consent->config['consent_mode'] ?? []);

        if (empty($config['enabled'])) {
            return '';
        }

        $categories = $consent->registry()->categories();
        $mapping = ConsentMode::mapping($config, $categories);
        $defaults = ConsentMode::defaults($mapping, $categories, (int)($config['wait_for_update'] ?? 500));

        $json = json_encode($defaults, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }

        return "<script data-consent-ignore>window.dataLayer=window.dataLayer||[];"
            . "function gtag(){dataLayer.push(arguments);}"
            . "gtag('consent','default',{$json});</script>";
    }

    /**
     * Inert <script> tags for every managed script, for the client to activate.
     *
     * In `dynamic` mode the granted ones are emitted live and the rest are
     * left out entirely, so nothing about a blocked service appears in the
     * source. Both modes are equally compliant — an inert script does not
     * execute and stores nothing — but dynamic trades cache-friendliness for a
     * tidier page.
     */
    public static function scriptTags(): string
    {
        $consent = Consent::instance();
        if (!$consent->isEnabled()) {
            return '';
        }

        $dynamic = $consent->renderMode() === 'dynamic';
        $html = '';

        foreach (self::managedScripts() as $script) {
            $granted = Consent::granted((string)$script['category']);

            if ($dynamic && !$granted) {
                continue;
            }

            $attrs = '';
            foreach (['async', 'defer'] as $flag) {
                if (!empty($script[$flag])) {
                    $attrs .= ' ' . $flag;
                }
            }
            foreach ((array)($script['attrs'] ?? []) as $name => $value) {
                $attrs .= sprintf(
                    ' %s="%s"',
                    preg_replace('/[^a-z0-9_-]/i', '', (string)$name),
                    htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8')
                );
            }

            $meta = sprintf(
                ' data-consent-service="%s" data-consent-category="%s"',
                htmlspecialchars((string)$script['service'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)$script['category'], ENT_QUOTES, 'UTF-8')
            );

            if ($dynamic) {
                $html .= isset($script['src'])
                    ? sprintf('<script src="%s"%s%s></script>', htmlspecialchars((string)$script['src'], ENT_QUOTES, 'UTF-8'), $attrs, $meta)
                    : sprintf('<script%s%s>%s</script>', $attrs, $meta, (string)$script['inline']);
                continue;
            }

            $html .= isset($script['src'])
                ? sprintf(
                    '<script type="text/plain" data-consent-src="%s"%s%s></script>',
                    htmlspecialchars((string)$script['src'], ENT_QUOTES, 'UTF-8'),
                    $attrs,
                    $meta
                )
                : sprintf(
                    '<script type="text/plain" data-consent-inline="1"%s%s>%s</script>',
                    $attrs,
                    $meta,
                    (string)$script['inline']
                );
        }

        return $html;
    }

    /**
     * Token overrides from the appearance config, so a non-technical owner can
     * restyle the banner without touching CSS.
     *
     * @param array<string, mixed> $config
     */
    public static function inlineStyles(array $config): string
    {
        $map = [
            'bg' => '--consent-bg',
            'fg' => '--consent-fg',
            'muted' => '--consent-muted',
            'border' => '--consent-border',
            'accent' => '--consent-accent',
            'accent_fg' => '--consent-accent-fg',
            'radius' => '--consent-radius',
            'max_width' => '--consent-max-width',
            'font' => '--consent-font',
            'z_index' => '--consent-z',
        ];

        $rules = [];
        foreach ($map as $key => $property) {
            $value = trim((string)($config['appearance'][$key] ?? ''));
            if ($value === '') {
                continue;
            }
            // Values land inside a style block, so anything that could close it
            // or start a new rule has to go.
            $value = str_replace(['<', '>', '{', '}', ';', '"', '!'], '', $value);
            // !important, deliberately. These are the site owner's explicit
            // choices from the admin, and they have to beat the plugin's own
            // light/dark blocks, which necessarily carry more specificity than
            // a plain token declaration can.
            $rules[] = $property . ':' . $value . ' !important';
        }

        $custom = trim((string)($config['appearance']['custom_css'] ?? ''));

        if ($rules === [] && $custom === '') {
            return '';
        }

        $css = $rules !== [] ? '.consent-root{' . implode(';', $rules) . '}' : '';

        return $css . str_replace(['</style', '<script'], '', $custom);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function endpoint(array $config): ?string
    {
        $log = (array)($config['log'] ?? []);
        if (($log['backend'] ?? 'file') === 'none' && empty($log['always_post'])) {
            // Nothing to record and nobody to tell, so skip the round trip.
            // `always_post` keeps it on for sites whose plugins listen to
            // onConsentChanged but do not want a log.
            return null;
        }

        $path = trim((string)($log['endpoint'] ?? '/_consent'));
        $path = '/' . ltrim($path, '/');

        return rtrim(Grav::instance()['base_url_relative'] ?? '', '/') . $path;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function consentModePayload(array $config, CategoryRegistry $categories): array
    {
        $block = (array)($config['consent_mode'] ?? []);
        if (empty($block['enabled'])) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'mapping' => ConsentMode::mapping($block, $categories),
        ];
    }

    /**
     * Whether the cookie should carry the Secure flag.
     *
     * Grav's Uri already accounts for a reverse proxy's X-Forwarded-Proto, so
     * a site behind Cloudflare or a load balancer is read correctly rather
     * than falling back to the bare $_SERVER['HTTPS'] test.
     */
    public static function isSecure(): bool
    {
        return Grav::instance()['uri']->scheme(true) === 'https';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array{label: string, url: string}>
     */
    private static function links(array $config): array
    {
        $out = [];
        foreach ((array)($config['content']['links'] ?? []) as $link) {
            $label = Translate::text((string)($link['label'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            $out[] = ['label' => $label, 'url' => $url];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function template(string $template, array $vars): string
    {
        try {
            return (string)Grav::instance()['twig']->processTemplate($template, $vars);
        } catch (\Throwable $e) {
            Grav::instance()['log']->error('consent: could not render ' . $template . ' — ' . $e->getMessage());

            return '';
        }
    }
}
