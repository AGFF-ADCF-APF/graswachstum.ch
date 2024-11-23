<?php

namespace Grav\Plugin\SEOMagic;

use Grav\Common\Data\Data;
use Grav\Common\File\CompiledJsonFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Media;
use Grav\Common\Utils;
use Grav\Plugin\ReadingTime\TwigReadingTimeFilters;
use Grav\Plugin\SEOMagic\StopWords\English;
use Grav\Plugin\SEOMagic\StopWords\French;
use Grav\Plugin\SEOMagic\StopWords\German;
use Grav\Plugin\SEOMagic\StopWords\Indonesian;
use Grav\Plugin\SEOMagic\StopWords\Italian;
use Grav\Plugin\SEOMagic\StopWords\Norwegian;
use Grav\Plugin\SEOMagic\StopWords\Russian;
use Grav\Plugin\SEOMagic\StopWords\Spanish;
use PhpScience\TextRank\TextRankFacade;
use RocketTheme\Toolbox\Event\Event;

class SEOMagic
{
    protected $config;
    protected $data_storage;
    protected $raw_data = [];

    public function __construct()
    {
        $this->config = Grav::instance()['config'];

    }

    public function getPageTitle(PageInterface $page)
    {
        $data = $this->getData($page);
        return $data->get('head.title', null);
    }

    public function getPageKeywords(PageInterface $page, int $count = 10)
    {
        $data = $this->getData($page);
        $body = $data->get('content.body', '');
        $lang = $this->getLanguage();
        return $this->generateKeywords($body, $count, $lang);
    }

    public function getPageDescription(PageInterface $page)
    {
        $data = $this->getData($page);
        $body = $data->get('content.body', '');
        $lang = $this->getLanguage();
        return $this->generateSummary($body, $lang);
    }

