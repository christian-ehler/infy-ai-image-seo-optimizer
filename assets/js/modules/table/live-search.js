/**
 * Live Search Module - AJAX table filtering for image search
 *
 * This module provides real-time search functionality for the WordPress
 * media library table. It implements debounced AJAX requests to filter
 * images by filename, alt text, title, or other metadata without page reloads.
 *
 * ## Features
 *
 * - Debounced input handling (500ms delay)
 * - Minimum 2 character search threshold
 * - Loading overlay during search
 * - Browser history state management
 * - Clear button for resetting search
 * - Enter key for immediate search
 *
 * ## Dependencies
 *
 * - jQuery
 * - WordPress media library page structure
 *
 * ## Usage
 *
 * ```javascript
 * import liveSearch from './table/live-search.js';
 *
 * // Initialize with table reinit callback
 * liveSearch.init(reinitializeTableHandlers);
 *
 * // Programmatic search
 * liveSearch.search('landscape');
 *
 * // Clear search
 * liveSearch.clear();
 * ```
 *
 * @file AJAX-based live search with debouncing for the WordPress media library table.
 * @author InfyPress
 * @since 1.0.0
 * @module table/live-search
 * @requires jQuery
 */

const liveSearch = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    /**
     * Debounce timer reference for search input
     * @type {number|null}
     * @private
     */
    let debounceTimer = null;

    /**
     * Flag to prevent concurrent search requests
     * @type {boolean}
     * @private
     */
    let isSearching = false;

    /**
     * Delay in milliseconds before executing search after input stops
     * @constant {number}
     */
    const DEBOUNCE_DELAY = 500;

    /**
     * Minimum characters required before triggering search
     * @constant {number}
     */
    const MIN_SEARCH_LENGTH = 2;

    /**
     * CSS selectors for DOM elements
     * @constant {Object}
     * @property {string} wrapper - Search wrapper container
     * @property {string} input - Search input field
     * @property {string} spinner - Loading spinner element
     * @property {string} clearBtn - Clear search button
     * @property {string} searchBox - Search box container
     * @property {string} table - WordPress list table
     * @property {string} tableWrapper - Table wrapper container
     */
    const selectors = {
        wrapper: '.infyp-modern-search-wrapper',
        input: '#infyp-modern-search-input',
        spinner: '.infyp-search-spinner',
        clearBtn: '.infyp-search-clear',
        searchBox: '.infyp-modern-search',
        table: '.wp-list-table',
        tableWrapper: '.infyp-table-wrapper'
    };

    /**
     * Callback function to reinitialize table handlers after AJAX update
     * @type {Function|null}
     * @private
     */
    let reinitCallback = null;

    /* =========================================================================
       INITIALIZATION
       ========================================================================= */

    /**
     * Initializes live search functionality.
     *
     * Sets up event handlers for search input, clear button, and keyboard shortcuts.
     * Stores the reinit callback for use after AJAX table updates. Should be called
     * once on page load with the reinitialization callback.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {Function} reinitTableHandlers - Callback to reinitialize table event handlers
     *                                         after AJAX replaces table content.
     * @returns {void}
     */
    function init(reinitTableHandlers) {
        const $input = $(selectors.input);
        if (!$input.length) return;

        reinitCallback = reinitTableHandlers;
        bindEvents();
    }

    /* =========================================================================
       EVENT HANDLERS
       ========================================================================= */

    /**
     * Binds event handlers for search functionality.
     *
     * Sets up:
     * - Input event with debounce for real-time filtering
     * - Enter key for immediate search (bypasses debounce)
     * - Clear button click handler
     * - Show/hide clear button based on input value
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function bindEvents() {
        const $input = $(selectors.input);

        // Input event with debounce
        $input.on('input', function() {
            const query = $(this).val().trim();

            clearTimeout(debounceTimer);

            if (query.length > 0) {
                showClearButton();
            } else {
                hideClearButton();
            }

            // Only search if minimum length or clearing
            if (query.length >= MIN_SEARCH_LENGTH || query.length === 0) {
                debounceTimer = setTimeout(function() {
                    performSearch(query);
                }, DEBOUNCE_DELAY);
            }
        });

        // Enter key to search immediately
        $input.on('keydown', function(e) {
            if (e.keyCode === 13) {
                e.preventDefault();
                clearTimeout(debounceTimer);
                performSearch($(this).val().trim());
            }
        });

        // Clear button click
        $(document).on('click', selectors.clearBtn, function(e) {
            e.preventDefault();
            clearSearch();
        });
    }

    /* =========================================================================
       SEARCH OPERATIONS
       ========================================================================= */

    /**
     * Performs AJAX search and updates table with results.
     *
     * Uses the ajaxTableLoader to search with the async table loading architecture.
     * This ensures the search works correctly with the performance-optimized
     * table loading system that skips prepare_items() on page load.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {string} query - Search query string (empty string clears search).
     * @returns {void}
     */
    function performSearch(query) {
        if (isSearching) return;
        isSearching = true;

        showSpinner();

        // Use ajaxTableLoader if available (preferred for performance)
        if (window.infyp && window.infyp.ajaxTableLoader) {
            window.infyp.ajaxTableLoader.search(query)
                .done(function() {
                    // Reinitialize table handlers
                    if (typeof reinitCallback === 'function') {
                        reinitCallback();
                    }
                })
                .always(function() {
                    hideSpinner();
                    isSearching = false;
                });
        } else {
            // Fallback to page reload
            const currentUrl = new URL(window.location.href);

            if (query.length >= MIN_SEARCH_LENGTH) {
                currentUrl.searchParams.set('s', query);
            } else {
                currentUrl.searchParams.delete('s');
            }

            // Reset to page 1 when searching
            currentUrl.searchParams.delete('paged');

            window.location.href = currentUrl.toString();
        }
    }

    /**
     * Clears search input and reloads table without filter.
     *
     * Resets the search input field, hides the clear button,
     * and performs an empty search to show all results.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function clearSearch() {
        const $input = $(selectors.input);
        $input.val('');
        hideClearButton();
        performSearch('');
    }

    /* =========================================================================
       UI STATE MANAGEMENT
       ========================================================================= */

    /**
     * Shows loading overlay on table during search.
     *
     * Creates a semi-transparent overlay with a centered spinner
     * positioned absolutely over the table to indicate loading state.
     * Removes any existing overlay before creating a new one.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {jQuery} $table - The table element to overlay.
     * @returns {void}
     */
    function showTableLoading($table) {
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
     * Hides and removes the table loading overlay.
     *
     * Fades out the overlay then removes it from the DOM.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function hideTableLoading() {
        $('.infyp-table-overlay').fadeOut(200, function() {
            $(this).remove();
        });
    }

    /**
     * Shows the spinner in the search input field.
     *
     * Activates the WordPress spinner to indicate search in progress.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function showSpinner() {
        $(selectors.spinner).addClass('is-active');
    }

    /**
     * Hides the spinner in the search input field.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function hideSpinner() {
        $(selectors.spinner).removeClass('is-active');
    }

    /**
     * Shows the clear (X) button in the search input.
     *
     * Displayed when there is text in the search input.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function showClearButton() {
        $(selectors.clearBtn).show();
    }

    /**
     * Hides the clear (X) button in the search input.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function hideClearButton() {
        $(selectors.clearBtn).hide();
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the live search module.
     *
     * @since 1.0.0
     *
     * @returns {Object} Public methods.
     * @property {Function} init - Initialize the module with reinit callback.
     * @property {Function} search - Perform a search programmatically.
     * @property {Function} clear - Clear the current search.
     */
    return {
        init: init,
        search: performSearch,
        clear: clearSearch
    };

})(jQuery);

// Export for ES6 modules
export default liveSearch;
