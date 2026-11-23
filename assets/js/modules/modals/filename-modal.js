/**
 * Filename Modal Module - Filename editing and optimization
 *
 * This module provides a modal dialog for editing and optimizing image filenames.
 * It includes auto-fixing for format issues (underscores, uppercase), real-time
 * validation, SEO scoring, keyword tracking, and AI-powered optimization.
 *
 * ## Features
 *
 * - Real-time filename editing with auto-fix for underscores/uppercase
 * - Format validation (lowercase, hyphens, alphanumeric only)
 * - Character/word count with min/max validation
 * - Live SEO score calculation with percentage display
 * - Keyword usage tracking (slug-formatted)
 * - AI-powered optimization via single field API
 * - Metric circles for keywords, characters, words, and format
 * - PHP template loading with JS fallback
 *
 * ## AJAX Actions
 *
 * | Action                          | Purpose                                    |
 * |---------------------------------|--------------------------------------------|
 * | infyp_get_fresh_image_url     | Fetch latest image URLs and metadata       |
 * | infyp_load_filename_modal     | Load modal HTML from PHP template          |
 * | infyp_update_filename         | Save filename changes and rename file      |
 * | infyp_optimize_single_field   | Generate AI-optimized filename             |
 *
 * @file Filename modal module for image SEO optimization.
 * @author AI Image SEO
 * @since 1.0.0
 * @module modals/filename-modal
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

const filenameModal = (function($) {
    'use strict';

    /* =========================================================================
       MODULE DEPENDENCIES
       ========================================================================= */

    // Get config functions
    const getScoringParams = config.getScoringParams;
    const getFilenameParams = config.getFilenameParams;
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
     * Shows filename editor modal.
     *
     * Fetches fresh image URL and metadata from server before displaying the modal
     * to ensure high-quality image preview. Falls back to table thumbnail if fetch fails.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {number} imageId - The WordPress attachment ID.
     * @param {string} currentFilename - Current filename value (without extension).
     * @returns {void}
     */
    function showFilenameEditor(imageId, currentFilename) {
        $('#infyp-lightbox-overlay').remove();
        $('#infyp-filename-lightbox').remove();

        // Fetch fresh image data first
        api.post('infyp_get_fresh_image_url', {
            image_id: imageId
        }).done(function(response) {
            if (response.success && response.data) {
                // Use fresh data from server
                const imageUrl = response.data.urls?.display || response.data.urls?.large || response.data.urls?.full || '';
                const freshFilename = response.data.filename || currentFilename;

                displayFilenameModal(imageId, freshFilename, imageUrl);
            } else {
                // Fallback to old method
                const $row = $(`tr[data-id="${imageId}"]`);
                const $imageThumb = $row.find('.column-thumbnail img');
                let imageUrl = $imageThumb.attr('src') || '';

                if (imageUrl) {
                    imageUrl = imageUrl.replace(/-\d+x\d+(\.\w+)$/, '$1');
                }

                displayFilenameModal(imageId, currentFilename, imageUrl);
            }
        }).fail(function() {
            // Fallback on error
            const $row = $(`tr[data-id="${imageId}"]`);
            const $imageThumb = $row.find('.column-thumbnail img');
            let imageUrl = $imageThumb.attr('src') || '';

            if (imageUrl) {
                imageUrl = imageUrl.replace(/-\d+x\d+(\.\w+)$/, '$1');
            }

            displayFilenameModal(imageId, currentFilename, imageUrl);
        });
    }

    /**
     * Displays filename modal.
     *
     * Wrapper function that delegates to PHP template loading method.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {number} imageId - The WordPress attachment ID.
     * @param {string} currentFilename - Current filename value.
     * @param {string} imageUrl - Image URL for preview.
     * @returns {void}
     */
    function displayFilenameModal(imageId, currentFilename, imageUrl) {
        // Use PHP/AJAX approach to load modal
        loadFilenameModalFromPHP(imageId, currentFilename, imageUrl);
    }

    /* =========================================================================
       PHP TEMPLATE LOADING
       ========================================================================= */

    /**
     * Loads filename modal from PHP template via AJAX.
     *
     * Requests modal HTML from server for consistent styling and i18n support.
     * Populates modal with dynamic content including keywords, counters, and
     * scoring displays.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {number} imageId - The WordPress attachment ID.
     * @param {string} currentFilename - Current filename value.
     * @param {string} imageUrl - Image URL for preview.
     * @returns {void}
     */
    function loadFilenameModalFromPHP(imageId, currentFilename, imageUrl) {
        const i18n = window.infyp_vars.i18n;

        // Load modal HTML from PHP template
        $.post(window.infyp_vars.ajax_url, {
            action: 'infyp_load_filename_modal',
            nonce: window.infyp_vars.nonce,
            image_id: imageId,
            current_filename: currentFilename,
            image_url: imageUrl
        }).done(function(response) {
            if (response.success && response.data) {
                // Remove any existing modal
                $('#infyp-lightbox-overlay, #infyp-filename-lightbox').remove();

                // Append the PHP-generated modal HTML
                $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                // Update modal with dynamic content
                const $modal = $('#infyp-filename-lightbox');
                const $imageIdSpan = $modal.find('.infyp-image-id');
                const $imagePreview = $modal.find('.infyp-modal-image-preview img');
                const $input = $modal.find('#infyp-filename-editor');
                const $saveBtn = $modal.find('.infyp-save-filename');
                const $optimizeBtn = $modal.find('#infyp-optimize-filename-btn');
                const $keywordsContainer = $modal.find('.infyp-keywords-container');

                // Set dynamic attributes and values
                $modal.attr('data-image-id', imageId);
                $imageIdSpan.text('#' + imageId);
                $imagePreview.attr('src', imageUrl);
                $input.val(currentFilename);
                $saveBtn.attr('data-image-id', imageId);
                $optimizeBtn.attr('data-id', imageId);

                // Add keywords if they exist
                if (response.data.keywords_html) {
                    $keywordsContainer.html(baseModal.sanitizeHtml(response.data.keywords_html)).show();
                } else {
                    $keywordsContainer.hide();
                }

                // Initialize counter and show modal using .is-open class system
                updateFilenameCounter();

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
                console.error('Failed to load filename modal:', response);
                alert(i18n.error_loading_modal || 'Error loading filename modal. Please try again.');
            }
        }).fail(function() {
            console.error('AJAX request failed for filename modal');
            alert(i18n.error_loading_modal || 'Error loading filename modal. Please try again.');
        });
    }

    /* =========================================================================
       COUNTER, VALIDATION, AND SCORING
       ========================================================================= */

    /**
     * Updates filename counter, validation, and scoring display.
     *
     * Auto-fixes underscores to hyphens and uppercase to lowercase.
     * Calculates character/word counts, validates format (alphanumeric + hyphens),
     * checks for generic filenames, updates SEO score with circular progress,
     * generates metric cards for keywords/characters/words/format,
     * and updates table row badges in real-time.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function updateFilenameCounter() {
        const $input = $('#infyp-filename-editor');
        const $counter = $('.infyp-filename-counter');
        let value = $input.val();
        const i18n = window.infyp_vars.i18n;

        // Auto-slugify on each input event so the user sees the canonical form
        // immediately. Mirrors the AI-optimize transform at this file's lines
        // ~622-638, minus the leading/trailing trim (we don't trim during typing
        // — that interferes when the user is editing mid-word).
        const original = value;
        // Whitespace runs → single hyphen ("my   photo" → "my-photo")
        value = value.replace(/\s+/g, '-');
        // Underscores → hyphens
        value = value.replace(/_/g, '-');
        // Lowercase
        value = value.toLowerCase();
        // Collapse consecutive hyphens that the above transforms can create
        value = value.replace(/-+/g, '-');

        if (value !== original) {
            $input.val(value);
        }

        const charCount = [...value].length;
        const words = value.split(/[-\s]+/).filter(w => w.length > 0);
        const wordCount = words.length;
        const params = getScoringParams();
        const filenameParams = params.filename;
        const weights = getWeights();

        // Calculate keyword usage FIRST (needed for scoring)
        const $keywordBadges = $('.infyp-modal-keyword-badge');
        let keywordsUsedCount = 0;
        const minKeywordsRequired = 2;

        $keywordBadges.each(function() {
            const $badge = $(this);
            // Get only the text content, excluding the dashicon
            const keyword = $badge.clone().children().remove().end().text().trim();

            if (keyword && value) {
                // Use the same helper function as updateKeywordUsageDisplay
                const isUsed = checkKeywordUsageHelper(value, keyword, true);
                if (isUsed) {
                    keywordsUsedCount++;
                }
            }
        });

        const hasEnoughKeywords = keywordsUsedCount >= minKeywordsRequired;

        let score = 0;
        const isGeneric = /^(DSC|IMG|DCIM|image)[\d_-]+$/i.test(value);
        // Shared tolerance-aware status: green within the play room around the
        // optimal range; 'error' red is reserved for missing values.
        let status = {
            class: 'empty',
            icon: 'dismiss',
            text: i18n.missing || 'Missing',
            ok: false,
            tooltip: ''
        };

        if (isGeneric) {
            status = {
                class: 'warning',
                icon: 'warning',
                text: i18n.generic || 'Generic',
                ok: false,
                tooltip: ''
            };
        } else if (value.trim()) {
            // Calculate proportional score based on filename quality only
            // Keywords are scored separately under keyword_density weight
            // Total: 15 points split as: Characters (33%), Words (33%), Format (34%)
            const totalWeight = weights.filename_quality || 15;

            // Characters score (5 points / 33%, full credit within tolerance)
            if (scoringEngine.withinTolerance(scoringEngine.evaluateChars(charCount, filenameParams))) {
                score += totalWeight * 0.33;
            } else if (charCount > 0) {
                // Partial points if close to range
                const charPercentage = charCount < filenameParams.min_chars
                    ? charCount / filenameParams.min_chars
                    : Math.max(0, 1 - ((charCount - filenameParams.max_chars) / filenameParams.max_chars));
                score += totalWeight * 0.33 * charPercentage;
            }

            // Words score (5 points / 33%, full credit within tolerance)
            if (scoringEngine.withinTolerance(scoringEngine.evaluateWords(wordCount, filenameParams))) {
                score += totalWeight * 0.33;
            } else if (wordCount > 0) {
                // Partial points if close to range
                const wordPercentage = wordCount < filenameParams.min_words
                    ? wordCount / filenameParams.min_words
                    : Math.max(0, 1 - ((wordCount - filenameParams.max_words) / filenameParams.max_words));
                score += totalWeight * 0.33 * wordPercentage;
            }

            // Format score (5 points / 34%)
            if (/^[a-z0-9\-]+$/.test(value)) {
                score += totalWeight * 0.34; // 0.34 to total 1.0
            }

            // Determine status: format issues first, then the shared
            // tolerance-aware length verdict.
            if (!/^[a-z0-9\-]+$/.test(value)) {
                status = {
                    class: 'warning',
                    icon: 'warning',
                    text: i18n.invalid_format || 'Invalid format',
                    ok: false,
                    tooltip: ''
                };
            } else {
                status = scoringEngine.lengthStatus(value, filenameParams, 'filename');
            }
        }

        const tooltipAttr = status.tooltip ? ` title="${status.tooltip}"` : '';
        let counterHtml = `
            <div class="infyp-modal-counter-badges">
                <span class="infyp-field-feedback ${status.class}"${tooltipAttr}>
                    <span class="dashicons dashicons-${status.icon}"></span> ${status.text}
                </span>
                <span class="infyp-field-feedback char-count">${charCount} | ${filenameParams.max_chars} ${i18n.chars || 'chars'}</span>
                <span class="infyp-field-feedback word-count">${wordCount} | ${filenameParams.max_words} ${i18n.words || 'words'}</span>
            </div>
        `;
        $counter.html(counterHtml);

        const imageId = $('.infyp-save-filename').data('image-id');
        if (imageId) {
            const $row = $(`tr[data-id="${imageId}"]`);
            const $filenameInput = $row.find('.infyp-filename-trigger');
            const $badgeContainer = $filenameInput.closest('.infyp-field-wrapper').find('.infyp-badge-container');
            const tableStatusHtml = `<span class="dashicons dashicons-${status.icon}"></span> ${value.trim() ? status.text : (i18n.missing || 'Missing')}`;

            $badgeContainer.html(`
                <div class="infyp-field-feedback char-count">${charCount} | ${filenameParams.max_chars} ${i18n.chars || 'chars'}</div>
                <div class="infyp-field-feedback ${status.class}"${tooltipAttr}>${tableStatusHtml}</div>
            `);

            updateLiveSEOScore(imageId, 'filename', value);
        }

        const percentage = Math.round((score / weights.filename_quality) * 100);
        const progressClass = percentage >= 90 ? 'success' : percentage >= 60 ? 'warning' : 'error';

        // Calculate circular progress (circumference = 2 * PI * radius, where radius = 52)
        const circumference = 2 * Math.PI * 52; // = 326.73
        const dashOffset = circumference - (percentage / 100) * circumference;

        $('#infyp-filename-lightbox .infyp-score-percentage').html(`
            <div class="infyp-circle-container">
                <svg class="infyp-circle-progress infyp-circle-${progressClass}" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                    <circle class="infyp-circle-bg" cx="60" cy="60" r="52"></circle>
                    <circle class="infyp-circle-fg" cx="60" cy="60" r="52" style="stroke-dashoffset: ${dashOffset}"></circle>
                    <text class="infyp-circle-text" x="60" y="60" text-anchor="middle" dominant-baseline="central">${percentage}%</text>
                </svg>
                <h4>${i18n.filename_score || 'Filename Score'}</h4>
                <div class="infyp-score-points-breakdown">
                    <span class="infyp-points-badge infyp-points-earned infyp-badge-${progressClass}">${Math.round(score)}</span>
                    <span class="infyp-points-separator">|</span>
                    <span class="infyp-points-badge infyp-points-total infyp-badge-success">${weights.filename_quality}</span>
                </div>
            </div>
        `);

        // Helper function to calculate metric percentage and create circle
        const createMetricCircle = (current, min, max, label, valueText, isFormat = false, zone) => {
            let percentage, progressClass, statusText;

            if (isFormat) {
                // Format is binary: valid or invalid
                percentage = current ? 100 : 0;
                progressClass = current ? 'success' : 'error';
                statusText = current ? (i18n.valid_format || 'Valid') : (i18n.invalid_format || 'Invalid');
            } else {
                // Tolerance-aware: a metric inside the play room shows
                // full/success, matching the badge and the score.
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
            }

            // Circle math: circumference = 2 * PI * radius (radius = 39 for 60px circle)
            const metricCircumference = 2 * Math.PI * 39; // = 245.04
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

        // Always show the 4 metric cards, regardless of filename state
        let breakdownHtml = '<div class="infyp-score-details">';

        // KEYWORD USAGE METRIC - First position
        // Keywords already calculated above for scoring
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

        // CHARACTERS METRIC - Second position
        const charValueText = `${charCount} / ${filenameParams.max_chars}`;
        breakdownHtml += createMetricCircle(
            charCount,
            filenameParams.min_chars,
            filenameParams.max_chars,
            i18n.characters_label || 'Characters',
            charValueText,
            false,
            scoringEngine.evaluateChars(charCount, filenameParams)
        );

        // WORDS METRIC - Third position
        const wordValueText = `${wordCount} / ${filenameParams.max_words}`;
        breakdownHtml += createMetricCircle(
            wordCount,
            filenameParams.min_words,
            filenameParams.max_words,
            i18n.words_label || 'Words',
            wordValueText,
            false,
            scoringEngine.evaluateWords(wordCount, filenameParams)
        );

        // FORMAT METRIC - Fourth position
        const hasCorrectFormat = /^[a-z0-9\-]+$/.test(value);
        const formatIssues = [];
        if (!hasCorrectFormat && value.trim()) {
            if (/[A-Z]/.test(value)) formatIssues.push('uppercase');
            if (/_/.test(value)) formatIssues.push('underscores');
            if (/\s/.test(value)) formatIssues.push('spaces');
        }
        const formatValueText = hasCorrectFormat
            ? (i18n.valid_format || 'Valid')
            : (formatIssues.length > 0 ? formatIssues.join(', ') : (i18n.invalid_format || 'Invalid'));

        breakdownHtml += createMetricCircle(
            hasCorrectFormat,
            0,
            1,
            i18n.format_label || 'Format',
            formatValueText,
            true
        );

        breakdownHtml += '</div>';

        $('#infyp-filename-lightbox .infyp-score-breakdown').html(breakdownHtml);

        // Update keyword usage display in real-time
        updateKeywordUsageDisplay(value, true);
    }

    /* =========================================================================
       AI OPTIMIZATION
       ========================================================================= */

    /**
     * Handles AI optimization for filename within modal.
     *
     * Calls the single field optimization API to generate AI-optimized filename
     * based on image content and keywords. Applies format validation rules
     * (lowercase, hyphens, remove special chars) to optimized value before display.
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
        const $input = $('#infyp-filename-editor');
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
        const $contextPrompt = $('#infyp-filename-lightbox').find('.infyp-context-prompt-textarea');
        const contextPromptVal = $contextPrompt.length ? $contextPrompt.val().trim() : '';

        // Call the single field optimization API
        api.post('infyp_optimize_single_field', {
            image_id: imageId,
            field_type: 'filename',
            keywords: keywords,
            custom_prompt: contextPromptVal
        }).done(function(response) {
            if (response.success && response.data.optimized_value) {
                // Apply filename validation rules to the optimized value
                let optimizedFilename = response.data.optimized_value;

                // Strip known image extensions before sanitizing (AI may include .jpg, .png, etc.)
                optimizedFilename = optimizedFilename.replace(/\.(jpe?g|png|webp|gif|bmp|tiff?|svg|avif|heic|heif)$/i, '');

                // Replace underscores with hyphens
                optimizedFilename = optimizedFilename.replace(/_/g, '-');

                // Convert to lowercase
                optimizedFilename = optimizedFilename.toLowerCase();

                // Remove any spaces
                optimizedFilename = optimizedFilename.replace(/\s+/g, '-');

                // Remove special characters except hyphens and numbers
                optimizedFilename = optimizedFilename.replace(/[^a-z0-9-]/g, '');

                // Remove multiple consecutive hyphens
                optimizedFilename = optimizedFilename.replace(/-+/g, '-');

                // Remove leading/trailing hyphens
                optimizedFilename = optimizedFilename.replace(/^-+|-+$/g, '');

                // Update the input with the validated optimized filename
                $input.val(optimizedFilename);
                updateFilenameCounter();

                // Show success notification
                notifications.show(infyp_vars.i18n.filename_optimized || 'Filename optimized with AI', 'success');
            } else {
                notifications.show(response.data?.message || infyp_vars.i18n.error_optimizing || 'Error optimizing filename', 'error');
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
        const $modal = $('#infyp-filename-lightbox');

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
     * Binds body-level event handlers for filename modal.
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
        $body.on('input', '#infyp-filename-editor', function() {
            updateFilenameCounter();
        });

        // Save button handler
        $body.on('click', '.infyp-save-filename', function() {
            saveFilename();
        });

        // AI optimization handler
        $body.on('click', '#infyp-filename-lightbox .infyp-optimize-ai', handleOptimizeAI);

        // Close/cancel handlers
        $body.on('click', '.infyp-cancel-filename,.infyp-filename-lightbox-close', handleClose);

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
     * Saves filename to database and renames physical file.
     *
     * Sends updated filename to server, which renames the actual file on disk,
     * updates database metadata, recalculates SEO score, updates table row display,
     * and closes modal on success.
     *
     * @since 1.0.0
     * @access private
     *
     * @returns {void}
     */
    function saveFilename() {
        const $saveBtn = $('.infyp-save-filename');
        const $input = $('#infyp-filename-editor');
        const imageId = $saveBtn.data('image-id');
        const i18n = window.infyp_vars.i18n;

        // Save guard: trim leading/trailing hyphens at submit time. Doing it here
        // (rather than in updateFilenameCounter()) keeps mid-word editing natural —
        // a user typing "-prefix" mid-edit shouldn't have the leading "-" stripped
        // immediately. The slugified form is what gets persisted; the input is
        // updated so the user sees what's actually being saved.
        let newFilename = $input.val().replace(/^-+|-+$/g, '');
        if (newFilename !== $input.val()) {
            $input.val(newFilename);
        }

        $saveBtn.prop('disabled', true).text(i18n.saving || 'Saving...');

        api.post('infyp_update_filename', {
            image_id: imageId,
            field: 'filename',
            value: newFilename
        }).done(function(response) {
            if (response.success) {
                const $input = $(`.infyp-filename-trigger[data-image-id="${imageId}"]`);
                const finalFilename = response.data.new_filename || newFilename;
                $input.val(finalFilename).data('original-value', finalFilename);
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
                const $lightbox = $('#infyp-filename-lightbox');

                $overlay.removeClass('is-visible');
                $lightbox.removeClass('is-open');

                // After animation, remove from DOM
                setTimeout(function() {
                    $overlay.remove();
                    $lightbox.remove();
                }, 200);

                // Show success notification
                baseModal.showNotification(i18n.filename_updated || 'Filename updated successfully', 'success');
            } else {
                alert(response.data.message || i18n.error_saving || 'Error saving filename');
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
     * Public interface for the filename modal module.
     *
     * @since 1.0.0
     *
     * @return {Object} Public methods.
     * @property {Function} show - Show modal with image ID and current filename.
     * @property {Function} display - Display modal with all parameters.
     * @property {Function} loadFromPHP - Load modal from PHP template.
     * @property {Function} updateCounter - Update counter, validation, and scoring.
     * @property {Function} save - Save filename changes and rename physical file.
     * @property {Function} bindEvents - Bind modal event handlers.
     * @property {Function} close - Close and remove modal from DOM.
     */
    return {
        show: showFilenameEditor,
        display: displayFilenameModal,
        loadFromPHP: loadFilenameModalFromPHP,
        updateCounter: updateFilenameCounter,
        save: saveFilename,
        bindEvents: bindEvents,
        close: handleClose
    };
})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = filenameModal;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.filenameModal = filenameModal;
}

export default filenameModal;
