/**
 * SEO Score Page - SEO weight configuration interface.
 *
 * This script handles the SEO Score Weights admin page functionality,
 * allowing users to customize how different factors contribute to
 * the overall SEO score for images.
 *
 * ## Features
 *
 * - Synchronized slider and input controls
 * - Real-time weight total calculation
 * - Validation (weights must equal 100%)
 * - Save weights via AJAX
 * - Reset to default weights
 * - WordPress admin notices integration
 *
 * ## Weight Categories
 *
 * | Weight          | Default | Description                    |
 * |-----------------|---------|--------------------------------|
 * | alt_text        | 35%     | Alt text presence and quality  |
 * | filename        | 25%     | SEO-friendly filename          |
 * | title_text      | 20%     | Image title optimization       |
 * | keyword_density | 15%     | Keyword usage in fields        |
 * | uniqueness      | 5%      | Content uniqueness             |
 *
 * ## AJAX Actions
 *
 * | Action                    | Purpose                |
 * |---------------------------|------------------------|
 * | infyp_save_seo_weights  | Save custom weights    |
 * | infyp_reset_seo_weights | Reset to defaults      |
 *
 * ## Localized Data
 *
 * Uses `infyp_seo_score` global object with:
 * - `ajax_url` - WordPress AJAX endpoint
 * - `nonce` - Security nonce
 * - `strings` - Translated UI strings
 *
 * @file SEO Score Weights configuration page handler.
 * @author AI Image SEO
 * @since 1.0.0
 * @requires jQuery
 */

