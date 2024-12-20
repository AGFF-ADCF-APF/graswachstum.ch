# v2.4.7
## 11/06/2024

1. [](#improved)
   * Added some basic login form styling (disable login's built-in CSS first) [getgrav/grav-premium-issues#433](https://github.com/getgrav/grav-premium-issues/issues/433)
   * Updated to latest Tailwind `v3.4.14` + other Tailwind plugins updates
   * Added `dev` (`watch`) and `prod` (`build`) alias commands for development 
   * Support shortcodes in hero `title1` and `title2`
3. [](#bugfix)
    * Fixed an issue that was breaking `text-primary-darker` and `bg-primary-darker`
   
# v2.4.6
## 04/04/2024

1. [](#new)
   * Added a new `height` option for Hero, to allow a min-height to be set utilizing a valid height CSS attribute. E.g. `500px`, or `50vh` etc.
2. [](#improved)
   * Fixed an issue with pagination URLs with absolute URLs enabled [getgrav/grav-plugin-pagination#60](https://github.com/getgrav/grav-plugin-pagination/issues/60)
   * Updated to latest Tailwind `v3.4.3`
3. [](#bugfix)
   * Restored and fixed modular footer functionality that was accidentally removed [getgrav/grav-premium-issues#428](https://github.com/getgrav/grav-premium-issues/issues/428)

# v2.4.5
## 02/02/2024

1. [](#new)
   * Added new 'custom' options for social icons so you can use any icon you wish and provide custom colors and network name [getgrav/grav-premium-issues#406](https://github.com/getgrav/grav-premium-issues/issues/406)
2. [](#improved)
   * Removed some old unused modular blueprints: `double_text`, `footer`, `list`, and `notices`
   * Updated autoprfixer library

# v2.4.4
## 01/06/2024

2. [](#improved)
   * Updated to latest Tailwind `v3.4.1`
   * Changed header transition so it doesn't jump when resizing

# v2.4.3
## 05/09/2023

2. [](#improved)
   * Removed `fallbacks()` for `shortcodes` Twig filter as this is deprecated
   * Fixes for `md5()` used as function when it should be `|md5` filter
   * Updated to latest Tailwind `v3.3.2`

# v2.4.2
## 03/13/2023

2. [](#improved)
   * Updated to latest Tailwind `v3.2.7`

# v2.4.1
## 02/03/2023

1. [](#new)
    * Notices now support a custom `route:` instead of the old `only_homepage:` so that they can be configured to show on any specific page.  `only_homepage: true` == `route: /`
2. [](#improved)
   * Updated to latest Tailwind `v3.2.4`, Typography plugin to `0.5.9`

# v2.4.0
## 09/15/2022

1. [](#new)
    * It is now possible to pick between Cookie or localStorage as appearance storage mechanism. Cookie is advised if you are experiencing FOUC [getgrav/grav-premium-issues#214](https://github.com/getgrav/grav-premium-issues/issues/214)

# v2.3.7
## 09/12/2022

2. [](#improved)
   * Updated to latest Tailwind `v3.1.8`, Typography plugin to `0.5.7` and forms to `0.5.3`
3. [](#bugfix)
   * Updated langswitcher logic to take into account the new translated logic available [getgrav/grav-premium-issues#306](https://github.com/getgrav/grav-premium-issues/issues/306)

# v2.3.6
## 07/26/2022

2. [](#improved)
   * Updated to latest Tailwind `v3.1.6`
   * Added support for `.webp` for hero backgrounds [getgrav/grav-premium-issues#292](https://github.com/getgrav/grav-premium-issues/issues/292)
   * Added support for customizable logo height [getgrav/grav-premium-issues#295](https://github.com/getgrav/grav-premium-issues/issues/295)
   * Added optional `hero.image_alignment` select under **Hero** tab to allow for center/top/bottom alignment [getgrav/grav-premium-issues#294](https://github.com/getgrav/grav-premium-issues/issues/294)
   * Added option to configure **open in new tab** for footer menu links and social links [getgrav/grav-premium-issues#294](https://github.com/getgrav/grav-premium-issues/issues/294)
3. [](#bugfix)
   * Fixed some dark typography issues [getgrav/grav-premium-issues#293](https://github.com/getgrav/grav-premium-issues/issues/293)
   * Fixed missing padding when hero is not displayed [getgrav/grav-premium-issues#294](https://github.com/getgrav/grav-premium-issues/issues/294)
   * Fixed light/dark active class in dropdown [getgrav/grav-premium-issues#294](https://github.com/getgrav/grav-premium-issues/issues/294)
   * Removed old reference for **FontAwesome** icon post date [getgrav/grav-premium-issues#294](https://github.com/getgrav/grav-premium-issues/issues/294)

# v2.3.5
## 06/14/2022

2. [](#improved)
   * Updated to latest Tailwind `v3.1.2`
   * Use new color var functionality from latest Tailwind

# v2.3.4
## 06/01/2022

2. [](#improved)
   * Added opacity support for configurable colors (primary, primary-lighter, primary-darker) - Requires `color-tools` v1.1.1
   * Remove `precess` dependency as we don't use it and it's no longer maintained 
   * Added support for `langswitcher` "Language Display" configuration option [getgrav/grav-premium-issues#286](https://github.com/getgrav/grav-premium-issues/issues/286)
   * Minor NPM version updates

# v2.3.3
## 04/25/2022

2. [](#improved)
   * Updated to latest Tailwind `v3.0.24`
   * Provide a fallback for `|shortcodes` filter if `shortcode-core` is not installed
3. [](#bugfix)
   * The `onpage_menu` option should be `No` by default

# v2.3.2
## 03/28/2022

3. [](#bugfix)
   * Fix typo in `blueprints/modular.yaml` causing `onpage_menu` option not to save

# v2.3.1
## 03/25/2022

2. [](#improved)
   * Better handling of taxonomy when hero is center aligned [getgrav/grav-premium-issues#236](https://github.com/getgrav/grav-premium-issues/issues/236)
   * Improved Page summary calculation [getgrav/grav-premium-issues#233](https://github.com/getgrav/grav-premium-issues/issues/233)
   * Added `sidebar_content` block around sidebar content, plus allow `sidebar_classes` variable to be passed to `partials/sidebar.html.twig` [getgrav/grav-premium-issues#235](https://github.com/getgrav/grav-premium-issues/issues/235)
   * Added `.magic-content` to various Twig templates for more seamless **SEO Magic** integration
3. [](#bugfix)
   * Fixed various styling issues with the Blog's 'sidebar' layout

# v2.3.0.1
## 03/10/2022

2. [](#improved)
   * Rebuilt CSS to include all styles from blog & onepage content

# v2.3.0
## 03/10/2022

1. [](#new)
   * Added support for new **fixed header** mode [getgrav/grav-premium-issues#205](https://github.com/getgrav/grav-premium-issues/issues/205)
   * Added configurable header height
   * Configurable vertical/horizontal classes in `features` module type [getgrav/grav-premium-issues#217](https://github.com/getgrav/grav-premium-issues/issues/217)
2. [](#improved)
   * Updated to latest Tailwind `v3.0.23`
   * Added checks in `modular/partials/title.html.twig` [getgrav/grav-premium-issues#228](https://github.com/getgrav/grav-premium-issues/issues/228)
3. [](#bugfix)
   * Fixed issue with `italic` font not properly loading
   * Fixed duplicated classnames [getgrav/grav-premium-issues#218](https://github.com/getgrav/grav-premium-issues/issues/218)
   * Couple of fixes in `modular/text-columns.html.twig` [getgrav/grav-premium-issues##229](https://github.com/getgrav/grav-premium-issues/issues/229)
   * Fixed some various reported typos (thanks to @aleclerc7)

# v2.2.2
## 01/05/2022

2. [](#improved)
   * Updated to latest Tailwind `v3.0.11`

# v2.2.1
## 12/23/2021

2. [](#improved)
   * Updated to latest Tailwind `v3.0.7`

# v2.2.0
## 12/16/2021

1. [](#new)
   * Require Grav `v1.7.6` as minimum
2. [](#improved)
   * Updated to latest Tailwind `v3.0.6`
   * Removed deprecated `transform`
   * Removed `mode: jit` and `variants` from tailwind.config.js as they are no longer needed
   * Switched from custom `gray` color palette to new Tailwind 3.0 `zinc` - [Color Palette info](https://tailwindcss.com/docs/customizing-colors#default-color-palette)
3. [](#bugfix)
   * Fixed broken widths in sidebar layout
   * Added missing 'modular' blueprint to enable/disable on-page menu

# v2.1.5
## 11/16/2021

2. [](#improved)
   * Updated to latest Tailwind `v2.2.19`

# v2.1.4
## 09/29/2021

2. [](#improved)
   * Updated to latest Tailwind `v2.2.16`
   * Renamed Twig navigations function like `typhoon_primary_menu` to `theme_primary_menu` to make it more generic and immune to DevTools renaming issues [getgrav/grav-premium-issues#152](https://github.com/getgrav/grav-premium-issues/issues/152)
3. [](#bugfix)
   * Use modular `slug` for onPageMenu links as it's safer than hyphenized menu [getgrav/grav-premium-issues#156](https://github.com/getgrav/grav-premium-issues/issues/156)
   * Updated Inter font to support variants such as italic

# v2.1.3
## 09/01/2021

3. [](#bugfix)
   * Fixed a bad check for `show_onpage_menu` in `modular.html.twig`
   
# v2.1.2
## 08/31/2021

1. [](#new)
   * Added missing `onpage_menu:true` for modular pages missing in Typhoon [getgrav/grav-premium-issues#131](https://github.com/getgrav/grav-premium-issues/issues/131)
2. [](#improved)
   * Added support for `italic` using Inter Variable font
   * Updated to latest Tailwind `v2.2.9`
   * Unroutable pages are clickable in the menu so they open children [getgrav/grav-premium-issues#141](https://github.com/getgrav/grav-premium-issues/issues/141)
3. [](#bugfix)
   * Fixed a missing language translation on error page [getgrav/grav-premium-issues#128](https://github.com/getgrav/grav-premium-issues/issues/128)
   
# v2.1.1
## 06/25/2021

1. [](#bugfix)
   * Check for existence of `langswitcher` plugin to ensure an error is not thrown [getgrav/grav-premium-issues#118](https://github.com/getgrav/grav-premium-issues/issues/118)

# v2.1.0
## 06/25/2021

1. [](#improved)
   * Added `before_icon` and `after_icon` support for menu items
   * Built-in support for `login` plugin with custom menu item with dropdown for logout
   * Built-in support for `langswitcher` plugin with custom dropdown menu item for language  
   * Added new `onSiteThemeMenu()` event for theme or plugins to integrate with Typhoon menu  
   * Updated to latest Tailwind `v2.2.4`
   * Updated Alpine.js to `v2.8.2`
   * Broke out `post-item.htm.twig` and prose around H3 to make editing spacing easier
   * Added author to the **Post** Tab for `post` pages
   * Support SimpleSearch query page
   * Changed default `content_wrapper` block to be an `<article>` tag and include `magic-content` class for SEO-Magic
1. [](#bugfix)
   * Fixed an issue with title's not getting cleared in `modular/gallery.html.twig` [getgrav/grav-premium-issues#109](https://github.com/getgrav/grav-premium-issues/issues/109)
      

# v2.0.1
## 05/04/2021

1. [](#improved)
   * Improved the dark styling for code / pre / notices styles
   * Updated `getting-started.md` page with latest changes + updates

# v2.0.0
## 04/30/2021

1. [](#new)
   * Reinstated **Tailwind JIT compiler** for much faster compilation + production quality 'purged' CSS + arbitrary styles + all variants
   * Multi-language support for **Footer elements + Notices** via new custom modular pages.  These take precedence over the theme level configuration and is backwards compatible with existing setups.
   * Move all lang strings into language files.  Please feel free to submit language translations!
   * Removed redundant **Development** / **Production** mode toggle.  JIT now provides optimal CSS at runtime.  To compress use the CSS pipeline feature of Grav.
1. [](#improved)
   * Updated to latest `v2.1.2` of Tailwind CSS
   * Set primary darker/lighter to `20%` rather than `10%`
  
# v1.1.1
## 04/12/2021

1. [](#improved)
   * Added `body_classes` configuration option in theme settings [getgrav/grav-premium-issues#72](https://github.com/getgrav/grav-premium-issues/issues/72)
   * Support SVG via `page.media.files` rather than `page.media.images` [getgrav/grav-premium-issues#78](https://github.com/getgrav/grav-premium-issues/issues/78)
   * Upgraded to Tailwind 2.1.1
1. [](#bugfix)
   * Forced to Roll-back JIT compilation until TailwindCSS fixes the compiler to support parent-page purge [tailwindlabs/tailwindcss#4059](https://github.com/tailwindlabs/tailwindcss/issues/4059)
   * Fixed issue with brands-based social icons in Safari + Mobile [getgrav/grav-premium-issues#73](https://github.com/getgrav/grav-premium-issues/issues/73)
   * Fixed tailwind import ordering [getgrav/grav-premium-issues#84](https://github.com/getgrav/grav-premium-issues/issues/84)


# v1.1.0
## 03/22/2021

1. [](#new)
   * Using **NEW** Tailwind JIT compiler for much faster compilation + production quality 'purged' CSS + arbitrary styles + all variants
   * Removed redundant **Development** / **Production** mode toggle.  JIT now provides optimal CSS at runtime.  To compress use the CSS pipeline feature of Grav.
   * Added support for custom favicon [getgrav/grav-premium-issues#65](https://github.com/getgrav/grav-premium-issues/issues/65)
1. [](#improved)
   * Updated to latest TailwindCSS `v2.0.4`
   * Added **Vimeo** to footer social icons [getgrav/grav-premium-issues#70](https://github.com/getgrav/grav-premium-issues/issues/70)
1. [](#bugfix)
   * Fixed an issue with Gallery modular page type not resetting `video` variable

# v1.0.12
## 03/15/2021

1. [](#new)
   * Added support for External URLs in new tabs [getgrav/grav-premium-issues#63](https://github.com/getgrav/grav-premium-issues/issues/63)
1. [](#improved)
   * Added **YouTube** to footer social icons [getgrav/grav-premium-issues#64](https://github.com/getgrav/grav-premium-issues/issues/64)
   * Added **Video** Support to the Gallery modular page type [getgrav/grav-premium-issues#61](https://github.com/getgrav/grav-premium-issues/issues/61)
1. [](#bugfix)
   * Removed a testing `dump()` statement
  
# v1.0.11
## 03/01/2021

1. [](#improved)
    * Allow **unroutable** page to serve as parent dropdown menu items, but not be linkable.

# v1.0.10
## 02/23/2021

1. [](#improved)
    * Support retina images in hero by default [getgrav/grav-premium-issues#48](https://github.com/getgrav/grav-premium-issues/issues/48)
1. [](#bugfix)
    * Fixed issue with `hero.display` not taking page variable into account [getgrav/grav-premium-issues/53](https://github.com/getgrav/grav-premium-issues/issues/53)
    * Rename the **Inter** font to remove `.var.` as this can cause an error on some Apache server configurations [getgrav/grav-premium-issues#49](https://github.com/getgrav/grav-premium-issues/issues/49)
    * Fix issue with side menu on homepage [getgrav/grav-premium-issues#52](https://github.com/getgrav/grav-premium-issues/issues/52)

# v1.0.9
## 02/12/2021

1. [](#improved)
    * Updated `tailwindcss` core package to latest `v2.0.3`
    * Updated `@tailwindcss/typography` package to latest `v0.4.0`  
    * Cleaned up spacing in Twig templates (2 space indents)
    * Force `|raw` on some output in `partials/hero.html.twig`
1. [](#bugfix)
    * Fix typo in `gallery` blueprint causing `grid_classes`, and `thumb` width & height options to have no effect. [getgrav/grav-premium-issues#37](https://github.com/getgrav/grav-premium-issues/issues/37)
    * Force `|raw` on simple search results [getgrav/grav-premium-issues#47](https://github.com/getgrav/grav-premium-issues/issues/47) 

# v1.0.8
## 01/28/2021

1. [](#new)
    * Added option to control location of **primary menu**, either `header` or `sidebar`
    * Added option to enable/disable mobile navigation hamburger + menu
1. [](#improved)
    * Moved `primary_menu_levels` under `menu:` configuration. You may need to reset this value.
    * Fixed some header navigation CSS styling that was infecting side navigation
    * Active sidebar menu items are now bold for clarity
    * Improved contrast of sidebar navigation in dark mode

# v1.0.7
## 01/25/2021

1. [](#improved)
    * Added `500px` and `GitBook` to social icon chooser. Now supports any brand in format of `gitbook__brand` for the value. Colors need to defined though. See `css/custom/social.css`.
1. [](#bugfix)
    * Removed a debug statement

# v1.0.6
## 01/15/2021

1. [](#improved)
    * Added instructions in `typhoon.yaml` configuration file
    * Added a new sidebar include with `/modular/sidebar` page if it exists
1. [](#bugfix)
    * Added fix for Safari overflow bug on gallery view
    * Added back support for `group-hover:scale` for gallery view
    
# v1.0.5
## 12/19/2020

1. [](#improved)
    * Typo in `getting-started.md`
    * Added toggles for Footer `menu` and `social` display enabled [getgrav/grav-premium-issues#6](https://github.com/getgrav/grav-premium-issues/issues/6)

# v1.0.4
## 12/15/2020

1. [](#improved)    
    * Improved hero image handling
    * Added `getting-started.md` file to help with CSS compilation questions
    * Upgraded to latest TailwindCSS 2.0.2
    * Various escaping fixes
    * Added Demo link

# v1.0.3
## 12/14/2020

1. [](#improved)
    * Upgraded to latest TailwindCSS 1.9.6
    * Switched some `yes`/`no` Typhoon option toggles to boolean
    * Switch to using `site.css`

# v1.0.2
## 10/07/2020

1. [](#new)
    * Light/Dark modes with automatic detection
    * Grav 1.6x support
1. [](#improved)
    * Added new fields in `gallery` modular template to control grid + thumb sizes
    * Improved semantic tags usages
    * Various SEO fixes (courtesy of SEO-Magic)
    * Various iOS fixes
    * Aria improvements
    * Tailwind upgrades
    * Many CSS and HTML fixes

# v1.0.1
## 09/02/2020

1. [](#new)
    * Initial Release