    public function updateMetadata(PageInterface $page)
    {
        $data = $this->getData($page);
        $metadata = null;

        if (!is_null($data)) {
            $page->resetMetadata();
            $header = new Data((array) $page->header());
            /** @var Media $media */
            $media = $page->media();
            $lang = $this->getLanguage();

            $header_metadata = $header->get('metadata') ?? [];
            if ($header_metadata) {
                $metadata = Utils::arrayMergeRecursiveUnique($this->config->get('site.metadata'), $header_metadata);
            } else {
                $metadata = $this->config->get('site.metadata');
            }

            // Get initial metadata for the page
            $metadata['generator'] = 'GravCMS';

            $page_title = $header->get($this->config->get('plugins.seo-magic.metadata.title_frontmatter'));
            $meta_title = trim($header->get('seo-magic.title') ?? $page_title ?? $this->getPageTitle($page) ?? $page->title());

            $payload = new \stdClass();
            $payload->title = $meta_title;

            Grav::instance()->fireEvent('onSeoMagicMetadataTitle', new Event(['data' => $payload]));

            $metadata['title'] = $payload->title;
            $body = $this->cleanBody($data);

            $description_default_fallback = $this->config->get('plugins.seo-magic.desc_default_fallback', false);
            $site_description = $this->config->get('site.metadata.description', '');
            $page_description_default_fallback = $header->get('seo-magic.desc_default_fallback') ?? null;

            if ($page_description_default_fallback === true) {
                $meta_description = trim($site_description);
            } else {
                $page_description = $header->get($this->config->get('plugins.seo-magic.metadata.description_frontmatter', 'seo-magic.description'));
                $description = $header->get('seo-magic.description') ?? $page_description ?? $this->generateSummary($data->get('content.body', null), $lang) ?: $header->get('metadata.description') ?? $site_description;
                if ($description_default_fallback && empty($description)) {
                    $description = $site_description;
                }
                $meta_description = trim($description) ?? '';
            }

            $payload = new \stdClass();
            $payload->description = $meta_description;

            Grav::instance()->fireEvent('onSeoMagicMetadataDescription', new Event(['data' => $payload]));

            $metadata['description'] = $payload->description;


            $position = $header->get('seo-magic.keywords_position') ?? 'prepend';

            $page_keywords = $header->get($this->config->get('plugins.seo-magic.metadata.keywords_frontmatter', 'metadata.keywords'));
            if (is_string($page_keywords)) {
                $page_keywords = array_map('trim', explode(',', $page_keywords));
            }
            $keywords = $header->get('seo-magic.keywords') ?? $page_keywords;

            $keywords_default_fallback = $this->config->get('plugins.seo-magic.keywords_default_fallback', false);
            $site_keywords = array_map('trim', explode(',', $this->config->get('site.metadata.keywords', '')));
            $page_keywords_default_fallback = $header->get('seo-magic.keywords_default_fallback') ?? null;

            if ($page_keywords_default_fallback === true) {
                $generated = $site_keywords;
            } else {
                $generated = $this->generateKeywords($body, 10, $lang) ?? [];
                if ($keywords_default_fallback) {
                    $generated = array_merge($generated, $site_keywords);
                }
            }

            if ($keywords && $position === 'prepend') {
                $keywords = array_unique(array_merge($keywords, $generated));
                $meta_keywords = implode(',', array_slice($keywords, 0, 10));
            } elseif ($keywords) {
                $meta_keywords = implode(',', $keywords);
            } elseif ($generated) {
                $meta_keywords = implode(',', $generated);
            } else {
                $meta_keywords = '';
            }

            $payload = new \stdClass();
            $payload->keywords = $meta_keywords;

            Grav::instance()->fireEvent('onSeoMagicMetadataKeywords', new Event(['data' => $payload]));


            $metadata['keywords'] = $payload->keywords;

            // Get an image for the opengraph cards
            $image_type = $this->pluginVar($page, 'seo-magic.images.type', 'auto');
            if ($image_type !== 'none') {
                  $metadata['image'] = Grav::instance()['pages']->url($page->route(), null, true) . '/seomagic-image.jpg';
            }

            $robots_meta = $this->pluginVar($page, 'seo-magic.robots');
            $robots_meta_page = $header->get('seo-magic.robots') ?? null;
            $robots_txt = $this->getRobotsFile();

            // Robots file doesn't exist, add robots meta even if it doesn't exist on page
            if (!$robots_txt) {
                $metadata['robots'] = $robots_meta;
            } elseif ($robots_meta_page) {
                $metadata['robots'] = $robots_meta_page;
            }

            if ($this->config->get('plugins.seo-magic.facebook.enabled')) {
                $metadata['fb:app_id'] = $this->config->get('plugins.seo-magic.facebook.appid');
            }

            if ($this->config->get('plugins.seo-magic.opengraph') === true ||
                $this->config->get('plugins.seo-magic.opengraph.enabled')) {
                $metadata['og:url'] = $page->url(true);

                $metadata['og:site_name'] = $this->config->get('site.title');
                $metadata['og:title'] = $header->get('seo-magic.opengraph.title') ?? $metadata['title'];
                $metadata['og:description'] = $header->get('seo-magic.opengraph.description') ?? $metadata['description'];

                if ($page->home()) {
                    $metadata['og:type'] = 'website';
                } else {
                    $metadata['og:type'] = 'article';
                }

                if (isset($metadata['image'])) {
                    $metadata['og:image'] = $metadata['image'];
                    $metadata['og:image:width'] = $this->config->get('plugins.seo-magic.images.size.x');
                    $metadata['og:image:height'] = $this->config->get('plugins.seo-magic.images.size.y');
                    if (Utils::startsWith($metadata['image'], ['https://', '//'])) {
                        $metadata['og:image:secure'] = $metadata['image'];
                    }
                }
            }
            if ($this->config->get('plugins.seo-magic.twitter.enabled')) {
                $metadata['twitter:card'] = $this->config->get('plugins.seo-magic.twitter.card');
                $metadata['twitter:site'] = $this->config->get('plugins.seo-magic.twitter.username');
                $metadata['twitter:title'] = $header->get('seo-magic.twitter.title') ?? $metadata['og:title'] ?? $metadata['title'];
                $metadata['twitter:description'] = $header->get('seo-magic.twitter.description') ?? $metadata['og:description'] ?? $metadata['description'];

                if (isset($metadata['image'])) {
                    $metadata['twitter:image'] = $metadata['image'];
                    $metadata['twitter:image:alt'] = $metadata['title'];
                }
                
                if ($this->config->get('plugins.seo-magic.twitter.reading_time', true) &&
                    $this->config->get('plugins.readingtime.enabled'))  {
                    $reading_time = new TwigReadingTimeFilters();
                    $metadata['twitter:label1'] = 'Est. reading time';
                    $metadata['twitter:data1'] = $reading_time->getReadingTime($data->get('content.body'), ['format' => '{minutes_short_count} {minutes_text}']);
                }
            }

        }
        return $metadata;
    }

