# v5.2.1
## 03/08/2024

1. [](#bugfix)
   * Set the `seomagic-image` image proxy URL to include a dummy `.jpg` extension.  This is simply to appease the likes of Instagram and Whatsapp, that don't just rely on the MIME type as most providers do. It will still serve a PNG, JPG, or WEBP as required by setting the mimetype appropriately. [getgrav/grav-premium-issues#422](https://github.com/getgrav/grav-premium-issues/issues/422)

# v5.2.0
## 06/14/2023

1. [](#bugfix)
   * Fixed an issue that was stopping page saves from reprocessing SEO-Data correctly [getgrav/grav-premium-issues#364](https://github.com/getgrav/grav-premium-issues/issues/364)

# v5.1.2
## 06/01/2023

1. [](#new)
   * added `onSeoMagicMetadataTitle`, `onSeoMagicMetadataDescription`, and `onSeoMagicMetadataKeywords` events to allow manipulation
1. [](#improved)
   * Fallback metadata `description` to `site.description` if no other description found

# v5.1.1
## 05/09/2023

1. [](#improved)
   * Fixed a deprecated message where `just_grade` filter was added twice
   * Fixed a deprecated message where `null` was being passed to `strip_tags()` function
   * Fixed various issues where casting was required to get by deprecated messages

# v5.1.0
## 05/04/2023

1. [](#improved)
   * Removed asynchronous page processing as it could overload a server and also lead to out-of-memory errors. New synchronous approach is much more reliable and impacts server much less.

# v5.0.3
## 02/03/2023

1. [](#bugfix)
   * Fixed an issue where `content-type` contained more data than just mime type

# v5.0.2
## 01/13/2023

1. [](#new)
   * Added configurable page default metadata locations. Defaults to `metadata.x`

# v5.0.1
## 03/28/2022

3. [](#bugfix)
   * Fixed issue with `$summary` not being defined [getgrav/grav-premium-issues#262](https://github.com/getgrav/grav-premium-issues/issues/262)

# v5.0.0
## 03/28/2022

1. [](#new)
   * Added an image proxy to deal with OpenGraph image-caching issues (e.g. Facebook) [getgrav/grav-premium-issues#237](https://github.com/getgrav/grav-premium-issues/issues/237)
   * Added configurable image ordering [getgrav/grav-premium-issues#246](https://github.com/getgrav/grav-premium-issues/issues/246)
   * Support custom language-based `og-image.XX.png` and `og-image.XX.jpg` images if current language is available [getgrav/grav-premium-issues#249](https://github.com/getgrav/grav-premium-issues/issues/249)
   * Added support for comma-separated `image_name` and `image_attribute` values to serve as fallbacks [#255](https://github.com/getgrav/grav-premium-issues/issues/255)
   * New Description Summarization option of `attribute` to select a page frontmatter field to use as the summary
   * Automatically handle current language file format when using "Image Name" option for images in the format: `myimage.LANG.extension` per the Grav standard
2. [](#improved)
   * Better support for multilang URLs in system reports (Requires Grav v1.7.32)
   * Internal caching of description/keywords generation for better performance.
3. [](#bugfix)
   * Fixed issue with multilevel image attributes [getgrav/grav-premium-issues#256](https://github.com/getgrav/grav-premium-issues/issues/256)

# v4.0.4
## 01/13/2022

1. [](#improved)
   * Added `og:image:width` and `og:image:height` to the output for better support for Facebook
   * Restructured blueprints to use new `elements` field that requires Grav `1.7.27`

# v4.0.3
## 12/28/2021

1. [](#bugfix)
   * Use a version of `deprecation-contracts` library that doesn't require PHP 8.0+


# v4.0.2
## 12/23/2021

1. [](#improved)
   * Upgraded to symfony `v5.4` libs
2. [](#bugfix)
   * Add additional `og:image:secure` attribute to hopefully address Facebook issues

# v4.0.1
## 11/16/2021

1. [](#improved)
   * Only set `og:type` to `website` on homepage, else set to `article` [getgrav/grav-premium-issues#182](https://github.com/getgrav/grav-premium-issues/issues/182)
   
# v4.0.0
## 10/28/2021

1. [](#new)
   * Request Grav `1.7.24` to take advantage of built-in HTTP client and configuration
   * Proxy Support added via Grav `1.7.24` [getgrav/grav-premium-issues#60](https://github.com/getgrav/grav-premium-issues/issues/60)
   * Curl/Fopen configuration support provided by Grav `1.7.24`

# v3.0.3
## 09/30/2021

1. [](#improved)
   * Disabled "Link Checker" by default. You can just enable it on the configuration.
   * Added option to disable "Image Checker" if needed

# v3.0.2
## 09/21/2021

1. [](#improved)
   * Added new features for link-checking functionality including: **Connection count, Link check timeout, Whitelisting, and Configurable retries of failed checks**.
   * Broke out link-checking configuration into its own section in blueprints.

# v3.0.1
## 09/17/2021

1. [](#improved)
   * Added more options for selectable `Image Types`
   * All settings fallback through: `Image Name` → `Image Attribute` → `OG-Image` → `First image found in Page` → `Default URL`

# v3.0.0
## 09/17/2021

1. [](#new)
   * New site-wide SEO-Magic Summary Report that displays all pages, their scores + broken link summary
   * Broken Link Checking functionality. Via CLI, Admin and displayed per page and in site report
2. [](#improved)
   * Configurable HTTP Client timeout
   * Various optimizations and code improvements

# v2.1.3
## 09/01/2021

1. [](#bugfix)
   * Fixed an issue with `image_attribute` not being set properly

# v2.1.2
## 08/18/2021

1. [](#bugfix)
   * Fixed an issue with Admin Quicktray throwing "Invalid AJAX Response" error

# v2.1.1
## 08/04/2021

1. [](#bugfix)
   * Fixed wrong Facebook App ID reference from config, preventing OpenGraph from properly rendering all the details

# v2.1.0
## 07/26/2021

1. [](#new)
   * Improved `image_attribute` support to automatically handle **File** fields 
   * Added a new `bin/plugin seo-magic purge` CLI command to clear out `user-data://seo-magic` data folder
   * Added `ignore_routes:` configuration option to provide routes to skip
1. [](#improved)
   * Refactored and improved the image logic to fallback across all supported image types
   * Simplified generator logic to not rely on HTTP response headers
   * Improved meta keywords + logic functionality to support using default `site.metadata.*` values

# v2.0.3
## 06/14/2021

1. [](#improved)
   * Removed redundant Admin page events as they were being called twice.  Saving is faster now!

# v2.0.2
## 06/13/2021

1. [](#improved)
   * Added Grav error logging in page events
1. [](#bugfix)
   * Fixed an issue caused by multiple references to the same broken image [getgrav/grav-premium-issues#108](https://github.com/getgrav/grav-premium-issues/issues/108)

# v2.0.1
## 06/08/2021

1. [](#bugfix)
   * Fix for saving and regenerating SEO data for homepage with multilang [getgrav/grav-premium-issues#101]([getgrav/grav-premium-issues#28](https://github.com/getgrav/grav-premium-issues/issues/28))
   * Fixed a missing lang string for 'Pre-Transfer'
   * SEO Magic tab is available when unpublished, only SEO Report is not available [getgrav/grav-premium-issues#107](https://github.com/getgrav/grav-premium-issues/issues/107)

# v2.0.0
## 05/23/2021

1. [](#new)
   * **Full multi-language support** when regenerating SEO data
   * Automatically use language-specific **stop-words** when supported (`en`, `fr`, `de`, `it`, `es`, `no`, `ru`, `id`)  for generating keywords and descriptions 
   * Utilize new **SiteMap 3.0**'s `JSON` sitemap for improved performance + multi-language support
   * Automatically determine sitemap URL from sitemap plugin configuration
1. [](#improved)
   * Support of UTF-8 Characters in keywords and description [getgrav/grav-premium-issues#28](https://github.com/getgrav/grav-premium-issues/issues/28)
   * Refactored Metadata report handling to address missing elements and improve performance
   * Updated to the latest version of `TextRank` vendor library
1. [](#bugfix)
   * Fixed issue with empty site description [getgrav/grav-premium-issues#56](https://github.com/getgrav/grav-premium-issues/issues/56)

# v1.0.5
## 04/22/2021

1. [](#improved)
   * English language strings created for all strings in SEO-Magic plugin
  
# v1.0.4
## 04/15/2021

1. [](#bugfix)
   * Fixed a couple of errors related to deleting existing SEO data entries [#87](https://github.com/getgrav/grav-premium-issues/issues/87)

# v1.0.3
## 01/25/2021

1. [](#bugfix)
   * Fixed an issue where body content was not cleaned properly causing issues with keyword + description auto-generation [#21](https://github.com/getgrav/grav-premium-issues/issues/21)
   * Fixed an issue where `Auto-Select Image...` didn't fall back to a "Specified Image" if default image is not found [#20](https://github.com/getgrav/grav-premium-issues/issues/20)

# v1.0.2
## 01/15/2021

1. [](#improved)
   * Only process page events + show tab if not a 'redirect'
1. [](#bugfix)
   * Fixed an issue with parsing `DomDocument`

# v1.0.1
## 12/17/2020

1. [](#improved)
   * Only process page events if 'published' and 'visible' [#4](https://github.com/getgrav/grav-premium-issues/issues/4)

# v1.0.0
## 12/14/2020

1. [](#new)
   * Initial Release
