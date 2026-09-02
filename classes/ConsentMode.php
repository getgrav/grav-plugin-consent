<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

/**
 * Google Consent Mode v2 signal mapping.
 *
 * Mandatory in practice for anyone running Google Ads to the EEA, and cheap
 * enough to include: it is a mapping from this plugin's categories onto
 * Google's seven storage signals, plus the default-denied call that has to be
 * on the page before any Google tag loads.
 */
final class ConsentMode
{
    /** Every signal Consent Mode v2 defines. */
    public const SIGNALS = [
        'ad_storage',
        'ad_user_data',
        'ad_personalization',
        'analytics_storage',
        'functionality_storage',
        'personalization_storage',
        'security_storage',
    ];

    /**
     * @return array<string, array<int, string>> category id => signals
     */
    public static function defaultMapping(): array
    {
        return [
            'necessary' => ['security_storage'],
            'functional' => ['functionality_storage', 'personalization_storage'],
            'analytics' => ['analytics_storage'],
            'marketing' => ['ad_storage', 'ad_user_data', 'ad_personalization'],
        ];
    }

    /**
     * Resolve the configured mapping, falling back per-category to the default.
     *
     * @param array<string, mixed> $config the `consent_mode` config block
     * @return array<string, array<int, string>>
     */
    public static function mapping(array $config, CategoryRegistry $categories): array
    {
        $configured = (array)($config['mapping'] ?? []);
        $defaults = self::defaultMapping();
        $out = [];

        foreach ($categories->ids() as $id) {
            $signals = $configured[$id] ?? $defaults[$id] ?? [];
            if (is_string($signals)) {
                $signals = preg_split('/[\s,]+/', $signals) ?: [];
            }
            $signals = array_values(array_intersect(
                array_map(static fn ($s) => strtolower(trim((string)$s)), (array)$signals),
                self::SIGNALS
            ));
            if ($signals !== []) {
                $out[$id] = $signals;
            }
        }

        return $out;
    }

    /**
     * The `gtag('consent','default',…)` payload.
     *
     * Everything denied except the signals belonging to required categories —
     * `security_storage` in the stock mapping, which is the one signal Google
     * itself documents as not requiring consent.
     *
     * @param array<string, array<int, string>> $mapping
     * @return array<string, string|int>
     */
    public static function defaults(array $mapping, CategoryRegistry $categories, int $waitMs = 500): array
    {
        $payload = [];
        foreach (self::SIGNALS as $signal) {
            $payload[$signal] = 'denied';
        }

        foreach ($categories->requiredIds() as $id) {
            foreach ($mapping[$id] ?? [] as $signal) {
                $payload[$signal] = 'granted';
            }
        }

        if ($waitMs > 0) {
            $payload['wait_for_update'] = $waitMs;
        }

        return $payload;
    }
}
