<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

use Grav\Common\Grav;

/**
 * Pass-through translation.
 *
 * Every human-readable string in this plugin — config fields, category labels,
 * service names contributed by other plugins — goes through here. Grav's
 * translator returns the lookup string unchanged when it finds no match, which
 * gives one field three useful behaviours:
 *
 *   "We use cookies"            → verbatim, for a single-language site
 *   "PLUGIN_CONSENT.TITLE"      → this plugin's own shipped translation
 *   "MYSITE.COOKIE_TITLE"       → resolved from user/languages/
 *
 * That is the whole multilang story. No parallel per-locale structure, no
 * separate translation file only this plugin knows how to read.
 */
final class Translate
{
    public static function text(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        return (string)Grav::instance()['language']->translate($value);
    }

    /**
     * Translate every value of an array, leaving keys alone.
     *
     * @param array<string, string|null> $values
     * @return array<string, string>
     */
    public static function all(array $values): array
    {
        return array_map(static fn ($v) => self::text(is_string($v) ? $v : null), $values);
    }
}