jQuery(document).ready(function($) {
    'use strict';

    /** Escape HTML entities to prevent XSS when inserting into .html(). */
    function escapeHtml(text) {
        if (typeof text !== 'string') return '';
        var map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return text.replace(/[&<>"']/g, function(c) { return map[c]; });
    }

    /* =========================================================================
       DOM REFERENCES
       ========================================================================= */

    /**
     * Weight input fields (number inputs).
     * @type {jQuery}
     */
    const $weightInputs = $('.infyp-weight-input');

    /**
     * Total weight display element.
     * @type {jQuery}
     */
    const $weightTotal = $('#infyp-weight-total');

    /**
     * Warning message shown when total !== 100%.
     * @type {jQuery}
     */
    const $weightWarning = $('#infyp-weight-warning');

    /**
     * Save status indicator element.
     * @type {jQuery}
     */
    const $saveStatus = $('#infyp-weight-save-status');

    /**
     * Weight slider controls (range inputs).
     * @type {jQuery}
     */
    const $weightSliders = $('.infyp-weight-slider');

    /* =========================================================================
       SLIDER/INPUT SYNCHRONIZATION
       ========================================================================= */

    /**
     * Sync slider value to corresponding input field.
     *
     * When a slider is moved, updates the linked input field
     * and triggers the input event for total recalculation.
     *
     * @since 1.0.0
     * @listens jQuery#input
     */
    $weightSliders.on('input', function() {
        const targetId = $(this).data('target');
        const value = $(this).val();
        $('#' + targetId).val(value).trigger('input');
    });

    /**
     * Handle weight input changes.
     *
     * Syncs the value to the corresponding slider, recalculates
     * the total weight, and updates the validation status.
     *
     * @since 1.0.0
     * @listens jQuery#input
     */
    $weightInputs.on('input', function() {
        const inputId = $(this).attr('id');
        const value = parseInt($(this).val()) || 0;

        // Sync to corresponding slider
        $weightSliders.each(function() {
            if ($(this).data('target') === inputId) {
                $(this).val(value);
            }
        });

        // Calculate total weight
        let total = 0;
        $weightInputs.each(function() {
            const val = parseInt($(this).val()) || 0;
            total += val;
        });

        // Update total display and validation
        $weightTotal.text(total + '%');
        if (total !== 100) {
            $weightTotal.css('color', '#d63638');
            $weightWarning.show();
        } else {
            $weightTotal.css('color', '#2271b1');
            $weightWarning.hide();
        }
    });

    /* =========================================================================
       FORM SUBMISSION
       ========================================================================= */

    /**
     * Handle weight form submission.
     *
     * Validates that weights total 100%, then saves via AJAX.
     * Updates UI with loading state and success/error feedback.
     *
     * @since 1.0.0
     * @listens jQuery#submit
     */
    $('#infyp-seo-weights-form').on('submit', function(e) {
        e.preventDefault();

        // Validate total equals 100%
        const total = parseInt($weightTotal.text());
        if (total !== 100) {
            showNotice('error', infyp_seo_score.strings.weights_must_equal_100 || 'Weights must add up to 100%');
            return;
        }

        const formData = $(this).serialize();

        // Show loading state
        $saveStatus
            .html('<span class="dashicons dashicons-update"></span><span>' + infyp_seo_score.strings.saving + '</span>')
            .removeClass('success error warning')
            .addClass('loading show');

        // Save via AJAX
        $.ajax({
            url: infyp_seo_score.ajax_url,
            type: 'POST',
            data: {
                action: 'infyp_save_seo_weights',
                nonce: infyp_seo_score.nonce,
                data: formData
            },
            success: function(response) {
                if (response.success) {
                    $saveStatus.text(infyp_seo_score.strings.saved).addClass('success');
                    showNotice('success', response.data.message);

                    // Update weight badges in sidebar
                    if (response.data.weights) {
                        $('.infyp-weight-badge').eq(0).text(response.data.weights.alt_text + '%');
                        $('.infyp-weight-badge').eq(1).text(response.data.weights.filename + '%');
                        $('.infyp-weight-badge').eq(2).text(response.data.weights.title_text + '%');
                    }

                    // Clear status after delay
                    setTimeout(function() {
                        $saveStatus.text('');
                    }, 3000);
                } else {
                    handleSaveError(response.data?.message || infyp_seo_score.strings.error);
                }
            },
            error: function() {
                $saveStatus.text(infyp_seo_score.strings.error).addClass('error');
                showNotice('error', infyp_seo_score.strings.error);
            }
        });
    });

    /* =========================================================================
       RESET WEIGHTS
       ========================================================================= */

    /**
     * Handle reset weights button click.
     *
     * Confirms with user, then resets all weights to defaults via AJAX.
     * Updates all input fields, sliders, and badges with new values.
     *
     * @since 1.0.0
     * @listens jQuery#click
     */
    $('#infyp-reset-weights').on('click', function() {
        // Confirm reset action
        if (!confirm(infyp_seo_score.strings.reset_confirm)) {
            return;
        }

        $saveStatus.text(infyp_seo_score.strings.saving).removeClass('success error');

        // Reset via AJAX
        $.ajax({
            url: infyp_seo_score.ajax_url,
            type: 'POST',
            data: {
                action: 'infyp_reset_seo_weights',
                nonce: infyp_seo_score.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update all input fields
                    $('#weight_alt_text').val(response.data.weights.alt_text);
                    $('#weight_filename').val(response.data.weights.filename);
                    $('#weight_title_text').val(response.data.weights.title_text);
                    $('#weight_keyword_density').val(response.data.weights.keyword_density);
                    $('#weight_uniqueness').val(response.data.weights.uniqueness);

                    // Update all sliders
                    $('#weight_alt_text_slider').val(response.data.weights.alt_text);
                    $('#weight_filename_slider').val(response.data.weights.filename);
                    $('#weight_title_text_slider').val(response.data.weights.title_text);
                    $('#weight_keyword_density_slider').val(response.data.weights.keyword_density);
                    $('#weight_uniqueness_slider').val(response.data.weights.uniqueness);

                    // Trigger input to recalculate total
                    $weightInputs.first().trigger('input');

                    // Update badges
                    $('.infyp-weight-badge').eq(0).text(response.data.weights.alt_text + '%');
                    $('.infyp-weight-badge').eq(1).text(response.data.weights.filename + '%');
                    $('.infyp-weight-badge').eq(2).text(response.data.weights.title_text + '%');

                    // Show success status
                    $saveStatus
                        .html('<span class="dashicons dashicons-yes-alt"></span><span>' + infyp_seo_score.strings.saved + '</span>')
                        .removeClass('loading error warning')
                        .addClass('success');
                    showNotice('success', response.data.message);

                    // Clear status after delay
                    setTimeout(function() {
                        $saveStatus.removeClass('show');
                        setTimeout(function() {
                            $saveStatus.html('').removeClass('success');
                        }, 300);
                    }, 3000);
                } else {
                    handleSaveError(response.data?.message || infyp_seo_score.strings.error);
                }
            },
            error: function() {
                $saveStatus.text(infyp_seo_score.strings.error).addClass('error');
                showNotice('error', infyp_seo_score.strings.error);
            }
        });
    });

    /* =========================================================================
       HELPER FUNCTIONS
       ========================================================================= */

    /**
     * Handle save error state.
     *
     * Updates the save status indicator with error styling
     * and shows an error notice. Auto-clears after 5 seconds.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {string} message - Error message to display.
     */
    function handleSaveError(message) {
        $saveStatus
            .html('<span class="dashicons dashicons-dismiss"></span><span>' + escapeHtml(message) + '</span>')
            .removeClass('loading success warning')
            .addClass('error');

        setTimeout(function() {
            $saveStatus.removeClass('show');
            setTimeout(function() {
                $saveStatus.html('').removeClass('error');
            }, 300);
        }, 5000);

        showNotice('error', message);
    }

    /**
     * Display a WordPress admin notice.
     *
     * Creates and shows a dismissible notice in the designated
     * notice container. Auto-dismisses after 5 seconds.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {string} type - Notice type: 'success', 'error', 'warning', 'info'.
     * @param {string} message - Message to display.
     */
    function showNotice(type, message) {
        const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>');
        $('#infyp-seo-score-notice').html($notice).show();

        // Handle dismiss button click
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut();
        });

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut();
        }, 5000);
    }
});
