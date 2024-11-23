<?php
namespace Grav\Plugin;

use Grav\Common\Data\Blueprints;
use Grav\Common\File\CompiledJsonFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\HTTP\Client;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Plugin\Admin\Admin;
use Grav\Plugin\Problems\Base\ProblemChecker;
use Grav\Plugin\SEOMagic\SEOData;
use Grav\Plugin\SEOMagic\SEOGenerator;
use Grav\Plugin\SEOMagic\SEOMagic;
use Grav\Plugin\SEOMagic\SEOScore;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Class SeoMagicPlugin
 * @package Grav\Plugin
 */
class SeoMagicPlugin extends Plugin
{
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ],
            'onBlueprintCreated' => ['onBlueprintCreated', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onAdminGenerateReports' => ['onAdminGenerateReports', 10],
        ];
    }

    /**
     * [onPluginsInitialized:100000] Composer autoload.
     *
     * @return ClassLoader
     */
    public function autoload()
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        $this->grav['seomagic'] = $seo = new SEOMagic();

        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin() && is_null($uri->extension())) {
            /** @var Admin $admin */
            $admin = $this->grav['admin'];

            if ($this->config->get('plugins.seo-magic.enable_quicktray')) {
                $this->enable([
                    'onAdminMenu' => ['onAdminMenu', 0],
                ]);
            }

            if ($admin->location === 'pages') {
                $this->enable([
                    'onTwigSiteVariables' => ['onTwigAdminVariables', 0]
                ]);
            }

            if ($this->config->get('plugins.seo-magic.enable_admin_page_events', true)) {
                $this->enable([
                    'onAdminAfterSave'   => ['onObjectSave', 0],
                    'onAdminAfterDelete' => ['onObjectDelete', 0],
                ]);
            }

            $this->grav['assets']->addJs('plugin://seo-magic/assets/admin/seo-magic.js', ['group' => 'bottom', 'loading' => 'defer', 'priority' => -5]);
            return;
        }

        // Enable the main event we are interested in
        $this->enable([
            'onTwigSiteVariables' => ['onTwigSiteVariables', 100],
            'onPageInitialized'   => ['onPageInitialized', 0],
            'onAdminTaskExecute'  => ['onAdminTaskExecute', 0],
            'onPageFallBackUrl'   => ['onPageFallBackUrl', 0],
        ]);
    }

    /**
     * Add reindex button to the admin QuickTray
     */
    public function onAdminMenu(): void
    {
        $options = [
            'authorize' => 'taskProcessSEOMagic',
            'hint' => Grav::instance()['language']->translate('PLUGIN_SEOMAGIC.SEOMAGIC_HINT'),
            'class' => 'seomagic-reindex',
            'icon' => 'fa-magic'
        ];

        $this->grav['twig']->plugins_quick_tray['SEO-Magic Process'] = $options;
    }

    public function onAdminTaskExecute(Event $e): void
    {
        $task = $e['method'] ?? null;
        $status = false;
        $msg = 'Not a valid action';

        if (in_array($task, ['taskProcessSEOMagic', 'taskRemoveDataSEOMagic'])) {

            $controller = $e['controller'];


            header('Content-type: application/json');

            if (!$controller->authorizeTask($task, ['admin.pages', 'admin.super'])) {
                $json_response = [
                    'status' => 'error',
                    'message' => '<i class="fa fa-warning"></i> SEO action not performed',
                    'details' => Grav::instance()['language']->translate('PLUGIN_SEOMAGIC.INSUFFICIENT_PERMS')
                ];
                echo json_encode($json_response);
                exit;
            }

            // disable warnings
            error_reporting(1);
            // disable execution time
            set_time_limit(0);

            switch ($task) {
                case 'taskProcessSEOMagic':
                    list($status, $msg) = SEOGenerator::processSEOData();
                    break;
                case 'taskRemoveDataSEOMagic':
                    list($status, $msg) = SEOGenerator::removeSEOData();
                    break;
            }


            $json_response = [
                'status' => $status ? 'success' : 'error',
                'message' => $msg
            ];

            echo json_encode($json_response);
            exit;
        }

    }

    /**
     * @param Event $e
     * @return void
     */
    public function onAdminGenerateReports(Event $e): void
    {

        if (!$this->config->get('plugins.seo-magic.enable_site_seo_report')) {
            return;
        }

        $reports = $e['reports'];

        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        /** @var Environment $twig */
        $twig = $this->grav['twig']->twig();

        $data = [
            'base_url' => $baseUrlRelative = $uri->rootUrl(false),
            'seomagic_url' => $baseUrlRelative . '/user/plugins/seo-magic',
            'seodata' => $this->getSeoData(),
        ];

        $reports['SEO-Magic Report'] = $twig->render('reports/seo-magic-report.html.twig', $data);

        $this->grav['assets']->addCss('plugins://seo-magic/assets/admin/seo-magic.css');
    }

    public function onBlueprintCreated(Event $event)
    {
        $blueprint = $event['blueprint'];
        if ($blueprint->get('form/fields/tabs', null, '/')) {
            if (!in_array($blueprint->getFilename(), array_keys($this->grav['pages']->modularTypes()))) {
                $blueprints = new Blueprints(__DIR__ . '/blueprints/');
                $extends = $blueprints->get('seo-magic');
                $blueprint->extend($extends, true);
            }
        }
    }

    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigSiteVariables()
    {

    }

    public function onTwigAdminVariables()
    {
        $assets = $this->grav['assets'];
        $assets->addCss('plugin://seo-magic/assets/admin/seo-magic.css');
        $assets->addJs('plugin://seo-magic/assets/admin/prefixfree.min.js', ['group' => 'bottom']);
        $assets->addJs('plugin://seo-magic/assets/admin/conic-gradient.js', ['group' => 'bottom']);
        $assets->addJs('plugin://seo-magic/assets/admin/textcounter.min.js', ['group' => 'bottom']);
        $assets->addJs('plugin://seo-magic/assets/admin/lazyload.js', ['group' => 'bottom']);
        // $assets->addJs('plugin://seo-magic/assets/admin/seo-magic.js', ['group' => 'bottom', 'loading' => 'defer']);
        $page = $this->getPage();

        if ($page->exists()) {
            $twig = $this->grav['twig'];
            /** @var SEOMagic $seo */
            $seo = $this->grav['seomagic'];
            $seo_data = $seo->getData($page);
            $keywords = explode(',', (string) $seo_data->get('head.meta.keywords')) ?? $this->getKeywords();
            $twig->twig_vars['seomagic_page'] = $page;
            $twig->twig_vars['seomagic_rawdata'] = $seo_data;
            $twig->twig_vars['seomagic_metadata'] = $seo->updateMetadata($page);
            $twig->twig_vars['seomagic_body'] = $seo->cleanBody($seo_data, $keywords);
            $twig->twig_vars['seomagic_score'] = new SEOScore($seo_data);
        }
    }

    // Access plugin events in this class
    public function onTwigInitialized()
    {
        $twig = $this->grav['twig'];
        $twig->twig()->addFunction(
            new TwigFunction('seomagic_keywords', [$this, 'getKeywords'])
        );
        $twig->twig()->addFunction(
            new TwigFunction('seomagic_title', [$this, 'getTitle'])
        );
        $twig->twig()->addFunction(
            new TwigFunction('seomagic_description', [$this, 'getDescription'])
        );
        $twig->twig()->addFunction(
            new TwigFunction('grade', [$this, 'displayGrade'])
        );
        $twig->twig()->addFunction(
            new TwigFunction('just_grade', [$this, 'getGradeFromScore'])
        );
        $twig->twig()->addFilter(
            new TwigFilter('flat_keys', [$this, 'flatKeys'])
        );
        $twig->twig()->addFilter(
            new TwigFilter('subs_last', [$this, 'subsLast'])
        );

    }

    public function onPageInitialized(Event $e)
    {
        $page = $e['page'];

        if ($this->isAdmin()) {
            return;
        }

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Set some custom user agent stuff
        if ($user_agent === $this->config->get('plugins.seo-magic.user_agent')) {
            header('Grav-Page-Title: ' . $page->title());
            header('Grav-Page-Route: ' . $page->route());
            header('Grav-Page-RawRoute: ' . $page->rawRoute());
        }

        $headers = $this->getAllHeaders();
        $action = $headers['Magic-Action'] ?? null;

        // For broken links exit as soon as possible
        if ($action === 'broken-links') {
            $code = $this->grav['page']->header()->http_response_code ?? 200;
            http_response_code($code);
            exit;
        }

        $seo = $this->grav['seomagic'];

        /** @var Page $page */
        $page = $e['page'];
        if ($page->templateFormat() === 'html') {
            $metadata = $seo->updateMetadata($page);
            if ($metadata) {
                $page->header()->metadata = $metadata;
            }
        }
    }

    public function onPageFallBackUrl($event): bool
    {
        $uri = $event['uri'];
        $page = $event['page'];
        $filename = $event['filename'];
        $local_path = $this->grav['locator']->findResource('cache://seo-magic/images', true, true);

        if (!file_exists($local_path)) {
            Folder::create($local_path);
        }

        // check if this is an seo-magic image proxy reference
        if ($filename === 'seomagic-image.jpg') {
            /** @var SEOMagic $seo */
            $seo = $this->grav['seomagic'];
            $image_type = $seo->pluginVar($page, 'seo-magic.images.type', 'auto');
            if ($image_type !== 'none') {
                $image = $seo->getPageImage($image_type, $page, false);

                if (Uri::isExternal($image)) {
                    $hash = md5($uri->path());
                    $extension = pathinfo($image, PATHINFO_EXTENSION) ?: 'png';
                    $local_image = "$local_path/$hash.$extension";
                    // if local image, grab it first
                    if (!file_exists($local_image)) {
                        $client = Client::getClient();
                        try {
                            $response = $client->request('GET', $image);
                            if ($response->getStatusCode() === 200) {
                                $image_content = $response->getContent();
                                file_put_contents($local_image, $image_content);
                            }
                        } catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
                            return false;
                        }
                    }
                    Utils::download($local_image,false);
                } else {
                    Utils::download($image, false);
                }
            }
        }
        return false;
    }

    public function onObjectSave($event): bool
    {
        if (defined('CLI_DISABLE_SEOMAGIC')) {
            return true;
        }

        $obj = $event['object'] ?: $event['page'];
        if ($obj instanceof PageInterface && $obj->routable() && $obj->published() && is_null($obj->redirect())) {
            $url = $obj->url(true, true, true);
            $route = $obj->url();
            $client = SEOGenerator::getHttpClient();

            SEOGenerator::processUrlSEOData($url, $client, $route);

        }

        return true;
    }

    public function onObjectDelete($event): bool
    {
        if (defined('CLI_DISABLE_SEOMAGIC')) {
            return true;
        }
        $obj = $event['object'] ?: $event['page'];
        if ($obj instanceof PageInterface && $obj->routable() && $obj->published() && is_null($obj->redirect())) {
            list($status, $message) = SEOGenerator::removeSEOData($obj->url(false, true, true));
            if ($status === 'error') {
                $this->grav['log']->error('SEOMagic: ' . $message);
            }
        }

        return true;
    }

    public function getTitle()
    {
        $page = $this->getPage();
        $title = $page->title();
        if ($page) {
            $seo = $this->grav['seomagic'];
            $title = $seo->getPageTitle($page);
        }
        return $title;
    }

    public function getKeywords($count = 10)
    {
        $page = $this->getPage();
        $keywords = [];
        if ($page) {
            $seo = $this->grav['seomagic'];
            $keywords = (array)$seo->getPageKeywords($page, $count);
        }
        return $keywords;
    }

    public function getDescription()
    {
        $page = $this->getPage();
        $description = '';
        if ($page) {
            $seo = $this->grav['seomagic'];
            $description = $seo->getPageDescription($page);
        }
        return $description;
    }

    public function flatKeys($meta, $prefix = ''): array
    {
        $keys = [];
        if (isset($meta['items'])) {
            foreach ($meta['items'] as $key => $value) {
                if (in_array($key, ['og', 'twitter']) && isset($value['items'])) {
                    $subkeys = $this->flatKeys($value, $key . ':');
                    $keys = array_merge($keys, $subkeys);
                } else {
                    $keys[$prefix . $key] = $value;
                }
            }
        }

        return $keys;
    }

    public function subsLast($meta): array
    {
        $end_meta = [];
        foreach ($meta as $key => $value) {
            if (Utils::contains($key,':')) {
                $end_meta[$key] = $value;
                unset($meta[$key]);
            }
        }

        return $meta + $end_meta;
    }

    public function displayGrade($score, $text = null, $weight = 1)
    {
        $grade = $this->getGradeFromScore($score);
        $grade_lower = strtolower($grade);
        if ($weight == 0) {
            $grade = in_array($grade, ['A','B', 'C']) ? '<i class="fa fa-check"></i>' : '<i class="fa fa-times"></i>';
            $grade_lower = 'ok';
        }
        $html = "<div class=\"seomagic-score__badge score-$grade_lower\"><span class=\"grade\">$grade</span>";
        if ($text) {
            $html .= "<span class=\"text\">" . $this->grav['language']->translate('PLUGIN_SEOMAGIC.' . $text) . "</span>";
        }
        $html .= "</div>";
        return $html;
    }

    public function getGradeFromScore($score)
    {
        if ($score >= 90) {
            return 'A';
        } elseif ($score >= 80) {
            return 'B';
        } elseif ($score >= 70) {
            return 'C';
        } elseif ($score >= 50) {
            return 'D';
        } else {
            return 'F';
        }
    }

    public static function shouldShowReportTab()
    {
        $page = Grav::instance()['admin']->page();
        return ($page->routable() && $page->published() && is_null($page->redirect()));
    }

    protected function getPage()
    {
        if ($this->isAdmin()) {
            /** @var Admin $admin */
            $admin = $this->grav['admin'];
            $page = $admin->page();
            // Fix for admin
            if ($page->home()) {
                $page->route('/');
            }
            return $page;
        } else {
            return $this->grav['page'];
        }
    }

    protected function getAllHeaders()
    {
        if (!function_exists('getallheaders')) {
            $headers = [];
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
            return $headers;
        }
        return getallheaders();
    }

    protected function getSeoData()
    {
        $seo = $this->grav['seomagic'];
        $locator = Grav::instance()['locator'];
        $seodata_dir = $locator->findResource('user-data://seo-magic', true, true);
        $seodata = [];

        if (file_exists($seodata_dir)) {
            foreach (new \DirectoryIterator($seodata_dir) as $file) {
                if ($file->isDot() || $file->isFile()) {
                    continue;
                }

                $data_path = $file->getPathname() . '/data.json';
                if (file_exists($data_path)) {
                    $data = $seo->getDataByPath($data_path);
                    $score = new SEOScore($data);

                    $broken_links = [];
                    $links_data = $data->get('content.links');
                    $total_link_count = 0;
                    foreach ($links_data as $link_route => $link) {
                        $total_link_count++;
                        $status = $link['status'] ?? 200;
                        if ($status > 400) {
                            $broken_links[$link_route] = $link;
                        }
                    }

                    $compressed_data = [
                        'route' => $data->get('grav.page_route'),
                        'rawroute' => $data->get('grav.page_rawroute'),
                        'title' => $data->get('grav.page_title'),
                        'url' => $data->get('info.url'),
                        'score' => $score->getScores(),
                        'broken_links' => $broken_links,
                        'total_links' => $total_link_count,
                    ];

                    $seodata[] = $compressed_data;
                }
            }
        }
        return $seodata;
    }

}
