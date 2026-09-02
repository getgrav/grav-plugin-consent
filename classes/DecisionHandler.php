<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

use Grav\Common\Grav;
use Grav\Framework\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use RocketTheme\Toolbox\Event\Event;

/**
 * Receives a decision from the browser.
 *
 * The cookie itself is written client-side, so consent applies the instant the
 * visitor clicks and the page needs no reload. This endpoint exists for the
 * two things the browser cannot do on its own:
 *
 *   1. Write the audit record that demonstrates consent was given.
 *   2. Fire `onConsentChanged` server-side, so a plugin can expire an HttpOnly
 *      cookie that JavaScript is not allowed to touch.
 *
 * A plain Grav route, not an API-plugin route — the frontend must not depend
 * on the API plugin being installed.
 */
final class DecisionHandler
{
    public function __construct(
        private readonly Consent $consent,
        private readonly ConsentLog $log,
    ) {
    }

    public function handle(): ResponseInterface
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return $this->json(['error' => 'method_not_allowed'], 405);
        }

        if (!$this->sameOrigin()) {
            // A forged consent is only a nuisance in the browser, but a forged
            // *log record* would poison the one thing the log exists to
            // provide. Cheap to check and it costs nothing at the cache layer.
            return $this->json(['error' => 'cross_origin'], 403);
        }

        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'bad_request'], 400);
        }

        $registry = $this->consent->registry();
        $categories = $registry->categories();

        $requested = array_values(array_filter(array_map(
            static fn ($c) => CategoryRegistry::normaliseId((string)$c),
            (array)($body['categories'] ?? [])
        )));

        // Only categories this site actually defines, plus the required ones
        // whether the client sent them or not.
        $granted = array_values(array_unique(array_merge(
            array_intersect($requested, $categories->ids()),
            $categories->requiredIds()
        )));

        $previousState = $this->consent->currentState();
        $previous = $previousState?->categories ?? [];
        $denied = array_values(array_diff($categories->ids(), $granted));

        $state = ConsentState::make(
            categories: $granted,
            version: $registry->effectiveVersion(),
            id: ConsentLog::newId(),
        );

        $event = new Event([
            'state' => $state,
            'granted' => $granted,
            'denied' => $denied,
            'previous' => $previous,
            'changed' => array_values(array_merge(
                array_diff($granted, $previous),
                array_diff($previous, $granted)
            )),
            'first' => $previousState === null,
            'method' => $this->method($body),
        ]);
        Grav::instance()->fireEvent('onConsentChanged', $event);

        $recordId = $state->id;
        if ($this->log->enabled()) {
            $recordId = $this->log->write($this->record($state, $granted, $body));
        }

        return $this->json([
            'ok' => true,
            'id' => $recordId,
            'version' => $state->version,
            'categories' => $granted,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<int, string> $granted
     * @return array<string, mixed>
     */
    private function record(ConsentState $state, array $granted, array $body): array
    {
        $config = (array)($this->consent->config['log'] ?? []);
        $services = [];

        foreach ($this->consent->registry()->services() as $service) {
            if (in_array($service->category, $granted, true)) {
                $services[] = $service->id;
            }
        }

        $record = [
            'id' => $state->id,
            'created' => $state->time,
            'version' => $state->version,
            'categories' => $granted,
            'services' => $services,
            'method' => $this->method($body),
            'lang' => (string)(Grav::instance()['language']->getLanguage() ?: 'en'),
            'url' => $this->safeUrl((string)($body['url'] ?? '')),
            'gpc' => (bool)($body['gpc'] ?? false),
        ];

        if (!empty($config['hash_ip'])) {
            $record['ip_hash'] = $this->log->hash($this->clientIp());
        }
        if (!empty($config['hash_ua'])) {
            $record['ua_hash'] = $this->log->hash((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }

        return array_filter($record, static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function method(array $body): string
    {
        $method = (string)($body['method'] ?? 'save_preferences');
        $allowed = ['accept_all', 'reject_all', 'save_preferences', 'gpc_auto', 'api'];

        return in_array($method, $allowed, true) ? $method : 'save_preferences';
    }

    /**
     * The page the decision was made on, path only.
     *
     * Query strings can carry anything — a reset token, an email address in a
     * campaign parameter — and none of it belongs in a privacy log.
     */
    private function safeUrl(string $url): string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $path = substr($path, 0, 255);

        return $path !== '' ? $path : '/';
    }

    private function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = trim((string)($_SERVER[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            // X-Forwarded-For is a chain; the client is the first entry.
            $value = trim(explode(',', $value)[0]);
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }

        return '';
    }

    private function sameOrigin(): bool
    {
        $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($fetchSite === 'same-origin' || $fetchSite === 'none') {
            return true;
        }
        if ($fetchSite !== '') {
            return false;
        }

        // Older browsers send no Sec-Fetch-Site. Fall back to Origin, and if
        // that is missing too, allow it — refusing would break the log on
        // browsers that are otherwise perfectly fine.
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            return true;
        }

        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));

        return $host !== '' && $host === strtolower((string)Grav::instance()['uri']->host());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            [
                'Content-Type' => 'application/json',
                // Never cache a decision receipt at any layer.
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ],
            (string)json_encode($data, JSON_UNESCAPED_SLASHES)
        );
    }
}