    public function getPageImage($image_type, $page, $url = true)
    {
        $fallback = $this->getAutoImageOrder($image_type);

        $options = [
            'image_x' => $this->config->get('plugins.seo-magic.images.size.x') ?: $this->config->get('plugins.seo-magic.images.size.y'),
            'image_y' => $this->config->get('plugins.seo-magic.images.size.y') ?: $this->config->get('plugins.seo-magic.images.size.x')
        ];

        foreach ($fallback as $type) {
            $image = $this->getPageImageByType($type, $page, $url, $options);
            if (isset($image)) {
                break;
            }
        }
        return $image;

    }

    protected function getAutoImageOrder($image_type): array
    {
        $auto_order = $this->config->get('plugins.seo-magic.images.auto_order');

        if ($image_type === 'webshot') {
            $fallback = ['webshot'];
        } elseif ($image_type == 'auto') {
            $fallback = $auto_order;
        } else {
            $found_index = array_search($image_type, $auto_order);
            $fallback = array_slice($auto_order, $found_index);
        }

        return $fallback;
    }

    public function getPageImageByType($image_type, $page, $url, $options): ?string
    {
        $media = $page->media();
        $image = null;
        $image_media = null;

        switch ($image_type) {
            case 'webshot':
                $webshot_url = $this->config->get('plugins.seo-magic.images.webshot_url', 'https://webshot.getgrav.org');
                $image = rtrim($webshot_url, '/') . '/?url=' . base64_encode($page->url(true));
                break;
            case 'image_name':
                $image_name = $this->pluginVar($page, 'seo-magic.images.image_name');
                $language = Grav::instance()['language'];
                $image_names = $this->getArrayFromString($image_name);

                if ($language->enabled()) {
                    $lang = $language->getLanguage();
                    foreach (array_reverse($image_names) as $i) {
                        $image_parts = pathinfo($i);
                        array_unshift($image_names, sprintf('%s.%s.%s',$image_parts['filename'], $lang, $image_parts['extension']));
                    }
                }

                foreach ($image_names as $iname) {
                    $image_media = $media->get($iname);
                    if (isset($image_media)) {
                        break 2;
                    }
                }
                break;
            case 'image_attribute':
                $image_attribute = $this->pluginVar($page, 'seo-magic.images.image_attribute');
                $header_array = (array) $page->header();
                $header = new Data($header_array);
                $header_attributes = $this->getArrayFromString($image_attribute);

                foreach ($header_attributes as $attribute) {
                    $header_image = $header->get($attribute);
                    if (is_string($header_image) && isset($media[$header_image])) {
                        $image_media = $media[$header_image] ?? null;
                    } elseif (is_array($header_image)) {
                        $first_item = current($header_image);
                        $image_media = $media->get($first_item['name'] ?? null);
                    }
                    if (isset($image_media)) {
                        break 2;
                    }
                }

                break;
            case 'og-image':
                $language = Grav::instance()['language'];
                $valid_files = ['og-image.png', 'og-image.jpg'];
                if ($language->enabled()) {
                    $lang = $language->getLanguage();
                    array_unshift($valid_files, "og-image.$lang.png", "og-image.$lang.jpg");
                }
                foreach ($valid_files as $file) {
                    $image_media = $media->get($file);
                    if (isset($image_media)) {
                        break;
                    }
                }
                break;
            case 'media_first':
                $images = $media->images();
                $image_media = array_shift($images);
                break;
            default:
                $image = $this->config->get('plugins.seo-magic.images.default_image');
        }


        if (is_null($image) && isset($image_media)) {
            $image_media = $image_media->cropZoom($options['image_x'], $options['image_y']);

            if ($url) {
                $image = $image_media->url();
            } else {
                $image = $image_media->path();
            }
        }


        return $image;
    }

