<?php

namespace Grav\Plugin\RevisionsPro;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Inflector;
use Grav\Common\Utils;
use RocketTheme\Toolbox\File\File;
use Grav\Plugin\Admin\Utils as AdminUtils;

class TrashManager
{
    protected $grav;
    protected $config;
    protected $revisionManager;
    protected $dataPath;
    protected $trashPath;
    protected $purged = false;

    public function __construct(Grav $grav, $config, RevisionManager $revisionManager)
    {
        $this->grav = $grav;
        $this->config = $config;
        $this->revisionManager = $revisionManager;

        $this->dataPath = $grav['locator']->findResource('user://data/revisions', true, true);
        $this->trashPath = $this->dataPath . '/trash';

        Folder::create($this->trashPath);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('plugins.revisions-pro.enable_trash', true);
    }

    public function capturePage(PageInterface $page, array $context = []): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $this->purgeIfNeeded();

        $pagesPath = $this->grav['locator']->findResource('page://');
        $sourcePath = $page->path();

        if (!$sourcePath || !is_dir($sourcePath)) {
            return null;
        }

        $id = $this->generateItemId($page->path());
        $itemDirectory = $this->trashPath . '/' . $id;
        $pageDirectory = $itemDirectory . '/page';

        Folder::create($itemDirectory);

        try {
            Folder::copy($sourcePath, $pageDirectory);
        } catch (\RuntimeException $e) {
            Folder::delete($itemDirectory);
            throw $e;
        }

        $metadata = $this->buildMetadata($id, $page, $context, $pagesPath);

        $metadataFile = File::instance($itemDirectory . '/item.json');
        $metadataFile->save(json_encode($metadata, JSON_PRETTY_PRINT));

        $index = $this->loadIndex();
        $index[$id] = [
            'id' => $id,
            'title' => $metadata['title'],
            'route' => $metadata['original_route'],
            'relative_path' => $metadata['relative_path'],
            'deleted_at' => $metadata['deleted_at'],
            'deleted_by' => $metadata['deleted_by'],
            'folder' => $metadata['folder'],
            'language' => $metadata['language'],
        ];
        $this->saveIndex($index);

