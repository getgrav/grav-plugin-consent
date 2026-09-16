<?php

declare(strict_types=1);

// Minimal Grav collaborators let these regression checks run in the plugin
// checkout without a Grav installation or a test-framework dependency.
namespace Grav\Common {
    final class Grav extends \ArrayObject
    {
        private static ?self $instance = null;

        public static function instance(): self
        {
            return self::$instance ??= new self([
                'base_url_relative' => '/subdir',
                'uri' => new class {
                    public function scheme(bool $absolute): string { return 'https'; }
                },
                'language' => new class {
                    public function translate(string $value): string { return $value; }
                },
                'twig' => new class {
                    public function processTemplate(string $name, array $vars): string
                    {
                        return json_encode(['template' => $name, 'vars' => $vars], JSON_THROW_ON_ERROR);
                    }
                },
            ]);
        }

        public function fireEvent(string $name, object $event): void {}
    }
}

namespace RocketTheme\Toolbox\Event {
    final class Event extends \ArrayObject {}
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    use Grav\Plugin\Consent\Consent;
    use Grav\Plugin\Consent\ConsentRenderer;

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        $checks++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function boot(array $geo = [], array $extra = []): Consent
    {
        $_COOKIE = [];
        $_SERVER = [];
        putenv('GEOIP_COUNTRY_CODE');
        putenv('HTTP_CF_IPCOUNTRY');
        return Consent::boot(array_replace_recursive([
            'enabled' => true, 'policy_version' => 1, 'geo' => $geo,
        ], $extra));
    }

    boot();
    check(ConsentRenderer::payload()['geo'] === ['mode' => 'all'], 'Everyone needs no country URL');
    boot(['mode' => 'typo']);
    check(ConsentRenderer::payload()['geo'] === ['mode' => 'all'], 'Invalid mode must ask everyone');

    foreach (['header', 'country_is'] as $provider) {
        boot(['mode' => 'eu', 'provider' => $provider]);
        $payload = ConsentRenderer::payload();
        check(count($payload['geo']['countries']) === 32, 'European preset contains EEA/UK/CH');
        check(in_array('GB', $payload['geo']['countries'], true), 'Preset includes UK');
        check(!in_array('US', $payload['geo']['countries'], true), 'Preset excludes US');
        check($payload['geo']['url'] === ($provider === 'country_is' ? 'https://api.country.is/' : '/subdir/_consent/country'), 'Correct lookup endpoint');
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'DE';
        $german = ConsentRenderer::payload();
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
        check($german === ConsentRenderer::payload(), 'Payload does not vary by visitor country');
    }

    boot(['mode' => 'custom', 'countries' => [' de ', 'US', 'de', 'USA', '', '1!']]);
    check(ConsentRenderer::payload()['geo']['countries'] === ['DE', 'US'], 'Normalize and deduplicate country codes');

    boot(['mode' => 'eu'], ['log' => ['endpoint' => '/privacy/choice/', 'backend' => 'none']]);
    $payload = ConsentRenderer::payload();
    check($payload['endpoint'] === null, 'Logging can be disabled');
    check($payload['geo']['url'] === '/subdir/privacy/choice/country', 'Country lookup still works without logging');

    foreach (['XX', 'T1', 'ZZ', '12', '<>', 'USA', ''] as $unknown) {
        $consent = boot(['mode' => 'eu']);
        $_SERVER['HTTP_CF_IPCOUNTRY'] = $unknown;
        check($consent->detectCountry() === null, 'Reject unknown header ' . $unknown);
        check($consent->isInScope(), 'Unknown country prompts');
    }

    $consent = boot(['mode' => 'custom', 'header' => 'X-Visitor-Country', 'countries' => ['DE']]);
    $_SERVER['HTTP_X_VISITOR_COUNTRY'] = ' de ';
    check($consent->detectCountry() === 'DE', 'Configured header supported');
    check($consent->isInScope(), 'Custom scope matches');

    $consent = boot(['mode' => 'eu']);
    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
    $_SERVER['GEOIP_COUNTRY_CODE'] = 'gb';
    check($consent->detectCountry() === 'GB', 'Unknown header falls back to server country');

