<?php

namespace Grav\Plugin\SEOMagic;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Utils;

class SEOScore
{
    protected $data;
    protected $scores;
    protected $lang;

    public function __construct($data)
    {
        $this->data = $data;
        $this->scores = new Data();
        $this->lang = Grav::instance()['language'];
    }

    public function getData()
    {
        return $this->data;
    }

    public function getScores()
    {
        if (!$this->scores->get('items')) {
            $this->calculate();
        }
        return $this->scores;
    }

    public function calculate()
    {
        $this->urlScore();
        $this->headScore();
        $this->contentScore();

        $this->compileScores();

        return $this;
    }

    protected function headScore()
    {
        $this->scores->set('items.head.weight', 1);
        $this->titleScore();
        $this->metaScore();
        $this->headIconScore();
        $this->headCanonicalScore();
        $this->headHreflangScore();
        $this->headJsonLdScore();
        $this->headOgImageQualityScore();
        $this->headLinksScore();
        $this->headScriptScore();
    }

    protected function contentScore()
    {
        $this->scores->set('items.content.weight', 1);
        $this->headerScore();
        $this->tagsScore();
        $this->contentLinksScore();
        $this->imagesScore();
    }


    protected function urlScore()
    {
        $url = $this->data->get('info.url');
        $weight = .7;
        $score = 100;
        $msg = $this->lang->translate('PLUGIN_SEOMAGIC.URL_SCORE_MSG_1');


        // Take of 20% for extension
        if (Utils::endsWith($url, ['.html', '.htm', '.php'])) {
            $score -= 20;
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.URL_SCORE_MSG_2');
        }

        // take of 20% for underscores or spaces
        if (Utils::contains($url, ['_'])) {
            $score -= 20;
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.URL_SCORE_MSG_3');
        }
        if (Utils::contains($url, [' ', '%20', '+'])) {
            $score -= 20;
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.URL_SCORE_MSG_4');
        }
        
        // Take off 25% if not HTTPS
        if (Utils::startsWith($url, 'http://')) {
            $score -= 50;
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.URL_SCORE_MSG_5');
        }

        // Take off 20% for very long URLs
        $url_length = strlen((string) Utils::replaceFirstOccurrence($this->data->get('response_headers.Grav-Base'), '', $url));
        if ($url_length > 200) {
            $score -= 20;
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.URL_SCORE_MSG_6'), $url_length);
        }

        $this->scores->set('items.url', ['weight' => $weight, 'score' => max($score,0), 'msg' => $msg]);

        return $this;

    }

    protected function titleScore()
    {
        $this->scores->set('items.head.items.title', $this->lengthScore('head.title', $this->lang->translate('PLUGIN_SEOMAGIC.TITLE'), ['weight'=>1, 'min'=>7, 'max'=>60]));
    }

