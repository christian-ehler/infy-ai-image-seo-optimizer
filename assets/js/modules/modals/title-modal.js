/**
 * Title Modal Module - Title editing and optimization
 *
 * This module provides a modal dialog for editing and optimizing image titles.
 * It includes real-time character/word counting, SEO scoring, keyword usage tracking,
 * and AI-powered optimization capabilities.
 *
 * ## Features
 *
 * - Real-time title editing with character/word count validation
 * - Live SEO score calculation with percentage display
 * - Keyword usage tracking and visual feedback
 * - AI-powered optimization via single field API
 * - Image preview with high-quality URL
 * - Metric circles for keywords, characters, and words
 * - PHP template loading with JS fallback
 *
 * ## AJAX Actions
 *
 * | Action                          | Purpose                                    |
 * |---------------------------------|--------------------------------------------|
 * | infyp_get_fresh_image_url     | Fetch latest image URLs and metadata       |
 * | infyp_load_title_modal        | Load modal HTML from PHP template          |
 * | infyp_update_image_data       | Save title changes to database             |
 * | infyp_optimize_single_field   | Generate AI-optimized title                |
 *
 * @file Title modal module for image SEO optimization.
 * @author AI Image SEO
 * @since 1.0.0
 * @module modals/title-modal
 * @requires jQuery
 * @requires api
 * @requires config
 * @requires scoring-engine
 * @requires base-modal
 * @requires notifications
 * @requires keyword-manager
 */
import api from '../api.js';
import config from '../core/config.js';
import scoringEngine from '../core/scoring-engine.js';
import baseModal from './base-modal.js';
import notifications from '../ui/notifications.js';
import optimizeStatus from '../ui/optimize-status.js';
// Phase 2C: Use shared modules
import keywordManager from '../features/keyword-manager.js';

