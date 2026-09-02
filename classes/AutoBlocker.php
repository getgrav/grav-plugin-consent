<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

/**
 * Neutralises third-party scripts and embeds in the rendered output.
 *
 * Off by default, because rewriting somebody's HTML is invasive. On, it is the
 * thirty-second path for a site that already has a Google Analytics snippet
 * pasted into its theme and does not want to restructure anything.
 *
 * Two escape hatches, both cheap: `data-consent-ignore` on any element skips
 * it, and `autoblock.allow` is a host allowlist.
 */
final class AutoBlocker
{
    /** @var array<string, array<string, mixed>> services matched during the pass */
    private array $detected = [];

    /**
     * @param array<string, mixed> $config the `autoblock` config block
     * @param array<int, string> $knownCategories
     * @param bool $dynamic render_mode: dynamic — see process()
     * @param callable(string):bool $granted answers whether a category is allowed
     */
    public function __construct(
        private readonly array $config,
        private readonly array $knownCategories,
        private readonly bool $dynamic = false,
        private $granted = null,
    ) {
    }

    private function isGranted(string $category): bool
    {
        return $this->granted !== null && ($this->granted)($category);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function detected(): array
    {
        return $this->detected;
    }

    /**
     * Rewrite the finished page.
     *
     * In `cached` render mode everything matched is neutralised, identically
     * for every visitor, and the client activates whatever consent allows.
     *
     * In `dynamic` mode the output depends on the decision:
     *
     *   granted     the element passes through completely untouched, so an
     *               allowed embed renders on the first paint with no swap.
     *   not granted a script is dropped entirely — nothing about it reaches
     *               the page. An iframe or pixel keeps its neutralised form,
     *               because the client needs an element to hang the "this is
     *               blocked, allow it?" placeholder on, and the address of a
     *               video somebody is being asked to permit is not a secret.
     */
    public function process(string $html): string
    {
        $html = $this->processScripts($html);
        $html = $this->processFrames($html);

        return $this->processPixels($html);
    }

    // ── scripts ────────────────────────────────────────────────────────────

    private function processScripts(string $html): string
    {
        return (string)preg_replace_callback(
            '#<script\b([^>]*)>(.*?)</script\s*>#is',
            function (array $m): string {
                $attrs = $m[1];
                $body = $m[2];

                if ($this->skip($attrs)) {
                    return $m[0];
                }

                $src = $this->attr($attrs, 'src');

                if ($src !== null) {
                    $match = $this->lookup($src);
                    if ($match === null) {
                        return $m[0];
                    }
                    [$id, $vendor] = $match;
                    $this->remember($id, $vendor);

                    if ($this->dynamic) {
                        return $this->isGranted($this->categoryFor($vendor)) ? $m[0] : '';
                    }

                    $attrs = $this->removeAttr($attrs, 'src');
                    $attrs = $this->removeAttr($attrs, 'type');

                    return sprintf(
                        '<script%s type="text/plain" data-consent-src="%s" data-consent-service="%s" data-consent-category="%s">%s</script>',
                        $attrs,
                        htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($this->categoryFor($vendor), ENT_QUOTES, 'UTF-8'),
                        $body
                    );
                }

                // Inline snippets. There is no reliable way to know what an
                // arbitrary script does, so the heuristic is narrow: block it
                // only when its own text names a host in the catalogue. That
                // catches the loader snippets people actually paste (GTM, the
                // Meta pixel) without touching a site's own JavaScript.
                if (empty($this->config['inline']) || trim($body) === '') {
                    return $m[0];
                }

                $match = $this->lookupInline($body);
                if ($match === null) {
                    return $m[0];
                }
                [$id, $vendor] = $match;
                $this->remember($id, $vendor);

                if ($this->dynamic) {
                    return $this->isGranted($this->categoryFor($vendor)) ? $m[0] : '';
                }

                $attrs = $this->removeAttr($attrs, 'type');

                return sprintf(
                    '<script%s type="text/plain" data-consent-inline="1" data-consent-service="%s" data-consent-category="%s">%s</script>',
                    $attrs,
                    htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($this->categoryFor($vendor), ENT_QUOTES, 'UTF-8'),
                    $body
                );
            },
            $html
        ) ?: $html;
    }

    // ── iframes ────────────────────────────────────────────────────────────

    private function processFrames(string $html): string
    {
        return (string)preg_replace_callback(
            '#<iframe\b([^>]*)>#i',
            function (array $m): string {
                $attrs = $m[1];
                if ($this->skip($attrs)) {
                    return $m[0];
                }

                $src = $this->attr($attrs, 'src');
                if ($src === null) {
                    return $m[0];
                }

                $match = $this->lookup($src);
                if ($match === null) {
                    return $m[0];
                }
                [$id, $vendor] = $match;
                $this->remember($id, $vendor);

                if ($this->dynamic && $this->isGranted($this->categoryFor($vendor))) {
                    return $m[0];
                }

                $attrs = $this->removeAttr($attrs, 'src');

                // The element stays where it is, blanked. The client renders a
                // placeholder in front of it and restores the real src on
                // grant, so layout and any sizing attributes survive.
                return sprintf(
                    '<iframe%s src="about:blank" data-consent-src="%s" data-consent-frame="1" data-consent-service="%s" data-consent-category="%s">',
                    $attrs,
                    htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($this->categoryFor($vendor), ENT_QUOTES, 'UTF-8')
                );
            },
            $html
        ) ?: $html;
    }

    // ── tracking pixels ────────────────────────────────────────────────────

    private function processPixels(string $html): string
    {
        return (string)preg_replace_callback(
            '#<img\b([^>]*)>#i',
            function (array $m): string {
                $attrs = $m[1];
                if ($this->skip($attrs)) {
                    return $m[0];
                }

                $src = $this->attr($attrs, 'src');
                if ($src === null) {
                    return $m[0];
                }

                $match = $this->lookup($src);
                if ($match === null) {
                    return $m[0];
                }
                [$id, $vendor] = $match;
                $this->remember($id, $vendor);

                if ($this->dynamic && $this->isGranted($this->categoryFor($vendor))) {
                    return $m[0];
                }

                $attrs = $this->removeAttr($attrs, 'src');

                return sprintf(
                    '<img%s data-consent-src="%s" data-consent-pixel="1" data-consent-service="%s" data-consent-category="%s">',
                    $attrs,
                    htmlspecialchars($src, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($this->categoryFor($vendor), ENT_QUOTES, 'UTF-8')
                );
            },
            $html
        ) ?: $html;
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function skip(string $attrs): bool
    {
        return stripos($attrs, 'data-consent-ignore') !== false
            || stripos($attrs, 'data-consent-src') !== false;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function lookup(string $url): ?array
    {
        if ($this->allowed($url)) {
            return null;
        }

        $match = VendorCatalog::match($url);
        if ($match === null) {
            return null;
        }

        // A vendor the site has classified as strictly necessary is not
        // something to hold back — blocking Stripe would break a checkout.
        return $this->categoryFor($match[1]) === '' ? null : $match;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function lookupInline(string $body): ?array
    {
        foreach (VendorCatalog::all() as $id => $vendor) {
            foreach ($vendor['hosts'] as $host) {
                if (str_contains($host, '/')) {
                    continue;
                }
                if (stripos($body, $host) !== false) {
                    if ($this->allowed('https://' . $host) || $this->categoryFor($vendor) === '') {
                        return null;
                    }

                    return [$id, $vendor];
                }
            }
        }

        return null;
    }

    private function allowed(string $url): bool
    {
        $host = strtolower((string)parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url, PHP_URL_HOST));
        if ($host === '') {
            return true;
        }

        foreach ((array)($this->config['allow'] ?? []) as $pattern) {
            $pattern = strtolower(trim((string)$pattern));
            if ($pattern !== '' && ($host === $pattern || str_ends_with($host, '.' . $pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The category to hold a vendor behind, or '' when it needs no consent.
     *
     * A vendor whose category the site has removed falls back to the first
     * category the site does define, so it is asked about rather than silently
     * let through.
     *
     * @param array<string, mixed> $vendor
     */
    private function categoryFor(array $vendor): string
    {
        $category = (string)($vendor['category'] ?? 'marketing');

        if ($category === 'necessary') {
            return '';
        }

        if (in_array($category, $this->knownCategories, true)) {
            return $category;
        }

        foreach ($this->knownCategories as $known) {
            if ($known !== 'necessary') {
                return $known;
            }
        }

        return $category;
    }

    /**
     * @param array<string, mixed> $vendor
     */
    private function remember(string $id, array $vendor): void
    {
        if (isset($this->detected[$id])) {
            return;
        }

        unset($vendor['hosts']);
        $vendor['category'] = $this->categoryFor($vendor);
        $vendor['owner'] = 'autoblock';
        $this->detected[$id] = $vendor;
    }

    private function attr(string $attrs, string $name): ?string
    {
        if (!preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $m)) {
            return null;
        }

        $value = $m[2] ?? '';
        if ($value === '') {
            $value = $m[3] ?? '';
        }
        if ($value === '') {
            $value = $m[4] ?? '';
        }

        return html_entity_decode(trim($value), ENT_QUOTES, 'UTF-8');
    }

    private function removeAttr(string $attrs, string $name): string
    {
        return (string)preg_replace(
            '/\s*\b' . preg_quote($name, '/') . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '',
            $attrs
        );
    }
}
