# v1.2.0
## 12/02/2025

1. [](#bugfix)
   * Fix missing `video` support in new shortcode syntax
   * Fix extra `<p>` tags getting put around thumbnails breaking layout
   * Fix for empty 'content' in lightbox shortcode throwing JS error

# v1.1.1
## 11/23/2025

1. [](#bugfix)
    * Fixed some bad logic in the lightbox shortcode that was not properly detecting content vs image accurately and was fallign back to using full size image.

# v1.1.0
## 11/23/2025

1. [](#new)
    * Much improved shortcode support with parent/child `[lightbox-gallery]` → `[lightbox]` shortcodes (see docs for details)
    * Editor-Pro support of new shortcodes for true WYSIWG editor
1. [](#improved)
   * Updated GLightbox to `v3.3.1`
   
# v1.0.6
## 08/24/2023

1. [](#improved)
   * Updated GLightbox to `v3.2.0`
   * Load `glightbox.min.js` in bottom group
   * Fixed a deprecated message regarding null's being passed to `md5()` function
1. [](#bugfix)
   * Fixed a bug with the wrong page being used when shortcode is processed in a modular sub-page. [getgrav/grav-premium-issues#382](https://github.com/getgrav/grav-premium-issues/issues/382)

# v1.0.5
## 05/26/2021

1. [](#bugfix)
   * Upgraded GLightbox library to fix issue with images types where width/height values would get ignored

# v1.0.4
## 04/09/2021

1. [](#new)
    * Fixed Videos not pausing while switching slide by updating to latest GLightbox `v3.0.7` library

# v1.0.3
## 02/25/2021

1. [](#new)
    * Added basic Nextgen shortcode integration
    * Added support for separate `image` and `video` tags in shortcode + Twig partial

# v1.0.2
## 12/14/2020

1. [](#bugfix)
    * Only process if valid page

# v1.0.1
## 09/11/2020

1. [](#new)
    * Initial Release...
