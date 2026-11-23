/**
 * Page/Post Filter Module
 *
 * AJAX-powered searchable dropdown to filter the image table by a specific
 * page or post. Shows image counts next to each result.
 *
 * @module table/page-filter
 * @since 1.2.0
 */
import ajaxTableLoader from './ajax-table-loader.js';

const pageFilter = (function($) {
    'use strict';

    /* =========================================================================
       CONSTANTS
       ========================================================================= */

    const DEBOUNCE_MS = 300;
    const SESSION_KEY = 'infyp_page_filter';

    /* =========================================================================
       STATE
       ========================================================================= */

    let debounceTimer = null;
    let currentRequest = null;

    /* =========================================================================
       DOM REFERENCES
       ========================================================================= */

    let $wrapper, $display, $input, $dropdown, $hidden, $active, $clearBtn;

    /* =========================================================================
       PRIVATE METHODS
       ========================================================================= */

    /**
     * Search posts/pages via AJAX
     * @param {string} term - Search term
     */
    function searchPosts(term) {
        // Cancel any pending request
        if (currentRequest && currentRequest.readyState !== 4) {
            currentRequest.abort();
        }

        const nonce = window.infyp_vars?.nonce || '';
        const loaderState = ajaxTableLoader.getState();

        currentRequest = $.ajax({
            url: window.ajaxurl,
            type: 'POST',
            data: {
                action: 'infyp_search_posts_with_images',
                nonce: nonce,
                search: term,
                post_type_filter: loaderState.postTypeFilter || 'all'
            },
            dataType: 'json'
        })
        .done(function(response) {
            if (response.success && response.data && response.data.results) {
                renderDropdown(response.data.results);
            } else {
                renderDropdown([]);
            }
        })
        .fail(function(xhr, status) {
            if (status !== 'abort') {
                renderDropdown([]);
            }
        });
    }

    /**
     * Render dropdown results
     * @param {Array} results - Array of {id, title, type, image_count}
     */
    function renderDropdown(results) {
        if (!results.length) {
            $dropdown.html(
                '<div class="infyp-page-option-empty">' +
                    (window.infyp_vars?.i18n?.no_results || 'No pages or posts found') +
                '</div>'
            );
            $dropdown.show();
            return;
        }

        let html = '';
        results.forEach(function(item) {
            const imageLabel = item.image_count === 1 ? 'image' : 'images';
            html += '<div class="infyp-page-option" data-post-id="' + item.id + '">' +
                '<span class="infyp-page-option-title" title="' + escapeHtml(item.title) + '">' + escapeHtml(item.title) + '</span>' +
                '<span class="infyp-page-option-meta">' +
                    '<span class="infyp-page-type-badge">' + escapeHtml(item.type) + '</span>' +
                    '<span class="infyp-page-image-count">' + item.image_count + ' ' + imageLabel + '</span>' +
                '</span>' +
            '</div>';
        });

        $dropdown.html(html);
        $dropdown.show();
    }

    /**
     * Select a post from the dropdown
     * @param {number} postId - Post ID
     * @param {string} title - Post title
     */
    function selectPost(postId, title) {
        $hidden.val(postId);
        $dropdown.hide();
        $input.val('');

        // Show active token, hide input
        if ($active.length) {
            $active.find('.infyp-page-filter-active-title').text(title).attr('title', title);
            $active.show();
        } else {
            // Create active token
            const tokenHtml = '<div class="infyp-page-filter-active" id="infyp-page-filter-active">' +
                '<span class="dashicons dashicons-admin-page"></span>' +
                '<span class="infyp-page-filter-active-title" title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</span>' +
                '<button type="button" class="infyp-page-filter-clear-btn" id="infyp-page-filter-clear">' +
                    '<span class="dashicons dashicons-no-alt"></span>' +
                '</button>' +
            '</div>';
            $wrapper.prepend(tokenHtml);
            $active = $('#infyp-page-filter-active');
            $clearBtn = $('#infyp-page-filter-clear');
            $clearBtn.on('click', clearFilter);
        }
        $display.hide();

        // Store in session
        try {
            sessionStorage.setItem(SESSION_KEY, JSON.stringify({ id: postId, title: title }));
        } catch (e) { /* silent */ }

        // Trigger table reload
        ajaxTableLoader.setParentPost(postId);
    }

    /**
     * Clear the filter
     */
    function clearFilter() {
        $hidden.val(0);
        if ($active.length) {
            $active.hide();
        }
        $display.show();
        $input.val('');
        $dropdown.hide();

        // Clear session
        try {
            sessionStorage.removeItem(SESSION_KEY);
        } catch (e) { /* silent */ }

        // Trigger table reload
        ajaxTableLoader.setParentPost(0);
    }

    /**
     * Escape HTML entities
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /**
     * Restore filter state from session storage
     */
    function restoreFromSession() {
        try {
            const saved = sessionStorage.getItem(SESSION_KEY);
            if (!saved) return;

            // If the URL has no parent_post param, the user navigated here
            // without the filter — clear the stale session instead of restoring.
            const url = new URL(window.location.href);
            if (!url.searchParams.has('parent_post')) {
                sessionStorage.removeItem(SESSION_KEY);
                return;
            }

            const data = JSON.parse(saved);
            if (data && data.id && data.id > 0) {
                // Only restore if not already set from URL/PHP
                if (parseInt($hidden.val(), 10) === 0) {
                    $hidden.val(data.id);

                    // Create active token
                    const tokenHtml = '<div class="infyp-page-filter-active" id="infyp-page-filter-active">' +
                        '<span class="dashicons dashicons-admin-page"></span>' +
                        '<span class="infyp-page-filter-active-title" title="' + escapeHtml(data.title) + '">' + escapeHtml(data.title) + '</span>' +
                        '<button type="button" class="infyp-page-filter-clear-btn" id="infyp-page-filter-clear">' +
                            '<span class="dashicons dashicons-no-alt"></span>' +
                        '</button>' +
                    '</div>';
                    $wrapper.prepend(tokenHtml);
                    $active = $('#infyp-page-filter-active');
                    $clearBtn = $('#infyp-page-filter-clear');
                    $clearBtn.on('click', clearFilter);
                    $display.hide();

                    // Set in ajaxTableLoader state (without triggering reload — init will handle it)
                    if (typeof ajaxTableLoader.setParentPostSilent === 'function') {
                        ajaxTableLoader.setParentPostSilent(data.id);
                    }
                }
            }
        } catch (e) { /* silent */ }
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Initialize the page filter module
     */
    function init() {
        $wrapper = $('#infyp-page-filter-wrapper');
        if (!$wrapper.length) return;

        $display = $('#infyp-page-filter-display');
        $input = $('#infyp-page-filter-input');
        $dropdown = $('#infyp-page-filter-dropdown');
        $hidden = $('#infyp-parent-post-value');
        $active = $('#infyp-page-filter-active');
        $clearBtn = $('#infyp-page-filter-clear');

        // Restore from session if no URL state
        restoreFromSession();

        // Clear page filter when post type filter changes (silent — no reload, post type filter handles it)
        $(document).on('infyp:postTypeFilterChanged', function() {
            if (parseInt($hidden.val(), 10) > 0) {
                clearFilterSilent();
            }
        });

        // Input handler (debounced)
        $input.on('input', function() {
            const term = $(this).val().trim();
            clearTimeout(debounceTimer);

            if (term.length === 0) {
                // Show recent posts with images
                debounceTimer = setTimeout(function() {
                    searchPosts('');
                }, 150);
                return;
            }

            debounceTimer = setTimeout(function() {
                searchPosts(term);
            }, DEBOUNCE_MS);
        });

        // Focus: show dropdown with recent results
        $input.on('focus', function() {
            if ($dropdown.children().length === 0) {
                searchPosts('');
            } else {
                $dropdown.show();
            }
        });

        // Select item from dropdown
        $dropdown.on('click', '.infyp-page-option', function() {
            const postId = $(this).data('post-id');
            const title = $(this).find('.infyp-page-option-title').text();
            selectPost(postId, title);
        });

        // Clear button
        if ($clearBtn.length) {
            $clearBtn.on('click', clearFilter);
        }

        // Close dropdown on click outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#infyp-page-filter-wrapper').length) {
                $dropdown.hide();
            }
        });

        // Keyboard navigation
        $input.on('keydown', function(e) {
            const $options = $dropdown.find('.infyp-page-option');
            const $focused = $dropdown.find('.infyp-page-option.is-focused');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if ($focused.length) {
                    const $next = $focused.removeClass('is-focused').next('.infyp-page-option');
                    if ($next.length) {
                        $next.addClass('is-focused');
                        scrollIntoViewIfNeeded($next[0], $dropdown[0]);
                    } else {
                        $options.first().addClass('is-focused');
                    }
                } else {
                    $options.first().addClass('is-focused');
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if ($focused.length) {
                    const $prev = $focused.removeClass('is-focused').prev('.infyp-page-option');
                    if ($prev.length) {
                        $prev.addClass('is-focused');
                        scrollIntoViewIfNeeded($prev[0], $dropdown[0]);
                    } else {
                        $options.last().addClass('is-focused');
                    }
                } else {
                    $options.last().addClass('is-focused');
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if ($focused.length) {
                    $focused.trigger('click');
                }
            } else if (e.key === 'Escape') {
                $dropdown.hide();
                $input.blur();
            }
        });
    }

    /**
     * Scroll element into view within a scrollable container
     */
    function scrollIntoViewIfNeeded(el, container) {
        const rect = el.getBoundingClientRect();
        const containerRect = container.getBoundingClientRect();
        if (rect.bottom > containerRect.bottom) {
            el.scrollIntoView({ block: 'end', behavior: 'smooth' });
        } else if (rect.top < containerRect.top) {
            el.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
    }

    /**
     * Clear the filter UI and state without triggering a table reload.
     * Used when another filter (e.g. post type) already triggers its own reload.
     */
    function clearFilterSilent() {
        $hidden.val(0);
        if ($active.length) {
            $active.hide();
        }
        $display.show();
        $input.val('');
        $dropdown.hide();

        // Clear session
        try {
            sessionStorage.removeItem(SESSION_KEY);
        } catch (e) { /* silent */ }

        // Update state without reload
        ajaxTableLoader.setParentPostSilent(0);
    }

    /**
     * Clear the filter externally (e.g., when post type filter changes)
     */
    function clear() {
        if (parseInt($hidden?.val() || '0', 10) > 0) {
            clearFilter();
        }
    }

    return {
        init,
        clear
    };

})(jQuery);

export default pageFilter;
