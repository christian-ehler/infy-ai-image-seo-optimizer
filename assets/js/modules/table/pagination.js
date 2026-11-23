/**
 * Pagination Module - Handles AJAX pagination for the image table
 *
 * This module manages pagination links in the WordPress media library table,
 * enabling seamless AJAX-based page navigation without full page reloads.
 * It updates the table content, navigation elements, and browser URL state.
 *
 * ## Features
 *
 * - AJAX-based pagination (no page reload)
 * - Loading overlay during page transitions
 * - Browser history state management
 * - Automatic table handler reinitialization
 * - Filter preservation across pages
 * - Smooth scroll to table top
 * - Graceful error handling
 *
 * ## Dependencies
 *
 * - jQuery
 * - api module (AJAX helper)
 * - WordPress list table structure
 *
 * ## Usage
 *
 * ```javascript
 * import pagination from './table/pagination.js';
 *
 * // Initialize with callbacks
 * pagination.init(
 *     reinitializeTableHandlers,  // Called after table updates
 *     reinitializeFilters         // Called after filter UI updates
 * );
 *
 * // Re-bind pagination handlers after AJAX updates
 * pagination.handlePaginationLinks();
 * ```
 *
 * @file Pagination Module - AJAX pagination for WordPress media library table.
 * @author InfyPress
 * @since 1.0.0
 * @module table/pagination
 * @requires jQuery
 * @requires module:api
 */

import api from '../api.js';

const pagination = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    /**
     * Reference to table reinitialization callback
     * @type {Function|null}
     * @private
     */
    let reinitCallback = null;

    /**
     * Reference to filter reinitialization callback
     * @type {Function|null}
     * @private
     */
    let filterInitCallback = null;

    /* =========================================================================
       EVENT HANDLERS
       ========================================================================= */

    /**
     * Handles pagination link clicks with AJAX.
     *
     * Intercepts pagination link clicks, performs AJAX request to load new page,
     * and updates table content without full page reload. Shows loading overlay
     * during the request and handles errors gracefully. Maintains filter state
     * and updates browser history.
     *
     * Workflow:
     * 1. Prevent default link behavior
     * 2. Show loading overlay on table
     * 3. Fetch new page HTML via AJAX
     * 4. Parse response and extract table/navigation elements
     * 5. Replace current table and navigation
     * 6. Update browser URL with pushState
     * 7. Scroll to top of table
     * 8. Reinitialize event handlers via callbacks
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function handlePaginationLinks() {
        // Remove existing handlers to prevent duplicates
        $(document).off('click.infypPagination', '.tablenav-pages a');

        // Add AJAX handler for pagination links
        $(document).on('click.infypPagination', '.tablenav-pages a', function(e) {
            e.preventDefault();

            const href = $(this).attr('href');
            if (!href || href === '#') return;

            // Get table reference
            const $table = $('.wp-list-table.widefat.fixed.striped.table-view-list.images');
            const tablePosition = $table.position();
            const tableWidth = $table.outerWidth();
            const tableHeight = $table.outerHeight();

            // Remove any existing overlay
            $('.infyp-table-overlay').remove();

            // Add loading overlay positioned over the table
            $table.parent().css('position', 'relative');
            $table.parent().append(
                '<div class="infyp-table-overlay" style="position: absolute; top: ' + tablePosition.top + 'px; left: ' + tablePosition.left + 'px; width: ' + tableWidth + 'px; height: ' + tableHeight + 'px; z-index: 100; display: flex; align-items: center; justify-content: center;">' +
                '<span class="spinner is-active" style="float: none;"></span>' +
                '</div>'
            );

            // Perform AJAX request
            $.ajax({
                url: href,
                type: 'GET',
                dataType: 'html',
                success: function(response) {
                    try {
                        const $response = $(response);

                        // Find new table elements
                        const $newTable = $response.find('.wp-list-table').first();
                        const $newTopNav = $response.find('.tablenav.top').first();
                        const $newBottomNav = $response.find('.tablenav.bottom').first();

                        if ($newTable.length) {
                            // Replace table and navigation
                            $('.wp-list-table').replaceWith($newTable);

                            if ($newTopNav.length) {
                                $('.tablenav.top').replaceWith($newTopNav);
                            }
                            if ($newBottomNav.length) {
                                $('.tablenav.bottom').replaceWith($newBottomNav);
                            }

                            // Update browser URL
                            window.history.pushState({}, '', href);

                            // Scroll to top of table (safely check offset exists)
                            const $wrap = $('.wrap');
                            if ($wrap.length && $wrap.offset()) {
                                $('html, body').animate({
                                    scrollTop: $wrap.offset().top - 32
                                }, 300);
                            }

                            // Reinitialize handlers
                            if (reinitCallback && typeof reinitCallback === 'function') {
                                reinitCallback();
                            }

                            // Reinitialize post type filter
                            if (filterInitCallback && typeof filterInitCallback === 'function') {
                                filterInitCallback();
                            }
                        }
                    } catch (error) {
                        console.error('Error updating table:', error);
                    } finally {
                        // Remove loading overlay
                        $('.infyp-table-overlay').fadeOut(200, function() {
                            $(this).remove();
                        });
                    }
                },
                error: function() {
                    $('.infyp-table-overlay').remove();
                    alert('Failed to load page. Please try again.');
                }
            });
        });
    }

    /* =========================================================================
       INITIALIZATION
       ========================================================================= */

    /**
     * Initializes pagination handlers.
     *
     * Sets up AJAX pagination for the table. Should be called on page load
     * and after table updates that might add new pagination links. Stores
     * callback references for use in the AJAX success handler.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {Function} reinitializeFn - Callback to reinitialize table handlers after pagination.
     * @param {Function} filterInitFn - Callback to reinitialize filter handlers after pagination.
     * @returns {void}
     */
    function init(reinitializeFn, filterInitFn) {
        // Store callbacks for use in AJAX success handler
        if (typeof reinitializeFn === 'function') {
            reinitCallback = reinitializeFn;
        }
        if (typeof filterInitFn === 'function') {
            filterInitCallback = filterInitFn;
        }

        // Initialize pagination handlers
        handlePaginationLinks();
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the pagination module.
     *
     * @since 1.0.0
     *
     * @returns {Object} Public methods.
     * @property {Function} init - Initialize pagination with callbacks.
     * @property {Function} handlePaginationLinks - Bind pagination click handlers (for re-initialization).
     */
    return {
        init,
        handlePaginationLinks
    };

})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = pagination;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.pagination = pagination;
}

export default pagination;
