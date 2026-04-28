<?php

declare(strict_types=1);

namespace Grav\Plugin\SVGIcons\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SvgIconsController extends AbstractApiController
{
    private const PERMISSION = 'api.pages.read';

    /** @var array|null */
    private static $manifest = null;

    /**
     * GET /svg-icons/sets — list available icon sets with their counts.
     */
    public function sets(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $manifest = $this->loadManifest();

        return ApiResponse::create([
            'sets' => $manifest['sets'] ?? [],
        ]);
    }

    /**
     * GET /svg-icons/icons?set=tabler&q=search&offset=0&limit=50 — paginated icon listing.
     */
    public function icons(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION);

        $params = $request->getQueryParams();
        $set    = (string) ($params['set']    ?? 'tabler');
        $search = trim((string) ($params['q'] ?? ''));
        $offset = max(0, (int) ($params['offset'] ?? 0));
        $limit  = max(1, min((int) ($params['limit'] ?? 50), 200));

        $manifest = $this->loadManifest();
        $iconsBySet = $manifest['icons'] ?? [];

        if (!isset($iconsBySet[$set])) {
            $set = array_key_first($iconsBySet) ?? $set;
        }

        $setIcons = $iconsBySet[$set] ?? [];

        if ($search !== '') {
            $setIcons = array_values(array_filter(
                $setIcons,
                static fn($icon) => stripos($icon, $search) !== false,
            ));
        }

        $total = count($setIcons);
        if ($offset >= $total && $total > 0) {
            $offset = max(0, $total - ($total % $limit));
        }

        $slice = array_slice($setIcons, $offset, $limit);
        $items = array_map(
            static fn($icon) => ['name' => $icon, 'value' => $set . '/' . $icon . '.svg'],
            $slice,
        );

        $payload = [
            'set'    => $set,
            'icons'  => $items,
            'offset' => $offset,
            'limit'  => $limit,
            'total'  => $total,
        ];

        if ($offset === 0 && $search === '') {
            $payload['sets'] = $manifest['sets'] ?? [];
        }

        return ApiResponse::create($payload);
    }

    /**
     * Load the plugin's pre-built icon manifest (cached per-request).
     */
    private function loadManifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $pluginDir = dirname(__DIR__, 2);
        $manifestPath = $pluginDir . '/data/icons-manifest.min.json';

        if (!file_exists($manifestPath)) {
            $manifestPath = $pluginDir . '/data/icons-manifest.json';
        }

        if (!file_exists($manifestPath)) {
            return self::$manifest = ['sets' => [], 'icons' => []];
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);

        return self::$manifest = is_array($decoded)
            ? $decoded
            : ['sets' => [], 'icons' => []];
    }
}
