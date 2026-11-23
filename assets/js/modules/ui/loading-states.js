/**
 * Loading States Module - Centralized loading/spinner management
 *
 * This module provides a unified interface for managing loading spinners
 * and visual loading states throughout the plugin. It uses WordPress native
 * spinners and MutationObserver for automatic cleanup when modals appear.
 *
 * ## Features
 *
 * - Field-level loading spinners (input fields)
 * - Keywords wrapper loading state
 * - Button loading spinners (actions)
 * - Table overlay for bulk operations
 * - Auto-cleanup via MutationObserver
 * - Fallback timeout cleanup (5 seconds)
 *
 * ## CSS Classes Used
 *
 * | Class                    | Purpose                          |
 * |--------------------------|----------------------------------|
 * | infyp-wrapper-loading  | Hides children, shows spinner    |
 * | infyp-field-spinner    | The actual spinner element       |
 * | infyp-table-overlay    | Full table loading overlay       |
 *
 * ## Dependencies
 *
 * - jQuery
 * - WordPress admin CSS (for .spinner class)
 *
 * ## Usage
 *
 * ```javascript
 * import loadingStates from './ui/loading-states.js';
 *
 * // Show spinner on input, cleanup when modal appears
 * loadingStates.showFieldSpinner($input, '#infyp-alt-text-lightbox');
 *
 * // Show table overlay during AJAX
 * loadingStates.showTableOverlay($table);
 * loadingStates.hideTableOverlay();
 * ```
 *
 * @module ui/loading-states
 * @requires jQuery
 */

