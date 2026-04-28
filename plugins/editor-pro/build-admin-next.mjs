#!/usr/bin/env node

/**
 * Build script for Editor Pro admin-next web component.
 *
 * Produces a single self-contained JS file at admin-next/fields/editor-pro.js
 * that includes:
 *  - TipTap core + all extensions (bundled from npm)
 *  - Editor Pro core (the full editor-pro.js IIFE)
 *  - CSS (injected as a <style> tag)
 *  - Web component wrapper class
 */

import { build } from 'esbuild';
import { readFileSync, writeFileSync, mkdirSync } from 'fs';
import { dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const isWatch = process.argv.includes('--watch');

/**
 * esbuild plugin that redirects `yjs` and `y-protocols/awareness` imports
 * to the admin2-side runtime instances exposed on `window.__GRAV_YJS__`.
 *
 * Background: y-prosemirror's source uses `instanceof Y.XmlFragment` etc.
 * If editor-pro bundled its own Yjs, those classes would be DIFFERENT
 * objects than the ones admin2 uses, and a Y.XmlFragment passed in from
 * admin2 would fail the check. Sharing one runtime copy fixes that.
 */
const shareYjsPlugin = {
    name: 'share-yjs',
    setup(build) {
        const targets = new Map([
            ['yjs', 'window.__GRAV_YJS__.yjs'],
            ['y-protocols/awareness', 'window.__GRAV_YJS__.awareness'],
        ]);
        const ns = 'shared-yjs';
        for (const pkg of targets.keys()) {
            build.onResolve({ filter: new RegExp(`^${pkg.replace(/[/]/g, '\\/')}$`) }, () => ({
                path: pkg,
                namespace: ns,
            }));
        }
        build.onLoad({ filter: /.*/, namespace: ns }, (args) => {
            const expr = targets.get(args.path);
            if (!expr) return null;
            // Use both the default and the namespace export so both
            // `import * as Y from 'yjs'` and `import { Awareness } from 'y-protocols/awareness'`
            // resolve to the same shared object.
            const contents = `
const __m = ${expr};
if (!__m) throw new Error('[editor-pro] window.__GRAV_YJS__ is not initialized — admin2 must import editorBinding before editor-pro loads');
module.exports = __m;
`;
            return { contents, loader: 'js' };
        });
    },
};

async function buildAdminNext() {
    // Step 1: Bundle TipTap dependencies into a temporary IIFE
    console.log('[editor-pro] Bundling TipTap dependencies...');
    const tiptapResult = await build({
        entryPoints: [`${__dirname}/src/tiptap-bundle.js`],
        bundle: true,
        format: 'iife',
        globalName: 'TipTap',
        write: false,
        minify: true,
        target: 'es2018',
        treeShaking: true,
        plugins: [shareYjsPlugin],
    });
    const tiptapBundle = tiptapResult.outputFiles[0].text;

    // Step 2: Read the editor-pro.js IIFE
    console.log('[editor-pro] Reading editor-pro.js...');
    let editorProJs = readFileSync(`${__dirname}/admin/assets/editor-pro.js`, 'utf-8');

    // Step 3: Read CSS files
    console.log('[editor-pro] Reading CSS...');
    let themeCSS = readFileSync(`${__dirname}/admin/assets/theme.css`, 'utf-8');
    let editorCSS = readFileSync(`${__dirname}/admin/assets/editor-pro.css`, 'utf-8');

    // Step 4: Replace Tailwind "gray" (slate-tinted) with "neutral" scale in all CSS
    const slateToNeutral = [
        // Tailwind v3 gray → neutral
        ['#f9fafb', '#fafafa'],   // 50
        ['#f3f4f6', '#f5f5f5'],   // 100
        ['#e5e7eb', '#e5e5e5'],   // 200
        ['#d1d5db', '#d4d4d4'],   // 300
        ['#9ca3af', '#a3a3a3'],   // 400
        ['#6b7280', '#737373'],   // 500
        ['#4b5563', '#525252'],   // 600
        ['#374151', '#404040'],   // 700
        ['#1f2937', '#262626'],   // 800
        ['#111827', '#171717'],   // 900
        // Tailwind v2 blue-gray/slate → neutral (used in shortcode modals etc.)
        ['#f7fafc', '#fafafa'],   // 50
        ['#edf2f7', '#f5f5f5'],   // 100
        ['#e2e8f0', '#e5e5e5'],   // 200
        ['#cbd5e0', '#d4d4d4'],   // 300
        ['#a0aec0', '#a3a3a3'],   // 400
        ['#718096', '#737373'],   // 500
        ['#4a5568', '#525252'],   // 600
        ['#2d3748', '#404040'],   // 700
        ['#1a202c', '#262626'],   // 800
    ];
    for (const [slate, neutral] of slateToNeutral) {
        const re = new RegExp(slate.replace('#', '#'), 'gi');
        themeCSS = themeCSS.replace(re, neutral);
        editorCSS = editorCSS.replace(re, neutral);
        editorProJs = editorProJs.replace(re, neutral);
    }

    // Replace dark-theme class references in JS with 'dark' for admin-next
    editorProJs = editorProJs.replace(/dark-theme/g, 'dark');

    // Admin-next uses `.dark` on <html>, editor-pro uses `.dark-theme` on the wrapper.
    // Duplicate all .dark-theme rules as `.dark` so they match anywhere (modals append to body).
    const darkThemeRe = /\.dark-theme\b/g;
    themeCSS += '\n/* Admin-next dark mode alias */\n' + themeCSS.replace(darkThemeRe, '.dark');
    editorCSS += '\n/* Admin-next dark mode alias */\n' + editorCSS.replace(darkThemeRe, '.dark');

    // Step 5: Read the admin-next content area CSS (maintained in editor-pro plugin)
    let contentCSS = readFileSync(`${__dirname}/admin-next/assets/editor-content.css`, 'utf-8');
    // Apply same slate-to-neutral color swap
    for (const [slate, neutral] of slateToNeutral) {
        const re = new RegExp(slate.replace('#', '#'), 'gi');
        contentCSS = contentCSS.replace(re, neutral);
    }
    // Add .dark alias for dark-theme rules within content CSS
    const darkRe2 = /\.dark-theme\b/g;
    contentCSS += '\n/* Admin-next dark mode alias */\n' + contentCSS.replace(darkRe2, '.dark');

    // Step 6: Generate the web component wrapper
    const webComponentWrapper = generateWebComponentWrapper();

    // Step 7: Combine everything
    const combinedOutput = [
        '/* Editor Pro — Admin-Next Web Component (auto-generated) */',
        '/* Do not edit directly — built by build-admin-next.mjs */',
        '',
        '(function() {',
        '"use strict";',
        '',
        '/* === TipTap Bundle === */',
        tiptapBundle,
        '',
        '/* === Editor Pro CSS Injection === */',
        generateCSSInjection(themeCSS + '\n' + editorCSS + '\n' + contentCSS),
        '',
        '/* === Editor Pro Core === */',
        editorProJs,
        '',
        '/* === Web Component Wrapper === */',
        webComponentWrapper,
        '',
        '})();',
    ].join('\n');

    // Write output
    mkdirSync(`${__dirname}/admin-next/fields`, { recursive: true });
    const outPath = `${__dirname}/admin-next/fields/editor-pro.js`;
    writeFileSync(outPath, combinedOutput, 'utf-8');

    const sizeKB = (Buffer.byteLength(combinedOutput, 'utf-8') / 1024).toFixed(1);
    console.log(`[editor-pro] Built admin-next/fields/editor-pro.js (${sizeKB} KB)`);
}

/**
 * Generate a JS snippet that injects CSS into the document head.
 */
function generateCSSInjection(css) {
    const escaped = JSON.stringify(css);
    return `
(function() {
    if (document.querySelector('style[data-editor-pro]')) return;
    var s = document.createElement('style');
    s.setAttribute('data-editor-pro', '');
    s.textContent = ${escaped};
    document.head.appendChild(s);
})();
`;
}

/**
 * Generate the HTMLElement web component class that bridges
 * the existing EditorPro class to the admin-next CustomFieldWrapper API.
 */
function generateWebComponentWrapper() {
    return `
(function() {
    var TAG = window.__GRAV_FIELD_TAG;
    if (!TAG) return; // Not loaded via CustomFieldWrapper

    class EditorProField extends HTMLElement {
        constructor() {
            super();
            this._field = null;
            this._value = '';
            this._editor = null;
            this._textarea = null;
            this._isUpdating = false;
            this._initialized = false;
            this._configLoaded = false;
            // Phase 6 collaboration context. Set by admin-next before
            // connectedCallback when collab is enabled.
            this._yFragment = null;
            this._yAwareness = null;
            this._yUser = null;
        }

        // --- Property API required by CustomFieldWrapper ---

        set field(f) { this._field = f; }
        get field() { return this._field; }

        set value(v) {
            var newVal = v || '';
            if (this._value === newVal) return;
            this._value = newVal;
            // Under collaborative mode the Y.XmlFragment owns the document;
            // reloading from a markdown string would wipe other peers'
            // pending edits. Let the Yjs layer handle content sync.
            if (this._yFragment) return;
            if (this._textarea && this._initialized && !this._isUpdating) {
                this._reloadContent(newVal);
            }
        }
        get value() { return this._value; }

        // --- Collaboration property API (optional) ---
        set yFragment(f) { this._yFragment = f; }
        get yFragment() { return this._yFragment; }
        set yAwareness(a) { this._yAwareness = a; }
        get yAwareness() { return this._yAwareness; }
        set yUser(u) { this._yUser = u; }
        get yUser() { return this._yUser; }

        // --- Lifecycle ---

        connectedCallback() {
            this._render();
            this._loadConfigAndInit();
        }

        disconnectedCallback() {
            if (this._editor && this._editor.editor) {
                this._editor.editor.destroy();
            }
            // Remove theme observer
            if (this._themeObserver) {
                this._themeObserver.disconnect();
            }
        }

        // --- Internal methods ---

        _render() {
            // Create the wrapper structure that EditorPro expects
            var wrapper = document.createElement('div');
            wrapper.className = 'editor-pro-wrapper';
            // Contain editor-pro's stacking context so its sticky toolbar
            // (which sets z-index 1001 on itself when fixed) can't punch
            // up through admin-next's pinned page-edit header. We use
            // isolation:isolate rather than position:relative+z-index:1
            // because the latter changes the containing block for any
            // absolute children inside the editor, which caused a brief
            // side-jump on the sticky-to-unstick transition.
            wrapper.style.isolation = 'isolate';
            wrapper.setAttribute('data-theme', this._detectTheme());

            // Set page route from global (set by admin-next page editor)
            var pageRoute = window.__GRAV_PAGE_ROUTE || '/';
            wrapper.setAttribute('data-page-route', pageRoute);

            // Create hidden textarea — EditorPro attaches to this
            var textarea = document.createElement('textarea');
            textarea.className = 'editor-pro-textarea';
            textarea.setAttribute('data-grav-field', 'editor-pro');
            textarea.style.display = 'none';
            textarea.value = this._value || '';

            // Create the editor container — EditorPro.initializeEditor() mounts TipTap here
            var container = document.createElement('div');
            container.className = 'editor-pro-container epc';

            wrapper.appendChild(textarea);
            wrapper.appendChild(container);
            this.appendChild(wrapper);

            this._textarea = textarea;
            this._wrapper = wrapper;

            // Watch for theme changes
            this._watchTheme();
        }

        async _loadConfigAndInit() {
            try {
                // Fetch config, shortcodes, and path mappings in parallel
                var [configData, shortcodesData, pathMappings] = await Promise.all([
                    this._apiFetch('/editor-pro/config'),
                    this._apiFetch('/editor-pro/shortcodes'),
                    this._resolveInitialPaths(),
                ]);

                // Set global config for EditorPro IIFE to read
                window.EditorProShortcodes = shortcodesData || [];
                window.EditorProExtraTypography = configData?.extra_typography || { enabled: true, custom: {} };
                window.EditorProPluginStatus = { shortcodeCore: configData?.plugin_status?.shortcode_core || false };

                // Store path mappings on the wrapper element
                if (pathMappings) {
                    this._wrapper.setAttribute('data-path-mappings', JSON.stringify(pathMappings));
                }

                // Set summary delimiter
                this._wrapper.setAttribute('data-summary-delimiter', '===');

                this._configLoaded = true;
                this._initEditor();

                // Load extension plugins after editor is ready
                this._loadPlugins();

            } catch (err) {
                console.error('[EditorPro] Failed to load config:', err);
                // Initialize anyway with defaults
                window.EditorProShortcodes = window.EditorProShortcodes || [];
                window.EditorProExtraTypography = window.EditorProExtraTypography || { enabled: true, custom: {} };
                window.EditorProPluginStatus = window.EditorProPluginStatus || { shortcodeCore: false };
                this._configLoaded = true;
                this._initEditor();
            }
        }

        _initEditor() {
            if (!this._textarea || !window.EditorPro?.EditorProClass) {
                console.error('[EditorPro] EditorProClass not available');
                return;
            }

            // Create the EditorPro instance on our textarea. When a Yjs
            // fragment has been handed to us (Phase 6 collab), pass it
            // through so TipTap uses y-prosemirror for document state.
            try {
                var opts = this._yFragment
                    ? { collab: {
                          fragment: this._yFragment,
                          awareness: this._yAwareness,
                          user: this._yUser,
                      } }
                    : undefined;
                this._editor = new window.EditorPro.EditorProClass(this._textarea, opts);
                this._initialized = true;

                // Listen for textarea changes (EditorPro dispatches 'input' events)
                this._textarea.addEventListener('input', () => {
                    if (!this._isUpdating) {
                        this._isUpdating = true;
                        this._value = this._textarea.value;
                        this.dispatchEvent(new CustomEvent('change', { detail: this._textarea.value }));
                        this._isUpdating = false;
                    }
                });
            } catch (err) {
                console.error('[EditorPro] Failed to initialize editor:', err);
            }
        }

        _reloadContent(markdown) {
            if (!this._editor || !this._editor.editor) return;

            // Update textarea value
            this._textarea.value = markdown;

            // Re-initialize the editor content
            var preserver = this._editor.preserver;
            var result = preserver.preserveContent(markdown);
            this._editor.preservedBlocks = result.blocks;

            var html = this._editor.markdownToEditor(result.processed);
            this._editor.editor.commands.setContent(html);
        }

        async _resolveInitialPaths() {
            if (!this._value) return { images: {}, links: {} };

            var pageRoute = window.__GRAV_PAGE_ROUTE || '/';
            try {
                return await this._apiFetch('/editor-pro/resolve', {
                    method: 'POST',
                    body: JSON.stringify({ route: pageRoute, content: this._value }),
                });
            } catch {
                return { images: {}, links: {} };
            }
        }

        async _loadPlugins() {
            try {
                var url = this._apiUrl('/editor-pro/plugins');
                var headers = this._apiHeaders();
                var resp = await fetch(url, { headers: headers });
                if (resp.ok) {
                    var code = await resp.text();
                    if (code && !code.startsWith('/* No editor-pro plugins')) {
                        // Execute plugin code
                        var blob = new Blob([code], { type: 'application/javascript' });
                        var blobUrl = URL.createObjectURL(blob);
                        await import(blobUrl);
                        URL.revokeObjectURL(blobUrl);

                        // Initialize plugins with our editor instance
                        if (window.EditorPro?.pluginSystem && this._editor) {
                            window.EditorPro.pluginSystem.initialize(this._editor);
                        }
                    }
                }
            } catch (err) {
                console.warn('[EditorPro] Failed to load plugins:', err);
            }
        }

        // --- API helpers ---

        _apiUrl(path) {
            var serverUrl = window.__GRAV_API_SERVER_URL || '';
            var prefix = window.__GRAV_API_PREFIX || '/api/v1';
            return serverUrl + prefix + path;
        }

        _apiHeaders() {
            var h = { 'Content-Type': 'application/json' };
            if (window.__GRAV_API_TOKEN) h['X-API-Token'] = window.__GRAV_API_TOKEN;
            return h;
        }

        async _apiFetch(path, options) {
            var url = this._apiUrl(path);
            var resp = await fetch(url, Object.assign({
                headers: this._apiHeaders(),
            }, options || {}));

            if (!resp.ok) throw new Error('API error: ' + resp.status);
            var json = await resp.json();
            return json.data !== undefined ? json.data : json;
        }

        // --- Theme ---

        _detectTheme() {
            return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        }

        _watchTheme() {
            this._themeObserver = new MutationObserver(() => {
                var theme = this._detectTheme();
                if (this._wrapper) {
                    this._wrapper.setAttribute('data-theme', theme);
                    // Update theme class on wrapper
                    if (theme === 'dark') {
                        this._wrapper.classList.add('dark');
                    } else {
                        this._wrapper.classList.remove('dark');
                    }
                }
            });
            this._themeObserver.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['class'],
            });
        }
    }

    // Register the custom element
    customElements.define(TAG, EditorProField);
})();
`;
}

// Run build
buildAdminNext().catch(err => {
    console.error('[editor-pro] Build failed:', err);
    process.exit(1);
});
