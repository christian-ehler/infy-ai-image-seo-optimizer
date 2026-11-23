/**
 * AI Optimizer Module - Complete AI-powered image optimization workflow
 *
 * This module provides the core functionality for AI-powered image SEO optimization.
 * It handles the full optimization workflow including field updates, validation badges,
 * keyword management, and UI state management during optimization.
 *
 * ## Features
 *
 * - Complete AI optimization workflow for images
 * - Real-time field validation and badge updates
 * - Alt text, title, and filename optimization
 * - Extensible via custom events for additional fields
 * - Keyword column updates
 * - SEO score recalculation after optimization
 * - Loading states and error handling
 * - Field-specific validation rules
 *
 * ## Validation Rules
 *
 * | Field    | Min Chars | Max Chars | Min Words | Max Words | Special Rules                    |
 * |----------|-----------|-----------|-----------|-----------|----------------------------------|
 * | Alt Text | Varies    | Varies    | Varies    | Varies    | Character and word count limits  |
 * | Title    | Varies    | Varies    | Varies    | Varies    | Character and word count limits  |
 * | Filename | Varies    | Varies    | N/A       | N/A       | Format: lowercase-with-hyphens   |
 *
 * ## Usage
 *
 * ```javascript
 * import aiOptimizer from './features/ai-optimizer.js';
 *
 * // Attach to optimize button
 * $('.infyp-optimize-ai').on('click', aiOptimizer.handleOptimizeClick);
 *
 * // Update field badge manually
 * aiOptimizer.updateFieldBadge($badge, charCount, params, validation, value, 'alt');
 *
 * // Update keywords column after optimization
 * aiOptimizer.updateKeywordsColumn($tr, imageId, keywords);
 * ```
 *
 * @file AI Optimizer Module - Complete AI-powered image optimization workflow
 * @author InfyPress
 * @since 1.0.0
 * @module features/ai-optimizer
 * @requires jQuery
 * @requires api
 * @requires core/config
 * @requires core/scoring-engine
 * @requires ui/notifications
 */

import api from '../api.js';
import config from '../core/config.js';
import scoringEngine from '../core/scoring-engine.js';
import notifications from '../ui/notifications.js';
import optimizeStatus from '../ui/optimize-status.js';
import customPromptModal from '../modals/custom-prompt-modal.js';
import loadingStates from '../ui/loading-states.js';