const loadingStates = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    /**
     * Fallback timeout duration for spinner cleanup
     * Ensures spinners don't persist indefinitely if modal fails to load
     * @constant {number}
     */
    const TIMEOUT_DURATION = 5000; // 5 seconds fallback timeout

    /* =========================================================================
       HELPER FUNCTIONS
       ========================================================================= */

    /**
     * Create a WordPress-style spinner element
     *
     * Creates an activated spinner using WordPress admin CSS classes.
     *
     * @returns {jQuery} The spinner jQuery element
     * @private
     */
    function createSpinner() {
        return $('<span class="spinner is-active infyp-field-spinner"></span>');
    }

    /**
     * Setup MutationObserver to watch for modal appearance
     *
     * Creates an observer that calls the cleanup function when the specified
     * modal element appears in the DOM. Includes a fallback timeout.
     *
     * @param {string} modalSelector - CSS selector for the modal to watch for
     * @param {Function} cleanup - Cleanup function to call when modal appears
     * @returns {MutationObserver} The observer instance (for manual disconnection if needed)
     * @private
     */
    function setupModalObserver(modalSelector, cleanup) {
        var timeoutId;

        const observer = new MutationObserver(function(mutations) {
            if ($(modalSelector).length > 0) {
                clearTimeout(timeoutId);
                cleanup();
                observer.disconnect();
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });

        // Fallback timeout cleanup
        timeoutId = setTimeout(function() {
            cleanup();
            observer.disconnect();
        }, TIMEOUT_DURATION);

        return observer;
    }

    /* =========================================================================
       FIELD SPINNERS
       ========================================================================= */

    /**
     * Show loading spinner on input field while modal is loading
     *
     * Adds loading class to the field wrapper (which hides children via CSS)
     * and appends a spinner. Automatically cleans up when the specified
     * modal appears or after the timeout duration.
     *
     * @param {jQuery} $input - The input field element
     * @param {string} modalSelector - CSS selector for the modal to watch for
     * @returns {void}
     */
    function showFieldSpinner($input, modalSelector) {
        const $wrapper = $input.closest('.infyp-field-wrapper');

        // Add loading class to wrapper - hides all children via CSS
        $wrapper.addClass('infyp-wrapper-loading');

        // Create and insert spinner
        const $spinner = createSpinner();
        $wrapper.append($spinner);

        // Cleanup function
        const cleanup = function() {
            $wrapper.removeClass('infyp-wrapper-loading');
            $spinner.remove();
        };

        setupModalObserver(modalSelector, cleanup);
    }

    /**
     * Show loading spinner on keywords wrapper while modal is loading
     *
     * Similar to showFieldSpinner but targets the keywords wrapper element.
     *
     * @param {jQuery} $trigger - The keywords trigger button
     * @param {string} modalSelector - CSS selector for the modal to watch for
     * @returns {void}
     */
    function showKeywordsSpinner($trigger, modalSelector) {
        const $wrapper = $trigger.closest('.infyp-keywords-wrapper');

        // Add loading class to wrapper
        $wrapper.addClass('infyp-wrapper-loading');

        // Create and insert spinner
        const $spinner = createSpinner();
        $wrapper.append($spinner);

        // Cleanup function
        const cleanup = function() {
            $wrapper.removeClass('infyp-wrapper-loading');
            $spinner.remove();
        };

        setupModalObserver(modalSelector, cleanup);
    }

    /**
     * Show loading spinner on action button while modal is loading
     *
     * Targets the actions wrapper containing the button and shows a spinner.
     *
     * @param {jQuery} $button - The button element
     * @param {string} modalSelector - CSS selector for the modal to watch for
     * @returns {void}
     */
    function showButtonSpinner($button, modalSelector) {
        const $wrapper = $button.closest('.infyp-actions-wrapper');

        // Add loading class to hide all content and show spinner
        $wrapper.addClass('infyp-wrapper-loading');
        const $spinner = createSpinner();
        $wrapper.append($spinner);

        // Cleanup function
        const cleanup = function() {
            $wrapper.removeClass('infyp-wrapper-loading');
            $spinner.remove();
        };

        setupModalObserver(modalSelector, cleanup);
    }

    /* =========================================================================
       TABLE OVERLAY
       ========================================================================= */

    /**
     * Show loading overlay on entire table
     *
     * Creates an absolutely positioned overlay with a centered spinner
     * that covers the entire table during bulk operations or AJAX updates.
     *
     * @param {jQuery} $table - The table element to overlay
     * @returns {void}
     */
    function showTableOverlay($table) {
        const tablePosition = $table.position() || { top: 0, left: 0 };
        const tableWidth = $table.outerWidth() || '100%';
        const tableHeight = $table.outerHeight() || 400;

        // Remove any existing overlay
        $('.infyp-table-overlay').remove();

        // Add loading overlay positioned over the table
        $table.parent().css('position', 'relative');
        $table.parent().append(
            '<div class="infyp-table-overlay" style="position: absolute; top: ' + tablePosition.top + 'px; left: ' + tablePosition.left + 'px; width: ' + tableWidth + 'px; height: ' + tableHeight + 'px; z-index: 100; display: flex; align-items: center; justify-content: center;">' +
            '<span class="spinner is-active" style="float: none;"></span>' +
            '</div>'
        );
    }

    /**
     * Hide and remove the table loading overlay
     *
     * Fades out the overlay then removes it from the DOM.
     *
     * @returns {void}
     */
    function hideTableOverlay() {
        $('.infyp-table-overlay').fadeOut(200, function() {
            $(this).remove();
        });
    }

    /* =========================================================================
       GENERIC WRAPPER SPINNER
       ========================================================================= */

    /**
     * Show loading spinner in a generic wrapper element
     *
     * Flexible spinner function that can be used with any wrapper.
     * Returns the cleanup function for manual control.
     *
     * @param {jQuery} $wrapper - The wrapper element
     * @param {string} [modalSelector] - Optional CSS selector for modal to watch for
     * @returns {Function} Cleanup function to manually remove spinner
     */
    function showWrapperSpinner($wrapper, modalSelector) {
        $wrapper.addClass('infyp-wrapper-loading');
        const $spinner = createSpinner();
        $wrapper.append($spinner);

        let cleaned = false;
        const cleanup = function() {
            if (cleaned) return;
            cleaned = true;
            $wrapper.removeClass('infyp-wrapper-loading');
            $spinner.remove();
        };

        if (modalSelector) {
            setupModalObserver(modalSelector, cleanup);
        }

        return cleanup;
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the loading states module
     *
     * @returns {Object} Public methods
     * @property {Function} showFieldSpinner - Show spinner on input field
     * @property {Function} showKeywordsSpinner - Show spinner on keywords wrapper
     * @property {Function} showButtonSpinner - Show spinner on action button
     * @property {Function} showTableOverlay - Show overlay on entire table
     * @property {Function} hideTableOverlay - Hide and remove table overlay
     * @property {Function} showWrapperSpinner - Show spinner on generic wrapper
     */
    return {
        showFieldSpinner: showFieldSpinner,
        showKeywordsSpinner: showKeywordsSpinner,
        showButtonSpinner: showButtonSpinner,
        showTableOverlay: showTableOverlay,
        hideTableOverlay: hideTableOverlay,
        showWrapperSpinner: showWrapperSpinner
    };

})(jQuery);

// Export for ES6 modules
export default loadingStates;
