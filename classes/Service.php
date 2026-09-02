<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

/**
 * One named thing that stores or reads data on the visitor's device.
 *
 * "Google Analytics 4", "KahunaCart wishlist", "YouTube embeds". A service
 * belongs to exactly one category, declares the individual cookies (or storage
 * entries) it is responsible for, and may hand over the scripts it needs so
 * the plugin can hold them inert until the category is granted.
 */
final class Service
{
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_PLUGIN = 'plugin';
    public const SOURCE_AUTOBLOCK = 'autoblock';

    /**
     * @param array<int, array{name: string, duration?: string, type?: string, description?: string}> $cookies
     * @param array<int, array{src?: string, inline?: string, async?: bool, defer?: bool, type?: string, attrs?: array}> $scripts
     * @param array{cookies?: array<int, string>, storage?: array<int, string>} $onRevoke
     */
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $provider = '',
        public readonly ?string $privacyUrl = null,
        public readonly array $cookies = [],
        public readonly array $scripts = [],
        public readonly array $onRevoke = [],
        public readonly string $source = self::SOURCE_PLUGIN,
        /** Which plugin or theme registered it, for the admin inventory. */
        public readonly string $owner = '',
    ) {
    }

    public static function fromArray(string $id, array $data, string $source = self::SOURCE_PLUGIN): self
    {
        return new self(
            id: $id,
            category: (string)($data['category'] ?? 'necessary'),
            name: (string)($data['name'] ?? $id),
            description: (string)($data['description'] ?? ''),
            provider: (string)($data['provider'] ?? ''),
            privacyUrl: ($data['privacy_url'] ?? null) ?: null,
            cookies: self::normaliseCookies($data['cookies'] ?? []),
            scripts: array_values((array)($data['scripts'] ?? [])),
            onRevoke: (array)($data['on_revoke'] ?? []),
            source: $source,
            owner: (string)($data['owner'] ?? ''),
        );
    }

    /**
     * Accept both the terse and the full cookie shape.
     *
     * A plugin that only knows the names writes `['cookies' => ['_ga', '_gid']]`
     * and gets sensible blanks; one that has the details writes the full rows.
     *
     * @param mixed $cookies
     * @return array<int, array{name: string, duration: string, type: string, description: string}>
     */
    private static function normaliseCookies(mixed $cookies): array
    {
        $out = [];
        foreach ((array)$cookies as $cookie) {
            if (is_string($cookie)) {
                $cookie = ['name' => $cookie];
            }
            if (!is_array($cookie) || empty($cookie['name'])) {
                continue;
            }
            $out[] = [
                'name' => (string)$cookie['name'],
                'duration' => (string)($cookie['duration'] ?? ''),
                'type' => (string)($cookie['type'] ?? 'cookie'),
                'description' => (string)($cookie['description'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Cookie name patterns to clear when this service's category is revoked.
     *
     * Wildcards are allowed (`_ga_*`). The client honours these without the
     * registering plugin writing a line of JavaScript, which is why most
     * integrations need nothing beyond a registration call.
     *
     * @return array<int, string>
     */
    public function revokeCookiePatterns(): array
    {
        $patterns = (array)($this->onRevoke['cookies'] ?? []);
        if ($patterns === [] && $this->cookies !== []) {
            // Nothing declared: fall back to the cookies the service owns, but
            // only the plain `cookie` ones — clearing storage is destructive
            // enough that it should be asked for explicitly.
            foreach ($this->cookies as $cookie) {
                if ($cookie['type'] === 'cookie') {
                    $patterns[] = $cookie['name'];
                }
            }
        }

        return array_values(array_unique(array_map('strval', $patterns)));
    }

    /**
     * @return array<int, string>
     */
    public function revokeStorageKeys(): array
    {
        return array_values(array_unique(array_map('strval', (array)($this->onRevoke['storage'] ?? []))));
    }

    /**
     * The shape handed to the client and to the admin inventory.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $cookies = array_map(static function (array $cookie): array {
            $cookie['description'] = Translate::text($cookie['description']);
            $cookie['duration'] = Translate::text($cookie['duration']);

            return $cookie;
        }, $this->cookies);

        return [
            'id' => $this->id,
            'category' => $this->category,
            'name' => Translate::text($this->name),
            'description' => Translate::text($this->description),
            'provider' => Translate::text($this->provider),
            'privacy_url' => $this->privacyUrl,
            'cookies' => $cookies,
            'source' => $this->source,
            'owner' => $this->owner,
            'revoke_cookies' => $this->revokeCookiePatterns(),
            'revoke_storage' => $this->revokeStorageKeys(),
        ];
    }
}
