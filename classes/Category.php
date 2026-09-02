<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

/**
 * One consent bucket the visitor toggles.
 *
 * Categories are the unit of consent. Services belong to exactly one, and the
 * cookie records granted category ids rather than service ids — so installing
 * a plugin that adds a service to an already-granted category does not need a
 * fresh decision for that service, only for the changed inventory (see
 * Registry::fingerprint()).
 */
final class Category
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description = '',
        /** Always on, not toggleable. Only `necessary` should set this. */
        public readonly bool $required = false,
        /** Pre-checked in the preferences panel before any decision is made. */
        public readonly bool $default = false,
        /** Lower sorts first. */
        public readonly int $priority = 100,
    ) {
    }

    public static function fromArray(string $id, array $data): self
    {
        return new self(
            id: $id,
            label: (string)($data['label'] ?? $id),
            description: (string)($data['description'] ?? ''),
            required: (bool)($data['required'] ?? false),
            default: (bool)($data['default'] ?? false),
            priority: (int)($data['priority'] ?? 100),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => Translate::text($this->label),
            'description' => Translate::text($this->description),
            'required' => $this->required,
            'default' => $this->required || $this->default,
            'priority' => $this->priority,
        ];
    }
}
