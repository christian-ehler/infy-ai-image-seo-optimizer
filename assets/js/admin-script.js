/**
 * Main Admin Script - Entry point for all plugin JavaScript.
 *
 * This is the primary entry point for the AI Image SEO plugin's JavaScript.
 * It initializes all modules and coordinates between different components
 * to ensure proper functionality in the WordPress admin area.
 *
 * ## Modules Initialized
 *
 * - `imageTable` - Image list table with inline editing and AI optimization
 * - `settings` - Settings forms and API key management
 * - `seoConfig` - SEO scoring configuration interface (conditional)
 *
 * ## Load Order
 *
 * 1. DOM ready event fires
 * 2. Image table module initializes (table interactions, modals, AJAX)
 * 3. Settings module initializes (form handlers, validation)
 * 4. SEO config initializes if on the configuration page
 * 5. WordPress postbox toggles enabled for collapsible sections
 *
 * @file Main entry point for plugin JavaScript.
 * @author AI Image SEO
 * @since 1.0.0
 * @requires jQuery
 * @requires ./modules/api.js
 * @requires ./modules/ui.js
 * @requires ./modules/image-table.js
 * @requires ./modules/settings.js
 * @requires ./modules/seo-config.js
 */
import api from './modules/api.js';
import ui from './modules/ui.js';
import imageTable from './modules/image-table.js';
import settings from './modules/settings.js';
import seoConfig from './modules/seo-config.js';

/**
 * Initialize plugin functionality when DOM is ready.
 *
 * Sets up all module initializations and WordPress admin integrations.
 * Each module is initialized in sequence to ensure proper dependency order.
 *
 * @since 1.0.0
 * @listens jQuery#ready
 */
jQuery(document).ready(function($) {
    'use strict';

    // Initialize media library enhancements and image table
    imageTable.init($);

    // Initialize settings forms and handlers
    settings.init($);

    // Initialize SEO configuration interface if on that page
    if ($('#infyp-seo-config-form').length) {
        seoConfig.init($);
    }

    // Enable WordPress postbox toggles (for collapsible meta boxes)
    // This allows users to expand/collapse sections on admin pages
    if (typeof postboxes !== 'undefined' && typeof pagenow !== 'undefined') {
        postboxes.add_postbox_toggles(pagenow);
    }
});