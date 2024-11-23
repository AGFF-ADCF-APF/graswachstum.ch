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
    public static function processSEOData($url = null, $callback = null, $show_score = false, $only_link_checker = false) : array
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
                foreach ($sitemap as $entries) {
                    foreach ($entries as $route => $entry) {
                        $total++;
                        if (isset($entry['location'])) {
                            $url = $entry['location'];
                            if (!in_array($entry['route'], $ignore_routes)) {
                                list($status, $message) = static::processUrlSEOData($url, $client, $route, $callback, $count, $show_score, $only_link_checker);
                            }
                        }
                    }
                }
            }

            $result = $count . "/" . $total;
            $message = sprintf($lang->translate('PLUGIN_SEOMAGIC.PROCESSED_RESULTS'), $result);

        } else {
            // URL doesn't exist
            if ($callback) {
                $callback(404, $url);
            }

            $status = 'error';
            $message = $url_response ?? sprintf($lang->translate('PLUGIN_SEOMAGIC.NO_SITEMAP'), $url);
        }

        return [$status, $message];
    }

    public static function processUrlSEOData(string $url, $client, $route, $callback = null, &$count = 0, $show_score = false, $only_link_checker = false): void
    {
        $config = Grav::instance()['config'];
        $lang = Grav::instance()['language'];
        $body_selectors = $config->get('plugins.seo-magic.body_selectors');
        $enable_link_checker = $config->get('plugins.seo-magic.enable_link_checker');
        $log = Grav::instance()['log'];

        try {
            $response = $client->request('GET', $url, ['timeout' => static::getClientTimeout()]);
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

                    if ($only_link_checker) {
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
                        $data->set('head.canonical', $canonical_obj->count() ? $canonical_obj->attr('href') : '');
                        $data->set('head.links', static::getHeadLinkResources($crawler, $client, $url));
                        $data->set('head.scripts', static::getHeadScriptResources($crawler, $client, $url));

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
                    }
                }



            }

            if ($config->get('plugins.seo-magic.log_results')) {
                $log->addNotice(sprintf($lang->translate('PLUGIN_SEOMAGIC.LOG_RESULTS'), $url, $code));
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
            'verify_peer' => false,
            'verify_host' => false,
        ];

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
                if ($href && !in_array($rel, ['icon', 'canonical'])) {
                    $link_data[$href] = ['rel' => $rel, 'type' => $type, 'media' => $media, 'crossorigin' => $crosssorigin];
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
            $script_element->each(function (Crawler $node) use (&$script_data) {
                $src = $node->attr('src');
                $async = $node->attr('async');
                $defer = $node->attr('defer');
                $type = $node->attr('type');
                if ($src) {
                    $script_data[$src] = ['async' => $async, 'defer' => $defer, 'type' => $type];
                }
            });
        }
        return $script_data;
    }

    public static function getImages(Crawler $crawler, $client, $host_base)
    {
        $image_elements = $crawler->filterXPath('//img');
        $images = [];
        $config = Grav::instance()['config'];
        $link_timeout = $config->get('plugins.seo-magic.link_check_timeout');
        $check_image_status = $config->get('plugins.seo-magic.enable_image_checker');

        if ($image_elements->count() > 0) {
            $image_elements->each(function (Crawler $node) use (&$client, $host_base, &$images, &$image_responses, $link_timeout, $check_image_status) {
                $src = trim($node->attr('src'));
                $alt = trim($node->attr('alt'));

                if ($src) {
                    $src = static::getValidLink($src, $host_base);
                    $response = null;

                    if ($check_image_status) {
                        $response = $client->request('HEAD', $src, ['timeout' => $link_timeout]);
                    }

                    $images[] = ['src' => $src, 'alt' => $alt, 'external' => static::isExternal($src, $host_base), 'response' => $response];

                }
            });
        }

        foreach ($images as &$image) {
            if (isset($image['response'])) {
                try {
                    $image['status'] = $image['response']->getStatusCode();
                } catch (\Exception $e) {
                    $image['status'] = 500;
                }
                unset($image['response']);
            }

        }
        return $images;
    }

    public static function getLinks(Crawler $crawler, $host_base)
    {
        $link_elements = $crawler->filterXPath('//a');
        $links = [];

        if ($link_elements->count() > 0) {
            $link_elements->each(function (Crawler $node) use (&$links, $host_base) {
                $href = trim($node->attr('href'));
                $target = trim($node->attr('target'));
                $rel = trim($node->attr('rel'));
                $text = trim($node->text());

                $links[$href] = ['count' => 0, 'target' => $target, 'rel' => $rel, 'text' => $text, 'external' => static::isExternal($href, $host_base)];
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
        $max_retries = $config->get('plugins.seo-magic.link_max_retries');
        $link_timeout = $config->get('plugins.seo-magic.link_check_timeout');
        $link_whitelist = $config->get('plugins.seo-magic.link_check_whitelist');

        $link_responses = [];
        $link_results = [];
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
                if ($link_data['status'] === 200 || ($link_data['count'] >= $max_retries)) {
                    $link_data['status_msg'] = 'cached';
                    $link_results[$link] = $link_data;
                    continue;
                }
            }
            $clean_link = static::getValidLink($link, $url);
            $link_responses[$link] = $client->request('HEAD', $clean_link, [
                'headers' => [
                    'Magic-Action' => 'broken-links',
                ],
                'timeout' => $link_timeout,
                'max_redirects' => 20,
            ]);
        }

        foreach ($link_responses as $link => $response) {
            $link_data = $link_cache[$link] ?? $links[$link] ?? [];
            try {
                $link_data['status'] = $response->getStatusCode();
            } catch (TransportExceptionInterface $e) {
                $msg = $e->getMessage();
                $link_data['status'] = 404;
                if (Utils::contains($msg, 'resolve host')) {
                    $msg = 'Could not resolve hostname';
                }
                $link_data['message'] = $msg;
            }

            $link_data['count']++;

            $link_results[$link] = $link_cache[$link] = $link_data;
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