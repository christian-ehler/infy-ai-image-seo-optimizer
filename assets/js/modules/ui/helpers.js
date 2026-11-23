/**
 * UI Helpers Module - Shared utility functions
 *
 * This module provides common utility functions used across the plugin's UI.
 * Includes XSS-safe element creation, string formatting, viewport detection,
 * and smooth scrolling utilities.
 *
 * ## Features
 *
 * - XSS-safe DOM element creation
 * - String formatting (sprintf)
 * - Table skeleton loader management
 * - Smooth scroll to element
 * - Debounce function
 * - Viewport detection
 *
 * ## Dependencies
 *
 * - jQuery
 *
 * ## Usage
 *
 * ```javascript
 * import helpers from './ui/helpers.js';
 *
 * // Safe element creation
 * const $el = helpers.safeCreateElement('span', 'badge', userInput);
 *
 * // Debounce for performance
 * const debouncedSearch = helpers.debounce(search, 300);
 *
 * // Show table after load
 * helpers.showTableWhenReady();
 * ```
 *
 * @module ui/helpers
 * @requires jQuery
 */

const helpers = (function($) {
    'use strict';

    /* =========================================================================
       DOM CREATION
       ========================================================================= */

    /**
     * Safely create DOM element with XSS prevention
     *
     * Uses jQuery's text() method to safely escape content,
     * preventing XSS attacks from user-generated input.
     *
     * @param {string} tag - HTML tag name (e.g., 'div', 'span')
     * @param {string} className - CSS class(es) to add
     * @param {string} [content] - Text content (will be escaped)
     * @returns {jQuery} The created jQuery element
     *
     * @example
     * const $badge = safeCreateElement('span', 'user-badge', userInput);
     */
    function safeCreateElement(tag, className, content) {
        const $element = $('<' + tag + '>').addClass(className);
        if (content) {
            $element.text(content);
        }
        return $element;
    }

    /* =========================================================================
       STRING FORMATTING
       ========================================================================= */

    /**
     * Simple sprintf implementation for number placeholders
     *
     * Replaces %d placeholders with provided arguments in order.
     * Useful for i18n strings with numeric values.
     *
     * @param {string} format - Format string with %d placeholders
     * @param {...number} args - Replacement values
     * @returns {string} Formatted string
     *
     * @example
     * sprintf('%d of %d images', 5, 10); // '5 of 10 images'
     */
    function sprintf(format, ...args) {
        let i = 0;
        return format.replace(/%d/g, () => args[i++]);
    }

    /* =========================================================================
       PAGE LOAD MANAGEMENT
       ========================================================================= */

    /**
     * Show table and hide skeleton loading when page is fully loaded
     *
     * The table is hidden via CSS initially with a skeleton placeholder shown.
     * This function transitions from skeleton to actual table on load,
     * with a 5-second fallback timeout.
     *
     * NOTE: If ajaxTableLoader has already rendered inline data and marked
     * the table as ready, this function will skip its logic to avoid
     * race conditions that cause table flicker.
     *
     * @returns {void}
     */
    function showTableWhenReady() {
        const $skeleton = $('.infyp-table-skeleton');
        const $wrapper = $('.infyp-table-wrapper');

        if (!$wrapper.length) return;

        // Check if table is already marked as ready (by ajaxTableLoader inline data)
        // This prevents race conditions where both try to manage visibility
        if ($wrapper.hasClass('infyp-table-ready')) {
            // Already ready - just ensure body class is set
            $('body').addClass('infyp-page-ready');
            return;
        }

        function showTable() {
            // Double-check in case ajaxTableLoader marked it ready while we were waiting
            if ($wrapper.hasClass('infyp-table-ready')) {
                $('body').addClass('infyp-page-ready');
                return;
            }

            $skeleton.fadeOut(200, function() {
                $(this).addClass('infyp-skeleton-hidden');
            });
            $wrapper.addClass('infyp-table-ready');
            // Show search box and post type filter
            $('body').addClass('infyp-page-ready');
        }

        // Check if window is already fully loaded
        if (document.readyState === 'complete') {
            showTable();
        } else {
            // Show table when window is fully loaded
            $(window).on('load.infypInitial', function() {
                showTable();
                $(window).off('load.infypInitial');
            });

            // Fallback: show table after 5 seconds if load event doesn't fire
            setTimeout(function() {
                if (!$wrapper.hasClass('infyp-table-ready')) {
                    showTable();
                    $(window).off('load.infypInitial');
                }
            }, 5000);
        }
    }

    /* =========================================================================
       DOM QUERIES
       ========================================================================= */

    /**
     * Get image table row by image ID
     *
     * @param {number} imageId - WordPress attachment ID
     * @returns {jQuery} Table row element (may be empty if not found)
     */
    function getImageRow(imageId) {
        return $('tr[data-image-id="' + imageId + '"]');
    }

    /* =========================================================================
       SCROLLING
       ========================================================================= */

    /**
     * Scroll element into view with smooth animation
     *
     * @param {string|jQuery} selector - Element selector or jQuery object
     * @param {number} [offset=100] - Offset from top of viewport in pixels
     * @returns {void}
     */
    function scrollToElement(selector, offset = 100) {
        const $el = $(selector);
        if ($el.length) {
            $('html, body').animate({
                scrollTop: $el.offset().top - offset
            }, 300);
        }
    }

    /* =========================================================================
       UTILITY FUNCTIONS
       ========================================================================= */

    /**
     * Debounce function execution
     *
     * Creates a debounced version of the function that delays execution
     * until after the specified wait time has elapsed since the last call.
     * Useful for expensive operations triggered by frequent events (e.g., input).
     *
     * @param {Function} func - Function to debounce
     * @param {number} wait - Wait time in milliseconds
     * @returns {Function} Debounced function
     *
     * @example
     * const debouncedSearch = debounce(search, 300);
     * $input.on('input', debouncedSearch);
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Check if element is currently visible in viewport
     *
     * Returns true if the entire element is within the visible viewport.
     *
     * @param {jQuery} $el - Element to check
     * @returns {boolean} True if element is fully visible in viewport
     */
    function isInViewport($el) {
        if (!$el.length) return false;

        const rect = $el[0].getBoundingClientRect();
        return (
            rect.top >= 0 &&
            rect.left >= 0 &&
            rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
            rect.right <= (window.innerWidth || document.documentElement.clientWidth)
        );
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the helpers module
     *
     * @returns {Object} Public methods
     * @property {Function} safeCreateElement - Create XSS-safe DOM element
     * @property {Function} sprintf - Format string with placeholders
     * @property {Function} showTableWhenReady - Show table after page load
     * @property {Function} getImageRow - Get table row by image ID
     * @property {Function} scrollToElement - Smooth scroll to element
     * @property {Function} debounce - Debounce function execution
     * @property {Function} isInViewport - Check if element is visible
     */
    return {
        safeCreateElement: safeCreateElement,
        sprintf: sprintf,
        showTableWhenReady: showTableWhenReady,
        getImageRow: getImageRow,
        scrollToElement: scrollToElement,
        debounce: debounce,
        isInViewport: isInViewport
    };

})(jQuery);

// Export for ES6 modules
export default helpers;
