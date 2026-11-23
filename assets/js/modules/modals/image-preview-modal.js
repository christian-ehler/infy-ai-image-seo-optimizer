/**
 * Image Preview Modal Module - Displays full-size image preview
 *
 * This module provides a lightbox modal for previewing images from the
 * dashboard table at full size, with a link to the WordPress Media Library
 * edit page and an Optimize with AI button.
 *
 * @module modals/image-preview-modal
 * @requires jQuery
 * @requires api
 * @requires base-modal
 */
import api from '../api.js';
import baseModal from './base-modal.js';

const imagePreviewModal = (function($) {
    'use strict';

    /* =========================================================================
       MODAL INITIALIZATION
       ========================================================================= */

    /**
     * Show image preview modal for an image
     *
     * Fetches fresh image URL for high-quality preview, then displays
     * the image preview modal.
     *
     * @param {number} imageId - The WordPress attachment ID
     * @returns {void}
     */
    function show(imageId) {
        // Remove any existing modals
        $('#infyp-lightbox-overlay').remove();
        $('#infyp-image-preview-lightbox').remove();

        // Fetch fresh image data for better quality
        api.post('infyp_get_fresh_image_url', {
            image_id: imageId
        }).done(function(response) {
            let imageUrl = '';
            if (response.success && response.data) {
                imageUrl = response.data.urls?.display || response.data.urls?.large || response.data.urls?.full || '';
            }

            if (!imageUrl) {
                imageUrl = getFallbackUrl(imageId);
            }

            loadFromPHP(imageId, imageUrl);
        }).fail(function() {
            const imageUrl = getFallbackUrl(imageId);
            loadFromPHP(imageId, imageUrl);
        });
    }

    /**
     * Get fallback image URL from the table thumbnail
     *
     * @param {number} imageId - The WordPress attachment ID
     * @returns {string} The image URL
     * @private
     */
    function getFallbackUrl(imageId) {
        const $row = $(`tr[data-id="${imageId}"]`);
        const $imageThumb = $row.find('.column-thumbnail img');
        let imageUrl = $imageThumb.attr('src') || '';

        // Try to get larger version by removing size suffix
        if (imageUrl) {
            imageUrl = imageUrl.replace(/-\d+x\d+(\.[^.]+)$/, '$1');
        }

        return imageUrl;
    }

    /* =========================================================================
       PHP TEMPLATE LOADING
       ========================================================================= */

    /**
     * Load image preview modal from PHP template via AJAX
     *
     * @param {number} imageId - The WordPress attachment ID
     * @param {string} imageUrl - URL for image preview
     * @returns {void}
     * @private
     */
    function loadFromPHP(imageId, imageUrl) {
        $.post(window.infyp_vars.ajax_url, {
            action: 'infyp_load_image_preview_modal',
            nonce: window.infyp_vars.nonce,
            image_id: imageId,
            image_url: imageUrl
        }).done(function(response) {
            if (response.success && response.data) {
                // Append modal HTML to body
                $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                const $overlay = $('#infyp-lightbox-overlay');
                const $modal = $('#infyp-image-preview-lightbox');

                // Set image data
                $modal.attr('data-image-id', imageId);
                $modal.find('.infyp-image-id').text('#' + imageId);
                $modal.find('.infyp-modal-image-preview img').attr('src', imageUrl);

                // Set edit link
                if (response.data.edit_link) {
                    $modal.find('.infyp-edit-media-link').attr('href', response.data.edit_link);
                }

                // Set optimize button image ID
                $modal.find('.infyp-optimize-from-modal').attr('data-id', imageId);

                // Show modal using base modal utilities
                baseModal.showModal($overlay, $modal);

                // Bind events
                bindEvents($overlay, $modal, imageId);
            } else {
                displayFallback(imageId, imageUrl);
            }
        }).fail(function() {
            displayFallback(imageId, imageUrl);
        });
    }

    /* =========================================================================
       FALLBACK RENDERING
       ========================================================================= */

    /**
     * Fallback function to display modal with inline JS-generated HTML
     *
     * @param {number} imageId - The WordPress attachment ID
     * @param {string} imageUrl - URL for image preview
     * @returns {void}
     * @private
     */
    function displayFallback(imageId, imageUrl) {
        const i18n = window.infyp_vars.i18n;

        // Build edit URL from WordPress admin URL
        const editUrl = window.infyp_vars.admin_url + 'post.php?post=' + imageId + '&action=edit';

        const modalHtml = `
            <div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
            <div class="infyp-image-preview-lightbox" id="infyp-image-preview-lightbox" data-image-id="${imageId}">
                <div class="infyp-image-preview-lightbox-header">
                    <h3>${i18n.image_preview || 'Image Preview'} <span class="infyp-image-id">#${imageId}</span></h3>
                    <button class="infyp-image-preview-lightbox-close">&times;</button>
                </div>
                <div class="infyp-image-preview-lightbox-content">
                    <div class="infyp-modal-image-preview">
                        <img src="${baseModal.escapeHtml(imageUrl)}" alt="${i18n.image_preview || 'Image preview'}" />
                    </div>
                </div>
                <div class="infyp-image-preview-lightbox-footer">
                    <a href="${baseModal.escapeHtml(editUrl)}" class="button infyp-edit-media-link" target="_blank">
                        <span class="dashicons dashicons-edit"></span>
                        ${i18n.edit_in_media_library || 'Edit in Media Library'}
                    </a>
                    <button class="button button-primary infyp-optimize-from-modal" data-id="${imageId}">
                        <span class="infyp-optimize-icon"></span>
                        ${i18n.optimize_now || 'Optimize with AI'}
                    </button>
                </div>
            </div>
        `;

        $('body').append(modalHtml);

        const $overlay = $('#infyp-lightbox-overlay');
        const $modal = $('#infyp-image-preview-lightbox');

        baseModal.showModal($overlay, $modal);
        bindEvents($overlay, $modal, imageId);
    }

    /* =========================================================================
       EVENT HANDLERS
       ========================================================================= */

    /**
     * Bind event handlers for image preview modal
     *
     * @param {jQuery} $overlay - The overlay element
     * @param {jQuery} $modal - The modal element
     * @param {number} imageId - The WordPress attachment ID
     * @returns {void}
     * @private
     */
    function bindEvents($overlay, $modal, imageId) {
        // Close events
        $overlay.on('click', function(e) {
            e.preventDefault();
            baseModal.closeModal($overlay, $modal);
        });

        $modal.find('.infyp-image-preview-lightbox-close').on('click', function(e) {
            e.preventDefault();
            baseModal.closeModal($overlay, $modal);
        });

        // Optimize with AI button - trigger the table row's optimize button
        $modal.find('.infyp-optimize-from-modal').on('click', function(e) {
            e.preventDefault();

            // Close the modal first
            baseModal.closeModal($overlay, $modal);

            // Find the row's optimize button and trigger click
            const $row = $(`tr[data-id="${imageId}"]`);
            if ($row.length) {
                const $optimizeBtn = $row.find('.infyp-optimize-ai');
                if ($optimizeBtn.length) {
                    $optimizeBtn.trigger('click');
                }
            }
        });

        // Escape key
        $(document).on('keydown.imagePreviewModal', function(e) {
            if (e.key === 'Escape' && $modal.hasClass('is-open')) {
                baseModal.closeModal($overlay, $modal);
                $(document).off('keydown.imagePreviewModal');
            }
        });

        // Prevent modal content clicks from closing
        $modal.on('click', function(e) {
            e.stopPropagation();
        });
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    return {
        show,
        loadFromPHP,
        displayFallback
    };
})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = imagePreviewModal;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.imagePreviewModal = imagePreviewModal;
}

export default imagePreviewModal;
