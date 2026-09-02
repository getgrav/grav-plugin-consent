<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

/**
 * The `consent` Twig global.
 *
 * A thin instance wrapper, because Twig cannot call static methods on a class
 * exposed as a variable — `{{ consent.granted('analytics') }}` needs an object.
 */
final class TwigProxy
{
    public function granted(string $category): bool
    {
        return Consent::granted($category);
    }

    public function grantedService(string $id): bool
    {
        return Consent::grantedService($id);
    }

    public function decided(): bool
    {
        return Consent::decided();
    }

    public function enabled(): bool
    {
        return Consent::enabled();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        return Consent::categories()->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function services(): array
    {
        return Consent::services()->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function servicesIn(string $category): array
    {
        return array_map(
            static fn (Service $s) => $s->toArray(),
            Consent::services()->inCategory($category)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function state(): ?array
    {
        return Consent::state()?->toArray();
    }
}
