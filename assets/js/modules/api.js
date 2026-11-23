/**
 * API Module - Handles all AJAX communications with WordPress backend.
 *
 * This module provides a centralized interface for making AJAX requests
 * to the WordPress backend. It handles nonce security tokens automatically
 * and provides methods for all plugin API operations.
 *
 * ## Features
 *
 * - Automatic nonce handling for WordPress security
 * - Centralized AJAX URL resolution (admin vs frontend)
 * - Promise-based API for async operations
 * - Methods for all plugin settings and data operations
 *
 * ## Usage
 *
 * ```javascript
 * import api from './api.js';
 *
 * // Generic POST request
 * api.post('infyp_custom_action', { key: 'value' })
 *     .done(response => console.log(response))
 *     .fail(error => console.error(error));
 *
 * // Save API keys
 * api.saveApiKeys($('#api-form').serialize())
 *     .done(response => console.log('Saved!'));
 * ```
 *
 * @file Handles all AJAX communications with WordPress backend.
 * @author InfyPress
 * @since 1.0.0
 * @module api
 * @requires jQuery
 */
const api = (function($) {
    'use strict';

    /* =========================================================================
       AJAX OPERATIONS
       ========================================================================= */

    /**
     * Generic POST request handler for AJAX operations.
     *
     * Automatically includes the security nonce and resolves the correct
     * AJAX URL based on the WordPress environment (admin vs frontend).
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} action - WordPress AJAX action name (e.g., 'infyp_save_settings').
     * @param {Object} data - Data to send with the request.
     *
     * @return {jQuery.Promise} jQuery promise for the AJAX request.
     *
     * @example
     * api.post('infyp_optimize_image', { image_id: 123 })
     *     .done(response => {
     *         if (response.success) {
     *             console.log('Image optimized:', response.data);
     *         }
     *     });
     */
    function post(action, data) {
        const ajaxData = {
            action: action,
            nonce: infyp_vars.nonce,  // Security nonce from localized script data
            ...data
        };
        // Use global ajaxurl if available (WordPress admin), otherwise use localized URL
        const url = typeof ajaxurl !== 'undefined' ? ajaxurl : infyp_vars.ajax_url;
        return $.post(url, ajaxData);
    }

    /* =========================================================================
       SETTINGS OPERATIONS
       ========================================================================= */

    /**
     * Save API keys (OpenAI, Anthropic, Google Gemini).
     *
     * Handles both individual key saves and bulk saves. The form data
     * should contain the API keys in the expected field names.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} serializedFormData - Serialized form data containing API keys.
     *
     * @return {jQuery.Promise} Promise resolving to save response.
     *
     * @example
     * api.saveApiKeys($('#api-keys-form').serialize())
     *     .done(response => {
     *         if (response.success) {
     *             alert('API keys saved!');
     *         }
     *     });
     */
    function saveApiKeys(serializedFormData) {
        const data = serializedFormData + '&action=infyp_save_api_keys&nonce=' + infyp_vars.nonce;
        const url = typeof ajaxurl !== 'undefined' ? ajaxurl : infyp_vars.ajax_url;
        return $.post(url, data);
    }

    /**
     * Save AI prompt settings (language, custom prompts, etc.).
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} serializedFormData - Serialized form data with prompt settings.
     *
     * @return {jQuery.Promise} Promise resolving to save response.
     */
    function savePromptSettings(serializedFormData) {
        const data = serializedFormData + '&action=infyp_save_prompt_settings&nonce=' + infyp_vars.nonce;
        const url = typeof ajaxurl !== 'undefined' ? ajaxurl : infyp_vars.ajax_url;
        return $.post(url, data);
    }

    /**
     * Save post type settings (which post types to enable for image optimization).
     *
     * @since 1.0.0
     * @access public
     *
     * @param {Object} formData - Object containing post type settings.
     *
     * @return {jQuery.Promise} Promise resolving to save response.
     */
    function savePostTypeSettings(formData) {
        return post('infyp_save_post_type_settings', formData);
    }

    /* =========================================================================
       IMAGE DATA OPERATIONS
       ========================================================================= */

    /**
     * Retrieve image metadata and optimization status.
     *
     * Used to populate image details in modals and UI components.
     * Returns alt text, title, filename, keywords, and optimization status.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {number} imageId - WordPress attachment ID.
     * @param {Function} callback - Callback function to handle the response.
     *
     * @example
     * api.getImageData(123, function(response) {
     *     if (response.success) {
     *         console.log('Alt text:', response.data.alt_text);
     *         console.log('Title:', response.data.title);
     *     }
     * });
     */
    function getImageData(imageId, callback) {
        const data = {
            action: 'infyp_get_image_data',
            image_id: imageId,
            nonce: infyp_vars.nonce
        };
        jQuery.post(infyp_vars.ajax_url, data, callback);
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the API module.
     *
     * @return {Object} Public methods.
     * @property {Function} post - Generic POST request handler.
     * @property {Function} saveApiKeys - Save API keys.
     * @property {Function} savePromptSettings - Save prompt settings.
     * @property {Function} savePostTypeSettings - Save post type settings.
     * @property {Function} getImageData - Retrieve image metadata.
     */
    return {
        post,
        saveApiKeys,
        savePromptSettings,
        savePostTypeSettings,
        getImageData
    };
})(jQuery);

export default api;