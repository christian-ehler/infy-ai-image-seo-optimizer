/**
 * Character Counter Module - Real-time field validation feedback
 *
 * This module provides real-time character counting and validation feedback
 * for editable fields in the WordPress media library table. It validates
 * alt text, title, and filename fields against SEO-optimized parameters.
 *
 * ## Features
 *
 * - Real-time character and word counting
 * - SEO parameter validation (min/max chars, min/max words)
 * - Filename format validation (lowercase, hyphens, no spaces)
 * - Visual status badges (success, warning, error)
 * - Configurable parameters from scoring config
 *
 * ## Status Types
 *
 * | Status   | Icon       | Meaning                                        |
 * |----------|------------|------------------------------------------------|
 * | success  | yes-alt    | Field within the optimal range or tolerance    |
 * | warning  | warning    | Field beyond tolerance (too short/long, format)|
 * | empty    | dismiss    | Field is missing/empty                         |
 *
 * Length checks are tolerance-aware via core/scoring-engine.js lengthStatus():
 * values inside the configured play room stay green with full score.
 *
 * ## Dependencies
 *
 * - jQuery
 * - config module (for SEO parameters)
 * - infyp_vars global (for i18n strings)
 *
 * ## Usage
 *
 * ```javascript
 * import characterCounter from './table/character-counter.js';
 *
 * // Update counter for a single field
 * characterCounter.update(inputElement);
 *
 * // Validate a field
 * const result = characterCounter.validate(inputElement);
 * if (result.isValid) { ... }
 *
 * // Initialize all fields in container
 * characterCounter.init($container);
 * ```
 *
 * @file Real-time character counting and SEO validation feedback for editable fields.
 * @author InfyPress
 * @since 1.0.0
 * @module table/character-counter
 * @requires jQuery
 * @requires core/config
 */

import config from '../core/config.js';
import scoringEngine from '../core/scoring-engine.js';