        return $metadata;
    }

    public function listItems(): array
    {
        $this->purgeIfNeeded();
        $index = $this->loadIndex();

        $items = [];
        $dirty = false;
        foreach ($index as $id => $entry) {
            $item = $this->getItem($id);
            if ($item) {
                $items[] = $item;
            } else {
                unset($index[$id]);
                $dirty = true;
            }
        }

        if ($dirty) {
            $this->saveIndex($index);
        }

        usort($items, static function ($a, $b) {
            return ($b['metadata']['deleted_at'] ?? 0) <=> ($a['metadata']['deleted_at'] ?? 0);
        });

        return $items;
    }

    public function getItem(string $id): ?array
    {
        $itemDirectory = $this->trashPath . '/' . $id;
        if (!is_dir($itemDirectory)) {
            return null;
        }

        $metadataFile = $itemDirectory . '/item.json';
        if (!file_exists($metadataFile)) {
            return null;
        }

        $metadata = json_decode(file_get_contents($metadataFile), true);
        if (!$metadata) {
            return null;
        }

        $metadata['id'] = $id;

        return [
            'id' => $id,
            'directory' => $itemDirectory,
            'metadata' => $metadata,
        ];
    }

    public function deleteItem(string $id): bool
    {
        $this->purged = false;
        $item = $this->getItem($id);
        if (!$item) {
            return false;
        }

        Folder::delete($item['directory']);

        $index = $this->loadIndex();
        unset($index[$id]);
        $this->saveIndex($index);

        return true;
    }

    public function emptyTrash(): int
    {
        $this->purged = false;
        $index = $this->loadIndex();
        $removed = 0;

        foreach (array_keys($index) as $id) {
            if ($this->deleteItem($id)) {
                $removed++;
            }
        }

        return $removed;
    }

    public function count(): int
    {
        $this->purgeIfNeeded();
        return count($this->loadIndex());
    }

    public function restoreItem(string $id, array $options = []): array
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new \RuntimeException('Trash item not found');
        }

        $metadata = $item['metadata'];
        $pageDirectory = $item['directory'] . '/page';
        if (!is_dir($pageDirectory)) {
            throw new \RuntimeException('Stored page contents missing');
        }

        $pagesPath = $this->grav['locator']->findResource('page://');
        $pages = $this->grav['pages'];
        $mode = $options['mode'] ?? 'original';
        $overwrite = (bool) ($options['overwrite'] ?? false);

        $targetPath = rtrim($pagesPath, '/');
        $targetRoute = $metadata['original_route'];

        if ($mode === 'custom') {
            $customRoute = $options['custom_route'] ?? $metadata['original_route'];
            $customRoute = '/' . ltrim($customRoute, '/');
            $segments = array_filter(explode('/', trim($customRoute, '/')));

            if (empty($segments)) {
                throw new \RuntimeException('Custom route is invalid');
            }

            $baseSlug = array_pop($segments);
            $derivedParentRoute = '/' . implode('/', $segments);
            if ($derivedParentRoute === '//') {
                $derivedParentRoute = '/';
            }

            $explicitParent = array_key_exists('parent_route', $options);
            $parentRouteValue = trim((string)($options['parent_route'] ?? $derivedParentRoute));
            if ($parentRouteValue === '') {
                $parentRoute = '/';
            } else {
                $parentRoute = '/' . ltrim($parentRouteValue, '/');
            }

            $slugCandidate = trim((string)($options['slug'] ?? ''));
            if ($slugCandidate === '') {
                $slugCandidate = $metadata['slug'] ?? $baseSlug;
            }
            if (class_exists(AdminUtils::class) && method_exists(AdminUtils::class, 'slug')) {
                $slug = AdminUtils::slug($slugCandidate);
            } else {
                $converted = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT', $slugCandidate) : $slugCandidate;
                $slug = strtolower(trim(preg_replace('/[^a-z0-9\-]+/', '-', $converted), '-'));
            }
            if (!$slug) {
                throw new \RuntimeException('Unable to determine page slug');
            }

            $folderNameInput = trim((string)($options['folder_name'] ?? ''));
            if ($folderNameInput === '') {
                $folderName = $metadata['folder'] ?? '';
                if ($folderName) {
                    $originalSlug = $metadata['slug'] ?? '';
                    if ($originalSlug && $originalSlug !== $slug) {
                        if (preg_match('/^(\d+)\.(.+)$/', $folderName, $matches)) {
                            $folderName = $matches[1] . '.' . $slug;
                        } else {
                            $folderName = $slug;
                        }
                    }
                }
                if (!$folderName) {
                    $folderName = $slug;
                }
            } else {
                $folderName = $folderNameInput;
            }

            $folderName = trim($folderName, '/');
            if ($folderName === '') {
                $folderName = $slug;
            }

            $targetRoute = $parentRoute === '/' ? '/' . $slug : rtrim($parentRoute, '/') . '/' . $slug;
            $parentPath = $this->ensureParentPathExists($parentRoute, $pagesPath, $pages, $explicitParent);

            $targetPath = rtrim($parentPath, '/') . '/' . $folderName;
        } else {
            $relative = $metadata['relative_path'] ?? null;
            if (!$relative) {
                $relative = trim($metadata['folder'] ?? '', '/');
            }

            if (!$relative) {
                throw new \RuntimeException('Original destination not recorded');
            }

            $targetPath = rtrim($pagesPath, '/') . '/' . trim($relative, '/');
        }

        if (is_dir($targetPath)) {
            if (!$overwrite) {
                throw new \RuntimeException('A page already exists at the destination');
            }

            $existingPage = $this->findPageByPath($targetPath);
            if ($existingPage instanceof PageInterface) {
                $this->capturePage($existingPage, ['source' => 'restore-overwrite']);
            }

            Folder::delete($targetPath);
        }

        Folder::copy($pageDirectory, $targetPath);

        $this->deleteItem($id);

        return [
            'route' => $targetRoute,
            'path' => $targetPath,
        ];
    }

    protected function ensureParentPathExists(string $parentRoute, string $pagesPath, $pages, bool $explicitParent): string
    {
        $pagesPath = rtrim($pagesPath, '/');

        if ($parentRoute === '/' || $parentRoute === '') {
            return $pagesPath;
        }

        $parentRoute = '/' . ltrim($parentRoute, '/');
        if ($parentRoute === '//') {
            $parentRoute = '/';
        }

        if ($parentRoute === '/') {
            return $pagesPath;
        }

        $segments = array_filter(explode('/', trim($parentRoute, '/')));
        $currentRoute = '';
        $currentPath = $pagesPath;

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $currentRoute .= '/' . $segment;
            $existing = $pages->find($currentRoute);
            if ($existing) {
                $currentPath = $existing->path();
                continue;
            }

            $folderName = $segment;
            if (strpos($folderName, '..') !== false) {
                throw new \RuntimeException('Invalid parent route segment: ' . $segment);
            }

            if (class_exists(AdminUtils::class) && method_exists(AdminUtils::class, 'slug')) {
                $cleanSlug = AdminUtils::slug($segment);
            } else {
                $converted = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT', $segment) : $segment;
                $cleanSlug = strtolower(trim(preg_replace('/[^a-z0-9\-]+/', '-', $converted), '-'));
            }

            if (!preg_match('/^\d+\.[\w\-]+$/', $folderName)) {
                $folderName = $cleanSlug ?: $folderName;
            }

            $currentPath .= '/' . $folderName;
            Folder::create($currentPath);

            $pageFile = $currentPath . '/default.md';
            if (!file_exists($pageFile)) {
                $titleSource = str_replace('-', ' ', $segment);
                $title = Inflector::titleize($titleSource ?: $folderName);
                $frontmatter = "---\ntitle: {$title}\nvisible: false\n---\n";
                File::instance($pageFile)->save($frontmatter);
            }
        }

        if (!is_dir($currentPath)) {
            if ($explicitParent) {
                throw new \RuntimeException(sprintf('Unable to create parent route "%s".', $parentRoute));
            }
            return $pagesPath;
        }

        return $currentPath;
    }

    protected function purgeIfNeeded(): void
    {
        if ($this->purged) {
            return;
        }

        $this->purged = true;

        $retention = (int) $this->config->get('plugins.revisions-pro.trash_retention_days', 0);
        $maxItems = (int) $this->config->get('plugins.revisions-pro.trash_max_items', 0);

        if ($retention <= 0 && $maxItems <= 0) {
            return;
        }

        $index = $this->loadIndex();
        $now = time();
        $modified = false;

        if ($retention > 0) {
            $threshold = $now - ($retention * 86400);
            foreach ($index as $id => $entry) {
                $deletedAt = $entry['deleted_at'] ?? null;
                if ($deletedAt && $deletedAt < $threshold) {
                    $this->deleteItem($id);
                    unset($index[$id]);
                    $modified = true;
                }
            }
        }

        if ($maxItems > 0) {
            if (count($index) > $maxItems) {
                uasort($index, static function ($a, $b) {
                    return ($a['deleted_at'] ?? 0) <=> ($b['deleted_at'] ?? 0);
                });

                $excess = count($index) - $maxItems;
                if ($excess > 0) {
                    $idsToRemove = array_slice(array_keys($index), 0, $excess);
                    foreach ($idsToRemove as $id) {
                        $this->deleteItem($id);
                        unset($index[$id]);
                    }
                    $modified = true;
                }
            }
        }

        if ($modified) {
            $this->saveIndex($index);
        }

        $this->purged = true;
    }

    protected function loadIndex(): array
    {
        $indexFile = $this->trashPath . '/index.json';
        if (!file_exists($indexFile)) {
            return [];
        }

        $data = json_decode(file_get_contents($indexFile), true);

        return is_array($data) ? $data : [];
    }

    protected function saveIndex(array $index): void
    {
        $indexFile = $this->trashPath . '/index.json';
        $file = File::instance($indexFile);
        $file->save(json_encode($index, JSON_PRETTY_PRINT));
    }

    protected function generateItemId(string $path): string
    {
        $hash = substr(md5($path . microtime(true)), 0, 10);

        return date('Ymd-His') . '-' . $hash;
    }

    protected function buildMetadata(string $id, PageInterface $page, array $context, string $pagesPath): array
    {
        $pagesPath = rtrim($pagesPath, '/');
        $pagePath = rtrim($page->path(), '/');
        $relativePath = ltrim(str_replace($pagesPath, '', $pagePath), '/');

        $parent = $page->parent();
        $parentPath = $parent ? rtrim($parent->path(), '/') : $pagesPath;
        $parentRelative = ltrim(str_replace($pagesPath, '', $parentPath), '/');

        $timestamp = time();
        $user = $context['user'] ?? ($this->grav['user']->username ?? 'unknown');

        return [
            'id' => $id,
            'title' => $page->title() ?: $page->slug(),
            'original_route' => $page->route(),
            'original_path' => $pagePath,
            'relative_path' => $relativePath,
            'folder' => basename($pagePath),
            'slug' => $page->slug(),
            'parent_route' => $parent ? $parent->route() : '/',
            'parent_relative_path' => $parentRelative,
            'language' => method_exists($page, 'language') ? $page->language() : null,
            'template' => $page->template(),
            'published' => $page->header()->published ?? null,
            'visible' => $page->visible(),
            'routable' => $page->routable(),
            'last_modified' => $page->modified(),
            'deleted_at' => $timestamp,
            'deleted_at_iso' => date(DATE_ATOM, $timestamp),
            'deleted_by' => $user,
            'context' => $context,
        ];
    }

    protected function findPageByPath(string $path): ?PageInterface
    {
        $path = rtrim($path, '/');
        $pages = $this->grav['pages'];

        /** @var PageInterface $page */
        foreach ($pages->instances() as $page) {
            if (rtrim($page->path(), '/') === $path) {
                return $page;
            }
        }

        return null;
    }
}
