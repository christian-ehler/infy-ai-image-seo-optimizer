/**
 * Filtering Module - Handles post type filtering for the image table
 *
 * This module manages the post type filter UI and AJAX operations for filtering
 * the WordPress media library table by post types (posts, pages, attachments, etc.).
 * Features include: token-based multi-select, dropdown search, and max selection limits.
 *
 * ## Features
 *
 * - Token-based multi-select filter (visual badges)
 * - Dropdown with live search functionality
 * - Maximum 3 simultaneous selections
 * - AJAX table filtering without page reload
 * - Clean URL format (plus-separated values)

 * - Keyboard navigation (ESC to close)
 * - Filter state preservation across pagination
 *
 * ## URL Format
 *
 * - Single filter: `?post_type_filter=post`
 * - Multiple filters: `?post_type_filter=post+page+media`
 * - All types: `?post_type_filter=all`
 *
 * ## Dependencies
 *
 * - jQuery
 * - api module (AJAX helper)
 * - infyp_vars global (i18n strings, admin_url)
 *
 * ## Usage
 *
 * ```javascript
 * import filtering from './table/filtering.js';
 *
 * // Initialize with reinit callback
 * filtering.init(reinitializeTableHandlers);
 *
 * // Re-initialize filter UI after AJAX updates
 * filtering.handlePostTypeFilter();
 * ```
 *
 * @file Filtering Module - Post type filter for WordPress media library table.
 * @author InfyPress
 * @since 1.0.0
 * @module table/filtering
 * @requires jQuery
 * @requires module:api
 */

import api from '../api.js';

