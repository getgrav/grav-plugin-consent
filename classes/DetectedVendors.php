<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

use Grav\Common\Grav;

/**
 * Third parties auto-blocking has seen on this site.
 *
 * The auto-blocker only knows what was on the page it just rewrote, which is
 * not enough on its own. Without this, the banner on the home page would not
 * mention the YouTube embed three pages in, and the admin inventory — which
 * renders no page at all — would list nothing auto-blocking had ever found.
 *
 * So the ids are remembered. A small JSON file, rewritten only when the set
 * actually changes, which on a settled site is never.
 *
 * These stay out of the policy-version fingerprint. A vendor appearing for the
 * first time should not re-ask a visitor who has already answered, and the file
 * changing on a Tuesday because somebody embedded a video is exactly the kind
 * of churn that would make consent meaningless.
 */
final class DetectedVendors
{
    private const FILE = 'detected.json';

    /** @var array<int, string>|null */
    private static ?array $cache = null;

    /**
     * Vendor ids seen on this site, filtered to ones the catalogue still knows.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $raw = @file_get_contents(self::path());
        if ($raw === false) {
            return self::$cache = [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return self::$cache = [];
        }

        $known = VendorCatalog::all();
        $ids = array_values(array_filter(
            array_map('strval', $data['vendors'] ?? []),
            static fn (string $id) => isset($known[$id])
        ));

        sort($ids);

        return self::$cache = $ids;
    }

    /**
     * Fold newly-seen ids into the remembered set.
     *
     * @param array<int, string> $ids
     */
    public static function remember(array $ids): void
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($ids === []) {
            return;
        }

        $known = self::all();
        $merged = array_values(array_unique(array_merge($known, $ids)));
        sort($merged);

        if ($merged === $known) {
            return;
        }

        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return;
        }

        $json = json_encode(['updated' => time(), 'vendors' => $merged], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            @file_put_contents($path, $json, LOCK_EX);
        }

        self::$cache = $merged;
    }

    /**
     * The remembered vendors as service definitions, ready to register.
     *
     * @param array<int, string> $knownCategories
     * @return array<string, array<string, mixed>>
     */
    public static function asServices(array $knownCategories): array
    {
        $catalog = VendorCatalog::all();
        $out = [];

        foreach (self::all() as $id) {
            $vendor = $catalog[$id] ?? null;
            if ($vendor === null) {
                continue;
            }

            unset($vendor['hosts']);
            $category = (string)($vendor['category'] ?? 'marketing');

            // A vendor the catalogue calls strictly necessary needs no consent,
            // so it is listed under whatever the site calls that category
            // rather than being moved somewhere a visitor can switch it off.
            if (!in_array($category, $knownCategories, true)) {
                $category = $knownCategories[0] ?? $category;
            }

            $vendor['category'] = $category;
            $vendor['owner'] = 'autoblock';
            $out[$id] = $vendor;
        }

        return $out;
    }

    /** Forget everything. Used when auto-blocking is switched off. */
    public static function forget(): void
    {
        @unlink(self::path());
        self::$cache = [];
    }

    private static function path(): string
    {
        $locator = Grav::instance()['locator'];
        $base = $locator->findResource('user://data', true) ?: (GRAV_ROOT . '/user/data');

        return rtrim((string)$base, '/') . '/consent/' . self::FILE;
    }
}
