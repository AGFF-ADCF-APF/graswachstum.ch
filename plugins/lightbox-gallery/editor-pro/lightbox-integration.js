/**
 * Lightbox Gallery Integration for Editor Pro
 *
 * This plugin provides minimal integration for the lightbox-gallery shortcodes
 * in Editor Pro. It relies on Editor Pro's built-in form system and path
 * resolution, with custom renderers providing visual feedback.
 *
 * The customRenderer functions are defined in the PHP registration
 * (lightbox-gallery.php) to keep all configuration in one place.
 */

(function() {
    'use strict';

    const LightboxGalleryEditorProPlugin = {
        name: 'lightbox-gallery',

        /**
         * Initialize the plugin
         * @param {Object} editorPro - The Editor Pro instance
         */
        init(editorPro) {
            this.editorPro = editorPro;
            console.log('[Lightbox Gallery v2] Editor Pro integration loaded');

            // The plugin registers itself but doesn't need to add custom UI
            // since we're using the minimal integration approach with built-in forms

            // Custom renderers are defined in PHP and executed by Editor Pro core
            // Image path resolution is handled by Editor Pro's preResolveContentPaths()
        }
    };

    // Register the plugin with Editor Pro
    if (window.EditorPro && window.EditorPro.registerPlugin) {
        window.EditorPro.registerPlugin(LightboxGalleryEditorProPlugin);
    }

})();
