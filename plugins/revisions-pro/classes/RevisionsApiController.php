<?php

declare(strict_types=1);

namespace Grav\Plugin\RevisionsPro;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\AdminProxy;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RevisionsApiController extends AbstractApiController
{
    /**
     * GET /revisions-pro/config — UI-relevant plugin configuration for admin-next.
     *
     * Returns the subset of revisions-pro.yaml settings that affect the panel UI.
     */
    public function config(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $config = $this->grav['config'];
        $trashManager = $this->getTrashManager();

        return ApiResponse::create([
            'compare_mode'        => (string) $config->get('plugins.revisions-pro.compare_mode', 'current'),
            'show_revision_count' => (bool) $config->get('plugins.revisions-pro.show_revision_count', true),
            'enable_trash'        => (bool) $config->get('plugins.revisions-pro.enable_trash', true),
            'trash_count'         => $trashManager && $trashManager->isEnabled() ? $trashManager->count() : 0,
        ]);
    }

    /**
     * GET /revisions-pro/badge?route=xxx&lang=xxx&type=page — Revision count for badge display.
     *
     * Type: page (default), config-{scope}, plugin-config, theme-config
     * Returns 0 if show_revision_count is disabled.
     */
    public function badge(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        // Respect the show_revision_count setting — return 0 to hide the badge
        if (!$this->grav['config']->get('plugins.revisions-pro.show_revision_count', true)) {
            return ApiResponse::create(['count' => 0]);
        }

        $route = $this->getQueryParam($request, 'route', '');
        $lang = $this->getQueryParam($request, 'lang', '');
        $type = $this->getQueryParam($request, 'type', 'page');

        $revisions = $this->getRevisionsForContext($route, $lang, $type);

        return ApiResponse::create(['count' => count($revisions)]);
    }

    /**
     * GET /revisions-pro/revisions?route=xxx&lang=xxx&type=page — List revisions.
     *
     * Type: page (default), config-{scope}, plugin-config, theme-config
     */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $route = $this->getQueryParam($request, 'route', '');
        $lang = $this->getQueryParam($request, 'lang', '');
        $type = $this->getQueryParam($request, 'type', 'page');

        $revisions = $this->getRevisionsForContext($route, $lang, $type);

        // Determine filename for display
        $filename = $this->getFilenameForContext($route, $lang, $type);

        // Map revisions to API format
        $items = [];
        foreach ($revisions as $i => $rev) {
            $items[] = [
                'id'        => $rev['id'],
                'timestamp' => $rev['timestamp'],
                'user'      => $rev['user'] ?? 'unknown',
                'action'    => $rev['action'] ?? 'update',
                'title'     => $rev['title'] ?? 'Untitled',
                'date'      => $rev['date'],
                'isCurrent' => $i === 0,
                'filename'  => $filename,
            ];
        }

