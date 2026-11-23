/**
 * AJAX Table Loader Module - Handles async table data loading
 *
 * This module enables fast initial page load by:
 * 1. Showing skeleton loader immediately
 * 2. Fetching table data via AJAX after DOM ready
 * 3. Rendering table rows without full page reload
 * 4. Handling pagination via AJAX
 *
 * ## Performance Impact
 *
 * - Initial page load: Timeout (10k+ images) -> < 3 seconds
 * - Table loads asynchronously after page shell renders
 * - Pagination doesn't require full page reload
 *
 * @file AJAX Table Loader Module
 * @author InfyPress
 * @since 1.0.0
 * @module table/ajax-table-loader
 * @requires jQuery
 */

const ajaxTableLoader = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    /**
     * Current page state
     * @type {Object}
     * @private
     */
    let state = {
        currentPage: 1,
        perPage: 20,
        totalPages: 1,
        totalItems: 0,
        search: '',
        orderby: 'seo_score',
        order: 'asc',
        postTypeFilter: 'all',
        parentPost: 0,
        isLoading: false,
        cacheWarmed: false,
        isFirstLoad: true,
        loadAttempts: 0,
        maxLoadAttempts: 2,
        cursor: null // Keyset pagination cursor for deep pages {next: {id, value}, prev: {id, value}}
    };

    /**
     * Load timeout reference for fallback handling
     * @type {number|null}
     * @private
     */
    let loadTimeout = null;

    /**
     * Load timeout duration in milliseconds
     * @constant {number}
     */
    const LOAD_TIMEOUT_MS = 10000; // 10 seconds

    /**
     * Maximum number of entries in the prefetch cache.
     * Keeps memory bounded during long browsing sessions.
     * @constant {number}
     */
    const PREFETCH_CACHE_MAX = 5;

    /**
     * Prefetch cache for pagination
     * Stores prefetched page data for instant navigation.
     * Limited to PREFETCH_CACHE_MAX entries; oldest evicted first.
     * @type {Map}
     * @private
     */
    const prefetchCache = new Map();

    /**
     * Currently prefetching pages (to avoid duplicate requests)
     * @type {Set}
     * @private
     */
    const prefetchingPages = new Set();

    /**
     * Session storage key for tracking cache warm status
     * @type {string}
     * @private
     */
    const CACHE_WARM_KEY = 'infyp_cache_warmed';

    /**
     * Session storage key for tracking index build status
     * @type {string}
     * @private
     */
    const INDEX_BUILD_KEY = 'infyp_indexes_built';

    /**
     * Callback for reinitializing table handlers
     * @type {Function|null}
     * @private
     */
    let reinitCallback = null;

    /**
     * Callback for reinitializing filter handlers
     * @type {Function|null}
     * @private
     */
    let filterInitCallback = null;

    /* =========================================================================
       SKELETON LOADER
       ========================================================================= */

    /**
     * Shows skeleton loader in table body
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function showSkeletonLoader() {
        const $tbody = $('.wp-list-table.images tbody');
        if (!$tbody.length) return;

        // Get column count from thead
        const columnCount = $('.wp-list-table.images thead th').length || 10;

        // Create skeleton rows
        let skeletonHtml = '';
        for (let i = 0; i < state.perPage; i++) {
            skeletonHtml += '<tr class="infyp-skeleton-row">';
            for (let j = 0; j < columnCount; j++) {
                skeletonHtml += '<td class="infyp-skeleton-cell"><div class="infyp-skeleton-content"></div></td>';
            }
            skeletonHtml += '</tr>';
        }

        $tbody.html(skeletonHtml);

        // Show skeleton wrapper and hide real table content
        $('.infyp-table-skeleton').show();
        $('.infyp-table-wrapper').addClass('infyp-loading');
    }

    /**
     * Hides skeleton loader and shows real table
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function hideSkeletonLoader() {
        const $skeleton = $('.infyp-table-skeleton');
        const $wrapper = $('.infyp-table-wrapper');

        // Hide the skeleton loader
        $skeleton.hide().addClass('infyp-skeleton-hidden');

        // Show the table wrapper and mark as ready
        // Adding infyp-table-ready class signals to showTableWhenReady() that
        // we've already handled visibility, preventing race condition flicker
        $wrapper
            .show()
            .removeClass('infyp-loading')
            .addClass('infyp-table-ready');

        // Also add body class for search/filter visibility
        $('body').addClass('infyp-page-ready');
    }

    /* =========================================================================
       CACHE WARMING
       ========================================================================= */

    /**
     * Shows cache warming banner on first load
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function showCacheWarmingBanner() {
        // Check if already shown this session
        if (sessionStorage.getItem(CACHE_WARM_KEY)) {
            return;
        }

        const i18n = window.infyp_vars?.i18n || window.infyp_data?.i18n || {};
        const message = i18n.preparing_library || 'Preparing your image library for faster performance...';

        const html = `
            <div class="infyp-cache-warming-banner" id="infyp-cache-warming">
                <span class="spinner is-active" style="float: none; margin: 0;"></span>
                <span class="infyp-cache-warming-message">${message}</span>
            </div>
        `;

        // Insert before table wrapper
        const $tableWrap = $('.infyp-table-wrapper, .wp-list-table.images').first().parent();
        if ($tableWrap.length) {
            $tableWrap.before(html);
        }
    }

    /**
     * Hides cache warming banner
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function hideCacheWarmingBanner() {
        $('#infyp-cache-warming').fadeOut(300, function() {
            $(this).remove();
        });
    }

    /**
     * Triggers background cache warming via AJAX
     * Warms cache for common filter combinations to speed up future loads
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function warmCache() {
        // Check if already warmed this session
        if (sessionStorage.getItem(CACHE_WARM_KEY)) {
            state.cacheWarmed = true;
            // Still hide the banner in case it was shown
            hideCacheWarmingBanner();
            return;
        }

        const nonce = window.infyp_vars?.nonce || window.infyp_data?.nonce || window.infyp_ajax?.nonce || '';

        // Fire and forget - don't block UI
        $.ajax({
            url: window.ajaxurl,
            type: 'POST',
            data: {
                action: 'infyp_warm_table_cache',
                nonce: nonce
            },
            dataType: 'json'
        })
        .done(function(response) {
            if (response.success) {
                state.cacheWarmed = true;
                sessionStorage.setItem(CACHE_WARM_KEY, 'true');
            }
        })
        .always(function() {
            // Hide banner after warming completes (or fails)
            setTimeout(hideCacheWarmingBanner, 500);
        });
    }

    /**
     * Builds search and filter indexes for fast queries
     *
     * Creates lookup tables on first load for 10-100x faster filtering and searching.
     * This runs in the background and doesn't block the UI.
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function buildSearchIndexes() {
        // Check if already built this session
        if (sessionStorage.getItem(INDEX_BUILD_KEY)) {
            return;
        }

        const nonce = window.infyp_vars?.nonce || window.infyp_data?.nonce || window.infyp_ajax?.nonce || '';

        // Fire and forget - don't block UI
        $.ajax({
            url: window.ajaxurl,
            type: 'POST',
            data: {
                action: 'infyp_build_search_indexes',
                nonce: nonce
            },
            dataType: 'json'
        })
        .done(function(response) {
            if (response.success) {
                sessionStorage.setItem(INDEX_BUILD_KEY, 'true');
                // Lookup stats available in response.data.lookup_stats if needed for debugging
            }
        });
    }

    /* =========================================================================
       AJAX DATA LOADING
       ========================================================================= */

    /**
     * Loads table data via AJAX
     *
     * Checks prefetch cache first for instant navigation. Falls back to
     * AJAX request if data not prefetched.
     *
     * @since 1.0.0
     * @access private
     * @param {number} page - Page number to load
     * @param {boolean} updateUrl - Whether to update browser URL (default: true, set false for initial load)
     * @returns {Promise} jQuery AJAX promise
     */
    function loadTableData(page = 1, updateUrl = true) {
        if (state.isLoading) {
            return $.Deferred().reject('Already loading').promise();
        }

        // Check prefetch cache first for instant navigation
        if (prefetchCache.has(page)) {
            const cachedData = prefetchCache.get(page);
            prefetchCache.delete(page); // Use once, then remove

            state.currentPage = page;
            updateTable(cachedData);
            updateState(cachedData);
            if (updateUrl) {
                updateUrlState();
            }

            // Prefetch adjacent pages for next navigation
            prefetchPage(page - 1);
            prefetchPage(page + 1);

            // Reinitialize handlers
            bindPaginationHandlers();

            return $.Deferred().resolve(cachedData).promise();
        }

        state.isLoading = true;
        state.currentPage = page;

        // Show loading state
        showLoadingOverlay();

        // Get nonce from localized script data
        const nonce = window.infyp_vars?.nonce || window.infyp_data?.nonce || window.infyp_ajax?.nonce || '';

        // Build request data
        const requestData = {
            action: 'infyp_get_table_data',
            nonce: nonce,
            page: page,
            per_page: state.perPage,
            search: (state.search || '').trim(),
            orderby: state.orderby,
            order: state.order,
            post_type_filter: state.postTypeFilter,
            parent_post: state.parentPost
        };

        // For deep pages (>50), use keyset pagination cursor for constant-time access
        // Only for sequential next/prev navigation (cursor available from previous response)
        if (page > 50 && state.cursor) {
            const isNext = page > state.currentPage;
            const cursorDir = isNext ? 'next' : 'prev';
            const cursorData = state.cursor[cursorDir];

            if (cursorData && cursorData.id) {
                requestData.cursor_id = cursorData.id;
                requestData.cursor_value = cursorData.value;
                requestData.cursor_direction = cursorDir;
            }
        }

        return $.ajax({
            url: window.ajaxurl,
            type: 'POST',
            data: requestData,
            dataType: 'json'
        })
        .done(function(response) {
            if (response.success && response.data) {
                updateTable(response.data);
                updateState(response.data);
                // Only update URL if requested (skip on initial load)
                if (updateUrl) {
                    updateUrlState();
                }

                // Prefetch next page for smoother navigation
                // Small delay to prioritize current page render
                setTimeout(function() {
                    prefetchPage(state.currentPage + 1);
                }, 200);
            } else {
                showError(response.data?.message || 'Failed to load table data');
            }
        })
        .fail(function(xhr, status, error) {
            console.error('AJAX error:', error);
            showError('Network error. Please try again.');
        })
        .always(function() {
            state.isLoading = false;
            hideLoadingOverlay();
            hideSkeletonLoader();
        });
    }

    /**
     * Prefetch a page in the background for instant navigation
     *
     * Called on mouseenter of pagination buttons to load data before click.
     * Stores result in prefetchCache for instant display when user clicks.
     *
     * @since 1.0.0
     * @access private
     * @param {number} page - Page number to prefetch
     * @returns {void}
     */
    function prefetchPage(page) {
        // Don't prefetch current page, invalid pages, or pages already cached/loading
        if (page === state.currentPage ||
            page < 1 ||
            page > state.totalPages ||
            prefetchCache.has(page) ||
            prefetchingPages.has(page) ||
            state.isLoading) {
            return;
        }

        // Mark as prefetching to avoid duplicate requests
        prefetchingPages.add(page);

        // Get nonce
        const nonce = window.infyp_vars?.nonce || window.infyp_data?.nonce || window.infyp_ajax?.nonce || '';

        // Build request data
        const requestData = {
            action: 'infyp_get_table_data',
            nonce: nonce,
            page: page,
            per_page: state.perPage,
            search: (state.search || '').trim(),
            orderby: state.orderby,
            order: state.order,
            post_type_filter: state.postTypeFilter,
            parent_post: state.parentPost
        };

        // For deep pages, use keyset cursor if available
        if (page > 50 && state.cursor) {
            const isNext = page > state.currentPage;
            const cursorDir = isNext ? 'next' : 'prev';
            const cursorData = state.cursor[cursorDir];

            if (cursorData && cursorData.id) {
                requestData.cursor_id = cursorData.id;
                requestData.cursor_value = cursorData.value;
                requestData.cursor_direction = cursorDir;
            }
        }

        // Silent AJAX request (no loading overlay)
        $.ajax({
            url: window.ajaxurl,
            type: 'POST',
            data: requestData,
            dataType: 'json'
        })
        .done(function(response) {
            if (response.success && response.data) {
                // Evict oldest entry if cache is at capacity
                if (prefetchCache.size >= PREFETCH_CACHE_MAX) {
                    const oldestKey = prefetchCache.keys().next().value;
                    prefetchCache.delete(oldestKey);
                }
                // Store in prefetch cache
                prefetchCache.set(page, response.data);
            }
        })
        .always(function() {
            prefetchingPages.delete(page);
        });
    }

    /**
     * Clear prefetch cache when filters/search change
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function clearPrefetchCache() {
        prefetchCache.clear();
        prefetchingPages.clear();
        state.cursor = null; // Cursor invalid when filters/search change
    }

    /**
     * Shows loading overlay on table
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function showLoadingOverlay() {
        const $table = $('.wp-list-table.images');
        if (!$table.length) return;

        // Remove any existing overlay
        $('.infyp-table-loading-overlay').remove();

        // Add overlay
        $table.parent().css('position', 'relative');
        $table.parent().append(
            '<div class="infyp-table-loading-overlay">' +
            '<div class="infyp-loading-spinner">' +
            '<span class="spinner is-active"></span>' +
            '<span class="infyp-loading-text">' + (window.infyp_vars?.i18n?.loading || 'Loading...') + '</span>' +
            '</div>' +
            '</div>'
        );
    }

    /**
     * Hides loading overlay
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function hideLoadingOverlay() {
        $('.infyp-table-loading-overlay').fadeOut(200, function() {
            $(this).remove();
        });
    }

    /**
     * Updates table with new data
     *
     * @since 1.0.0
     * @access private
     * @param {Object} data - Response data from AJAX
     * @returns {void}
     */
    function updateTable(data) {
        const $tbody = $('.wp-list-table.images tbody');

        if (data.has_items && data.rows_html) {
            $tbody.html(data.rows_html);
        } else {
            // Show no items message
            const columnCount = $('.wp-list-table.images thead th').length || 10;
            $tbody.html(
                '<tr class="no-items">' +
                '<td class="colspanchange" colspan="' + columnCount + '">' +
                (window.infyp_vars?.i18n?.no_images || 'No images found.') +
                '</td>' +
                '</tr>'
            );
        }

        // Update pagination
        if (data.pagination_html) {
            // Ensure pagination containers exist (they may be missing on initial load)
            $('.tablenav.top, .tablenav.bottom').each(function() {
                if (!$(this).find('.tablenav-pages').length) {
                    // Add pagination container if it doesn't exist
                    $(this).append('<div class="tablenav-pages"></div>');
                }
            });
            $('.tablenav.top .tablenav-pages').html(data.pagination_html);
            $('.tablenav.bottom .tablenav-pages').html(data.pagination_html);
        }

        // Reinitialize handlers
        if (reinitCallback && typeof reinitCallback === 'function') {
            reinitCallback();
        }

        // Reinitialize pagination click handlers
        bindPaginationHandlers();
    }

    /**
     * Updates internal state from response
     *
     * @since 1.0.0
     * @access private
     * @param {Object} data - Response data from AJAX
     * @returns {void}
     */
    function updateState(data) {
        state.currentPage = data.current_page || 1;
        state.totalPages = data.total_pages || 1;
        state.totalItems = data.total || 0;
        state.perPage = data.per_page || 20;
        // Store cursor data for keyset pagination on deep pages
        state.cursor = data.cursor || null;
    }

    /**
     * Renders inline initial data (Phase 3 Performance Optimization)
     *
     * Uses pre-rendered data from PHP to display the table immediately
     * without an AJAX call. Improves initial load by 200-400ms.
     *
     * @since 1.1.0
     * @access private
     * @param {Object} data - Inline data from infyp_vars.initial_table_data
     * @returns {void}
     */
    function renderInlineData(data) {
        const $tbody = $('.wp-list-table.images tbody');

        if (data.has_items && data.rows_html) {
            $tbody.html(data.rows_html);
        } else {
            // Show no items message
            const columnCount = $('.wp-list-table.images thead th').length || 10;
            $tbody.html(
                '<tr class="no-items">' +
                '<td class="colspanchange" colspan="' + columnCount + '">' +
                (window.infyp_vars?.i18n?.no_images || 'No images found.') +
                '</td>' +
                '</tr>'
            );
        }

        // Update pagination
        if (data.pagination_html) {
            // Ensure pagination containers exist
            $('.tablenav.top, .tablenav.bottom').each(function() {
                if (!$(this).find('.tablenav-pages').length) {
                    $(this).append('<div class="tablenav-pages"></div>');
                }
            });
            $('.tablenav.top .tablenav-pages').html(data.pagination_html);
            $('.tablenav.bottom .tablenav-pages').html(data.pagination_html);
        }

        // Reinitialize handlers
        if (reinitCallback && typeof reinitCallback === 'function') {
            reinitCallback();
        }

        // Bind pagination handlers
        bindPaginationHandlers();
    }

    /**
     * Updates browser URL state
     *
     * Keeps URL clean by only adding parameters when they differ from defaults.
     * paged=1 is never added since page 1 is the default.
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function updateUrlState() {
        const url = new URL(window.location.href);

        // Only set paged if not page 1 (keep URL clean)
        if (state.currentPage > 1) {
            url.searchParams.set('paged', state.currentPage);
        } else {
            url.searchParams.delete('paged');
        }

        if (state.search) {
            url.searchParams.set('s', state.search);
        } else {
            url.searchParams.delete('s');
        }

        if (state.postTypeFilter !== 'all') {
            url.searchParams.set('post_type_filter', state.postTypeFilter);
        } else {
            url.searchParams.delete('post_type_filter');
        }

        if (state.parentPost > 0) {
            url.searchParams.set('parent_post', state.parentPost);
        } else {
            url.searchParams.delete('parent_post');
        }

        // Always include sort params so page reloads preserve sort state
        // and WP_List_Table column headers show the correct sorted indicator.
        url.searchParams.set('orderby', state.orderby);
        url.searchParams.set('order', state.order);

        window.history.replaceState({}, '', url.toString());
    }

    /**
     * Shows error message with retry button
     *
     * @since 1.0.0
     * @access private
     * @param {string} message - Error message
     * @returns {void}
     */
    function showError(message) {
        const $tbody = $('.wp-list-table.images tbody');
        const columnCount = $('.wp-list-table.images thead th').length || 10;

        $tbody.html(
            '<tr class="no-items infyp-error-row">' +
            '<td class="colspanchange" colspan="' + columnCount + '">' +
            '<div class="infyp-table-error">' +
            '<span class="dashicons dashicons-warning"></span> ' +
            message +
            '<button type="button" class="button button-small infyp-retry-load">' +
            (window.infyp_vars?.i18n?.retry || 'Retry') +
            '</button>' +
            '</div>' +
            '</td>' +
            '</tr>'
        );
    }

    /**
     * Shows fallback load button when table fails to load
     *
     * Displayed when initial load times out or fails multiple times.
     * Provides a manual way to trigger table loading.
     *
     * @since 1.1.0
     * @access private
     * @returns {void}
     */
    function showFallbackLoadButton() {
        const $tbody = $('.wp-list-table.images tbody');
        const columnCount = $('.wp-list-table.images thead th').length || 10;
        const i18n = window.infyp_vars?.i18n || {};

        // Clear any skeleton rows
        $tbody.empty();

        $tbody.html(
            '<tr class="no-items infyp-fallback-row">' +
            '<td class="colspanchange" colspan="' + columnCount + '">' +
            '<div class="infyp-table-fallback">' +
            '<span class="dashicons dashicons-images-alt2" style="font-size: 48px; width: 48px; height: 48px; color: #c3c4c7; display: block; margin: 0 auto 16px;"></span>' +
            '<p style="margin: 0 0 16px; color: #646970;">' + (i18n.table_load_failed || 'Table could not load automatically.') + '</p>' +
            '<button type="button" class="button button-primary button-hero infyp-manual-load">' +
            '<span class="dashicons dashicons-update" style="margin-right: 8px;"></span>' +
            (i18n.load_table || 'Load Image Table') +
            '</button>' +
            '</div>' +
            '</td>' +
            '</tr>'
        );

        // Hide skeleton if visible
        hideSkeletonLoader();
    }

    /**
     * Starts load timeout timer
     *
     * If table doesn't load within timeout, shows fallback button.
     *
     * @since 1.1.0
     * @access private
     * @returns {void}
     */
    function startLoadTimeout() {
        clearLoadTimeout();
        loadTimeout = setTimeout(function() {
            // Check if table still doesn't have data
            const $tbody = $('.wp-list-table.images tbody');
            const hasData = $tbody.find('tr:not(.infyp-skeleton-row):not(.no-items):not(.infyp-fallback-row)').length > 0;

            if (!hasData && !state.isLoading) {
                console.warn('INFYP: Table load timeout, showing fallback button');
                showFallbackLoadButton();
            }
        }, LOAD_TIMEOUT_MS);
    }

    /**
     * Clears load timeout timer
     *
     * @since 1.1.0
     * @access private
     * @returns {void}
     */
    function clearLoadTimeout() {
        if (loadTimeout) {
            clearTimeout(loadTimeout);
            loadTimeout = null;
        }
    }

    /* =========================================================================
       EVENT HANDLERS
       ========================================================================= */

    /**
     * Binds pagination click handlers for AJAX loading
     *
     * Also binds mouseenter handlers for prefetching adjacent pages.
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function bindPaginationHandlers() {
        // Remove existing handlers
        $(document).off('click.infypAjaxPage', '.infyp-ajax-page');
        $(document).off('keypress.infypAjaxPage', '.infyp-page-input');
        $(document).off('click.infypRetry', '.infyp-retry-load');
        $(document).off('mouseenter.infypPrefetch', '.infyp-ajax-page');

        // Handle pagination button clicks
        $(document).on('click.infypAjaxPage', '.infyp-ajax-page', function(e) {
            e.preventDefault();
            const page = parseInt($(this).data('page'), 10);
            if (page && page !== state.currentPage && !$(this).prop('disabled')) {
                loadTableData(page).done(function() {
                    scrollToTable();
                });
            }
        });

        // Handle page number input
        $(document).on('keypress.infypAjaxPage', '.infyp-page-input', function(e) {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                const page = parseInt($(this).val(), 10);
                if (page && page >= 1 && page <= state.totalPages && page !== state.currentPage) {
                    loadTableData(page).done(function() {
                        scrollToTable();
                    });
                }
            }
        });

        // Handle retry button
        $(document).on('click.infypRetry', '.infyp-retry-load', function(e) {
            e.preventDefault();
            state.loadAttempts = 0; // Reset attempts on manual retry
            loadTableData(state.currentPage);
        });

        // Handle manual load button (fallback)
        $(document).on('click.infypManualLoad', '.infyp-manual-load', function(e) {
            e.preventDefault();
            const $btn = $(this);

            // Show loading state on button
            $btn.prop('disabled', true);
            $btn.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-update-alt spin');
            $btn.contents().filter(function() { return this.nodeType === 3; }).last()[0].textContent = ' Loading...';

            // Reset attempts and try loading
            state.loadAttempts = 0;
            showSkeletonLoader();

            loadTableData(1, false)
                .always(function() {
                    // Button will be replaced by table content or error
                });
        });

        // Prefetch on hover for instant navigation
        $(document).on('mouseenter.infypPrefetch', '.infyp-ajax-page', function() {
            const page = parseInt($(this).data('page'), 10);
            if (page && !$(this).prop('disabled')) {
                prefetchPage(page);
            }
        });

        // Also intercept traditional pagination links (for backwards compatibility)
        $(document).off('click.infypPaginationLinks', '.tablenav-pages a:not(.infyp-ajax-page)');
        $(document).on('click.infypPaginationLinks', '.tablenav-pages a:not(.infyp-ajax-page)', function(e) {
            e.preventDefault();
            const href = $(this).attr('href');
            if (href) {
                const url = new URL(href, window.location.origin);
                const page = parseInt(url.searchParams.get('paged'), 10) || 1;
                loadTableData(page).done(function() {
                    scrollToTable();
                });
            }
        });
    }

    /**
     * Scrolls to table top
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function scrollToTable() {
        const $wrap = $('.wrap');
        // Check both element exists AND offset() returns a value
        // offset() can return undefined if element is hidden or not in DOM
        if ($wrap.length && $wrap.offset()) {
            $('html, body').animate({
                scrollTop: $wrap.offset().top - 32
            }, 300);
        }
    }

    /**
     * Parses current URL for state
     *
     * @since 1.0.0
     * @access private
     * @returns {void}
     */
    function parseUrlState() {
        const url = new URL(window.location.href);
        state.currentPage = parseInt(url.searchParams.get('paged'), 10) || 1;
        state.search = url.searchParams.get('s') || '';
        state.postTypeFilter = url.searchParams.get('post_type_filter') || 'all';
        state.parentPost = parseInt(url.searchParams.get('parent_post'), 10) || 0;
        state.orderby = url.searchParams.get('orderby') || 'seo_score';
        state.order = url.searchParams.get('order') || 'asc';
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Initializes AJAX table loader
     *
     * @since 1.0.0
     * @access public
     * @param {Object} options - Configuration options
     * @param {Function} options.reinitCallback - Callback for table handler reinitialization
     * @param {Function} options.filterInitCallback - Callback for filter reinitialization
     * @param {boolean} options.loadOnInit - Whether to load data immediately (default: true)
     * @returns {boolean} True if inline data was rendered (reinitCallback was called), false otherwise
     */
    function init(options = {}) {
        // Track whether inline data was rendered (and reinitCallback was called)
        let inlineDataRendered = false;

        // Store callbacks
        if (typeof options.reinitCallback === 'function') {
            reinitCallback = options.reinitCallback;
        }
        if (typeof options.filterInitCallback === 'function') {
            filterInitCallback = options.filterInitCallback;
        }

        // Parse URL for initial state
        parseUrlState();

        // Get per_page from screen options if available
        const screenPerPage = parseInt($('#images_per_page').val(), 10);
        if (screenPerPage) {
            state.perPage = screenPerPage;
        }

        // Bind pagination handlers
        bindPaginationHandlers();

        // Clear prefetch cache when tab becomes hidden to free memory
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearPrefetchCache();
            }
        });

        // Check if this is first load (cache not warmed yet)
        const isFirstLoad = !sessionStorage.getItem(CACHE_WARM_KEY);

        // Note: Cache warming banner removed - skeleton loader already shows loading state

        // Load data if requested (default: true)
        if (options.loadOnInit !== false) {
            // Start load timeout - shows fallback button if load takes too long
            startLoadTimeout();

            // Phase 3 Optimization: Check for inline initial data first
            // This eliminates the first AJAX call, improving load by 200-400ms
            const inlineData = window.infyp_vars?.initial_table_data;

            if (inlineData && inlineData.has_items !== undefined) {
                // Use inline data - no AJAX needed for initial load!
                renderInlineData(inlineData);
                hideSkeletonLoader();
                clearLoadTimeout(); // Success - clear timeout

                // Mark that inline data was rendered (reinitCallback was called)
                inlineDataRendered = true;

                // Update state from inline data
                state.currentPage = inlineData.current_page || 1;
                state.totalPages = inlineData.total_pages || 1;
                state.totalItems = inlineData.total || 0;
                state.perPage = inlineData.per_page || 20;

                // Prefetch page 2 in background for instant navigation
                setTimeout(function() {
                    prefetchPage(2);
                }, 100);

                // Trigger background optimizations
                if (isFirstLoad) {
                    buildSearchIndexes();
                    warmCache();
                }
            } else if (inlineData && inlineData.has_items === false) {
                // Inline data says no items - show empty state
                hideSkeletonLoader();
                clearLoadTimeout();
                const $tbody = $('.wp-list-table.images tbody');
                const columnCount = $('.wp-list-table.images thead th').length || 10;
                $tbody.html(
                    '<tr class="no-items">' +
                    '<td class="colspanchange" colspan="' + columnCount + '">' +
                    (window.infyp_vars?.i18n?.no_images || 'No images found.') +
                    '</td>' +
                    '</tr>'
                );

                // Mark as inline data handled (no items, but no AJAX needed)
                inlineDataRendered = true;

                // Still warm cache for future
                if (isFirstLoad) {
                    buildSearchIndexes();
                    warmCache();
                }
            } else {
                // Fallback: Check if table already has data (non-AJAX initial load)
                const $tbody = $('.wp-list-table.images tbody');
                const hasExistingData = $tbody.find('tr:not(.infyp-skeleton-row):not(.no-items):not(.infyp-fallback-row)').length > 0;

                if (!hasExistingData) {
                    // Show skeleton while loading
                    showSkeletonLoader();

                    // No data yet, load via AJAX (pass false to skip URL update on initial load)
                    loadTableData(state.currentPage, false)
                        .done(function() {
                            clearLoadTimeout(); // Success - clear timeout
                            // After first data load completes, trigger background optimizations
                            if (isFirstLoad) {
                                // Build search indexes for fast filtering (10-100x faster)
                                buildSearchIndexes();
                                // Warm cache for other filter combinations
                                warmCache();
                            }
                        })
                        .fail(function() {
                            // Increment attempts and show fallback if max reached
                            state.loadAttempts++;
                            if (state.loadAttempts >= state.maxLoadAttempts) {
                                showFallbackLoadButton();
                            }
                            // Hide banner even if data load fails
                            hideCacheWarmingBanner();
                        });
                } else {
                    clearLoadTimeout(); // Already have data
                    if (isFirstLoad) {
                        // Table has data but cache might be cold - optimize in background
                        buildSearchIndexes();
                        warmCache();
                    }
                }
            }
        }

        state.isFirstLoad = false;

        // Return whether inline data was rendered (and reinitCallback was called)
        // This allows callers to avoid duplicate handler attachment
        return inlineDataRendered;
    }

    /**
     * Reloads current page
     *
     * @since 1.0.0
     * @access public
     * @returns {Promise} jQuery AJAX promise
     */
    function reload() {
        return loadTableData(state.currentPage);
    }

    /**
     * Loads specific page
     *
     * @since 1.0.0
     * @access public
     * @param {number} page - Page number
     * @returns {Promise} jQuery AJAX promise
     */
    function goToPage(page) {
        return loadTableData(page);
    }

    /**
     * Updates search term and reloads
     *
     * @since 1.0.0
     * @access public
     * @param {string} search - Search term
     * @returns {Promise} jQuery AJAX promise
     */
    function search(searchTerm) {
        state.search = searchTerm;
        state.currentPage = 1;
        clearPrefetchCache(); // Clear prefetch cache when search changes
        return loadTableData(1);
    }

    /**
     * Updates filter and reloads
     *
     * @since 1.0.0
     * @access public
     * @param {string} filter - Post type filter
     * @returns {Promise} jQuery AJAX promise
     */
    function setFilter(filter) {
        state.postTypeFilter = filter;
        state.currentPage = 1;
        clearPrefetchCache(); // Clear prefetch cache when filter changes
        return loadTableData(1);
    }

    /**
     * Updates parent post filter and reloads
     *
     * @since 1.2.0
     * @access public
     * @param {number} postId - Parent post ID (0 = all)
     * @returns {Promise} jQuery AJAX promise
     */
    function setParentPost(postId) {
        state.parentPost = parseInt(postId, 10) || 0;
        state.currentPage = 1;
        clearPrefetchCache();
        return loadTableData(1);
    }

    /**
     * Sets parent post in state without triggering a reload
     * Used for restoring session state before init
     *
     * @since 1.2.0
     * @access public
     * @param {number} postId - Parent post ID
     */
    function setParentPostSilent(postId) {
        state.parentPost = parseInt(postId, 10) || 0;
    }

    /**
     * Updates sort column and direction, then reloads
     *
     * @since 1.2.0
     * @access public
     * @param {string} orderby - Column name (e.g. 'seo_score', 'date')
     * @param {string} order - Direction ('asc' or 'desc')
     * @returns {Promise} jQuery AJAX promise
     */
    function setSort(orderby, order) {
        state.orderby = orderby;
        state.order = order;
        state.currentPage = 1;
        clearPrefetchCache();
        return loadTableData(1);
    }

    /**
     * Sets sort in state without triggering a reload
     * Used for restoring session state before init
     *
     * @since 1.2.0
     * @access public
     * @param {string} orderby
     * @param {string} order
     */
    function setSortSilent(orderby, order) {
        state.orderby = orderby;
        state.order = order;
    }

    /**
     * Gets current state
     *
     * @since 1.0.0
     * @access public
     * @returns {Object} Current state
     */
    function getState() {
        return { ...state };
    }

    return {
        init,
        reload,
        goToPage,
        search,
        setFilter,
        setParentPost,
        setParentPostSilent,
        setSort,
        setSortSilent,
        getState,
        showSkeletonLoader,
        hideSkeletonLoader,
        bindPaginationHandlers
    };

})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ajaxTableLoader;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.ajaxTableLoader = ajaxTableLoader;
}

export default ajaxTableLoader;
