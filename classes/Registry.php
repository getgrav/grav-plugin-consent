<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

use Grav\Common\Grav;
use RocketTheme\Toolbox\Event\Event;

/**
 * Assembles the site's consent inventory once per request.
 *
 * Three sources, merged in this order so that the more specific wins:
 *
 *   1. Services the site owner declared in plugin config.
 *   2. Services other plugins register via `onConsentRegisterServices`.
 *   3. Services the auto-blocker matched in the rendered output.
 *
 * The site owner's own wording therefore survives a plugin registering the
 * same id, and a hand-described vendor survives auto-detection.
 */
final class Registry
{
    private ?CategoryRegistry $categories = null;
    private ?ServiceRegistry $services = null;
    private ?int $effectiveVersion = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function categories(): CategoryRegistry
    {
        if ($this->categories !== null) {
            return $this->categories;
        }

        $registry = new CategoryRegistry();

        foreach ($this->configCategories() as $id => $definition) {
            $registry->add((string)$id, $definition);
        }

        Grav::instance()->fireEvent(
            'onConsentRegisterCategories',
            new Event(['categories' => $registry, 'config' => $this->config])
        );

        // A site with no `necessary` equivalent has no way to describe the
        // cookies it cannot switch off, so put one back rather than render a
        // banner that lies by omission.
        if ($registry->requiredIds() === []) {
            $registry->add('necessary', [
                'label' => 'PLUGIN_CONSENT.CATEGORY.NECESSARY',
                'description' => 'PLUGIN_CONSENT.CATEGORY.NECESSARY_DESC',
                'required' => true,
                'priority' => 10,
            ]);
        }

        return $this->categories = $registry;
    }

    public function services(): ServiceRegistry
    {
        if ($this->services !== null) {
            return $this->services;
        }

        $registry = new ServiceRegistry();

        foreach ($this->configServices() as $id => $definition) {
            $registry->add((string)$id, $definition, Service::SOURCE_CONFIG);
        }

        Grav::instance()->fireEvent(
            'onConsentRegisterServices',
            new Event(['services' => $registry, 'categories' => $this->categories(), 'config' => $this->config])
        );

        // Third parties auto-blocking has seen on this site before. Without
        // these the banner would only ever describe the page it happens to be
        // on — the home page would not mention the YouTube embed three clicks
        // in — and the admin inventory, which renders no page, would show
        // nothing auto-blocking had ever found.
        if (!empty($this->config['autoblock']['enabled'])) {
            foreach (DetectedVendors::asServices($this->categories()->ids()) as $id => $definition) {
                $registry->add((string)$id, $definition, Service::SOURCE_AUTOBLOCK);
            }
        }

        $registry->reconcile($this->categories(), $this->fallbackCategory());

        return $this->services = $registry;
    }

    /**
     * Late additions from the auto-blocker, which only knows what it found
     * after the page was rendered.
     *
     * @param array<string, array<string, mixed>> $services
     */
    public function addDetected(array $services): void
    {
        $registry = $this->services();
        foreach ($services as $id => $definition) {
            $registry->add((string)$id, $definition, Service::SOURCE_AUTOBLOCK);
        }
        $registry->reconcile($this->categories(), $this->fallbackCategory());

        // Deliberately NOT invalidating the cached version. Auto-detected
        // services are whatever happened to be on the page just rendered, so
        // folding them into the fingerprint would make the policy version
        // differ page by page — a visitor who accepted on the home page would
        // be asked again the moment they opened a page with a YouTube embed,
        // and the decision endpoint (which renders no page, so detects
        // nothing) would record a version that matches nobody's cookie.
    }

    /**
     * The version a decision is checked against.
     *
     * With auto-versioning on (the default) this folds in a hash of the
     * category and service *ids*. Adding a plugin that stores something new
     * therefore re-asks everyone, which is the correct behaviour and the thing
     * hand-maintained JSON inventories can never do. Labels, descriptions and
     * translations are deliberately excluded from the hash, so fixing a typo
     * or shipping a new language does not invalidate anybody's consent.
     */
    public function effectiveVersion(): int
    {
        if ($this->effectiveVersion !== null) {
            return $this->effectiveVersion;
        }

        $base = (int)($this->config['policy_version'] ?? 1);

        if (empty($this->config['auto_version'])) {
            return $this->effectiveVersion = $base;
        }

        // Only the stable, site-wide inventory counts: categories, services the
        // owner declared, and services plugins registered. Auto-blocked
        // vendors are excluded for the reason spelled out in addDetected() —
        // they vary per page, and a per-page consent version is worse than not
        // re-asking. Adding a vendor that only auto-blocking knows about and
        // wanting everyone re-asked is what `policy_version` is for.
        $ids = $this->categories()->ids();
        foreach ($this->services() as $id => $service) {
            if ($service->source !== Service::SOURCE_AUTOBLOCK) {
                $ids[] = $id;
            }
        }
        sort($ids);

        return $this->effectiveVersion = $base + (int)(crc32(implode('|', $ids)) % 100000);
    }

    /**
     * Where a service lands when it names a category the site does not define.
     *
     * The first non-required category, so an unknown thing needs an opt-in
     * rather than riding along inside `necessary`.
     */
    private function fallbackCategory(): string
    {
        foreach ($this->categories()->all() as $id => $category) {
            if (!$category->required) {
                return $id;
            }
        }

        return (string)array_key_first($this->categories()->all());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configCategories(): array
    {
        $configured = $this->config['categories'] ?? null;

        if (!is_array($configured) || $configured === []) {
            return self::defaultCategories();
        }

        $out = [];
        foreach ($configured as $key => $row) {
            // Admin list fields hand back a numerically-indexed array of rows
            // carrying their own `id`; a hand-edited yaml is more likely keyed
            // by id. Accept both.
            $id = is_string($key) ? $key : (string)($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$id] = is_array($row) ? $row : [];
        }

        return $out !== [] ? $out : self::defaultCategories();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configServices(): array
    {
        $configured = $this->config['services'] ?? [];
        if (!is_array($configured)) {
            return [];
        }

        $out = [];
        foreach ($configured as $key => $row) {
            $id = is_string($key) ? $key : (string)($row['id'] ?? '');
            if ($id === '' || !is_array($row)) {
                continue;
            }
            $out[$id] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaultCategories(): array
    {
        return [
            'necessary' => [
                'label' => 'PLUGIN_CONSENT.CATEGORY.NECESSARY',
                'description' => 'PLUGIN_CONSENT.CATEGORY.NECESSARY_DESC',
                'required' => true,
                'priority' => 10,
            ],
            'functional' => [
                'label' => 'PLUGIN_CONSENT.CATEGORY.FUNCTIONAL',
                'description' => 'PLUGIN_CONSENT.CATEGORY.FUNCTIONAL_DESC',
                'priority' => 20,
            ],
            'analytics' => [
                'label' => 'PLUGIN_CONSENT.CATEGORY.ANALYTICS',
                'description' => 'PLUGIN_CONSENT.CATEGORY.ANALYTICS_DESC',
                'priority' => 30,
            ],
            'marketing' => [
                'label' => 'PLUGIN_CONSENT.CATEGORY.MARKETING',
                'description' => 'PLUGIN_CONSENT.CATEGORY.MARKETING_DESC',
                'priority' => 40,
            ],
        ];
    }
}