const aiOptimizer = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    // Get config functions
    const getAltParams = config.getAltParams;
    const getTitleParams = config.getTitleParams;
    const getFilenameParams = config.getFilenameParams;
    const updateSEOScore = scoringEngine.updateSEOScore;

    /* =========================================================================
       FIELD VALIDATION
       ========================================================================= */

    /**
     * Updates field badge with validation status and visual feedback.
     *
     * Displays character count, validation status (optimized, missing, too short, etc.),
     * and appropriate icons based on field-specific validation rules. Supports both
     * server-side validation results and client-side validation.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {jQuery} $badgeContainer - Badge container element to update
     * @param {number} charCount - Current character count of field value
     * @param {Object} params - Field validation parameters (min_chars, max_chars, min_words, max_words)
     * @param {Object} [validation] - Server validation result with icon, text, and class properties
     * @param {string} value - Current field value to validate
     * @param {string} fieldType - Field type: 'alt', 'title', or 'filename'
     * @returns {void}
     *
     * @example
     * // Update alt text badge
     * updateFieldBadge($badge, 45, {min_chars: 40, max_chars: 60}, null, 'Red car', 'alt');
     *
     * @example
     * // Update with server validation
     * updateFieldBadge($badge, 45, params, {icon: 'yes-alt', text: 'Optimized', class: 'success'}, 'Red car', 'alt');
     */
    function updateFieldBadge($badgeContainer, charCount, params, validation, value, fieldType) {
        if (validation) {
            const statusHtml = `<span class="dashicons dashicons-${validation.icon}"></span> ${validation.text}`;
            $badgeContainer.html(`
                <div class="infyp-field-feedback char-count">${charCount} | ${params.max_chars} ${infyp_vars.i18n.chars || 'chars'}</div>
                <div class="infyp-field-feedback ${validation.class}">${statusHtml}</div>
            `);
            return;
        }

        // Calculate validation locally via the shared tolerance-aware helper
        let status;

        if (!value) {
            status = {
                class: 'empty',
                icon: 'dismiss',
                text: infyp_vars.i18n.missing || 'Missing',
                tooltip: ''
            };
        } else if (fieldType === 'filename' && /^(DSC|IMG|DCIM|image)[\d_-]+$/i.test(value)) {
            status = {
                class: 'warning',
                icon: 'warning',
                text: infyp_vars.i18n.generic || 'Generic',
                tooltip: ''
            };
        } else if (fieldType === 'filename' && !/^[a-z0-9\-]+$/.test(value)) {
            status = {
                class: 'warning',
                icon: 'warning',
                text: infyp_vars.i18n.invalid_format || 'Invalid format',
                tooltip: ''
            };
        } else {
            status = scoringEngine.lengthStatus(value, params, fieldType === 'filename' ? 'filename' : fieldType);
        }

        const tooltipAttr = status.tooltip ? ` title="${status.tooltip}"` : '';
        $badgeContainer.html(`
            <div class="infyp-field-feedback char-count">${charCount} | ${params.max_chars} ${infyp_vars.i18n.chars || 'chars'}</div>
            <div class="infyp-field-feedback ${status.class}"${tooltipAttr}><span class="dashicons dashicons-${status.icon}"></span> ${status.text}</div>
        `);
    }

    /* =========================================================================
       KEYWORD COLUMN UPDATES
       ========================================================================= */

    /**
     * Updates keywords column with optimized keywords.
     *
     * Refreshes the keywords column display to show either filled keywords with
     * edit button or empty state with add button. Updates the row's data-keywords
     * attribute for persistence.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {jQuery} $tr - Table row containing the keywords column
     * @param {number} imageId - WordPress attachment ID
     * @param {Array} keywords - Array of keyword strings
     * @returns {void}
     *
     * @example
     * // Update with keywords
     * updateKeywordsColumn($tr, 123, ['red car', 'sports car', 'vehicle']);
     *
     * @example
     * // Update with empty keywords
     * updateKeywordsColumn($tr, 123, []);
     */
    function updateKeywordsColumn($tr, imageId, keywords) {
        const keywordsCell = $tr.find('.column-keywords');

        if (keywords && keywords.length > 0) {
            let keywordsHtml = '<div class="infyp-keywords-wrapper infyp-keywords-filled" data-image-id="' + imageId + '">';
            keywordsHtml += '<div class="infyp-keywords-list">';

            keywords.forEach(function(keyword) {
                keywordsHtml += '<span class="infyp-keyword-badge">' + $('<div>').text(keyword).html() + '</span>';
            });

            keywordsHtml += '<button class="infyp-keyword-badge placeholder infyp-add-keywords-trigger" ';
            keywordsHtml += 'data-image-id="' + imageId + '" ';
            keywordsHtml += 'title="' + (infyp_vars.i18n.edit_keywords || 'Edit keywords') + '">';
            keywordsHtml += '<span class="dashicons dashicons-edit"></span> ' + (infyp_vars.i18n.edit_keywords || 'Edit Keywords');
            keywordsHtml += '</button>';
            keywordsHtml += '</div></div>';

            keywordsCell.html(keywordsHtml);
            $tr.attr('data-keywords', JSON.stringify(keywords));
        } else {
            let keywordsHtml = '<div class="infyp-keywords-wrapper infyp-keywords-empty" data-image-id="' + imageId + '">';
            keywordsHtml += '<button class="infyp-keyword-badge placeholder infyp-add-keywords-trigger" ';
            keywordsHtml += 'data-image-id="' + imageId + '">';
            keywordsHtml += '<span class="dashicons dashicons-plus"></span> ' + (infyp_vars.i18n.add_keywords || 'Add Keywords');
            keywordsHtml += '</button>';
            keywordsHtml += '<div class="infyp-keywords-help">';
            keywordsHtml += '<span class="dashicons dashicons-info"></span>';
            keywordsHtml += '<span class="infyp-tooltip">' + (infyp_vars.i18n.click_to_add_keywords || 'Click to add keywords') + '</span>';
            keywordsHtml += '</div></div>';

            keywordsCell.html(keywordsHtml);
            $tr.attr('data-keywords', '[]');
        }
    }

    /* =========================================================================
       ACTION BUTTON CREATION
       ========================================================================= */

    /**
     * Creates action buttons after successful optimization.
     *
     * Replaces the loading state with re-optimize and SEO breakdown buttons,
     * plus an AI disclaimer tooltip. Used after AI optimization completes.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {jQuery} $actionsCell - Actions cell element to populate
     * @param {number} imageId - WordPress attachment ID
     * @param {number} score - Current SEO score percentage (0-100)
     * @returns {void}
     *
     * @example
     * createActionButtons($actionsCell, 123, 85);
     */
    function createActionButtons($actionsCell, imageId, score) {
        const $actionsWrapper = $('<div>').addClass('infyp-actions-wrapper');

        const reoptimizeButton = $('<button>')
            .addClass('button button-secondary button-small infyp-optimize-ai infyp-has-icon')
            .attr('data-id', imageId)
            .html('<span class="dashicons dashicons-yes-alt"></span>' + (infyp_vars.i18n.reoptimize || 'Re-optimize'));

        const breakdownButton = $('<button>')
            .addClass('button button-secondary button-small infyp-check-seo-breakdown')
            .attr('data-id', imageId)
            .attr('data-score', score)
            .attr('title', infyp_vars.i18n.check_seo_tooltip || 'Click to see what\'s needed for 100% score')
            .html(infyp_vars.i18n.seo_score_breakdown || 'SEO Score Breakdown');

        const disclaimerHtml = '<div class="infyp-ai-disclaimer">' +
            '<span class="dashicons dashicons-info"></span>' +
            '<span class="infyp-disclaimer-text">' +
            (infyp_vars.i18n.ai_disclaimer_short || 'AI Disclaimer') +
            '</span>' +
            '<span class="infyp-tooltip">' +
            (infyp_vars.i18n.ai_disclaimer || 'AI can make mistakes. Please double-check responses.') +
            '</span></div>';

        $actionsWrapper.append(reoptimizeButton, breakdownButton, disclaimerHtml);
        $actionsCell.empty().append($actionsWrapper);
    }

    /* =========================================================================
       AI OPTIMIZATION WORKFLOW
       ========================================================================= */

    /**
     * Handles AI optimization workflow when optimize button is clicked.
     *
     * Manages the complete optimization process including:
     * - Loading state display
     * - Field disabling during optimization
     * - AJAX request to backend
     * - Field updates with optimized values
     * - Validation badge updates
     * - Keywords column refresh
     * - SEO score recalculation
     * - Error handling and recovery
     *
     * @since 1.0.0
     * @access public
     *
     * @param {Event} e - Click event from optimize button
     * @returns {void}
     *
     * @example
     * // Attach to optimize button
     * $('.infyp-optimize-ai').on('click', handleOptimizeClick);
     */
    /**
     * Server messages arrive HTML-escaped. Turns entities back into plain
     * text without interpreting markup (DOMParser documents run no scripts);
     * the result is inserted with .text() only.
     *
     * @since 1.0.9
     *
     * @param {string} message - Message from the server.
     * @returns {string} Plain text.
     */
    function toPlainText(message) {
        const doc = new DOMParser().parseFromString(String(message), 'text/html');
        return (doc.documentElement.textContent || '').trim();
    }

    /**
     * Shows a failed optimization's message next to the row's buttons,
     * announced to screen readers (role="alert"). Replaces an earlier one.
     *
     * @since 1.0.9
     *
     * @param {jQuery} actionsCell - The row's actions cell.
     * @param {string} message - Message from the server.
     * @returns {void}
     */
    function showOptimizeError(actionsCell, message) {
        actionsCell.find('.infyp-optimize-error').remove();
        const $notice = $('<div class="notice notice-error inline infyp-optimize-error" role="alert"><p></p></div>');
        $notice.find('p').text(toPlainText(message) || infyp_vars.i18n.unknown_error);
        actionsCell.append($notice);
    }

    async function handleOptimizeClick(e) {
        e.preventDefault();

        const button = $(e.currentTarget);
        const tr = button.closest('tr');
        const actionsCell = tr.find('.column-actions');

        // A new attempt replaces the message of the last failed one.
        actionsCell.find('.infyp-optimize-error').remove();

        // Skip if already being processed
        if (tr.hasClass('infyp-processing')) {
            return;
        }

        const imageId = tr.data('id');
        if (!imageId) {
            console.error(infyp_vars.i18n.no_image_id_found);
            alert(infyp_vars.i18n.error_no_image_id);
            return;
        }

        // Check if per-image custom prompt is enabled
        let customPrompt = '';
        if (window.infyp_vars.enable_per_image_prompt) {
            // Show row spinner while modal loads, auto-cleanup when modal appears
            loadingStates.showWrapperSpinner(actionsCell, '#infyp-custom-prompt-modal');

            const promptResult = await customPromptModal.show(imageId);
            if (promptResult === null) {
                // User skipped or closed the modal — cancel optimization
                return;
            }
            customPrompt = promptResult.prompt || '';
        }

        const originalContent = actionsCell.html();

        // Show loading status with spinner, status text, and timer
        actionsCell.addClass('infyp-wrapper-loading');
        actionsCell.html(optimizeStatus.createStatusHtml());
        const stopStatus = optimizeStatus.startStatusTimer(actionsCell);

        // Disable all input fields in this row during optimization
        const $rowInputs = tr.find('.infyp-editable-field, .infyp-alt-text-trigger, .infyp-filename-trigger, .infyp-title-trigger, .infyp-add-keywords-trigger, .infyp-check-seo-breakdown');
        $rowInputs.addClass('infyp-optimizing-disabled').prop('disabled', true);
        tr.addClass('infyp-row-optimizing');
        $(document).trigger('infyp:optimization:start', [tr]);

        const ajaxData = { image_id: imageId };
        if (customPrompt) {
            ajaxData.custom_prompt = customPrompt;
        }

        api.post('infyp_optimize_with_ai', ajaxData).done(function(response) {
            stopStatus();
            if (response.success) {
                const { newData } = response.data;
                tr.data('ai-updating', true);

                // Update Alt Text field
                const $altInput = tr.find('.infyp-alt-text-trigger');
                $altInput.val(newData.alt_text).data('original-value', newData.alt_text);
                const $altBadgeContainer = $altInput.closest('.infyp-field-wrapper').find('.infyp-badge-container');
                const altCharCount = newData.alt_text ? [...newData.alt_text].length : 0;
                const altParams = response.data.scoring_params?.alt_text || getAltParams();
                updateFieldBadge($altBadgeContainer, altCharCount, altParams, response.data.validations?.alt_text, newData.alt_text, 'alt');

                // Update Title field
                const $titleInput = tr.find('.infyp-title-trigger');
                $titleInput.val(newData.title).data('original-value', newData.title);
                const $titleBadgeContainer = $titleInput.closest('.infyp-field-wrapper').find('.infyp-badge-container');
                const titleCharCount = newData.title ? [...newData.title].length : 0;
                const titleParams = response.data.scoring_params?.title || getTitleParams();
                updateFieldBadge($titleBadgeContainer, titleCharCount, titleParams, response.data.validations?.title, newData.title, 'title');

                // Update Filename field
                const $filenameInput = tr.find('.infyp-filename-trigger');
                $filenameInput.val(newData.filename).data('original-value', newData.filename);
                const $filenameBadgeContainer = $filenameInput.closest('.infyp-field-wrapper').find('.infyp-badge-container');
                const filenameCharCount = newData.filename ? [...newData.filename].length : 0;
                const filenameParams = response.data.scoring_params?.filename || getFilenameParams();
                updateFieldBadge($filenameBadgeContainer, filenameCharCount, filenameParams, response.data.validations?.filename, newData.filename, 'filename');

                // Allow extensions to handle additional fields
                $(document).trigger('infyp:optimization:applied', [tr, newData, response.data]);

                // Update keywords column
                updateKeywordsColumn(tr, imageId, newData.keywords);

                // Update SEO score
                if (response.data.seo_score) {
                    updateSEOScore(tr.data('id'), response.data.seo_score);
                }

                // Remove loading state and create action buttons
                actionsCell.removeClass('infyp-wrapper-loading');
                const newScore = response.data.seo_score ? response.data.seo_score.percentage : 0;
                createActionButtons(actionsCell, tr.data('id'), newScore);

                tr.removeData('ai-updating');

                // Re-enable inputs after successful optimization
                tr.find('.infyp-editable-field, .infyp-alt-text-trigger, .infyp-filename-trigger, .infyp-title-trigger, .infyp-add-keywords-trigger, .infyp-check-seo-breakdown')
                    .removeClass('infyp-optimizing-disabled').prop('disabled', false);
                tr.removeClass('infyp-row-optimizing');
                $(document).trigger('infyp:optimization:end', [tr]);

            } else {
                actionsCell.removeClass('infyp-wrapper-loading');
                actionsCell.html(originalContent);
                $rowInputs.removeClass('infyp-optimizing-disabled').prop('disabled', false);
                tr.removeClass('infyp-row-optimizing');
                $(document).trigger('infyp:optimization:end', [tr]);

                const errorMsg = (response.data && response.data.message) || infyp_vars.i18n.unknown_error;
                console.error('AI Optimization Response Error:', errorMsg);
                showOptimizeError(actionsCell, errorMsg);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            stopStatus();
            console.error('AI Optimization Failed:', {
                status: jqXHR.status,
                statusText: jqXHR.statusText,
                responseText: jqXHR.responseText,
                textStatus: textStatus,
                errorThrown: errorThrown
            });

            actionsCell.removeClass('infyp-wrapper-loading');
            actionsCell.html(originalContent);
            $rowInputs.removeClass('infyp-optimizing-disabled').prop('disabled', false);
            tr.removeClass('infyp-row-optimizing');
            $(document).trigger('infyp:optimization:end', [tr]);

            let errorMessage = infyp_vars.i18n.server_error;
            try {
                if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errorMessage = jqXHR.responseJSON.data.message;
                } else if (jqXHR.responseText) {
                    console.error('Raw response:', jqXHR.responseText);
                }
            } catch (e) {
                console.error('Error parsing response:', e);
            }
            showOptimizeError(actionsCell, errorMessage);
        });
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the AI optimizer module.
     *
     * @since 1.0.0
     *
     * @returns {Object} Public methods
     * @property {Function} handleOptimizeClick - Handle optimize button click event
     * @property {Function} updateFieldBadge - Update field validation badge
     * @property {Function} updateKeywordsColumn - Update keywords column display
     * @property {Function} createActionButtons - Create action buttons after optimization
     */
    return {
        handleOptimizeClick: handleOptimizeClick,
        updateFieldBadge: updateFieldBadge,
        updateKeywordsColumn: updateKeywordsColumn,
        createActionButtons: createActionButtons
    };

})(jQuery);

// Export for ES6 modules
export default aiOptimizer;
