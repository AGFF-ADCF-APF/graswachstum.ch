<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Plugin;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class LightboxGalleryPlugin
 * @package Grav\Plugin
 */
class LightboxGalleryPlugin extends Plugin
{
    protected $configuration;

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
                ['autoload', 100000], // TODO: Remove when plugin requires Grav >=1.7
                ['onPluginsInitialized', 0]
            ],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onShortcodeHandlers' => ['onShortcodeHandlers', 0],
            'registerEditorProPlugin' => ['registerEditorProPlugin', 0],
            'onEditorProShortcodeRegister' => ['onEditorProShortcodeRegister', 0],
            'onEditorProExtractPaths' => ['onEditorProExtractPaths', 0],
        ];
    }

    /**
    * Composer autoload.
    *is
    * @return ClassLoader
    */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        $extension = $this->grav['uri']->extension();
        $allowed_extensions = [null, 'html','htm'];
        if ($this->isAdmin()) {
            $this->enable([
                'registerNextGenEditorPlugin' => ['registerNextGenEditorPluginShortcodes', 0],
            ]);
        }

        if ($this->isAdmin() || defined('GRAV_CLI') || !in_array($extension, $allowed_extensions)) {
            return;
        }

        // Enable the main events we are interested in
        $this->enable([
            'onTwigSiteVariables' => ['onTwigVariables', 0],
        ]);

        $this->configuration = $this->config();
    }

    /**
     * Initialize configuration
     */
    public function onTwigVariables(Event $e)
    {
        /** @var Page $page */
        $page = $e['page'] ?? $this->grav['page'] ?? null;

        if ($page && ($page instanceof PageInterface) && isset($page->header()->lightbox)) {
            if (is_array($page->header()->lightbox)) {
                $this->configuration['active'] = true;
                $this->configuration = Utils::arrayMergeRecursiveUnique($this->config(), $page->header()->lightbox);
            }
        }

        if ($this->configuration['active'] && $this->configuration['autoIncludeAssets']) {
            $this->addAssets();
        }

        $this->grav['twig']->twig_vars['lightbox_gallery'] = $this;
    }

    public function addAssets()
    {
        $this->grav['assets']->addCss('plugin://lightbox-gallery/css/glightbox.min.css');
        $this->grav['assets']->addJs('plugin://lightbox-gallery/js/glightbox.min.js', ['group' => 'bottom']);

        $options = json_encode($this->getOptions());
        $inline = "const lightbox = GLightbox({$options});";
        $this->grav['assets']->addInlineJs($inline, null, 'bottom');
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    protected function getOptions() {
        $options = [];

        $defaults = [
            'selector' => '.glightbox',
            'elements' => null,
            'skin' => 'clean',
            'openEffect' => 'zoom',
            'closeEffect' => 'zoom',
            'slideEffect' => 'slide',
            'moreText' => 'See more',
            'moreLength' => 60,
            'closeButton' => true,
            'touchNavigation' => true,
            'touchFollowAxis' => true,
            'keyboardNavigation' => true,
            'closeOnOutsideClick' => true,
            'startAt' => 0,
            'width' => '900px',
            'height' => '506px',
            'videosWidth' => '960px',
            'descPosition' => 'bottom',
            'loop' => false,
            'zoomable' => true,
            'draggable' => true,
            'dragToleranceX' => 40,
            'dragToleranceY' => 65,
            'dragAutoSnap' => false,
            'preload' => true,
            'autoplayVideos' => true,
            'autofocusVideos' => false,
        ];

        foreach ($defaults as $option => $default) {
            if (isset($this->configuration['options'][$option]) && $default !== $this->configuration['options'][$option]) {
                $options[$option] = $this->configuration['options'][$option];
            }
        }
        return $options;

    }

    /**
     * Initialize configuration
     */
    public function onShortcodeHandlers()
    {
        $this->grav['shortcode']->registerAllShortcodes(__DIR__ . '/classes/shortcodes');
    }

    public function registerNextGenEditorPluginShortcodes($event) {
        $plugins = $event['plugins'];
        $plugins['js'][] = 'plugin://lightbox-gallery/nextgen-editor/shortcodes/lightbox/lightbox.js';
        $event['plugins']  = $plugins;
        return $event;
    }

    /**
     * Register Editor Pro plugin JavaScript
     */
    public function registerEditorProPlugin(Event $event)
    {
        $plugins = $event['plugins'];
        $plugins['js'][] = 'plugin://lightbox-gallery/editor-pro/lightbox-integration.js';
        $event['plugins'] = $plugins;
        return $event;
    }

    /**
     * Register lightbox shortcodes with Editor Pro
     */
    public function onEditorProShortcodeRegister(Event $event)
    {
        $shortcodes = $event['shortcodes'];

        // Register [lightbox] shortcode
        $shortcodes[] = [
            'name' => 'lightbox',
            'title' => 'Lightbox',
            'type' => 'block',
            'hasContent' => true,
            'attributes' => [
                'image' => [
                    'type' => 'text',
                    'title' => 'Image',
                    'description' => 'Image filename (e.g., image.jpg)',
                    'placeholder' => 'photo.jpg'
                ],
                'video' => [
                    'type' => 'text',
                    'title' => 'Video',
                    'description' => 'Video URL or filename',
                    'placeholder' => 'https://www.youtube.com/watch?v=...'
                ],
                'thumb' => [
                    'type' => 'text',
                    'title' => 'Custom Thumbnail',
                    'description' => 'Custom thumbnail image',
                    'placeholder' => 'thumbnail.jpg'
                ],
                'class' => [
                    'type' => 'text',
                    'title' => 'CSS Class',
                    'default' => '',
                    'placeholder' => 'my-custom-class'
                ],
                'gallery' => [
                    'type' => 'text',
                    'title' => 'Gallery ID',
                    'description' => 'Group lightboxes together',
                    'placeholder' => 'gallery-1'
                ],
                'title' => [
                    'type' => 'text',
                    'title' => 'Title',
                    'description' => 'Lightbox title',
                    'placeholder' => 'My Image Title'
                ],
                'desc' => [
                    'type' => 'textarea',
                    'title' => 'Description',
                    'description' => 'Description text (legacy)',
                    'placeholder' => 'Image description text...'
                ],
                'descPosition' => [
                    'type' => 'select',
                    'title' => 'Description Position',
                    'options' => [
                        '' => 'Default',
                        'bottom' => 'bottom',
                        'top' => 'top',
                        'left' => 'left',
                        'right' => 'right'
                    ],
                    'default' => ''
                ],
                'width' => [
                    'type' => 'text',
                    'title' => 'Width',
                    'description' => 'Lightbox width (e.g., 900px)',
                    'placeholder' => '900px'
                ],
                'height' => [
                    'type' => 'text',
                    'title' => 'Height',
                    'description' => 'Lightbox height (e.g., 506px)',
                    'placeholder' => '506px'
                ],
                'zoomable' => [
                    'type' => 'select',
                    'title' => 'Zoomable',
                    'options' => [
                        '' => 'Default',
                        'true' => 'true',
                        'false' => 'false'
                    ],
                    'default' => ''
                ],
                'draggable' => [
                    'type' => 'select',
                    'title' => 'Draggable',
                    'options' => [
                        '' => 'Default',
                        'true' => 'true',
                        'false' => 'false'
                    ],
                    'default' => ''
                ],
                'type' => [
                    'type' => 'text',
                    'title' => 'Type',
                    'description' => 'Content type override',
                    'placeholder' => 'image, video, inline, or external'
                ],
                'effect' => [
                    'type' => 'text',
                    'title' => 'Effect',
                    'description' => 'Transition effect',
                    'placeholder' => 'fade, zoom, slide, none'
                ]
            ],
            'titleBarAttributes' => ['image', 'video', 'title'],
            'presave' => "function(blockData) {
                // Remove empty attributes to keep shortcode clean
                if (blockData.attributes) {
                    Object.keys(blockData.attributes).forEach(key => {
                        const val = blockData.attributes[key];
                        if (val === '' || val === null || val === undefined) {
                            delete blockData.attributes[key];
                        }
                    });
                }
                return blockData;
            }",
            'customRenderer' => "function(blockData, config) {
                // Ensure attributes object exists
                const attrs = blockData.attributes || {};

                // Get attributes and trim whitespace
                const imageName = (attrs.image || '').trim();
                const videoName = (attrs.video || '').trim();
                const thumbName = (attrs.thumb || '').trim();
                const title = (attrs.title || '').trim();
                const cssClass = (attrs.class || '').trim();
                const gallery = (attrs.gallery || '').trim();
                const editorInstance = (window.EditorPro && window.EditorPro.activeEditor) ? window.EditorPro.activeEditor : null;
                const previewId = 'lightbox-thumb-' + (blockData.blockId || blockData.id || Math.random().toString(36).slice(2, 8));

                // Helper to escape HTML
                function escapeHtml(unsafe) {
                    if (!unsafe) return '';
                    return unsafe
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/\"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                // Helper function to resolve image path with transformations
                function resolveImagePath(filename, parentThumbOptions) {
                    if (!filename) return null;

                    // Access path mappings from Editor Pro
                    const editor = window.EditorPro && window.EditorPro.activeEditor ? window.EditorPro.activeEditor : null;
                    const pathMappings = editor && editor.pathMappings && editor.pathMappings.images ? editor.pathMappings.images : {};

                    // Internal helper to find a path in mappings (Exact -> Base -> Fuzzy)
                    function findInMappings(searchKey) {
                        // 1. Exact match
                        if (pathMappings[searchKey]) {
                            return pathMappings[searchKey].resolved || pathMappings[searchKey].url;
                        }
                        
                        // 2. Fuzzy match (ends with)
                        const keys = Object.keys(pathMappings);
                        for (var i = 0; i < keys.length; i++) {
                            if (keys[i].endsWith('/' + searchKey) || keys[i] === searchKey) {
                                return pathMappings[keys[i]].resolved || pathMappings[keys[i]].url;
                            }
                        }
                        return null;
                    }

                    // Strategy A: Try the filename exactly as provided
                    let resolved = findInMappings(filename);
                    if (resolved) return resolved;

                    // Strategy B: If filename has query params, strip them and try base filename
                    if (filename.includes('?')) {
                        const parts = filename.split('?');
                        const baseFilename = parts[0];
                        const queryParams = parts[1]; // Use the provided params, not parent options!
                        
                        // Try to resolve the base filename (exact or fuzzy)
                        const resolvedBase = findInMappings(baseFilename);
                        if (resolvedBase) {
                            // Append the explicit params from the filename
                            const separator = resolvedBase.includes('?') ? '&' : '?';
                            return resolvedBase + separator + queryParams;
                        }
                    }

                    // Strategy C: If parent has thumb-options, try combining
                    if (parentThumbOptions && !filename.includes('?')) {
                        const withOptions = filename + '?' + parentThumbOptions;
                        if (pathMappings[withOptions]) {
                             return pathMappings[withOptions].resolved || pathMappings[withOptions].url;
                        }
                        // If exact combination fails, fall back to base filename (uncropped)
                        const resolvedBase = findInMappings(filename);
                        if (resolvedBase) {
                             return resolvedBase;
                        }
                    }

                    // Strategy D: Common fallback variations
                    const variations = [
                        filename + '?cropZoom=100,100',
                        filename + '?cropZoom=200,200'
                    ];
                    for (var i = 0; i < variations.length; i++) {
                        if (pathMappings[variations[i]]) {
                            return pathMappings[variations[i]].resolved || pathMappings[variations[i]].url;
                        }
                    }

                    return null;
                }

                // Try to get parent gallery's thumb-options
                let parentThumbOptions = null;
                if (blockData.parent && blockData.parent.tagName === 'lightbox-gallery' && blockData.parent.attributes) {
                    parentThumbOptions = blockData.parent.attributes['thumb-options'];
                }

                // Build badges array
                const badges = [];
                if (thumbName) badges.push({label: 'Custom Thumb', val: thumbName});
                if (cssClass) badges.push({label: 'Class', val: cssClass});
                if (gallery) badges.push({label: 'Gallery', val: gallery});

                // Build preview HTML
                let html = '<div style=\"padding: 6px; background: transparent;\">';
                html += '<div style=\"display: flex; align-items: start; gap: 10px;\">';
                let usedFallbackImage = false;
                let resolvedPath = null;

                if (imageName || thumbName) {
                    // Logic: 
                    // 1. If thumbName exists, try to resolve it.
                    // 2. If that fails (or no thumbName), try to resolve imageName.
                    // 3. This ensures we fallback to the main image if the custom thumb path is invalid/unknown.
                    
                    let previewImage = imageName; // Default to showing image name in fallback

                    if (thumbName) {
                        resolvedPath = resolveImagePath(thumbName, null); // Thumb has priority, no parent options
                        previewImage = thumbName;
                    }
                    
                    // If thumb resolution failed (or didn't exist), try the main image
                    if (!resolvedPath && imageName) {
                        // Use parent options only if we are falling back to the main image and it has no params
                        const useParentOptions = (parentThumbOptions && !imageName.includes('?'));
                        resolvedPath = resolveImagePath(imageName, useParentOptions ? parentThumbOptions : null);
                        // If we fell back to image, update the 'raw' preview text to match
                        if (!thumbName) {
                            previewImage = imageName; 
                        } else {
                            usedFallbackImage = true;
                        }
                    }

                    // Image Container
                    html += '<div style=\"position: relative; width: 60px; height: 45px; margin-top: 2px;\">';
                    
                    if (resolvedPath) {
                        // Primary: Resolved Path
                        html += '<img data-preview-id=\"' + previewId + '\" src=\"' + resolvedPath + '\" style=\"width: 100%; height: 100%; object-fit: cover; border-radius: 3px; box-shadow: 0 1px 2px rgba(0,0,0,0.2);\" onerror=\"this.style.display=\\'none\\'; this.nextElementSibling.style.display=\\'flex\\'\" />';
                        // Fallback Icon
                        html += '<div style=\"display: none; width: 100%; height: 100%; background: rgba(255,255,255,0.1); border-radius: 3px; align-items: center; justify-content: center; font-size: 20px;\">🖼️</div>';
                    } else {
                        // Secondary: Try Raw Filename (last resort for relative paths)
                        // Use previewImage (which is thumb or image)
                        html += '<img data-preview-id=\"' + previewId + '\" src=\"' + previewImage + '\" style=\"width: 100%; height: 100%; object-fit: cover; border-radius: 3px; box-shadow: 0 1px 2px rgba(0,0,0,0.2);\" onerror=\"this.style.display=\\'none\\'; this.nextElementSibling.style.display=\\'flex\\'\" />';
                        html += '<div style=\"display: none; width: 100%; height: 100%; background: rgba(255,255,255,0.1); border-radius: 3px; align-items: center; justify-content: center; font-size: 20px;\">🖼️</div>';
                    }
                    html += '</div>';

                    html += '<div style=\"flex: 1; min-width: 0;\">'; 
                    html += '<div style=\"font-weight: 500; font-size: 13px; color: rgba(255,255,255,0.95); margin-bottom: 2px; line-height: 1.3;\">' + escapeHtml(title || 'Image Lightbox') + '</div>';
                    
                    html += '<div style=\"display: flex; flex-wrap: wrap; align-items: center; gap: 6px; row-gap: 4px;\">';
                    html += '<span style=\"font-size: 11px; color: rgba(255,255,255,0.7); margin-right: 4px;\" title=\"' + escapeHtml(imageName) + '\">' + escapeHtml(imageName) + '</span>';
                    
                    for (var i = 0; i < badges.length; i++) {
                        html += '<span style=\"font-size: 10px; color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.2); padding: 1px 5px; border-radius: 3px; white-space: nowrap;\">' + 
                                (badges[i].label === 'Custom Thumb' ? 'Thumb' : escapeHtml(badges[i].label + ': ' + badges[i].val)) + 
                                '</span>';
                    }
                    html += '</div>';
                    html += '</div>';

                } else if (videoName) {
                    html += '<div style=\"width: 60px; height: 45px; background: rgba(255,255,255,0.1); border-radius: 3px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin-top: 2px;\">🎥</div>';
                    html += '<div style=\"flex: 1; min-width: 0;\">';
                    html += '<div style=\"font-weight: 500; font-size: 13px; color: rgba(255,255,255,0.95); margin-bottom: 2px; line-height: 1.3;\">' + escapeHtml(title || 'Video Lightbox') + '</div>';
                    html += '<div style=\"display: flex; flex-wrap: wrap; align-items: center; gap: 6px; row-gap: 4px;\">';
                    html += '<span style=\"font-size: 11px; color: rgba(255,255,255,0.7); margin-right: 4px;\">' + escapeHtml(videoName) + '</span>';
                    for (var i = 0; i < badges.length; i++) {
                         html += '<span style=\"font-size: 10px; color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.2); padding: 1px 5px; border-radius: 3px; white-space: nowrap;\">' + escapeHtml(badges[i].label + ': ' + badges[i].val) + '</span>';
                    }
                    html += '</div>';
                    html += '</div>';
                } else {
                    html += '<div style=\"flex: 1; padding: 4px; color: rgba(255,255,255,0.6); font-size: 12px; font-style: italic;\">⚠️ No image or video specified</div>';
                }

                // If we have a custom thumb that wasn't resolved from cached mappings, resolve it asynchronously
                const thumbHasParams = thumbName.includes('?');
                const hasCachedThumb = editorInstance && editorInstance.pathMappings && editorInstance.pathMappings.images && editorInstance.pathMappings.images[thumbName];
                const needsThumbResolve = thumbName && editorInstance && typeof editorInstance.resolveImageUrl === 'function' && (!hasCachedThumb || thumbHasParams);
                
                if (needsThumbResolve && !hasCachedThumb) {
                    window.__lightboxThumbResolveCache = window.__lightboxThumbResolveCache || new Set();
                    if (!window.__lightboxThumbResolveCache.has(thumbName)) {
                        window.__lightboxThumbResolveCache.add(thumbName);
                        setTimeout(() => {
                            editorInstance.resolveImageUrl(thumbName).then(resolvedHtml => {
                                const parser = new DOMParser();
                                const doc = parser.parseFromString(resolvedHtml, 'text/html');
                                const img = doc.querySelector('img');
                                const resolvedSrc = img ? (img.getAttribute('src') || thumbName) : thumbName;

                                // Update path mappings for future renders
                                editorInstance.pathMappings = editorInstance.pathMappings || { images: {}, links: {} };
                                if (!editorInstance.pathMappings.images) {
                                    editorInstance.pathMappings.images = {};
                                }
                                editorInstance.pathMappings.images[thumbName] = {
                                    resolved: resolvedSrc,
                                    original: thumbName,
                                    data_src: thumbName,
                                    html: resolvedHtml
                                };

                                // Swap the preview to the resolved URL if available
                                const previewEl = document.querySelector('[data-preview-id=\"' + previewId + '\"]');
                                if (previewEl && resolvedSrc) {
                                    previewEl.src = resolvedSrc;
                                    previewEl.style.display = 'block';
                                    const fallbackIcon = previewEl.nextElementSibling;
                                    if (fallbackIcon) {
                                        fallbackIcon.style.display = 'none';
                                    }
                                }
                            }).catch(() => {
                                // Ignore errors and keep the fallback preview
                            }).finally(() => {
                                window.__lightboxThumbResolveCache.delete(thumbName);
                            });
                        }, 0);
                    }
                }

                html += '</div>';
                html += '</div>';
                return html;
            }"

        ];

        // Register [lightbox-gallery] wrapper shortcode
        $shortcodes[] = [
            'name' => 'lightbox-gallery',
            'title' => 'Lightbox Gallery',
            'type' => 'block',
            'hasContent' => true,
            'allowedChildren' => ['lightbox'],
            'attributes' => [
                'thumb-options' => [
                    'type' => 'text',
                    'title' => 'Thumbnail Options',
                    'description' => 'Image transformation options (e.g., cropZoom=100,100)',
                    'default' => '',
                    'placeholder' => 'cropZoom=200,200'
                ],
                'class' => [
                    'type' => 'text',
                    'title' => 'CSS Class',
                    'description' => 'CSS classes for gallery wrapper',
                    'default' => '',
                    'placeholder' => 'grid grid-cols-3 gap-4'
                ],
                'id' => [
                    'type' => 'text',
                    'title' => 'ID',
                    'description' => 'HTML ID attribute',
                    'default' => '',
                    'placeholder' => 'my-gallery'
                ],
                'gallery' => [
                    'type' => 'text',
                    'title' => 'Gallery Name',
                    'description' => 'Custom gallery identifier',
                    'default' => '',
                    'placeholder' => 'gallery-1'
                ]
            ],
            'titleBarAttributes' => ['thumb-options', 'class'],
            'customRenderer' => "function(blockData, config) {
                const thumbOptions = blockData.attributes['thumb-options'] || '';
                const cssClass = blockData.attributes.class || '';

                let html = '<div style=\"padding: 6px 8px; background: transparent; display: flex; flex-wrap: wrap; align-items: center; gap: 8px;\">';
                html += '<div style=\"font-weight: 600; font-size: 13px; color: rgba(255,255,255,0.95);\">Lightbox Gallery</div>';

                if (thumbOptions) {
                    html += '<div style=\"font-size: 11px; color: rgba(255,255,255,0.85); background: rgba(255,255,255,0.2); padding: 2px 6px; border-radius: 3px;\"><span style=\"opacity:0.7\">Thumb Options:</span> ' + thumbOptions + '</div>';
                }
                if (cssClass) {
                    html += '<div style=\"font-size: 11px; color: rgba(255,255,255,0.85); background: rgba(255,255,255,0.2); padding: 2px 6px; border-radius: 3px;\"><span style=\"opacity:0.7\">Class:</span> ' + cssClass + '</div>';
                }

                html += '</div>';

                return html;
            }"
        ];

        $event['shortcodes'] = $shortcodes;
        return $event;
    }

    /**
     * Extract lightbox gallery image paths for Editor Pro
     * This event handler adds lightbox shortcode images to the path resolution system
     */
    public function onEditorProExtractPaths(Event $event)
    {
        $this->grav['log']->debug('[Lightbox Gallery] onEditorProExtractPaths called');

        $content = $event['content'];
        $imageMatches = $event['imageMatches'];

        // Extract images from shortcode attributes (e.g., image="file.jpg", thumb="file.jpg")
        preg_match_all('/(?:image|thumb|video)=["\']([^"\']+)["\']/', $content, $shortcodeImageMatches, PREG_SET_ORDER);

        // Extract thumb-options from lightbox-gallery wrappers to apply to child images
        $thumbOptionsMap = [];
        if (preg_match_all('/\[lightbox-gallery[^\]]*thumb-options=["\']([^"\']+)["\'][^\]]*\](.*?)\[\/lightbox-gallery\]/s', $content, $galleryMatches, PREG_SET_ORDER)) {
            foreach ($galleryMatches as $galleryMatch) {
                $thumbOptions = $galleryMatch[1]; // e.g., "cropZoom=100,100"
                $galleryContent = $galleryMatch[2];

                // Find all images within this gallery
                if (preg_match_all('/(?:image|thumb)=["\']([^"\']+)["\']/', $galleryContent, $galleryImageMatches)) {
                    foreach ($galleryImageMatches[1] as $galleryImage) {
                        // Store base filename without query params for the map
                        $baseFilename = preg_replace('/\?.*$/', '', $galleryImage);
                        $thumbOptionsMap[$baseFilename] = $thumbOptions;
                    }
                }
            }
        }

        // Add shortcode images to the imageMatches array with empty alt text
        foreach ($shortcodeImageMatches as $match) {
            $filename = $match[1];
            // Only process if it looks like an image file (not a URL)
            if (!preg_match('#^https?://#', $filename) && preg_match('/\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i', $filename)) {
                // Check if this image has thumb-options from a parent gallery
                // Only apply parent thumb-options if the image doesn't already have query params
                $imageWithOptions = $filename;
                if (!strpos($filename, '?')) {
                    $baseFilename = $filename;
                    if (isset($thumbOptionsMap[$baseFilename])) {
                        $imageWithOptions = $filename . '?' . $thumbOptionsMap[$baseFilename];
                    }
                }
                
                // Always add the specific requested version
                $imageMatches[] = ['', '', $imageWithOptions];
                
                // If the version we just added had options appended, ALSO add the raw base filename
                // This ensures the JS renderer can always resolve the base file for dynamic previews
                if ($imageWithOptions !== $filename) {
                    $imageMatches[] = ['', '', $filename];
                }
            }
        }

        // Set the modified array back on the event
        $event['imageMatches'] = $imageMatches;
    }
}
