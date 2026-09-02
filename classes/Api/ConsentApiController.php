<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent\Api;

use Grav\Common\Grav;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Consent\Consent;
use Grav\Plugin\Consent\ConsentLog;
use Grav\Plugin\Consent\Translate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin Next endpoints: the inventory, the decision log and its statistics.
 *
 * None of this is on the visitor's path — the banner itself never touches the
 * API plugin, so the frontend works on a site that has not installed it.
 */
class ConsentApiController extends AbstractApiController
{
    /**
     * Everything this site stores on a visitor's device, grouped by category.
     *
     * Assembled fresh from config, from plugins answering the registration
     * event, and from anything the auto-blocker has matched — which is why it
     * cannot go stale the way a hand-written list does.
     */
    public function inventory(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.consent.read');

        $consent = Consent::instance();
        $registry = $consent->registry();
        $services = $registry->services();

        $groups = [];
        foreach ($registry->categories()->all() as $id => $category) {
            $rows = array_map(
                static fn ($service) => $service->toArray(),
                $services->inCategory($id)
            );

            $groups[] = [
                'id' => $id,
                'label' => Translate::text($category->label),
                'description' => Translate::text($category->description),
                'required' => $category->required,
                'services' => array_values($rows),
                'cookie_count' => array_sum(array_map(
                    static fn (array $s) => count($s['cookies']),
                    $rows
                )),
            ];
        }

        return ApiResponse::create([
            'version' => $registry->effectiveVersion(),
            'enabled' => $consent->isEnabled(),
            'autoblock' => (bool)($consent->config['autoblock']['enabled'] ?? false),
            'categories' => $groups,
            'totals' => [
                'services' => count($services),
                'cookies' => array_sum(array_map(
                    static fn (array $g) => $g['cookie_count'],
                    $groups
                )),
            ],
        ]);
    }

    public function stats(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.consent.read');

        $params = $request->getQueryParams();
        $days = max(1, min(365, (int)($params['days'] ?? 30)));

        $stats = $this->consentLog()->stats($days);

        // Label the categories so the client does not have to fetch the
        // inventory just to render a summary strip.
        $labels = [];
        foreach (Consent::instance()->registry()->categories()->all() as $id => $category) {
            $labels[$id] = Translate::text($category->label);
        }
        $stats['category_labels'] = $labels;

        return ApiResponse::create($stats);
    }

    public function log(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.consent.read');

        $pagination = $this->getPagination($request);
        $params = $request->getQueryParams();

        $result = $this->consentLog()->query(
            [
                'category' => (string)($params['category'] ?? ''),
                'method' => (string)($params['method'] ?? ''),
                'from' => (int)($params['from'] ?? 0),
                'to' => (int)($params['to'] ?? 0),
            ],
            (int)$pagination['page'],
            (int)$pagination['per_page']
        );

        return ApiResponse::paginated(
            $result['records'],
            $result['total'],
            (int)$pagination['page'],
            (int)$pagination['per_page'],
            $this->getApiBaseUrl() . '/consent/log'
        );
    }

    /**
     * The whole log as CSV.
     *
     * Exists because "show me your consent records" is a request that arrives
     * by email from someone who wants a spreadsheet, not a JSON API.
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.consent.read');

        $result = $this->consentLog()->query([], 1, PHP_INT_MAX);

        $handle = fopen('php://temp', 'r+b');
        fputcsv($handle, ['id', 'created', 'iso8601', 'version', 'method', 'categories', 'services', 'page', 'lang', 'gpc', 'ip_hash', 'ua_hash']);

        foreach ($result['records'] as $record) {
            fputcsv($handle, [
                (string)($record['id'] ?? ''),
                (string)($record['created'] ?? ''),
                date('c', (int)($record['created'] ?? 0)),
                (string)($record['version'] ?? ''),
                (string)($record['method'] ?? ''),
                implode(' ', (array)($record['categories'] ?? [])),
                implode(' ', (array)($record['services'] ?? [])),
                (string)($record['url'] ?? ''),
                (string)($record['lang'] ?? ''),
                !empty($record['gpc']) ? 'yes' : 'no',
                (string)($record['ip_hash'] ?? ''),
                (string)($record['ua_hash'] ?? ''),
            ]);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="consent-log-' . date('Y-m-d') . '.csv"',
        ], $csv);
    }

    public function purge(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.consent.write');

        $removed = $this->consentLog()->purge();

        return ApiResponse::create([
            'removed' => $removed,
            'toast' => [
                'message' => Translate::text('PLUGIN_CONSENT.ADMIN.PURGED'),
                'type' => 'success',
            ],
        ]);
    }

    /**
     * The subset of plugin config the admin components actually need.
     *
     * Never the whole block — there is no reason for a browser to be handed
     * the log salt path, the endpoint internals, or anything else it does not
     * render.
     */
    public function config(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.consent.read');

        $config = (array)Grav::instance()['config']->get('plugins.consent', []);

        return ApiResponse::create([
            'enabled' => (bool)($config['enabled'] ?? false),
            'log_enabled' => ($config['log']['backend'] ?? 'file') !== 'none',
            'retain_days' => (int)($config['log']['retain_days'] ?? 400),
            'autoblock' => (bool)($config['autoblock']['enabled'] ?? false),
            'render_mode' => (string)($config['render_mode'] ?? 'cached'),
        ]);
    }

    private function consentLog(): ConsentLog
    {
        $config = (array)Grav::instance()['config']->get('plugins.consent.log', []);

        return new ConsentLog($config);
    }
}
