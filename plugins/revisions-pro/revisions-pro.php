<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Data\Data;
use Grav\Plugin\RevisionsPro\RevisionManager;
use Grav\Plugin\RevisionsPro\TrashManager;
use Grav\Common\Utils;
use Grav\Common\Filesystem\Folder;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;

class RevisionsProPlugin extends Plugin
{
    protected $route = 'revisions';
    protected $revisionManager;
    protected $trashManager;
    protected $trashCaptureRegistry = [];

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100001],
                ['onPluginsInitialized', 0]
            ],
            'onAssetsInitialized' => ['onAssetsInitialized', 0],
            'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0]
        ];
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onPluginsInitialized()
    {
        
        // Include classes
        include_once __DIR__ . '/classes/RevisionManager.php';
        include_once __DIR__ . '/classes/TrashManager.php';
        
        if ($this->isAdmin()) {
            $this->enable([
                'onAdminSave' => ['onAdminSave', 0],
                'onAdminAfterSave' => ['onAdminAfterSave', 0],
                'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0],
                'onAdminPageTypes' => ['onAdminPageTypes', 0],
                'onPageProcessed' => ['onPageProcessed', 0],
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigInitialized' => ['onTwigInitialized', 0],
                'onPagesInitialized' => ['onPagesInitialized', 0],
            ]);
            
            // Register AJAX route for revisions
            $uri = $this->grav['uri'];
            if (strpos($uri->path(), $this->config->get('plugins.admin.route') . '/revisions-api') !== false) {
                $this->enable([
                    'onPagesInitialized' => ['handleAjaxRequest', 100000]
                ]);
            }

            // Initialize revision manager
            $this->revisionManager = new RevisionManager($this->grav, $this->grav['config']);
            $this->trashManager = new TrashManager($this->grav, $this->grav['config'], $this->revisionManager);
        }
    }

    public function onAssetsInitialized()
    {
        if ($this->isAdmin()) {
            $this->grav['assets']->addCss('plugin://revisions-pro/assets/css/revisions-pro.css?v=' . time());
            $this->grav['assets']->addJs('plugin://revisions-pro/assets/js/revisions-pro.js?v=' . time());
            
            // Inject admin route and configuration for JavaScript to use
            $adminRoute = $this->config->get('plugins.admin.route', '/admin');
            $trackPages = $this->config->get('plugins.revisions-pro.track_pages', true) ? 'true' : 'false';
            $trackConfig = $this->config->get('plugins.revisions-pro.track_config', true) ? 'true' : 'false';
            $trackPlugins = $this->config->get('plugins.revisions-pro.track_plugins', true) ? 'true' : 'false';
            $showRevisionCount = $this->config->get('plugins.revisions-pro.show_revision_count', true) ? 'true' : 'false';
            $compareMode = $this->config->get('plugins.revisions-pro.compare_mode', 'current');
            $trashEnabled = $this->config->get('plugins.revisions-pro.enable_trash', true) ? 'true' : 'false';
            $trashCount = $this->trashManager ? (int) $this->trashManager->count() : 0;
            
            // Get default language from Grav
            $defaultLang = $this->grav['language']->getDefault() ?: '';
            
            $this->grav['assets']->addInlineJs("
                document.documentElement.setAttribute('data-admin-route', '{$adminRoute}');
                window.RevisionsProConfig = {
                    trackPages: {$trackPages},
                    trackConfig: {$trackConfig},
                    trackPlugins: {$trackPlugins},
                    showRevisionCount: {$showRevisionCount},
                    defaultLanguage: '{$defaultLang}',
                    compareMode: '{$compareMode}',
                    trashEnabled: {$trashEnabled},
                    trashCount: {$trashCount}
                };
            ");
        }
    }

    public function onAdminSave(Event $event)
    {
        // We don't need to do anything here anymore
        // We'll create the revision in onAdminAfterSave
    }

    public function onAdminAfterSave(Event $event)
    {
        $object = $event['object'];

        // Debug logging

        if ($object instanceof PageInterface) {
            // Check if page tracking is enabled
            if (!$this->config->get('plugins.revisions-pro.track_pages', true)) {
                return;
            }
            
            $type = 'page';
            
            try {
                $result = $this->revisionManager->createRevision($object, $type);
            } catch (\Exception $e) {
            }
        } elseif ($object instanceof Data) {
            $type = $this->getDataType($object);
            
            // Check if the specific tracking is enabled
            if ($type === 'plugin-config' && !$this->config->get('plugins.revisions-pro.track_plugins', true)) {
                return;
            } elseif (in_array($type, ['config-system', 'config-site']) && !$this->config->get('plugins.revisions-pro.track_config', true)) {
                return;
            }
            
            // Try to get the file path from the Data object
            $filePath = null;
            if (method_exists($object, 'file')) {
                $file = $object->file();
                if ($file && method_exists($file, 'filename')) {
                    $filePath = $file->filename();
                }
            }
            
            try {
                $result = $this->revisionManager->createRevision($object, $type, $filePath);
            } catch (\Exception $e) {
            }
        }
    }

    public function onPagesInitialized()
    {
        if (!$this->isAdmin() || !$this->trashManager || !$this->trashManager->isEnabled()) {
            return;
        }

        $uri = $this->grav['uri'];
        $task = $_POST['task'] ?? $uri->param('task') ?? null;

        if ($task !== 'delete') {
            return;
        }

        $admin = $this->grav['admin'];
        if (!$admin) {
            return;
        }

        $admin->enablePages();

        $page = $admin->page();
        if (!$page) {
            $route = $admin->route ?? '';
            $page = $this->grav['pages']->find('/' . ltrim($route, '/'));
        }

        if (!$page || !$page->path()) {
            return;
        }

        $pathKey = $page->path();
        if (isset($this->trashCaptureRegistry[$pathKey])) {
            return;
        }

        try {
            $this->trashManager->capturePage($page, ['source' => 'admin-delete']);
            $this->trashCaptureRegistry[$pathKey] = true;
        } catch (\Exception $e) {
            if ($this->grav['log']) {
                $this->grav['log']->error('[Revisions Pro] Failed to capture page for trash: ' . $e->getMessage());
            }
        }
    }

    public function onAdminMenu()
    {
        // Menu item removed - functionality integrated into page editor
    }

    public function onAdminTwigTemplatePaths($event)
    {
        $event['paths'] = array_merge($event['paths'], [__DIR__ . '/admin/templates']);
    }

    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigInitialized()
    {
        $this->grav['twig']->twig()->addGlobal('revisions_pro', $this);
    }
    
    // Public methods for Twig access
    public function getPageRevisions($page)
    {
        return $this->revisionManager->getRevisions($page);
    }
    
    public function getRevision($id)
    {
        return $this->revisionManager->getRevision($id);
    }
    
    public function getDiff($id, $compareWith = 'current')
    {
        return $this->revisionManager->getDiff($id, $compareWith);
    }

    public function onAdminPageTypes(Event $event)
    {
        $types = $event['types'];
        $types['revisions'] = 'revisions';
        $event['types'] = $types;
    }

//    public function onBlueprintCreated(Event $event)
//    {
//        $blueprint = $event['blueprint'];
//        $blueprintId = $blueprint->getFilename();
//
//        // Debug logging
//        $this->grav['debugger']->addMessage('Blueprint ID: ' . $blueprintId);
//
//        // Check if this is a page blueprint
//        if (strpos($blueprintId, 'pages/') !== false ||
//            $blueprintId === 'default' ||
//            $blueprintId === 'item' ||
//            $blueprintId === 'modular') {
//
//            // Get existing tabs fields
//            $tabs = $blueprint->get('form/fields/tabs/fields', []);
//
//            // Debug existing tabs
//            $this->grav['debugger']->addMessage('Existing tabs: ' . json_encode(array_keys($tabs)));
//
//        }
//    }

    public function onPageProcessed(Event $event)
    {
        $page = $event['page'];
        $route = $this->grav['admin']->route;

        // Add revision indicator and UI to page editor
        if ($route && strpos($route, '/pages/') !== false) {
            $revisions = $this->revisionManager->getRevisions($page);
            
            // Always initialize the revision UI
            $this->grav['assets']->addInlineJs("
                document.addEventListener('DOMContentLoaded', function() {
                    RevisionsProPlugin.addRevisionIndicator(" . json_encode([
                        'count' => count($revisions),
                        'latest' => $revisions[0] ?? null
                    ]) . ");
                    RevisionsProPlugin.initPageEditor();
                });
            ");
        }
    }

    public function handleAjaxRequest()
    {
        // Check admin authorization
        if (!$this->grav['admin']->authorize(['admin.pages', 'admin.super'])) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Unauthorized']);
            exit();
        }
        
        $uri = $this->grav['uri'];
        // Check both GET and POST for action parameter
        $action = $uri->query('action') ?: ($_POST['action'] ?? 'list');
        
        
        switch ($action) {
            case 'list':
                $this->ajaxRevisionList();
                break;
            case 'view':
                $this->ajaxRevisionView();
                break;
            case 'diff':
                $this->ajaxRevisionDiff();
                break;
            case 'restore':
                $this->ajaxRevisionRestore();
                break;
            case 'delete':
                $this->ajaxRevisionDelete();
                break;
            case 'trash-list':
                $this->ajaxTrashList();
                break;
            case 'trash-restore':
                $this->ajaxTrashRestore();
                break;
            case 'trash-delete':
                $this->ajaxTrashDelete();
                break;
            case 'trash-empty':
                $this->ajaxTrashEmpty();
                break;
            case 'trash-count':
                $this->ajaxTrashCount();
                break;
            default:
                header('HTTP/1.1 400 Bad Request');
                echo json_encode(['error' => 'Invalid action']);
                exit();
        }
    }

    protected function ajaxRevisionList()
    {
        $uri = $this->grav['uri'];
        $route = $uri->query('route');
        $type = $uri->query('type') ?: 'page';
        
        
        if (!$route) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<p class="error">No route specified</p>';
            exit();
        }
        
        // Enable pages in admin context
        if ($this->grav['admin']) {
            $this->grav['admin']->enablePages();
        }
        
        // For pages, we need to find the actual page object
        if ($type === 'page') {
            // Check if route contains language suffix (e.g., "typography:fr")
            $lang = null;
            $baseRoute = $route;
            
            if (strpos($route, ':') !== false) {
                list($baseRoute, $lang) = explode(':', $route, 2);
                // Set the active language for this request
                if ($lang && $this->grav['language']->enabled()) {
                    $this->grav['language']->setActive($lang);
                }
            }
            
            
            // Try different route formats to find the page
            $page = $this->grav['pages']->find('/' . ltrim($baseRoute, '/'));
            if (!$page) {
                // Try without leading slash
                $page = $this->grav['pages']->find(ltrim($baseRoute, '/'));
                if (!$page) {
                    // Try with home prefix
                    $page = $this->grav['pages']->find('/home/' . ltrim($baseRoute, '/'));
                }
            }
            
            if ($page) {
                $pageDir = $page->path();
                $template = $page->template() ?: 'default';
                
                // Check what files exist in the directory
                $files = scandir($pageDir);
                $mdFiles = array_filter($files, function($file) {
                    return preg_match('/\.md$/', $file);
                });
                
                
                // Get the actual filename from the page
                $pageFile = $page->file();
                if ($pageFile) {
                    $baseFilename = basename($pageFile->filename());
                    // Extract the template name from the filename (e.g., "default" from "default.en.md")
                    if (preg_match('/^(.+?)(?:\.[a-z]{2})?\.md$/', $baseFilename, $matches)) {
                        $template = $matches[1];
                    }
                }
                
                
                // Determine which file to get revisions for based on language
                if ($this->grav['language']->enabled()) {
                    if ($lang) {
                        // Specific language requested
                        $targetFile = $pageDir . '/' . $template . '.' . $lang . '.md';
                    } else {
                        // No specific language - use current active language
                        $currentLang = $this->grav['language']->getActive();
                        $defaultLang = $this->grav['language']->getDefault();
                        
                        if ($currentLang && $currentLang !== $defaultLang) {
                            $targetFile = $pageDir . '/' . $template . '.' . $currentLang . '.md';
                            // If language-specific file doesn't exist, fall back to default
                            if (!file_exists($targetFile)) {
                                $targetFile = $pageDir . '/' . $template . '.md';
                            }
                        } else {
                            // Default language - check if language-specific file exists
                            $langSpecificFile = $pageDir . '/' . $template . '.' . $currentLang . '.md';
                            if (file_exists($langSpecificFile)) {
                                $targetFile = $langSpecificFile;
                            } else {
                                $targetFile = $pageDir . '/' . $template . '.md';
                            }
                        }
                    }
                } else {
                    // Multilanguage not enabled
                    $targetFile = $pageDir . '/' . $template . '.md';
                }
                
                
                // Get revisions for the specific file
                if (file_exists($targetFile)) {
                    $revisions = $this->revisionManager->getPageRevisions($targetFile);
                } else {
                    $revisions = [];
                }
            } else {
                // Page not found
                $revisions = [];
            }
        } else {
            $revisions = $this->revisionManager->getRevisionsForRoute($route, $type);
        }
        
        $html = $this->grav['twig']->processTemplate('revisions-inline.html.twig', [
            'revisions' => $revisions,
            'route' => $route,
            'type' => $type,
            'admin' => $this->grav['admin']
        ]);
        
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit();
    }

    protected function ajaxRevisionView()
    {
        $uri = $this->grav['uri'];
        $id = $uri->query('id');
        $revision = $this->revisionManager->getRevision($id);

        $html = $this->grav['twig']->processTemplate('revision-view.html.twig', [
            'revision' => $revision,
            'admin' => $this->grav['admin']
        ]);
        
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit();
    }

    protected function ajaxRevisionDiff()
    {
        $uri = $this->grav['uri'];
        $id = $uri->query('id');
        $compareWith = $uri->query('compare') ?: 'current';
        
        
        $diff = $this->revisionManager->getDiff($id, $compareWith);
        
        // Add information about what we're comparing with
        $compareRevision = null;
        if ($compareWith !== 'current') {
            $compareRevision = $this->revisionManager->getRevision($compareWith);
            if ($compareRevision) {
                
                // Add the date field that the template expects
                $compareRevision['date'] = date('Y-m-d H:i:s', $compareRevision['timestamp']);
            }
        }
        
        // Debug log the data being sent to the template
        
        $html = $this->grav['twig']->processTemplate('revision-diff.html.twig', [
            'diff' => $diff,
            'admin' => $this->grav['admin'],
            'compareWith' => $compareWith,
            'compareRevision' => $compareRevision
        ]);
        
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit();
    }

    protected function ajaxRevisionRestore()
    {
        // Get POST data
        $id = $_POST['id'] ?? null;
        $routeParam = $_POST['route'] ?? null;
        
        
        try {
            // Get the revision to find the page route
            $revision = $this->revisionManager->getRevision($id);
            if (!$revision) {
                header('HTTP/1.1 404 Not Found');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Revision not found'
                ]);
                exit();
            }
            
            $result = $this->revisionManager->restoreRevision($id);
            
            if ($result) {
                
                // Clear the cache to ensure changes are reflected
                $this->grav['cache']->clearCache('all');
                
                // Set success message in session for display after reload
                $messages = $this->grav['messages'];
                $revisionDate = isset($revision['timestamp']) ? date('Y-m-d H:i:s', $revision['timestamp']) : 'Unknown date';
                $revisionUser = isset($revision['user']) ? $revision['user'] : 'Unknown user';
                $messages->add(sprintf('Revision from %s by %s has been restored successfully', $revisionDate, $revisionUser), 'info');
                
                // Return JSON response
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => 'Revision restored successfully'
                ]);
            } else {
                header('HTTP/1.1 500 Internal Server Error');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to restore revision'
                ]);
            }
        } catch (\Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
        
        exit();
    }

    protected function ajaxRevisionDelete()
    {
        // Get POST data
        $id = $_POST['id'] ?? null;

        try {
            $result = $this->revisionManager->deleteRevision($id);
            
            if ($result) {
                
                // Set success message in session
                $messages = $this->grav['messages'];
                $messages->add('Revision deleted successfully', 'info');
                
                // Return JSON response
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => true,
                    'message' => 'Revision deleted successfully'
                ]);
            } else {
                header('HTTP/1.1 500 Internal Server Error');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to delete revision'
                ]);
            }
        } catch (\Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
        
        exit();
    }

    protected function ajaxTrashList()
    {
        if (!$this->trashManager || !$this->trashManager->isEnabled()) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<p class="error">Trash feature is disabled.</p>';
            exit();
        }

        $items = $this->trashManager->listItems();

        $html = $this->grav['twig']->processTemplate('trash-list.html.twig', [
            'items' => $items,
            'admin' => $this->grav['admin']
        ]);

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit();
    }

    protected function ajaxTrashRestore()
    {
        if (!$this->trashManager || !$this->trashManager->isEnabled()) {
            header('HTTP/1.1 400 Bad Request');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Trash feature is disabled'
            ]);
            exit();
        }

        $id = $_POST['id'] ?? null;
        if (!$id) {
            header('HTTP/1.1 400 Bad Request');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Missing trash item identifier'
            ]);
            exit();
        }

        $options = [
            'mode' => $_POST['mode'] ?? 'original',
            'overwrite' => filter_var($_POST['overwrite'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'custom_route' => $_POST['custom_route'] ?? null,
            'parent_route' => $_POST['parent_route'] ?? null,
            'folder_name' => $_POST['folder_name'] ?? null,
            'slug' => $_POST['slug'] ?? null,
        ];

        try {
            $result = $this->trashManager->restoreItem($id, $options);

            $this->grav['cache']->clearCache('all');
            if (isset($this->grav['pages'])) {
                $this->grav['pages']->reset();
                $this->grav['pages']->init();
            }
            $messages = $this->grav['messages'];
            $message = $this->grav['language']->translate('PLUGIN_REVISIONS_PRO.TRASH_RESTORE_SUCCESS') ?: 'Page restored from trash';
            $messages->add($message, 'info');

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => $message,
                'route' => $result['route'] ?? null,
                'count' => $this->trashManager->count()
            ]);
        } catch (\Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }

        exit();
    }

    protected function ajaxTrashDelete()
    {
        if (!$this->trashManager || !$this->trashManager->isEnabled()) {
            header('HTTP/1.1 400 Bad Request');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Trash feature is disabled'
            ]);
            exit();
        }

        $id = $_POST['id'] ?? null;
        if (!$id) {
            header('HTTP/1.1 400 Bad Request');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Missing trash item identifier'
            ]);
            exit();
        }

        $result = $this->trashManager->deleteItem($id);

        if ($result) {
            $message = $this->grav['language']->translate('PLUGIN_REVISIONS_PRO.TRASH_DELETE_SUCCESS') ?: 'Page removed from trash';
            $this->grav['messages']->add($message, 'info');

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => $message,
                'count' => $this->trashManager->count()
            ]);
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Unable to remove trash item'
            ]);
        }

        exit();
    }

    protected function ajaxTrashEmpty()
    {
        if (!$this->trashManager || !$this->trashManager->isEnabled()) {
            header('HTTP/1.1 400 Bad Request');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Trash feature is disabled'
            ]);
            exit();
        }

        $removed = $this->trashManager->emptyTrash();
        $message = $this->grav['language']->translate('PLUGIN_REVISIONS_PRO.TRASH_EMPTY_SUCCESS') ?: 'Trash emptied';
        $this->grav['messages']->add($message, 'info');

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'removed' => $removed,
            'count' => $this->trashManager->count()
        ]);

        exit();
    }

    protected function ajaxTrashCount()
    {
        if (!$this->trashManager || !$this->trashManager->isEnabled()) {
            header('HTTP/1.1 400 Bad Request');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Trash feature is disabled'
            ]);
            exit();
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'count' => $this->trashManager->count()
        ]);

        exit();
    }


    protected function getDataType($object)
    {
        // Detect config type from admin route
        $route = $this->grav['admin']->route;
        
        // Handle plugin configs
        if (strpos($route, '/plugins/') !== false) {
            return 'plugin-config';
        }
        
        // Handle theme configs
        if (strpos($route, '/themes/') !== false) {
            return 'theme-config';
        }
        
        // Handle all config types dynamically
        if (preg_match('/\/config\/([^\/]+)/', $route, $matches)) {
            return 'config-' . $matches[1];
        }
        
        return 'unknown';
    }
    
    public function onAdminTaskExecute(Event $event)
    {
        $controller = $event['controller'];
        $method = $event['method'];

        // Handle our custom revision tasks
        if ($method === 'taskRevisionsList') {
            $this->handleRevisionsList($controller);
            $event->stopPropagation();
        } elseif ($method === 'taskRevisionsView') {
            $this->handleRevisionsView($controller);
            $event->stopPropagation();
        } elseif ($method === 'taskRevisionsDiff') {
            $this->handleRevisionsDiff($controller);
            $event->stopPropagation();
        }
    }
    
    protected function handleRevisionsList($controller)
    {
        // Check permissions
        if (!$controller->authorizeTask('list revisions', ['admin.pages', 'admin.super'])) {
            $controller->admin->json_response = [
                'status' => 'error',
                'message' => $this->grav['language']->translate('PLUGIN_ADMIN.INSUFFICIENT_PERMISSIONS_FOR_TASK')
            ];
            return;
        }
        
        $uri = $this->grav['uri'];
        $route = $uri->query('route');
        $type = $uri->query('type') ?: 'page';
        
        
        if (!$route) {
            $controller->admin->json_response = [
                'status' => 'error',
                'message' => 'No route specified'
            ];
            return;
        }
        
        // For pages, we need to find the actual page object
        if ($type === 'page') {
            $page = $this->grav['pages']->find('/' . ltrim($route, '/'));
            if ($page) {
                $revisions = $this->revisionManager->getRevisions($page);
            } else {
                $revisions = [];
            }
        } else {
            $revisions = $this->revisionManager->getRevisionsForRoute($route, $type);
        }
        
        // Render the template and return HTML
        $html = $this->grav['twig']->processTemplate('revisions-inline.html.twig', [
            'revisions' => $revisions,
            'route' => $route,
            'type' => $type,
            'admin' => $controller->admin
        ]);
        
        $controller->admin->json_response = [
            'status' => 'success',
            'html' => $html
        ];
    }
    
    protected function handleRevisionsView($controller)
    {
        // Implementation for viewing a specific revision
        $uri = $this->grav['uri'];
        $id = $uri->query('id');
        $revision = $this->revisionManager->getRevision($id);
        
        $html = $this->grav['twig']->processTemplate('revision-view.html.twig', [
            'revision' => $revision,
            'admin' => $controller->admin
        ]);
        
        $controller->admin->json_response = [
            'status' => 'success',
            'html' => $html
        ];
    }
    
    protected function handleRevisionsDiff($controller)
    {
        // Implementation for showing diff
        $uri = $this->grav['uri'];
        $id = $uri->query('id');
        $compareWith = $uri->query('compare') ?: 'current';
        
        $diff = $this->revisionManager->getDiff($id, $compareWith);
        
        $html = $this->grav['twig']->processTemplate('revision-diff.html.twig', [
            'diff' => $diff,
            'admin' => $controller->admin
        ]);
        
        $controller->admin->json_response = [
            'status' => 'success',
            'html' => $html
        ];
    }
    
    public function onSchedulerInitialized(Event $event)
    {
        $scheduler = $event['scheduler'];
        
        // Only add cleanup task if auto_cleanup is enabled
        if ($this->config->get('plugins.revisions-pro.auto_cleanup', true)) {
            $job = $scheduler->addCommand('bin/plugin revisions-pro cleanup --quiet');
            $job->at('0 3 * * *'); // Run at 3 AM daily
        }
    }
}