const characterCounter = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    /**
     * Get alt text parameters from config
     * @type {Function}
     * @private
     */
    const getAltParams = config.getAltParams;

    /**
     * Get title parameters from config
     * @type {Function}
     * @private
     */
    const getTitleParams = config.getTitleParams;

    /**
     * Get filename parameters from config
     * @type {Function}
     * @private
     */
    const getFilenameParams = config.getFilenameParams;

    /* =========================================================================
       PARAMETER RETRIEVAL
       ========================================================================= */

    /**
     * Get validation parameters for a specific field type
     *
     * Returns SEO-optimized character and word limits for the given field type.
     * Falls back to default values for unknown field types.
     *
     * @param {string} fieldType - Field type identifier
     *                             ('alt', 'alt_text', 'title', 'image_title', 'filename')
     * @returns {Object} Validation parameters
     * @returns {number} returns.min_chars - Minimum character count
     * @returns {number} returns.max_chars - Maximum character count
     * @returns {number} returns.min_words - Minimum word count (text fields only)
     * @returns {number} returns.max_words - Maximum word count (text fields only)
     */
    function getParamsForType(fieldType) {
        switch (fieldType) {
            case 'alt':
            case 'alt_text':
                return getAltParams();
            case 'title':
            case 'image_title':
                return getTitleParams();
            case 'filename':
                return getFilenameParams();
            default:
                return {
                    min_chars: 40,
                    max_chars: 60,
                    min_words: 3,
                    max_words: 10
                };
        }
    }

    /* =========================================================================
       VALIDATION FUNCTIONS
       ========================================================================= */

    /**
     * Validate filename format against SEO best practices
     *
     * Checks for common filename issues:
     * - Uppercase characters (should be lowercase)
     * - Underscores (should use hyphens)
     * - Spaces (not allowed)
     *
     * @param {string} value - Filename value to validate
     * @returns {string[]} Array of human-readable issue descriptions
     *
     * @example
     * validateFilenameFormat('My_Image');
     * // Returns: ['lowercase only', 'use hyphens']
     */
    function validateFilenameFormat(value) {
        const issues = [];

        if (!/^[a-z0-9\-]+$/.test(value)) {
            if (/[A-Z]/.test(value)) {
                issues.push(infyp_vars.i18n.lowercase_only || 'lowercase only');
            }
            if (/_/.test(value)) {
                issues.push(infyp_vars.i18n.use_hyphens || 'use hyphens');
            }
            if (/\s/.test(value)) {
                issues.push(infyp_vars.i18n.no_spaces || 'no spaces');
            }
        }

        return issues;
    }

    /**
     * Determine validation status for a field value
     *
     * Evaluates the value against SEO parameters and returns a status object
     * for displaying visual feedback. Performs checks in order:
     * 1. Empty check
     * 2. Filename format check (if applicable)
     * 3. Character count range check
     * 4. Word count range check (if applicable)
     *
     * @param {string} value - The field value to validate
     * @param {Object} params - Validation parameters from getParamsForType()
     * @param {string} fieldType - Field type identifier
     * @returns {Object} Status object for UI display
     * @returns {string} returns.class - CSS class ('success', 'warning', 'error', 'empty')
     * @returns {string} returns.icon - Dashicon name ('yes-alt', 'warning', 'dismiss')
     * @returns {string} returns.text - Human-readable status message
     */
    function getStatus(value, params, fieldType) {
        const charCount = value ? [...value].length : 0;

        // Empty value
        if (!value || charCount === 0) {
            return {
                class: 'empty',
                icon: 'dismiss',
                text: infyp_vars.i18n.missing || 'Missing'
            };
        }

        // Filename-specific validation
        if (fieldType === 'filename') {
            const formatIssues = validateFilenameFormat(value);

            if (formatIssues.length > 0) {
                return {
                    class: 'warning',
                    icon: 'warning',
                    text: formatIssues.join(' | ')
                };
            }
        }

        // Length validation — shared tolerance-aware implementation.
        return scoringEngine.lengthStatus(value, params, fieldType);
    }

    /* =========================================================================
       UI UPDATE FUNCTIONS
       ========================================================================= */

    /**
     * Update character counter display for a field
     *
     * Calculates current character count, validates against parameters,
     * and updates the badge container with count and status indicators.
     *
     * @param {HTMLElement|jQuery} field - The input field element to update
     * @returns {void}
     *
     * @example
     * // Update on input event
     * $input.on('input', function() {
     *     characterCounter.update(this);
     * });
     */
    function update(field) {
        const $field = $(field);
        const fieldType = $field.data('field');
        const $wrapper = $field.closest('.infyp-field-wrapper');
        const $badgeContainer = $wrapper.find('.infyp-badge-container');
        const value = $field.val() || '';
        const charCount = [...value].length;

        // Get params for this field type
        const params = getParamsForType(fieldType);

        // Build character count badge
        const charBadgeHtml = `${charCount} | ${params.max_chars} ${infyp_vars.i18n.chars || 'chars'}`;

        // Get validation status
        const status = getStatus(value, params, fieldType);

        // Build status HTML
        let statusHtml = '';
        if (status.icon) {
            statusHtml = `<span class="dashicons dashicons-${status.icon}"></span> ${status.text}`;
        } else {
            statusHtml = status.text;
        }

        // Update badge container (tooltip marks acceptable-zone values)
        const tooltipAttr = status.tooltip ? ` title="${status.tooltip}"` : '';
        $badgeContainer.html(`
            <div class="infyp-field-feedback char-count">${charBadgeHtml}</div>
            <div class="infyp-field-feedback ${status.class}"${tooltipAttr}>${statusHtml}</div>
        `);
    }

    /**
     * Validate field value and return detailed results
     *
     * Performs full validation on a field and returns comprehensive results
     * including validity flag, status object, counts, and parameters used.
     *
     * @param {HTMLElement|jQuery} field - The input field element to validate
     * @param {string} [type] - Optional field type override (uses data-field if omitted)
     * @returns {Object} Validation result object
     * @returns {boolean} returns.isValid - True if field passes all validation
     * @returns {Object} returns.status - Status object from getStatus()
     * @returns {number} returns.charCount - Current character count
     * @returns {number} returns.wordCount - Current word count
     * @returns {Object} returns.params - Parameters used for validation
     *
     * @example
     * const result = characterCounter.validate(inputElement);
     * if (!result.isValid) {
     *     console.log('Needs', result.params.min_chars - result.charCount, 'more chars');
     * }
     */
    function validate(field, type) {
        const $field = $(field);
        const fieldType = type || $field.data('field');
        const value = $field.val() || '';
        const params = getParamsForType(fieldType);
        const charCount = [...value].length;
        const wordCount = value ? value.trim().split(/\s+/).length : 0;
        const status = getStatus(value, params, fieldType);

        return {
            isValid: status.class === 'success',
            status: status,
            charCount: charCount,
            wordCount: wordCount,
            params: params
        };
    }

    /* =========================================================================
       INITIALIZATION
       ========================================================================= */

    /**
     * Initialize character counters for all editable fields
     *
     * Finds all fields with `.infyp-editable-field` class within the
     * container and updates their character counter displays.
     *
     * @param {jQuery} [$container] - Container to search within (defaults to document)
     * @returns {void}
     */
    function init($container) {
        const $fields = ($container || $(document)).find('.infyp-editable-field');

        $fields.each(function() {
            update(this);
        });
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the character counter module
     *
     * @returns {Object} Public methods
     * @property {Function} update - Update counter for a single field
     * @property {Function} validate - Validate a field and get detailed results
     * @property {Function} getStatus - Get validation status for a value
     * @property {Function} getParamsForType - Get parameters for a field type
     * @property {Function} validateFilenameFormat - Check filename format
     * @property {Function} init - Initialize all fields in a container
     */
    return {
        update: update,
        validate: validate,
        getStatus: getStatus,
        getParamsForType: getParamsForType,
        validateFilenameFormat: validateFilenameFormat,
        init: init
    };

})(jQuery);

// Export for ES6 modules
export default characterCounter;