    protected function getArrayFromString($string): array
    {
        return array_map('trim', explode(',', $string));
    }

    public function generateSummary($content, $lang = null)
    {
        $page = Utils::isAdminPlugin() ? Grav::instance()['admin']->page() : Grav::instance()['page'];

        if ($this->pluginVar($page, 'seo-magic.autogenerate_desc' )) {

            $content = $this->cleanText(strip_tags((string) $content));
            $method = $this->pluginVar($page,'seo-magic.desc_summarization_method');
            $summary_attribute = $this->pluginVar($page, 'seo-magic.desc_summarization_attribute');
            $summary = '';

            $hash = md5($page->raw());
            $local_path = Grav::instance()['locator']->findResource('cache://seo-magic/summaries', true, true);

            if (!file_exists($local_path)) {
                Folder::create($local_path);
            }

            $local_summary = "$local_path/$hash";
            if (!file_exists($local_summary)) {
                if ($method === 'textrank') {
                    $words = $this->config->get('plugins.seo-magic.custom_stopwords', null);
                    $api = new TextRankFacade();
                    $api->setStopWords($this->getStopWords($lang, $words));
                    $summary = (array)$api->summarizeTextBasic($content);
                    $summary = implode(" ", $summary);
                    if (strlen($summary) > 160) {
                        $summary = substr($summary, 0, 159) . "…";
                    }
                } elseif ($method === 'attribute' && isset($summary_attribute)) {

                    $header_array = (array) $page->header();
                    $header = new Data($header_array);
                    $header_attributes = $this->getArrayFromString($summary_attribute);

                    foreach ($header_attributes as $attribute) {
                        $header_summary = $header->get($attribute);
                        if (is_string($header_summary)) {
                            $summary = $header_summary;
                        }
                        if (!empty($summary)) {
                            break;
                        }
                    }
                } else {
                    $summary = Utils::truncate($content, 157, false, ' ', '...');
                }
                file_put_contents($local_summary, $summary);
            } else {
                $summary = file_get_contents($local_summary);
            }

            return $summary;
        }
        return null;
    }

    public function generateKeywords($content, $count = 10, $lang = null): array
    {
        $global_keywords = $this->config->get('plugins.seo-magic.global_keywords', []);
        $keywords = [];
        $page = Utils::isAdminPlugin() ? Grav::instance()['admin']->page() : Grav::instance()['page'];

        if ($this->pluginVar($page, 'seo-magic.autogenerate_keywords' )) {

            $hash = md5($page->raw());
            $local_path = Grav::instance()['locator']->findResource('cache://seo-magic/keywords', true, true);

            if (!file_exists($local_path)) {
                Folder::create($local_path);
            }

            $local_keywords = "$local_path/$hash";
            if (!file_exists($local_keywords)) {
                $words = $this->config->get('plugins.seo-magic.custom_stopwords', null);
                $content = $this->cleanText(strip_tags($content));
                $api = new TextRankFacade();
                $api->setStopWords($this->getStopWords($lang, $words));
                $keywords = array_keys($api->getOnlyKeyWords($content));

                file_put_contents($local_keywords, json_encode($keywords));


            } else {
                $keywords = json_decode(file_get_contents($local_keywords));
            }
        }

        $count = $count - count($global_keywords);
        $keywords = array_slice($keywords, 0, $count);

        return array_merge($global_keywords, $keywords);
    }

    public function getData($page)
    {
        $route = $page->url();

        if (is_null($route)) {
            return null;
        }

        $data = $this->raw_data[$route] ?? null;
        if (is_null($data)) {

            $data_path = SEOData::getFilename(str_replace('/', '_', $route));
            $data = $this->getDataByPath($data_path);
            $this->raw_data[$route] = $data;
        }

        if (is_null($data)) {
            $data = new SEOData();
        }
        return $data;
    }