const filtering = (function($) {
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
     * Reference to self reinitialization callback
     * @type {Function|null}
     * @private
     */
    let selfInitCallback = null;

    /**
     * Session storage key for filter state persistence
     * @constant {string}
     */
    const FILTER_STATE_KEY = 'infyp_filter_state';

    /* =========================================================================
       FILTER STATE PERSISTENCE
       ========================================================================= */

    /**
     * Saves filter state to sessionStorage
     *
     * Persists the current filter selection so it can be restored
     * when the user navigates back to the page within the same session.
     *
     * @since 1.1.0
     * @access private
     * @param {string} filterValue - Current filter value
     * @returns {void}
     */
    function saveFilterState(filterValue) {
        try {
            sessionStorage.setItem(FILTER_STATE_KEY, filterValue);
        } catch (e) {
            // sessionStorage not available, ignore
        }
    }

    /**
     * Restores filter state from sessionStorage
     *
     * Returns the previously saved filter state, or null if none exists.
     * Only returns a value if the URL doesn't already have a filter set.
     *
     * @since 1.1.0
     * @access private
     * @returns {string|null} Saved filter state or null
     */
    function getSavedFilterState() {
        // If URL already has a filter, don't override
        const url = new URL(window.location.href);
        if (url.searchParams.has('post_type_filter')) {
            return null;
        }

        try {
            return sessionStorage.getItem(FILTER_STATE_KEY);
        } catch (e) {
            return null;
        }
    }

    /**
     * Clears saved filter state
     *
     * @since 1.1.0
     * @access private
     * @returns {void}
     */
    function clearFilterState() {
        try {
            sessionStorage.removeItem(FILTER_STATE_KEY);
        } catch (e) {
            // sessionStorage not available, ignore
        }
    }

    /* =========================================================================
       HELPER FUNCTIONS
       ========================================================================= */

    /**
     * Escapes HTML special characters to prevent XSS.
     *
     * Converts HTML-sensitive characters to their entity equivalents
     * for safe insertion into the DOM.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {string} text - Text to escape.
     * @returns {string} Escaped text safe for HTML insertion.
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    /* =========================================================================
       FILTER MANAGEMENT
       ========================================================================= */

    /**
     * Handles post type filter initialization and events.
     *
     * Sets up the token-based filter with search functionality.
     * Manages filter tokens, dropdown interactions, and AJAX filtering.
     * Supports maximum 3 simultaneous post type selections.
     *
     * Initializes nested functions for:
     * - Token management (add/remove)
     * - Dropdown state (show/hide/search)
     * - Selection validation (max limit)
     * - AJAX filtering with loading states
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function handlePostTypeFilter() {
        // Use more specific selector to exclude modal filter from main table operations
        const $filterWrapper = $('.infyp-post-type-filter-wrapper').not('.infyp-modal-post-type-filter');
        const $tokenList = $('#infyp-post-type-tokens');
        const $filterInput = $('#infyp-post-type-input');
        const $filterValue = $('#infyp-post-type-filter-value');
        const $dropdown = $('#infyp-post-type-dropdown');
        const $dropdownSearch = $('#infyp-dropdown-search');

        if (!$filterWrapper.length) return;

        // Store original placeholder text from server-rendered HTML
        const originalPlaceholder = $filterInput.attr('placeholder') || 'Add post type filter...';
        const maxFiltersText = infyp_vars.i18n.max_filters_allowed || 'Max 3 filters allowed';

        // Remove any existing event handlers to prevent duplicates
        $filterInput.off('.postTypeFilter');
        $dropdownSearch.off('.postTypeFilter');
        $(document).off('.postTypeFilter');

        let isFiltering = false;
        let selectedTypes = [];
        const MAX_SELECTIONS = 3; // Maximum number of post types that can be selected

        /**
         * Initializes selected types from existing tokens.
         *
         * Parses DOM tokens to rebuild selectedTypes array from current state.
         * Called on initialization to sync JavaScript state with server-rendered HTML.
         *
         * @since 1.0.0
         * @access private
         * @memberof handlePostTypeFilter
         *
         * @returns {void}
         */
        function initializeSelectedTypes() {
            selectedTypes = [];
            $tokenList.find('.infyp-filter-token').each(function() {
                const type = $(this).data('type');
                if (type) selectedTypes.push(type);
            });
        }

        /**
         * Shows dropdown with proper positioning.
         *
         * Updates option states before displaying and handles viewport overflow
         * by flipping dropdown to top if it would extend below viewport.
         *
         * @since 1.0.0
         * @access private
         * @memberof handlePostTypeFilter
         *
         * @returns {void}
         */
        function showDropdown() {
            // Update dropdown options based on current selections
            updateDropdownOptionsState();

            $dropdown.show();
            $dropdownSearch.focus();
            $filterInput.attr('aria-expanded', 'true');

            // Position dropdown if needed (flip to top if would overflow)
            const wrapperRect = $filterWrapper[0].getBoundingClientRect();
            const dropdownRect = $dropdown[0].getBoundingClientRect();
            if (dropdownRect.bottom > window.innerHeight) {
                $dropdown.css('bottom', '100%').css('top', 'auto').css('margin-bottom', '4px').css('margin-top', '0');
            }
        }

        /**
         * Updates dropdown options based on selection state.
         *
         * Handles max selection limit, selected state, and disabled state.
         * Updates visual indicators (checkmarks) and tooltips for options.
         * Disables options when max selection limit is reached.
         *
         * @since 1.0.0
         * @access private
         * @memberof handlePostTypeFilter
         *
         * @returns {void}
         */
        function updateDropdownOptionsState() {
            const limitReached = selectedTypes.length >= MAX_SELECTIONS && !selectedTypes.includes('all');

            // Update input placeholder based on limit
            if (limitReached) {
                $filterInput.addClass('limit-reached');
                $filterInput.attr('placeholder', maxFiltersText);
            } else {
                $filterInput.removeClass('limit-reached');
                $filterInput.attr('placeholder', originalPlaceholder);
            }

            $dropdown.find('.infyp-dropdown-option').each(function() {
                const $option = $(this);
                const value = $option.data('value');
                const isSelected = selectedTypes.includes(value);
                // Update selected state and show/hide check icon
                if (isSelected) {
                    $option.addClass('infyp-option-selected');
                    $option.find('.infyp-selected-check').show();
                } else {
                    $option.removeClass('infyp-option-selected');
                    $option.find('.infyp-selected-check').hide();
                }

                // Don't modify already selected items or "All"
                if (isSelected || value === 'all') {
                    if (!isSelected && value !== 'all' && limitReached) {
                        $option.addClass('infyp-option-limit-reached');
                        $option.attr('title', 'Maximum ' + MAX_SELECTIONS + ' selections allowed');
                    }
                    return;
                }

                if (limitReached) {
                    $option.addClass('infyp-option-limit-reached');
                    $option.attr('title', 'Maximum ' + MAX_SELECTIONS + ' selections allowed');
                } else {
                    $option.removeClass('infyp-option-limit-reached');
                    $option.removeAttr('title');
                }
            });
        }

        /**
         * Hide dropdown and reset search
         */
        function hideDropdown() {
            $dropdown.hide();
            $filterInput.attr('aria-expanded', 'false');
            $dropdownSearch.val('');
            filterDropdownOptions('');
        }

        /**
         * Filters dropdown options based on search term.
         *
         * Shows/hides dropdown options based on whether their label matches
         * the search term (case-insensitive).
         *
         * @since 1.0.0
         * @access private
         * @memberof handlePostTypeFilter
         *
         * @param {string} searchTerm - Search query to filter options.
         * @returns {void}
         */
        function filterDropdownOptions(searchTerm) {
            const term = searchTerm.toLowerCase();
            $dropdown.find('.infyp-dropdown-option').each(function() {
                const $option = $(this);
                const label = ($option.data('label') || $option.text()).toLowerCase();
                if (term === '' || label.includes(term)) {
                    $option.show();
                } else {
                    $option.hide();
                }
            });
        }

        /**
         * Applies filter with AJAX.
         *
         * Uses the ajaxTableLoader to reload table with selected filters.
         * This integrates with the async table loading architecture.
         * Saves filter state to sessionStorage for persistence.
         *
         * @since 1.0.0
         * @access private
         * @memberof handlePostTypeFilter
         *
         * @returns {void}
         */
        function applyFilter() {
            if (isFiltering) return;
            isFiltering = true;

            // Build filter string
            let filterValue;
            if (selectedTypes.includes('all') || selectedTypes.length === 0) {
                filterValue = 'all';
                // Clear filter state when "all" is selected
                clearFilterState();
            } else if (selectedTypes.length === 1) {
                filterValue = selectedTypes[0];
            } else {
                filterValue = selectedTypes.join('+');
            }

            // Save filter state for session persistence
            if (filterValue !== 'all') {
                saveFilterState(filterValue);
            }

            // Update hidden input
            $filterValue.val(filterValue);

            // Clear page/post filter when post type changes (selected post may not match new type)
            $(document).trigger('infyp:postTypeFilterChanged');

            // Use ajaxTableLoader if available (preferred for performance)
            if (window.infyp && window.infyp.ajaxTableLoader) {
                window.infyp.ajaxTableLoader.setFilter(filterValue)
                    .done(function() {
                        // Reinitialize handlers for the new table
                        if (reinitCallback && typeof reinitCallback === 'function') {
                            reinitCallback();
                        }
                    })
                    .always(function() {
                        isFiltering = false;
                    });
            } else {
                // Fallback to page reload
                const url = new URL(window.location.href);
                url.searchParams.set('post_type_filter', filterValue);
                url.searchParams.delete('paged');
                window.location.href = url.toString();
            }
        }

        /**
         * Adds token to list.
         *
         * Creates and displays a filter token (badge) for the selected post type.
         * Validates max selections and removes "All" token if present.
         * Automatically triggers filter application after adding token.
         *
         * @since 1.0.0
         * @access private
         * @memberof handlePostTypeFilter
         *
         * @param {string} type - Post type value (e.g., 'post', 'page').
         * @param {string} label - Display label for token.
         * @returns {void}
         */
        function addToken(type, label) {
            // Check maximum selections limit (excluding 'all')
            if (selectedTypes.length >= MAX_SELECTIONS && !selectedTypes.includes('all')) {
                console.warn('Maximum selection limit reached:', MAX_SELECTIONS);
                return;
            }

            // Remove "All" token if it exists and update selectedTypes
            if ($tokenList.find('.infyp-all-token').length > 0) {
                $tokenList.find('.infyp-all-token').remove();
                // Remove 'all' from selectedTypes
                selectedTypes = selectedTypes.filter(t => t !== 'all');
            }

            // Check if already added
            if (selectedTypes.includes(type)) return;

            // Create token HTML
            // Get aria-label from existing token or use default
            const removeLabel = $('.infyp-filter-token-remove').first().attr('aria-label') || 'Remove filter';
            let tokenHtml = '<span class="infyp-filter-token" data-type="' + type + '">';
            tokenHtml += label;
            tokenHtml += '<button type="button" class="infyp-filter-token-remove" aria-label="' + escapeHtml(removeLabel) + '">×</button>';
            tokenHtml += '</span>';

            // Insert before input
            $(tokenHtml).insertBefore($filterInput);

            // Update selected types
            selectedTypes.push(type);

            // Apply filter
            applyFilter();
        }

        /**
         * Removes token from list.
         *
         * Removes the filter token from the DOM and updates the selectedTypes array.
         * If no tokens remain after removal, adds an "All" token to represent no filtering.
         * Automatically triggers filter application after removing token.
         *
         * @since 1.0.0
         * @access private
         * @memberof handlePostTypeFilter
         *
         * @param {jQuery} $token - Token element to remove.
         * @returns {void}
         */
        function removeToken($token) {
            const type = $token.data('type');

            // Remove from DOM
            $token.remove();

            // Update selected types
            selectedTypes = selectedTypes.filter(t => t !== type);

            // If no tokens left, add "All" token
            if (selectedTypes.length === 0) {
                // Get the "All" label from the dropdown option
                const allLabel = $('.infyp-dropdown-option[data-value="all"] span').first().text() || 'All';
                const allTokenHtml = '<span class="infyp-filter-token infyp-all-token" data-type="all">' +
                    escapeHtml(allLabel) +
                    '<button type="button" class="infyp-filter-token-remove" aria-label="Clear filter">×</button>' +
                    '</span>';
                $(allTokenHtml).insertBefore($filterInput);
                selectedTypes = ['all'];
            }

            // Apply filter
            applyFilter();
        }

        // Initialize
        initializeSelectedTypes();

        // Event: Click on input to show dropdown
        $filterInput.on('focus.postTypeFilter click.postTypeFilter', function(e) {
            e.stopPropagation();
            showDropdown();
        });

        // Event: Type in input to filter dropdown
        $filterInput.on('input.postTypeFilter', function() {
            const searchTerm = $(this).val();
            filterDropdownOptions(searchTerm);
        });

        // Event: Search in dropdown
        $dropdownSearch.on('input.postTypeFilter', function() {
            const searchTerm = $(this).val();
            filterDropdownOptions(searchTerm);
        });

        // Event: Click on dropdown option
        $(document).on('click.postTypeFilter', '.infyp-dropdown-option', function(e) {
            e.stopPropagation();

            const $option = $(this);
            const value = $option.data('value');
            const label = $option.data('label') || $option.find('span').first().text();

            // Handle "All" selection
            if (value === 'all') {
                // Clear all tokens
                $tokenList.find('.infyp-filter-token').remove();

                // Add "All" token with proper label
                const allTokenHtml = '<span class="infyp-filter-token infyp-all-token" data-type="all">' +
                    escapeHtml(label) +
                    '<button type="button" class="infyp-filter-token-remove" aria-label="Clear filter">×</button>' +
                    '</span>';
                $(allTokenHtml).insertBefore($filterInput);
                selectedTypes = ['all'];

                // Apply filter
                applyFilter();
            } else {
                // Check if we've reached the maximum selections
                if (selectedTypes.length >= MAX_SELECTIONS && !selectedTypes.includes('all')) {
                    alert(infyp_vars.i18n.max_filters_allowed || 'Max ' + MAX_SELECTIONS + ' filters allowed');
                    return;
                }

                // Add as token
                addToken(value, label);
            }

            // Clear input and hide dropdown
            $filterInput.val('');
            hideDropdown();
        });

        // Event: Remove token
        $(document).on('click.postTypeFilter', '#infyp-post-type-tokens .infyp-filter-token-remove', function(e) {
            e.stopPropagation();
            const $token = $(this).closest('.infyp-filter-token');

            // If removing "All", don't do anything
            if ($token.hasClass('infyp-all-token')) {
                return;
            }

            removeToken($token);
        });

        // Event: Click outside to close dropdown
        $(document).on('click.postTypeFilter', function(e) {
            if (!$(e.target).closest('.infyp-post-type-filter-wrapper').length) {
                hideDropdown();
            }
        });

        // Event: ESC key to close dropdown
        $(document).on('keydown.postTypeFilter', function(e) {
            if (e.key === 'Escape' && $dropdown.is(':visible')) {
                hideDropdown();
                $filterInput.blur();
            }
        });
    }

    /* =========================================================================
       INITIALIZATION
       ========================================================================= */

    /**
     * Initializes post type filtering.
     *
     * Sets up the filter UI and event handlers. Should be called on page load
     * and after table updates that might replace the filter UI. Stores callback
     * references for use in AJAX success handlers.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {Function} reinitializeFn - Callback to reinitialize table handlers after filtering.
     * @returns {void}
     */
    function init(reinitializeFn) {
        // Store callbacks for use in AJAX success handler
        if (typeof reinitializeFn === 'function') {
            reinitCallback = reinitializeFn;
        }

        // Store reference to self for re-initialization after AJAX
        selfInitCallback = handlePostTypeFilter;

        // Initialize filter handlers
        handlePostTypeFilter();
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the filtering module.
     *
     * @since 1.0.0
     *
     * @returns {Object} Public methods.
     * @property {Function} init - Initialize filtering with reinit callback.
     * @property {Function} handlePostTypeFilter - Bind filter event handlers (for re-initialization).
     */
    return {
        init,
        handlePostTypeFilter
    };

})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = filtering;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.filtering = filtering;
}

export default filtering;
