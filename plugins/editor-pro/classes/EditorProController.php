<?php

declare(strict_types=1);

namespace Grav\Plugin\EditorPro;

use Grav\Common\Helpers\Excerpts;
use Grav\Common\Page\Pages;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

class EditorProController extends AbstractApiController
{
    private const PERMISSION_READ = 'api.pages.read';

    /**
     * GET /editor-pro/config
     *
     * Returns editor configuration: toolbar, extra typography, plugin status.
     */
    public function getConfig(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $config = $this->config->get('plugins.editor-pro', []);

        $data = [
            'toolbar' => $config['toolbar'] ?? 'undo,redo,|,removeformat,|,heading,|,bold,italic,underline,strikethrough,|,link,image,|,blockquote,bulletList,orderedList,|,codeBlock,table,horizontalRule,|,htmlBlock,shortcodeBlock,githubAlert,|,markdown-toggle',
            'extra_typography' => $config['extra_typography'] ?? ['enabled' => true, 'custom' => []],
            'plugin_status' => [
                'shortcode_core' => (bool) $this->config->get('plugins.shortcode-core.enabled', false),
            ],
            'default_for_all' => $config['default_for_all'] ?? false,
        ];

        return ApiResponse::create($data);
    }

    /**
     * GET /editor-pro/shortcodes
     *
     * Fires onEditorProShortcodeRegister event and returns all registered shortcode configs.
     */
    public function getShortcodes(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $shortcodes = [];
        $event = new Event(['shortcodes' => $shortcodes]);
        $this->grav->fireEvent('onEditorProShortcodeRegister', $event);
        $shortcodes = $event['shortcodes'];

        return ApiResponse::create($shortcodes ?: []);
    }

    /**
     * POST /editor-pro/resolve
     *
     * Resolves Grav media/link paths to frontend-displayable URLs.
     *
     * Request body:
     *   { "route": "/blog/my-post", "content": "markdown content..." }
     *
     * Returns:
     *   { "images": { "photo.jpg": { "resolved": "/user/pages/...", "original": "photo.jpg" } }, "links": { ... } }
     */
    public function resolvePaths(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $body = $this->getRequestBody($request);
        $route = $body['route'] ?? null;
        $content = $body['content'] ?? '';

        if (!$route || !$content) {
            return ApiResponse::create(['images' => [], 'links' => []]);
        }

        // Enable pages and find the target page
        $pages = $this->grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }

        $page = $pages->find($route);
        if (!$page) {
            return ApiResponse::create(['images' => [], 'links' => []]);
        }

        $pathMappings = ['images' => [], 'links' => []];

        // Extract markdown images: ![alt](url "optional title")
        preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/', $content, $imageMatches, PREG_SET_ORDER);
        // Extract markdown links (not images): [text](url "optional title")
        preg_match_all('/(?<!\!)\[([^\]]+)\]\(([^)]+)\)/', $content, $linkMatches, PREG_SET_ORDER);

        // Fire event to allow plugins to add custom extraction logic
        $extractEvent = new Event([
            'content' => $content,
            'page' => $page,
            'imageMatches' => $imageMatches,
            'linkMatches' => $linkMatches,
        ]);
        $this->grav->fireEvent('onEditorProExtractPaths', $extractEvent);
        $imageMatches = $extractEvent['imageMatches'];
        $linkMatches = $extractEvent['linkMatches'];

