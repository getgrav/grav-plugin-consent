<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

use Grav\Common\Grav;

/**
 * The public face of the plugin.
 *
 * Everything a theme or another plugin needs, without reaching into internals:
 *
 *     Consent::granted('analytics')
 *     Consent::grantedService('kahunacart-wishlist')
 *     Consent::decided()
 *
 * `granted()` answers false until the visitor has actually decided. Default
 * deny is the whole point, so there is no "assume yes until told otherwise"
 * mode and no configuration option to add one.
 */
final class Consent
{
    private static ?self $instance = null;

    private ?ConsentState $state = null;
    private bool $stateLoaded = false;
    private ?Registry $registry = null;
    private ?bool $inScope = null;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(public readonly array $config)
    {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function boot(array $config): self
    {
        return self::$instance = new self($config);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            $config = (array)Grav::instance()['config']->get('plugins.consent', []);
            self::boot($config);
        }

        return self::$instance;
    }

    /** Test seam — drops the per-request singleton. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // ── the API other code actually calls ──────────────────────────────────

    public static function granted(string $category): bool
    {
        $self = self::instance();

        if (!$self->isEnabled()) {
            // With the plugin off, nothing is gated. That is the honest
            // reading: a site with no consent layer has made no promises.
            return true;
        }

        $categories = $self->categories();
        $definition = $categories->get($category);
        if ($definition !== null && $definition->required) {
            return true;
        }

        if (!$self->isInScope()) {
            // Outside the geographic scope the site chose to ask in, there is
            // no banner to answer, so gated things run.
            return true;
        }

        return $self->currentState()?->granted($category) ?? false;
    }

    public static function grantedService(string $id): bool
    {
        $service = self::instance()->services()->get($id);

        return $service !== null && self::granted($service->category);
    }

    public static function decided(): bool
    {
        return self::instance()->currentState() !== null;
    }

    public static function state(): ?ConsentState
    {
        return self::instance()->currentState();
    }

    public static function categories(): CategoryRegistry
    {
        return self::instance()->registry()->categories();
    }

    public static function services(): ServiceRegistry
    {
        return self::instance()->registry()->services();
    }

    public static function enabled(): bool
    {
        return self::instance()->isEnabled();
    }

    // ── internals ──────────────────────────────────────────────────────────

    public function registry(): Registry
    {
        return $this->registry ??= new Registry($this->config);
    }

    public function isEnabled(): bool
    {
        return (bool)($this->config['enabled'] ?? false);
    }

    /**
     * The visitor's decision, or null when it is missing or stale.
     */
    public function currentState(): ?ConsentState
    {
        if ($this->stateLoaded) {
            return $this->state;
        }

        $this->stateLoaded = true;

        $parsed = ConsentState::parse($_COOKIE[$this->cookieName()] ?? null);
        if ($parsed !== null && !$parsed->isCurrent($this->registry()->effectiveVersion())) {
            // A decision made against a different inventory is not a decision
            // about this one.
            $parsed = null;
        }

        return $this->state = $parsed;
    }

    public function cookieName(): string
    {
        $name = trim((string)($this->config['cookie']['name'] ?? 'consent'));

        return $name !== '' ? $name : 'consent';
    }

    public function cookieLifetimeDays(): int
    {
        return max(1, (int)($this->config['cookie']['lifetime_days'] ?? 180));
    }

    public function renderMode(): string
    {
        return ($this->config['render_mode'] ?? 'cached') === 'dynamic' ? 'dynamic' : 'cached';
    }

    /**
     * Whether the visitor's browser is asking not to be tracked.
     *
     * Browsers that implement Global Privacy Control send `Sec-GPC: 1`, so
     * this is answerable server-side as well as in JavaScript. GPC is legally
     * binding in several US states and honouring it is never wrong elsewhere.
     */
    public function gpcSignal(): bool
    {
        if (empty($this->config['honor_gpc'])) {
            return false;
        }

        return ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
    }

    /**
     * Does this visitor get asked at all?
     *
     * No IP database is bundled. Country comes from whatever header the stack
     * in front of Grav sets — Cloudflare's `CF-IPCountry` by default. When the
     * country cannot be determined the banner shows: failing open would be a
     * compliance hole, so the unknown case is treated as in-scope.
     */
    public function isInScope(): bool
    {
        if ($this->inScope !== null) {
            return $this->inScope;
        }

        $geo = (array)($this->config['geo'] ?? []);
        $mode = (string)($geo['mode'] ?? 'all');

        if ($mode === 'all') {
            return $this->inScope = true;
        }

        $country = $this->detectCountry($geo);
        if ($country === null) {
            return $this->inScope = true;
        }

        $countries = $mode === 'eu'
            ? self::EU_EEA_UK_CH
            : array_map(static fn ($c) => strtoupper(trim((string)$c)), (array)($geo['countries'] ?? []));

        return $this->inScope = in_array($country, $countries, true);
    }

    /**
     * @param array<string, mixed> $geo
     */
    private function detectCountry(array $geo): ?string
    {
        $headers = array_filter(array_merge(
            [trim((string)($geo['header'] ?? 'CF-IPCountry'))],
            ['CF-IPCountry', 'X-Country-Code', 'X-AppEngine-Country', 'GeoIP-Country-Code']
        ));

        foreach ($headers as $header) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            $value = strtoupper(trim((string)($_SERVER[$key] ?? '')));
            if ($value !== '' && $value !== 'XX' && strlen($value) === 2) {
                return $value;
            }
        }

        // Some hosts expose it as an environment variable instead.
        foreach (['GEOIP_COUNTRY_CODE', 'HTTP_CF_IPCOUNTRY'] as $env) {
            $raw = $_SERVER[$env] ?? getenv($env);
            $value = strtoupper(trim((string)($raw !== false ? $raw : '')));
            if ($value !== '' && strlen($value) === 2) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The EEA, plus the UK and Switzerland — the practical scope of "GDPR
     * applies to this visitor" for a site choosing to ask only in Europe.
     */
    public const EU_EEA_UK_CH = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE',
        'IS', 'LI', 'NO',
        'GB', 'CH',
    ];
}
