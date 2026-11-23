/**
 * SEO Score Modal Module - SEO breakdown display and analysis
 *
 * This module provides a detailed modal dialog displaying comprehensive SEO score
 * breakdowns for images. It shows overall scores, field-specific scores (alt text,
 * title, filename, keywords, uniqueness), and detailed metric circles for each field.
 *
 * ## Features
 *
 * - Overall SEO score with circular progress indicator
 * - Field-specific score breakdowns (alt text, title, filename)
 * - Keyword density tracking across all fields
 * - Uniqueness score display
 * - Detailed metric cards for each field
 * - Points earned vs total points visualization
 * - Perfect score celebration display
 * - Missing points summary with quick fix suggestions
 * - Optimize with AI button for incomplete scores
 * - PHP template loading with JS fallback
 *
 * ## AJAX Actions
 *
 * | Action                            | Purpose                                  |
 * |-----------------------------------|------------------------------------------|
 * | infyp_get_seo_score_breakdown   | Fetch detailed score breakdown data      |
 * | infyp_load_seo_breakdown_modal  | Load modal HTML from PHP template        |
 *
 * @file SEO score modal module for detailed score analysis.
 * @author AI Image SEO
 * @since 1.0.0
 * @module modals/seo-score-modal
 * @requires jQuery
 * @requires api
 * @requires config
 * @requires base-modal
 */
import api from '../api.js';
import config from '../core/config.js';
import scoringEngine from '../core/scoring-engine.js';
import baseModal from './base-modal.js';

