/**
 * Usage Modal Module - Displays image usage information
 *
 * This module provides a modal dialog showing where an image is used throughout
 * the WordPress site. It displays posts, pages, and other content types where
 * the image appears as featured image, in content, or via page builders.
 *
 * ## Features
 *
 * - Shows image preview with high-quality URL
 * - Lists all posts/pages using the image
 * - Displays usage type badges (Featured, Content, Page Builder)
 * - Refresh button to update usage data
 * - PHP template loading with JS fallback
 *
 * ## Usage Types Displayed
 *
 * | Type     | Description                              |
 * |----------|------------------------------------------|
 * | featured | Image is set as featured/thumbnail       |
 * | content  | Image is embedded in post content        |
 * | builder  | Image is used in page builder elements   |
 *
 * ## Dependencies
 *
 * - jQuery
 * - api module (for AJAX requests)
 * - baseModal module (for common modal utilities)
 * - infyp_vars global (for AJAX URL, nonce, i18n)
 *
 * ## Usage
 *
 * ```javascript
 * import usageModal from './modals/usage-modal.js';
 *
 * // Show modal with usage data
 * usageModal.show(123, '<div class="usage-item">...</div>');
 * ```
 *
 * @module modals/usage-modal
 * @requires jQuery
 * @requires api
 * @requires base-modal
 */
import api from '../api.js';
import baseModal from './base-modal.js';

