/**
 * Scoring Engine Module - SEO Score Calculations
 *
 * This module handles all SEO score calculations for images, including:
 * - Individual field scoring (alt text, title, filename)
 * - Total score aggregation
 * - Keyword density scoring
 * - Live score updates in the UI
 *
 * @module core/scoring-engine
 */

import config from './config.js';

/**
 * Export as ES6 module with WordPress compatibility fallback
 */
const scoringEngine = (function($) {
    'use strict';

    /* =========================================================================
       TOLERANCE ZONES (single source of truth for length checks)

       The configured [min, max] range is the optimal target; a tolerance band
       on each side is still "acceptable" (full credit, green badge, no
       nagging). Only beyond the band does a metric count as too short / too
       long. Mirrors INFYP_SEO_Score_Calculator::evaluate_metric() in PHP.
       ========================================================================= */

    /**
     * Resolve which tolerance zone a metric count falls into.
     *
     * @param {number} count - Measured value (characters or words)
     * @param {number} min - Lower bound of the optimal range
     * @param {number} max - Upper bound of the optimal range
     * @param {number} tolLow - Play room below min
     * @param {number} tolHigh - Play room above max
     * @returns {string} 'optimal' | 'acceptable' | 'too_short' | 'too_long'
     */
    function evaluateMetric(count, min, max, tolLow, tolHigh) {
        if (count < min - tolLow) return 'too_short';
        if (count > max + tolHigh) return 'too_long';
        if (count < min || count > max) return 'acceptable';
        return 'optimal';
    }

    /**
     * Evaluate a character count against a field's params, tolerance-aware.
     * The tolerance is tolerance_chars_pct percent of each limit.
     *
     * @param {number} count - Character count
     * @param {Object} params - Field params (min_chars, max_chars, tolerance_chars_pct)
     * @returns {string} Zone name (see evaluateMetric)
     */
    function evaluateChars(count, params) {
        const raw = parseFloat(params.tolerance_chars_pct);
        const pct = isNaN(raw) ? 15 : Math.max(0, raw);
        return evaluateMetric(
            count,
            params.min_chars,
            params.max_chars,
            Math.round(params.min_chars * pct / 100),
            Math.round(params.max_chars * pct / 100)
        );
    }

    /**
     * Evaluate a word count against a field's params, tolerance-aware.
     * The tolerance is tolerance_words absolute words on each side.
     *
     * @param {number} count - Word count
     * @param {Object} params - Field params (min_words, max_words, tolerance_words)
     * @returns {string} Zone name (see evaluateMetric)
     */
    function evaluateWords(count, params) {
        const raw = parseInt(params.tolerance_words, 10);
        const tol = isNaN(raw) ? 1 : Math.max(0, raw);
        return evaluateMetric(count, params.min_words, params.max_words, tol, tol);
    }

    /**
     * Whether a zone counts as meeting the length target (full credit).
     *
     * @param {string} zone - Zone name from evaluateMetric
     * @returns {boolean} True for 'optimal' and 'acceptable'
     */
    function withinTolerance(zone) {
        return zone === 'optimal' || zone === 'acceptable';
    }

    /**
     * Shared length-badge status for a field value — the one implementation
     * every badge surface (table counter, modals, optimizer preview) uses.
     *
     * Only covers length; callers handle empty values and filename format /
     * generic checks first.
     *
     * @param {string} value - Field value
     * @param {Object} params - Field params (min/max chars/words + tolerances)
     * @param {string} [fieldType] - 'filename' switches to hyphen-aware word counting
     * @returns {Object} {class, icon, text, ok, tooltip} — ok=true means green
     */
    function lengthStatus(value, params, fieldType) {
        const i18n = (typeof infyp_vars !== 'undefined' && infyp_vars.i18n) || {};
        const charCount = [...value].length;
        const wordCount = fieldType === 'filename'
            ? value.split(/[-_\s]+/).filter(w => w.length > 0).length
            : value.trim().split(/\s+/).filter(w => w.length > 0).length;

        const charZone = evaluateChars(charCount, params);
        const wordZone = evaluateWords(wordCount, params);

        if (withinTolerance(charZone) && withinTolerance(wordZone)) {
            const acceptable = charZone === 'acceptable' || wordZone === 'acceptable';
            return {
                class: 'success',
                icon: 'yes-alt',
                text: i18n.optimized || 'Optimized',
                ok: true,
                tooltip: acceptable ? (i18n.slightly_outside || 'Slightly outside the target range — still fine.') : ''
            };
        }

        // Character verdict takes precedence, matching the historic behavior.
        const tooShort = charZone === 'too_short' || (withinTolerance(charZone) && wordZone === 'too_short');
        return {
            class: 'warning',
            icon: 'warning',
            text: tooShort ? (i18n.too_short || 'Too short') : (i18n.too_long || 'Too long'),
            ok: false,
            tooltip: ''
        };
    }

    /**
     * SEO Scoring Engine
     * Calculates SEO scores for individual fields and overall image optimization
     */
    const engine = {
        /**
         * Calculate score for a specific field (alt, title, filename)
         *
         * @param {string} field - Field name ('alt_text', 'filename', 'title')
         * @param {string} value - Field value to score
         * @param {Object} weights - Score weight distribution
         * @param {Object} params - Scoring parameters (min/max values)
         * @returns {Object} Score result object
         * @returns {number} return.score - Calculated score for the field (0-100)
         * @returns {Object} return.status - Status information
         * @returns {string} return.status.class - CSS class for status ('empty', 'warning', 'success', 'error')
         * @returns {string} return.status.icon - Dashicon name for status
         * @returns {string} return.status.text - Human-readable status text
         */
        calculateFieldScore: function(field, value, weights, params) {
            if (!params) {
                const allParams = config.getScoringParams();
                switch (field) {
                    case 'alt_text':
                        params = allParams.alt;
                        break;
                    case 'filename':
                        params = allParams.filename;
                        break;
                    case 'title':
                        params = allParams.title;
                        break;
                }
            }

            let score = 0;
            let status = {
                class: 'empty',
                icon: 'dismiss',
                text: (typeof infyp_vars !== 'undefined' && infyp_vars.i18n.missing) || 'Missing'
            };

            switch (field) {
                case 'alt_text':
                    if (value.trim()) {
                        score += weights.alt_presence;
                        status = lengthStatus(value, params);
                        // Within tolerance (optimal or acceptable) earns the
                        // full quality weight so badge and score agree.
                        score += status.ok ? weights.alt_quality : weights.alt_quality * 0.5;
                    }
                    break;

                case 'filename':
                    const isGeneric = /^(DSC|IMG|DCIM|image)[\d_-]+$/i.test(value);
                    if (isGeneric) {
                        status = {
                            class: 'warning',
                            icon: 'warning',
                            text: (typeof infyp_vars !== 'undefined' && infyp_vars.i18n.generic) || 'Generic'
                        };
                    } else if (value.trim()) {
                        // Calculate proportional score (without keywords since not available in table context)
                        // Characters (33%), Words (33%), Format (33%)
                        const totalWeight = weights.filename_quality || 15;
                        const charCount = [...value].length;
                        const wordCount = value.split(/[-\s]+/).filter(w => w.length > 0).length;

                        // Characters score (full credit within tolerance)
                        if (withinTolerance(evaluateChars(charCount, params))) {
                            score += totalWeight * 0.33;
                        } else if (charCount > 0) {
                            const charPercentage = charCount < params.min_chars
                                ? charCount / params.min_chars
                                : Math.max(0, 1 - ((charCount - params.max_chars) / params.max_chars));
                            score += totalWeight * 0.33 * charPercentage;
                        }

                        // Words score (full credit within tolerance)
                        if (withinTolerance(evaluateWords(wordCount, params))) {
                            score += totalWeight * 0.33;
                        } else if (wordCount > 0) {
                            const wordPercentage = wordCount < params.min_words
                                ? wordCount / params.min_words
                                : Math.max(0, 1 - ((wordCount - params.max_words) / params.max_words));
                            score += totalWeight * 0.33 * wordPercentage;
                        }

                        // Format score
                        if (/^[a-z0-9\-]+$/.test(value)) {
                            score += totalWeight * 0.34; // 0.34 to make total = 1.0
                        }

                        // Status based on all factors (length is tolerance-aware)
                        if (withinTolerance(evaluateChars(charCount, params)) &&
                            withinTolerance(evaluateWords(wordCount, params)) &&
                            /^[a-z0-9\-]+$/.test(value)) {
                            status = {
                                class: 'success',
                                icon: 'yes-alt',
                                text: (typeof infyp_vars !== 'undefined' && infyp_vars.i18n.optimized) || 'Optimized'
                            };
                        } else {
                            status = {
                                class: 'warning',
                                icon: 'warning',
                                text: (typeof infyp_vars !== 'undefined' && infyp_vars.i18n.invalid_format) || 'Invalid format'
                            };
                        }
                    }
                    break;

                case 'title':
                    if (value.trim()) {
                        score += weights.title_presence;
                        status = lengthStatus(value, params);
                        // Within tolerance (optimal or acceptable) earns the
                        // full quality weight so badge and score agree.
                        score += status.ok ? weights.title_quality : weights.title_quality * 0.5;
                    }
                    break;
            }

            return {
                score,
                status
            };
        },

        /**
         * Calculate total SEO score for an image
         *
         * @param {Object} imageData - Image metadata
         * @param {string} imageData.alt_text - Alt text value
         * @param {string} imageData.filename - Filename value
         * @param {string} imageData.title - Title value
         * @param {Array<string>} imageData.keywords - Array of keywords
         * @param {Object} weights - Score weight distribution
         * @returns {Object} Total score result
         * @returns {number} return.score - Total calculated score
         * @returns {number} return.percentage - Score as percentage (0-100)
         * @returns {string} return.status - Human-readable status
         * @returns {string} return.status_class - CSS class for status
         */
        calculateTotalScore: function(imageData, weights) {
            let totalScore = 0;
            const params = config.getScoringParams();

            // Calculate alt text score
            if (imageData.alt_text) {
                const altResult = this.calculateFieldScore('alt_text', imageData.alt_text, weights, params.alt);
                totalScore += altResult.score;
            }

            // Calculate filename score
            if (imageData.filename) {
                const filenameResult = this.calculateFieldScore('filename', imageData.filename, weights, params.filename);
                totalScore += filenameResult.score;
            }

            // Calculate title score
            if (imageData.title) {
                const titleResult = this.calculateFieldScore('title', imageData.title, weights, params.title);
                totalScore += titleResult.score;
            }

            // Calculate keyword score
            if (imageData.keywords && imageData.keywords.length > 0 && weights.keyword_density) {
                const keywordScore = this.calculateKeywordScore(imageData, weights.keyword_density);
                totalScore += keywordScore;
            }

            // Add uniqueness weight (assumed present)
            if (weights.uniqueness) {
                totalScore += weights.uniqueness;
            }

            // Calculate percentage
            const totalPossible = Object.values(weights).reduce((sum, weight) => sum + weight, 0);
            const percentage = Math.round((totalScore / totalPossible) * 100);

            // Determine status
            let status, statusClass;
            if (percentage >= 80) {
                status = (typeof infyp_vars !== 'undefined' && infyp_vars.i18n.status_optimized) || 'Optimized';
                statusClass = 'optimized';
            } else if (percentage >= 60) {
                status = (typeof infyp_vars !== 'undefined' && infyp_vars.i18n.status_good) || 'Good';
                statusClass = 'good';
            } else if (percentage >= 40) {
                status = (typeof infyp_vars !== 'undefined' && infyp_vars.i18n.status_fair) || 'Fair';
                statusClass = 'fair';
            } else {
                status = (typeof infyp_vars !== 'undefined' && infyp_vars.i18n.status_poor) || 'Poor';
                statusClass = 'poor';
            }

            return {
                score: totalScore,
                percentage: percentage,
                status: status,
                status_class: statusClass
            };
        },

        /**
         * Calculate keyword density score based on per-field keyword usage
         * Target: 2 keywords per field = 100% (3rd keyword is optional bonus)
         *
         * Distribution:
         * - Filename: 40% (most important for SEO)
         * - Alt text: 35% (important for accessibility & SEO)
         * - Title: 25% (supports overall SEO)
         *
         * @param {Object} imageData - Image metadata with keywords
         * @param {string} imageData.alt_text - Alt text value
         * @param {string} imageData.filename - Filename value
         * @param {string} imageData.title - Title value
         * @param {Array<string>} imageData.keywords - Array of keywords
         * @param {number} keywordWeight - Total weight allocated to keyword scoring
         * @returns {number} Calculated keyword score
         */
        calculateKeywordScore: function(imageData, keywordWeight) {
            if (!imageData.keywords || imageData.keywords.length === 0) return 0;

            const minKeywordsForFull = 2; // 2 keywords = 100% per field
            let score = 0;

            // Helper to count keywords in a field (handles multi-word keywords)
            const countKeywordsInField = (fieldValue, keywords) => {
                if (!fieldValue) return 0;
                let count = 0;
                const normalizedField = fieldValue.toLowerCase();

                keywords.forEach(keyword => {
                    const normalizedKeyword = keyword.toLowerCase();
                    // For filename, also check hyphenated version
                    if (normalizedField.includes(normalizedKeyword)) {
                        count++;
                    } else if (fieldValue === imageData.filename) {
                        const hyphenatedKeyword = normalizedKeyword.replace(/\s+/g, '-');
                        if (normalizedField.includes(hyphenatedKeyword)) {
                            count++;
                        }
                    }
                });

                return count;
            };

            // Filename keywords (40% of total keyword weight)
            const filenameKeywordCount = countKeywordsInField(imageData.filename, imageData.keywords);
            const filenamePercentage = Math.min(filenameKeywordCount / minKeywordsForFull, 1.0); // Cap at 100%
            score += keywordWeight * 0.40 * filenamePercentage;

            // Alt text keywords (35% of total keyword weight)
            const altKeywordCount = countKeywordsInField(imageData.alt_text, imageData.keywords);
            const altPercentage = Math.min(altKeywordCount / minKeywordsForFull, 1.0); // Cap at 100%
            score += keywordWeight * 0.35 * altPercentage;

            // Title keywords (25% of total keyword weight)
            const titleKeywordCount = countKeywordsInField(imageData.title, imageData.keywords);
            const titlePercentage = Math.min(titleKeywordCount / minKeywordsForFull, 1.0); // Cap at 100%
            score += keywordWeight * 0.25 * titlePercentage;

            return score;
        }
    };

    /**
     * Update live SEO score as user types in a field
     *
     * @param {number} imageId - WordPress attachment ID
     * @param {string} fieldName - Field being edited ('alt_text', 'filename', 'title')
     * @param {string} newValue - New value for the field
     * @param {boolean} forceUpdate - Force update even if not in AI updating mode
     */
    function updateLiveSEOScore(imageId, fieldName, newValue, forceUpdate = false) {
        const $row = $(`tr[data-id="${imageId}"]`);
        if (!$row.length) return;

        // Only update if in AI updating mode or forced
        if (!forceUpdate && !$row.data('ai-updating')) {
            return;
        }

        // Get keywords from row data
        let keywords = $row.data('keywords') || [];
        if (typeof keywords === 'string') {
            try {
                keywords = JSON.parse(keywords);
            } catch (e) {
                keywords = [];
            }
        }

        // Gather current field values
        const imageData = {
            alt_text: fieldName === 'alt_text' ? newValue : $row.find('.infyp-alt-text-trigger').val() || '',
            filename: fieldName === 'filename' ? newValue : $row.find('.infyp-filename-trigger').val() || '',
            title: fieldName === 'title' ? newValue : $row.find('.infyp-title-trigger').val() || '',
            keywords: keywords
        };

        // Calculate new score
        const weights = config.getWeights();
        const scoreData = engine.calculateTotalScore(imageData, weights);

        // Update UI
        updateSEOScore(imageId, scoreData);
    }

    /**
     * Update SEO score display in the UI
     *
     * @param {number} imageId - WordPress attachment ID
     * @param {Object} scoreData - Score data from calculateTotalScore
     * @param {number} scoreData.percentage - Score percentage (0-100)
     * @param {string} scoreData.status - Status text
     * @param {string} scoreData.status_class - CSS class for status
     */
    function updateSEOScore(imageId, scoreData) {
        const scoreWrapper = $(`.infyp-seo-score-wrapper[data-image-id="${imageId}"]`);
        if (!scoreWrapper.length) return;

        const progressPath = scoreWrapper.find('.infyp-score-progress');
        const scoreText = scoreWrapper.find('.infyp-score-text');
        const scoreLabel = scoreWrapper.find('.infyp-score-label');
        const scoreNumber = scoreWrapper.find('.infyp-score-number');

        const currentPercentage = parseInt(scoreNumber.text()) || 0;
        const targetPercentage = scoreData.percentage;

        // Update classes
        progressPath.removeClass('optimized good fair poor');
        scoreLabel.removeClass('optimized good fair poor');
        progressPath.addClass(scoreData.status_class);
        scoreLabel.addClass(scoreData.status_class);

        // Update progress circle
        progressPath.attr('stroke-dasharray', `${targetPercentage},100`);

        // Animate score counter
        if (currentPercentage !== targetPercentage) {
            const duration = 1000;
            const increment = targetPercentage > currentPercentage ? 1 : -1;
            const steps = Math.abs(targetPercentage - currentPercentage);
            const stepDuration = duration / steps;
            let current = currentPercentage;

            const counter = setInterval(() => {
                current += increment;
                scoreText.html(`<span class="infyp-score-number">${current}%</span>`);

                if (current === targetPercentage) {
                    clearInterval(counter);
                    const labelText = targetPercentage == 100 ?
                        ((typeof infyp_vars !== 'undefined' && infyp_vars.i18n.fully_optimized) || 'Optimized') :
                        scoreData.status;

                    const textNode = scoreLabel.contents().filter(function() {
                        return this.nodeType === 3;
                    });

                    if (textNode.length > 0) {
                        textNode[0].nodeValue = labelText + ' ';
                    } else {
                        scoreLabel.prepend(labelText + ' ');
                    }
                }
            }, stepDuration);
        } else {
            // No animation needed, just update label
            const labelText = targetPercentage == 100 ?
                ((typeof infyp_vars !== 'undefined' && infyp_vars.i18n.fully_optimized) || 'Optimized') :
                scoreData.status;

            const textNode = scoreLabel.contents().filter(function() {
                return this.nodeType === 3;
            });

            if (textNode.length > 0) {
                textNode[0].nodeValue = labelText + ' ';
            } else {
                scoreLabel.prepend(labelText + ' ');
            }
        }
    }

    // Public API
    return {
        engine,
        updateLiveSEOScore,
        updateSEOScore,
        evaluateMetric,
        evaluateChars,
        evaluateWords,
        withinTolerance,
        lengthStatus
    };

})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = scoringEngine;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.scoringEngine = scoringEngine;
}

export default scoringEngine;
