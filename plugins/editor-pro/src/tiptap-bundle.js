// TipTap Bundle for Editor Pro
import { Editor, Node, Mark, Extension } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import Link from '@tiptap/extension-link'
import Image from '@tiptap/extension-image'
import Table from '@tiptap/extension-table'
import TableRow from '@tiptap/extension-table-row'
import TableCell from '@tiptap/extension-table-cell'
import TableHeader from '@tiptap/extension-table-header'
import BubbleMenu from '@tiptap/extension-bubble-menu'
import DragHandle from '@tiptap/extension-drag-handle'
import CharacterCount from '@tiptap/extension-character-count'
import Typography from '@tiptap/extension-typography'
// Gapcursor is already included in StarterKit
import { marked } from 'marked'
import RawBlock from './nodes/RawBlock.js'
import ShortcodeBlock from './nodes/ShortcodeBlock.js'
import GitHubAlert from './nodes/GitHubAlert.js'
import MarkdownParser from './extensions/MarkdownParser.js'
import ExtraTypography from './extensions/ExtraTypography.js'
import MarkdownShortcuts from './extensions/MarkdownShortcuts.js'
import CustomCodeBlock from './extensions/CustomCodeBlock.js'
import { RawMarkdownMode } from './RawMarkdownMode.js'
import { Plugin, PluginKey, NodeSelection } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
// yjs and y-protocols/awareness are externalized by build-admin-next.mjs
// so they resolve at runtime to admin2's instances on window.__GRAV_YJS__.
// y-prosemirror is bundled here but uses the same shared yjs internally
// (its own `import 'yjs'` statements get redirected by the same plugin).
import * as Y from 'yjs'
import { Awareness } from 'y-protocols/awareness'
import { ySyncPlugin, yCursorPlugin, yUndoPlugin, undo as yUndo, redo as yRedo } from 'y-prosemirror'

// Configure marked for Grav-like markdown
marked.setOptions({
  gfm: true,        // GitHub Flavored Markdown
  breaks: false,    // Don't convert line breaks to <br>
  pedantic: false,  // Don't be overly strict
  sanitize: false,  // Don't sanitize HTML (we want to preserve it)
  smartLists: true, // Smarter list behavior
  smartypants: false // Don't use smart quotes (conflicts with Grav)
});

// Expose globally for Editor Pro
window.TiptapCore = { Editor, Node, Mark, Extension }
window.TiptapStarterKit = { StarterKit }
window.TiptapUnderline = { Underline }
window.TiptapLink = { Link }
window.TiptapImage = { Image }
window.TiptapTable = { Table }
window.TiptapTableRow = { TableRow }
window.TiptapTableCell = { TableCell }
window.TiptapTableHeader = { TableHeader }
window.TiptapBubbleMenu = { BubbleMenu }
window.TiptapDragHandle = { DragHandle }
window.TiptapCharacterCount = { CharacterCount }
window.TiptapTypography = { Typography }
// window.TiptapGapcursor = { Gapcursor } - Already included in StarterKit
window.TiptapRawBlock = { RawBlock }
window.TiptapShortcodeBlock = { ShortcodeBlock }
window.TiptapGitHubAlert = { GitHubAlert }
window.TiptapMarkdownParser = { MarkdownParser }
window.TiptapExtraTypography = { ExtraTypography }
window.TiptapMarkdownShortcuts = { MarkdownShortcuts }
window.TiptapCustomCodeBlock = { CustomCodeBlock }
window.TiptapPM = { Plugin, PluginKey, NodeSelection }
window.TiptapPMView = { Decoration, DecorationSet }
window.marked = marked
window.RawMarkdownMode = RawMarkdownMode

// --- Yjs collaboration (Phase 6) -----------------------------------------
// Y and Awareness here are admin2's instances (shared via the build-time
// plugin redirect). y-prosemirror was bundled with the same redirected yjs
// import, so its internal class identities match. Pass-through to window.
window.TiptapYjs = {
    Y,
    Awareness,
    ySyncPlugin,
    yCursorPlugin,
    yUndoPlugin,
    yUndo,
    yRedo,
}

/**
 * TipTap extension wrapping y-prosemirror's plugins.
 *
 * Usage:
 *   TiptapCollaboration.configure({
 *       fragment: yXmlFragment,       // required
 *       awareness: yAwareness,        // optional, enables live cursors
 *       user: { name: 'Alice', color: '#f96' }, // optional cursor label
 *   })
 *
 * Pass alongside the rest of the extensions to `new Editor({ extensions: […] })`.
 * When this extension is in the set, ySyncPlugin takes ownership of the
 * document — initial `content` in the Editor constructor is ignored IF the
 * fragment is non-empty; otherwise it seeds the fragment from the content.
 */
const TiptapCollaboration = Extension.create({
    name: 'collaboration',
    addOptions() {
        return { fragment: null, awareness: null, user: null };
    },
    addProseMirrorPlugins() {
        const plugins = [];
        if (!this.options.fragment) return plugins;
        plugins.push(ySyncPlugin(this.options.fragment));
        if (this.options.awareness) {
            if (this.options.user) {
                this.options.awareness.setLocalStateField('user', this.options.user);
            }
            plugins.push(yCursorPlugin(this.options.awareness));
        }
        plugins.push(yUndoPlugin());
        return plugins;
    },
    // When collab is active StarterKit's history extension is disabled
    // (otherwise its keymap would walk the full ProseMirror transaction
    // stack and roll back peer edits). We replace it here with bindings
    // that route through yUndoPlugin, whose internal Y.UndoManager only
    // tracks edits originating from the local ySyncPlugin.
    addKeyboardShortcuts() {
        if (!this.options.fragment) return {};
        const run = (fn) => () => fn(this.editor.view.state);
        return {
            'Mod-z': run(yUndo),
            'Mod-y': run(yRedo),
            'Mod-Shift-z': run(yRedo),
        };
    },
});
window.TiptapCollaboration = { Collaboration: TiptapCollaboration }