    protected function metaScore()
    {
        $this->scores->set('items.head.items.meta.weight', 1);
        $this->scores->set('items.head.items.meta.items.og.weight', 0.2);
        $this->scores->set('items.head.items.meta.items.twitter.weight', 0.2);
        
        $this->scores->set('items.head.items.meta.items.title', $this->lengthScore('head.meta.title', $this->lang->translate('PLUGIN_SEOMAGIC.METADATA_TITLE'), ['weight'=>5, 'min'=>7, 'max'=>60]));
        $this->scores->set('items.head.items.meta.items.description', $this->lengthScore('head.meta.description', $this->lang->translate('PLUGIN_SEOMAGIC.METADATA_DESCRIPTION'), ['weight'=>3, 'min'=>50, 'max'=>160, 'max_factor'=>.15, 'max_offset'=>123]));
        // Drop meta keywords from contributing to score
        $kw = $this->itemsScore('head.meta.keywords', $this->lang->translate('PLUGIN_SEOMAGIC.METADATA_KEYWORDS'), ['weight'=>0, 'min'=>5, 'max'=>10]);
        $this->scores->set('items.head.items.meta.items.keywords', $kw);

        if ($this->data->get('head.meta.image')) {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_1');
            $this->scores->set('items.head.items.meta.items.image', ['weight'=>.5, 'score'=>100, 'msg'=>$msg]);
        } else {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_2');
            $this->scores->set('items.head.items.meta.items.image', ['weight'=>0, 'score'=>100, 'msg'=>$msg]);
        }

        if ($this->data->get('head.meta.viewport')) {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_3');
            $this->scores->set('items.head.items.meta.items.viewport', ['weight'=>.5, 'score'=>100, 'msg'=>$msg]);
        } else {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_4');
            $this->scores->set('items.head.items.meta.items.viewport', ['weight'=>.5, 'score'=>0, 'msg'=>$msg]);
        }

        if ($this->data->get('head.meta.robots')) {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_5');
            $this->scores->set('items.head.items.meta.items.robots', ['weight'=>.5, 'score'=>100, 'msg'=>$msg]);
        } else {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_6');
            $this->scores->set('items.head.items.meta.items.robots', ['weight'=>0, 'score'=>100, 'msg'=>$msg]);
        }

        if ($this->data->get('head.meta.charset')) {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_7');
            $this->scores->set('items.head.items.meta.items.charset', ['weight'=>.5, 'score'=>100, 'msg'=>$msg]);
        } else {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_8');
            $this->scores->set('items.head.items.meta.items.charset', ['weight'=>.5, 'score'=>0, 'msg'=>$msg]);
        }

        foreach ($this->data->get('head.meta', []) as $name => $content) {
            if (Utils::startsWith($name,'og:')) {
                $part = trim(Utils::replaceFirstOccurrence('og:', '', $name));
                $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_9'), ucfirst($part));
                $this->scores->set('items.head.items.meta.items.og.items.' . $part, ['weight'=>.2, 'score'=>100, 'msg'=>$msg]);
            }
            if (Utils::startsWith($name,'twitter:')) {
                $part = trim(Utils::replaceFirstOccurrence('twitter:', '', $name));
                $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.META_SCORE_MSG_10'), ucfirst($part));
                $this->scores->set('items.head.items.meta.items.twitter.items.' . $part, ['weight'=>.2, 'score'=>100, 'msg'=>$msg]);
            }
        }
        
        return $this;
    }

    protected function headIconScore()
    {
        $this->scores->set('items.head.items.icon.weight', .2);

        if ($this->data->get('head.icon')) {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.HEAD_ICON_SCORE_MSG_1');
            $this->scores->set('items.head.items.icon',  ['weight'=>1, 'score'=>100, 'msg'=>$msg]);
        } else {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.HEAD_ICON_SCORE_MSG_2');
            $this->scores->set('items.head.items.icon',  ['weight'=>.5, 'score'=>0, 'msg'=>$msg]);
        }
    }

    protected function headCanonicalScore()
    {
        $this->scores->set('items.head.items.canonical.weight', .8);

        $config = \Grav\Common\Grav::instance()['config'];
        $consider = (bool)$config->get('plugins.seo-magic.score.consider_injection', false);
        $ok = (bool)$this->data->get('head.canonical');
        if (!$ok) {
            $ok = (bool)$this->data->get('head.meta.canonical');
        }
        if ($consider) {
            $injectAlways = (bool)$config->get('plugins.seo-magic.inject.canonical_always', true);
            $injectIfMissing = (bool)$config->get('plugins.seo-magic.inject.canonical_if_missing', true);
            $missingFlag = (bool)$this->data->get('head.flags.missing_canonical');
            $ok = $ok || $injectAlways || ($injectIfMissing && $missingFlag);
        }

        if ($ok) {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.HEAD_CANONICAL_SCORE_MSG_1');
            $this->scores->set('items.head.items.canonical',  ['weight'=>1, 'score'=>100, 'msg'=>$msg]);
        } else {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.HEAD_CANONICAL_SCORE_MSG_2');
            $this->scores->set('items.head.items.canonical', ['weight'=>.5, 'score'=>0, 'msg'=>$msg]);
        }
    }

