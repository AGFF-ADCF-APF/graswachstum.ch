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
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
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
    /** @var string */
    protected $admin_route = 'seo-magic';

    /** @var array<int, array<string, string>> */
    protected array $queued_head_links = [];

    /** @var array<string, bool> */
    protected array $queued_head_link_index = [];

    /** @var bool */
    protected bool $head_injector_enabled = false;
    
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
            // Ensure Admin collects our templates regardless of dynamic enabling
            'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
            'onBlueprintCreated' => ['onBlueprintCreated', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onAdminGenerateReports' => ['onAdminGenerateReports', 10],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
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
            // Admin page route id for this plugin

            // Always hook admin menu and register admin template paths
            $this->enable([
                'onAdminMenu' => ['onAdminMenu', 0],
                // Ensure Admin collects our templates regardless of route timing
                'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
                // Also add directly via generic hook
                'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
            ]);

            if ($admin->location === 'pages') {
                $this->enable([
                    'onTwigSiteVariables' => ['onTwigAdminVariables', 0]
                ]);
            }

            // Log current admin template/route for troubleshooting
            if (isset($this->grav['log']) && method_exists($this->grav['log'], 'notice')) {
                try { $this->grav['log']->notice('SEOMagic admin context', ['location' => $admin->location, 'route' => $admin->route]); } catch (\Throwable $e) {}
            }

            // When on dashboard route, add templates + assets/vars (Cloudflare pattern)
            if ($admin->location === $this->admin_route || $admin->route === $this->admin_route) {
                $this->enable([
                    'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
                    'onPageInitialized'   => ['onPageInitializedAdmin', 0],
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
        // QuickTray action to (re)process crawl
        if ($this->config->get('plugins.seo-magic.enable_quicktray')) {
            $options = [
                'authorize' => 'taskProcessSEOMagic',
                'hint' => Grav::instance()['language']->translate('PLUGIN_SEOMAGIC.SEOMAGIC_HINT'),
                'class' => 'seomagic-reindex',
                'icon' => 'fa-magic'
            ];
            $this->grav['twig']->plugins_quick_tray['SEO-Magic Process'] = $options;
        }

        // Left navigation entry for Dashboard
        if (isset($this->grav['twig']->plugins_hooked_nav)) {
            $this->grav['twig']->plugins_hooked_nav['SEO-Magic'] = [
                'route' => $this->admin_route,
                'location' => $this->admin_route,
                'icon'  => 'fa-chart-line',
                'authorize' => ['admin.pages', 'admin.super']
            ];
        }
    }

    /** Add admin templates path for dashboard rendering (Admin collector) */
    public function onAdminTwigTemplatePaths($event): void
    {
        $paths = $event['paths'];
        $paths[] = __DIR__ . '/admin/templates';
        $event['paths'] = $paths;
    }

    /** Register template path directly into Twig (used when location matches). */
    public function onTwigAdminTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
    }

    /** Prepare data and assets for the Dashboard */
    public function onPageInitializedAdmin(): void
    {
        $assets = $this->grav['assets'];
        // Reuse existing admin styles
        $assets->addCss('plugins://seo-magic/assets/admin/seo-magic.css');
        // Provide a local HTMX fallback (minimal) for partial swaps if HTMX is not present
        $assets->addJs('plugin://seo-magic/assets/admin/htmx-lite.js', ['group' => 'bottom', 'loading' => 'defer']);

        // Provide seodata + derived listing helpers to Twig
        $rows = $this->getSeoData();
        $this->grav['twig']->twig_vars['seodata'] = $rows;
        $this->grav['twig']->twig_vars['seo_summary'] = $this->summarizeSeoData($rows);
        $this->grav['twig']->twig_vars['seo_languages'] = $this->collectLanguages($rows);
        $this->grav['twig']->twig_vars['seo_listing'] = $this->buildSeoListing($rows, []);
        // Attach history/trends + running scan status
        try {
            $history_file = $this->grav['locator']->findResource('user-data://seo-magic/history.json', true, true);
            $history = file_exists($history_file) ? (json_decode(file_get_contents($history_file) ?: '[]', true) ?: []) : [];
        } catch (\Throwable $e) { $history = []; }
        $this->grav['twig']->twig_vars['seohistory'] = $history;
        $this->grav['twig']->twig_vars['seostatus'] = $this->readScanStatus();
    }

    public function onAdminTaskExecute(Event $e): void
    {
        $task = $e['method'] ?? null;
        $status = false;
        $msg = 'Not a valid action';

        if (in_array($task, ['taskProcessSEOMagic', 'taskRemoveDataSEOMagic', 'taskExportSEOMagic', 'taskSEOMagicStatus', 'taskSEOMagicCancel', 'taskSEOMagicPartial', 'taskSEOMagicTable'])) {

            $controller = $e['controller'];


            // Default to JSON unless rendering HTML partial
            if ($task !== 'taskSEOMagicPartial') {
                header('Content-type: application/json');
            }

            if (!$controller->authorizeTask($task, ['admin.pages', 'admin.super'])) {
                $json_response = [
                    'status' => 'error',
                    'message' => '<i class="fa fa-warning"></i> SEO action not performed',
                    'details' => Grav::instance()['language']->translate('PLUGIN_SEOMAGIC.INSUFFICIENT_PERMS')
                ];
                echo json_encode($json_response);
                exit;
            }

            // Surface warnings for logging during long-running scans
            error_reporting(E_ALL);
            // disable execution time
            set_time_limit(0);

            switch ($task) {
                case 'taskProcessSEOMagic':
                    $mode = $_POST['mode'] ?? $_GET['mode'] ?? 'full';
                    if (!in_array($mode, ['full', 'changed'], true)) {
                        $mode = 'full';
                    }
                    $username = null;
                    if (isset($this->grav['user'])) {
                        $user = $this->grav['user'];
                        if (is_object($user) && isset($user->username)) {
                            $username = $user->username;
                        } elseif (is_array($user) && isset($user['username'])) {
                            $username = $user['username'];
                        }
                    }
                    $this->logMessage('info', 'SEO-Magic dashboard scan triggered', ['mode' => $mode, 'user' => $username]);
                    // Start asynchronously so polling can read status while scanning
                    $this->initScanStatus($mode);
                    $spawned = $this->launchBackgroundScan($mode);
                    $this->logMessage('info', 'SEO-Magic background launch result', ['mode' => $mode, 'spawned' => $spawned]);

                    if (!$spawned) {
                        $this->logMessage('warning', 'SEO-Magic background launch unavailable, scheduling shutdown fallback', ['mode' => $mode]);
                        $plugin = $this;
                        register_shutdown_function(function() use ($plugin, $mode) {
                            $plugin->logMessage('info', 'SEO-Magic shutdown fallback starting', ['mode' => $mode]);
                            try {
                                $plugin->executeScanJob($mode, false);
                                $plugin->logMessage('info', 'SEO-Magic shutdown fallback completed', ['mode' => $mode]);
                            } catch (\Throwable $t) {
                                $plugin->logMessage('error', 'SEO-Magic shutdown fallback failed', ['mode' => $mode, 'error' => $t->getMessage()]);
                            }
                        });
                    }

                    $started = [
                        'status' => 'success',
                        'message' => 'started',
                        'mode' => $mode
                    ];
                    echo json_encode($started);
                    // Flush response and let shutdown/background handlers continue
                    try { @session_write_close(); if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } } catch (\Throwable $e) { /* ignore */ }
                    @ob_flush(); @flush();
                    exit; // response already sent
                case 'taskSEOMagicPartial':
                    // Return just the dashboard HTML fragment to swap with HTMX
                    try {
                        /** @var \Grav\Common\Twig\Twig $twigService */
                        $twigService = $this->grav['twig'];
                        // Ensure loader knows our admin template path
                        try { $twigService->addPath(__DIR__ . '/admin/templates'); } catch (\Throwable $e) {}

                        $seodata = $this->getSeoData();
                        $listing = $this->buildSeoListing($seodata, []);
                        $history_file = $this->grav['locator']->findResource('user-data://seo-magic/history.json', true, true);
                        $history = file_exists($history_file) ? (json_decode(file_get_contents($history_file) ?: '[]', true) ?: []) : [];

                        $env = $twigService->twig();
                        $html = $env->render('partials/seo-magic-dashboard.html.twig', [
                            'seodata' => $seodata,
                            'seo_summary' => $this->summarizeSeoData($seodata),
                            'seo_languages' => $this->collectLanguages($seodata),
                            'seo_listing' => $listing,
                            'seohistory' => $history
                        ]);
                        header('Content-Type: text/html; charset=utf-8');
                        echo $html; exit;
                    } catch (\Throwable $t) {
                        header('Content-Type: text/plain');
                        echo 'Error rendering dashboard'; exit;
                    }
                case 'taskSEOMagicTable':
                    try {
                        $input = $_POST + $_GET;
                        $options = [
                            'page' => isset($input['page']) ? (int)$input['page'] : 1,
                            'per_page' => isset($input['per_page']) ? (int)$input['per_page'] : 25,
                            'sort' => isset($input['sort']) ? (string)$input['sort'] : 'updated',
                            'dir' => isset($input['dir']) ? (string)$input['dir'] : 'desc',
                            'mode' => isset($input['mode']) ? (string)$input['mode'] : 'all',
                            'q' => isset($input['q']) ? (string)$input['q'] : '',
                            'lang' => isset($input['lang']) ? (string)$input['lang'] : '',
                        ];

                        $rows = $this->getSeoData();
                        $listing = $this->buildSeoListing($rows, $options);

                        /** @var \Grav\Common\Twig\Twig $twigService */
                        $twigService = $this->grav['twig'];
                        try { $twigService->addPath(__DIR__ . '/admin/templates'); } catch (\Throwable $e) {}
                        $env = $twigService->twig();
                        $html = $env->render('partials/seo-magic-table-rows.html.twig', [
                            'rows' => $listing['rows'],
                            'listing' => $listing,
                        ]);

                        $response = [
                            'status' => 'success',
                            'html' => $html,
                            'page' => $listing['page'],
                            'pages' => $listing['pages'],
                            'total' => $listing['total'],
                            'per_page' => $listing['per_page'],
                            'sort' => $listing['sort'],
                            'dir' => $listing['dir'],
                            'mode' => $listing['mode'],
                            'query' => $listing['query'],
                            'lang' => $listing['lang'],
                        ];
                        echo json_encode($response);
                        exit;
                    } catch (\Throwable $t) {
                        echo json_encode(['status' => 'error', 'message' => 'Unable to load SEO rows']);
                        exit;
                    }
                case 'taskRemoveDataSEOMagic':
                    list($status, $msg) = SEOGenerator::removeSEOData();
                    break;
                case 'taskExportSEOMagic':
                    $format = $_POST['format'] ?? $_GET['format'] ?? 'json';
                    $this->exportSeoMagic($format);
                    return; // response already sent
                case 'taskSEOMagicStatus':
                    $json = $this->readScanStatus();
                    echo json_encode(['status' => 'success', 'data' => $json]);
                    exit;
                case 'taskSEOMagicCancel':
                    $this->setCancelFlag(true);
                    echo json_encode(['status' => 'success', 'message' => 'Cancellation requested']);
                    exit;
            }


            $json_response = [
                'status' => $status ? 'success' : 'error',
                'message' => $msg
            ];

            echo json_encode($json_response);
            exit;
        }

    }

    protected function locatorPath(string $res): string
    {
        return $this->grav['locator']->findResource($res, true, true);
    }

    protected function getScanStatusPath(): string
    {
        return $this->locatorPath('user-data://seo-magic/status.json');
    }

    protected function getCancelPath(): string
    {
        return $this->locatorPath('user-data://seo-magic/cancel.flag');
    }

    protected function setCancelFlag(bool $on): void
    {
        $file = $this->getCancelPath();
        if ($on) {
            @file_put_contents($file, '1');
            $this->logMessage('info', 'SEO-Magic cancel flag set');
        } else {
            if (file_exists($file)) { @unlink($file); }
        }
    }

    protected function isCancelled(): bool
    {
        return file_exists($this->getCancelPath());
    }

    protected function initScanStatus(string $mode = 'full', int $total = 0): void
    {
        $status = [
            'running' => true,
            'mode' => $mode,
            'total' => $total,
            'processed' => 0,
            'started_at' => time(),
            'updated_at' => time(),
            'last' => null,
        ];
        $written = @file_put_contents($this->getScanStatusPath(), json_encode($status));
        if ($written === false) {
            $this->logMessage('error', 'SEO-Magic failed to write scan status during init', ['mode' => $mode]);
        }
        $this->setCancelFlag(false);
    }

    protected function updateScanStatus(callable $mutator): void
    {
        $path = $this->getScanStatusPath();
        $data = $this->readScanStatus();
        $mutator($data);
        $data['updated_at'] = time();
        $written = @file_put_contents($path, json_encode($data));
        if ($written === false) {
            $this->logMessage('error', 'SEO-Magic failed to persist scan status update', ['path' => $path]);
        }
    }

    protected function finalizeScanStatus(string $state = 'done'): void
    {
        $this->updateScanStatus(function (&$s) use ($state) {
            $s['running'] = false;
            $s['state'] = $state;
            $s['finished_at'] = time();
        });
        $this->setCancelFlag(false);
    }

    protected function logMessage(string $level, string $message, array $context = []): void
    {
        if (!isset($this->grav['log'])) {
            return;
        }
        $levelLower = strtolower($level);
        $detailed = (bool)$this->config->get('plugins.seo-magic.detailed_logging', false);
        if (!$detailed && !in_array($levelLower, ['error', 'warning'], true)) {
            return;
        }
        $logger = $this->grav['log'];
        try {
            if (method_exists($logger, $level)) {
                $logger->{$level}($message, $context);
            } elseif (method_exists($logger, 'log')) {
                $logger->log($level, $message, $context);
            }
        } catch (\Throwable $e) {
            // ignore logging failures
        }
    }

    public function executeScanJob(string $mode = 'full', bool $initStatus = false, bool $updateHistory = true, ?callable $progress = null, array $options = []): array
    {
        if (!in_array($mode, ['full', 'changed'], true)) {
            $mode = 'full';
        }

        if ($initStatus) {
            $this->initScanStatus($mode);
        }

        try {
            $this->logMessage('info', 'SEO-Magic scan starting', ['mode' => $mode]);
            $result = $this->runScan($mode, $progress, $options);
            $this->finalizeScanStatus();
            if ($updateHistory) {
                $this->snapshotHistory();
            }
            $this->logMessage('info', 'SEO-Magic scan completed', ['mode' => $mode, 'status' => $result['status'] ?? null, 'message' => $result['message'] ?? null]);
            return $result;
        } catch (\Throwable $t) {
            $this->logMessage('error', 'SEO-Magic scan failed', ['mode' => $mode, 'error' => $t->getMessage()]);
            try { $this->finalizeScanStatus('error'); } catch (\Throwable $e) {}
            throw $t;
        }
    }

    protected function launchBackgroundScan(string $mode): bool
    {
        if (!class_exists('Symfony\\Component\\Process\\PhpExecutableFinder') || !class_exists('Symfony\\Component\\Process\\Process')) {
            $this->logMessage('warning', 'SEO-Magic background scan skipped: Symfony Process component not available');
            return false;
        }
        try {
            $locator = $this->grav['locator'];
            $workingDir = defined('GRAV_ROOT') ? GRAV_ROOT : getcwd();
            $bin = $workingDir ? $workingDir . '/bin/plugin' : null;
            if ((!$bin || !file_exists($bin)) && method_exists($locator, 'findResource')) {
                try {
                    $bin = $locator->findResource('root://bin/plugin', false, true) ?: $bin;
                } catch (\Throwable $ignored) {}
            }
            if (!$bin || !file_exists($bin)) {
                $this->logMessage('warning', 'SEO-Magic background scan skipped: plugin binary not found', ['bin' => $bin]);
                return false;
            }

            $finder = new PhpExecutableFinder();
            $phpBinary = $finder->find(false) ?: (PHP_BINARY ?: null);
            if (!$phpBinary) {
                $this->logMessage('warning', 'SEO-Magic background scan skipped: PHP executable not found');
                return false;
            }

            $workingDir = $workingDir ?: dirname($bin, 2) ?: null;
            $uriService = $this->grav['uri'] ?? null;
            $environment = [];
            $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            if (!$host && $uriService && method_exists($uriService, 'host')) {
                try { $host = (string)($uriService->host() ?? ''); } catch (\Throwable $ignored) {}
            }
            if ($host) {
                $environment['GRAV_HTTP_HOST'] = $host;
            }
            $scheme = '';
            if ($uriService && method_exists($uriService, 'scheme')) {
                try { $scheme = (string)($uriService->scheme() ?? ''); } catch (\Throwable $ignored) {}
            }
            if (!$scheme) {
                if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                    $scheme = 'https';
                } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
                    $scheme = (string)$_SERVER['REQUEST_SCHEME'];
                } elseif (!empty($_SERVER['SERVER_PORT'])) {
                    $scheme = (int)$_SERVER['SERVER_PORT'] === 443 ? 'https' : 'http';
                }
            }
            if ($scheme) {
                $scheme = strtolower(rtrim($scheme, ':/'));
            }
            if ($scheme) {
                $environment['GRAV_HTTP_SCHEME'] = $scheme;
            }
            $port = $_SERVER['SERVER_PORT'] ?? null;
            if (!$port && $uriService && method_exists($uriService, 'port')) {
                try { $port = $uriService->port(true); } catch (\Throwable $ignored) {}
            }
            if ($port) {
                $environment['GRAV_HTTP_PORT'] = (string)$port;
            }
            $baseUri = '';
            if ($uriService && method_exists($uriService, 'rootUrl')) {
                try { $baseUri = (string)$uriService->rootUrl(false); } catch (\Throwable $ignored) {}
            }
            if (!$baseUri) {
                $baseUri = $this->grav['base_url_relative'] ?? '';
            }
            if ($baseUri) {
                $environment['GRAV_BASE_URI'] = $baseUri;
            }
            $customBase = '';
            if ($host) {
                $customBase = ($scheme ?: 'http') . '://' . $host;
                if (!empty($port) && !in_array((int)$port, [80, 443], true)) {
                    $customBase .= ':' . $port;
                }
                if ($baseUri) {
                    $customBase .= rtrim($baseUri, '/');
                }
            }
            if ($customBase) {
                $environment['GRAV_CONFIG'] = '1';
                $environment['GRAV_CONFIG__system__custom_base_url'] = $customBase;
                $environment['GRAV_CONFIG__system__absolute_urls'] = '1';
            }

            $logFile = null;
            try {
                $logFile = $locator->findResource('log://seo-magic-dashboard.log', true, true);
            } catch (\Throwable $ignored) {}

            $args = [
                $phpBinary,
                $bin,
                'seo-magic',
                'dashboard-scan',
                '--mode=' . $mode,
            ];
            $quoted = array_map('escapeshellarg', $args);
            $baseCommand = implode(' ', $quoted);

            if (DIRECTORY_SEPARATOR === '\\') {
                $cmdline = $baseCommand;
                if ($logFile) {
                    $cmdline .= ' >> ' . escapeshellarg($logFile) . ' 2>&1';
                }
                $command = 'start /B "" ' . $cmdline;
                $this->logMessage('info', 'SEO-Magic background scan launching (Windows)', ['mode' => $mode, 'command' => $command]);
                try {
                    pclose(popen($command, 'r'));
                    return true;
                } catch (\Throwable $t) {
                    $this->logMessage('error', 'SEO-Magic background launch failed on Windows', ['mode' => $mode, 'error' => $t->getMessage()]);
                    return false;
                }
            }

            $exportPrefix = '';
            if ($environment) {
                $segments = [];
                foreach ($environment as $key => $value) {
                    $segments[] = sprintf('export %s=%s', $key, escapeshellarg($value));
                }
                if ($segments) {
                    $exportPrefix = implode('; ', $segments) . '; ';
                }
            }

            $logRedirect = $logFile ? (' >> ' . escapeshellarg($logFile) . ' 2>&1') : '';
            $shellCommand = sprintf('%scd %s && %s%s & echo $!', $exportPrefix, escapeshellarg($workingDir ?: getcwd()), $baseCommand, $logRedirect);
            $this->logMessage('info', 'SEO-Magic background scan launching', ['mode' => $mode, 'command' => $shellCommand, 'cwd' => $workingDir]);

            $launcher = Process::fromShellCommandline($shellCommand);
            $launcher->setTimeout(5);
            $launcher->run();

            $pid = null;
            $output = trim($launcher->getOutput() . PHP_EOL . $launcher->getErrorOutput());
            if (preg_match('/^(\d+)/', $output, $match)) {
                $pid = (int)$match[1];
            }

            if ($launcher->isSuccessful()) {
                $this->logMessage('info', 'SEO-Magic background scan dispatched', ['mode' => $mode, 'pid' => $pid, 'command' => $shellCommand]);
                return true;
            }

            $this->logMessage('error', 'SEO-Magic background launch failed', ['mode' => $mode, 'output' => $output ?: null]);
            return false;
        } catch (\Throwable $e) {
            $this->logMessage('error', 'SEO-Magic background launch failed', ['mode' => $mode, 'error' => $e->getMessage()]);
            return false;
        }
    }

    protected function readScanStatus(): array
    {
        $path = $this->getScanStatusPath();
        if (!file_exists($path)) {
            return ['running' => false, 'processed' => 0, 'total' => 0, 'mode' => 'none'];
        }
        $raw = @file_get_contents($path);
        $json = json_decode($raw ?: '{}', true) ?: [];
        return $json + ['running' => false, 'processed' => 0, 'total' => 0, 'mode' => 'none'];
    }

    protected function enumerateEntries(?string $sitemapUrl = null): array
    {
        $entries = [];
        $ignore_routes = (array)$this->grav['config']->get('plugins.seo-magic.ignore_routes', []);
        try {
            $sitemapResult = \Grav\Plugin\SEOMagic\SEOGenerator::getSiteMap($sitemapUrl, 'GET');
            $resp = is_array($sitemapResult) ? ($sitemapResult[1] ?? null) : null;
            if ($resp instanceof \Symfony\Contracts\HttpClient\ResponseInterface) {
                $content_type = $resp->getHeaders()['content-type'][0] ?? '';
                if (\Grav\Common\Utils::contains($content_type, 'application/json')) {
                    $map = $resp->toArray();
                    foreach ($map as $set) {
                        foreach ($set as $route => $entry) {
                            if (!empty($entry['location'])) {
                                if (!in_array($route, $ignore_routes, true)) {
                                    $entries[] = ['route' => $route, 'url' => $entry['location']];
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logMessage('warning', 'SEO-Magic sitemap enumeration failed', ['error' => $e->getMessage()]);
        }

        if (empty($entries)) {
            // fallback to pages enumeration
            try {
                $pages = $this->grav['pages'];
                foreach ($pages->all() as $page) {
                    if (!$page->routable() || !$page->published() || !is_null($page->redirect())) { continue; }
                    $route = $page->route();
                    if (in_array($route, $ignore_routes, true)) { continue; }
                    $entries[] = ['route' => $route, 'url' => $page->url(true, true, true)];
                }
                $this->logMessage('info', 'SEO-Magic fallback enumeration collected pages', ['count' => count($entries)]);
            } catch (\Throwable $e) {}
        }
        return $entries;
    }

    protected function filterChanged(array $entries): array
    {
        $out = [];
        /** @var \Grav\Common\Page\Pages $pages */
        $pages = $this->grav['pages'];
        /** @var SEOMagic $seo */
        $seo = $this->grav['seomagic'];
        foreach ($entries as $e) {
            $route = $e['route'];
            $page = $pages->find($route, true);
            $modified = $page ? (int)$page->modified() : 0;
            $data_path = \Grav\Plugin\SEOMagic\SEOData::getFilename(str_replace('/', '_', $route));
            $data = $seo->getDataByPath($data_path);
            $updated = (int)($data ? ($data->get('updated') ?: 0) : 0);
            if ($updated === 0 || $modified > $updated) {
                $out[] = $e;
            }
        }
        return $out;
    }

    public function runScan(string $mode = 'full', ?callable $progress = null, array $options = []): array
    {
        $config = $this->grav['config'];
        $show_score = (bool)($options['show_score'] ?? false);
        $links_only_mode = (bool)($options['links_only_mode'] ?? false);
        $update_status = !array_key_exists('update_status', $options) || (bool)$options['update_status'];
        $entries = $this->enumerateEntries($options['sitemap_url'] ?? null);
        if ($mode === 'changed') { $entries = $this->filterChanged($entries); }

        $total = count($entries);
        $this->logMessage('info', 'SEO-Magic scan enumerated entries', ['mode' => $mode, 'total' => $total]);
        if ($update_status) {
            $this->updateScanStatus(function (&$s) use ($total) { $s['total'] = $total; });
        }

        if ($total === 0) {
            return ['status' => true, 'message' => 'No pages to process'];
        }

        $client = \Grav\Plugin\SEOMagic\SEOGenerator::getHttpClient();
        $processed = 0;
        $countRef = 0;
        foreach ($entries as $e) {
            if ($this->isCancelled()) {
                return ['status' => false, 'message' => 'Scan cancelled'];
            }
            $route = $e['route'];
            $url = $e['url'];
            $this->logMessage('info', 'SEO-Magic processing route', ['route' => $route, 'url' => $url]);
            \Grav\Plugin\SEOMagic\SEOGenerator::processUrlSEOData($url, $client, $route, function($code,$u,$score) use (&$processed,$route,$progress,$total,$update_status){
                $processed++;
                if ($update_status) {
                    $this->updateScanStatus(function (&$s) use ($processed,$route,$code,$u,$score) {
                        $s['processed'] = $processed;
                        $s['last'] = ['route' => $route, 'url' => $u, 'code' => $code, 'score' => $score];
                    });
                }
                if ($progress) {
                    try { $progress($processed, $total, $route, $u, $code, $score); } catch (\Throwable $t) {}
                }
            }, $countRef, $show_score, $links_only_mode);
        }

        return ['status' => true, 'message' => sprintf('Processed %d pages', $processed)];
    }

    protected function snapshotHistory(): void
    {
        $summary = $this->summarizeSeoData($this->getSeoData());
        $row = ['t' => time()] + $summary;
        $file = $this->locatorPath('user-data://seo-magic/history.json');
        $list = [];
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $list = json_decode($raw ?: '[]', true) ?: [];
        }
        $list[] = $row;
        // keep last 50 entries
        if (count($list) > 50) { $list = array_slice($list, -50); }
        @file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT));
    }

    protected function exportSeoMagic(string $format = 'json'): void
    {
        $data = $this->getSeoData();
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="seo-magic-report.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['route','rawroute','title','url','score','total_links','broken_links_count','first_broken_link','broken_links']);
            foreach ($data as $row) {
                $broken = $row['broken_links'] ?? [];
                $brokenCount = count($broken);
                $firstBroken = '';
                if ($brokenCount > 0) {
                    $firstHref = array_key_first($broken);
                    $firstInfo = $broken[$firstHref] ?? [];
                    $firstBroken = ($firstInfo['status'] ?? '') . ' ' . $firstHref;
                    if (!empty($firstInfo['message'])) { $firstBroken .= ' (' . $firstInfo['message'] . ')'; }
                }
                $brokenList = implode(' | ', array_map(function($href, $info){
                    $s = $info['status'] ?? ''; $m = $info['message'] ?? '';
                    return "$s $href" . ($m ? " ($m)" : '');
                }, array_keys($broken), $broken));
                fputcsv($out, [
                    $row['route'] ?? '',
                    $row['rawroute'] ?? '',
                    $row['title'] ?? '',
                    $row['url'] ?? '',
                    $row['score']->get('score') ?? ($row['score']['score'] ?? ''),
                    $row['total_links'] ?? 0,
                    $brokenCount,
                    $firstBroken,
                    $brokenList
                ]);
            }
            fclose($out);
        } else {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="seo-magic-report.json"');
            echo json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    public function onSchedulerInitialized(Event $e): void
    {
        $config = $this->config->get('plugins.seo-magic.scheduler');
        if (!$config) { return; }
        try {
            $scheduler = $e['scheduler'];
            if (!empty($config['process_cron'])) {
                $job = $scheduler->addCommand('bin/plugin seo-magic process', $config['process_cron']);
                $job->output($this->grav['locator']->findResource('log://seo-magic-process.log', true, true));
            }
            if (!empty($config['link_cron'])) {
                $job = $scheduler->addCommand('bin/plugin seo-magic link-checker', $config['link_cron']);
                $job->output($this->grav['locator']->findResource('log://seo-magic-link-checker.log', true, true));
            }
        } catch (\Throwable $t) {
            // scheduler not available or API mismatch; ignore safely
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

        // Render a lightweight card linking to the new Dashboard
        $data = [
            'base_url' => $baseUrlRelative = $uri->rootUrl(false),
            'dashboard_url' => $baseUrlRelative . '/admin/seo-magic',
            'summary' => $this->summarizeSeoData($this->getSeoData()),
        ];

        $reports['SEO-Magic Report'] = $twig->render('reports/seo-magic-dashboard-link.html.twig', $data);
    }

    /** Build basic summary numbers for dashboard/report link */
    protected function summarizeSeoData(array $rows): array
    {
        $count = count($rows);
        if ($count === 0) {
            return ['pages' => 0, 'avg' => 0, 'broken_pages' => 0, 'broken_links' => 0, 'broken_images' => 0, 'issues_pages' => 0];
        }
        $total = 0; $broken_pages = 0; $broken_links = 0; $broken_images = 0; $issues_pages = 0;
        foreach ($rows as $r) {
            $total += (int)($r['score']['score'] ?? 0);
            $b = (int)count($r['broken_links'] ?? []);
            $bi = (int)count($r['broken_images'] ?? []);
            if ($b > 0) { $broken_links += $b; }
            if ($bi > 0) { $broken_images += $bi; }
            if ($b > 0 || $bi > 0) { $issues_pages++; }
            if ($b > 0) { $broken_pages++; } // keep for backward compat
        }
        return [
            'pages' => $count,
            'avg' => (int)round($total / $count),
            'broken_pages' => $broken_pages,
            'broken_links' => $broken_links,
            'broken_images' => $broken_images,
            'issues_pages' => $issues_pages,
        ];
    }

    /**
     * Collect list of language codes present in SEO dataset.
     */
    protected function collectLanguages(array $rows): array
    {
        $langs = [];
        foreach ($rows as $row) {
            $code = $row['lang'] ?? 'default';
            if ($code === '' || $code === null) {
                $code = 'default';
            }
            if (!in_array($code, $langs, true)) {
                $langs[] = $code;
            }
        }
        sort($langs);
        return $langs;
    }

    /**
     * Build filtered/sorted/paginated listing metadata for admin table.
     */
    protected function buildSeoListing(array $rows, array $options): array
    {
        $defaults = [
            'page' => 1,
            'per_page' => 25,
            'sort' => 'updated',
            'dir' => 'desc',
            'mode' => 'all',
            'q' => '',
            'lang' => '',
        ];
        $opts = array_merge($defaults, $options);
        $opts['page'] = max(1, (int)$opts['page']);
        $opts['per_page'] = max(1, min(200, (int)$opts['per_page']));

        $allowedSorts = ['score', 'lang', 'title', 'links', 'updated'];
        if (!in_array($opts['sort'], $allowedSorts, true)) {
            $opts['sort'] = 'updated';
        }
        $opts['dir'] = strtolower($opts['dir']) === 'asc' ? 'asc' : 'desc';

        $allowedModes = ['all', 'links', 'images', 'issues'];
        if (!in_array($opts['mode'], $allowedModes, true)) {
            $opts['mode'] = 'all';
        }

        $opts['q'] = trim((string)$opts['q']);
        $opts['lang'] = trim((string)$opts['lang']);

        $filtered = array_values(array_filter($rows, function (array $row) use ($opts) {
            $lang = $opts['lang'];
            if ($lang !== '' && (string)($row['lang'] ?? 'default') !== $lang) {
                return false;
            }

            $brokenLinks = (int)count($row['broken_links'] ?? []);
            $brokenImages = (int)count($row['broken_images'] ?? []);
            switch ($opts['mode']) {
                case 'links':
                    if ($brokenLinks <= 0) {
                        return false;
                    }
                    break;
                case 'images':
                    if ($brokenImages <= 0) {
                        return false;
                    }
                    break;
                case 'issues':
                    if ($brokenLinks <= 0 && $brokenImages <= 0) {
                        return false;
                    }
                    break;
            }

            if ($opts['q'] !== '') {
                $needle = strtolower($opts['q']);
                $haystack = strtolower((string)($row['title'] ?? '') . ' ' . (string)($row['url'] ?? ''));
                if (strpos($haystack, $needle) === false) {
                    return false;
                }
            }

            return true;
        }));

        $sortKey = $opts['sort'];
        $multiplier = $opts['dir'] === 'asc' ? 1 : -1;
        usort($filtered, function (array $a, array $b) use ($sortKey, $multiplier) {
            $av = $this->resolveSortValue($a, $sortKey);
            $bv = $this->resolveSortValue($b, $sortKey);
            if (is_string($av) || is_string($bv)) {
                $av = (string)$av;
                $bv = (string)$bv;
                return strcmp($av, $bv) * $multiplier;
            }
            if ($av === $bv) {
                return 0;
            }
            return ($av < $bv ? -1 : 1) * $multiplier;
        });

        $total = count($filtered);
        $pages = $total > 0 ? (int)ceil($total / $opts['per_page']) : 1;
        $page = min($opts['page'], $pages);
        $offset = ($page - 1) * $opts['per_page'];
        $pageRows = array_slice($filtered, $offset, $opts['per_page']);

        return [
            'rows' => array_values($pageRows),
            'total' => $total,
            'pages' => max(1, $pages),
            'page' => max(1, $page),
            'per_page' => $opts['per_page'],
            'sort' => $opts['sort'],
            'dir' => $opts['dir'],
            'mode' => $opts['mode'],
            'query' => $opts['q'],
            'lang' => $opts['lang'],
        ];
    }

    protected function resolveSortValue(array $row, string $key)
    {
        switch ($key) {
            case 'score':
                return (int)($row['score']['score'] ?? 0);
            case 'lang':
                return strtolower((string)($row['lang'] ?? ''));
            case 'title':
                return strtolower((string)($row['title'] ?? ''));
            case 'links':
                return (int)($row['total_links'] ?? 0);
            case 'updated':
            default:
                return (int)($row['updated'] ?? 0);
        }
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
        // Ensure admin dashboard template is resolvable when active
        if ($this->isAdmin()) {
            try {
                /** @var Admin $admin */
                $admin = $this->grav['admin'];
                if ($admin && $admin->location === 'seo-magic') {
                    $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
                }
            } catch (\Throwable $e) {
                // ignore if admin service not ready
            }
        }
    }

    public function onTwigSiteVariables()
    {
        // Frontend: add hreflang/canonical if missing and configured (based on last crawl)
        if ($this->isAdmin()) { return; }
        /** @var PageInterface $page */
        $page = $this->getPage();
        if (!$page || !$page->exists() || !$page->routable() || !$page->published()) { return; }

        $grav = $this->grav;
        $assets = $grav['assets'];
        $language = $grav['language'];
        /** @var SEOMagic $seo */
        $seo = $grav['seomagic'];
        $seoData = $seo->getData($page);

        // Canonical injection
        $canonicalAlways = (bool)$grav['config']->get('plugins.seo-magic.inject.canonical_always', true);
        $canonicalIfMissing = (bool)$grav['config']->get('plugins.seo-magic.inject.canonical_if_missing', true);
        $canonicalUrl = $page->canonical();
        if ($canonicalUrl) {
            if ($canonicalAlways || ($canonicalIfMissing && (bool)$seoData->get('head.flags.missing_canonical'))) {
                $this->queueHeadLink(['rel' => 'canonical', 'href' => $canonicalUrl]);
            }
        }

        // Hreflang injection
        $hreflangAlways = (bool)$grav['config']->get('plugins.seo-magic.inject.hreflang_always', false);
        $hreflangIfMissing = (bool)$grav['config']->get('plugins.seo-magic.inject.hreflang_if_missing', false);
        if ($language->enabled()) {
            if ($hreflangAlways || ($hreflangIfMissing && (bool)$seoData->get('head.flags.missing_hreflang'))) {
                $translated = (array)$page->translatedLanguages(true);
                if (!empty($translated)) {
                    /** @var \Grav\Common\Page\Pages $pages */
                    $pages = $grav['pages'];
                    $currentLang = $language->getLanguage() ?: $language->getDefault();
                    if (!isset($translated[$currentLang])) {
                        $translated[$currentLang] = $page->route();
                    }
                    $isHomePage = $page->home();
                    foreach ($translated as $code => $route) {
                        // Use homeUrl() for home page instead of actual route (e.g. '/home')
                        $href = $isHomePage ? $pages->homeUrl($code, true) : $pages->url($route, $code, true);
                        $this->queueHeadLink(['rel' => 'alternate', 'hreflang' => $code, 'href' => $href]);
                    }
                    $defaultCode = $language->getDefault();
                    $defaultHref = $isHomePage ? $pages->homeUrl($defaultCode, true) : $pages->url($translated[$defaultCode] ?? $page->route(), $defaultCode, true);
                    $this->queueHeadLink(['rel' => 'alternate', 'hreflang' => 'x-default', 'href' => $defaultHref]);
                }
            }
        }

        // Structured Data (JSON-LD)
        if ($grav['config']->get('plugins.seo-magic.structured_data.enabled', true) && $grav['config']->get('plugins.seo-magic.structured_data.auto_inject', true)) {
            $jsonlds = $this->buildStructuredData($page);
            foreach ($jsonlds as $schema) {
                $json = json_encode($schema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
                if ($json) {
                    // Use inline JS with custom type to inject JSON-LD into <head>
                    $assets->addInlineJs($json, ['group' => 'head', 'type' => 'application/ld+json']);
                }
            }
        }
    }

    protected function queueHeadLink(array $attributes): void
    {
        $href = trim((string)($attributes['href'] ?? ''));
        if ($href === '') {
            return;
        }

        if (!$this->shouldInjectHeadLinks()) {
            return;
        }

        if (($attributes['rel'] ?? '') === 'canonical') {
            $page = $this->getPage();
            if ($page) {
                $header = $page->header();
                $meta = $header->metadata ?? null;
                if (is_array($meta) && array_key_exists('canonical', $meta)) {
                    unset($meta['canonical']);
                    $header->metadata = $meta;
                    $page->resetMetadata();
                }
            }
        }

        $normalized = [];
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }
            $normalized[$key] = (string)$value;
        }

        if (empty($normalized['href'])) {
            return;
        }

        ksort($normalized);
        $hash = md5(json_encode($normalized) ?: serialize($normalized));
        if (isset($this->queued_head_link_index[$hash])) {
            return;
        }

        $this->queued_head_link_index[$hash] = true;
        $this->queued_head_links[] = $normalized;

        if (!$this->head_injector_enabled) {
            $this->enable([
                'onOutputGenerated' => ['onOutputGenerated', 0],
            ]);
            $this->head_injector_enabled = true;
        }
    }

    public function onOutputGenerated(): void
    {
        if ($this->isAdmin() || empty($this->queued_head_links)) {
            return;
        }

        if (!$this->shouldInjectHeadLinks()) {
            return;
        }

        $output = (string)($this->grav->output ?? '');
        if ($output === '') {
            return;
        }

        if (!preg_match('/<head\b/i', $output) || stripos($output, '</head>') === false) {
            return;
        }

        $injection = '';
        $hasCanonical = stripos($output, 'rel="canonical"') !== false || stripos($output, "rel='canonical'") !== false;
        foreach ($this->queued_head_links as $link) {
            if (($link['rel'] ?? '') === 'canonical' && $hasCanonical) {
                continue;
            }
            $markup = $this->renderHeadLink($link);
            if ($markup) {
                $injection .= $markup;
                if (($link['rel'] ?? '') === 'canonical') {
                    $hasCanonical = true;
                }
            }
        }

        if ($injection === '') {
            return;
        }

        if (stripos($output, '</head>') !== false) {
            $output = preg_replace('/<\/head>/i', $injection . '</head>', $output, 1) ?? $output;
        } else {
            $output .= $injection;
        }

        $this->grav->output = $output;
    }

    protected function renderHeadLink(array $attributes): string
    {
        if (empty($attributes['href'])) {
            return '';
        }

        $parts = [];
        foreach ($attributes as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            $parts[] = sprintf('%s="%s"', $key, htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'));
        }

        if (!$parts) {
            return '';
        }

        return '<link ' . implode(' ', $parts) . " />\n";
    }

    protected function shouldInjectHeadLinks(): bool
    {
        if ($this->isAdmin()) {
            return false;
        }

        try {
            $uri = $this->grav['uri'] ?? null;
        } catch (\Throwable $e) {
            $uri = null;
        }

        if ($uri instanceof Uri) {
            $extension = strtolower(ltrim((string)$uri->extension(), '.'));
            if ($extension !== '' && !in_array($extension, ['html', 'htm', 'php'], true)) {
                return false;
            }
        }

        $page = $this->getPage();
        if (!$page instanceof PageInterface) {
            return false;
        }

        $format = strtolower((string)$page->templateFormat());
        if ($format !== '' && !in_array($format, ['html', 'htm', 'php'], true)) {
            return false;
        }

        return true;
    }

    protected function buildStructuredData(PageInterface $page): array
    {
        $grav = $this->grav;
        $config = $grav['config'];
        $pages = $grav['pages'];
        $schemas = [];

        $siteName = (string)$config->get('site.title');
        $siteUrl = $pages->homeUrl(null, true);
        $pageUrl = $page->url(true);

        // Organization
        if ($config->get('plugins.seo-magic.structured_data.organization.enabled', true)) {
            $orgName = trim((string)$config->get('plugins.seo-magic.structured_data.organization.name')) ?: $siteName;
            $orgLogoRes = trim((string)$config->get('plugins.seo-magic.structured_data.organization.logo'));
            $logoUrl = $orgLogoRes ? ($grav['locator']->findResource($orgLogoRes, true) ?: $orgLogoRes) : '';
            $sameAs = (array)$config->get('plugins.seo-magic.structured_data.organization.same_as', []);
            $org = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $orgName,
                'url' => $siteUrl,
            ];
            if ($logoUrl) { $org['logo'] = $logoUrl; }
            if (!empty($sameAs)) { $org['sameAs'] = array_values($sameAs); }
            $schemas[] = $org;
        }

        // WebSite + SearchAction
        if ($config->get('plugins.seo-magic.structured_data.website.enabled', true)) {
            $searchUrl = trim((string)$config->get('plugins.seo-magic.structured_data.website.search_url'));
            $site = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => $siteUrl,
            ];
            if ($searchUrl) {
                $target = Utils::startsWith($searchUrl, ['http://','https://']) ? $searchUrl : $pages->url($searchUrl, null, true);
                $site['potentialAction'] = [
                    '@type' => 'SearchAction',
                    'target' => str_replace('{query}', '{search_term_string}', $target),
                    'query-input' => 'required name=search_term_string'
                ];
            }
            $schemas[] = $site;
        }

        // BreadcrumbList
        if ($config->get('plugins.seo-magic.structured_data.breadcrumb.enabled', true)) {
            $crumbs = [];
            $pos = 1;
            // Build ancestors from root -> parent
            $ancestors = [];
            $cursor = $page->parent();
            while ($cursor) {
                array_unshift($ancestors, $cursor);
                $cursor = $cursor->parent();
            }
            foreach ($ancestors as $ancestor) {
                if (!$ancestor->routable() || !$ancestor->published()) { continue; }
                $crumbs[] = [
                    '@type' => 'ListItem',
                    'position' => $pos++,
                    'name' => $ancestor->title(),
                    'item' => $ancestor->url(true)
                ];
            }
            $crumbs[] = [
                '@type' => 'ListItem',
                'position' => $pos,
                'name' => $page->title(),
                'item' => $pageUrl
            ];

            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => $crumbs
            ];
        }

        // Article/BlogPosting
        if ($config->get('plugins.seo-magic.structured_data.article.enabled', true)) {
            $header = (array)$page->header();
            $isArticle = isset($header['date']) || in_array('blog', (array)($header['taxonomy']['category'] ?? []));
            if ($isArticle && !$page->home()) {
                /** @var SEOMagic $seo */
                $seo = $this->grav['seomagic'];
                $metadata = $seo->updateMetadata($page);
                $image = $metadata['image'] ?? $metadata['og:image'] ?? null;
                $article = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Article',
                    'headline' => $metadata['title'] ?? $page->title(),
                    'mainEntityOfPage' => [
                        '@type' => 'WebPage',
                        '@id' => $pageUrl
                    ],
                    'datePublished' => $header['date'] ?? null,
                    'dateModified' => $header['modified'] ?? null,
                    'author' => [
                        '@type' => 'Person',
                        'name' => $header['author']['name'] ?? $config->get('site.author.name') ?? $config->get('site.title')
                    ],
                ];
                if ($image) { $article['image'] = [$image]; }
                $schemas[] = array_filter($article);
            }
        }

        return $schemas;
    }

    public function onTwigAdminVariables()
    {
        $assets = $this->grav['assets'];
        $assets->addCss('plugin://seo-magic/assets/admin/seo-magic.css');
        $assets->addJs('plugin://seo-magic/assets/admin/prefixfree.min.js', ['group' => 'bottom']);
        $assets->addJs('plugin://seo-magic/assets/admin/conic-gradient.js', ['group' => 'bottom']);
        $assets->addJs('plugin://seo-magic/assets/admin/textcounter.min.js', ['group' => 'bottom']);
        $assets->addJs('plugin://seo-magic/assets/admin/lazyload.js', ['group' => 'bottom']);
        $assets->addJs('plugin://seo-magic/assets/admin/seo-magic.js', ['group' => 'bottom', 'loading' => 'defer']);
        $page = $this->getPage();

        if ($page->exists()) {
            $twig = $this->grav['twig'];
            /** @var SEOMagic $seo */
            $seo = $this->grav['seomagic'];
            $seo_data = $seo->getData($page);
            // Fallback bootstrap for brand-new pages with no SEO data yet
            if (!$seo_data->get('updated')) {
                $body = strip_tags((string)$page->content());
                $lang = $seo->getLanguage();
                $seo_data->set('head.title', $page->title());
                $seo_data->set('content.body', $body);
                $seo_data->set('content.headers', ['h1'=>[], 'h2'=>[], 'h3'=>[], 'h4'=>[], 'h5'=>[], 'h6'=>[]]);
                $seo_data->set('head.meta.description', $seo->generateSummary($body, $lang));
                $seo_data->set('head.meta.keywords', implode(',', $seo->generateKeywords($body, 10, $lang)));
            }
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
        // Provide structured data for themes: returns array of schema objects
        $twig->twig()->addFunction(new TwigFunction('seomagic_structured_data', function() {
            $page = $this->getPage();
            if (!$page || !$page->exists()) { return []; }
            return $this->buildStructuredData($page);
        }));
    }

    public function onPageInitialized(Event $e)
    {
        $page = $e['page'];

        if ($this->isAdmin()) {
            return;
        }

        // Handle direct requests to dynamic SEO-Magic image regardless of fallback flow
        $uri = $this->grav['uri'];
        $basename = strtolower((string)$uri->basename());
        if (in_array($basename, ['seomagic-image.jpg','seomagic-image.jpeg','seomagic-image.png'], true)) {
            $images_cache = $this->grav['locator']->findResource('cache://seo-magic/images', true, true);
            if (!file_exists($images_cache)) {
                Folder::create($images_cache);
            }
            // Determine the page by using the directory part of the path
            $route_dir = rtrim((string)dirname($uri->path()), '/');
            /** @var \Grav\Common\Page\Pages $pages */
            $pages = $this->grav['pages'];
            $target = $pages->find($route_dir ?: '/', true);
            if ($target) {
                /** @var SEOMagic $seo */
                $seo = $this->grav['seomagic'];
                $image_type = $seo->pluginVar($target, 'seo-magic.images.type', 'auto');
                if ($image_type !== 'none') {
                    $image = $seo->getPageImage($image_type, $target, false);
                    // External: webshot streamed; non-webshot cached then streamed
                    if (Uri::isExternal($image)) {
                        if ($this->isWebshotImage($image)) {
                            try {
                                $client = Client::getClient();
                                $response = $client->request('GET', $image);
                                if ($response->getStatusCode() === 200) {
                                    $headers = $response->getHeaders(false);
                                    $contentType = $headers['content-type'][0] ?? 'application/octet-stream';
                                    $content = $response->getContent();
                                    $this->sanitizeImageResponse($content, $contentType);
                                    if (ini_get('zlib.output_compression')) { @ini_set('zlib.output_compression', 'Off'); }
                                    header('Content-Type: ' . $contentType);
                                    header('Content-Length: ' . strlen($content));
                                    if ($this->grav['config']->get('system.cache.enabled')) {
                                        header('Cache-Control: public, max-age=86400');
                                        header('Expires: ' . gmdate('D, d M Y H:i:s T', time() + 86400));
                                        header('Pragma: cache');
                                    }
                                    echo $content; exit;
                                }
                            } catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $ex) { /* fall through */ }
                        } else {
                            $hash = md5($uri->path());
                            $extension = pathinfo($image, PATHINFO_EXTENSION) ?: 'png';
                            $local_image = "$images_cache/$hash.$extension";
                            if (!file_exists($local_image)) {
                                $client = Client::getClient();
                                try {
                                    $response = $client->request('GET', $image);
                                    if ($response->getStatusCode() === 200) {
                                        $image_content = $response->getContent();
                                        file_put_contents($local_image, $image_content);
                                    }
                                } catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $ex) { /* ignore */ }
                            }
                            if (file_exists($local_image)) { Utils::download($local_image, false); }
                        }
                    } else {
                        Utils::download($image, false);
                    }
                }
            }
            // If anything failed above, make sure Grav continues (404)
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

        // check if this is an seo-magic image proxy reference (be tolerant of path)
        $requested_basename = basename((string)$filename);
        $requested_basename_lc = strtolower($requested_basename);
        if (in_array($requested_basename_lc, ['seomagic-image.jpg','seomagic-image.jpeg','seomagic-image.png'], true)) {
            // Log once for troubleshooting intercepts
            if (method_exists($this->grav['log'], 'notice')) {
                $this->grav['log']->notice('SEOMagic fallback hit', ['uri' => $uri->path(), 'filename' => $requested_basename]);
            }

            if (!$page instanceof PageInterface || !$page->exists()) {
                return false;
            }

            /** @var SEOMagic $seo */
            $seo = $this->grav['seomagic'];
            $image_type = $seo->pluginVar($page, 'seo-magic.images.type', 'auto');
            if ($image_type !== 'none') {
                $image = $seo->getPageImage($image_type, $page, false);

                if (Uri::isExternal($image)) {
                    // External URL
                    if ($this->isWebshotImage($image)) {
                        // Stream remote webshot image directly
                        try {
                            $client = Client::getClient();
                            $response = $client->request('GET', $image);
                            if ($response->getStatusCode() === 200) {
                                $headers = $response->getHeaders(false);
                                $contentType = $headers['content-type'][0] ?? 'application/octet-stream';
                                $content = $response->getContent();

                                $this->sanitizeImageResponse($content, $contentType);

                                if (ini_get('zlib.output_compression')) {
                                    @ini_set('zlib.output_compression', 'Off');
                                }
                                header('Content-Type: ' . $contentType);
                                header('Content-Length: ' . strlen($content));
                                if ($this->grav['config']->get('system.cache.enabled')) {
                                    header('Cache-Control: public, max-age=86400');
                                    header('Expires: ' . gmdate('D, d M Y H:i:s T', time() + 86400));
                                    header('Pragma: cache');
                                }
                                echo $content;
                                exit;
                            }
                        } catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface $e) {
                            return false;
                        }
                    } else {
                        // Non-webshot external: cache locally once and stream
                        $hash = md5($uri->path());
                        $extension = pathinfo($image, PATHINFO_EXTENSION) ?: 'png';
                        $local_image = "$local_path/$hash.$extension";
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
                    }
                } else {
                    // Local file from page media pipeline
                    Utils::download($image, false);
                }
            }
        }
        return false;
    }

    protected function isWebshotImage($image): bool
    {
        $webshot_url = $this->config->get('plugins.seo-magic.images.webshot_url', 'https://webshot.getgrav.org');
        if (Utils::startsWith($image, $webshot_url)) {
            return true;
        }
        return false;
    }

    /**
     * Remove any leading text (e.g. PHP warnings) from response body and set proper content-type.
     * This makes the endpoint resilient against noisy webshot servers.
     */
    protected function sanitizeImageResponse(&$content, &$contentType): void
    {
        $originalType = strtolower((string)$contentType);
        $isImageHeader = static function($t) {
            return (bool)preg_match('~^image/[^;]+~', strtolower((string)$t));
        };

        $setType = function($type) use (&$contentType, $originalType) {
            if (!$isImageHeader($originalType)) {
                $contentType = $type;
            }
        };

        // Known signatures
        $signatures = [
            'image/png'  => "\x89PNG\x0D\x0A\x1A\x0A",
            'image/jpeg' => "\xFF\xD8\xFF",
            'image/gif'  => "GIF87a",
            'image/gif2' => "GIF89a",
            'image/webp' => 'WEBP', // within RIFF container
        ];

        // If response doesn't claim to be an image, try to locate a signature and strip preface.
        if (!$isImageHeader($originalType)) {
            foreach ($signatures as $type => $sig) {
                $pos = strpos($content, $sig);
                if ($pos !== false) {
                    if ($pos > 0) {
                        $content = substr($content, $pos);
                    }
                    // Map gif2 to gif
                    $mime = ($type === 'image/gif2') ? 'image/gif' : $type;
                    // Special handling for WEBP: ensure RIFF container exists
                    if ($mime === 'image/webp') {
                        $riff = strpos($content, 'RIFF');
                        if ($riff !== false && $riff < 16) {
                            $content = substr($content, $riff);
                        }
                    }
                    $setType($mime);
                    return;
                }
            }
        }
    }

    public function onObjectSave($event): bool
    {
        if (defined('CLI_DISABLE_SEOMAGIC')) {
            return true;
        }

        $obj = $event['object'] ?: $event['page'];
        if (!$obj instanceof PageInterface) {
            return true;
        }
        // Use admin.page() for reliable status since event object may not have correct defaults
        $page = $this->getPage();
        $useAdminPage = ($page instanceof PageInterface && $page->exists());
        $published = $useAdminPage ? $page->published() : $obj->published();
        $routable = $useAdminPage ? $page->routable() : $obj->routable();
        $redirect = $obj->redirect();

        if ($routable && $published && is_null($redirect)) {
            $url = $obj->url(true, true, true);
            // Use rawRoute for consistent storage key across languages and subdirectory installations
            $route = $obj->rawRoute();
            $client = SEOGenerator::getHttpClient();

            SEOGenerator::processUrlSEOData($url, $client, $route);

            // Optionally persist alt text to image meta.yaml files
            if ($this->config->get('plugins.seo-magic.alt_fix.auto_persist')) {
                $this->persistImageAltMeta($obj);
            }

        }

        return true;
    }

    protected function persistImageAltMeta(PageInterface $page): void
    {
        /** @var \Grav\Common\Page\Media $media */
        $media = $page->media();
        $images = $media->images();
        if (empty($images)) { return; }

        $strategy = (string)$this->config->get('plugins.seo-magic.alt_fix.strategy', 'filename');
        $pagePath = rtrim($page->path(), '/');
        $pageTitle = trim((string)$page->title());

        foreach ($images as $filename => $medium) {
            $metaPath = $pagePath . '/' . $filename . '.meta.yaml';
            // If meta exists with alt, skip
            if (file_exists($metaPath)) {
                try {
                    $existing = @file_get_contents($metaPath) ?: '';
                    if (preg_match('/^\s*alt\s*:/mi', $existing)) { continue; }
                } catch (\Throwable $t) {}
            }

            // Generate alt
            $alt = '';
            if ($strategy === 'title' && $pageTitle) {
                $alt = $pageTitle;
            } else {
                $name = pathinfo($filename, PATHINFO_FILENAME);
                $name = preg_replace('/[-_]+/', ' ', $name);
                $name = preg_replace('/\s+\d+$/', '', trim($name));
                if ($name && !preg_match('/^(img|image|photo|picture|screenshot)$/i', $name)) {
                    $alt = ucwords($name);
                } elseif ($pageTitle) {
                    $alt = $pageTitle . ' Image';
                } else {
                    $alt = 'Image';
                }
            }

            // Write meta file
            $yaml = "alt: " . str_replace("\n", ' ', addslashes($alt)) . "\n";
            // Use simple write; Grav will pick it up on next render
            @file_put_contents($metaPath, $yaml);
        }
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
                if ($file->isDot() || !$file->isDir()) {
                    continue;
                }

                $dir = $file->getPathname();
                $row = $seo->readDashboardRow($dir);
                if (is_array($row) && (($row['report_version'] ?? 0) >= \Grav\Plugin\SEOMagic\SEOMagic::DASHBOARD_REPORT_VERSION)) {
                    $seodata[] = $seo->normalizeDashboardRow($row);
                    continue;
                }

                $data_path = $dir . '/data.json';
                if (!file_exists($data_path)) {
                    continue;
                }

                $data = $seo->getDataByPath($data_path);
                if (!$data instanceof \Grav\Plugin\SEOMagic\SEOData) {
                    continue;
                }

                $generated = $seo->buildDashboardRowFromData($data);
                $seo->persistDashboardRow($dir, $generated);
                $seodata[] = $seo->normalizeDashboardRow($generated);
            }
        }
        return $seodata;
    }
}
