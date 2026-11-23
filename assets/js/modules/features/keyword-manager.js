/**
 * Keyword Manager Module - Keyword management and usage tracking
 *
 * This module handles all keyword-related functionality including token input management,
 * keyword usage detection in text fields, AI-powered keyword generation, and visual
 * feedback for keyword usage in modals.
 *
 * ## Features
 *
 * - Token-based keyword input with add/remove
 * - Real-time keyword usage detection in alt text, title, and filename
 * - AI-powered keyword generation
 * - Maximum 3 keywords per image
 * - Visual feedback badges showing used/unused keywords
 * - Support for filename format (hyphen-separated words)
 * - Backspace to remove last keyword
 * - Enter/comma to add keywords
 * - Duplicate detection
 *
 * ## Keyword Usage Detection
 *
 * | Field Type | Detection Method                                              |
 * |------------|---------------------------------------------------------------|
 * | Alt Text   | Case-insensitive substring match                              |
 * | Title      | Case-insensitive substring match                              |
 * | Filename   | All words from keyword exist as hyphen-separated segments     |
 *
 * ## Usage
 *
 * ```javascript
 * import keywordManager from './features/keyword-manager.js';
 *
 * // Initialize token input in modal
 * keywordManager.initTokenInput();
 *
 * // Check if keyword is used
 * const isUsed = keywordManager.checkUsage('Red car on road', 'red car');
 *
 * // Generate keywords with AI
 * keywordManager.generateWithAI($button, imageId);
 *
 * // Update usage display in real-time
 * keywordManager.updateUsageDisplay('red car on highway', false);
 * ```
 *
 * @file Keyword Manager Module - Keyword management and usage tracking
 * @author InfyPress
 * @since 1.0.0
 * @module features/keyword-manager
 * @requires jQuery
 * @requires api
 * @requires ui/notifications
 */

import api from '../api.js';
import notifications from '../ui/notifications.js';

