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
 * Optional categories require a decision unless geographic scoping is
 * configured to allow them outside the selected countries.
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

        $state = $self->currentState();
        if ($state !== null) {
            return $state->granted($category);
        }

        return $definition !== null
            && !$self->gpcSignal()
            && ($self->config['geo']['outside_scope'] ?? 'allow') === 'allow'
            && !$self->isInScope();
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
     * country.is resolves in the browser and leaves a short-lived country
     * cookie so PHP integrations and dynamic rendering can use the result.
     */
    public function isInScope(): bool
    {
        if ($this->inScope !== null) {
            return $this->inScope;
        }

        $geo = (array)($this->config['geo'] ?? []);
        $mode = (string)($geo['mode'] ?? 'all');

        if (!in_array($mode, ['eu', 'custom'], true)) {
            return $this->inScope = true;
        }

        $country = ($geo['provider'] ?? 'header') === 'country_is'
            ? $this->cookieCountry() : $this->detectCountry();
        if ($country === null) {
            return $this->inScope = true;
        }

        $countries = $mode === 'eu'
            ? self::EU_EEA_UK_CH
            : array_map(static fn ($c) => strtoupper(trim((string)$c)), (array)($geo['countries'] ?? []));

        return $this->inScope = in_array($country, $countries, true);
    }

    private function cookieCountry(): ?string
    {
        $raw = $_COOKIE[$this->cookieName() . '_country'] ?? null;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || ($data['provider'] ?? null) !== 'country_is'
            || !is_numeric($data['expires'] ?? null)
            || $data['expires'] <= time() || $data['expires'] > time() + 3600) {
            return null;
        }

        $country = $data['country'] ?? null;

        return is_string($country) && preg_match('/^[A-Z]{2}$/D', $country)
            && !in_array($country, ['XX', 'ZZ'], true) ? $country : null;
    }

    /** Read the country supplied by the site's trusted proxy or web server. */
    public function detectCountry(): ?string
    {
        $geo = (array)($this->config['geo'] ?? []);
        $headers = array_filter(array_merge(
            [trim((string)($geo['header'] ?? 'CF-IPCountry'))],
            ['CF-IPCountry', 'X-Country-Code', 'X-AppEngine-Country', 'GeoIP-Country-Code']
        ));

        foreach ($headers as $header) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            $value = strtoupper(trim((string)($_SERVER[$key] ?? '')));
            if (preg_match('/^[A-Z]{2}$/D', $value) && !in_array($value, ['XX', 'ZZ'], true)) {
                return $value;
            }
        }

        // Some hosts expose it as an environment variable instead.
        foreach (['GEOIP_COUNTRY_CODE', 'HTTP_CF_IPCOUNTRY'] as $env) {
            $raw = $_SERVER[$env] ?? getenv($env);
            $value = strtoupper(trim((string)($raw !== false ? $raw : '')));
            if (preg_match('/^[A-Z]{2}$/D', $value) && !in_array($value, ['XX', 'ZZ'], true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A geographic preset, not a determination of which laws apply to a site.
     */
    public const EU_EEA_UK_CH = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
        'SI', 'ES', 'SE',
        'IS', 'LI', 'NO',
        'GB', 'CH',
    ];
}