    protected function headHreflangScore()
    {
        $this->scores->set('items.head.items.hreflang.weight', .6);
        $links = $this->data->get('head.links', []);
        $alternates = 0;
        foreach ($links as $href => $attrs) {
            if (($attrs['rel'] ?? null) === 'alternate' && !empty($attrs['hreflang'])) {
                $alternates++;
            }
        }
        $config = \Grav\Common\Grav::instance()['config'];
        $ok = $alternates > 0;
        if ((bool)$config->get('plugins.seo-magic.score.consider_injection', false)) {
            $injectAlways = (bool)$config->get('plugins.seo-magic.inject.hreflang_always', true);
            $injectIfMissing = (bool)$config->get('plugins.seo-magic.inject.hreflang_if_missing', true);
            $missingFlag = (bool)$this->data->get('head.flags.missing_hreflang');
            $ok = $ok || $injectAlways || ($injectIfMissing && $missingFlag);
        }

        if ($ok) {
            $msg = sprintf('Found %d hreflang alternates', $alternates);
            $this->scores->set('items.head.items.hreflang', ['weight'=>1, 'score'=>100, 'msg'=>$msg]);
        } else {
            $msg = 'No hreflang alternates found; add <link rel="alternate" hreflang="…"> for translations';
            $this->scores->set('items.head.items.hreflang', ['weight'=>.5, 'score'=>0, 'msg'=>$msg]);
        }
    }

    protected function headJsonLdScore()
    {
        $this->scores->set('items.head.items.jsonld.weight', .5);
        $scripts = $this->data->get('head.scripts', []);
        $found = false;
        foreach ($scripts as $key => $attrs) {
            if (strtolower((string)($attrs['type'] ?? '')) === 'application/ld+json') { $found = true; break; }
            if (strpos($key, 'inline:ldjson:') === 0) { $found = true; break; }
        }
        $config = \Grav\Common\Grav::instance()['config'];
        $ok = $found;
        if ((bool)$config->get('plugins.seo-magic.score.consider_injection', false)) {
            $autoInject = (bool)$config->get('plugins.seo-magic.structured_data.enabled', true) && (bool)$config->get('plugins.seo-magic.structured_data.auto_inject', true);
            $ok = $ok || $autoInject;
        }
        if ($ok) {
            $this->scores->set('items.head.items.jsonld', ['weight'=>.5, 'score'=>100, 'msg'=>'Found JSON-LD structured data']);
        } else {
            $this->scores->set('items.head.items.jsonld', ['weight'=>.3, 'score'=>0, 'msg'=>'No JSON-LD found; enable Structured Data in SEO-Magic or theme']);
        }
    }

    protected function headOgImageQualityScore()
    {
        $this->scores->set('items.head.items.ogimage.weight', .7);
        $width = (int)($this->data->get('head.meta.og:image:width') ?? 0);
        $height = (int)($this->data->get('head.meta.og:image:height') ?? 0);
        $image = $this->data->get('head.meta.og:image') ?? $this->data->get('head.meta.image');
        if (empty($image)) {
            $this->scores->set('items.head.items.ogimage', ['weight'=>.5, 'score'=>0, 'msg'=>'No OpenGraph image found']);
            return;
        }
        $okSize = ($width >= 1200 && $height >= 630);
        if ($okSize) {
            $this->scores->set('items.head.items.ogimage', ['weight'=>1, 'score'=>100, 'msg'=>sprintf('OG image size looks good (%dx%d)', $width, $height)]);
        } else {
            $msg = $width && $height ? sprintf('OG image is smaller than recommended (got %dx%d, want ≥1200x630)', $width, $height) : 'OG image width/height not provided';
            $this->scores->set('items.head.items.ogimage', ['weight'=>.7, 'score'=>50, 'msg'=>$msg]);
        }
    }

