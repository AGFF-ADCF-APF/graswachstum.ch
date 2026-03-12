<?php
namespace Grav\Plugin\RevisionsPro;

use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Data\Data;
use Grav\Common\Filesystem\Folder;
use RocketTheme\Toolbox\File\File;
use Grav\Common\Yaml;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

class RevisionManager
{
    protected $grav;
    protected $config;
    protected $dataPath;
    
    public function __construct(Grav $grav, $config)
    {
        $this->grav = $grav;
        $this->config = $config;
        $this->dataPath = $grav['locator']->findResource('user://data/revisions', true, true);
    }
    
    public function createRevision($object, $type, $original = null)
    {
        // First, get the content that would be saved
        $currentContent = '';
        if ($object instanceof PageInterface) {
            // For pages, get the raw content including frontmatter
            $currentContent = $object->raw();
        } else {
            // For config objects, get the YAML representation
            $currentContent = Yaml::dump($object->toArray());
        }
        
        // Calculate MD5 of current content
        $currentMd5 = md5($currentContent);
        
        // Get the route/identifier for the object
        $route = '';
        if ($object instanceof PageInterface) {
            // Get page path to match how we store revisions in getPageRevisions
            $pagePath = $object->filePath();
            
            // If no file path, try to get it from the page
            if (!$pagePath && method_exists($object, 'path')) {
                $pageDir = $object->path();
                $template = $object->template() ?: 'default';
                
                // Check for language-specific file first if multilanguage is enabled
                if ($this->grav['language']->enabled()) {
                    $currentLang = $this->grav['language']->getLanguage();
                    $defaultLang = $this->grav['language']->getDefault();
                    
                    if ($currentLang && $currentLang !== $defaultLang) {
                        $langPath = $pageDir . '/' . $template . '.' . $currentLang . '.md';
                        if (file_exists($langPath)) {
                            $pagePath = $langPath;
                        }
                    }
                }
                
                // Fallback to default file
                if (!$pagePath || !file_exists($pagePath)) {
                    $pagePath = $pageDir . '/' . $template . '.md';
                }
            }
            
            $route = $pagePath;
        } elseif ($original) {
            // For config files, use the file path as route
            $route = str_replace($this->grav['locator']->findResource('user://'), '', $original);
        }
        
        // Get latest revision and check if content has changed
        if ($route) {
            
            // For pages, use getPageRevisions directly with the file path
            if ($object instanceof PageInterface) {
                $revisions = $this->getPageRevisions($route, 1);
            } else {
                $revisions = $this->getRevisionsForRoute($route, $type, 1);
            }
            
            if (!empty($revisions)) {
                $latestRevision = reset($revisions);
                // Load the revision file and get its content
                if (isset($latestRevision['file']) && file_exists($latestRevision['file'])) {
                    $revisionData = json_decode(file_get_contents($latestRevision['file']), true);
                    if ($revisionData && isset($revisionData['rawContent'])) {
                        $lastMd5 = md5($revisionData['rawContent']);
                        if ($currentMd5 === $lastMd5) {
                            // Content hasn't changed, don't create a new revision
                            return true;
                        }
                    }
                }
            }
        }
        
        $timestamp = date('Ymd-His');
        $metadata = [
            'timestamp' => time(),
            'user' => $this->grav['user']->username ?? 'unknown',
            'type' => $type,
            'action' => $original ? 'update' : 'create'
        ];
        
        if ($object instanceof PageInterface) {
            return $this->createPageRevision($object, $timestamp, $metadata);
        } else {
            // $original might be the file path for config objects
            return $this->createConfigRevision($object, $type, $timestamp, $metadata, $original);
        }
    }
    
