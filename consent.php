<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Plugin\Consent\AutoBlocker;
use Grav\Plugin\Consent\Consent;
use Grav\Plugin\Consent\ConsentLog;
use Grav\Plugin\Consent\ConsentRenderer;
use Grav\Plugin\Consent\DecisionHandler;
use Grav\Plugin\Consent\Twig\ConsentTokenParser;
use Grav\Plugin\Consent\TwigProxy;
use RocketTheme\Toolbox\Event\Event;
use Twig\TwigFunction;

/**
 * Cookie consent for Grav, built the way Grav does things.
 *
 * The inventory of what a site stores on a visitor's device is assembled at
 * runtime from three sources — config, other plugins answering
 * `onConsentRegisterServices`, and optionally the auto-blocker — rather than
 * hand-maintained in a JSON file that rots the moment anything is installed.
 */
class ConsentPlugin extends Plugin
{
    private bool $bannerRendered = false;

    /** @var array<string, mixed> */
    private array $settings = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 0],
            ],
            // Registered statically, never behind isAdmin(): on the Admin Next
            // path $grav['admin'] does not exist yet when onPluginsInitialized
            // runs, so a gated subscription would silently never fire.
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
            // Fired while the Twig environment is built, which is before
            // onPluginsInitialized has enabled anything — so it has to be
            // subscribed statically or the tag is unusable in page content.
            'onBuildTwigSandboxPolicy' => ['onBuildTwigSandboxPolicy', 0],
        ];
    }

    /**
     * Let {% consent %} work in editor-authored page content.
     *
     * Grav 2.0 runs Twig-in-content through a sandbox that allows only the
     * tags, filters and functions on its list. A custom tag that is not on it
     * soft-fails with a placeholder, so an author wrapping a YouTube embed on a
     * page would get nothing and no obvious reason why.
     *
     * Adding these is an assertion that they are safe to hand a content author,
     * and they are: the tag decides between two blocks of markup the author
     * already wrote, the functions answer a boolean and emit a button. None of
     * them reads config, touches the filesystem, or takes a callable.
     * `consent_banner()` is deliberately left off — placing the banner is a
     * theme's job, not a page author's.
     */
    public function onBuildTwigSandboxPolicy(Event $event): void
    {
        $tags = $event['tags'];
        $tags[] = 'consent';
        $event['tags'] = $tags;

        $functions = $event['functions'];
        $functions[] = 'consent_granted';
        $functions[] = 'consent_link';
        $event['functions'] = $functions;

        $methods = $event['methods'];
        $methods[] = [
            'class' => TwigProxy::class,
            'methods' => ['granted', 'grantedService', 'decided', 'enabled', 'categories', 'services', 'servicesIn', 'state'],
        ];
        $event['methods'] = $methods;
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onPluginsInitialized(): void
    {
        $this->settings = (array)$this->config->get('plugins.consent', []);
        Consent::boot($this->settings);

        // A separate uncached request keeps a proxy's country out of shared HTML.
        if (Consent::enabled() && $this->isCountryRequest()) {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $this->grav->close(new \Grav\Framework\Psr7\Response(
                $method === 'GET' ? 200 : 405,
                [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                    'Allow' => 'GET',
                ],
                (string)json_encode($method === 'GET'
                    ? ['country' => Consent::instance()->detectCountry()]
                    : ['error' => 'method_not_allowed'])
            ));

            return;
        }

        // The decision endpoint answers on admin and frontend alike — a
        // visitor can be logged in, and a plain Grav route is used rather than
        // an API-plugin route so the frontend never depends on the API plugin
        // being installed.
        if ($this->isDecisionRequest()) {
            $this->handleDecision();

            return;
        }

        if (!Consent::enabled() || $this->isAdmin()) {
            return;
        }

        $this->protectDynamicMode();

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onTwigExtensions' => ['onTwigExtensions', 0],
            // Late, so anything another plugin injects has already landed and
            // the auto-blocker sees the finished page.
            'onOutputGenerated' => ['onOutputGenerated', -100],
        ]);
    }

    /**
     * Stop Grav's content cache from freezing one visitor's consent state.
     *
     * `render_mode: dynamic` makes the page body depend on the decision — a
     * granted embed renders directly, a blocked script is absent. Grav caches
     * the Twig-processed content of a page, so without this the first visitor
     * to arrive decides what everyone else sees: whoever renders the page
     * before consenting bakes the placeholder in for good.
     *
     * Only Twig-in-content is affected; the markdown result is still cached.
     * Cached render mode needs none of this, which is one more reason it is
     * the default.
     */
    private function protectDynamicMode(): void
    {
        if (($this->settings['render_mode'] ?? 'cached') !== 'dynamic') {
            return;
        }

        if (!$this->config->get('system.pages.never_cache_twig', false)) {
            $this->config->set('system.pages.never_cache_twig', true);
        }
    }

    // ── Twig ───────────────────────────────────────────────────────────────

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigExtensions(): void
    {
        $this->grav['twig']->twig->addTokenParser(new ConsentTokenParser());
    }

    public function onTwigInitialized(): void
    {
        $twig = $this->grav['twig'];

        $twig->twig_vars['consent'] = new TwigProxy();

        $twig->twig->addFunction(new TwigFunction(
            'consent_granted',
            static fn (string $category): bool => Consent::granted($category)
        ));

        $twig->twig->addFunction(new TwigFunction(
            'consent_link',
            [$this, 'consentLink'],
            ['is_safe' => ['html']]
        ));

        // Lets a theme place the banner itself. Rendering it this way suppresses
        // the automatic injection, so the two can never both appear.
        $twig->twig->addFunction(new TwigFunction(
            'consent_banner',
            function (): string {
                $this->bannerRendered = true;

                return ConsentRenderer::banner();
            },
            ['is_safe' => ['html']]
        ));
    }

    /**
     * A button that reopens the preferences panel.
     *
     * Withdrawing consent has to be as easy as giving it, so this is the piece
     * a theme drops in its footer. When no such element exists on the page the
     * plugin falls back to its own corner badge — see the `reopen` setting.
     *
     * @param array<string, string> $attributes
     */
    public function consentLink(string $label = '', array $attributes = []): string
    {
        $label = $label !== ''
            ? \Grav\Plugin\Consent\Translate::text($label)
            : \Grav\Plugin\Consent\Translate::text('PLUGIN_CONSENT.BUTTON.REOPEN');

        $rendered = '';
        foreach ($attributes as $name => $value) {
            $name = preg_replace('/[^a-z0-9_-]/i', '', (string)$name);
            if ($name === '') {
                continue;
            }
            $rendered .= sprintf(' %s="%s"', $name, htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
        }

        return sprintf(
            '<button type="button" data-consent-open%s>%s</button>',
            $rendered,
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }

    // ── output ─────────────────────────────────────────────────────────────

    public function onOutputGenerated(): void
    {
        $output = (string)$this->grav->output;

        if ($output === '' || !$this->isHtmlResponse($output) || $this->isExcludedRoute()) {
            return;
        }

        $consent = Consent::instance();

        // In dynamic mode the response body genuinely differs by consent
        // state, so it must not be shared between visitors by a cache in
        // front of Grav. Emitted directly rather than through the page
        // headers because Grav builds those from the page and offers no hook
        // for an arbitrary one; its own emitter appends rather than replaces,
        // so this survives. Cached mode needs none of this — that is the whole
        // point of it.
        if ($consent->renderMode() === 'dynamic' && !headers_sent()) {
            header('Vary: Cookie', false);
            if (($this->settings['geo']['mode'] ?? 'all') !== 'all') {
                // Header-derived country and GPC may also change this body.
                $this->grav['page']->cacheControl('private, no-store');
            }
        }

        // Auto-blocking runs first: it can register services the banner then
        // has to list, so the inventory must settle before anything renders.
        if (!empty($this->settings['autoblock']['enabled'])) {
            $blocker = new AutoBlocker(
                (array)$this->settings['autoblock'],
                $consent->registry()->categories()->ids(),
                $consent->renderMode() === 'dynamic',
                static fn (string $category): bool => Consent::granted($category)
            );
            $output = $blocker->process($output);
            $detected = $blocker->detected();
            $consent->registry()->addDetected($detected);
            // Remember them, so the banner on every other page and the admin
            // inventory both know about a vendor that only appears here.
            \Grav\Plugin\Consent\DetectedVendors::remember(array_keys($detected));
        }

        // Consent Mode's default-denied call has to precede any Google tag, so
        // it goes first in <head>, ahead of everything the theme loads.
        $bootstrap = ConsentRenderer::consentModeBootstrap();
        if ($bootstrap !== '') {
            $output = $this->injectAfterHead($output, $bootstrap);
        }

        // Styles and inert scripts go LAST in <head>, after the theme's own
        // stylesheets. Themes style bare `button` and `table` heavily — Pico,
        // which Quark 2 builds on, paints a coloured focus ring on every
        // button — and at equal specificity the later rule wins. Loading after
        // the theme is what keeps the banner looking like itself. Token
        // overrides still work from a theme because the token block carries no
        // specificity at all (see :where() in consent.css).
        $head = $this->styleTag() . ConsentRenderer::scriptTags();
        if ($head !== '') {
            $output = $this->injectBeforeHeadEnd($output, $head);
        }

        $tail = '';
        if (!$this->bannerRendered && !$this->hasBannerMarkup($output)) {
            $tail .= ConsentRenderer::banner();
        }
        $tail .= $this->scriptTag();

        if ($tail !== '') {
            $output = $this->injectBeforeBody($output, $tail);
        }

        $this->grav->output = $output;
    }

    private function styleTag(): string
    {
        $inline = (bool)($this->settings['assets']['inline_css'] ?? true);
        $overrides = ConsentRenderer::inlineStyles($this->settings);

        if (!$inline) {
            return sprintf(
                '<link rel="stylesheet" href="%s">%s',
                htmlspecialchars((string)Utils::url('plugin://consent/assets/consent.css'), ENT_QUOTES, 'UTF-8'),
                $overrides !== '' ? '<style>' . $overrides . '</style>' : ''
            );
        }

        // Inlined by default. The file is small, and having it in the document
        // means the banner is styled on first paint instead of flashing
        // unstyled while a stylesheet request completes.
        return '<style>' . $this->minifiedCss() . $overrides . '</style>';
    }

    /**
     * The stylesheet without its comments.
     *
     * The source file is heavily commented — that is the point of it — but
     * none of that belongs on every page of a live site. Stripping comments
     * and collapsing whitespace takes it to roughly a third of its size, which
     * is what makes inlining the sensible default.
     */
    private function minifiedCss(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $css = @file_get_contents(__DIR__ . '/assets/consent.css');
        if ($css === false) {
            return $cached = '';
        }

        $css = (string)preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string)preg_replace('/\s*\n\s*/', "\n", $css);
        $css = (string)preg_replace('/\n{2,}/', "\n", $css);
        $css = (string)preg_replace('/\s*([{};:,>])\s*/', '$1', $css);

        return $cached = trim($css);
    }

    private function scriptTag(): string
    {
        if (!empty($this->settings['assets']['inline_js'])) {
            $js = @file_get_contents(__DIR__ . '/assets/consent.js');

            return '<script data-consent-ignore>' . ($js !== false ? $js : '') . '</script>';
        }

        return sprintf(
            '<script src="%s" defer data-consent-ignore></script>',
            htmlspecialchars((string)Utils::url('plugin://consent/assets/consent.js'), ENT_QUOTES, 'UTF-8')
        );
    }

    // ── decision endpoint ──────────────────────────────────────────────────

    private function isCountryRequest(): bool
    {
        $path = '/' . trim((string)($this->settings['log']['endpoint'] ?? '/_consent'), '/');

        return rtrim($this->grav['uri']->path(), '/') === rtrim($path, '/') . '/country';
    }

    private function isDecisionRequest(): bool
    {
        if (!Consent::enabled()) {
            return false;
        }

        $configured = trim((string)($this->settings['log']['endpoint'] ?? '/_consent'));
        $configured = '/' . trim($configured, '/');

        return rtrim($this->grav['uri']->path(), '/') === rtrim($configured, '/');
    }

    private function handleDecision(): void
    {
        $handler = new DecisionHandler(
            Consent::instance(),
            new ConsentLog((array)($this->settings['log'] ?? []))
        );

        $this->grav->close($handler->handle());
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function isHtmlResponse(string $output): bool
    {
        $extension = $this->grav['uri']->extension();
        if ($extension !== null && !in_array(strtolower($extension), ['html', 'htm', 'php'], true)) {
            return false;
        }

        // Cheap structural test. A JSON body, an RSS feed or an htmx partial
        // has no </body> to inject before, and injecting into one would be a
        // bug rather than a feature.
        return stripos($output, '</body>') !== false;
    }

    private function isExcludedRoute(): bool
    {
        $path = $this->grav['uri']->path();

        foreach ((array)($this->settings['exclude_routes'] ?? []) as $pattern) {
            $pattern = trim((string)$pattern);
            if ($pattern !== '' && fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function hasBannerMarkup(string $output): bool
    {
        return stripos($output, 'data-consent-banner') !== false;
    }

    private function injectAfterHead(string $output, string $html): string
    {
        $position = stripos($output, '<head>');
        if ($position === false) {
            // No <head> to lead: fall back to before </head>, and if there is
            // no head at all leave the document alone.
            $close = stripos($output, '</head>');

            return $close === false ? $output : substr_replace($output, $html, $close, 0);
        }

        return substr_replace($output, $html, $position + 6, 0);
    }

    private function injectBeforeHeadEnd(string $output, string $html): string
    {
        $position = stripos($output, '</head>');

        return $position === false ? $output : substr_replace($output, $html, $position, 0);
    }

    private function injectBeforeBody(string $output, string $html): string
    {
        $position = strripos($output, '</body>');

        return $position === false ? $output . $html : substr_replace($output, $html, $position, 0);
    }

    // ── Admin Next ─────────────────────────────────────────────────────────

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\Consent\Api\ConsentApiController::class;

        // Static routes before parameterised ones — FastRoute matches in
        // registration order.
        $routes->get('/consent/inventory', [$controller, 'inventory']);
        $routes->get('/consent/stats', [$controller, 'stats']);
        $routes->get('/consent/log', [$controller, 'log']);
        $routes->get('/consent/log/export', [$controller, 'export']);
        $routes->delete('/consent/log', [$controller, 'purge']);
        $routes->get('/consent/config', [$controller, 'config']);
    }

    public function onApiSidebarItems(Event $event): void
    {
        if (empty($this->config->get('plugins.consent.enabled'))
            || empty($this->config->get('plugins.consent.admin.sidebar', true))) {
            return;
        }

        $items = $event['items'] ?? [];
        $items[] = [
            'id' => 'consent',
            'plugin' => 'consent',
            'label' => 'PLUGIN_CONSENT.TITLE',
            'icon' => 'fa-cookie-bite',
            'route' => '/plugin/consent',
            'priority' => 3,
            'authorize' => ['admin.super', 'api.consent.read'],
        ];
        $event['items'] = $items;
    }

    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'consent') {
            return;
        }

        // Translated here rather than handed over as a key: Admin Next resolves
        // sidebar labels but renders a plugin page's title verbatim, so a raw
        // key would show up as the page heading.
        $event['definition'] = [
            'id' => 'consent',
            'plugin' => 'consent',
            'title' => \Grav\Plugin\Consent\Translate::text('PLUGIN_CONSENT.ADMIN.LOG_TITLE'),
            'icon' => 'fa-cookie-bite',
            'page_type' => 'component',
        ];
    }
}