        return ApiResponse::create($items);
    }

    /**
     * GET /revisions-pro/revisions/{id} — Get a single revision with raw content.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $id = $this->getRouteParam($request, 'id');
        $manager = $this->getRevisionManager();
        $revision = $manager->getRevision($id);

        if (!$revision) {
            throw new NotFoundException('Revision not found.');
        }

        // Get the raw content from the revision
        $content = $this->getRevisionRawContent($revision);

        // Determine filename from the revision file path
        $filename = 'unknown';
        if (isset($revision['file'])) {
            // Extract the base markdown filename from the revision file path
            $basename = basename($revision['file']);
            // e.g. "default.en.md.20260409-115843.rev" → "default.en.md"
            $filename = preg_replace('/\.\d{8}-\d{6}\.rev$/', '', $basename);
        }

        return ApiResponse::create([
            'id'        => $revision['id'],
            'timestamp' => $revision['timestamp'],
            'user'      => $revision['user'] ?? 'unknown',
            'date'      => $revision['date'] ?? date('Y-m-d H:i:s', $revision['timestamp']),
            'content'   => $content,
            'filename'  => $filename,
        ]);
    }

    /**
     * GET /revisions-pro/revisions/{id}/diff?compare=current|{id}|previous|next
     */
    public function diff(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.read');

        $id = $this->getRouteParam($request, 'id');
        $compare = $this->getQueryParam($request, 'compare', 'current');

        $manager = $this->getRevisionManager();

        // Handle "previous" and "next" by finding adjacent revisions
        if ($compare === 'previous' || $compare === 'next') {
            $revision = $manager->getRevision($id);
            if (!$revision) {
                throw new NotFoundException('Revision not found.');
            }

            // Get the page file to find all revisions
            $revisionFile = $revision['file'] ?? '';
            $pageDir = dirname($revisionFile);
            // Extract the original md filename from the rev file
            $basename = basename($revisionFile);
            $mdFile = preg_replace('/\.\d{8}-\d{6}\.rev$/', '', $basename);
            $pagePath = $pageDir . '/' . $mdFile;

            $allRevisions = $manager->getPageRevisions($pagePath);
            $currentIdx = null;
            foreach ($allRevisions as $idx => $rev) {
                if ($rev['id'] === $id) {
                    $currentIdx = $idx;
                    break;
                }
            }

            if ($currentIdx === null) {
                throw new NotFoundException('Revision not found in history.');
            }

            if ($compare === 'previous') {
                // Previous = older = higher index (sorted newest first)
                $targetIdx = $currentIdx + 1;
                if ($targetIdx >= count($allRevisions)) {
                    // No previous revision, compare against empty
                    $compare = null;
                } else {
                    $compare = $allRevisions[$targetIdx]['id'];
                }
            } else {
                // Next = newer = lower index
                $targetIdx = $currentIdx - 1;
                if ($targetIdx < 0) {
                    $compare = 'current';
                } else {
                    $compare = $allRevisions[$targetIdx]['id'];
                }
            }
        }

        // Use the RevisionManager's getDiff
        $diffResult = $manager->getDiff($id, $compare ?? 'current');
        if (!$diffResult) {
            throw new NotFoundException('Could not generate diff.');
        }

        // Build structured line data from the old and new content
        $lines = $this->buildStructuredDiff(
            $diffResult['oldContent'] ?? '',
            $diffResult['newContent'] ?? ''
        );

        // Build compare label
        if ($compare === null) {
            $compareLabel = 'empty (first revision)';
        } elseif ($compare === 'current') {
            $compareLabel = 'current version';
        } else {
            $compareRevision = $manager->getRevision($compare);
            $compareLabel = $compareRevision
                ? ($compareRevision['date'] ?? date('Y-m-d H:i:s', $compareRevision['timestamp']))
                : 'unknown';
        }

        $rev = $diffResult['revision'];

        return ApiResponse::create([
            'revision' => [
                'id'        => $rev['id'],
                'timestamp' => $rev['timestamp'],
                'user'      => $rev['user'] ?? 'unknown',
                'date'      => $rev['date'] ?? date('Y-m-d H:i:s', $rev['timestamp']),
            ],
            'compareWith'  => $compare ?? 'current',
            'compareLabel' => $compareLabel,
            'lines'        => $lines,
        ]);
    }

    /**
     * POST /revisions-pro/revisions/{id}/restore — Restore a revision.
     */
    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.update');

        $id = $this->getRouteParam($request, 'id');
        $manager = $this->getRevisionManager();

        $revision = $manager->getRevision($id);
        if (!$revision) {
            throw new NotFoundException('Revision not found.');
        }

        $result = $manager->restoreRevision($id);
        if (!$result) {
            return ApiResponse::create(['success' => false, 'message' => 'Failed to restore revision.'], 500);
        }

        // Clear cache
        $this->grav['cache']->clearCache('all');

        // Determine page route for invalidation
        $route = $revision['data']['route'] ?? '';

        return $this->respondWithInvalidation(
            ['success' => true, 'message' => 'Revision restored successfully.'],
            ['pages:update:' . $route]
        );
    }

    /**
     * DELETE /revisions-pro/revisions/{id} — Delete a revision.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.update');

        $id = $this->getRouteParam($request, 'id');
        $manager = $this->getRevisionManager();

        $revision = $manager->getRevision($id);
        if (!$revision) {
            throw new NotFoundException('Revision not found.');
        }

        $result = $manager->deleteRevision($id);
        if (!$result) {
            return ApiResponse::create(['success' => false, 'message' => 'Failed to delete revision.'], 500);
        }

        return ApiResponse::create(['success' => true, 'message' => 'Revision deleted successfully.']);
    }

    // ─── Trash endpoints ─────────────────────────────

    /**
     * GET /revisions-pro/trash — List trashed pages.
     */
    public function trashList(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');
        $trashManager = $this->requireTrashManager();

        $items = $trashManager->listItems();

        // Map to API shape — flatten the nested metadata
        $result = [];
        foreach ($items as $item) {
            $meta = $item['metadata'] ?? [];
            $result[] = [
                'id'            => $item['id'],
                'title'         => $meta['title'] ?? 'Untitled',
                'route'         => $meta['original_route'] ?? '',
                'relative_path' => $meta['relative_path'] ?? '',
                'folder'        => $meta['folder'] ?? '',
                'slug'          => $meta['slug'] ?? '',
                'parent_route'  => $meta['parent_route'] ?? '/',
                'language'      => $meta['language'] ?? '',
                'template'      => $meta['template'] ?? '',
                'deleted_at'    => $meta['deleted_at'] ?? null,
                'deleted_by'    => $meta['deleted_by'] ?? 'unknown',
            ];
        }

        return ApiResponse::create($result);
    }

    /**
     * GET /revisions-pro/trash/badge — Trash item count for badge display.
     */
    public function trashBadge(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $trashManager = $this->getTrashManager();
        if (!$trashManager || !$trashManager->isEnabled()) {
            return ApiResponse::create(['count' => 0]);
        }

        return ApiResponse::create(['count' => $trashManager->count()]);
    }

    /**
     * POST /revisions-pro/trash/{id}/restore — Restore a trash item.
     *
     * Body options:
     *   - mode: 'original' | 'custom'
     *   - overwrite: bool
     *   - custom_route, parent_route, folder_name, slug (when mode=custom)
     */
    public function trashRestore(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.update');
        $trashManager = $this->requireTrashManager();

        $id = $this->getRouteParam($request, 'id');
        $body = $this->getRequestBody($request);

        $options = [
            'mode'         => $body['mode'] ?? 'original',
            'overwrite'    => (bool) ($body['overwrite'] ?? false),
            'custom_route' => $body['custom_route'] ?? null,
            'parent_route' => $body['parent_route'] ?? null,
            'folder_name'  => $body['folder_name'] ?? null,
            'slug'         => $body['slug'] ?? null,
        ];

        try {
            // Need pages enabled for the restore operation
            AdminProxy::enablePages();

            $result = $trashManager->restoreItem($id, $options);

            // Clear cache and reset pages so the restored page appears immediately
            $this->grav['cache']->clearCache('all');
            if (isset($this->grav['pages'])) {
                $this->grav['pages']->reset();
                $this->grav['pages']->init();
            }
        } catch (\RuntimeException $e) {
            throw new ValidationException($e->getMessage());
        }

        return $this->respondWithInvalidation(
            [
                'success' => true,
                'message' => 'Page restored from trash.',
                'route'   => $result['route'] ?? null,
                'count'   => $trashManager->count(),
            ],
            ['pages:list', 'pages:update:' . ($result['route'] ?? '')]
        );
    }

    /**
     * DELETE /revisions-pro/trash/{id} — Permanently delete a trash item.
     */
    public function trashDelete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.update');
        $trashManager = $this->requireTrashManager();

        $id = $this->getRouteParam($request, 'id');

        if (!$trashManager->getItem($id)) {
            throw new NotFoundException('Trash item not found.');
        }

        $result = $trashManager->deleteItem($id);
        if (!$result) {
            return ApiResponse::create(['success' => false, 'message' => 'Failed to delete trash item.'], 500);
        }

        return ApiResponse::create([
            'success' => true,
            'message' => 'Trash item deleted.',
            'count'   => $trashManager->count(),
        ]);
    }

    /**
     * DELETE /revisions-pro/trash — Empty the trash.
     */
    public function trashEmpty(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.pages.update');
        $trashManager = $this->requireTrashManager();

        $removed = $trashManager->emptyTrash();

        return ApiResponse::create([
            'success' => true,
            'message' => sprintf('%d item(s) removed from trash.', $removed),
            'removed' => $removed,
            'count'   => $trashManager->count(),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────

    private function getQueryParam(ServerRequestInterface $request, string $name, string $default = ''): string
    {
        $params = $request->getQueryParams();
        return $params[$name] ?? $default;
    }

    private function getRevisionManager(): RevisionManager
    {
        // Ensure pages are available (needed for page lookups in RevisionManager)
        AdminProxy::enablePages();

        $config = $this->grav['config'];
        return new RevisionManager($this->grav, $config);
    }

    private function getTrashManager(): ?TrashManager
    {
        if (!$this->grav['config']->get('plugins.revisions-pro.enable_trash', true)) {
            return null;
        }

        AdminProxy::enablePages();
        return new TrashManager($this->grav, $this->grav['config'], $this->getRevisionManager());
    }

    private function requireTrashManager(): TrashManager
    {
        $manager = $this->getTrashManager();
        if (!$manager || !$manager->isEnabled()) {
            throw new ValidationException('Trash feature is disabled.');
        }
        return $manager;
    }

    /**
     * Get revisions for any context type (page, config, plugin, theme).
     */
    private function getRevisionsForContext(string $route, string $lang, string $type): array
    {
        if (empty($route)) {
            return [];
        }

        $manager = $this->getRevisionManager();

        if ($type === 'page' || $type === 'pages') {
            $filePath = $this->findPageFile($route, $lang);
            return $filePath ? $manager->getPageRevisions($filePath) : [];
        }

        // Config types: use getRevisionsForRoute with the appropriate type string
        $revType = match ($type) {
            'config'  => 'config-' . $route,
            'plugins' => 'plugin-config',
            'themes'  => 'theme-config',
            default   => $type,
        };

        return $manager->getRevisionsForRoute($route, $revType);
    }

    /**
     * Determine a display filename for the given context.
     */
    private function getFilenameForContext(string $route, string $lang, string $type): string
    {
        return match ($type) {
            'page', 'pages' => ($f = $this->findPageFile($route, $lang)) ? basename($f) : 'unknown',
            'config'  => $route . '.yaml',
            'plugins' => 'plugins/' . $route . '.yaml',
            'themes'  => 'themes/' . $route . '.yaml',
            default   => $route,
        };
    }

    /**
     * Find the target page file path for a given route and language.
     *
     * Replicates the page-finding logic from the admin-classic AJAX handler,
     * handling multilanguage pages correctly.
     */
    private function findPageFile(string $route, string $lang): ?string
    {
        if (empty($route)) {
            return null;
        }

        // Enable pages (disabled by default during API requests)
        AdminProxy::enablePages();

        // Set active language if provided
        if ($lang && $this->grav['language']->enabled()) {
            $this->grav['language']->setActive($lang);
        }

        // Find the page
        $page = $this->grav['pages']->find('/' . ltrim($route, '/'));
        if (!$page) {
            $page = $this->grav['pages']->find(ltrim($route, '/'));
        }

        if (!$page || !$page->path()) {
            return null;
        }

        $pageDir = $page->path();
        $template = $page->template() ?: 'default';

        // Get actual template from the page file
        $pageFile = $page->file();
        if ($pageFile) {
            $baseFilename = basename($pageFile->filename());
            if (preg_match('/^(.+?)(?:\.[a-z]{2})?\.md$/', $baseFilename, $matches)) {
                $template = $matches[1];
            }
        }

        // Determine the target file based on language
        if ($this->grav['language']->enabled() && $lang) {
            $targetFile = $pageDir . '/' . $template . '.' . $lang . '.md';
            if (!file_exists($targetFile)) {
                // Fall back to non-language file
                $targetFile = $pageDir . '/' . $template . '.md';
            }
        } else {
            $targetFile = $pageDir . '/' . $template . '.md';

            // If multilanguage is enabled but no lang specified, check for default language file
            if ($this->grav['language']->enabled()) {
                $defaultLang = $this->grav['language']->getDefault();
                if ($defaultLang) {
                    $langFile = $pageDir . '/' . $template . '.' . $defaultLang . '.md';
                    if (file_exists($langFile)) {
                        $targetFile = $langFile;
                    }
                }
            }
        }

        return file_exists($targetFile) ? $targetFile : null;
    }

    /**
     * Extract raw content from a revision (same logic as RevisionManager::getRevisionContent).
     */
    private function getRevisionRawContent(array $revision): string
    {
        $data = $revision['data'] ?? [];

        if ($revision['type'] === 'page') {
            if (isset($data['rawContent'])) {
                return $data['rawContent'];
            }

            $content = $data['content'] ?? '';
            $header = $data['header'] ?? [];

            if (!empty($header) && strpos($content, '---') !== 0) {
                $headerYaml = \Grav\Common\Yaml::dump($header);
                return "---\n" . $headerYaml . "---\n\n" . $content;
            }

            return $content;
        }

        if (isset($data['rawContent'])) {
            return $data['rawContent'];
        }

        if (isset($data['content']) && is_array($data['content'])) {
            return \Grav\Common\Yaml::dump($data['content']);
        }

        return '';
    }

    /**
     * Build a structured diff as an array of line objects for JSON output.
     *
     * Returns: [{ type: 'context'|'added'|'removed', oldNum: int|null, newNum: int|null, content: string }]
     */
    private function buildStructuredDiff(string $oldContent, string $newContent): array
    {
        // Normalize line endings before comparison
        $oldContent = str_replace("\r\n", "\n", $oldContent);
        $newContent = str_replace("\r\n", "\n", $newContent);

        if ($oldContent === $newContent) {
            // No changes — return all lines as context
            $lines = explode("\n", $newContent);
            $result = [];
            foreach ($lines as $i => $line) {
                $num = $i + 1;
                $result[] = ['type' => 'context', 'oldNum' => $num, 'newNum' => $num, 'content' => $line];
            }
            return $result;
        }

        $oldLines = explode("\n", $oldContent);
        $newLines = explode("\n", $newContent);

        $differ = new \SebastianBergmann\Diff\Differ();
        $diff = $differ->diffToArray($oldLines, $newLines);

        $result = [];
        $oldNum = 0;
        $newNum = 0;

        foreach ($diff as $entry) {
            $content = $entry[0];
            $type = $entry[1]; // 0=unchanged, 1=added, 2=removed

            if ($type === 0) {
                $oldNum++;
                $newNum++;
                $result[] = ['type' => 'context', 'oldNum' => $oldNum, 'newNum' => $newNum, 'content' => $content];
            } elseif ($type === 2) {
                // Removed (was in old, not in new)
                $oldNum++;
                $result[] = ['type' => 'removed', 'oldNum' => $oldNum, 'newNum' => null, 'content' => $content];
            } elseif ($type === 1) {
                // Added (in new, not in old)
                $newNum++;
                $result[] = ['type' => 'added', 'oldNum' => null, 'newNum' => $newNum, 'content' => $content];
            }
        }

        return $result;
    }
}