    protected function createPageRevision(PageInterface $page, $timestamp, $metadata)
    {
        // Get the page file path
        $pagePath = $page->filePath();
        
        // If that doesn't work, try the path method
        if (!$pagePath) {
            $pagePath = $page->path();
        }
        
        // Debug logging
        
        if (!$pagePath || !file_exists($pagePath)) {
            return false;
        }
        
        $pageDir = dirname($pagePath);
        $pageFile = basename($pagePath);
        
        // Store revision alongside the page
        $revisionFile = $pageDir . '/' . $pageFile . '.' . $timestamp . '.rev';
        
        
        // Get the full raw content of the page (header + content)
        $fullContent = $page->raw();
        
        $revisionData = [
            'metadata' => $metadata,
            'content' => $fullContent,  // This includes both frontmatter and content
            'rawContent' => $fullContent,  // Store the raw content for display
            'header' => $page->header(),
            'route' => $page->route(),
            'title' => $page->title()
        ];
        
        try {
            $file = File::instance($revisionFile);
            $file->save(json_encode($revisionData, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            return false;
        }
        
        // Also store metadata in central index for quick lookup
        $this->updateRevisionIndex($page->route(), 'page', $revisionFile, $metadata);
        
        return true;
    }
    
    protected function createConfigRevision($object, $type, $timestamp, $metadata, $filePath = null)
    {
        // If we have a file path, store revision alongside the config file
        if ($filePath && file_exists($filePath)) {
            
            $configDir = dirname($filePath);
            $configFile = basename($filePath);
            
            // Store revision alongside the config file
            $revisionFile = $configDir . '/' . $configFile . '.' . $timestamp . '.rev';
            
            
            // Get the full content as YAML using Grav's YAML handler
            $content = Yaml::dump($object->toArray());
            
            $revisionData = [
                'metadata' => $metadata,
                'content' => $object->toArray(),
                'rawContent' => $content,
                'filePath' => $filePath,
                'type' => $type
            ];
            
            try {
                $file = File::instance($revisionFile);
                $file->save(json_encode($revisionData, JSON_PRETTY_PRINT));
                
                // Update the index with the file path as the route
                $route = str_replace($this->grav['locator']->findResource('user://'), '', $filePath);
                $this->updateRevisionIndex($route, $type, $revisionFile, $metadata);
                
                return true;
            } catch (\Exception $e) {
                return false;
            }
        }
        
        // Fallback to old method if no file path
        $path = $this->getConfigPath($type);
        if (!$path) {
            return false;
        }
        
        $revisionDir = $this->dataPath . '/' . $path;
        Folder::create($revisionDir);
        
        $filename = $this->getConfigFilename($type);
        $revisionFile = $revisionDir . '/' . $filename . '.' . $timestamp . '.rev';
        
        $revisionData = [
            'metadata' => $metadata,
            'content' => $object->toArray()
        ];
        
        $file = File::instance($revisionFile);
        $file->save(json_encode($revisionData, JSON_PRETTY_PRINT));
        
        $this->updateRevisionIndex($path . '/' . $filename, $type, $revisionFile, $metadata);
        
        return true;
    }
    
    public function getRevisions($object, $limit = null)
    {
        // Use configured limit if not specified
        if ($limit === null) {
            $limit = $this->config->get('plugins.revisions-pro.max_revisions_per_page', 50);
        }
        
        if ($object instanceof PageInterface) {
            $pagePath = $object->filePath();
            
            // If no file path, try to get it from the page
            if (!$pagePath && method_exists($object, 'path')) {
                $pageDir = $object->path();
                $template = $object->template() ?: 'default';
                
                // Check for language-specific file first if multilanguage is enabled
                if ($this->grav['language']->enabled()) {
                    $currentLang = $this->grav['language']->getLanguage();
                    $defaultLang = $this->grav['language']->getDefault();
                    
                    if ($currentLang && $currentLang !== $defaultLang) {
                        $langPath = $pageDir . '/' . $template . '.' . $currentLang . '.md';
                        if (file_exists($langPath)) {
                            $pagePath = $langPath;
                        }
                    }
                }
                
                // Fallback to default file
                if (!$pagePath || !file_exists($pagePath)) {
                    $pagePath = $pageDir . '/' . $template . '.md';
                }
            }
            
            return $this->getPageRevisions($pagePath, $limit);
        }
        
        // For other types, use the index
        return [];
    }
    
    public function getPageRevisions($pagePath, $limit = null)
    {
        // Use configured limit if not specified
        if ($limit === null) {
            $limit = $this->config->get('plugins.revisions-pro.max_revisions_per_page', 50);
        }
        
        if (!$pagePath || !file_exists($pagePath)) {
            return [];
        }
        
        $pageDir = dirname($pagePath);
        $pageFile = basename($pagePath);
        
        // Check if multilanguage is enabled
        $isMultilang = $this->grav['language']->enabled();
        $currentLang = $isMultilang ? $this->grav['language']->getLanguage() : null;
        $defaultLang = $isMultilang ? $this->grav['language']->getDefault() : null;
        
        $revisions = [];
        
        try {
            $files = Folder::all($pageDir);

            foreach ($files as $file) {
                // Create a more specific regex pattern for multilanguage support
                $pattern = '/' . preg_quote($pageFile, '/') . '\.(\d{8}-\d{6})\.rev$/';
                
                if ($isMultilang) {
                    // For multilanguage sites, we need to be more specific
                    // Extract the base filename without language code
                    $baseFilename = preg_replace('/\.[a-z]{2}\.md$/', '.md', $pageFile);
                    
                    // Check if the current page file has a language code
                    if (preg_match('/\.([a-z]{2})\.md$/', $pageFile, $langMatch)) {
                        // Page has a specific language code, only match revisions for this language
                        $pattern = '/' . preg_quote($pageFile, '/') . '\.(\d{8}-\d{6})\.rev$/';
                    } else {
                        // Page is default language, only match revisions without language code
                        // This prevents default.md from showing default.fr.md revisions
                        $pattern = '/' . preg_quote($baseFilename, '/') . '\.(\d{8}-\d{6})\.rev$/';
                    }
                }

                if (preg_match($pattern, $file, $matches)) {
                    // Additional check for multilanguage to ensure exact match
                    if ($isMultilang) {
                        // Extract the revision's base filename (without timestamp and .rev)
                        $revisionBase = preg_replace('/\.\d{8}-\d{6}\.rev$/', '', $file);

                        // Only include if it exactly matches our page file
                        if ($revisionBase !== $pageFile) {
                            continue;
                        }
                    }
                    
                    $revisionFile = $pageDir . '/' . $file;
                    
                    $data = json_decode(file_get_contents($revisionFile), true);
                    
                    if ($data) {
                        $revisions[] = [
                            'id' => md5($revisionFile),
                            'file' => $revisionFile,
                            'timestamp' => $data['metadata']['timestamp'],
                            'user' => $data['metadata']['user'],
                            'action' => $data['metadata']['action'],
                            'title' => $data['title'] ?? 'Untitled',
                            'date' => date('Y-m-d H:i:s', $data['metadata']['timestamp'])
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
        }
        
        
        // Sort by timestamp descending
        usort($revisions, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return array_slice($revisions, 0, $limit);
    }
    
    protected function getConfigRevisions($route, $type, $limit = null)
    {
        // Use configured limit if not specified
        if ($limit === null) {
            $limit = $this->config->get('plugins.revisions-pro.max_revisions_per_page', 50);
        }
        
        
        // First check the index for config revisions
        $indexFile = $this->dataPath . '/index.json';
        if (file_exists($indexFile)) {
            $index = json_decode(file_get_contents($indexFile), true);
            $needsCleanup = false;
            
            // Look for entries that match config patterns
            foreach ($index as $indexRoute => $indexRevisions) {
                // Check if this route matches our config file
                $match = false;
                if ($type === 'plugin-config') {
                    $match = strpos($indexRoute, '/config/plugins/' . $route . '.yaml') !== false;
                } elseif ($type === 'theme-config') {
                    $match = strpos($indexRoute, '/config/themes/' . $route . '.yaml') !== false;
                } else {
                    $match = strpos($indexRoute, '/config/' . $route . '.yaml') !== false;
                }
                
                if ($match) {
                    
                    $revisions = [];
                    $validRevisions = [];
                    
                    foreach ($indexRevisions as $revision) {
                        // Convert relative path to absolute
                        $absolutePath = GRAV_ROOT . '/' . $revision['file'];
                        
                        if (file_exists($absolutePath)) {
                            $data = json_decode(file_get_contents($absolutePath), true);
                            if ($data) {
                                $revisions[] = [
                                    'id' => $revision['id'],
                                    'file' => $absolutePath,
                                    'timestamp' => $revision['timestamp'],
                                    'user' => $revision['user'],
                                    'action' => $revision['action'],
                                    'type' => $revision['type'] ?? $type,
                                    'date' => date('Y-m-d H:i:s', $revision['timestamp']),
                                    'size' => filesize($absolutePath),
                                    'size_formatted' => $this->formatFileSize(filesize($absolutePath))
                                ];
                                $validRevisions[] = $revision;
                            }
                        } else {
                            // File doesn't exist, mark for cleanup
                            $needsCleanup = true;
                        }
                    }
                    
                    // Update index if we found missing files
                    if ($needsCleanup) {
                        $index[$indexRoute] = $validRevisions;
                        file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT));
                    }
                    
                    // Sort by timestamp descending
                    usort($revisions, function($a, $b) {
                        return $b['timestamp'] - $a['timestamp'];
                    });
                    
                    return array_slice($revisions, 0, $limit);
                }
            }
        }
        
        // Fallback: manually search for revision files
        
        $revisions = [];
        $configName = str_replace('config-', '', $type);
        
        // Try different possible paths
        $possiblePaths = [
            $this->grav['locator']->findResource('config://') . '/' . $configName . '.yaml',
            $this->grav['locator']->findResource('user://') . '/config/' . $configName . '.yaml'
        ];
        
        // Try to add environment path if available
        try {
            $envPath = $this->grav['locator']->findResource('environment://');
            if ($envPath) {
                $possiblePaths[] = $envPath . '/config/' . $configName . '.yaml';
            }
        } catch (\Exception $e) {
            // Environment not available
        }
        
        // Find the actual config file
        $configFile = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $configFile = $path;
                break;
            }
        }
        
        if (!$configFile) {
            return [];
        }
        
        // Look for revision files alongside the config file
        $configDir = dirname($configFile);
        $configFilename = basename($configFile);
        
        try {
            $files = Folder::all($configDir);
            
            foreach ($files as $file) {
                if (preg_match('/' . preg_quote($configFilename, '/') . '\.(\d{8}-\d{6})\.rev$/', $file, $matches)) {
                    $revisionFile = $configDir . '/' . $file;
                    
                    $data = json_decode(file_get_contents($revisionFile), true);
                    
                    if ($data) {
                        // Use the same ID that would have been stored in the index
                        $revisions[] = [
                            'id' => md5($revisionFile),
                            'file' => $revisionFile,
                            'timestamp' => $data['metadata']['timestamp'],
                            'user' => $data['metadata']['user'],
                            'action' => $data['metadata']['action'],
                            'type' => $data['metadata']['type'] ?? $type,
                            'date' => date('Y-m-d H:i:s', $data['metadata']['timestamp']),
                            'size' => filesize($revisionFile),
                            'size_formatted' => $this->formatFileSize(filesize($revisionFile))
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
        }
        
        // Sort by timestamp descending
        usort($revisions, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return array_slice($revisions, 0, $limit);
    }
    
    protected function formatFileSize($size)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
    
    public function cleanupIndex()
    {
        $indexFile = $this->dataPath . '/index.json';
        if (!file_exists($indexFile)) {
            return;
        }
        
        $index = json_decode(file_get_contents($indexFile), true);
        $cleanedIndex = [];
        $removedCount = 0;
        
        foreach ($index as $route => $revisions) {
            $validRevisions = [];
            
            foreach ($revisions as $revision) {
                $absolutePath = GRAV_ROOT . '/' . $revision['file'];
                
                if (file_exists($absolutePath)) {
                    $validRevisions[] = $revision;
                } else {
                    $removedCount++;
                }
            }
            
            if (!empty($validRevisions)) {
                $cleanedIndex[$route] = $validRevisions;
            }
        }
        
        file_put_contents($indexFile, json_encode($cleanedIndex, JSON_PRETTY_PRINT));
        
        return $removedCount;
    }
    
    public function cleanupOldRevisions($daysOld = null)
    {
        // Use configured value if not specified
        if ($daysOld === null) {
            $daysOld = $this->config->get('plugins.revisions-pro.cleanup_older_than', 90);
        }
        
        
        $cleanedCount = 0;
        $cutoffTime = time() - ($daysOld * 24 * 60 * 60);
        
        // Load and clean index
        $indexFile = $this->dataPath . '/index.json';
        if (!file_exists($indexFile)) {
            return 0;
        }
        
        $index = json_decode(file_get_contents($indexFile), true);
        $needsSave = false;
        
        foreach ($index as $route => &$revisions) {
            $keptRevisions = [];
            
            foreach ($revisions as $revision) {
                if ($revision['timestamp'] < $cutoffTime) {
                    // Delete the physical file
                    $absolutePath = GRAV_ROOT . '/' . $revision['file'];
                    if (file_exists($absolutePath)) {
                        if (unlink($absolutePath)) {
                            $cleanedCount++;
                            $needsSave = true;
                        }
                    }
                } else {
                    $keptRevisions[] = $revision;
                }
            }
            
            $revisions = $keptRevisions;
        }
        
        // Clean up empty routes
        $index = array_filter($index, function($revisions) {
            return !empty($revisions);
        });
        
        if ($needsSave) {
            file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT));
        }
        
        // Also clean revision files that exist alongside config files
        $this->cleanupConfigRevisions($daysOld, $cutoffTime, $cleanedCount);

        return $cleanedCount;
    }
    
    protected function cleanupConfigRevisions($daysOld, $cutoffTime, &$cleanedCount)
    {
        // Clean up revision files stored alongside config files
        $configPaths = [
            $this->grav['locator']->findResource('user://config'),
            $this->grav['locator']->findResource('user://config/plugins'),
            $this->grav['locator']->findResource('user://config/themes')
        ];
        
        // Add environment-specific paths if they exist
        try {
            $env = $this->grav['environment'] ?? null;
            if ($env) {
                $envPath = $this->grav['locator']->findResource('user://env/' . $env . '/config');
                if ($envPath && file_exists($envPath)) {
                    $configPaths[] = $envPath;
                }
            }
        } catch (\Exception $e) {
            // Environment not available in CLI mode
        }
        
        foreach ($configPaths as $path) {
            if (!$path || !file_exists($path)) {
                continue;
            }
            
            // Find all .rev files
            $revFiles = glob($path . '/*.yaml.*.rev');
            if ($revFiles) {
                foreach ($revFiles as $revFile) {
                    // Extract timestamp from filename
                    if (preg_match('/\.(\d{8}-\d{6})\.rev$/', $revFile, $matches)) {
                        $timestamp = strtotime($matches[1]);
                        if ($timestamp && $timestamp < $cutoffTime) {
                            if (unlink($revFile)) {
                                $cleanedCount++;
                            }
                        }
                    }
                }
            }
        }
    }
    
    public function getRevisionsForRoute($route, $type = 'page', $limit = null)
    {
        // Use configured limit if not specified
        if ($limit === null) {
            $limit = $this->config->get('plugins.revisions-pro.max_revisions_per_page', 50);
        }
        
        // For config types, look for revision files alongside the config file
        if (strpos($type, 'config-') === 0 || strpos($type, 'plugin-config') === 0 || strpos($type, 'theme-config') === 0) {
            return $this->getConfigRevisions($route, $type, $limit);
        }
        
        $indexFile = $this->dataPath . '/index.json';
        if (!file_exists($indexFile)) {
            return [];
        }
        
        $index = json_decode(file_get_contents($indexFile), true);
        $revisions = [];
        
        $searchKey = $type === 'page' ? $route : $route;
        
        if (isset($index[$searchKey])) {
            foreach ($index[$searchKey] as $revision) {
                if (file_exists($revision['file'])) {
                    $revisions[] = $revision;
                }
            }
        }
        
        // Sort by timestamp descending
        usort($revisions, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return array_slice($revisions, 0, $limit);
    }
    
    public function getRevision($id)
    {
        // Handle special 'current' ID for config files
        if ($id === 'current') {
            return null; // Current state is not a revision
        }
        
        // First try to find in the index
        $indexFile = $this->dataPath . '/index.json';
        if (file_exists($indexFile)) {
            $index = json_decode(file_get_contents($indexFile), true);
            $needsCleanup = false;
            
            foreach ($index as $route => $revisions) {
                $validRevisions = [];
                
                foreach ($revisions as $revision) {
                    // Convert relative path to absolute
                    $absolutePath = GRAV_ROOT . '/' . $revision['file'];
                    
                    if ($revision['id'] === $id) {
                        if (file_exists($absolutePath)) {
                            $data = json_decode(file_get_contents($absolutePath), true);
                            $revision['file'] = $absolutePath; // Use absolute path for return
                            // Ensure date field is present
                            if (!isset($revision['date']) && isset($revision['timestamp'])) {
                                $revision['date'] = date('Y-m-d H:i:s', $revision['timestamp']);
                            }
                            return array_merge($revision, ['data' => $data]);
                        } else {
                            // File doesn't exist but ID matches
                            $needsCleanup = true;
                            continue;
                        }
                    }
                    
                    // Check if this revision file still exists for cleanup
                    if (file_exists($absolutePath)) {
                        $validRevisions[] = $revision;
                    } else {
                        $needsCleanup = true;
                    }
                }
                
                // Update this route's revisions if cleanup needed
                if ($needsCleanup && count($validRevisions) !== count($revisions)) {
                    $index[$route] = $validRevisions;
                }
            }
            
            // Save cleaned up index if needed
            if ($needsCleanup) {
                file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT));
            }
        }
        
        // If not found in index, search through all pages directories
        // This handles revisions stored alongside pages
        $pagesDir = $this->grav['locator']->findResource('page://');
        if ($pagesDir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pagesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/\.(\d{8}-\d{6})\.rev$/', $file->getFilename())) {
                    $filePath = $file->getPathname();
                    $fileId = md5($filePath);
                    
                    if ($fileId === $id) {
                        $data = json_decode(file_get_contents($filePath), true);
                        if ($data) {
                            $timestamp = $data['metadata']['timestamp'] ?? 0;
                            return [
                                'id' => $id,
                                'file' => $filePath,
                                'timestamp' => $timestamp,
                                'user' => $data['metadata']['user'] ?? 'unknown',
                                'type' => $data['metadata']['type'] ?? 'page',
                                'action' => $data['metadata']['action'] ?? 'update',
                                'date' => date('Y-m-d H:i:s', $timestamp),
                                'data' => $data
                            ];
                        }
                    }
                }
            }
        }
        
        // Also search config directories for revision files
        $configDirs = [
            $this->grav['locator']->findResource('config://'),
            $this->grav['locator']->findResource('user://config')
        ];
        
        // Try to add environment paths if available
        try {
            $envPath = $this->grav['locator']->findResource('environment://config');
            if ($envPath) {
                $configDirs[] = $envPath;
            }
            
            $env = $this->grav['environment'] ?? null;
            if ($env) {
                $envPath = $this->grav['locator']->findResource('user://env/' . $env . '/config');
                if ($envPath) {
                    $configDirs[] = $envPath;
                }
            }
        } catch (\Exception $e) {
            // Environment not available in CLI mode
        }
        
        
        foreach ($configDirs as $configDir) {
            if ($configDir && is_dir($configDir)) {
                $files = Folder::all($configDir);
                foreach ($files as $file) {
                    if (preg_match('/\.(\d{8}-\d{6})\.rev$/', $file)) {
                        $filePath = $configDir . '/' . $file;
                        $fileId = md5($filePath);
                        
                        if ($fileId === $id) {
                            $data = json_decode(file_get_contents($filePath), true);
                            if ($data) {
                                $timestamp = $data['metadata']['timestamp'] ?? 0;
                                return [
                                    'id' => $id,
                                    'file' => $filePath,
                                    'timestamp' => $timestamp,
                                    'user' => $data['metadata']['user'] ?? 'unknown',
                                    'type' => $data['metadata']['type'] ?? 'config',
                                    'action' => $data['metadata']['action'] ?? 'update',
                                    'date' => date('Y-m-d H:i:s', $timestamp),
                                    'data' => $data
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        return null;
    }
    
    public function getDiff($revisionId, $compareWith = 'current')
    {
        
        $revision = $this->getRevision($revisionId);
        if (!$revision) {
            return null;
        }
        
        
        // When comparing with current, we want to show what would change if we restore this revision
        // So the revision content is the "new" content and current is the "old" content
        $revisionContent = $this->getRevisionContent($revision);
        $currentContent = '';
        
        if ($compareWith === 'current') {
            $currentContent = $this->getCurrentContent($revision);
            
            // Normalize line endings for comparison
            $revisionContent = str_replace("\r\n", "\n", $revisionContent);
            $currentContent = str_replace("\r\n", "\n", $currentContent);
            
            // Debug first 100 chars of each
            
            if (empty($currentContent)) {
            }
        } else {
            $compareRevision = $this->getRevision($compareWith);
            if ($compareRevision) {
                $currentContent = $this->getRevisionContent($compareRevision);
            }
        }
        
        // Generate both unified diff and custom HTML diff
        // IMPORTANT: We swap the order here - current is "old" and revision is "new"
        // This shows what would be added/removed if we restore this revision
        $differ = new Differ(new UnifiedDiffOutputBuilder());
        $unifiedDiff = $differ->diff($currentContent, $revisionContent);
        
        // Generate custom HTML diff with line-by-line highlighting
        $htmlDiff = $this->generateHtmlDiff($currentContent, $revisionContent);
        
        return [
            'revision' => $revision,
            'diff' => $unifiedDiff,
            'htmlDiff' => $htmlDiff,
            'oldContent' => $currentContent,
            'newContent' => $revisionContent
        ];
    }
    
    public function restoreRevision($id)
    {
        $revision = $this->getRevision($id);
        if (!$revision) {
            return false;
        }
        
        // Create a backup of current version before restoring
        $this->createBackupBeforeRestore($revision);
        
        if ($revision['type'] === 'page') {
            return $this->restorePageRevision($revision);
        } else {
            return $this->restoreConfigRevision($revision);
        }
    }
    
    public function deleteRevision($id)
    {
        $revision = $this->getRevision($id);
        if (!$revision) {
            return false;
        }
        
        // Delete the revision file
        if (file_exists($revision['file'])) {
            try {
                unlink($revision['file']);
            } catch (\Exception $e) {
                return false;
            }
        }
        
        // Update index if the revision was in the index
        $this->removeFromRevisionIndex($id);
        
        return true;
    }
    
    protected function updateRevisionIndex($route, $type, $file, $metadata)
    {
        $indexFile = $this->dataPath . '/index.json';
        $index = [];
        
        if (file_exists($indexFile)) {
            $index = json_decode(file_get_contents($indexFile), true);
        }
        
        if (!isset($index[$route])) {
            $index[$route] = [];
        }
        
        // Store relative path instead of absolute
        $basePath = GRAV_ROOT . '/';
        $relativePath = str_replace($basePath, '', $file);
        
        $index[$route][] = [
            'id' => md5($file),
            'file' => $relativePath,  // Store relative path
            'timestamp' => $metadata['timestamp'],
            'user' => $metadata['user'],
            'type' => $type,
            'action' => $metadata['action']
        ];
        
        // Enforce max revisions limit if configured
        $maxRevisions = $this->config->get('plugins.revisions-pro.max_revisions_per_page', 50);
        if ($maxRevisions > 0 && count($index[$route]) > $maxRevisions) {
            // Sort by timestamp descending
            usort($index[$route], function($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });
            
            // Remove oldest revisions
            $toRemove = array_slice($index[$route], $maxRevisions);
            $index[$route] = array_slice($index[$route], 0, $maxRevisions);
            
            // Delete the physical files
            foreach ($toRemove as $revision) {
                $absolutePath = GRAV_ROOT . '/' . $revision['file'];
                if (file_exists($absolutePath)) {
                    unlink($absolutePath);
                }
            }
        }
        
        file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT));
    }
    
    protected function removeFromRevisionIndex($id)
    {
        $indexFile = $this->dataPath . '/index.json';
        if (!file_exists($indexFile)) {
            return;
        }
        
        $index = json_decode(file_get_contents($indexFile), true);
        
        foreach ($index as $route => &$revisions) {
            $revisions = array_filter($revisions, function($revision) use ($id) {
                return $revision['id'] !== $id;
            });
        }
        
        file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT));
    }
    
    protected function getConfigPath($type)
    {
        // Handle plugin configs
        if ($type === 'plugin-config') {
            return 'config/plugins';
        }
        
        // Handle theme configs
        if ($type === 'theme-config') {
            return 'config/themes';
        }
        
        // Handle dynamic config types (config-media, config-security, etc.)
        if (strpos($type, 'config-') === 0) {
            $configName = str_replace('config-', '', $type);
            return 'config/' . $configName;
        }
        
        return null;
    }
    
    protected function getConfigFilename($type)
    {
        if ($type === 'plugin-config') {
            // Extract plugin name from route
            $route = $this->grav['admin']->route;
            if (preg_match('/\/plugins\/([^\/]+)/', $route, $matches)) {
                return $matches[1] . '.yaml';
            }
        } elseif ($type === 'theme-config') {
            // Extract theme name from route
            $route = $this->grav['admin']->route;
            if (preg_match('/\/themes\/([^\/]+)/', $route, $matches)) {
                return $matches[1] . '.yaml';
            }
        } elseif (strpos($type, 'config-') === 0) {
            // Extract config name and add .yaml extension
            $configName = str_replace('config-', '', $type);
            return $configName . '.yaml';
        }
        
        return 'site.yaml';
    }
    
    protected function getRevisionContent($revision)
    {
        $data = $revision['data'];
        
        if ($revision['type'] === 'page') {
            // Check if this revision has the full raw content
            if (isset($data['rawContent'])) {
                return $data['rawContent'];
            }
            
            // For older revisions, reconstruct the full content
            $content = $data['content'] ?? '';
            $header = $data['header'] ?? [];
            
            // If content doesn't start with frontmatter, add it
            if (!empty($header) && strpos($content, '---') !== 0) {
                // Use Grav's YAML encoding for frontmatter
                $headerYaml = Yaml::dump($header);
                $frontmatter = "---\n" . $headerYaml . "---\n\n";
                return $frontmatter . $content;
            }
            
            return $content;
        } else {
            // For config and other types
            if (isset($data['rawContent'])) {
                return $data['rawContent'];
            }
            
            // If we have content as array, convert to YAML using Grav's handler
            if (isset($data['content']) && is_array($data['content'])) {
                return Yaml::dump($data['content']);
            }
            
            return '';
        }
    }
    
    protected function generateHtmlDiff($oldContent, $newContent)
    {
        // Handle empty content
        if (empty($oldContent) && empty($newContent)) {
            return '<div class="revisions-diff-container"><div class="no-changes-modern"><p>No content to compare</p></div></div>';
        }
        
        // Check if content is identical
        if ($oldContent === $newContent) {
            return '<div class="revisions-diff-container"><div class="no-changes-modern"><p>No changes detected</p></div></div>';
        }
        
        $oldLines = explode("\n", $oldContent);
        $newLines = explode("\n", $newContent);
        
        $differ = new Differ();
        $diff = $differ->diffToArray($oldLines, $newLines);
        
        $html = '<div class="revisions-diff-container">';
        $lineNum = 0;
        
        // Build a map to find pairs of removed/added lines for inline highlighting
        $removedLines = [];
        $addedLines = [];
        $lineIndex = 0;
        
        foreach ($diff as $line) {
            if ($line[1] === 2) {
                $removedLines[] = ['content' => $line[0], 'index' => $lineIndex];
            } elseif ($line[1] === 1) {
                $addedLines[] = ['content' => $line[0], 'index' => $lineIndex];
            }
            $lineIndex++;
        }
        
        // Try to pair removed and added lines that are similar
        $pairs = [];
        foreach ($removedLines as $i => $removed) {
            foreach ($addedLines as $j => $added) {
                // Check if lines are adjacent or close
                if (abs($removed['index'] - $added['index']) <= 2) {
                    similar_text($removed['content'], $added['content'], $percent);
                    if ($percent > 30) {
                        $pairs[$removed['index']] = $added['index'];
                        $pairs[$added['index']] = $removed['index'];
                        break;
                    }
                }
            }
        }
        
        // Process the diff
        $lineIndex = 0;
        foreach ($diff as $line) {
            $rawContent = $line[0];
            $type = $line[1];
            
            if ($type === 0) {
                // Unchanged line
                $lineNum++;
                $content = htmlspecialchars($rawContent);
                if ($content === '') {
                    $content = '&nbsp;';
                }
                $html .= '<div class="diff-line diff-unchanged">';
                $html .= '<span class="diff-line-num">' . $lineNum . '</span>';
                $html .= '<span class="diff-line-content">' . $content . '</span>';
                $html .= '</div>';
            } elseif ($type === 2) {
                // Removed line (from current version)
                $content = $rawContent;
                
                // Check if this line has a pair for inline highlighting
                if (isset($pairs[$lineIndex])) {
                    $pairedIndex = $pairs[$lineIndex];
                    $pairedContent = '';
                    
                    // Find the paired line content
                    $idx = 0;
                    foreach ($diff as $d) {
                        if ($idx === $pairedIndex) {
                            $pairedContent = $d[0];
                            break;
                        }
                        $idx++;
                    }
                    
                    if ($pairedContent) {
                        $content = $this->highlightLineDifferences($rawContent, $pairedContent);
                    } else {
                        $content = htmlspecialchars($rawContent);
                    }
                } else {
                    $content = htmlspecialchars($rawContent);
                }
                
                if ($content === '' || $rawContent === '') {
                    $content = '&nbsp;';
                }
                
                $html .= '<div class="diff-line diff-removed">';
                $html .= '<span class="diff-line-num">-</span>';
                $html .= '<span class="diff-line-content">' . $content . '</span>';
                $html .= '</div>';
            } elseif ($type === 1) {
                // Added line (in the revision)
                $lineNum++;
                $content = $rawContent;
                
                // Check if this line has a pair for inline highlighting
                if (isset($pairs[$lineIndex])) {
                    $pairedIndex = $pairs[$lineIndex];
                    $pairedContent = '';
                    
                    // Find the paired line content
                    $idx = 0;
                    foreach ($diff as $d) {
                        if ($idx === $pairedIndex) {
                            $pairedContent = $d[0];
                            break;
                        }
                        $idx++;
                    }
                    
                    if ($pairedContent) {
                        $content = $this->highlightLineDifferences($rawContent, $pairedContent);
                    } else {
                        $content = htmlspecialchars($rawContent);
                    }
                } else {
                    $content = htmlspecialchars($rawContent);
                }
                
                if ($content === '' || $rawContent === '') {
                    $content = '&nbsp;';
                }
                
                $html .= '<div class="diff-line diff-added">';
                $html .= '<span class="diff-line-num">+</span>';
                $html .= '<span class="diff-line-content">' . $content . '</span>';
                $html .= '</div>';
            }
            
            $lineIndex++;
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    protected function highlightLineDifferences($line1, $line2)
    {
        // Escape HTML first
        $line1 = htmlspecialchars($line1);
        $line2 = htmlspecialchars($line2);
        
        // If lines are very different, just return the escaped line
        similar_text($line1, $line2, $percent);
        if ($percent < 30) {
            return $line1;
        }
        
        // Find common prefix
        $prefixLen = 0;
        $len1 = strlen($line1);
        $len2 = strlen($line2);
        $minLen = min($len1, $len2);
        
        while ($prefixLen < $minLen && $line1[$prefixLen] === $line2[$prefixLen]) {
            $prefixLen++;
        }
        
        // Find common suffix
        $suffixLen = 0;
        while ($suffixLen < ($minLen - $prefixLen) && 
               $line1[$len1 - $suffixLen - 1] === $line2[$len2 - $suffixLen - 1]) {
            $suffixLen++;
        }
        
        // Build result with highlighted middle part
        $result = '';
        
        // Add common prefix
        if ($prefixLen > 0) {
            $result .= substr($line1, 0, $prefixLen);
        }
        
        // Add highlighted different part
        $diffStart = $prefixLen;
        $diffEnd = $len1 - $suffixLen;
        
        if ($diffEnd > $diffStart) {
            $diffPart = substr($line1, $diffStart, $diffEnd - $diffStart);
            $result .= '<span class="diff-highlight">' . $diffPart . '</span>';
        }
        
        // Add common suffix
        if ($suffixLen > 0) {
            $result .= substr($line1, $len1 - $suffixLen);
        }
        
        return $result;
    }
    
    protected function highlightDifferences($line1, $line2, $type)
    {
        // For very different lines, just return the whole line as changed
        similar_text($line1, $line2, $percent);
        if ($percent < 30) {
            return htmlspecialchars($line1);
        }
        
        // Try word-level diff for better granularity
        $words1 = $this->tokenizeLine($line1);
        $words2 = $this->tokenizeLine($line2);
        
        // Use LCS (Longest Common Subsequence) algorithm for word-level diff
        $lcs = $this->computeLCS($words1, $words2);
        $diff = $this->buildWordDiff($words1, $words2, $lcs, $type);
        
        return $diff;
    }
    
    protected function tokenizeLine($line)
    {
        // Split line into words and delimiters, preserving spaces and punctuation
        preg_match_all('/\w+|[^\w]+/', $line, $matches);
        return $matches[0];
    }
    
    protected function computeLCS($words1, $words2)
    {
        $m = count($words1);
        $n = count($words2);
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
        
        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($words1[$i - 1] === $words2[$j - 1]) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }
        
        return $lcs;
    }
    
    protected function buildWordDiff($words1, $words2, $lcs, $type)
    {
        $result = '';
        $i = count($words1);
        $j = count($words2);
        
        $diff = [];
        
        // Backtrack through LCS to build diff
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $words1[$i - 1] === $words2[$j - 1]) {
                // Common word
                array_unshift($diff, ['word' => $words1[$i - 1], 'type' => 'common']);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                // Word only in words2 (added)
                if ($type === 'removed') {
                    array_unshift($diff, ['word' => '', 'type' => 'skip']);
                } else {
                    array_unshift($diff, ['word' => $words2[$j - 1], 'type' => 'changed']);
                }
                $j--;
            } elseif ($i > 0) {
                // Word only in words1 (removed)
                if ($type === 'added') {
                    array_unshift($diff, ['word' => '', 'type' => 'skip']);
                } else {
                    array_unshift($diff, ['word' => $words1[$i - 1], 'type' => 'changed']);
                }
                $i--;
            }
        }
        
        // Build HTML from diff
        foreach ($diff as $item) {
            if ($item['type'] === 'common') {
                $result .= htmlspecialchars($item['word']);
            } elseif ($item['type'] === 'changed') {
                $result .= '<span class="diff-highlight">' . htmlspecialchars($item['word']) . '</span>';
            }
            // Skip 'skip' type
        }
        
        return $result;
    }
    
    protected function highlightDifferences_old($line1, $line2, $type)
    {
        // Find common prefix
        $prefixLen = 0;
        $len1 = mb_strlen($line1);
        $len2 = mb_strlen($line2);
        $minLen = min($len1, $len2);
        
        while ($prefixLen < $minLen && mb_substr($line1, $prefixLen, 1) === mb_substr($line2, $prefixLen, 1)) {
            $prefixLen++;
        }
        
        // Find common suffix
        $suffixLen = 0;
        while ($suffixLen < ($minLen - $prefixLen) && 
               mb_substr($line1, $len1 - $suffixLen - 1, 1) === mb_substr($line2, $len2 - $suffixLen - 1, 1)) {
            $suffixLen++;
        }
        
        // Build result with highlighted middle part
        $result = '';
        
        // Add common prefix
        if ($prefixLen > 0) {
            $result .= htmlspecialchars(mb_substr($line1, 0, $prefixLen));
        }
        
        // Add highlighted different part
        $diffStart = $prefixLen;
        $diffEnd = $len1 - $suffixLen;
        
        if ($diffEnd > $diffStart) {
            $result .= '<span class="diff-highlight">';
            $result .= htmlspecialchars(mb_substr($line1, $diffStart, $diffEnd - $diffStart));
            $result .= '</span>';
        }
        
        // Add common suffix
        if ($suffixLen > 0) {
            $result .= htmlspecialchars(mb_substr($line1, $len1 - $suffixLen));
        }
        
        return $result;
    }
    
    protected function getCurrentContent($revision)
    {
        if ($revision['type'] === 'page') {
            $route = $revision['data']['route'];
            
            // Try to find the page using different methods
            $page = $this->grav['pages']->find($route);
            if (!$page) {
                // Try with leading slash
                $page = $this->grav['pages']->find('/' . ltrim($route, '/'));
            }
            
            if (!$page && isset($revision['file'])) {
                // Try to find the page by its file path
                $revisionFilePath = $revision['file'];
                $pageDir = dirname($revisionFilePath);
                
                // Look for the page file in the same directory
                $pageFiles = ['default.md', 'item.md', 'blog.md', 'modular.md'];
                
                // Check if multilanguage is enabled and add language-specific files
                if ($this->grav['language']->enabled()) {
                    // Extract language from revision filename if present
                    $revisionBase = basename($revisionFilePath);
                    if (preg_match('/\.([a-z]{2})\.md\.\d{8}-\d{6}\.rev$/', $revisionBase, $langMatch)) {
                        $lang = $langMatch[1];
                        // Add language-specific versions to the beginning of the array
                        $langPageFiles = [];
                        foreach ($pageFiles as $baseFile) {
                            $langPageFiles[] = str_replace('.md', '.' . $lang . '.md', $baseFile);
                        }
                        $pageFiles = array_merge($langPageFiles, $pageFiles);
                    }
                }
                
                foreach ($pageFiles as $pageFile) {
                    $pagePath = $pageDir . '/' . $pageFile;
                    if (file_exists($pagePath)) {
                        return file_get_contents($pagePath);
                    }
                }
            }
            
            if ($page) {
                return $page->raw();
            } else {
                return '';
            }
        }
        
        // For config types, load current config file
        
        if ((strpos($revision['type'], 'config') !== false || $revision['type'] === 'unknown') && isset($revision['data']['filePath'])) {
            $configPath = $revision['data']['filePath'];
            
            if (file_exists($configPath)) {
                $content = file_get_contents($configPath);
                return $content;
            }
        }
        
        return '';
    }
    
    protected function createBackupBeforeRestore($revision)
    {
        
        // Create a revision of the current state before restoring
        $metadata = [
            'timestamp' => time(),
            'user' => $this->grav['user']->username ?? 'unknown',
            'type' => $revision['type'],
            'action' => 'backup-before-restore'
        ];
        
        $timestamp = date('Ymd-His');
        
        if ($revision['type'] === 'page') {
            // For pages, create backup of current page
            // Implementation needed
        } elseif (strpos($revision['type'], 'config') !== false && isset($revision['data']['filePath'])) {
            // For config files, create backup of current config
            $configPath = $revision['data']['filePath'];
            if (file_exists($configPath)) {
                
                // Read current content
                $currentContent = file_get_contents($configPath);
                
                // Create backup revision
                $backupFile = $configPath . '.' . $timestamp . '.rev';
                $backupData = [
                    'metadata' => $metadata,
                    'content' => Yaml::parse($currentContent),
                    'rawContent' => $currentContent,
                    'filePath' => $configPath,
                    'type' => $revision['type']
                ];
                
                try {
                    $file = File::instance($backupFile);
                    $file->save(json_encode($backupData, JSON_PRETTY_PRINT));
                    
                    // Update the index
                    $route = str_replace($this->grav['locator']->findResource('user://'), '', $configPath);
                    $this->updateRevisionIndex($route, $revision['type'], $backupFile, $metadata);
                    
                } catch (\Exception $e) {
                }
            }
        }
    }
    
    protected function restorePageRevision($revision)
    {
        $data = $revision['data'];
        $route = $data['route'];
        
        // Try different ways to find the page
        $page = $this->grav['pages']->find($route);
        if (!$page) {
            // Try with leading slash
            $page = $this->grav['pages']->find('/' . ltrim($route, '/'));
        }
        
        if (!$page && isset($revision['file'])) {
            // Try to find page by its file path
            $revisionFilePath = $revision['file'];
            $pageDir = dirname($revisionFilePath);
            
            // Look for the page file in the same directory
            $pageFiles = ['default.md', 'item.md', 'blog.md', 'modular.md'];
            
            // Check if multilanguage is enabled and add language-specific files
            if ($this->grav['language']->enabled()) {
                // Extract language from revision filename if present
                $revisionBase = basename($revisionFilePath);
                if (preg_match('/\.([a-z]{2})\.md\.\d{8}-\d{6}\.rev$/', $revisionBase, $langMatch)) {
                    $lang = $langMatch[1];
                    // Add language-specific versions to the beginning of the array
                    $langPageFiles = [];
                    foreach ($pageFiles as $baseFile) {
                        $langPageFiles[] = str_replace('.md', '.' . $lang . '.md', $baseFile);
                    }
                    $pageFiles = array_merge($langPageFiles, $pageFiles);
                }
            }
            
            foreach ($pageFiles as $pageFile) {
                $pagePath = $pageDir . '/' . $pageFile;
                if (file_exists($pagePath)) {
                    // Create a temporary page object to save the content
                    $page = new Page();
                    $page->filePath($pagePath);
                    break;
                }
            }
        }
        
        if (!$page) {
            return false;
        }
        
        // Get the full content to restore
        $contentToRestore = '';
        
        // Check if this revision has the full raw content
        if (isset($data['rawContent'])) {
            $contentToRestore = $data['rawContent'];
        } elseif (isset($data['content'])) {
            // For older revisions, use the content field
            $contentToRestore = $data['content'];
        }
        
        if (empty($contentToRestore)) {
            return false;
        }
        
        // Restore the full raw content
        $page->raw($contentToRestore);
        
        // Save the page
        try {
            $page->save();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    protected function restoreConfigRevision($revision)
    {
        
        if (!isset($revision['data']['filePath'])) {
            return false;
        }
        
        $targetPath = $revision['data']['filePath'];
        
        // Get the content to restore
        $content = $revision['data']['rawContent'] ?? '';
        if (!$content && isset($revision['data']['content'])) {
            // Convert array back to YAML if needed using Grav's handler
            $content = Yaml::dump($revision['data']['content']);
        }
        
        if (!$content) {
            return false;
        }
        
        try {
            // Write the content back to the config file
            $file = File::instance($targetPath);
            $file->save($content);
            
            
            // Clear Grav's cache so changes take effect
            $this->grav['cache']->clearCache('all');
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}