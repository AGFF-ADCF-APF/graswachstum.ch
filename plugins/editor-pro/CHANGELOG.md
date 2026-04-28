# v2.0.1
## 04/26/2026

1. [](#new)
    * **Real-time collaborative editing** in the admin-next field via y-prosemirror. The editor now accepts a Yjs `XmlFragment` + `Awareness` from the host (admin-next's page editor) and routes ProseMirror's document state through `ySyncPlugin` / `yCursorPlugin`, so multiple users editing the same page see each other's changes character-by-character along with named cursors. Yjs and `y-protocols/awareness` are externalized in the build (`build-admin-next.mjs`) and resolve at runtime to admin-next's instances on `window.__GRAV_YJS__` — sharing class identities is mandatory because y-prosemirror's `instanceof Y.XmlFragment` check would otherwise reject a fragment created by a different copy of Yjs. The web component wrapper picks up `yFragment` / `yAwareness` / `yUser` properties before `connectedCallback` so `EditorProClass` can wire the collab extension at construction time; the initial markdown content seeds an empty Yjs fragment so the first peer's content propagates to later joiners. Backwards-compatible — when no `yFragment` is provided the editor behaves exactly as before. Requires grav-admin-next ≥ beta.13 (which sets `window.__GRAV_YJS__`) and grav-plugin-sync.
2. [](#bugfix)
    * Cmd-Z / Cmd-Y / the toolbar undo+redo buttons no longer roll back peer edits when collaborative editing is active. TipTap's StarterKit `history` extension is now disabled when `this.collab.fragment` is set (its keymap walked the full ProseMirror transaction stack, which includes remote edits applied via `ySyncPlugin`); the new `TiptapCollaboration` extension binds `Mod-Z` / `Mod-Y` / `Mod-Shift-Z` to y-prosemirror's `yUndo` / `yRedo` via `addKeyboardShortcuts`, and the toolbar undo/redo buttons branch on collab to call `window.TiptapYjs.yUndo` / `yRedo` instead of `editor.commands.undo`. y-prosemirror's `yUndoPlugin` already scopes the underlying `Y.UndoManager` to `ySyncPluginKey` origin, so only edits that originated from the local ySyncPlugin are undoable.
    * Heading shortcodes (`[h1]`–`[h6]`) no longer save with their content on a separate line, which previously caused the markdown engine to wrap the inner text in a `<p>` and produced invalid HTML like `<h2><p>My Heading</p></h2>` on the frontend. The shortcode-block serializer now emits headings on a single line (`[h2]My Heading[/h2]`) regardless of their block-level classification, since heading elements can't legally contain block-level children. Fixes [getgrav/grav-premium-issues#569](https://github.com/getgrav/grav-premium-issues/issues/569).

# v2.0.0
## 04/22/2026

1. [](#new)
    * **Admin-next integration via a web component custom field.** Editor Pro now renders natively inside admin-next alongside its existing admin-classic form-field mode — no shims or iframes. Ships as a self-contained ES module (`admin-next/fields/editor-pro.js`) built with esbuild, with `EditorProClass` exposed on `window.EditorPro` so the web component wrapper can instantiate the editor with the same config path the admin-classic field uses.
    * New `EditorProController` with API endpoints for config resolution, shortcode discovery, path lookup, and plugin-script loading. Routes are registered via `onApiRegisterRoutes`, so the editor fetches everything it needs through the standard Grav API — no direct filesystem or admin-session assumptions.
    * `onApiBlueprintResolved` event hook rewrites `type: markdown` fields in page/plugin/theme blueprints to Editor Pro when admin-next requests a blueprint, giving every markdown editor in the admin a consistent modern experience without per-blueprint config. `type: editor` (explicit code editor / CodeMirror) is deliberately left alone.
    * Bundled dark-theme support: all Tailwind `slate` greys swapped to `neutral` to match admin-next's palette, and the dark theme is aliased to the `.dark` class so it tracks admin-next's color-mode toggle instead of requiring a separate switch.
2. [](#improved)
    * Toolbar configuration set in plugin config (or field blueprint) is now honored by the admin-next editor field — previously the admin-next variant rendered the default toolbar regardless of the configured string.
3. [](#bugfix)
    * `createCodeMirrorCompatibility` no longer crashes with `jQuery is not defined` in admin-next, where jQuery isn't loaded. The bare `jQuery` reference is now guarded with a `typeof` check.
    * Only `type: markdown` fields are overridden to Editor Pro in the blueprint resolver. `type: editor` fields (intended as CodeMirror code editors) stay on CodeMirror as intended — previously they were being swapped too, breaking fields that needed the code-editor behavior.

# v1.3.5
## 03/24/2026

1. [](#bugfix)
    * Fix for single apostrophes in shortcodes breaking editor

# v1.3.4
## 03/24/2026

1. [](#bugfix)
    * Another fix for markdown target

# v1.3.3
## 03/23/2026

1. [](#bugfix)
    * Fix target attribute not updating to use Grav's markdown target
    * Minor CSS fix for toolbar icon alignment

# v1.3.2
## 03/06/2026

1. [](#bugfix)
    * Fix for shortcode first in content

# v1.3.1
## 12/23/2025

1. [](#improved)
    * Add wrap support for code blocks

# v1.3.0
## 12/23/2025

1. [](#new)
    * New **code shortcode block** support (needed for Codesh syntax highlighting plugin)
1. [](#bugfix)
    * Fix for raw shortcodes
    * Fix for white border in dark mode
    * Fix for bbcode syntax not being reliable

# v1.2.1
## 12/08/2025

1. [](#bugfix)
    * Fixed nested bold/italics [519](https://github.com/getgrav/grav-premium-issues/issues/519)
    * Fixed nested ol/ul lists [520](https://github.com/getgrav/grav-premium-issues/issues/520)

# v1.2.0
## 12/08/2025

1. [](#new)
    * Added a new **raw markdown** editor mode

# v1.1.2
## 12/01/2025

1. [](#improved)
    * Added `title` support to images [#514](https://github.com/getgrav/grav-premium-issues/issues/514)

# v1.1.1
## 12/01/2025

1. [](#bugfix)
    * CSS fix for drag-handle overriding Sortable Pages [#516](https://github.com/getgrav/grav-premium-issues/issues/516)

# v1.1.0
## 11/23/2025

1. [](#improved) 
    * Added new `onEditorProExtractPaths` event
    * Improved shortcode dropdown support with label + value options, not just values
    * Improved shortcode dropdown styling

# v1.0.7
## 11/05/2025

1. [](#bugfix)
    * Fixed an issue with images that contain multiple spaces in their names
    * Fixed a z-index issue with drag handles floating over menubar

# v1.0.6
## 11/02/2025

1. [](#improved) 
    * Added a new summary break (delimiter) support in the editor
    * Restructured 'break' features in a dropdown in the toolbar

# v1.0.5
## 10/13/2025

1. [](#bugfix) 
    * Fix for nested shortcode blocks not saving content the first time [#498](https://github.com/getgrav/grav-premium-issues/issues/498)

# v1.0.4
## 08/30/2025

1. [](#bugfix) 
    * Fixed HTML and Twig in code blocks not rendering properly

# v1.0.3
## 08/30/2025

1. [](#bugfix) 
    * Fixed image regression from v1.0.2 that broke image rendering with spaces in filenames

# v1.0.2
## 08/30/2025

1. [](#bugfix) 
    * Fixed greedy regex issue with images breaking image rendering
    * Added support for image 'title' attribute that was breaking image rendering

# v1.0.1
## 08/29/2025

1. [](#improved)
    * Cleaned up console debug errors
    * Fixed duplicate `image` extension causing TipTap warning
1. [](#bugfix)
    * Fixed issue with inline shortcodes removing extra whitespace
    * Fixed issue with adding shortcodes would not self-close , e.g. `[fa icon=foo /]`
    * Fixed bug where updating existing inline shortcodes would save with old values

# v1.0.0
## 08/28/2025

1. [](#new)
    * Initial release....
