<?php

namespace Grav\Plugin\Shortcodes;

use Thunder\Shortcode\Shortcode\ShortcodeInterface;

class LightboxGalleryShortcode extends Shortcode
{
    public function init()
    {
        // Gallery Wrapper
        $this->shortcode->getHandlers()->add('lightbox-gallery', function(ShortcodeInterface $sc) {
            
            $gallery_name = $sc->getParameter('gallery');
            if (!$gallery_name) {
                $gallery_name = 'gallery-' . substr(md5(uniqid()), 0, 8);
            }

            $thumb_options = $sc->getParameter('thumb-options') ?: $sc->getParameter('thumb_options');

            // Process content (this will run child shortcodes first, returning their HTML)
            $content = $this->shortcode->processShortcodes($sc->getContent());
            
            // 1. Extract and Move Descriptions
            $descriptions = [];
            $content = preg_replace_callback('~<div\s+class="[^"]*glightbox-desc\s+([^\"]+)"[^>]*>.*?</div>~s', function($matches) use (&$descriptions) {
                $descriptions[] = $matches[0];
                return ''; 
            }, $content);

            // 2. Fix-up Anchors (Thumbnails and Gallery ID)
            $page = $this->shortcode->getPage();
            
            $content = preg_replace_callback('~<a\s+([^>]*class="[^"]*glightbox[^"]*"[^>]*)>(.*?)</a>~s', function($matches) use ($gallery_name, $thumb_options, $page) {
                $attrs = $matches[1];
                $inner = $matches[2];

                // Detect custom thumb and filename
                $thumb_attr = '';
                if (preg_match('~data-thumb="([^"]*)"~', $attrs, $t_match)) {
                    $thumb_attr = $t_match[1];
                }
                // Capture any existing img src rendered from markdown/content
                $inner_img_src = '';
                if (preg_match('~<img[^>]+src="([^"]+)"~', $inner, $img_match)) {
                    $inner_img_src = $img_match[1];
                }

                if (preg_match('~data-filename="([^"]+)"~', $attrs, $f_match)) {
                    $filename = $f_match[1];
                    // Choose which path to process: custom thumb (if provided) or main image
                    $target_for_options = $thumb_attr ?: $filename;
                    $apply_options = ($thumb_attr === '') ? $thumb_options : null; // parent options only when no custom thumb

                    $new_thumb_url = null;

                    if ($inner_img_src) {
                        // If markdown already produced an image (legacy mode), prefer it.
                        // Only override when parent thumb-options exist or custom thumb is set.
                        if ($thumb_attr === '' && empty($thumb_options)) {
                            $new_thumb_url = null; // leave as-is
                        } elseif (strpos($inner_img_src, '?') !== false) {
                            $new_thumb_url = $inner_img_src; // keep existing query-based thumb
                        }
                    }

                    // If we still need a thumb URL, build it from target/options
                    if ($new_thumb_url === null) {
                        $new_thumb_url = $this->buildThumbUrl($page, $target_for_options, $apply_options);
                    }

                    if ($new_thumb_url) {
                        // Replace img src
                        $inner = preg_replace('~(<img\s+[^>]*src=")([^"]+)("[^>]*>)~', '$1' . $new_thumb_url . '$3', $inner);
                    }
                }

                // Update data-gallery attribute
                if (strpos($attrs, 'data-gallery=') !== false) {
                    $attrs = preg_replace('~data-gallery="[^"]*"~', 'data-gallery="' . $gallery_name . '"', $attrs);
                } else {
                    $attrs .= ' data-gallery="' . $gallery_name . '"';
                }

                return '<a ' . $attrs . '>' . $inner . '</a>';

            }, $content);

            // Append collected descriptions
            if (!empty($descriptions)) {
                $content .= implode('', $descriptions);
            }

            $class = $sc->getParameter('class');
            $id = $sc->getParameter('id');
            $attrs = '';
            if ($class) $attrs .= ' class="'.$class.'"';
            if ($id) $attrs .= ' id="'.$id.'"';

            return '<div'.$attrs.'>'.$content.'</div>';
        });

        // Lightbox Item
        $this->shortcode->getHandlers()->add('lightbox', function(ShortcodeInterface $sc) {
            // Standard processing
            $image = $sc->getParameter('image');
            $thumb = $sc->getParameter('thumb');
            $content = trim($this->unindent($sc->getContent()));
            
            // Determine Mode (new vs legacy)
            $is_desc_mode = false;
            $thumbnail_media = null;
            if ($thumb !== null) {
                // Explicit thumb provided: treat content as description, thumb as trigger
                $thumb_str = $thumb ?: $image;
                $thumbnail_media = $this->getMedia($thumb_str);
                $is_desc_mode = true;
            } elseif ((strpos($content, '<img') === false && strpos($content, '![') === false) && $image) {
                // No image in content; fallback to image as thumb/trigger
                $thumbnail_media = $this->getMedia($image);
                $is_desc_mode = true;
            }
            $desc_id = 'gl-desc-' . substr(md5(uniqid()), 0, 8);
            $gallery = $sc->getParameter('gallery') ?: md5((string) $image);

            return $this->twig->processTemplate(
                'partials/lightbox.html.twig',
                [
                    'page'            => $this->shortcode->getPage(),
                    'content'         => $content,
                    'image'           => $image,
                    'thumbnail_media' => $thumbnail_media,
                    'thumb'           => $thumb,
                    'desc_id'         => $desc_id,
                    'gallery'         => $gallery,
                    'class'           => $sc->getParameter('class'),
                    'title'           => $sc->getParameter('title'),
                    'descPosition'    => $sc->getParameter('descPosition'),
                    'type'            => $sc->getParameter('type'),
                    'effect'          => $sc->getParameter('effect'),
                    'width'           => $sc->getParameter('width'),
                    'height'          => $sc->getParameter('height'),
                    'zoomable'        => $sc->getParameter('zoomable'),
                    'draggable'       => $sc->getParameter('draggable'),
                    'thumb'           => $thumb,
                    'only_desc'       => false,
                    'only_anchor'     => false, 
                ]
            );
        });
    }