        try {
            // Process images
            foreach ($imageMatches as $match) {
                $altText = $match[1];
                $inner = trim($match[2]);
                $urlOnly = $inner;
                $title = '';
                if (preg_match('/^(.*?)(?:\s+"([^"]*)")\s*$/', $inner, $m2)) {
                    $urlOnly = $m2[1];
                    $title = $m2[2] ?? '';
                }

                $tempHtml = '<img src="' . htmlspecialchars($urlOnly) . '" alt="' . htmlspecialchars($altText) . '" />';
                $processedHtml = Excerpts::processImageHtml($tempHtml, $page);

                if (preg_match('/src="([^"]+)"/', $processedHtml, $srcMatch)) {
                    $resolvedPath = $srcMatch[1];
                    $adminRoute = $this->config->get('plugins.admin.route', '/admin');
                    if (strpos($resolvedPath, $adminRoute) !== false) {
                        $resolvedPath = str_replace($adminRoute . '/pages', '', $resolvedPath);
                    }

                    if ($resolvedPath !== $urlOnly) {
                        $mapping = [
                            'resolved' => $resolvedPath,
                            'original' => $urlOnly,
                            'html' => $processedHtml,
                        ];
                        $pathMappings['images'][$urlOnly] = $mapping;
                        if ($title !== '') {
                            $fullKey = $urlOnly . ' "' . $title . '"';
                            $pathMappings['images'][$fullKey] = $mapping;
                        }
                    }
                }
            }

            // Process links
            foreach ($linkMatches as $match) {
                $linkText = $match[1];
                $inner = trim($match[2]);
                $urlOnly = $inner;
                $title = '';
                if (preg_match('/^(.*?)(?:\s+"([^"]*)")\s*$/', $inner, $m2)) {
                    $urlOnly = $m2[1];
                    $title = $m2[2] ?? '';
                }

                $tempHtml = '<a href="' . htmlspecialchars($urlOnly) . '">' . htmlspecialchars($linkText) . '</a>';
                $processedHtml = Excerpts::processLinkHtml($tempHtml, $page);

                if (preg_match('/href="([^"]+)"/', $processedHtml, $hrefMatch)) {
                    $resolvedPath = $hrefMatch[1];
                    $adminRoute = $this->config->get('plugins.admin.route', '/admin');
                    if (strpos($resolvedPath, $adminRoute) !== false) {
                        $resolvedPath = str_replace($adminRoute . '/pages', '', $resolvedPath);
                    }

                    if ($resolvedPath !== $urlOnly) {
                        $mapping = [
                            'resolved' => $resolvedPath,
                            'original' => $urlOnly,
                            'html' => $processedHtml,
                        ];
                        $pathMappings['links'][$urlOnly] = $mapping;
                        if ($title !== '') {
                            $fullKey = $urlOnly . ' "' . $title . '"';
                            $pathMappings['links'][$fullKey] = $mapping;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently handle path resolution errors
        }

        return ApiResponse::create($pathMappings);
    }

    /**
     * GET /editor-pro/plugins
     *
     * Fires registerEditorProPlugin event, reads registered JS/CSS file paths,
     * and returns them as a concatenated JavaScript response.
     */
    public function getPluginScripts(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $plugins = ['js' => [], 'css' => []];
        $event = new Event(['plugins' => &$plugins]);
        $this->grav->fireEvent('registerEditorProPlugin', $event);

        $jsContent = '';
        $cssContent = '';

        // Read and concatenate JS files
        foreach ($plugins['js'] as $path) {
            $resolvedPath = $this->resolveAssetPath($path);
            if ($resolvedPath && file_exists($resolvedPath)) {
                $jsContent .= "\n/* Editor Pro Plugin: {$path} */\n";
                $jsContent .= file_get_contents($resolvedPath);
                $jsContent .= "\n";
            }
        }

        // Read and concatenate CSS files, embed as JS injection
        foreach ($plugins['css'] as $path) {
            $resolvedPath = $this->resolveAssetPath($path);
            if ($resolvedPath && file_exists($resolvedPath)) {
                $cssContent .= file_get_contents($resolvedPath) . "\n";
            }
        }

        // If we have CSS, wrap it in JS that injects a <style> tag
        if ($cssContent) {
            $escapedCss = json_encode($cssContent);
            $jsContent .= "\n/* Editor Pro Plugin CSS */\n";
            $jsContent .= "(function(){ var s=document.createElement('style'); s.textContent={$escapedCss}; document.head.appendChild(s); })();\n";
        }

        return new \Grav\Framework\Psr7\Response(
            200,
            [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
            $jsContent ?: '/* No editor-pro plugins registered */',
        );
    }

    /**
     * Resolve a Grav asset path (plugin://..., theme://...) to an absolute filesystem path.
     */
    private function resolveAssetPath(string $path): ?string
    {
        // Handle plugin:// and theme:// stream wrappers
        if (preg_match('/^(plugin|theme):\/\/(.+)$/', $path, $m)) {
            $resolved = $this->grav['locator']->findResource("{$m[1]}://{$m[2]}", true);
            return $resolved ?: null;
        }

        // Handle absolute paths
        if (str_starts_with($path, '/') || str_starts_with($path, GRAV_ROOT)) {
            $absPath = str_starts_with($path, GRAV_ROOT) ? $path : GRAV_ROOT . $path;
            return file_exists($absPath) ? $absPath : null;
        }

        // Relative to Grav root
        $absPath = GRAV_ROOT . '/' . ltrim($path, '/');
        return file_exists($absPath) ? $absPath : null;
    }
}
