<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The site's consent categories, in display order.
 *
 * @implements IteratorAggregate<string, Category>
 */
final class CategoryRegistry implements IteratorAggregate, Countable
{
    /** @var array<string, Category> */
    private array $items = [];

    private bool $sorted = true;

    /**
     * @param array<string, mixed> $definition
     */
    public function add(string $id, array $definition): self
    {
        $id = self::normaliseId($id);
        if ($id === '') {
            return $this;
        }

        $this->items[$id] = Category::fromArray($id, $definition);
        $this->sorted = false;

        return $this;
    }

    public function remove(string $id): self
    {
        unset($this->items[self::normaliseId($id)]);

        return $this;
    }

    public function has(string $id): bool
    {
        return isset($this->items[self::normaliseId($id)]);
    }

    public function get(string $id): ?Category
    {
        return $this->items[self::normaliseId($id)] ?? null;
    }

    /**
     * @return array<string, Category>
     */
    public function all(): array
    {
        $this->sort();

        return $this->items;
    }

    /**
     * @return array<int, string>
     */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /**
     * Categories that are always on and cannot be toggled.
     *
     * @return array<int, string>
     */
    public function requiredIds(): array
    {
        return array_keys(array_filter($this->all(), static fn (Category $c) => $c->required));
    }

    /**
     * The set pre-checked in the preferences panel before a decision exists.
     *
     * @return array<int, string>
     */
    public function defaultIds(): array
    {
        return array_keys(array_filter($this->all(), static fn (Category $c) => $c->required || $c->default));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_values(array_map(static fn (Category $c) => $c->toArray(), $this->all()));
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    public function count(): int
    {
        return count($this->items);
    }

    private function sort(): void
    {
        if ($this->sorted) {
            return;
        }

        uasort($this->items, static function (Category $a, Category $b): int {
            return [$a->priority, $a->id] <=> [$b->priority, $b->id];
        });
        $this->sorted = true;
    }

    public static function normaliseId(string $id): string
    {
        $id = strtolower(trim($id));

        return (string)preg_replace('/[^a-z0-9_-]/', '', $id);
    }
}
