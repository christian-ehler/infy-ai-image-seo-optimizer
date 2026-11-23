/**
 * Attachment Page Handler - Manages AI optimization on WordPress attachment edit pages.
 *
 * This script provides AI-powered image metadata optimization directly on the
 * WordPress attachment edit page. It includes real-time field updates, manual
 * edit tracking, and AJAX-based optimization with live feedback.
 *
 * ## Features
 *
 * - AI optimization button in attachment metabox
 * - Manual field update tracking and auto-save
 * - Real-time status feedback with WordPress dashicons
 * - Filename change notifications
 * - Original value preservation for change detection
 * - AJAX error handling and recovery
 *
 * ## Usage
 *
 * This script is automatically loaded on WordPress attachment edit pages
 * where the AI Image SEO metabox is present. No manual initialization required.
 *
 * @file Manages AI optimization on WordPress attachment edit pages.
 * @author AI Image SEO
 * @since 1.0.0
 * @requires jQuery
 */
(function($) {
    'use strict';

    /** Escape HTML entities to prevent XSS when inserting into .html(). */
    function escapeHtml(text) {
        if (typeof text !== 'string') return '';
        var map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return text.replace(/[&<>"']/g, function(c) { return map[c]; });
    }

    /* =========================================================================
       MANUAL UPDATE HANDLER
       ========================================================================= */

    /**
     * Handles manual field updates for alt text and title.
     *
     * Detects changes to alt text or title fields and automatically saves
     * them via AJAX. Compares current value with original to prevent
     * unnecessary saves. Shows loading and success/error status feedback.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {Event} e - Change event from input field.
     *
     * @return {void}
     */
    function handleManualUpdate(e) {
        const $input = $(e.target);
        const originalValue = $input.data('original-value');
        const value = $input.val();
        if (value === originalValue) {
            return;
        }
        const $metabox = $('#infyp_attachment_seo_optimizer');
        const $button = $metabox.find('#infyp-optimize-in-metabox');
        const $status = $metabox.find('#infyp-metabox-status');
        const imageId = $button.data('image-id');
        let fieldName = '';
        if ($input.attr('id') === 'attachment_alt') {
            fieldName = 'alt_text';
        } else if ($input.attr('name') === 'post_title') {
            fieldName = 'title';
        }
        if (!imageId || !fieldName) {
            return;
        }
        $status.html('<span class="spinner is-active" style="float:none;vertical-align:middle;"></span> ' + infyp_vars.i18n.saving);
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'infyp_update_image_data',
                nonce: infyp_vars.nonce,
                image_id: imageId,
                field: fieldName,
                value: value,
            },
            success: function(response) {
                if (response.success) {
                    $status.html('<span class="dashicons dashicons-yes-alt" style="color:green;"></span> ' + infyp_vars.i18n.saved);
                    $input.data('original-value', value);
                } else {
                    $status.html('<span class="dashicons dashicons-warning" style="color:red;"></span> ' + escapeHtml(response.data.message));
                    $input.val(originalValue);
                }
            },
            error: function() {
                $status.html('<span class="dashicons dashicons-warning" style="color:red;"></span> ' + infyp_vars.i18n.unexpectedError);
                $input.val(originalValue);
            },
            complete: function() {
                setTimeout(() => $status.empty(), 3000);
            }
        });
    }

    /* =========================================================================
       INITIALIZATION
       ========================================================================= */

    /**
     * Initializes attachment page functionality on document ready.
     *
     * Sets up AI optimization button handler and manual field update tracking.
     * Preserves original field values for change detection.
     *
     * @since 1.0.0
     * @access private
     *
     * @return {void}
     */
    $(document).ready(function() {
        const $metabox = $('#infyp_attachment_seo_optimizer');
        if (!$metabox.length) {
            return;
        }
        const $button = $metabox.find('#infyp-optimize-in-metabox');
        const $status = $metabox.find('#infyp-metabox-status');
        const imageId = $button.data('image-id');

        /* =====================================================================
           AI OPTIMIZATION BUTTON HANDLER
           ===================================================================== */

        /**
         * Handles AI optimization button click in metabox.
         *
         * Sends AJAX request to optimize image metadata using AI. Updates
         * title and alt text fields with AI-generated values and shows
         * filename change notification if applicable.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        $button.on('click', function(e) {
            e.preventDefault();
            $button.prop('disabled', true);
            $status.html('<span class="spinner is-active" style="float:none;vertical-align:middle;"></span> ' + infyp_vars.i18n.optimizing);
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'infyp_optimize_with_ai',
                    nonce: infyp_vars.nonce,
                    image_id: imageId,
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<span class="dashicons dashicons-yes-alt" style="color:green;"></span> ' + escapeHtml(response.data.message));
                        if (response.data.newData) {
                            const newData = response.data.newData;
                            const $titleInput = $('input[name="post_title"]');
                            const $altInput = $('#attachment_alt');
                            $titleInput.val(newData.title).data('original-value', newData.title);
                            $altInput.val(newData.alt_text).data('original-value', newData.alt_text);
                            if (newData.full_filename) {
                                $status.append('<br/>' + infyp_vars.i18n.filenameChangedReload);
                            }
                        }
                    } else {
                        $status.html('<span class="dashicons dashicons-warning" style="color:red;"></span> ' + escapeHtml(response.data.message));
                    }
                },
                error: function() {
                    $status.html('<span class="dashicons dashicons-warning" style="color:red;"></span> ' + infyp_vars.i18n.unexpectedError);
                },
                complete: function() {
                    setTimeout(function() {
                        $button.prop('disabled', false);
                        $status.empty();
                    }, 5000);
                }
            });
        });

        /* =====================================================================
           MANUAL FIELD UPDATE TRACKING
           ===================================================================== */

        const $titleInput = $('input[name="post_title"]');
        const $altInput = $('#attachment_alt');

        // Store original values for change detection
        $titleInput.data('original-value', $titleInput.val());
        $altInput.data('original-value', $altInput.val());

        // Bind change handlers for auto-save
        $titleInput.on('change', handleManualUpdate);
        $altInput.on('change', handleManualUpdate);
    });
})(jQuery);