    protected function headLinksScore()
    {
        $links = [];
        $this->scores->set('items.head.items.links.weight', 0);

        foreach ($this->data->get('head.links', []) as $link => $attributes)
        {
            $rel = $attributes['rel'] ?? null;
            if ($rel === 'stylesheet') {
                $msg = $this->lang->translate('PLUGIN_SEOMAGIC.HEAD_LINKS_SCORE_MSG_1');
                $links[$link] = ['weight'=> 0, 'score'=>85, 'msg'=>$msg];
            }
        }

        $this->scores->set('items.head.items.links.items', $links);
    }

    protected function headScriptScore()
    {
        $scripts = [];

        $this->scores->set('items.head.items.scripts.weight', .5);

        foreach ($this->data->get('head.scripts', []) as $script => $attributes)
        {
            $defer = $attributes['defer'] ?? null;
            $async = $attributes['async'] ?? null;
            if ($defer or $async) {
                $type = $defer ? 'defer' : 'async';
                $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.HEAD_SCRIPT_SCORE_MSG_1'), $type);
                $scripts[$script] = ['weight'=>.5, 'score'=>100, 'msg'=>$msg];
            } else {
                $msg = $this->lang->translate('PLUGIN_SEOMAGIC.HEAD_SCRIPT_SCORE_MSG_2');
                $scripts[$script] = ['weight'=> 0, 'score'=>85, 'msg'=>$msg];
            }
        }

        $this->scores->set('items.head.items.scripts.items', $scripts);
    }


    protected function tagsScore()
    {
        $this->scores->set('items.content.items.bad_tags.weight', .1);
        $this->scores->set('items.content.items.good_tags.weight', .1);

        // Bad Tags
        $flash = $this->data->get('content.bad_tags.flash');
        if ($flash > 0) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.TAG_SCORE_MSG_1'), $flash);
            $this->scores->set('items.content.items.bad_tags.items.flash', ['weight'=>.5, 'score'=>0, 'msg'=>$msg]);
        } else {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.TAG_SCORE_MSG_2');
            $this->scores->set('items.content.items.bad_tags.items.flash', ['weight'=>.5, 'score'=>100, 'msg'=>$msg]);
        }

