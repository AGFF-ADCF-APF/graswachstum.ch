<?php

declare(strict_types=1);

namespace Grav\Plugin\SEOMagic;

use Grav\Common\Data\Data;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\AdminProxy;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SeoMagicApiController extends AbstractApiController
{
    /**
     * GET /seo-magic/config
     *
     * Returns UI-relevant plugin configuration for admin-next components.
     * Only exposes feature toggles that affect which UI elements are shown —
     * never returns crawler internals, timeouts, or user agent strings.
     */
    public function config(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $cfg = $this->grav['config']->get('plugins.seo-magic', []);

        return ApiResponse::create([
            'enabled'                 => (bool) ($cfg['enabled'] ?? true),
            'enable_quicktray'        => (bool) ($cfg['enable_quicktray'] ?? true),
            'enable_seo_report'       => (bool) ($cfg['enable_seo_report'] ?? true),
            'enable_site_seo_report'  => (bool) ($cfg['enable_site_seo_report'] ?? true),
            'enable_image_checker'    => (bool) ($cfg['enable_image_checker'] ?? true),
            'enable_link_checker'     => (bool) ($cfg['enable_link_checker'] ?? false),
        ]);
    }

    /**
     * GET /seo-magic/dashboard
     *
     * Returns summary stats, paginated listing, available languages, and scan status.
     */
    public function dashboard(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $query = $request->getQueryParams();

        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 25));
        $sort    = $query['sort'] ?? 'updated';
        $dir     = $query['dir'] ?? 'desc';
        $search  = $query['q'] ?? '';
        $lang    = $query['lang'] ?? '';
        $mode    = $query['mode'] ?? 'all';

        $rows = $this->callPlugin('getSeoData');

        if (!is_array($rows)) {
            $rows = [];
        }

        $summary   = $this->callPlugin('summarizeSeoData', $rows);
        $languages = $this->callPlugin('collectLanguages', $rows);

        $listing = $this->callPlugin('buildSeoListing', $rows, [
            'page'     => $page,
            'per_page' => $perPage,
            'sort'     => $sort,
            'dir'      => $dir,
            'q'        => $search,
            'lang'     => $lang,
            'mode'     => $mode,
        ]);

        // Serialize listing rows: convert Data objects to plain arrays
        if (isset($listing['rows']) && is_array($listing['rows'])) {
            $listing['rows'] = array_map([$this, 'serializeRow'], $listing['rows']);
        }

        $status = $this->callPlugin('readScanStatus');

        return ApiResponse::create([
            'summary'   => $summary,
            'listing'   => $listing,
            'languages' => $languages,
            'status'    => $status,
        ]);
    }

    /**
     * POST /seo-magic/crawl
     *
     * Starts a full background crawl.
     */
    public function crawl(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $this->callPlugin('initScanStatus', 'full');
        $spawned = $this->callPlugin('launchBackgroundScan', 'full');

        return ApiResponse::create([
            'status'  => 'success',
            'message' => 'Crawl started',
            'mode'    => 'full',
            'spawned' => (bool) $spawned,
        ]);
    }

    /**
     * POST /seo-magic/crawl-changed
     *
     * Starts a background crawl for changed pages only.
     */
    public function crawlChanged(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $this->callPlugin('initScanStatus', 'changed');
        $spawned = $this->callPlugin('launchBackgroundScan', 'changed');

        return ApiResponse::create([
            'status'  => 'success',
            'message' => 'Crawl started',
            'mode'    => 'changed',
            'spawned' => (bool) $spawned,
        ]);
    }

    /**
     * POST /seo-magic/delete-data
     *
     * Removes all SEO data.
     */
    public function deleteData(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        [$success, $message] = SEOGenerator::removeSEOData();

        return ApiResponse::create([
            'status'  => $success ? 'success' : 'error',
            'message' => $message,
        ]);
    }

    /**
     * GET /seo-magic/export/csv
     *
     * Exports all SEO data as a CSV file download.
     */
    public function exportCsv(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $rows = $this->callPlugin('getSeoData');

        if (!is_array($rows)) {
            $rows = [];
        }

        $handle = fopen('php://temp', 'r+');

        // Header row
        fputcsv($handle, [
            'route',
            'rawroute',
            'title',
            'url',
            'score',
            'total_links',
            'broken_links_count',
            'first_broken_link',
            'broken_links',
        ]);

        foreach ($rows as $row) {
            $score = $this->extractScore($row);
            $brokenLinks = $row['broken_links'] ?? [];
            if (!is_array($brokenLinks)) {
                $brokenLinks = [];
            }

            $brokenCount = count($brokenLinks);
            $firstBroken = '';
            if ($brokenCount > 0) {
                $firstHref = array_key_first($brokenLinks);
                $firstInfo = $brokenLinks[$firstHref] ?? [];
                $firstBroken = ($firstInfo['status'] ?? '') . ' ' . $firstHref;
                if (!empty($firstInfo['message'])) {
                    $firstBroken .= ' (' . $firstInfo['message'] . ')';
                }
            }
            $brokenList = implode(' | ', array_map(
                static fn($href, $info) => ($info['status'] ?? '') . " {$href}" . (!empty($info['message']) ? " ({$info['message']})" : ''),
                array_keys($brokenLinks),
                $brokenLinks,
            ));

            fputcsv($handle, [
                $row['route'] ?? '',
                $row['rawroute'] ?? '',
                $row['title'] ?? '',
                $row['url'] ?? '',
                $score,
                $row['total_links'] ?? 0,
                $brokenCount,
                $firstBroken,
                $brokenList,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response(
            200,
            [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="seo-magic-report.csv"',
                'Cache-Control'       => 'no-store',
            ],
            $csv,
        );
    }

    /**
     * GET /seo-magic/status
     *
     * Returns the current scan status.
     */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $status = $this->callPlugin('readScanStatus');

        return ApiResponse::create($status);
    }

    /**
     * POST /seo-magic/cancel
     *
     * Requests cancellation of the current scan.
     */
    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $this->callPlugin('setCancelFlag', true);

        return ApiResponse::create([
            'status'  => 'success',
            'message' => 'Cancellation requested',
        ]);
    }

    /**
     * GET /seo-magic/page-data/{route:.*}
     *
     * Returns detailed SEO data for a specific page.
     */
    public function pageData(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $route = $this->getRouteParam($request, 'route') ?? '';
        $query = $request->getQueryParams();
        $lang = $query['lang'] ?? null;

        // Set active language if specified so getData() resolves the correct lang data
        $language = $this->grav['language'];
        if ($lang && $language->enabled()) {
            $language->setActive($lang);
        }

        // Ensure pages are initialized
        $pages = AdminProxy::enablePages();
        $page  = $pages->find('/' . ltrim($route, '/'));

        if (!$page) {
            throw new NotFoundException("Page not found: /{$route}");
        }

        /** @var SEOMagic $seo */
        $seo = $this->grav['seomagic'];

        $seoData = $seo->getData($page, $lang);

        if (!$seoData) {
            throw new NotFoundException("No SEO data found for page: /{$route}");
        }

        // Bootstrap fallback for pages with no SEO data yet — provide basic info
        // without calling generateSummary/generateKeywords which require admin page context
        if (!$seoData->get('updated')) {
            $seoData->set('head.title', $page->title());
            $seoData->set('content.headers', ['h1'=>[],'h2'=>[],'h3'=>[],'h4'=>[],'h5'=>[],'h6'=>[]]);
            try {
                $body = strip_tags((string) $page->content());
                $seoData->set('content.body', $body);
            } catch (\Throwable $e) {
                // content() may fail without full page context
            }
        }

        // Guard against methods that assume admin page context
        try {
            $metadata = $seo->updateMetadata($page);
        } catch (\Throwable $e) {
            $metadata = [];
        }

        $scoreData = [];
        try {
            $seoScore = new SEOScore($seoData);
            $scores = $seoScore->getScores();
            $scoreData = $scores instanceof Data ? $scores->toArray() : (array) $scores;
        } catch (\Throwable $e) {
            // Score calculation may fail if data is incomplete
        }

        $keywords = explode(',', (string) $seoData->get('head.meta.keywords'));
        $body = '';
        try {
            $body = $seo->cleanBody($seoData, $keywords);
        } catch (\Throwable $e) {}

        return ApiResponse::create([
            'rawdata'    => $seoData->toArray(),
            'metadata'   => $metadata,
            'score'      => $scoreData,
            'body'       => $body,
            'page_title' => $page->title(),
            'page_route' => $page->route(),
            'updated'    => $seoData->get('updated'),
        ]);
    }

    /**
     * GET /seo-magic/sitemap-status
     *
     * Returns whether the sitemap plugin is installed and enabled.
     */
    public function sitemapStatus(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $installed = class_exists('Grav\\Plugin\\SitemapPlugin')
            || $this->grav['plugins']->get('sitemap') !== null;
        $enabled = (bool) $this->config->get('plugins.sitemap.enabled', false);

        return ApiResponse::create([
            'installed' => $installed,
            'enabled'   => $enabled,
        ]);
    }

    // ─── Private helpers ─────────────────────────────────────────────

    /**
     * Get the seo-magic plugin instance.
     */
    private function plugin(): \Grav\Plugin\SeoMagicPlugin
    {
        $plugin = $this->grav['seomagic_plugin'] ?? null;

        if (!$plugin) {
            throw new NotFoundException('The seo-magic plugin is not installed or enabled.');
        }

        return $plugin;
    }

    /**
     * Call a protected method on the seo-magic plugin via Closure binding.
     */
    private function callPlugin(string $method, mixed ...$args): mixed
    {
        $plugin = $this->plugin();
        $closure = \Closure::bind(function () use ($method, $args) {
            return $this->$method(...$args);
        }, $plugin, $plugin);

        return $closure();
    }

    /**
     * Serialize a single listing row, converting Data objects to arrays.
     */
    private function serializeRow(array $row): array
    {
        if (isset($row['score']) && $row['score'] instanceof Data) {
            $row['score'] = $row['score']->toArray();
        }

        return $row;
    }

    /**
     * Extract the numeric score value from a row's score field.
     */
    private function extractScore(array $row): string
    {
        $score = $row['score'] ?? '';

        if ($score instanceof Data) {
            return (string) $score->get('score', '');
        }

        if (is_array($score)) {
            return (string) ($score['score'] ?? '');
        }

        return (string) $score;
    }
}
