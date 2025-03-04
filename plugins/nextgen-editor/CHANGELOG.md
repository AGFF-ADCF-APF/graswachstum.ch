# v1.1.18
## 07/24/2023

1. [](#improved)
    * Removed option to merge table cells, to be more consistent with markdown capabilities

# v1.1.17
## 06/16/2023

1. [](#bugfix)
    * Preserve new-lines in table cells by converting them to `<br>` tags

# v1.1.16
## 05/26/2023

1. [](#bugfix)
    * Fixed encoded HTML entities breaking table cells

# v1.1.15
## 05/23/2023

1. [](#bugfix)
    * Fixed table cells not preserving `<br>` tags [getgrav/grav-premium-issues#346](https://github.com/getgrav/grav-premium-issues/issues/346)

# v1.1.14
## 05/10/2023

1. [](#bugfix)
    * Fixed `webp` not being recognized as images [getgrav/grav-premium-issues#347](https://github.com/getgrav/grav-premium-issues/issues/347)
  
# v1.1.13
## 01/12/2023

1. [](#improved)
    * Exposed `insertMedia` util (`nextgenEditor.insertMedia`) to allow 3rd party to integrate with adding media into NextGen programmatically
    * Changed the convertUrl URL route to match new Flex Object approach
    * Broke out nextgen field into `-editor` and `-logic` to provide override capability

# v1.1.12
## 04/19/2022

1. [](#improved)
    * Added support for subscript/superscript [getgrav/grav-premium-issues#231](https://github.com/getgrav/grav-premium-issues/issues/231)
2. [](#bugfix)
    * Fixed regression with Page Picker, preventing it from working [getgrav/grav-premium-issues#272](https://github.com/getgrav/grav-premium-issues/issues/272)

# v1.1.11
## 02/02/2022

1. [](#new)
   * It is now possible to create custom advanced transformations through regex replacement [learn more](https://getgrav.org/premium/nextgen-editor/docs#advanced-transformations) [getgrav/grav-premium-issues#203](https://github.com/getgrav/grav-premium-issues/issues/203)
2. [](#bugfix)
   * Ensure empty toolbar still translates as empty array [getgrav/grav-premium-issues#210](https://github.com/getgrav/grav-premium-issues/issues/210)
   * Fixed issue with new language specific transformations not accurately turning on/off

# v1.1.10
## 02/01/2022

1. [](#new)
   * It is now possible to customize, per language, the **Auto Text Transformations** [getgrav/grav-premium-issues#203](https://github.com/getgrav/grav-premium-issues/issues/203)
2. [](#bugfix)
   * Fixed regression causing duplicate editors to initialize for the same field, due to race-condition issue [getgrav/grav-premium-issues#201](https://github.com/getgrav/grav-premium-issues/issues/201)


# v1.1.9
## 01/28/2022

1. [](#improved)
   * Better initialization checks to prevent potential race-condition issue, resulting in duplicate editors [getgrav/grav-premium-issues#201](https://github.com/getgrav/grav-premium-issues/issues/201)
   * Optimized package to not include or reference sourcemaps when building for release [getgrav/grav-premium-issues#201](https://github.com/getgrav/grav-premium-issues/issues/201)
2. [](#bugfix)
   * Fixed regression with Sticky bar, sticking to the top, behind the toolbar, rather than below

# v1.1.8
## 01/11/2022

1. [](#new)
   * Added new field type `nextgen-editor`, use this to enable NextGen as text editor for fields (before was confusingly only available as `markdown`) [getgrav/grav-premium-issues#188](https://github.com/getgrav/grav-premium-issues/issues/188) 
2. [](#improved)
   * Updated from CK5 v29.2 to v31.1
   * Media picker now loads the current folder media by default. It also adds a sidebar navigation with pages tree structure [getgrav/grav-premium-issues#168](https://github.com/getgrav/grav-premium-issues/issues/168)
3. [](#bugfix)
   * Fixed issue with "Default for All" setting, not allowing for NextGen to be used in other fields [getgrav/grav-premium-issues#188](https://github.com/getgrav/grav-premium-issues/issues/188)
   * Fixed issue with images using stream as a source, ending up with an undesired prefixed leading slash [getgrav/grav-premium-issues#167](https://github.com/getgrav/grav-premium-issues/issues/167)
   * Fixed compatibility with `lists` field [getgrav/grav-premium-issues#180](https://github.com/getgrav/grav-premium-issues/issues/180)

# v1.1.7
## 09/28/2021

1. [](#bugfix)
   * Do not append `?link` for non-image media files [getgrav/grav-premium-issues#159](https://github.com/getgrav/grav-premium-issues/issues/159)

# v1.1.6
## 09/27/2021

1. [](#bugfix)
   * Fixed regression where insertion of images ended up throwing JS errors and not functioning as expected [getgrav/grav-premium-issues#151](https://github.com/getgrav/grav-premium-issues/issues/151)

# v1.1.5
## 09/23/2021

1. [](#improved)
   * Updated to CK5 v29.2
2. [](#bugfix)
   * Fixed inserting Page Media assets getting transformed to image wrapped links [getgrav/grav-premium-issues#142](https://github.com/getgrav/grav-premium-issues/issues/142)
   * Fixed issue with Media Picker losing the leading `/` and causing images not to display or save correctly [getgrav/grav-premium-issues#139](https://github.com/getgrav/grav-premium-issues/issues/139) [getgrav/grav-premium-issues#143](https://github.com/getgrav/grav-premium-issues/issues/143)

# v1.1.4
## 07/21/2021

1. [](#improved)
   * Updated from CK5 v27 to v29
   * Previously selected code block language will be now remembered for the next selection
   * Advanced: It is now possible to view and edit the source code of the content by adding the `sourceEditing` toolbar entry [getgrav/grav-premium-issues#96](https://github.com/getgrav/grav-premium-issues/issues/96)
1. [](#bugfix)
   * Allow using twig inside HTML Snippet and ensure `<twig>` tags get cleared on save [getgrav/grav-premium-issues#124](https://github.com/getgrav/grav-premium-issues/issues/124)
   
# v1.1.3
## 06/02/2021

1. [](#improved)
   * It is now possible to add new custom code block languages on top of the built-in ones (plaintext, bash, c, cs, cpp, css, diff, html, java, javascript, json, php, python, ruby, sh, typescript, xml, yaml).
1. [](#bugfix)
   * Fixed double scrollbar in Code Block dropdown menu

# v1.1.2
## 05/07/2021

1. [](#bugfix)
   * Fixed issue with media sources URIs containing a fragment in the query and wrongly getting parsed by Grav [getgrav/grav-premium-issues#95](https://github.com/getgrav/grav-premium-issues/issues/95)

# v1.1.1
## 04/29/2021

1. [](#improved)
   * Added `mark` to the list of allowed inline tags and to preserve [getgrav/grav-premium-issues#92](https://github.com/getgrav/grav-premium-issues/issues/92)

# v1.1.0
## 04/27/2021

1. [](#new)
   * Added ability to define custom Media Embed Providers ([see documentation](https://getgrav.org/premium/nextgen-editor/docs#media-embed-providers)) [getgrav/grav-premium-issues#89](https://github.com/getgrav/grav-premium-issues/issues/89)
1. [](#improved)
   * Updated from CK5 v27 to v27.1
   * Added better support for [CK5 Media Embed](https://ckeditor.com/docs/ckeditor5/latest/features/media-embed.html#displaying-embedded-media-on-your-website) feature.
1. [](#bugfix)
   * Fixed case where missing options would throw a JS error
   * Better support for new non-paragraph lines (SHIFT + RETURN). They will now be converted to proper double-space markdown lines [getgrav/grav-premium-issues#93](https://github.com/getgrav/grav-premium-issues/issues/93)

# v1.0.8
## 04/19/2021

1. [](#bugfix)
   * Proper fix for edge-case with non-breaking space from v1.0.7, only applying the fix once (oops!) [getgrav/grav-premium-issues#32](https://github.com/getgrav/grav-premium-issues/issues/32)

# v1.0.7
## 04/19/2021

1. [](#improved)
   * Better dynamic minimum height for the Editor.
   * Upgraded from CK5 v25 to v27 which brings many enhancements, bugs fixes and security fixes. Worth noticing:
   * Enhanced drag & drop of content from outside the editor (including textual content from applications, widgets and HTML)
   * Drag & drop for reordering content as well as adding new one, within the editor, has a better indicator of where the content will end up in
   * Better handling of large content when formatting
   * Security fixes with Markdown GFM
   * The Ctrl key is now translated to Cmd on macOS to avoid conflicts with some macOS keyboard shortcuts
1. [](#bugfix)
   * Fixed edge-case where Non-breaking Space Transformations option would not properly convert to empty space, preventing certain combinations of markdown and shortcodes to work together (ie, `## [fa icon="grav" /] Title`) [getgrav/grav-premium-issues#32](https://github.com/getgrav/grav-premium-issues/issues/32)

# v1.0.6
## 01/29/2021

1. [](#bugfix)
   * Fixed way of retrieving page routing for Media Picker
   * Fixed settings popup position when close to the bottom of the page [getgrav/grav-premium-issues#23](https://github.com/getgrav/grav-premium-issues/issues/23)

# v1.0.5
## 01/22/2021

1. [](#new)
   * Added new setting to preserve non-breaking spaces [getgrav/grav-premium-issues#19](https://github.com/getgrav/grav-premium-issues/issues/19)
   * Added support for labels in `markdow` type [getgrav/grav-premium-issues#17](https://github.com/getgrav/grav-premium-issues/issues/17)

# v1.0.4
## 01/21/2021

1. [](#bugfix)
   * Fixed issue preventing editor to load when using custom Admin routes

# v1.0.3
## 01/15/2021

1. [](#new)
   * Added new `Remove Format` toolbar button to clear any formatted text that is selected
   * Added new `HTML Snippet` toolbar button, available as **Insert HTML** that will allow to store/restore and edit HTML snippet without having them being treated as Markdown
   * Added global setting `Show HTML Embed Previews` to allow previewing the new HTML Snippets feature. Code will show by default.
   * Added settings for a variety of Automatic Text Transformations (typography, quotes, symbols, mathematical and custom).
1. [](#improved)
   * Updated libraries to their latest versions
1. [](#bugfix)
   * Fixed links where _unlinking_ them would not properly remove the link
   * Fixed Page Picker, wrapping the selection with an unnecessary span

# v1.0.2
## 12/20/2020

1. [](#bugfix)
    * Fixed regression in Grav 1.6 preventing Page Picker to load

# v1.0.1
## 12/18/2020

1. [](#improved)
    * Improved PagePicker module to restore the path value (used by PageInject)

# v1.0.0
## 12/09/2020

1. [](#new)
    * Initial Release