const usageModal = (function($) {
    'use strict';

    /* =========================================================================
       MODAL INITIALIZATION
       ========================================================================= */

    /**
     * Show usage modal for an image
     *
     * Fetches fresh image URL for high-quality preview, then displays
     * the usage modal with the provided usage HTML content.
     *
     * @param {number} imageId - The WordPress attachment ID
     * @param {string} usageHtml - Pre-rendered HTML content showing usage locations
     * @returns {void}
     */
    function showUsageModal(imageId, usageHtml) {
        // Remove any existing modals
        $('#infyp-lightbox-overlay').remove();
        $('#infyp-usage-lightbox').remove();

        // Fetch fresh image data first for better quality image
        api.post('infyp_get_fresh_image_url', {
            image_id: imageId
        }).done(function(response) {
            let imageUrl = '';
            if (response.success && response.data) {
                // Use fresh URL from server - prefer display or large size
                imageUrl = response.data.urls?.display || response.data.urls?.large || response.data.urls?.full || '';
            } else {
                // Fallback to thumbnail if fresh fetch fails
                const $row = $(`tr[data-id="${imageId}"]`);
                const $imageThumb = $row.find('.column-thumbnail img');
                imageUrl = $imageThumb.attr('src') || '';

                // Try to get larger version by replacing -150x150 pattern
                if (imageUrl) {
                    imageUrl = imageUrl.replace(/-\d+x\d+(\.[^.]+)$/, '$1');
                }
            }

            displayUsageModal(imageId, usageHtml, imageUrl);
        }).fail(function() {
            // Fallback if API call fails
            const $row = $(`tr[data-id="${imageId}"]`);
            const $imageThumb = $row.find('.column-thumbnail img');
            let imageUrl = $imageThumb.attr('src') || '';

            // Try to get larger version
            if (imageUrl) {
                imageUrl = imageUrl.replace(/-\d+x\d+(\.[^.]+)$/, '$1');
            }

            displayUsageModal(imageId, usageHtml, imageUrl);
        });
    }

    /**
     * Display usage modal with image URL
     *
     * Wrapper function that delegates to PHP template loading.
     *
     * @param {number} imageId - The WordPress attachment ID
     * @param {string} usageHtml - Pre-rendered HTML content showing usage
     * @param {string} imageUrl - URL for image preview
     * @returns {void}
     * @private
     */
    function displayUsageModal(imageId, usageHtml, imageUrl) {
        // Use PHP/AJAX approach to load modal
        loadUsageModalFromPHP(imageId, usageHtml, imageUrl);
    }

    /* =========================================================================
       PHP TEMPLATE LOADING
       ========================================================================= */

    /**
     * Load usage modal from PHP template via AJAX
     *
     * Requests the modal HTML from the server, which provides consistent
     * styling and i18n support. Falls back to JS-generated HTML on failure.
     *
     * @param {number} imageId - The WordPress attachment ID
     * @param {string} usageHtml - Pre-rendered HTML content showing usage
     * @param {string} imageUrl - URL for image preview
     * @returns {void}
     * @private
     */
    function loadUsageModalFromPHP(imageId, usageHtml, imageUrl) {
        // Load modal HTML from PHP template
        $.post(window.infyp_vars.ajax_url, {
            action: 'infyp_load_usage_modal',
            nonce: window.infyp_vars.nonce,
            image_id: imageId,
            usage_html: usageHtml,
            image_url: imageUrl
        }).done(function(response) {
            if (response.success && response.data) {
                // Append modal HTML to body
                $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                // Set image data
                $('#infyp-usage-lightbox').attr('data-image-id', imageId);
                $('#infyp-usage-lightbox .infyp-image-id').text('#' + imageId);
                $('#infyp-usage-lightbox .infyp-modal-image-preview img').attr('src', imageUrl);
                $('.infyp-usage-list').html(usageHtml);
                $('.infyp-refresh-usage').attr('data-image-id', imageId);

                // Lock body scroll and store scroll position for restoration
                const scrollY = window.scrollY;
                $('body')
                    .addClass('infyp-lightbox-open')
                    .css('top', `-${scrollY}px`)
                    .data('scroll-position', scrollY);

                // Show overlay with CSS transition
                const $overlay = $('#infyp-lightbox-overlay');
                $overlay.css('display', 'block');
                // Trigger reflow for CSS transition
                $overlay[0].offsetHeight;
                $overlay.addClass('is-visible');

                // Add is-open class to modal (triggers CSS Grid display)
                $('#infyp-usage-lightbox').addClass('is-open');

                // Bind usage modal events
                bindUsageModalEvents(imageId);
            } else {
                // Fallback to inline HTML if AJAX fails
                displayUsageModalFallback(imageId, usageHtml, imageUrl);
            }
        }).fail(function() {
            // Fallback on AJAX error
            displayUsageModalFallback(imageId, usageHtml, imageUrl);
        });
    }

    /* =========================================================================
       FALLBACK RENDERING
       ========================================================================= */

    /**
     * Fallback function to display usage modal with inline JS-generated HTML
     *
     * Used when PHP template loading fails. Generates complete modal HTML
     * including header, image preview, usage list, legend, and footer.
     *
     * @param {number} imageId - The WordPress attachment ID
     * @param {string} usageHtml - Pre-rendered HTML content showing usage
     * @param {string} imageUrl - URL for image preview
     * @returns {void}
     * @private
     */
    function displayUsageModalFallback(imageId, usageHtml, imageUrl) {
        const i18n = window.infyp_vars.i18n;
        const modalHtml = `
            <div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
            <div class="infyp-usage-lightbox" id="infyp-usage-lightbox" data-image-id="${imageId}">
                <div class="infyp-usage-lightbox-header">
                    <h3>${i18n.image_usage || 'Image Usage'} <span class="infyp-image-id">#${imageId}</span></h3>
                    <button class="infyp-usage-lightbox-close">&times;</button>
                </div>
                <div class="infyp-usage-lightbox-content">
                    <div class="infyp-modal-image-preview">
                        <img src="${imageUrl}" alt="${i18n.image_preview || 'Image preview'}" />
                    </div>
                    <div class="infyp-usage-details-section">
                        <h4>${i18n.where_used || 'Where This Image Is Used'}</h4>
                        <div class="infyp-usage-list">${usageHtml}</div>
                    </div>
                    <div class="infyp-usage-info">
                        <h4>${i18n.usage_types || 'Usage Types'}</h4>
                        <ul class="infyp-usage-legend">
                            <li><span class="infyp-usage-badge featured">${i18n.featured_image || 'Featured Image'}</span> - ${i18n.featured_image_desc || 'Used as the main image for a post or page'}</li>
                            <li><span class="infyp-usage-badge content">${i18n.in_content || 'In Content'}</span> - ${i18n.in_content_desc || 'Embedded within the post or page content'}</li>
                            <li><span class="infyp-usage-badge builder">${i18n.page_builder || 'Page Builder'}</span> - ${i18n.page_builder_desc || 'Used in page builder elements (Elementor, etc.)'}</li>
                        </ul>
                    </div>
                </div>
                <div class="infyp-usage-lightbox-footer">
                    <button class="button infyp-refresh-usage" data-image-id="${imageId}">
                        <span class="dashicons dashicons-update"></span> ${i18n.refresh || 'Refresh'}
                    </button>
                    <button class="button button-primary infyp-close-usage">${i18n.close || 'Close'}</button>
                </div>
            </div>
        `;

        $('body').append(modalHtml);

        // Lock body scroll and store scroll position for restoration
        const scrollY = window.scrollY;
        $('body')
            .addClass('infyp-lightbox-open')
            .css('top', `-${scrollY}px`)
            .data('scroll-position', scrollY);

        // Show overlay with CSS transition
        const $overlayEl = $('#infyp-lightbox-overlay');
        $overlayEl.css('display', 'block');
        // Trigger reflow for CSS transition
        $overlayEl[0].offsetHeight;
        $overlayEl.addClass('is-visible');

        // Add is-open class to modal (triggers CSS Grid display)
        $('#infyp-usage-lightbox').addClass('is-open');

        bindUsageModalEvents(imageId);
    }

    /* =========================================================================
       EVENT HANDLERS
       ========================================================================= */

    /**
     * Bind event handlers for usage modal interactions
     *
     * Sets up handlers for:
     * - Close button and overlay clicks
     * - Refresh button to reload usage data
     * - Click propagation prevention on modal content
     *
     * @param {number} imageId - The WordPress attachment ID for refresh requests
     * @returns {void}
     * @private
     */
    function bindUsageModalEvents(imageId) {
        const i18n = window.infyp_vars.i18n;

        // Close events using .is-open class system
        $('#infyp-lightbox-overlay, .infyp-usage-lightbox-close, .infyp-close-usage').off('click').on('click', function(e) {
            e.preventDefault();

            const $overlay = $('#infyp-lightbox-overlay');
            const $modal = $('#infyp-usage-lightbox');

            // Restore scroll position and unlock body scroll
            const scrollY = $('body').data('scroll-position') || 0;
            $('body')
                .removeClass('infyp-lightbox-open')
                .css('top', '');
            window.scrollTo(0, scrollY);

            // Remove visibility classes for CSS transition
            $overlay.removeClass('is-visible');
            $modal.removeClass('is-open');

            // After animation, remove from DOM
            setTimeout(function() {
                $overlay.remove();
                $modal.remove();
            }, 200);
        });

        // Refresh button
        $('.infyp-refresh-usage').off('click').on('click', function(e) {
            e.preventDefault();
            const $btn = $(this);
            const imgId = imageId || $btn.data('image-id');

            $btn.prop('disabled', true);
            $btn.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> ' + (i18n.loading || 'Loading...'));

            // Clear cache and refetch
            api.post('infyp_get_image_usage', {
                image_id: imgId,
                refresh: true
            }).done(response => {
                if (response.success && response.data.html) {
                    $('.infyp-usage-list').html(baseModal.sanitizeHtml(response.data.html));
                }
            }).always(() => {
                $btn.prop('disabled', false);
                $btn.html('<span class="dashicons dashicons-update"></span> ' + (i18n.refresh || 'Refresh'));
            });
        });

        // Prevent modal content from closing when clicked
        $('#infyp-usage-lightbox').off('click').on('click', function(e) {
            e.stopPropagation();
        });
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the usage modal module
     *
     * @returns {Object} Public methods
     * @property {Function} show - Show modal with image ID and usage HTML
     * @property {Function} display - Display modal with all parameters
     * @property {Function} loadFromPHP - Load modal from PHP template
     * @property {Function} displayFallback - Display modal with JS fallback
     * @property {Function} bindEvents - Bind modal event handlers
     */
    return {
        show: showUsageModal,
        display: displayUsageModal,
        loadFromPHP: loadUsageModalFromPHP,
        displayFallback: displayUsageModalFallback,
        bindEvents: bindUsageModalEvents
    };
})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = usageModal;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.usageModal = usageModal;
}

export default usageModal;
