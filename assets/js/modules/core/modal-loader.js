/**
 * Modal Loader Module - Lazy loading for modals
 *
 * This module provides on-demand loading of modal modules using dynamic imports.
 * It implements a caching system to avoid re-loading modules and supports
 * preloading commonly used modals for faster access.
 *
 * ## Features
 *
 * - Dynamic ES6 module imports for lazy loading
 * - In-memory caching of loaded modules
 * - Preload API for anticipatory loading
 * - Support for all plugin modal types
 *
 * ## Available Modals
 *
 * | Name      | Module                    |
 * |-----------|---------------------------|
 * | alt-text  | modals/alt-text-modal.js  |
 * | filename  | modals/filename-modal.js  |
 * | title     | modals/title-modal.js     |
 * | keywords  | modals/keywords-modal.js  |
 * | seo-score | modals/seo-score-modal.js |
 * | usage     | modals/usage-modal.js     |
 *
 * ## Dependencies
 *
 * - jQuery (for compatibility)
 * - ES6 dynamic import support
 *
 * ## Usage
 *
 * ```javascript
 * import modalLoader from './core/modal-loader.js';
 *
 * // Load modal on demand
 * const altTextModal = await modalLoader.load('alt-text');
 * altTextModal.show(imageId);
 *
 * // Preload commonly used modals
 * modalLoader.preloadMultiple(['alt-text', 'filename', 'title']);
 *
 * // Check if modal is loaded
 * if (modalLoader.isLoaded('alt-text')) { ... }
 * ```
 *
 * @file Lazy loading system for modal modules with caching and preload support.
 * @author InfyPress
 * @since 1.0.0
 * @module core/modal-loader
 * @requires jQuery
 */

const modalLoader = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    /**
     * Cache for loaded modal modules
     * @type {Map<string, Object>}
     * @private
     */
    const modalCache = new Map();

    /**
     * Configuration map for available modals
     *
     * Each entry contains:
     * - module: Cached module reference (initially null)
     * - loader: Function that returns the dynamic import promise
     *
     * @type {Object.<string, {module: Object|null, loader: Function}>}
     * @private
     */
    const modalConfig = {
        'alt-text': {
            module: null,
            loader: () => import('../modals/alt-text-modal.js')
        },
        'filename': {
            module: null,
            loader: () => import('../modals/filename-modal.js')
        },
        'title': {
            module: null,
            loader: () => import('../modals/title-modal.js')
        },
        'keywords': {
            module: null,
            loader: () => import('../modals/keywords-modal.js')
        },
        'seo-score': {
            module: null,
            loader: () => import('../modals/seo-score-modal.js')
        },
        'usage': {
            module: null,
            loader: () => import('../modals/usage-modal.js')
        }
    };

    /* =========================================================================
       LOADING FUNCTIONS
       ========================================================================= */

    /**
     * Load a modal module on demand
     *
     * Checks cache first, then dynamically imports the module if not cached.
     * Throws an error if the modal name is unknown.
     *
     * @param {string} modalName - Name of the modal to load (e.g., 'alt-text')
     * @returns {Promise<Object>} Promise resolving to the modal module's default export
     * @throws {Error} If modal name is not in modalConfig
     *
     * @example
     * const modal = await load('alt-text');
     * modal.show(imageId);
     */
    async function load(modalName) {
        // Check cache first
        if (modalCache.has(modalName)) {
            return modalCache.get(modalName);
        }

        const config = modalConfig[modalName];
        if (!config) {
            throw new Error(`Unknown modal: ${modalName}`);
        }

        try {
            const module = await config.loader();
            const modalInstance = module.default;
            modalCache.set(modalName, modalInstance);
            return modalInstance;
        } catch (error) {
            console.error(`Failed to load modal: ${modalName}`, error);
            throw error;
        }
    }

    /* =========================================================================
       PRELOADING
       ========================================================================= */

    /**
     * Preload a modal without showing it
     *
     * Useful for anticipatory loading of commonly used modals during idle time.
     * Silently catches and logs any loading errors.
     *
     * @param {string} modalName - Name of the modal to preload
     * @returns {void}
     */
    function preload(modalName) {
        if (!modalCache.has(modalName)) {
            load(modalName).catch(err => {
                console.warn(`Preload failed for modal: ${modalName}`, err);
            });
        }
    }

    /**
     * Preload multiple modals at once
     *
     * Convenience method to preload several modals simultaneously.
     *
     * @param {string[]} modalNames - Array of modal names to preload
     * @returns {void}
     */
    function preloadMultiple(modalNames) {
        modalNames.forEach(name => preload(name));
    }

    /* =========================================================================
       CACHE MANAGEMENT
       ========================================================================= */

    /**
     * Check if a modal is already loaded and cached
     *
     * @param {string} modalName - Name of the modal
     * @returns {boolean} True if modal is in cache
     */
    function isLoaded(modalName) {
        return modalCache.has(modalName);
    }

    /**
     * Clear modal(s) from cache
     *
     * Useful for testing or forcing a fresh load.
     *
     * @param {string} [modalName] - Specific modal to clear, or omit to clear all
     * @returns {void}
     */
    function clearCache(modalName) {
        if (modalName) {
            modalCache.delete(modalName);
        } else {
            modalCache.clear();
        }
    }

    /**
     * Get array of all currently loaded modal names
     *
     * @returns {string[]} Array of modal names that are cached
     */
    function getLoadedModals() {
        return Array.from(modalCache.keys());
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the modal loader module
     *
     * @returns {Object} Public methods
     * @property {Function} load - Load a modal on demand (async)
     * @property {Function} preload - Preload a modal without showing
     * @property {Function} preloadMultiple - Preload multiple modals
     * @property {Function} isLoaded - Check if modal is cached
     * @property {Function} clearCache - Clear modal(s) from cache
     * @property {Function} getLoadedModals - Get list of cached modals
     */
    return {
        load: load,
        preload: preload,
        preloadMultiple: preloadMultiple,
        isLoaded: isLoaded,
        clearCache: clearCache,
        getLoadedModals: getLoadedModals
    };

})(jQuery);

// Export for ES6 modules
export default modalLoader;
