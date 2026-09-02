<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

/**
 * A visitor's stored decision, parsed.
 *
 * Deliberately tiny and immutable. The cookie is the only source of truth; the
 * server never keeps per-visitor state, so there is nothing to fall out of sync
 * and nothing to migrate.
 */
final class ConsentState
{
    /**
     * @param array<int, string> $categories granted category ids
     */
    private function __construct(
        public readonly array $categories,
        public readonly int $version,
        public readonly int $time,
        public readonly string $id,
    ) {
    }

    /**
     * Parse a raw cookie value. Returns null for anything unusable.
     */
    public static function parse(?string $raw): ?self
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }

        // PHP has already url-decoded $_COOKIE, but a value that arrived
        // double-encoded (some proxies, some JS libraries) still starts with
        // %7B rather than {. Decode once more in that case only.
        if (str_starts_with($raw, '%7B')) {
            $raw = rawurldecode($raw);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $categories = array_values(array_filter(array_map(
            static fn ($c) => is_string($c) ? CategoryRegistry::normaliseId($c) : '',
            (array)($data['c'] ?? [])
        )));

        return new self(
            categories: array_values(array_unique($categories)),
            version: (int)($data['v'] ?? 0),
            time: (int)($data['t'] ?? 0),
            id: preg_replace('/[^a-f0-9]/i', '', (string)($data['r'] ?? '')) ?: '',
        );
    }

    /**
     * @param array<int, string> $categories
     */
    public static function make(array $categories, int $version, string $id, ?int $time = null): self
    {
        $categories = array_values(array_unique(array_filter(array_map(
            static fn ($c) => CategoryRegistry::normaliseId((string)$c),
            $categories
        ))));

        return new self($categories, $version, $time ?? time(), $id);
    }

    public function granted(string $category): bool
    {
        return in_array(CategoryRegistry::normaliseId($category), $this->categories, true);
    }

    /**
     * A decision made against an older inventory has to be asked again.
     */
    public function isCurrent(int $effectiveVersion): bool
    {
        return $this->version === $effectiveVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'categories' => $this->categories,
            'version' => $this->version,
            'time' => $this->time,
            'id' => $this->id,
        ];
    }

    /**
     * The cookie payload. Keys are single letters because this rides on every
     * request to the site and there is no reason for it to be large.
     */
    public function toCookieValue(): string
    {
        return (string)json_encode([
            'v' => $this->version,
            't' => $this->time,
            'c' => $this->categories,
            'r' => $this->id,
        ], JSON_UNESCAPED_SLASHES);
    }
}