    // Helper to find media object
    protected function findMediaObject($page, $filename) {
        $media = $page->media()->get($filename);
        if (!$media) {
            $media = $page->media()->get(rawurldecode($filename));
        }
        if (!$media) {
            $media_collection = $page->media()->all();
            $filename_lower = strtolower($filename);
            foreach ($media_collection as $name => $medium) {
                if (strtolower($name) === $filename_lower) {
                    $media = $medium;
                    break;
                }
            }
        }
        return $media;
    }

    protected function getMedia($path)
    {
        $page = $this->shortcode->getPage();
        $parts = explode('?', $path, 2);
        $filename = $parts[0];
        $query = $parts[1] ?? '';
        
        $media = $this->findMediaObject($page, $filename);

        if (!$media) {
            return $path; 
        }

        if ($query) {
             parse_str($query, $params);
             foreach ($params as $action => $args) {
                 try {
                      if ($args !== '') {
                          $arguments = array_map('trim', explode(',', $args));
                          $media = $media->$action(...$arguments);
                      } else {
                          $media = $media->$action();
                      }
                 } catch (\Exception $e) {}
             }
        }
        return $media;
    }

    /**
     * Build a thumbnail URL by applying either inline query params or provided options.
     * If options are provided, they are applied only when the target has no existing query string.
     */
    protected function buildThumbUrl($page, $target, $options = null)
    {
        // Split target into path and inline query (if any)
        $parts = explode('?', $target, 2);
        $filename = $parts[0];
        $query = $parts[1] ?? '';

        $media = $this->findMediaObject($page, $filename);
        if (!$media) {
            return $target; // Fallback to raw path
        }

        // If the target already has inline transformations, honor those first
        if ($query !== '') {
            parse_str($query, $params);
            foreach ($params as $action => $args) {
                try {
                    if ($args !== '') {
                        $arguments = array_map('trim', explode(',', $args));
                        $media = $media->$action(...$arguments);
                    } else {
                        $media = $media->$action();
                    }
                } catch (\Exception $e) {}
            }
            return $media->url();
        }

        // Otherwise, apply provided options (typically from parent thumb-options)
        if ($options) {
            parse_str($options, $params);
            foreach ($params as $action => $args) {
                try {
                    if ($args !== '') {
                        $arguments = array_map('trim', explode(',', $args));
                        $media = $media->$action(...$arguments);
                    } else {
                        $media = $media->$action();
                    }
                } catch (\Exception $e) {}
            }
        }

        return $media->url();
    }

    private function unindent($text)
    {
        $lines = explode("\n", $text);
        $min_indent = null;
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $indent = strlen($line) - strlen(ltrim($line));
            if ($min_indent === null || $indent < $min_indent) {
                $min_indent = $indent;
            }
        }
        if ($min_indent > 0) {
            foreach ($lines as &$line) {
                if (strlen($line) >= $min_indent) {
                    $line = substr($line, $min_indent);
                }
            }
        }
        return implode("\n", $lines);
    }
}