        $iframes = $this->data->get('content.bad_tags.iframes');
        if ($iframes > 0) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.TAG_SCORE_MSG_3'), $iframes);
            $this->scores->set('items.content.items.bad_tags.items.iframes', ['weight'=>.5, 'score'=>0, 'msg'=>$msg]);
        } else {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.TAG_SCORE_MSG_4');
            $this->scores->set('items.content.items.bad_tags.items.iframes', ['weight'=>.5, 'score'=>100, 'msg'=>$msg]);
        }

        // Good Tags
        $semantic_count = 0;
        foreach ($this->data->get('content.good_tags.semantic', []) as $tag => $count) {
            $semantic_count += $count;
        }

        if ($semantic_count > 3) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.TAG_SCORE_MSG_5'), $semantic_count);
            $this->scores->set('items.content.items.good_tags.items.semantic', ['weight'=>.5, 'score'=>100, 'msg'=>$msg]);
        } elseif ($semantic_count > 0) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.TAG_SCORE_MSG_6'), $semantic_count);
            $this->scores->set('items.content.items.bad_tags.items.semantic', ['weight'=>.5, 'score'=>50, 'msg'=>$msg]);
        } else {
            $msg = $this->lang->translate('PLUGIN_SEOMAGIC.TAG_SCORE_MSG_7');
            $this->scores->set('items.content.items.bad_tags.items.semantic', ['weight'=>.2, 'score'=>0, 'msg'=>$msg]);
        }
    }

    protected function contentLinksScore()
    {
        $this->scores->set('items.content.items.links.weight', 1);
        $keywords = array_filter(explode(',', (string) $this->data->get('head.meta.keywords')));
        $links = [];

        $data = $this->data->get('content.links', []);

        foreach ($data as $link => $attributes) {
            $text = $attributes['text'] ?? '';
            $score = 100;
            $status = $attributes['status'] ?? 200;
            $msg = [];

            if ($status < 400) {
                $msg[] = $this->lang->translate('PLUGIN_SEOMAGIC.CONTENT_LINKS_SCORE_MSG_1');
            } else {
                $msg[] = $this->lang->translate('PLUGIN_SEOMAGIC.CONTENT_LINKS_SCORE_MSG_2');
                $score = 10;
            }

            if (Utils::contains($text, $keywords)) {
                $msg[] = $this->lang->translate('PLUGIN_SEOMAGIC.CONTENT_LINKS_SCORE_MSG_3');
            }

            $links[$link] = ['weight'=>1, 'score'=>$score, 'msg'=> implode('. ', $msg)];
        }

        // Don't punish score for no links
        if (!empty($links)) {
            $this->scores->set('items.content.items.links.items', $links);
        } else {
            $this->scores->set('items.content.items.links', ['weight'=>0,'score'=>100]);
        }
    }

    protected function imagesScore()
    {
        $this->scores->set('items.content.items.images.weight', 1);
        $images = [];

        foreach ($this->data->get('content.images', []) as $image) {
            $src = $image['src'] ?? '';
            $alt = $image['alt'] ?? '';
            $status = $image['status'] ?? false;
            $score = 100;
            $msg = [];

            if ($status !== false) {
                if ($status == 200) {
                    $msg[] = $this->lang->translate('PLUGIN_SEOMAGIC.IMAGES_SCORE_MSG_1');
                } else {
                    $msg[] = $this->lang->translate('PLUGIN_SEOMAGIC.IMAGES_SCORE_MSG_2');
                    $score -= 50;
                }
            }

            if (empty($alt)) {
                $msg[] = $this->lang->translate('PLUGIN_SEOMAGIC.IMAGES_SCORE_MSG_3');
                $score -= 50;
            } else {
                $msg[] = $this->lang->translate('PLUGIN_SEOMAGIC.IMAGES_SCORE_MSG_4');
            }

            $images[] = ['src' => $src, 'weight'=>.5, 'score'=>$score, 'msg'=> implode('. ', $msg)];
        }

        // Don't punish score for no images
        if (!empty($images)) {
            $this->scores->set('items.content.items.images.items', $images);
        } else {
            $this->scores->set('items.content.items.images', ['weight'=>0,'score'=>100]);
        }
    }

    protected function headerScore()
    {
        $this->scores->set('items.content.items.headers.weight', .5);
        $this->scores->set('items.content.items.headers.msg', $this->lang->translate('PLUGIN_SEOMAGIC.HEADER_SCORE_MSG_1'));
        $this->scores->set('items.content.items.headers.items.h1', $this->itemsScore('content.headers.h1', 'H1 ' . $this->lang->translate('PLUGIN_SEOMAGIC.HEADERS'), ['weight'=>1, 'min'=>1, 'max'=>5]));
        $this->scores->set('items.content.items.headers.items.h2', $this->itemsScore('content.headers.h2', 'H2 ' . $this->lang->translate('PLUGIN_SEOMAGIC.HEADERS'), ['weight'=>0.5, 'min'=>0, 'max'=>50]));
        $this->scores->set('items.content.items.headers.items.h3', $this->itemsScore('content.headers.h3', 'H3 ' . $this->lang->translate('PLUGIN_SEOMAGIC.HEADERS'), ['weight'=>0.5, 'min'=>0, 'max'=>50]));
        $this->scores->set('items.content.items.headers.items.h4', $this->itemsScore('content.headers.h4', 'H4 ' . $this->lang->translate('PLUGIN_SEOMAGIC.HEADERS'), ['weight'=>0, 'min'=>0, 'max'=>50]));
        $this->scores->set('items.content.items.headers.items.h5', $this->itemsScore('content.headers.h5', 'H5 ' . $this->lang->translate('PLUGIN_SEOMAGIC.HEADERS'), ['weight'=>0, 'min'=>0, 'max'=>50]));
        $this->scores->set('items.content.items.headers.items.h6', $this->itemsScore('content.headers.h6', 'H6 ' . $this->lang->translate('PLUGIN_SEOMAGIC.HEADERS'), ['weight'=>0, 'min'=>0, 'max'=>50]));
    }

    protected function itemsScore($attribute, $name, $options = [])
    {
        $weight = $options['weight'] ?? 10;
        $min = $options['min'] ?? 7;
        $max = $options['max'] ?? 20;
        $max_factor = $options['max_factor'] ?? (100 / (100 - $max));
        $max_offset = $options['max_offset'] ?? ($max_factor * 100);

        $items = $this->data->get($attribute) ?? '';

        if (is_string($items)) {
            $length = count(explode(',', $items));
        } else {
            $length = count($items);
        }

        $score = 100;
        $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.ITEMS_SCORE_MSG_1'), $length, $name, $min, $max);

        if ($min == 0 && $length == 0) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.ITEMS_SCORE_MSG_2'), $name);
            $score = 100;
        } elseif ($length == 0) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.ITEMS_SCORE_MSG_3'), $name, $min, $max);
            $score = 0;
        } elseif ($length < $min) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.ITEMS_SCORE_MSG_4'), $name, $min);
            $score = $length * 100 / $min;
        } elseif ($length > $max) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.ITEMS_SCORE_MSG_5'), $name, $max);
            $score = -($max_factor) * $length + ($max_offset);
        }

        return ['weight' => $weight, 'score' => max(round($score),0), 'msg' => $msg];
    }


    protected function lengthScore($attribute, $name, $options)
    {
        $weight = $options['weight'] ?? 10;
        $min = $options['min'] ?? 7;
        $max = $options['max'] ?? 20;
        $max_factor = $options['max_factor'] ?? (100 / (100 - $max));
        $max_offset = $options['max_offset'] ?? ($max_factor * 100);

        $item = $this->data->get($attribute) ?? '';
        $length = strlen($item);
        $score = 100;
        $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.LENGTH_SCORE_MSG_1'), $name, $length, $min, $max);

        if ($min == 0 && $length == 0) {
            $msg = "$name not found but is optional";
            $score = 100;
        } elseif ($length == 0) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.LENGTH_SCORE_MSG_2'), $name,  $min, $max);
            $score = 0;
        } elseif ($length < $min) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.LENGTH_SCORE_MSG_3'), $name, $length, $min);
            $score = $length * 100 / $min;
        } elseif ($length > $max) {
            $msg = sprintf($this->lang->translate('PLUGIN_SEOMAGIC.LENGTH_SCORE_MSG_4'), $name, $length, $max);
            $score = -($max_factor) * $length + ($max_offset);
        }

        return ['weight' => $weight, 'score' => max(round($score),0), 'msg' => $msg];
    }

    protected function compileScores($key = 'items')
    {
        $score = $weights = 0;
        $scores = $this->scores->get($key);
        foreach ($scores as $item => $data)  {
            if (!isset($data['score']) && isset($data['items'])) {
                $sub_key = !empty($key) ? "$key.$item.items" : "$item.items";
                $sub_scores = $this->compileScores($sub_key);
                $sub_score = $sub_scores[0] ?? 0;
                $sub_weight = $sub_scores[1] ?? 0;
            } else {
                $sub_score = ($data['score'] ?? 0) * ($data['weight'] ?? 0);
                $sub_weight = $data['weight'] ?? 0;
            }
            if ($sub_weight > 0) {
                $score += $sub_score;
                $weights += $sub_weight;
            }
        }

        $total = $weights == 0 ? 0 : round($score / $weights);
        $main_key = Utils::replaceLastOccurrence('items', '', $key);
        $weight = $this->scores->get($main_key . 'weight', 1);
        $this->scores->set($main_key . 'score', $total);
        return  [$total * $weight, $weight];
    }

}