const seoScoreModal = (function($) {
    'use strict';

    /* =========================================================================
       MODULE DEPENDENCIES
       ========================================================================= */

    // Get config functions
    const getAltParams = config.getAltParams;
    const getTitleParams = config.getTitleParams;
    const getFilenameParams = config.getFilenameParams;

    /* =========================================================================
       MODAL INITIALIZATION
       ========================================================================= */

    /**
     * Shows SEO score modal with detailed breakdown.
     *
     * Fetches complete score breakdown data from server, then displays modal
     * with field-specific scores, metric cards, and optimization suggestions.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {number} imageId - The WordPress attachment ID.
     * @param {number} currentScore - Current SEO score percentage for reference.
     * @returns {void}
     */
    function showSEOScoreModal(imageId, currentScore) {
        // First get the breakdown data
        api.post('infyp_get_seo_score_breakdown', {
            image_id: imageId
        }).done(function(response) {
            if (response.success && response.data) {
                const actualScore = response.data.score_data.percentage;
                const breakdown = response.data.breakdown;

                // Use PHP/AJAX approach to load modal
                loadSEOBreakdownModalFromPHP(imageId, actualScore, breakdown, response.data);
            }
        }).fail(function() {
            alert(window.infyp_vars.i18n.error_loading_score || 'Error loading SEO score breakdown');
        });
    }

    /* =========================================================================
       PHP TEMPLATE LOADING
       ========================================================================= */

    /**
     * Loads SEO breakdown modal from PHP template via AJAX.
     *
     * Requests modal HTML from server, then populates with breakdown data
     * including overall score, field scores, and metric cards.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {number} imageId - The WordPress attachment ID.
     * @param {number} actualScore - Actual SEO score percentage.
     * @param {Array} breakdown - Array of score breakdown objects.
     * @param {Object} serverData - Server data including params and score data.
     * @returns {void}
     */
    function loadSEOBreakdownModalFromPHP(imageId, actualScore, breakdown, serverData) {
        // Load modal HTML from PHP template
        $.post(window.infyp_vars.ajax_url, {
            action: 'infyp_load_seo_breakdown_modal',
            nonce: window.infyp_vars.nonce,
            image_id: imageId
        }).done(function(response) {
            if (response.success && response.data) {
                // Append modal HTML to body
                $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                // Populate the modal with breakdown data
                populateSEOBreakdownModal(breakdown, actualScore, imageId, serverData);

                // Lock body scroll and store scroll position for restoration
                const scrollY = window.scrollY;
                $('body')
                    .addClass('infyp-lightbox-open')
                    .css('top', `-${scrollY}px`)
                    .data('scroll-position', scrollY);

                // Add is-open class to modal (triggers CSS display)
                const $modal = $('#infyp-score-modal');
                $modal.css('display', 'block');
                // Trigger reflow for CSS transition
                $modal[0].offsetHeight;
                $modal.addClass('is-open');

                // Bind SEO breakdown modal events
                bindSEOBreakdownModalEvents(imageId);
            } else {
                // Fallback to inline HTML if AJAX fails
                displaySEOBreakdownModalFallback(imageId, actualScore, breakdown, serverData);
            }
        }).fail(function() {
            // Fallback on AJAX error
            displaySEOBreakdownModalFallback(imageId, actualScore, breakdown, serverData);
        });
    }

    /* =========================================================================
       MODAL POPULATION
       ========================================================================= */

    /**
     * Populates SEO breakdown modal with score data.
     *
     * Groups breakdown items by field, calculates totals, updates header badges,
     * populates overall score header with breakdown cards, and populates each
     * field section with detailed metrics.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {Array} breakdown - Array of score breakdown objects.
     * @param {number} actualScore - Actual SEO score percentage.
     * @param {number} imageId - The WordPress attachment ID.
     * @param {Object} serverData - Server data including params.
     * @returns {void}
     */
    function populateSEOBreakdownModal(breakdown, actualScore, imageId, serverData) {
        let totalPossible = 0;
        let totalEarned = 0;
        const params = serverData && serverData.params ? serverData.params : {};

        // Group breakdown items by field
        const fieldGroups = {
            alt_text: [],
            filename: [],
            title: [],
            keyword_density: [],
            uniqueness: []
        };

        // Categorize breakdown items
        breakdown.forEach(item => {
            totalPossible += item.weight;
            totalEarned += item.score;

            // Determine which field this item belongs to
            if (item.field === 'alt_presence' || item.field === 'alt_quality' || item.field === 'alt_text') {
                fieldGroups.alt_text.push(item);
            } else if (item.field === 'filename' || item.field === 'filename_quality') {
                fieldGroups.filename.push(item);
            } else if (item.field === 'title_presence' || item.field === 'title_quality' || item.field === 'title_text' || item.field === 'title') {
                fieldGroups.title.push(item);
            } else if (item.field === 'keyword_density' || item.field === 'keywords') {
                fieldGroups.keyword_density.push(item);
            } else if (item.field === 'uniqueness') {
                fieldGroups.uniqueness.push(item);
            }
        });

        // Calculate actual percentage
        const actualPercentage = totalPossible > 0 ? Math.round((totalEarned / totalPossible) * 100) : 0;

        // Update header badges
        $('.infyp-modal-header-badges .infyp-points-earned').text(totalEarned)
            .removeClass('infyp-badge-success infyp-badge-warning infyp-badge-error')
            .addClass(getScoreBadgeClass(actualPercentage));

        // Populate overall score header with breakdown cards
        populateOverallScoreHeader(actualPercentage, totalEarned, totalPossible, fieldGroups);

        // Populate each field section
        populateFieldSection('alt_text', fieldGroups.alt_text, serverData);
        populateFieldSection('filename', fieldGroups.filename, serverData);
        populateFieldSection('title', fieldGroups.title, serverData);
        populateFieldSection('keyword_density', fieldGroups.keyword_density, serverData);
        populateFieldSection('uniqueness', fieldGroups.uniqueness, serverData);

        // Show optimize button if needed
        if (actualPercentage < 100) {
            $('.infyp-optimize-from-modal').show();
        }
    }

    /**
     * Populates overall score header section.
     *
     * Creates main score circle, calculates field summaries, and generates
     * metric cards for alt text, filename, and title fields.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {number} percentage - Overall score percentage.
     * @param {number} earned - Points earned.
     * @param {number} total - Total possible points.
     * @param {Object} fieldGroups - Grouped breakdown items by field.
     * @returns {void}
     */
    function populateOverallScoreHeader(percentage, earned, total, fieldGroups) {
        const progressClass = getScoreBadgeClass(percentage).replace('infyp-badge-', '');
        const circumference = 2 * Math.PI * 52;
        const dashOffset = circumference - (percentage / 100) * circumference;
        const i18n = window.infyp_vars.i18n;

        const headerHtml = `
            <div class="infyp-circle-container">
                <svg class="infyp-circle-progress infyp-circle-${progressClass}" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                    <circle class="infyp-circle-bg" cx="60" cy="60" r="52"></circle>
                    <circle class="infyp-circle-fg" cx="60" cy="60" r="52" style="stroke-dashoffset: ${dashOffset}"></circle>
                    <text class="infyp-circle-text" x="60" y="60" text-anchor="middle" dominant-baseline="central">${percentage}%</text>
                </svg>
                <h4>${i18n.overall_seo_score || 'Overall SEO Score'}</h4>
                <div class="infyp-score-points-breakdown">
                    <span class="infyp-points-badge infyp-points-earned infyp-badge-${progressClass}">${Math.round(earned)}</span>
                    <span class="infyp-points-separator">|</span>
                    <span class="infyp-points-badge infyp-points-total infyp-badge-success">${total}</span>
                </div>
            </div>
        `;

        $('.infyp-score-field-section[data-field="overall"] .infyp-score-percentage').html(headerHtml);

        // Generate breakdown cards for the 3 main fields
        let breakdownHtml = '<div class="infyp-score-details">';

        // Helper function to create field summary card
        const createFieldSummaryCard = (fieldName, items) => {
            let fieldEarned = 0;
            let fieldTotal = 0;
            items.forEach(item => {
                fieldEarned += item.score;
                fieldTotal += item.weight;
            });

            const fieldPercentage = fieldTotal > 0 ? Math.round((fieldEarned / fieldTotal) * 100) : 0;
            const fieldProgressClass = getScoreBadgeClass(fieldPercentage).replace('infyp-badge-', '');
            const fieldCircumference = 2 * Math.PI * 39;
            const fieldDashOffset = fieldCircumference - (fieldPercentage / 100) * fieldCircumference;

            const fieldLabels = {
                alt_text: i18n.alt_text_label || 'Alt Text',
                filename: i18n.filename_label || 'Filename',
                title: i18n.title_label || 'Title'
            };

            return `
                <div class="infyp-metric-card">
                    <svg class="infyp-metric-circle infyp-circle-${fieldProgressClass}" viewBox="0 0 90 90" xmlns="http://www.w3.org/2000/svg">
                        <circle class="infyp-metric-circle-bg" cx="45" cy="45" r="39"></circle>
                        <circle class="infyp-metric-circle-fg" cx="45" cy="45" r="39" style="stroke-dashoffset: ${fieldDashOffset}"></circle>
                        <text class="infyp-metric-circle-text" x="45" y="45" text-anchor="middle" dominant-baseline="central">${fieldPercentage}%</text>
                    </svg>
                    <div class="infyp-metric-label">${fieldLabels[fieldName]}</div>
                    <div class="infyp-metric-value">${fieldEarned} / ${fieldTotal}</div>
                </div>
            `;
        };

        // Add cards for Alt Text, Filename, Title
        if (fieldGroups.alt_text && fieldGroups.alt_text.length > 0) {
            breakdownHtml += createFieldSummaryCard('alt_text', fieldGroups.alt_text);
        }
        if (fieldGroups.filename && fieldGroups.filename.length > 0) {
            breakdownHtml += createFieldSummaryCard('filename', fieldGroups.filename);
        }
        if (fieldGroups.title && fieldGroups.title.length > 0) {
            breakdownHtml += createFieldSummaryCard('title', fieldGroups.title);
        }

        breakdownHtml += '</div>';

        $('.infyp-score-field-section[data-field="overall"] .infyp-score-breakdown').html(breakdownHtml);
    }

    /**
     * Populates individual field section.
     *
     * Calculates field totals, generates field header with score circle,
     * and creates detailed breakdown metrics if applicable.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {string} fieldName - Field name (alt_text, filename, title, keyword_density, uniqueness).
     * @param {Array} items - Array of breakdown items for this field.
     * @param {Object} serverData - Server data including params.
     * @returns {void}
     */
    function populateFieldSection(fieldName, items, serverData) {
        if (items.length === 0) return;

        const $section = $(`.infyp-score-field-section[data-field="${fieldName}"]`);
        if ($section.length === 0) return;

        // Calculate field totals
        let fieldEarned = 0;
        let fieldTotal = 0;
        items.forEach(item => {
            fieldEarned += item.score;
            fieldTotal += item.weight;
        });

        const fieldPercentage = fieldTotal > 0 ? Math.round((fieldEarned / fieldTotal) * 100) : 0;
        const progressClass = getScoreBadgeClass(fieldPercentage).replace('infyp-badge-', '');

        // Populate field header with overall field score
        const circumference = 2 * Math.PI * 52;
        const dashOffset = circumference - (fieldPercentage / 100) * circumference;

        const fieldHeaderHtml = `
            <div class="infyp-circle-container">
                <svg class="infyp-circle-progress infyp-circle-${progressClass}" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                    <circle class="infyp-circle-bg" cx="60" cy="60" r="52"></circle>
                    <circle class="infyp-circle-fg" cx="60" cy="60" r="52" style="stroke-dashoffset: ${dashOffset}"></circle>
                    <text class="infyp-circle-text" x="60" y="60" text-anchor="middle" dominant-baseline="central">${fieldPercentage}%</text>
                </svg>
                <h4>${getFieldTitle(fieldName)}</h4>
                <div class="infyp-score-points-breakdown">
                    <span class="infyp-points-badge infyp-points-earned infyp-badge-${progressClass}">${Math.round(fieldEarned)}</span>
                    <span class="infyp-points-separator">|</span>
                    <span class="infyp-points-badge infyp-points-total infyp-badge-success">${fieldTotal}</span>
                </div>
            </div>
        `;

        $section.find('.infyp-score-percentage').html(fieldHeaderHtml);

        // Populate detailed breakdown if needed
        if (items.length > 1 || needsDetailedBreakdown(fieldName)) {
            const detailsHtml = generateFieldDetails(fieldName, items, serverData);
            $section.find('.infyp-score-breakdown').html(detailsHtml);
        }
    }

    /* =========================================================================
       HELPER FUNCTIONS
       ========================================================================= */

    /**
     * Gets field title from field name.
     *
     * Maps internal field names to user-friendly display titles with i18n support.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {string} fieldName - Field name (alt_text, filename, title, etc.).
     * @returns {string} User-friendly field title.
     */
    function getFieldTitle(fieldName) {
        const i18n = window.infyp_vars.i18n;
        const titles = {
            alt_text: i18n.alt_text_score || 'Alt Text Score',
            filename: i18n.filename_score || 'Filename Score',
            title: i18n.title_score || 'Title Score',
            keyword_density: i18n.keyword_score || 'Keyword Usage Score',
            uniqueness: i18n.uniqueness_score || 'Uniqueness Score'
        };
        return titles[fieldName] || fieldName;
    }

    /**
     * Checks if field needs detailed breakdown display.
     *
     * Determines whether a field should show detailed metric cards.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {string} fieldName - Field name.
     * @returns {boolean} True if field needs detailed breakdown.
     */
    function needsDetailedBreakdown(fieldName) {
        return fieldName === 'alt_text' || fieldName === 'filename' || fieldName === 'title' || fieldName === 'keyword_density';
    }

    /**
     * Generates field details HTML with metric circles.
     *
     * Creates metric circle visualizations for characters, words, keywords,
     * and format validation based on field type.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {string} fieldName - Field name.
     * @param {Array} items - Array of breakdown items.
     * @param {Object} serverData - Server data including params.
     * @returns {string} HTML for field details section.
     */
    function generateFieldDetails(fieldName, items, serverData) {
        const params = serverData && serverData.params ? serverData.params : {};
        const i18n = window.infyp_vars.i18n;

        // Find item with details
        let detailItem = null;
        for (let i = 0; i < items.length; i++) {
            if (items[i].details) {
                detailItem = items[i];
                break;
            }
        }

        // For keyword_density, always show details even if no detailItem
        // For other fields, return empty if no details
        if ((!detailItem || !detailItem.details) && fieldName !== 'keyword_density') {
            return '';
        }

        let detailsHtml = '<div class="infyp-score-details">';

        // Helper function to create metric circles
        const createMetricCircle = (current, min, max, label, valueText, zone) => {
            let percentage, progressClass;

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

            const circumference = 2 * Math.PI * 39;
            const dashOffset = circumference - (percentage / 100) * circumference;

            return `
                <div class="infyp-metric-card">
                    <svg class="infyp-metric-circle infyp-circle-${progressClass}" viewBox="0 0 90 90" xmlns="http://www.w3.org/2000/svg">
                        <circle class="infyp-metric-circle-bg" cx="45" cy="45" r="39"></circle>
                        <circle class="infyp-metric-circle-fg" cx="45" cy="45" r="39" style="stroke-dashoffset: ${dashOffset}"></circle>
                        <text class="infyp-metric-circle-text" x="45" y="45" text-anchor="middle" dominant-baseline="central">${percentage}%</text>
                    </svg>
                    <div class="infyp-metric-label">${label}</div>
                    <div class="infyp-metric-value">${valueText}</div>
                </div>
            `;
        };

        // Helper for binary metrics (keywords)
        const createBinaryMetricCircle = (hasValue, label, valueText) => {
            const percentage = hasValue ? 100 : 0;
            const progressClass = hasValue ? 'success' : 'error';
            const circumference = 2 * Math.PI * 39;
            const dashOffset = circumference - (percentage / 100) * circumference;

            return `
                <div class="infyp-metric-card">
                    <svg class="infyp-metric-circle infyp-circle-${progressClass}" viewBox="0 0 90 90" xmlns="http://www.w3.org/2000/svg">
                        <circle class="infyp-metric-circle-bg" cx="45" cy="45" r="39"></circle>
                        <circle class="infyp-metric-circle-fg" cx="45" cy="45" r="39" style="stroke-dashoffset: ${dashOffset}"></circle>
                        <text class="infyp-metric-circle-text" x="45" y="45" text-anchor="middle" dominant-baseline="central">${percentage}%</text>
                    </svg>
                    <div class="infyp-metric-label">${label}</div>
                    <div class="infyp-metric-value">${valueText}</div>
                </div>
            `;
        };

        // Add metric cards based on field type
        if (fieldName === 'filename') {
            const fieldParams = params.filename || { min_chars: 40, max_chars: 60, min_words: 3, max_words: 6 };

            if (detailItem.details.char_count !== undefined) {
                const charText = `${detailItem.details.char_count} / ${fieldParams.max_chars} ${i18n.chars || 'chars'}`;
                detailsHtml += createMetricCircle(detailItem.details.char_count, fieldParams.min_chars, fieldParams.max_chars, i18n.characters_label || 'Characters', charText, scoringEngine.evaluateChars(detailItem.details.char_count, fieldParams));
            }

            if (detailItem.details.word_count !== undefined) {
                const wordText = `${detailItem.details.word_count} / ${fieldParams.max_words} ${i18n.words || 'words'}`;
                detailsHtml += createMetricCircle(detailItem.details.word_count, fieldParams.min_words, fieldParams.max_words, i18n.words_label || 'Words', wordText, scoringEngine.evaluateWords(detailItem.details.word_count, fieldParams));
            }

            if (detailItem.details.has_keywords !== undefined) {
                const keywordText = detailItem.details.has_keywords ?
                    (i18n.keywords_found || 'Keywords Found') :
                    (i18n.no_keywords || 'No Keywords');
                detailsHtml += createBinaryMetricCircle(detailItem.details.has_keywords, i18n.keywords_label || 'Keywords', keywordText);
            }
        } else if (fieldName === 'alt_text' || fieldName === 'title') {
            const fieldParams = params[fieldName] || { min_chars: 50, max_chars: 125, min_words: 10, max_words: 16 };

            if (detailItem.details.char_count !== undefined) {
                const charText = `${detailItem.details.char_count} / ${fieldParams.max_chars} ${i18n.chars || 'chars'}`;
                detailsHtml += createMetricCircle(detailItem.details.char_count, fieldParams.min_chars, fieldParams.max_chars, i18n.characters_label || 'Characters', charText, scoringEngine.evaluateChars(detailItem.details.char_count, fieldParams));
            }

            if (detailItem.details.word_count !== undefined) {
                const wordText = `${detailItem.details.word_count} / ${fieldParams.max_words} ${i18n.words || 'words'}`;
                detailsHtml += createMetricCircle(detailItem.details.word_count, fieldParams.min_words, fieldParams.max_words, i18n.words_label || 'Words', wordText, scoringEngine.evaluateWords(detailItem.details.word_count, fieldParams));
            }

            if (detailItem.details.has_keywords !== undefined) {
                const keywordText = detailItem.details.has_keywords ?
                    (i18n.keywords_found || 'Keywords Found') :
                    (i18n.no_keywords || 'No Keywords');
                detailsHtml += createBinaryMetricCircle(detailItem.details.has_keywords, i18n.keywords_label || 'Keywords', keywordText);
            }
        } else if (fieldName === 'keyword_density') {
            // For keyword density, show placement info
            const fieldsWithKeywords = detailItem.details && detailItem.details.fields_with_keywords ? detailItem.details.fields_with_keywords : 0;
            const placement = detailItem.details && detailItem.details.placement ? detailItem.details.placement : { alt: [], title: [], filename: [] };
            const totalFields = 3; // alt, title, filename

            // Create a metric for overall keyword usage
            const usagePercentage = Math.round((fieldsWithKeywords / totalFields) * 100);
            const progressClass = fieldsWithKeywords === 3 ? 'success' : (fieldsWithKeywords > 0 ? 'warning' : 'error');
            const circumference = 2 * Math.PI * 39;
            const dashOffset = circumference - (usagePercentage / 100) * circumference;

            detailsHtml += `
                <div class="infyp-metric-card">
                    <svg class="infyp-metric-circle infyp-circle-${progressClass}" viewBox="0 0 90 90" xmlns="http://www.w3.org/2000/svg">
                        <circle class="infyp-metric-circle-bg" cx="45" cy="45" r="39"></circle>
                        <circle class="infyp-metric-circle-fg" cx="45" cy="45" r="39" style="stroke-dashoffset: ${dashOffset}"></circle>
                        <text class="infyp-metric-circle-text" x="45" y="45" text-anchor="middle" dominant-baseline="central">${usagePercentage}%</text>
                    </svg>
                    <div class="infyp-metric-label">${i18n.fields_with_keywords || 'Fields with Keywords'}</div>
                    <div class="infyp-metric-value">${fieldsWithKeywords} / ${totalFields}</div>
                </div>
            `;

            // Individual field keyword presence
            detailsHtml += createBinaryMetricCircle(placement.alt && placement.alt.length > 0,
                i18n.alt_text_label || 'Alt Text',
                placement.alt && placement.alt.length > 0 ? (i18n.has_keywords || 'Has Keywords') : (i18n.no_keywords || 'No Keywords'));

            detailsHtml += createBinaryMetricCircle(placement.title && placement.title.length > 0,
                i18n.title_label || 'Title',
                placement.title && placement.title.length > 0 ? (i18n.has_keywords || 'Has Keywords') : (i18n.no_keywords || 'No Keywords'));

            detailsHtml += createBinaryMetricCircle(placement.filename && placement.filename.length > 0,
                i18n.filename_label || 'Filename',
                placement.filename && placement.filename.length > 0 ? (i18n.has_keywords || 'Has Keywords') : (i18n.no_keywords || 'No Keywords'));
        }

        detailsHtml += '</div>';
        return detailsHtml;
    }

    /**
     * Gets score badge class from percentage.
     *
     * Maps score percentage to CSS class for color coding (success/warning/error).
     *
     * @since 1.0.0
     * @access private
     *
     * @param {number} percentage - Score percentage (0-100).
     * @returns {string} CSS badge class.
     */
    function getScoreBadgeClass(percentage) {
        if (percentage >= 80) {
            return 'infyp-badge-success';
        } else if (percentage >= 60) {
            return 'infyp-badge-warning';
        } else {
            return 'infyp-badge-error';
        }
    }

    /**
     * Creates score circle SVG.
     *
     * Generates SVG circular progress indicator for score visualization.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {number} percentage - Score percentage (0-100).
     * @param {number} [strokeWidth=3] - SVG stroke width.
     * @returns {string} SVG HTML string.
     */
    function createScoreCircle(percentage, strokeWidth = 3) {
        const radius = 35;
        const circumference = 2 * Math.PI * radius;
        const offset = circumference - (percentage / 100) * circumference;
        let statusClass = '';

        if (percentage >= 80) {
            statusClass = 'optimized';
        } else if (percentage >= 60) {
            statusClass = 'good';
        } else if (percentage >= 40) {
            statusClass = 'fair';
        } else {
            statusClass = 'poor';
        }

        return `
            <div class="infyp-score-circle">
                <svg width="80" height="80" viewBox="0 0 80 80">
                    <circle cx="40" cy="40" r="${radius}" stroke="var(--infyp-border)" stroke-width="${strokeWidth}" fill="none"/>
                    <circle cx="40" cy="40" r="${radius}" stroke-dasharray="${circumference}" stroke-dashoffset="${offset}" stroke-width="${strokeWidth}" fill="none" class="infyp-score-progress ${statusClass}" transform="rotate(-90 40 40)"/>
                </svg>
                <div class="infyp-score-text">
                    <span class="infyp-score-number">${percentage}%</span>
                </div>
            </div>
        `;
    }

    /* =========================================================================
       FALLBACK RENDERING
       ========================================================================= */

    /**
     * Creates SEO score modal HTML fallback.
     *
     * Generates complete modal HTML with JS when PHP template loading fails.
     * Includes score items, summary table, and optimization suggestions.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {Array} breakdown - Score breakdown array.
     * @param {number} currentScore - Current score percentage.
     * @param {number} imageId - The WordPress attachment ID.
     * @param {Object} serverData - Server data including params.
     * @returns {string} Complete modal HTML string.
     */
    function createSEOScoreModal(breakdown, currentScore, imageId, serverData) {
        let itemsHtml = '';
        let totalPossible = 0;
        let totalEarned = 0;
        let missingItems = [];
        const params = serverData && serverData.params ? serverData.params : {};
        const i18n = window.infyp_vars.i18n;

        breakdown.forEach(item => {
            totalPossible += item.weight;
            totalEarned += item.score;
            if (item.status === 'missing' || item.status === 'partial') {
                missingItems.push({
                    title: item.title,
                    points: item.weight - item.score,
                    action: item.message
                });
            }

            // Determine badge classes based on status
            let earnedBadgeClass = '';
            if (item.status === 'complete') {
                earnedBadgeClass = 'infyp-badge-success';
            } else if (item.status === 'partial') {
                earnedBadgeClass = 'infyp-badge-warning';
            } else {
                earnedBadgeClass = 'infyp-badge-error';
            }

            let formattedMessage = item.message;
            if (!item.message.includes('<div') && !item.message.includes('<span')) {
                formattedMessage = `<div class="infyp-score-item-simple-message">${item.message}</div>`;
            }

            itemsHtml += `
                <div class="infyp-score-item ${item.status}">
                    <div class="infyp-score-item-header">
                        <span class="infyp-score-item-title">${item.title}</span>
                        <div class="infyp-score-item-badges">
                            <span class="infyp-points-badge infyp-points-badge-small infyp-points-earned ${earnedBadgeClass}">${item.score}</span>
                            <span class="infyp-points-separator">|</span>
                            <span class="infyp-points-badge infyp-points-badge-small infyp-points-total">${item.weight}</span>
                        </div>
                    </div>
                    <div class="infyp-score-item-status">${formattedMessage}</div>
                </div>
            `;
        });

        const actualPercentage = totalPossible > 0 ? Math.round((totalEarned / totalPossible) * 100) : 0;
        let summaryHtml = '';

        if (actualPercentage === 100) {
            summaryHtml = `
                <div class="infyp-score-summary infyp-card infyp-perfect-score">
                    <div class="infyp-score-summary-circle-container">
                        ${createScoreCircle(100, 5)}
                    </div>
                    <div class="infyp-score-summary-content">
                        <h3>${i18n.perfect_score || 'Perfect SEO Score!'}</h3>
                        <p>${i18n.no_adjustments_needed || 'No adjustments needed. Your image is fully optimized for SEO.'}</p>
                    </div>
                </div>
            `;
        } else if (missingItems.length > 0) {
            // Calculate total points needed
            let totalPointsNeeded = 0;
            missingItems.forEach(item => {
                totalPointsNeeded += item.points;
            });

            summaryHtml = `
                <div class="infyp-score-summary infyp-card">
                    <div class="infyp-score-summary-circle-container">
                        ${createScoreCircle(actualPercentage, 5)}
                    </div>
                    <div class="infyp-score-summary-content">
                        <h3>${i18n.to_reach_100 || 'To reach 100% SEO score:'}</h3>
                        <table class="infyp-score-summary-table">
                            <thead>
                                <tr>
                                    <th>${i18n.criterion || 'Criterion'}</th>
                                    <th>${i18n.points_needed || 'Points needed'}</th>
                                </tr>
                            </thead>
                            <tbody>`;

            missingItems.forEach(item => {
                summaryHtml += `
                                <tr>
                                    <td>${item.title}</td>
                                    <td class="infyp-points-needed">+${item.points}</td>
                                </tr>`;
            });

            summaryHtml += `
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td><strong>${i18n.total || 'Total'}</strong></td>
                                    <td class="infyp-points-needed"><strong>+${totalPointsNeeded}</strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="infyp-score-quick-fix">
                        <span class="dashicons dashicons-lightbulb infyp-quick-fix-icon"></span>
                        <div class="infyp-quick-fix-content">
                            <strong>${i18n.quick_fix || 'Quick fix:'}</strong>
                            ${i18n.optimize_with_ai_help || 'Click "Optimize with AI" to automatically improve all missing elements'}
                        </div>
                    </div>
                </div>
            `;
        }

        const modalHtml = `
            <div id="infyp-score-modal" class="infyp-score-modal">
                <div class="infyp-score-modal-content">
                    <div class="infyp-score-modal-header">
                        <h2>${i18n.seo_score_breakdown || 'SEO Score Breakdown'}</h2>
                        <div class="infyp-modal-header-badges">
                            <span class="infyp-points-badge infyp-points-earned ${getScoreBadgeClass(actualPercentage)}">${totalEarned}</span>
                            <span class="infyp-points-separator">|</span>
                            <span class="infyp-points-badge infyp-points-total infyp-badge-success">${totalPossible}</span>
                        </div>
                        <span class="infyp-score-modal-close">&times;</span>
                    </div>
                    <div class="infyp-score-modal-body">
                        ${summaryHtml}
                        <div class="infyp-score-breakdown ${summaryHtml ? 'infyp-score-breakdown-with-summary' : 'infyp-score-breakdown-without-summary'}">
                            ${itemsHtml}
                        </div>
                        <div class="infyp-score-total">
                            <div class="infyp-score-total-circle">
                                ${createScoreCircle(actualPercentage, 5)}
                            </div>
                            <div class="infyp-score-total-details">
                                <div class="infyp-score-total-label">${i18n.current_seo_score || 'Current SEO Score'}</div>
                                <div class="infyp-score-points-breakdown">
                                    <span class="infyp-points-badge infyp-points-earned ${getScoreBadgeClass(actualPercentage)}">${totalEarned}</span>
                                    <span class="infyp-points-separator">|</span>
                                    <span class="infyp-points-badge infyp-points-total infyp-badge-success">${totalPossible}</span>
                                </div>
                            </div>
                            ${actualPercentage < 100 ? `
                                <button class="button button-primary infyp-optimize-from-modal">
                                    <span class="infyp-optimize-icon"></span>
                                    ${i18n.optimize_now || 'Optimize with AI'}
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;

        return modalHtml;
    }

    /**
     * Displays SEO breakdown modal using fallback HTML.
     *
     * Appends fallback modal to body and shows with fade animation.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {number} imageId - The WordPress attachment ID.
     * @param {number} actualScore - Actual score percentage.
     * @param {Array} breakdown - Score breakdown array.
     * @param {Object} serverData - Server data including params.
     * @returns {void}
     */
    function displaySEOBreakdownModalFallback(imageId, actualScore, breakdown, serverData) {
        const modal = createSEOScoreModal(breakdown, actualScore, imageId, serverData);
        $('body').append(modal);

        // Lock body scroll and store scroll position for restoration
        const scrollY = window.scrollY;
        $('body')
            .addClass('infyp-lightbox-open')
            .css('top', `-${scrollY}px`)
            .data('scroll-position', scrollY);

        // Show modal using .is-open class system
        const $modal = $('#infyp-score-modal');
        $modal.css('display', 'block');
        // Trigger reflow for CSS transition
        $modal[0].offsetHeight;
        $modal.addClass('is-open');

        bindSEOBreakdownModalEvents(imageId);
    }

    /* =========================================================================
       EVENT BINDING
       ========================================================================= */

    /**
     * Binds event handlers for SEO breakdown modal interactions.
     *
     * Sets up handlers for:
     * - Close button and overlay clicks
     * - Optimize with AI button
     *
     * @since 1.0.0
     * @access public
     *
     * @param {number} imageId - The WordPress attachment ID for optimization requests.
     * @returns {void}
     */
    function bindSEOBreakdownModalEvents(imageId) {
        // Close modal events using .is-open class system
        $('.infyp-score-modal-close, #infyp-score-modal').off('click').on('click', function(e) {
            if (e.target === this) {
                const $modal = $('#infyp-score-modal');

                // Restore scroll position and unlock body scroll
                const scrollY = $('body').data('scroll-position') || 0;
                $('body')
                    .removeClass('infyp-lightbox-open')
                    .css('top', '');
                window.scrollTo(0, scrollY);

                // Remove visibility class for CSS transition
                $modal.removeClass('is-open');

                // After animation, remove from DOM
                setTimeout(function() {
                    $modal.remove();
                }, 200);
            }
        });

        // Optimize button
        $('.infyp-optimize-from-modal').off('click').on('click', function(e) {
            e.preventDefault();

            const $modal = $('#infyp-score-modal');

            // Restore scroll position and unlock body scroll
            const scrollY = $('body').data('scroll-position') || 0;
            $('body')
                .removeClass('infyp-lightbox-open')
                .css('top', '');
            window.scrollTo(0, scrollY);

            // Close modal using .is-open class system
            $modal.removeClass('is-open');

            // After animation, remove from DOM and trigger optimize
            setTimeout(function() {
                $modal.remove();

                const $optimizeBtn = $(`.infyp-optimize-ai[data-id="${imageId}"]`);
                if ($optimizeBtn.length) {
                    $optimizeBtn.click();
                }
            }, 200);
        });
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the SEO score modal module.
     *
     * @since 1.0.0
     *
     * @return {Object} Public methods.
     * @property {Function} show - Show SEO score modal with breakdown.
     * @property {Function} loadFromPHP - Load modal from PHP template.
     * @property {Function} populate - Populate modal with score data.
     * @property {Function} createFallback - Create fallback modal HTML.
     * @property {Function} displayFallback - Display modal using fallback HTML.
     * @property {Function} bindEvents - Bind modal event handlers.
     */
    return {
        show: showSEOScoreModal,
        loadFromPHP: loadSEOBreakdownModalFromPHP,
        populate: populateSEOBreakdownModal,
        createFallback: createSEOScoreModal,
        displayFallback: displaySEOBreakdownModalFallback,
        bindEvents: bindSEOBreakdownModalEvents
    };
})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = seoScoreModal;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.seoScoreModal = seoScoreModal;
}

export default seoScoreModal;