const titleModal = (function($) {
    'use strict';

    /* =========================================================================
       MODULE DEPENDENCIES
       ========================================================================= */

    // Get config functions
    const getScoringParams = config.getScoringParams;
    const getTitleParams = config.getTitleParams;
    const getWeights = config.getWeights;

    // Get scoring engine functions
    const updateLiveSEOScore = scoringEngine.updateLiveSEOScore;
    const updateSEOScore = scoringEngine.updateSEOScore;

    // Use shared keyword functions from keyword-manager module
    const checkKeywordUsageHelper = keywordManager.checkUsage;
    const updateKeywordUsageDisplay = keywordManager.updateUsageDisplay;

    /* =========================================================================
       MODAL INITIALIZATION
       ========================================================================= */

    /**
     * Shows title editor modal.
     *
     * Fetches fresh image URL and metadata from server before displaying the modal
     * to ensure high-quality image preview. Falls back to table thumbnail if fetch fails.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {number} imageId - The WordPress attachment ID.
     * @param {string} currentTitle - Current title value to display in editor.
     * @returns {void}
     */
    function showTitleEditor(imageId, currentTitle) {
        $('#infyp-lightbox-overlay').remove();
        $('#infyp-title-lightbox').remove();

        // Fetch fresh image data first
        api.post('infyp_get_fresh_image_url', {
            image_id: imageId
        }).done(function(response) {
            if (response.success && response.data) {
                // Use fresh data from server
                const imageUrl = response.data.urls?.display || response.data.urls?.large || response.data.urls?.full || '';
                const freshTitle = response.data.title || currentTitle;

                displayTitleModal(imageId, freshTitle, imageUrl);
            } else {
                // Fallback to old method
                const $row = $(`tr[data-id="${imageId}"]`);
                const $imageThumb = $row.find('.column-thumbnail img');
                let imageUrl = $imageThumb.attr('src') || '';

                if (imageUrl) {
                    imageUrl = imageUrl.replace(/-\d+x\d+(\.\w+)$/, '$1');
                }

                displayTitleModal(imageId, currentTitle, imageUrl);
            }
        }).fail(function() {
            // Fallback on error
            const $row = $(`tr[data-id="${imageId}"]`);
            const $imageThumb = $row.find('.column-thumbnail img');
            let imageUrl = $imageThumb.attr('src') || '';

            if (imageUrl) {
                imageUrl = imageUrl.replace(/-\d+x\d+(\.\w+)$/, '$1');
            }

            displayTitleModal(imageId, currentTitle, imageUrl);
        });
    }

    /**
     * Displays title modal.
     *
     * Wrapper function that delegates to PHP template loading method.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {number} imageId - The WordPress attachment ID.
     * @param {string} currentTitle - Current title value.
     * @param {string} imageUrl - Image URL for preview.
     * @returns {void}
     */
    function displayTitleModal(imageId, currentTitle, imageUrl) {
        // Use PHP/AJAX approach to load modal
        loadTitleModalFromPHP(imageId, currentTitle, imageUrl);
    }

    /* =========================================================================
       PHP TEMPLATE LOADING
       ========================================================================= */

    /**
     * Loads title modal from PHP template via AJAX.
     *
     * Requests modal HTML from server for consistent styling and i18n support.
     * Populates modal with dynamic content including keywords, counters, and
     * scoring displays.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {number} imageId - The WordPress attachment ID.
     * @param {string} currentTitle - Current title value.
     * @param {string} imageUrl - Image URL for preview.
     * @returns {void}
     */
    function loadTitleModalFromPHP(imageId, currentTitle, imageUrl) {
        const i18n = window.infyp_vars.i18n;

        // Load modal HTML from PHP template
        $.post(window.infyp_vars.ajax_url, {
            action: 'infyp_load_title_modal',
            nonce: window.infyp_vars.nonce,
            image_id: imageId,
            current_title: currentTitle,
            image_url: imageUrl
        }).done(function(response) {
            if (response.success && response.data) {
                // Remove any existing modal
                $('#infyp-lightbox-overlay, #infyp-title-lightbox').remove();

                // Append the PHP-generated modal HTML
                $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                // Update modal with dynamic content
                const $modal = $('#infyp-title-lightbox');
                const $imageIdSpan = $modal.find('.infyp-image-id');
                const $imagePreview = $modal.find('.infyp-modal-image-preview img');
                const $input = $modal.find('#infyp-title-editor');
                const $saveBtn = $modal.find('.infyp-save-title');
                const $optimizeBtn = $modal.find('#infyp-optimize-title-btn');
                const $keywordsContainer = $modal.find('.infyp-keywords-container');

                // Set dynamic attributes and values
                $modal.attr('data-image-id', imageId);
                $imageIdSpan.text('#' + imageId);
                $imagePreview.attr('src', imageUrl);
                $input.val(currentTitle);
                $saveBtn.attr('data-image-id', imageId);
                $optimizeBtn.attr('data-id', imageId);

                // Add keywords if they exist
                if (response.data.keywords_html) {
                    $keywordsContainer.html(baseModal.sanitizeHtml(response.data.keywords_html)).show();
                } else {
                    $keywordsContainer.hide();
                }

                // Initialize counter and show modal using .is-open class system
                updateTitleCounter();

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
                $modal.addClass('is-open');

                // Focus input after animation
                setTimeout(() => $input.get(0).focus(), 200);
            } else {
                console.error('Failed to load title modal:', response);
                alert(i18n.error_loading_modal || 'Error loading title modal. Please try again.');
            }
        }).fail(function() {
            console.error('AJAX request failed for title modal');
            alert(i18n.error_loading_modal || 'Error loading title modal. Please try again.');
        });
    }

    /* =========================================================================
       COUNTER AND SCORING
       ========================================================================= */

    /**
     * Updates title counter and scoring display.
     *
     * Calculates character and word counts, validates against min/max parameters,
     * updates SEO score with circular progress indicator, generates metric cards
     * for keywords/characters/words, and updates table row badges in real-time.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function updateTitleCounter() {
        const $input = $('#infyp-title-editor');
        const $counter = $('.infyp-title-counter');
        const value = $input.val();
        const charCount = [...value].length;
        const wordCount = value.trim() ? value.trim().split(/\s+/).length : 0;
        const params = getScoringParams();
        const titleParams = params.title;
        const weights = getWeights();
        const i18n = window.infyp_vars.i18n;

        let score = 0;
        // Shared tolerance-aware status: green within the play room around the
        // optimal range; 'error' red is reserved for missing values.
        let status = {
            class: 'empty',
            icon: 'dismiss',
            text: i18n.missing_title || 'Missing',
            ok: false,
            tooltip: ''
        };

        if (value.trim()) {
            score += weights.title_presence;
            status = scoringEngine.lengthStatus(value, titleParams);
            score += status.ok ? weights.title_quality : weights.title_quality * 0.5;
        }

        const tooltipAttr = status.tooltip ? ` title="${status.tooltip}"` : '';
        let counterHtml = `
            <div class="infyp-modal-counter-badges">
                <span class="infyp-field-feedback ${status.class}"${tooltipAttr}>
                    <span class="dashicons dashicons-${status.icon}"></span> ${status.text}
                </span>
                <span class="infyp-field-feedback char-count">${charCount} | ${titleParams.max_chars} ${i18n.chars || 'chars'}</span>
                <span class="infyp-field-feedback word-count">${wordCount} | ${titleParams.max_words} ${i18n.words || 'words'}</span>
            </div>
        `;
        $counter.html(counterHtml);

        const imageId = $('.infyp-save-title').data('image-id');
        if (imageId) {
            const $row = $(`tr[data-id="${imageId}"]`);
            const $titleInput = $row.find('.infyp-title-trigger');
            const $badgeContainer = $titleInput.closest('.infyp-field-wrapper').find('.infyp-badge-container');
            const tableStatusHtml = `<span class="dashicons dashicons-${status.icon}"></span> ${value.trim() ? status.text : (i18n.missing || 'Missing')}`;

            $badgeContainer.html(`
                <div class="infyp-field-feedback char-count">${charCount} | ${titleParams.max_chars} ${i18n.chars || 'chars'}</div>
                <div class="infyp-field-feedback ${status.class}"${tooltipAttr}>${tableStatusHtml}</div>
            `);

            updateLiveSEOScore(imageId, 'title', value);
        }

        const maxScore = weights.title_presence + weights.title_quality;
        const percentage = Math.round((score / maxScore) * 100);
        const progressClass = percentage >= 90 ? 'success' : percentage >= 60 ? 'warning' : 'error';

        // Circle SVG
        const circumference = 2 * Math.PI * 52;
        const dashOffset = circumference - (percentage / 100) * circumference;

        $('#infyp-title-lightbox .infyp-score-percentage').html(`
            <div class="infyp-circle-container">
                <svg class="infyp-circle-progress infyp-circle-${progressClass}" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                    <circle class="infyp-circle-bg" cx="60" cy="60" r="52"></circle>
                    <circle class="infyp-circle-fg" cx="60" cy="60" r="52" style="stroke-dashoffset: ${dashOffset}"></circle>
                    <text class="infyp-circle-text" x="60" y="60" text-anchor="middle" dominant-baseline="central">${percentage}%</text>
                </svg>
                <h4>${i18n.title_score || 'Title Score'}</h4>
                <div class="infyp-score-points-breakdown">
                    <span class="infyp-points-badge infyp-points-earned infyp-badge-${progressClass}">${Math.round(score)}</span>
                    <span class="infyp-points-separator">|</span>
                    <span class="infyp-points-badge infyp-points-total infyp-badge-success">${maxScore}</span>
                </div>
            </div>
        `);

        // Helper function to create metric circle (same as filename modal)
        const createTitleMetricCircle = (current, min, max, label, valueText, zone) => {
            let percentage, progressClass, statusText;

            // Tolerance-aware: a metric inside the play room shows full/success,
            // matching the badge and the score.
            if (zone === 'optimal' || zone === 'acceptable') {
                percentage = 100;
                progressClass = 'success';
            } else if (current < min) {
                percentage = Math.round((current / min) * 100);
                progressClass = 'error';
            } else if (current > max) {
                percentage = Math.max(0, 100 - Math.round(((current - max) / max) * 100));
                progressClass = 'warning';
            } else {
                percentage = 100;
                progressClass = 'success';
            }
            statusText = valueText;

            const metricCircumference = 2 * Math.PI * 39;
            const metricDashOffset = metricCircumference - (percentage / 100) * metricCircumference;

            return `
                <div class="infyp-metric-card">
                    <svg class="infyp-metric-circle infyp-circle-${progressClass}" viewBox="0 0 90 90" xmlns="http://www.w3.org/2000/svg">
                        <circle class="infyp-metric-circle-bg" cx="45" cy="45" r="39"></circle>
                        <circle class="infyp-metric-circle-fg" cx="45" cy="45" r="39" style="stroke-dashoffset: ${metricDashOffset}"></circle>
                        <text class="infyp-metric-circle-text" x="45" y="45" text-anchor="middle" dominant-baseline="central">${percentage}%</text>
                    </svg>
                    <div class="infyp-metric-label">${label}</div>
                    <div class="infyp-metric-value">${statusText}</div>
                </div>
            `;
        };

        // Always show the 3 metric cards (keywords, characters, words)
        let breakdownHtml = '<div class="infyp-score-details">';

        // KEYWORD USAGE METRIC - First position
        const $keywordBadges = $('.infyp-modal-keyword-badge');
        let keywordsUsedCount = 0;
        const minKeywordsRequired = 2;

        $keywordBadges.each(function() {
            const $badge = $(this);
            // Get only the text content, excluding the dashicon
            const keyword = $badge.clone().children().remove().end().text().trim();

            if (keyword && value) {
                // Use the same helper function as updateKeywordUsageDisplay
                const isUsed = checkKeywordUsageHelper(value, keyword, false);
                if (isUsed) {
                    keywordsUsedCount++;
                }
            }
        });

        const keywordValueText = `${keywordsUsedCount} / ${minKeywordsRequired}`;

        // Calculate keyword percentage: 0 keywords = 0%, 1 keyword = 50%, 2+ keywords = 100%
        let keywordPercentage = 0;
        let keywordProgressClass = 'error';

        if (keywordsUsedCount === 0) {
            keywordPercentage = 0;
            keywordProgressClass = 'error';
        } else if (keywordsUsedCount === 1) {
            keywordPercentage = 50;
            keywordProgressClass = 'warning';
        } else {
            // 2 or more keywords = 100%
            keywordPercentage = 100;
            keywordProgressClass = 'success';
        }

        // Create keyword metric circle manually with percentage
        const keywordCircumference = 2 * Math.PI * 39;
        const keywordDashOffset = keywordCircumference - (keywordPercentage / 100) * keywordCircumference;

        breakdownHtml += `
            <div class="infyp-metric-card">
                <svg class="infyp-metric-circle infyp-circle-${keywordProgressClass}" viewBox="0 0 90 90" xmlns="http://www.w3.org/2000/svg">
                    <circle class="infyp-metric-circle-bg" cx="45" cy="45" r="39"></circle>
                    <circle class="infyp-metric-circle-fg" cx="45" cy="45" r="39" style="stroke-dashoffset: ${keywordDashOffset}"></circle>
                    <text class="infyp-metric-circle-text" x="45" y="45" text-anchor="middle" dominant-baseline="central">${keywordPercentage}%</text>
                </svg>
                <div class="infyp-metric-label">${i18n.keywords_label || 'Keywords'}</div>
                <div class="infyp-metric-value">${keywordValueText}</div>
            </div>
        `;

        // Characters metric
        const charValueText = `${charCount} / ${titleParams.max_chars}`;
        breakdownHtml += createTitleMetricCircle(
            charCount,
            titleParams.min_chars,
            titleParams.max_chars,
            i18n.characters_label || 'Characters',
            charValueText,
            scoringEngine.evaluateChars(charCount, titleParams)
        );

        // Words metric
        const wordValueText = `${wordCount} / ${titleParams.max_words}`;
        breakdownHtml += createTitleMetricCircle(
            wordCount,
            titleParams.min_words,
            titleParams.max_words,
            i18n.words_label || 'Words',
            wordValueText,
            scoringEngine.evaluateWords(wordCount, titleParams)
        );

        breakdownHtml += '</div>';

        $('#infyp-title-lightbox .infyp-score-breakdown').html(breakdownHtml);

        // Update keyword usage display in real-time
        updateKeywordUsageDisplay(value, false);
    }

    /* =========================================================================
       AI OPTIMIZATION
       ========================================================================= */

    /**
     * Handles AI optimization for title within modal.
     *
     * Calls the single field optimization API to generate AI-optimized title
     * based on image content and keywords. Updates input field and counter on success.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {Event} e - Click event from optimize button.
     * @returns {void}
     */
    function handleOptimizeAI(e) {
        e.preventDefault();
        const $button = $(e.currentTarget);
        const imageId = $button.data('id');
        const $input = $('#infyp-title-editor');
        const i18n = window.infyp_vars.i18n;

        // Hide disclaimer and button, show status spinner with timer
        const $buttonGrid = $button.closest('.infyp-modal-optimize-grid');
        $buttonGrid.addClass('infyp-optimizing');
        $buttonGrid.append('<div class="infyp-modal-optimize-spinner">' + optimizeStatus.createStatusHtml() + '</div>');
        const stopStatus = optimizeStatus.startStatusTimer($buttonGrid.find('.infyp-modal-optimize-spinner'));

        // Get keywords from the modal
        const $row = $(`tr[data-id="${imageId}"]`);
        const keywordsData = $row.attr('data-keywords');
        let keywords = [];
        try {
            keywords = keywordsData ? JSON.parse(keywordsData) : [];
        } catch (err) {
            keywords = [];
        }

        // Read context prompt from the extension-injected textarea (if present)
        const $contextPrompt = $('#infyp-title-lightbox').find('.infyp-context-prompt-textarea');
        const contextPromptVal = $contextPrompt.length ? $contextPrompt.val().trim() : '';

        // Call the single field optimization API
        api.post('infyp_optimize_single_field', {
            image_id: imageId,
            field_type: 'title',
            keywords: keywords,
            custom_prompt: contextPromptVal
        }).done(function(response) {
            if (response.success && response.data.optimized_value) {
                // Update the input with the optimized title
                $input.val(response.data.optimized_value);
                updateTitleCounter();

                // Show success notification
                notifications.show(infyp_vars.i18n.title_optimized || 'Title optimized with AI', 'success');
            } else {
                notifications.show(response.data?.message || infyp_vars.i18n.error_optimizing || 'Error optimizing title', 'error');
            }
        }).fail(function() {
            notifications.show(infyp_vars.i18n.server_error || 'Server error occurred', 'error');
        }).always(function() {
            stopStatus();
            $buttonGrid.removeClass('infyp-optimizing');
            $buttonGrid.find('.infyp-modal-optimize-spinner').remove();
        });
    }

    /* =========================================================================
       MODAL CONTROLS
       ========================================================================= */

    /**
     * Handles modal close/cancel action.
     *
     * Removes .is-open class from modal and .is-visible from overlay,
     * then removes them from DOM after CSS transition completes.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function handleClose() {
        const $overlay = $('#infyp-lightbox-overlay');
        const $modal = $('#infyp-title-lightbox');

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
    }

    /* =========================================================================
       EVENT BINDING
       ========================================================================= */

    /**
     * Binds body-level event handlers for title modal.
     *
     * Sets up delegated events for input changes, save button, AI optimization,
     * and close actions. Called once during initialization.
     *
     * @since 1.0.0
     * @access public
     *
     * @returns {void}
     */
    function bindEvents() {
        const $body = $('body');

        // Input handler for counter updates
        $body.on('input', '#infyp-title-editor', function() {
            updateTitleCounter();
        });

        // Save button handler
        $body.on('click', '.infyp-save-title', function() {
            saveTitle();
        });

        // AI optimization handler
        $body.on('click', '#infyp-title-lightbox .infyp-optimize-ai', handleOptimizeAI);

        // Close/cancel handlers
        $body.on('click', '.infyp-cancel-title,.infyp-title-lightbox-close', handleClose);

        // Overlay click to close (click outside modal)
        $body.on('click', '#infyp-lightbox-overlay', function(e) {
            // Only close if clicking directly on overlay, not on modal content
            if (e.target === this) {
                handleClose();
            }
        });
    }

    /* =========================================================================
       DATA PERSISTENCE
       ========================================================================= */

    /**
     * Saves title to database.
     *
     * Sends updated title to server, updates table row display,
     * recalculates SEO score, and closes modal on success.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function saveTitle() {
        const $saveBtn = $('.infyp-save-title');
        const imageId = $saveBtn.data('image-id');
        const newTitle = $('#infyp-title-editor').val();
        const i18n = window.infyp_vars.i18n;

        $saveBtn.prop('disabled', true).text(i18n.saving || 'Saving...');

        api.post('infyp_update_image_data', {
            image_id: imageId,
            field: 'title',
            value: newTitle
        }).done(function(response) {
            if (response.success) {
                const $input = $(`.infyp-title-trigger[data-image-id="${imageId}"]`);
                $input.val(newTitle).data('original-value', newTitle);
                $input.trigger('input');

                if (response.data && response.data.seo_score) {
                    updateSEOScore(imageId, response.data.seo_score);
                }

                // Restore scroll position and unlock body scroll
                const scrollY = $('body').data('scroll-position') || 0;
                $('body')
                    .removeClass('infyp-lightbox-open')
                    .css('top', '');
                window.scrollTo(0, scrollY);

                // Close modal using .is-open class system
                const $overlay = $('#infyp-lightbox-overlay');
                const $lightbox = $('#infyp-title-lightbox');

                $overlay.removeClass('is-visible');
                $lightbox.removeClass('is-open');

                // After animation, remove from DOM
                setTimeout(function() {
                    $overlay.remove();
                    $lightbox.remove();
                }, 200);

                // Show success notification
                baseModal.showNotification(i18n.title_updated || 'Title updated successfully', 'success');
            } else {
                alert(response.data.message || i18n.error_saving || 'Error saving title');
            }
        }).fail(function() {
            alert(i18n.server_error || 'Server error');
        }).always(function() {
            $saveBtn.prop('disabled', false).text(i18n.save_changes || 'Save Changes');
        });
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the title modal module.
     *
     * @since 1.0.0
     *
     * @return {Object} Public methods.
     * @property {Function} show - Show modal with image ID and current title.
     * @property {Function} display - Display modal with all parameters.
     * @property {Function} loadFromPHP - Load modal from PHP template.
     * @property {Function} updateCounter - Update character/word counter and scoring.
     * @property {Function} save - Save title changes to database.
     * @property {Function} bindEvents - Bind modal event handlers.
     * @property {Function} close - Close and remove modal from DOM.
     */
    return {
        show: showTitleEditor,
        display: displayTitleModal,
        loadFromPHP: loadTitleModalFromPHP,
        updateCounter: updateTitleCounter,
        save: saveTitle,
        bindEvents: bindEvents,
        close: handleClose
    };
})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = titleModal;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.titleModal = titleModal;
}

export default titleModal;
