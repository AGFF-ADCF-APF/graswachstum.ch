<?php

namespace Grav\Plugin\SEOMagic;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\HTTP\Client;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Framework\File\Formatter\JsonFormatter;
use Grav\Framework\File\JsonFile;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SEOGenerator
{
    protected static function requestWithRetry($client, string $method, string $url, array $options, int $maxAttempts, int $backoffMs, array $retryOn = [429, 408, 500, 502, 503, 504])
    {
        $attempt = 0;
        $lastResponse = null;
        $lastStatus = null;
        $lastError = null;
        while ($attempt < max(1, $maxAttempts)) {
            $attempt++;
            try {
                $response = $client->request($method, $url, $options);
                try {
                    $status = $response->getStatusCode();
                } catch (\Throwable $t) {
                    $status = 0;
                }
                $lastResponse = $response;
                $lastStatus = $status;
                // Honour Retry-After header for 429/503 if present
                if (in_array($status, $retryOn, true)) {
                    $headers = [];
                    try { $headers = $response->getHeaders(false); } catch (\Throwable $t) {}
                    $retryAfter = $headers['retry-after'][0] ?? null;
                    if ($attempt < $maxAttempts) {
                        $sleepMs = $retryAfter ? ((int)$retryAfter * 1000) : ($backoffMs * $attempt);
                        if ($sleepMs > 0) { usleep($sleepMs * 1000); }
                        continue;
                    }
                }
                return [$lastResponse, $lastStatus, null];
            } catch (TransportExceptionInterface $e) {
                $lastError = $e->getMessage();
                if ($attempt < $maxAttempts) {
                    $sleepMs = $backoffMs * $attempt;
                    if ($sleepMs > 0) { usleep($sleepMs * 1000); }
                    continue;
                }
                break;
            }
        }
        return [$lastResponse, $lastStatus, $lastError];
    }
    public static function processSEOData($url = null, $callback = null, $show_score = false, $links_only_mode = false) : array
    {
        $config = Grav::instance()['config'];
        $ignore_routes = $config->get('plugins.seo-magic.ignore_routes', []);

        $status = 'success';
        $count = 0;
        $total = 0;

        $lang = Grav::instance()['language'];
        $url = Utils::url($url, true);
        list($client, $url_response) = static::getSiteMap($url);

        if ($url_response instanceof ResponseInterface) {

            // if a sitemap, load that, and get the list of loc URLs
            $content_type = $url_response->getHeaders()['content-type'][0] ?? null;
            if (Utils::contains($content_type, 'application/json')) {
                $sitemap = $url_response->toArray();
                $delay_ms = (int)$config->get('plugins.seo-magic.page_crawl_delay_ms', 0);
                foreach ($sitemap as $entries) {
                    foreach ($entries as $route => $entry) {
                        $total++;
                        if (isset($entry['location'])) {
                            $url = $entry['location'];
                            if (!in_array($entry['route'], $ignore_routes)) {
                                static::processUrlSEOData($url, $client, $route, $callback, $count, $show_score, $links_only_mode);
                                if ($delay_ms > 0) { usleep($delay_ms * 1000); }
                            }
                        }
                    }
                }
            }

            $result = $count . "/" . $total;
            $message = sprintf($lang->translate('PLUGIN_SEOMAGIC.PROCESSED_RESULTS'), $result);

        } else {
            // Fallback: enumerate published, routable Grav pages if sitemap is unavailable
            try {
                /** @var \Grav\Common\Page\Pages $pages */
                $pages = Grav::instance()['pages'];
                $site_base = Utils::url('/', true);
                $collection = $pages->all();
                $delay_ms = (int)$config->get('plugins.seo-magic.page_crawl_delay_ms', 0);
                foreach ($collection as $page) {
                    if (!$page->routable() || !$page->published() || !is_null($page->redirect())) {
                        continue;
                    }
                    $route = $page->route();
                    if (in_array($route, $ignore_routes, true)) {
                        continue;
                    }
                    $total++;
                    $page_url = $page->url(true, true, true);
                    static::processUrlSEOData($page_url, $client ?? static::getHttpClient(), $route, $callback, $count, $show_score, $links_only_mode);
                    if ($delay_ms > 0) { usleep($delay_ms * 1000); }
                }
                $result = $count . "/" . $total;
                $message = sprintf($lang->translate('PLUGIN_SEOMAGIC.PROCESSED_RESULTS'), $result);
            } catch (\Throwable $t) {
                if ($callback) {
                    $callback(404, $url);
                }
                $status = 'error';
                $message = $url_response ?? sprintf($lang->translate('PLUGIN_SEOMAGIC.NO_SITEMAP'), $url);
            }
        }

        return [$status, $message];
    }

    public static function processUrlSEOData(string $url, $client, $route, $callback = null, &$count = 0, $show_score = false, $links_only_mode = false): void
    {
        $config = Grav::instance()['config'];
        $lang = Grav::instance()['language'];
        $body_selectors = $config->get('plugins.seo-magic.body_selectors');
        $enable_link_checker = $config->get('plugins.seo-magic.enable_link_checker');
        $log = Grav::instance()['log'];

        try {
            $pageAttempts = max(1, (int)$config->get('plugins.seo-magic.page_retry_attempts', 2));
            $pageBackoff = max(0, (int)$config->get('plugins.seo-magic.page_retry_backoff_ms', 250));
            list($response, $code) = static::requestWithRetry($client, 'GET', $url, ['timeout' => static::getClientTimeout()], $pageAttempts, $pageBackoff);
            $code = $response->getStatusCode();
            $info = $response->getInfo();
            $url = $info['url'] ?? 'unknown';
            $callback_message = null;
            $response_headers = static::getResponseHeaders($info['response_headers']);
            $page_route = $response_headers['grav-page-route'] ?? '';
            $page_rawroute = $response_headers['grav-page-rawroute'] ?? '';
            $page_title = $response_headers['grav-page-title'] ?? '';

            // If exists
            if (200 === $code) {
                $count++;
                $content = $response->getContent(false);

                if (!empty($content)) {
                    $crawler = new Crawler($content);

                    if ($links_only_mode) {
                        $links = static::getLinks($crawler, $url);
                        $broken_links = static::getBrokenLinks($links, $client, $url);
                        $callback_message = $broken_links;

                    } elseif (!empty($content)) {
                        $info['content_size'] = strlen($content);
                        $title_obj = $crawler->filterXPath('//title');
                        $icon_obj = $crawler->filterXpath('//link[@rel="icon"]');
                        $canonical_obj = $crawler->filterXpath('//link[@rel="canonical"]');
                        $data = new SEOData();

                        $data->set('grav.page_route', $page_route);
                        $data->set('grav.page_rawroute', $page_rawroute);
                        $data->set('grav.page_title', $page_title);
                        $data->set('updated', time());
                        $data->set('response_headers', $response_headers);
                        $data->set('timings', static::getTimings($info));
                        $data->set('info', static::getInfo($info));
                        $data->set('content.body', static::getBodyContent($crawler, $body_selectors));
                        $data->set('content.headers', static::cleanHeaders($crawler));
                        $data->set('content.images', static::getImages($crawler, $client, $url));
                        $data->set('content.good_tags', static::getGoodTags($crawler));
                        $data->set('content.bad_tags', static::getBadTags($crawler));
                        $data->set('head.title', $title_obj->count() ? trim($title_obj->text()) : '');
                        $data->set('head.meta', static::getMetadata($crawler, $data->get('content.body')));
                        $data->set('head.icon', $icon_obj->count() ? $icon_obj->attr('href') : '');
                        // Canonical: prefer <link rel="canonical">, fall back to <meta name="canonical">
                        $canonical = $canonical_obj->count() ? $canonical_obj->attr('href') : '';
                        if (!$canonical) {
                            $metaCanon = $data->get('head.meta.canonical') ?? '';
                            if ($metaCanon) { $canonical = $metaCanon; }
                        }
                        $data->set('head.canonical', $canonical);
                        $data->set('head.links', static::getHeadLinkResources($crawler, $client, $url));
                        $data->set('head.scripts', static::getHeadScriptResources($crawler, $client, $url));

                        // Derive alternates and flags
                        $alternates = [];
                        foreach ((array)$data->get('head.links', []) as $href => $attrs) {
                            if (($attrs['rel'] ?? null) === 'alternate' && !empty($attrs['hreflang'])) {
                                $alternates[$attrs['hreflang']] = $href;
                            }
                        }
                        $data->set('head.alternates', $alternates);
                        $data->set('head.flags.missing_canonical', empty($data->get('head.canonical')));
                        try {
                            $grav = Grav::instance();
                            $language = $grav['language'];
                            if ($language->enabled()) {
                                /** @var \Grav\Common\Page\Pages $pages */
                                $pages = $grav['pages'];
                                $pageObj = $pages->find($page_route ?: $route, true);
                                $hasTranslations = $pageObj ? count((array)$pageObj->translatedLanguages(true)) > 0 : false;
                                $data->set('head.flags.missing_hreflang', $hasTranslations && count($alternates) === 0);
                            }
                        } catch (\Throwable $e) {
                            // ignore
                        }

                        $links = static::getLinks($crawler, $url);
                        if ($enable_link_checker) {
                            $data->set('content.links', static::getBrokenLinks($links, $client, $url));
                        } else {
                            $data->set('content.links', $links);
                        }

                        $data->set('debug', $info['debug']);

                        $data_path = SEOData::getFilename(str_replace('/', '_', $route));
                        $formatter = new JsonFormatter(['encode_options' => JSON_PRETTY_PRINT]);
                        $data_file = new JsonFile($data_path, $formatter);
                        $data_file->save($data);


                        if ($show_score) {
                            $seo_score = new SEOScore($data);
                            $callback_message = intval($seo_score->getScores()->get('score'));
                        }

                        try {
                            $seoService = Grav::instance()['seomagic'] ?? null;
                            if ($seoService && method_exists($seoService, 'buildDashboardRowFromData')) {
                                $reportRow = $seoService->buildDashboardRowFromData($data);
                                $seoService->persistDashboardRow(dirname($data_path), $reportRow);
                            }
                        } catch (\Throwable $ignored) {
                            // fail silently; dashboard will rebuild row lazily
                        }
                    }
                }



            }

            if ($config->get('plugins.seo-magic.log_results')) {
                $log->notice(sprintf($lang->translate('PLUGIN_SEOMAGIC.LOG_RESULTS'), $url, $code));
            }

            if ($callback) {
                $callback($code, $url, $callback_message);
            }
        } catch (TransportExceptionInterface $e) {
            if ($callback) {
                $callback(500, $url, $e->getMessage());
            }
        }
    }

    public static function removeSEOData($url = null)
    {
        if (is_null($url)) {
            $data_path = 'user-data://seo-magic';
        } else {
            $path = str_replace('/', '_', $url);
            $data_path = dirname(SEOData::getFilename($path));
        }

        if (file_exists($data_path)) {
            Folder::delete($data_path);
        }

        return [true, Grav::instance()['language']->translate('PLUGIN_SEOMAGIC.REMOVED_MSG')];
    }

    public static function getSiteMap($url = null, $method = 'GET')
    {
        $config = Grav::instance()['config'];
        $client = static::getHttpClient();

        $sitemap_route = $config->get('plugins.sitemap.route');
        $sitemap_url = $url ?: Utils::url($sitemap_route . '.json', true);

        try {
            $url_response = $client->request($method, $sitemap_url, ['timeout' => static::getClientTimeout()]);
        } catch (TransportExceptionInterface $e) {
            return null;
        }

        // If url exists
        try {
            if ($url_response->getStatusCode() === 200) {
                return [$client, $url_response];
            }
        } catch (\Exception $e) {
            return [$client, $e->getMessage()];
        }

        return [$client, null];
    }

    public static function getHttpClient(): HttpClientInterface
    {
        $config = Grav::instance()['config'];
        $options = [
            'headers' => ['User-Agent' => $config->get('plugins.seo-magic.user_agent')],
        ];

        // Opt-in only: allow insecure TLS for edge cases
        if ($config->get('plugins.seo-magic.insecure_tls', false)) {
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
        }

        return Client::getClient($options, $config->get('plugins.seo-magic.client_connections', 10));
    }


    public static function getBodyContent(Crawler $crawler, $selector)
    {
        $body_parts = [];
        foreach ($selector as $body_selector) {
            $body_elements = $crawler->filter($body_selector);
            if ($body_elements->count() > 0) {
                $elements = $body_elements->each(function (Crawler $node) {
                    return $node->html();
                });
                $body_parts[] = implode("", $elements);
                break;
            }
        }

        if (!empty($body_parts)) {
            $body = implode(' ', $body_parts);
        } else {
            $body = $crawler->filter('body')->html();
        }

        if (!empty($body)) {
            $body = mb_convert_encoding(static::cleanHtml($body), 'HTML-ENTITIES', 'UTF-8');
        }

        return trim($body);
    }

    public static function getMetadata(Crawler $crawler, $body = null): array
    {
        $meta_elements = $crawler->filterXPath('//meta');
        $metadata = [];
        if ($meta_elements->count() > 0) {
            $meta_elements->each(function (Crawler $node) use (&$metadata) {
                $name = $node->attr('name');
                $charset = $node->attr('charset');
                $property = $node->attr('property');
                $content = $node->attr('content');

                if (!$name && $property) {
                    $name = $property;
                } elseif (!$name && $charset) {
                    $name = 'charset';
                    $content = $charset;
                }
                if ($name && $content) {
                    $metadata[$name] = $content;
                }
            });
        }

        $seomagic = new SEOMagic();
        $lang = $seomagic->getLanguage();
        if (!isset($metadata['description'])) {
            $metadata['description'] = $seomagic->generateSummary($body, $lang);
        }
        if (!isset($metadata['keywords'])) {
            $metadata['keywords'] = implode(',', $seomagic->generateKeywords($body, 10, $lang));
        }

        return $metadata;
    }

    public static function getHeadLinkResources(Crawler $crawler, $client, $host_base)
    {
        $link_element = $crawler->filterXPath('//link');
        $link_data = [];
        if ($link_element->count() > 0) {
            $link_element->each(function (Crawler $node) use (&$link_data) {
                $href = $node->attr('href');
                $rel = $node->attr('rel');
                $type = $node->attr('type');
                $media = $node->attr('media');
                $crosssorigin = $node->attr('crosssorigin');
                $hreflang = $node->attr('hreflang');
                if ($href && !in_array($rel, ['icon', 'canonical'])) {
                    $link_data[$href] = ['rel' => $rel, 'type' => $type, 'media' => $media, 'crossorigin' => $crosssorigin, 'hreflang' => $hreflang];
                }
            });
        }
        return $link_data;
    }

    public static function getHeadScriptResources(Crawler $crawler, $client, $host_base)
    {
        $script_element = $crawler->filterXPath('//script');
        $script_data = [];
        if ($script_element->count() > 0) {
            $i = 0;
            $script_element->each(function (Crawler $node) use (&$script_data, &$i) {
                $src = $node->attr('src');
                $async = $node->attr('async');
                $defer = $node->attr('defer');
                $type = $node->attr('type');
                if ($src) {
                    $script_data[$src] = ['async' => $async, 'defer' => $defer, 'type' => $type];
                } else {
                    // Capture inline JSON-LD presence
                    if (strtolower((string)$type) === 'application/ld+json') {
                        $script_data['inline:ldjson:' . (++$i)] = ['type' => $type];
                    }
                }
            });
        }
        return $script_data;
    }

    public static function getImages(Crawler $crawler, $client, $host_base)
    {
        $image_elements = $crawler->filterXPath('//img');
        $images = [];
        static $image_cache = [];
        $config = Grav::instance()['config'];
        $link_timeout = $config->get('plugins.seo-magic.link_check_timeout');
        $check_image_status = $config->get('plugins.seo-magic.enable_image_checker');
        $image_check_sequential = (bool)$config->get('plugins.seo-magic.image_check_sequential', true);

        if ($image_elements->count() > 0) {
            $image_elements->each(function (Crawler $node) use (&$client, $host_base, &$images, &$image_cache, $link_timeout, $check_image_status, $image_check_sequential) {
                $src = trim($node->attr('src'));
                $alt = trim($node->attr('alt'));

                if ($src) {
                    $src = static::getValidLink($src, $host_base);
                    $response = null;
                    $external = static::isExternal($src, $host_base);

                    if ($check_image_status && isset($image_cache[$src])) {
                        $cached = $image_cache[$src];
                        $cached['alt'] = $alt;
                        $cached['external'] = $external;
                        if (!isset($cached['status_msg'])) {
                            $cached['status_msg'] = 'cached';
                        }
                        $images[] = $cached;
                        return;
                    }

                    if ($check_image_status) {
                        if ($image_check_sequential) {
                            try {
                                $resp = $client->request('HEAD', $src, ['timeout' => $link_timeout, 'headers' => ['Connection' => 'close']]);
                                $status = $resp->getStatusCode();
                            } catch (\Exception $e) {
                                $status = 500;
                            }
                            $entry = ['src' => $src, 'alt' => $alt, 'external' => $external, 'status' => $status];
                            $image_cache[$src] = $entry;
                            $images[] = $entry;
                            return; // continue to next img
                        } else {
                            $response = $client->request('HEAD', $src, ['timeout' => $link_timeout, 'headers' => ['Connection' => 'close']]);
                        }
                    }

                    $images[] = ['src' => $src, 'alt' => $alt, 'external' => $external, 'response' => $response];

                }
            });
        }

        if (!$image_check_sequential) {
            foreach ($images as &$image) {
                if (isset($image['response'])) {
                    try {
                        $image['status'] = $image['response']->getStatusCode();
                    } catch (\Exception $e) {
                        $image['status'] = 500;
                    }
                    unset($image['response']);
                    if ($check_image_status) {
                        $image_cache[$image['src']] = $image;
                    }
                }
                if ($check_image_status && isset($image['src']) && !isset($image_cache[$image['src']])) {
                    $image_cache[$image['src']] = $image;
                }
            }
            unset($image);
        }
        return $images;
    }

    public static function getLinks(Crawler $crawler, $host_base)
    {
        $link_elements = $crawler->filterXPath('//a');
        $links = [];
        $config = Grav::instance()['config'];
        $ignore_patterns = (array)$config->get('plugins.seo-magic.link_ignore_patterns', []);
        $respect_robots = (bool)$config->get('plugins.seo-magic.link_respect_robots', true);
        $robots_ua = (string)$config->get('plugins.seo-magic.link_robots_user_agent', '*');
        $disallows = $respect_robots ? static::getRobotsDisallows($robots_ua) : [];

        if ($link_elements->count() > 0) {
            $link_elements->each(function (Crawler $node) use (&$links, $host_base, $ignore_patterns, $disallows) {
                $href = trim($node->attr('href'));
                $target = trim($node->attr('target'));
                $rel = trim($node->attr('rel'));
                $text = trim($node->text());
                if (empty($href)) { return; }
                // ignore patterns
                foreach ($ignore_patterns as $pat) {
                    if (@preg_match($pat, '') !== false) { // regex
                        if (@preg_match($pat, $href)) { return; }
                    } elseif (strpos($href, $pat) !== false) { return; }
                }
                // robots.txt for internal links
                $is_ext = static::isExternal($href, $host_base);
                if (!$is_ext) {
                    $abs = static::getValidLink($href, $host_base);
                    $path = parse_url($abs, PHP_URL_PATH) ?: '/';
                    foreach ($disallows as $dis) { if ($dis !== '' && str_starts_with($path, $dis)) { return; } }
                }
                $links[$href] = ['count' => 0, 'target' => $target, 'rel' => $rel, 'text' => $text, 'external' => $is_ext];
            });
        }

        return $links;
    }

    public static function getGoodTags(Crawler $crawler)
    {
//        $good_html['canonical'] = $crawler->filterXPath('//link[@rel=\'canonical\']')->count();
//        $good_html['hreflang'] = $crawler->filterXPath('//link[@rel=\'alternate\']')->count();

        $good_html['semantic']['header'] = $crawler->filterXPath('//header')->count();
        $good_html['semantic']['nav'] = $crawler->filterXPath('//nav')->count();
        $good_html['semantic']['section'] = $crawler->filterXPath('//section')->count();
        $good_html['semantic']['aside'] = $crawler->filterXPath('//aside')->count();
        $good_html['semantic']['article'] = $crawler->filterXPath('//article')->count();
        $good_html['semantic']['footer'] = $crawler->filterXPath('//footer')->count();

        return $good_html;
    }

    public static function getBadTags(Crawler $crawler)
    {
        $bad_html['iframes'] = $crawler->filterXpath('//iframe')->count();
        $bad_html['flash'] = $crawler->filterXpath('//embed[contains(@src, \'.swf\')]')->count();

        return $bad_html;
    }

    public static function getBrokenLinks($links, $client, $url)
    {
        static $link_cache = [];
        $config = Grav::instance()['config'];
        $link_timeout = $config->get('plugins.seo-magic.link_check_timeout');
        $link_whitelist = $config->get('plugins.seo-magic.link_check_whitelist');
        $concurrency_internal = max(1, (int)$config->get('plugins.seo-magic.link_check_concurrency_internal', 1));
        $concurrency_external = max(1, (int)$config->get('plugins.seo-magic.link_check_concurrency_external', 4));
        $request_delay_ms = max(0, (int)$config->get('plugins.seo-magic.link_request_delay_ms', 0));

        $link_results = [];
        $internal = [];
        $external = [];

        foreach (array_keys($links) as $link) {
            if (Utils::contains($link, $link_whitelist)) {
                $link_data = $links[$link];
                $link_data['status'] = 302;
                $link_data['status_msg'] = 'whitelisted';
                $link_results[$link] = $link_data;
                continue;
            }

            if (isset($link_cache[$link])) {
                $link_data = $link_cache[$link];
                if (!isset($link_data['status_msg'])) {
                    $link_data['status_msg'] = 'cached';
                }
                $link_results[$link] = $link_data;
                continue;
            }

            // Bucket by internal/external
            $is_external = ($links[$link]['external'] ?? null) === true;
            if ($is_external) {
                $external[] = $link;
            } else {
                $internal[] = $link;
            }
        }

        // Helper to process in batches with limited concurrency
        $process_batch = function(array $batch) use (&$links, &$link_cache, &$link_results, $client, $link_timeout, $url) {
            $attempts = max(1, (int)Grav::instance()['config']->get('plugins.seo-magic.link_max_retries', 2));
            $backoff = max(0, (int)Grav::instance()['config']->get('plugins.seo-magic.link_retry_backoff_ms', 200));
            foreach ($batch as $link) {
                $clean_link = static::getValidLink($link, $url);
                list($response, $status, $err) = static::requestWithRetry($client, 'HEAD', $clean_link, [
                    'headers' => [ 'Magic-Action' => 'broken-links', 'Connection' => 'close' ],
                    'timeout' => $link_timeout,
                    'max_redirects' => 20,
                ], $attempts, $backoff);

                $link_data = $link_cache[$link] ?? $links[$link] ?? [];
                if ($status === null) { $status = 500; }
                $link_data['status'] = $status;
                // Friendly status message hints
                if ($status >= 500) { $link_data['status_msg'] = 'Server error'; }
                elseif ($status === 429) { $link_data['status_msg'] = 'Too Many Requests'; }
                elseif ($status >= 400) { $link_data['status_msg'] = 'Client error'; }
                if ($err) {
                    $msg = $err;
                    if (Utils::contains($msg, 'resolve host')) { $msg = 'Could not resolve hostname'; }
                    $link_data['message'] = $msg;
                }
                $link_data['count'] = (int)($link_data['count'] ?? 0) + 1;
                $link_results[$link] = $link_cache[$link] = $link_data;
            }
        };

        // Process internal links (typically same host as the site) conservatively
        if (!empty($internal)) {
            $chunks = array_chunk($internal, $concurrency_internal);
            foreach ($chunks as $chunk) {
                $process_batch($chunk);
                if ($request_delay_ms > 0) { usleep($request_delay_ms * 1000); }
            }
        }

        // Process external links with higher concurrency
        if (!empty($external)) {
            $chunks = array_chunk($external, $concurrency_external);
            foreach ($chunks as $chunk) {
                $process_batch($chunk);
                if ($request_delay_ms > 0) { usleep($request_delay_ms * 1000); }
            }
        }
        return $link_results;
    }

    public static function getTimings($raw)
    {
        $timing_keys = ['start_time', 'appconnect_time', 'connect_time', 'namelookup_time', 'pretransfer_time', 'redirect_time', 'starttransfer_time', 'total_time'];
        return static::filterArrayBykeys($raw, $timing_keys);
    }

    public static function getInfo($raw)
    {
        $timing_keys = ['url', 'content_type', 'header_size', 'content_size', 'request_size', 'redirect_count', 'size_download', 'speed_download', 'download_content_length', 'primary_ip', 'local_ip', 'http_version', 'scheme', 'certinfo', 'ssl_verifyresult'];
        return static::filterArrayBykeys($raw, $timing_keys);
    }

    public static function getResponseHeaders($raw)
    {
        $valid_headers = ['grav-base', 'grav-page-route', 'grav-page-rawroute', 'grav-page-title', 'date', 'server', 'content-length', 'content-type', 'expires', 'cache-control', 'pragma', 'content-encoding'];
        return static::filterArrayBykeys($raw, $valid_headers, function ($entry) {
            $parts = explode(':', $entry, 2);
            return [strtolower(trim($parts[0])), trim($parts[1] ?? '')];
        });
    }

    public static function cleanHeaders($crawler)
    {
        $valid_headers = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
        $headers = array_fill_keys($valid_headers, []);

        $header_elements = $crawler->filter(implode(',', $valid_headers));

        foreach ($header_elements as $header) {
            $text = trim($header->textContent);
            if (!empty($text)) {
                $headers[$header->nodeName][] = $text;
            }
        }

        return $headers;
    }


    public static function cleanHtml($html, array $whitelist = null)
    {
        libxml_use_internal_errors(true) and libxml_clear_errors();

        $whitelist = $whitelist ?? [
                '#text',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'blockquote', 'q', 'p',
                'pre', 'code',
                'ul', 'ol', 'li',
                'b', 'em', 'i', 'u', 'strike', 'sup', 'sub',
            ];

        if (is_object($html)) {
            if ($html->hasChildNodes()) {
                foreach (range($html->childNodes->length - 1, 0) as $i) {
                    static::cleanHtml($html->childNodes->item($i), $whitelist);
                }
            }

            if (!in_array($html->nodeName, $whitelist)) {
                $fragment = $html->ownerDocument->createDocumentFragment();
                while ($html->childNodes && $html->childNodes->length > 0) {
                    $fragment->appendChild($html->childNodes->item(0));
                }
                return $html->parentNode->replaceChild($fragment, $html);
            }

            while ($html->hasAttributes()) {
                $html->removeAttributeNode($html->attributes->item(0));
            }

        } elseif (is_string($html)) {
            $document = new \DOMDocument();
            $document->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            static::cleanHtml($document->documentElement, $whitelist);
            return preg_replace('~<(?:!DOCTYPE|/?(?:html|body))[^>]*>\s*~i', '', $document->saveHTML());
        }
    }

    protected static function filterArrayBykeys($array, $keys, $function = null)
    {
        $filtered = [];
        foreach ($array as $key => $value) {
            if ($function) {
                list($key, $value) = $function($value);
            }

            if (in_array($key, $keys)) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    protected static function in_arrayi($needle, $haystack)
    {
        return in_array(strtolower($needle), array_map('strtolower', $haystack));
    }

    protected static function isExternal($link, $base_url)
    {
        if (Utils::startsWith($link, [$base_url, 'tel:', 'fax:', 'email:']) || !Uri::isExternal($link)) {
            return false;
        }
        return true;
    }

    protected static function getRobotsDisallows(string $ua = '*'): array
    {
        static $cache;
        if (isset($cache[$ua])) { return $cache[$ua]; }
        $disallows = [];
        $generic = [];
        try {
            $seomagic = new \Grav\Plugin\SEOMagic\SEOMagic();
            $content = $seomagic->getRobotsFile();
            if ($content) {
                $currentUA = [];
                foreach (preg_split('/\r?\n/', $content) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) { continue; }
                    if (stripos($line, 'User-agent:') === 0) {
                        $uaLine = trim(substr($line, 11));
                        $uaLine = ltrim($uaLine, ':');
                        $uaLine = trim($uaLine);
                        $currentUA = [$uaLine];
                    } elseif (stripos($line, 'Disallow:') === 0) {
                        $path = trim(substr($line, 9));
                        if ($path === '') { continue; }
                        $path = rtrim($path, '/');
                        foreach ($currentUA as $cua) {
                            if ($cua === '*' || $cua === ' *') { $generic[] = $path; }
                            if (strcasecmp($cua, $ua) === 0) { $disallows[] = $path; }
                        }
                    }
                }
            }
        } catch (\Throwable $t) {}
        // Prefer specific UA list; fallback to generic *
        $out = !empty($disallows) ? $disallows : $generic;
        $out = array_values(array_unique($out));
        $cache[$ua] = $out;
        return $out;
    }

    protected static function getValidLink($link, $base_url)
    {
        if (Utils::startsWith($link, '/')) {
            $parts = parse_url($base_url);
            $port = $parts['port'] ?? null;
            $base = $parts['scheme'] . "://" . $parts['host'];
            $base = $port ? $base . ":" . $port : $base;
            return $base . $link;
        } elseif (Utils::startsWith($link, ['http://', 'https://'])) {
            return $link;
        } else {
            return rtrim($base_url, '/') . '/' . $link;
        }
    }

    protected static function getClientTimeout()
    {
        static $client_timeout;
        if (is_null($client_timeout)) {
            $client_timeout = Grav::instance()['config']->get('plugins.seo-magic.client_timeout');
        }
        return $client_timeout;
    }

}