const keywordManager = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    /**
     * Maximum number of keywords allowed per image.
     *
     * @since 1.0.0
     * @constant {number}
     */
    const MAX_KEYWORDS = 3;

    /**
     * Minimum character length for a valid keyword.
     *
     * @since 1.0.0
     * @constant {number}
     */
    const MIN_KEYWORD_LENGTH = 3;

    /* =========================================================================
       KEYWORD USAGE DETECTION
       ========================================================================= */

    /**
     * Checks if a keyword is used in text content.
     *
     * For regular text (alt text, title): performs case-insensitive substring match.
     * For filenames: checks if all words from the keyword exist as hyphen-separated
     * segments in the filename. Normalizes underscores to hyphens for comparison.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} text - Text content to check
     * @param {string} keyword - Keyword to search for
     * @param {boolean} [isFilename=false] - Whether checking a filename field
     * @returns {boolean} True if keyword is found in text
     *
     * @example
     * // Regular text matching
     * checkUsage('Beautiful red car', 'red car');        // true
     *
     * @example
     * // Filename matching
     * checkUsage('beautiful-red-car', 'red car', true);  // true
     * checkUsage('red_car_photo', 'red car', true);      // true
     */
    function checkUsage(text, keyword, isFilename = false) {
        if (!text || !keyword) return false;

        // For filenames, check if all words from the keyword are present (even if split/reordered)
        if (isFilename) {
            // Normalize the filename: replace underscores with hyphens for comparison
            const normalizedText = text.toLowerCase().replace(/_/g, '-');

            // Split the keyword into individual words
            const keywordWords = keyword.toLowerCase().split(/\s+/);

            // Check if all words from the keyword are present in the filename
            const allWordsPresent = keywordWords.every(word => {
                // Check if the word exists in the filename
                const wordPattern = new RegExp(
                    `(^|-)${word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(-|$)`
                );
                return wordPattern.test(normalizedText);
            });

            return allWordsPresent;
        }

        // For regular text (alt text, title), do normal comparison
        return text.toLowerCase().includes(keyword.toLowerCase());
    }

    /**
     * Updates keyword usage display badges in real-time.
     *
     * Scans all keyword badges in the modal and updates their visual state
     * based on whether each keyword is used in the current field value. Toggles
     * between 'keyword-used' and 'keyword-unused' classes and updates dashicons.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} currentValue - Current field value to check against
     * @param {boolean} [isFilename=false] - Whether checking filename field
     * @returns {void}
     *
     * @example
     * // Update badges when user types in alt text field
     * updateUsageDisplay('Red sports car on highway', false);
     */
    function updateUsageDisplay(currentValue, isFilename = false) {
        const $keywordBadges = $('.infyp-modal-keyword-badge');

        $keywordBadges.each(function() {
            const $badge = $(this);
            const keyword = $badge.text().trim();

            // Check if keyword is used
            const isUsed = checkUsage(currentValue, keyword, isFilename);

            // Update badge appearance
            if (isUsed) {
                $badge.removeClass('keyword-unused').addClass('keyword-used');
                $badge.find('.dashicons')
                    .removeClass('dashicons-warning')
                    .addClass('dashicons-yes-alt');
            } else {
                $badge.removeClass('keyword-used').addClass('keyword-unused');
                $badge.find('.dashicons')
                    .removeClass('dashicons-yes-alt')
                    .addClass('dashicons-warning');
            }
        });
    }

    /* =========================================================================
       HTML GENERATION
       ========================================================================= */

    /**
     * Generates HTML for keyword usage display in modals.
     *
     * Creates the HTML structure for displaying keywords with usage status
     * indicators in field editing modals. Returns empty string if no keywords
     * are assigned to the image.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {number} imageId - WordPress attachment ID
     * @param {string} currentValue - Current field value for usage checking
     * @param {boolean} [isFilename=false] - Whether for filename field
     * @returns {string} HTML string (empty if no keywords)
     *
     * @example
     * // Generate display for alt text modal
     * const html = getDisplayHtml(123, 'Red sports car', false);
     * $('.modal-body').append(html);
     */
    function getDisplayHtml(imageId, currentValue, isFilename = false) {
        const $row = $(`tr[data-id="${imageId}"]`);
        const keywordsData = $row.attr('data-keywords');
        let keywords = [];

        try {
            keywords = keywordsData ? JSON.parse(keywordsData) : [];
        } catch (e) {
            keywords = [];
        }

        if (keywords.length === 0) {
            return '';
        }

        return `
            <div class="infyp-modal-keywords-display">
                <div class="infyp-modal-keywords-header">
                    <span class="infyp-modal-keywords-label">${infyp_vars.i18n.keyword_usage || 'Keyword usage'}</span>
                </div>
                <div class="infyp-modal-keywords-list">
                    ${keywords.map(keyword => {
                        const isUsed = checkUsage(currentValue, keyword, isFilename);
                        return `
                            <span class="infyp-modal-keyword-badge ${isUsed ? 'keyword-used' : 'keyword-unused'}">
                                <span class="dashicons dashicons-${isUsed ? 'yes-alt' : 'warning'}"></span>
                                ${notifications.escapeHtml(keyword)}
                            </span>
                        `;
                    }).join('')}
                </div>
                <div class="infyp-modal-keywords-hint">
                    ${infyp_vars.i18n.keyword_usage_hint || 'Include keywords naturally in your text for better SEO'}
                </div>
            </div>
        `;
    }

    /* =========================================================================
       TOKEN INPUT MANAGEMENT
       ========================================================================= */

    /**
     * Initializes keyword token input functionality.
     *
     * Sets up event handlers for the token-based keyword input including:
     * - Enter key and comma to add keywords
     * - Backspace to remove last keyword when input is empty
     * - Click on X button to remove specific keyword
     * - Click on container to focus input
     * - Paste detection with comma splitting
     *
     * @since 1.0.0
     * @access public
     *
     * @returns {void}
     *
     * @example
     * // Initialize in keywords modal
     * initTokenInput();
     */
    function initTokenInput() {
        const $input = $('#infyp-keyword-input');
        const $container = $('#infyp-keywords-tokens');

        if (!$input.length) return;

        // Handle Enter key and comma to add keyword
        $input.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                const value = $(this).val().trim();
                if (value) {
                    addToken(value);
                }
            }
        });

        // Also handle comma in keyup for pasted content
        $input.on('input', function() {
            const value = $(this).val();
            if (value.includes(',')) {
                const keywords = value.split(',');
                keywords.forEach(kw => {
                    const trimmed = kw.trim();
                    if (trimmed && trimmed !== ',') {
                        addToken(trimmed);
                    }
                });
                $(this).val('');
            }
        });

        // Handle backspace on empty input to remove last token
        $input.on('keydown', function(e) {
            if (e.key === 'Backspace' && $(this).val() === '') {
                const $lastToken = $container.find('.infyp-keyword-token:last');
                if ($lastToken.length) {
                    $lastToken.remove();
                    updateCounter();
                }
            }
        });

        // Handle removing keywords
        $container.on('click', '.infyp-token-remove', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).closest('.infyp-keyword-token').remove();
            updateCounter();

            // Re-enable input if we're below max
            const count = $('.infyp-keyword-token').length;
            if (count < MAX_KEYWORDS) {
                $input.prop('disabled', false).attr('placeholder', '');
            }
        });

        // Click on container focuses input
        $container.on('click', function(e) {
            if (e.target === this || $(e.target).hasClass('infyp-token-list')) {
                $input.focus();
            }
        });
    }

    /**
     * Adds a keyword token to the input.
     *
     * Validates the keyword (length, duplicates, max count) before adding.
     * Creates a token element with remove button. Disables input if max
     * keywords reached.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} keyword - Keyword to add
     * @returns {boolean} True if keyword was successfully added
     *
     * @example
     * // Add a keyword
     * addToken('red car');  // true
     * addToken('a');        // false (too short)
     */
    function addToken(keyword) {
        if (!keyword) return false;

        const $container = $('#infyp-keywords-tokens');
        const $input = $('#infyp-keyword-input');
        const existingKeywords = [];

        // Get existing keywords
        $container.find('.infyp-keyword-token').each(function() {
            const kw = $(this).data('keyword') || $(this).text().trim().replace('×', '').trim();
            existingKeywords.push(kw.toLowerCase());
        });

        // Validation - at max keywords
        if (existingKeywords.length >= MAX_KEYWORDS) {
            $input.val('');
            return false;
        }

        // Validation - too short
        if (keyword.length < MIN_KEYWORD_LENGTH) {
            return false;
        }

        // Validation - duplicate
        if (existingKeywords.includes(keyword.toLowerCase())) {
            $input.val('');
            return false;
        }

        // Add the keyword token before the input
        const tokenHtml = `
            <span class="infyp-keyword-token" data-keyword="${notifications.escapeHtml(keyword)}">
                ${notifications.escapeHtml(keyword)}
                <button type="button" class="infyp-token-remove" aria-label="Remove keyword">&times;</button>
            </span>
        `;

        $input.before(tokenHtml);
        $input.val('');

        // Update counter
        updateCounter();

        // Disable input if we've reached max keywords
        if (existingKeywords.length + 1 >= MAX_KEYWORDS) {
            $input.prop('disabled', true).attr('placeholder', infyp_vars.i18n.max_keywords_reached || 'Maximum 3 keywords');
        }

        return true;
    }

    /**
     * Updates the keyword counter display.
     *
     * Displays current count vs. maximum (e.g., "2 | 3 Keywords").
     *
     * @since 1.0.0
     * @access public
     *
     * @returns {void}
     */
    function updateCounter() {
        const count = $('#infyp-keywords-tokens .infyp-keyword-token').length;
        $('.infyp-keywords-counter .keyword-count').text(`${count} | ${MAX_KEYWORDS} Keywords`);
    }

    /**
     * Gets all current keywords from the token input.
     *
     * @since 1.0.0
     * @access public
     *
     * @returns {string[]} Array of keyword strings
     *
     * @example
     * const keywords = getCurrentKeywords();  // ['red car', 'sports car']
     */
    function getCurrentKeywords() {
        const keywords = [];
        $('#infyp-keywords-tokens .infyp-keyword-token').each(function() {
            const kw = $(this).data('keyword') || $(this).text().trim().replace('×', '').trim();
            if (kw) {
                keywords.push(kw);
            }
        });
        return keywords;
    }

    /**
     * Clears all keyword tokens from the input.
     *
     * Removes all tokens, re-enables the input field, and updates the counter.
     *
     * @since 1.0.0
     * @access public
     *
     * @returns {void}
     *
     * @example
     * clearTokens();  // Removes all keywords
     */
    function clearTokens() {
        $('#infyp-keywords-tokens .infyp-keyword-token').remove();
        const $input = $('#infyp-keyword-input');
        $input.prop('disabled', false).attr('placeholder', '');
        updateCounter();
    }

    /**
     * Sets keywords from an array.
     *
     * Clears existing keywords and adds all keywords from the array.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string[]} keywords - Array of keywords to set
     * @returns {void}
     *
     * @example
     * setKeywords(['red car', 'sports car']);
     */
    function setKeywords(keywords) {
        clearTokens();
        if (Array.isArray(keywords)) {
            keywords.forEach(kw => addToken(kw));
        }
    }

    /* =========================================================================
       AI GENERATION
       ========================================================================= */

    /**
     * Generates keywords using AI.
     *
     * Calls the backend AI service to generate relevant keywords for the image.
     * Updates the token input with generated keywords on success. Shows loading
     * state on button and displays success/error notifications.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {jQuery} $btn - The trigger button element
     * @param {number} imageId - WordPress attachment ID
     * @returns {jQuery.Promise} The AJAX promise
     *
     * @example
     * generateWithAI($button, 123).done(function() {
     *     console.log('Keywords generated');
     * });
     */
    function generateWithAI($btn, imageId) {
        console.log('[Keywords] Starting AI generation for image:', imageId);

        $btn.prop('disabled', true).html(
            infyp_vars.i18n.generating_keywords || 'Generating keywords...'
        );

        return api.post('infyp_generate_keywords', {
            image_id: imageId
        }).done(function(response) {
            console.log('[Keywords] AI generation response:', response);
            if (response.success && response.data.keywords) {
                // Clear existing keywords
                clearTokens();

                // Add generated keywords
                response.data.keywords.forEach(keyword => {
                    addToken(keyword);
                });

                notifications.success(infyp_vars.i18n.keywords_generated || 'Keywords generated successfully');
            } else {
                console.error('[Keywords] AI generation error:', response);
                alert(response.data.message || infyp_vars.i18n.error_generating_keywords);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error('[Keywords] AI generation failed:', {
                status: jqXHR.status,
                statusText: jqXHR.statusText,
                responseText: jqXHR.responseText,
                textStatus: textStatus,
                errorThrown: errorThrown
            });
            alert(infyp_vars.i18n.server_error || 'Server error');
        }).always(function() {
            $btn.prop('disabled', false).html(`
                <span class="infyp-optimize-icon"></span>
                ${infyp_vars.i18n.generate_keywords || 'Generate with AI'}
            `);
        });
    }

    /* =========================================================================
       EVENT HANDLERS
       ========================================================================= */

    /**
     * Handles generate keywords button click event.
     *
     * Extracts image ID from button's data attribute and initiates AI generation.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {Event} e - Click event
     * @returns {void}
     */
    function handleGenerateClick(e) {
        e.preventDefault();
        const $btn = $(e.currentTarget);
        const imageId = $btn.data('image-id');
        generateWithAI($btn, imageId);
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the keyword manager module.
     *
     * @since 1.0.0
     *
     * @returns {Object} Public methods and constants
     * @property {Function} checkUsage - Check if keyword is used in text
     * @property {Function} updateUsageDisplay - Update badge display in modal
     * @property {Function} getDisplayHtml - Generate HTML for keyword display
     * @property {Function} initTokenInput - Initialize token input handlers
     * @property {Function} addToken - Add a keyword token
     * @property {Function} updateCounter - Update keyword count display
     * @property {Function} getCurrentKeywords - Get array of current keywords
     * @property {Function} clearTokens - Remove all keyword tokens
     * @property {Function} setKeywords - Set keywords from array
     * @property {Function} generateWithAI - Generate keywords with AI
     * @property {Function} handleGenerateClick - Handle generate button click
     * @property {number} MAX_KEYWORDS - Maximum keywords allowed (3)
     * @property {number} MIN_KEYWORD_LENGTH - Minimum keyword length (3 chars)
     */
    return {
        checkUsage: checkUsage,
        updateUsageDisplay: updateUsageDisplay,
        getDisplayHtml: getDisplayHtml,
        initTokenInput: initTokenInput,
        addToken: addToken,
        updateCounter: updateCounter,
        getCurrentKeywords: getCurrentKeywords,
        clearTokens: clearTokens,
        setKeywords: setKeywords,
        generateWithAI: generateWithAI,
        handleGenerateClick: handleGenerateClick,
        MAX_KEYWORDS: MAX_KEYWORDS,
        MIN_KEYWORD_LENGTH: MIN_KEYWORD_LENGTH
    };

})(jQuery);

// Export for ES6 modules
export default keywordManager;
