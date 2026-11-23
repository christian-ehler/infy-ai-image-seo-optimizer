/**
 * Field Editor Module - Handles inline field editing and validation
 *
 * This module manages inline editing of image metadata fields (alt text, title,
 * filename) in the WordPress media library table. It handles AJAX updates, field validation,
 * SEO score recalculation, and user feedback during the editing process.
 *
 * ## Features
 *
 * - Inline field editing with AJAX persistence
 * - Automatic filename sanitization (lowercase, hyphens, no special chars)
 * - Real-time validation feedback (spinner, success, error)
 * - SEO score recalculation after updates
 * - Graceful error handling with user notifications
 * - XSS protection via safe DOM manipulation
 *
 * ## Supported Fields
 *
 * | Field      | AJAX Action                    | Special Handling      |
 * |------------|--------------------------------|-----------------------|
 * | alt_text   | infyp_update_image_data      | None                  |
 * | title      | infyp_update_image_data      | None                  |
 * | filename   | infyp_update_filename        | Auto-sanitization     |
 *
 * ## Dependencies
 *
 * - jQuery
 * - api module (AJAX helper)
 * - ui module (UI utilities)
 * - scoring-engine module (SEO score updates)
 * - infyp_vars global (i18n strings)
 *
 * ## Usage
 *
 * ```javascript
 * import fieldEditor from './table/field-editor.js';
 *
 * // Initialize on page load
 * fieldEditor.init();
 *
 * // Re-initialize after AJAX table updates
 * fieldEditor.init();
 * ```
 *
 * @file Field Editor Module - Inline editing for image metadata fields.
 * @author InfyPress
 * @since 1.0.0
 * @module table/field-editor
 * @requires jQuery
 * @requires module:api
 * @requires module:ui
 * @requires module:core/scoring-engine
 */

import api from '../api.js';
import ui from '../ui.js';
import scoringEngineModule from '../core/scoring-engine.js';

const fieldEditor = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    /**
     * Reference to the scoring engine update function
     * @type {Function}
     * @private
     */
    const updateSEOScore = scoringEngineModule.updateSEOScore;

    /* =========================================================================
       HELPER FUNCTIONS
       ========================================================================= */

    /**
     * Creates a safe DOM element with text content.
     *
     * Prevents XSS by using jQuery's text() method instead of html().
     * Used for displaying user-facing messages in the UI.
     *
     * @since 1.0.0
     * @access private
     *
     * @param {string} tag - HTML tag name (e.g., 'span', 'div').
     * @param {string} className - CSS class name(s) to apply.
     * @param {string} content - Text content to display (will be escaped).
     * @returns {jQuery} The created jQuery element.
     */
    function safeCreateElement(tag, className, content) {
        const $element = $('<' + tag + '>').addClass(className);
        if (content) {
            $element.text(content);
        }
        return $element;
    }

    /* =========================================================================
       EVENT HANDLERS
       ========================================================================= */

    /**
     * Handles manual updates to image metadata fields.
     *
     * Triggered when user edits alt text, title, or filename inline.
     * Performs the following workflow:
     * 1. Validates that value has changed
     * 2. Shows loading spinner in actions cell
     * 3. Sanitizes filename if applicable
     * 4. Sends AJAX request to update database
     * 5. Updates SEO score if returned
     * 6. Shows success/error feedback
     * 7. Restores original value on error
     *
     * @since 1.0.0
     * @access private
     *
     * @param {Event} e - Input blur or change event from the field.
     * @returns {void}
     */
    function handleUpdate(e) {
        const inputField = $(e.target);
        const originalValue = inputField.data('original-value');
        let value = inputField.val();

        // Skip if value hasn't changed
        if (value === originalValue) return;

        const tr = inputField.closest('tr');
        const actionsCell = tr.find('.column-actions');
        const aiButton = actionsCell.find('.infyp-optimize-ai');

        // Find or create status container
        let statusContainer = actionsCell.find('.infyp-manual-update-status');
        if (!statusContainer.length) {
            statusContainer = $('<div>').addClass('infyp-manual-update-status');
            if (aiButton.length) {
                aiButton.parent().append(statusContainer);
            } else {
                actionsCell.append(statusContainer);
            }
        }

        // Show loading spinner
        statusContainer.html('<span class="spinner is-active infyp-spinner-left"></span>');
        inputField.prop('disabled', true);

        // Get field name and determine AJAX action
        const field = inputField.data('field');
        let action = 'infyp_update_image_data';

        // Special handling for filename field
        if (field === 'filename') {
            action = 'infyp_update_filename';
            // Strip known image extensions before sanitizing
            value = value.replace(/\.(jpe?g|png|webp|gif|bmp|tiff?|svg|avif|heic|heif)$/i, '');
            // Sanitize filename: lowercase, replace spaces with hyphens, remove special chars
            value = value.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-_]/g, '');
            inputField.val(value);
        }

        // Send AJAX request to update field
        api.post(action, {
            image_id: tr.data('id'),
            field: field,
            value: value
        }).done(function(response) {
            if (response.success) {
                // Show success message
                const successMsg = safeCreateElement('span', 'infyp-log-entry success', infyp_vars.i18n.optimization_successful);
                statusContainer.html(successMsg);

                // Update field value (handle filename changes from server)
                const newValue = (response.data && response.data.new_filename) ? response.data.new_filename : value;
                inputField.val(newValue).data('original-value', newValue);

                // Update SEO score if returned
                if (response.data && response.data.seo_score) {
                    updateSEOScore(tr.data('id'), response.data.seo_score);
                }
            } else {
                // Show error message
                const message = response.data.message || infyp_vars.i18n.unknown_error;
                const errorMsg = safeCreateElement('span', 'infyp-log-entry error', message);
                statusContainer.html(errorMsg);
                inputField.val(originalValue);
            }
        }).fail(() => {
            // Show server error message
            const errorMsg = safeCreateElement('span', 'infyp-log-entry error', infyp_vars.i18n.server_error);
            statusContainer.html(errorMsg);
            inputField.val(originalValue);
        }).always(() => {
            // Re-enable field and hide status after delay
            inputField.prop('disabled', false);
            setTimeout(() => statusContainer.fadeOut(500, () => statusContainer.empty().show()), 2500);
        });
    }

    /* =========================================================================
       INITIALIZATION
       ========================================================================= */

    /**
     * Initializes field editor event handlers.
     *
     * Attaches change event handlers to editable fields in the WordPress list table.
     * Should be called on page load and after table updates (pagination, filtering, AJAX).
     * Removes old handlers before attaching new ones to prevent duplicate bindings.
     *
     * @since 1.0.0
     * @access public
     *
     * @returns {void}
     */
    function init() {
        const table = $('.wp-list-table');

        if (table.length) {
            // Remove old handlers to prevent duplicates
            table.off('change', '.infyp-editable-field:not(.infyp-alt-text-trigger):not(.infyp-filename-trigger):not(.infyp-title-trigger)');

            // Attach change handler to editable fields
            table.on('change', '.infyp-editable-field:not(.infyp-alt-text-trigger):not(.infyp-filename-trigger):not(.infyp-title-trigger)', handleUpdate);
        }
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the field editor module.
     *
     * @since 1.0.0
     *
     * @returns {Object} Public methods.
     * @property {Function} init - Initialize field editor event handlers.
     * @property {Function} handleUpdate - Handle field update event (can be called programmatically).
     */
    return {
        init,
        handleUpdate
    };

})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = fieldEditor;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.fieldEditor = fieldEditor;
}

export default fieldEditor;