    public function getDataByPath($data_path)
    {
        $data = null;
        $data_file = CompiledJsonFile::instance($data_path);

        if ($data_file->exists()) {
            $data_contents = $data_file->content();
            $data_file->free();
            $data = new SEOData($data_contents);
        }
        return $data;
    }

    public function cleanBody($data, $keywords = null)
    {
        $body = $this->cleanText(strip_tags($data->get('content.body', '')));

        if ($keywords) {
            $body = $this->highlightText($body, $keywords);
        }

        return $body;
    }

    public function cleanText($text, $cleanup = true)
    {
        // replace HTML encoded chars and special chars
        $search = [
            '&#039;',
            '&lsquo;',
            '&rsquo;',
            '&ldquo;',
            '&rdquo;',
            '&mdash;',
            '&ndash;',
            '&amp;',
        ];
        $replace = ["'","'","'",'"','"',' - ', '-', ' and '];
        $text = str_replace($search, $replace, $text);

        if ($cleanup) {
            $text = preg_replace( '/(\r\n)+|\r+|\n+|\t+/', ' ', $text );
            $text = preg_replace('/\s{2,}/', ' ', $text);
            if ($this->config->get('plugins.seo-magic.keep_utf8_chars', true) !== true) {
                $text = preg_replace("/&#?[a-z0-9]+;/i","", $text);
            }
            $text = str_replace('"', '', $text);
        }

        return html_entity_decode($text);
    }

    public function pluginVar($page, $var, $default = null)
    {
        // Try to find var in the page headers
        if ($page instanceof PageInterface && $page->exists()) {
            // Loop over pages and look for header vars
            while ($page && !$page->root()) {
                $header = new Data((array)$page->header());
                $value = $header->get($var);
                if (isset($value)) {
                    return $value;
                }
                $page = $page->parent();
            }
        }

        return Grav::instance()['config']->get('plugins.' . $var, $default);
    }

    public function checkSitemap()
    {
        list($client, $response) = SEOGenerator::getSiteMap(null, 'HEAD');
        return $response ?? null;
    }
    
    public function getRobotsFile()
    {
        $robots_file = GRAV_ROOT . '/robots.txt';
        if (file_exists($robots_file)) {
            return file_get_contents($robots_file);
        }
        return null;
    }
    
    public function highlightText($text, $words) {
        $word_list = implode("|", array_map('preg_quote', $words));
        $re = '~\\b(' . $word_list . ')\\b~';
        return preg_replace($re, '<mark>$0</mark>', $text);
    }

    public function getLanguage()
    {
        /** @var Language $language */
        $language = Grav::instance()['language'];
        $lang = $language->getLanguage();
        return $lang ?: 'en';
    }

    protected function getStopWords($lang, $words)
    {
        switch(strtolower($lang)) {
            case 'fr':
            case 'fr-be':
            case 'fr-ca':
            case 'fr-ch':
            case 'fr-fr':
            case 'fr-lu':
            case 'fr-mc':
                return new French($words);
            case 'de':
            case 'de-at':
            case 'de-ch':
            case 'de-de':
            case 'de-li':
            case 'de-lu':
                return new German($words);
            case 'id':
            case 'id-id':
                return new Indonesian($words);
            case 'it':
            case 'it-ch':
            case 'it-it':
                return new Italian($words);
            case 'nb':
            case 'nb-no':
            case 'nn-no':
                return new Norwegian($words);
            case 'ru':
            case 'ru-ru':
                return new Russian($words);
            case 'es':
            case 'es-ar':
            case 'es-bo':
            case 'es-cl':
            case 'es-co':
            case 'es-cr':
            case 'es-do':
            case 'es-ec':
            case 'es-es':
            case 'es-gt':
            case 'es-hn':
            case 'es-mx':
            case 'es-ni':
            case 'es-pa':
            case 'es-pe':
            case 'es-pr':
            case 'es-py':
            case 'es-sv':
            case 'es-uy':
            case 'es-ve':
                return new Spanish($words);
            default:
                return new English($words);
        }
    }
    
}
