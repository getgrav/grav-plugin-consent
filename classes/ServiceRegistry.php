<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Everything on this site that stores data on the visitor's device.
 *
 * Assembled fresh on every request from config, from plugins answering
 * `onConsentRegisterServices`, and from the auto-blocker. Nothing here is
 * hand-maintained in a config file, which is the point.
 *
 * @implements IteratorAggregate<string, Service>
 */
final class ServiceRegistry implements IteratorAggregate, Countable
{
    /** @var array<string, Service> */
    private array $items = [];

    /**
     * @param array<string, mixed> $definition
     */
    public function add(string $id, array $definition, string $source = Service::SOURCE_PLUGIN): self
    {
        $id = CategoryRegistry::normaliseId($id);
        if ($id === '') {
            return $this;
        }

        // First registration wins. Config is merged before plugins and the
        // auto-blocker, so a site owner who has described a service by hand
        // keeps their own wording and category rather than having a detected
        // vendor overwrite it.
        if (isset($this->items[$id])) {
            return $this;
        }

        $this->items[$id] = Service::fromArray($id, $definition, $source);

        return $this;
    }

    public function remove(string $id): self
    {
        unset($this->items[CategoryRegistry::normaliseId($id)]);

        return $this;
    }

    public function has(string $id): bool
    {
        return isset($this->items[CategoryRegistry::normaliseId($id)]);
    }

    public function get(string $id): ?Service
    {
        return $this->items[CategoryRegistry::normaliseId($id)] ?? null;
    }

    /**
     * @return array<string, Service>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return array<int, string>
     */
    public function ids(): array
    {
        $ids = array_keys($this->items);
        sort($ids);

        return $ids;
    }

    /**
     * @return array<int, Service>
     */
    public function inCategory(string $category): array
    {
        $category = CategoryRegistry::normaliseId($category);

        return array_values(array_filter(
            $this->items,
            static fn (Service $s) => $s->category === $category
        ));
    }

    /**
     * Drop services pointing at a category the site does not define.
     *
     * A plugin can register against a category the site owner has removed. The
     * safe reading of an unknown category is "not consented", so rather than
     * silently treating it as necessary we move it to the fallback — which
     * defaults to the first non-required category, and to `necessary` only if
     * the site has nothing else.
     */
    public function reconcile(CategoryRegistry $categories, string $fallback): self
    {
        foreach ($this->items as $id => $service) {
            if ($categories->has($service->category)) {
                continue;
            }

            $data = $service->toArray();
            $data['category'] = $fallback;
            $data['cookies'] = $service->cookies;
            $data['on_revoke'] = $service->onRevoke;
            $data['privacy_url'] = $service->privacyUrl;
            $this->items[$id] = Service::fromArray($id, $data, $service->source);
        }

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_values(array_map(static fn (Service $s) => $s->toArray(), $this->items));
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