    foreach (['cached', 'dynamic'] as $mode) {
        $consent = boot(['mode' => 'eu', 'outside_scope' => 'deny'], ['render_mode' => $mode]);
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
        check(!$consent->isInScope(), 'Outside selected countries');
        check(!Consent::granted('analytics'), 'Geography does not grant PHP consent in ' . $mode);
        check(Consent::granted('necessary'), 'Required categories remain allowed');
        $block = json_decode(ConsentRenderer::block('analytics', '<iframe>private</iframe>', null, []), true);
        check($block['template'] === 'partials/consent-placeholder.html.twig', 'Outside scope still renders a placeholder');
        check($block['vars']['payload'] === ($mode === 'cached' ? '<iframe>private</iframe>' : null), 'Correct payload for render mode');

        boot(['mode' => 'eu'], ['render_mode' => $mode]);
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
        $_COOKIE['consent'] = json_encode(['v' => 1, 't' => time(), 'c' => ['necessary'], 'r' => 'abc']);
        check(!Consent::granted('analytics'), 'Saved refusal respected outside scope');
    }

    $consent = boot(['mode' => 'eu', 'provider' => 'country_is']);
    $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
    check($consent->isInScope(), 'PHP defers country.is prompting to the browser');

    foreach (['header', 'country_is'] as $provider) {
        $consent = boot(['mode' => 'eu', 'provider' => $provider]);
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
        $_COOKIE['consent_country'] = json_encode(['country' => 'US', 'provider' => 'country_is', 'expires' => time() + 3500]);
        check(!Consent::decided(), 'Geographic allowance is not a consent decision');
        check(Consent::granted('analytics'), 'Outside scope allows automatically with ' . $provider);
        check(ConsentRenderer::payload()['geo']['outsideScope'] === 'allow', 'Automatic allowance is the default');

        boot(['mode' => 'eu', 'provider' => $provider], ['honor_gpc' => true]);
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
        $_SERVER['HTTP_SEC_GPC'] = '1';
        $_COOKIE['consent_country'] = json_encode(['country' => 'US', 'provider' => 'country_is', 'expires' => time() + 3500]);
        check(!Consent::granted('analytics'), 'GPC prevents geographic allowance with ' . $provider);

        boot(['mode' => 'eu', 'provider' => $provider]);
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'US';
        $_COOKIE['consent_country'] = json_encode(['country' => 'US', 'provider' => 'country_is', 'expires' => time() + 3500]);
        $_COOKIE['consent'] = json_encode(['v' => 1, 't' => time(), 'c' => ['necessary'], 'r' => 'abc']);
        check(!Consent::granted('analytics'), 'Saved refusal overrides geographic allowance with ' . $provider);
    }

    foreach (['cached', 'dynamic'] as $mode) {
        boot(['mode' => 'eu', 'provider' => 'country_is'], ['render_mode' => $mode]);
        $_COOKIE['consent_country'] = json_encode(['country' => 'US', 'provider' => 'country_is', 'expires' => time() + 3500]);
        $markup = ConsentRenderer::block('analytics', '<p>Allowed content</p>', null, []);
        check(($markup === '<p>Allowed content</p>') === ($mode === 'dynamic'), 'Only dynamic markup can vary by geographic allowance');
        check(!Consent::granted('nonexistent'), 'Unknown categories stay denied');
    }

    foreach ([
        ['country' => 'US', 'provider' => 'country_is', 'expires' => time() - 1],
        ['country' => 'US', 'provider' => 'header', 'expires' => time() + 100],
        ['country' => 'XX', 'provider' => 'country_is', 'expires' => time() + 100],
        ['country' => 'US', 'provider' => 'country_is', 'expires' => time() + 7200],
    ] as $hint) {
        boot(['mode' => 'eu', 'provider' => 'country_is']);
        $_COOKIE['consent_country'] = json_encode($hint);
        check(!Consent::granted('analytics'), 'Invalid/stale country cookie must not grant');
    }

    echo "Passed {$checks} PHP geographic checks.\n";
}
