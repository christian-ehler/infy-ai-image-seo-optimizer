/**
 * Usage Checker Module - Image usage checking functionality
 *
 * This module handles checking where an image is used throughout the WordPress
 * site and displaying the results in a modal. It integrates with the usage
 * modal module to display posts, pages, and other content using the image.
 *
 * ## Features
 *
 * - AJAX-based usage checking
 * - Loading spinner during fetch
 * - Integration with usage modal display
 * - Error handling with user feedback
 * - Button state management (hide during load, restore after)
 * - Server-rendered HTML results
 *
 * ## Usage Flow
 *
 * 1. User clicks "Check Usage" button
 * 2. Button hides, loading spinner appears
 * 3. AJAX request fetches usage data from server
 * 4. Usage modal displays results
 * 5. Button restores to normal state
 *
 * ## Usage
 *
 * ```javascript
 * import usageChecker from './features/usage-checker.js';
 *
 * // Check usage programmatically
 * usageChecker.checkUsage(123, $button);
 *
 * // Use as click handler
 * $('.infyp-check-usage').on('click', usageChecker.handleClick);
 * ```
 *
 * @file Usage Checker Module - Image usage checking functionality
 * @author InfyPress
 * @since 1.0.0
 * @module features/usage-checker
 * @requires jQuery
 * @requires api
 * @requires modals/usage-modal
 */

import api from '../api.js';
import usageModal from '../modals/usage-modal.js';

const usageChecker = (function($) {
    'use strict';

    /* =========================================================================
       USAGE CHECKING
       ========================================================================= */

    /**
     * Checks image usage and displays results in modal.
     *
     * Makes an AJAX request to fetch all locations where the image is used
     * throughout the WordPress site, then displays the results in the usage modal.
     * Shows loading spinner during fetch and handles errors gracefully.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {number} imageId - WordPress attachment ID
     * @param {jQuery} $button - The trigger button element (for loading state)
     * @returns {void}
     *
     * @example
     * // Check usage programmatically
     * checkUsage(123, $('.infyp-check-usage'));
     */
    function checkUsage(imageId, $button) {
        const $wrapper = $button.closest('.infyp-usage-details');

        // Hide button and show spinner
        $button.hide();
        const $spinner = $('<span class="spinner is-active infyp-field-spinner"></span>');
        $wrapper.addClass('infyp-wrapper-loading').append($spinner);

        // Fetch usage data
        api.post('infyp_get_image_usage', {
            image_id: imageId
        }).done(response => {
            if (response.success && response.data.html) {
                usageModal.show(imageId, response.data.html);
            } else {
                alert(infyp_vars.i18n.error || 'Error loading usage data');
            }
        }).fail(() => {
            alert(infyp_vars.i18n.server_error || 'Server error occurred');
        }).always(() => {
            // Restore button
            $wrapper.removeClass('infyp-wrapper-loading');
            $spinner.remove();
            $button.show();
        });
    }

    /* =========================================================================
       EVENT HANDLERS
       ========================================================================= */

    /**
     * Handles usage check button click event.
     *
     * Extracts the image ID from the button's data attribute and
     * initiates the usage check workflow.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {Event} e - Click event
     * @returns {void}
     *
     * @example
     * // Attach to usage button
     * $('.infyp-check-usage').on('click', handleClick);
     */
    function handleClick(e) {
        e.preventDefault();
        const $button = $(e.target).closest('.infyp-check-usage');
        const imageId = $button.data('id');
        checkUsage(imageId, $button);
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the usage checker module.
     *
     * @since 1.0.0
     *
     * @returns {Object} Public methods
     * @property {Function} checkUsage - Check usage and display modal
     * @property {Function} handleClick - Handle button click event
     */
    return {
        checkUsage: checkUsage,
        handleClick: handleClick
    };

})(jQuery);

// Export for ES6 modules
export default usageChecker;
