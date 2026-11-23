/**
 * UI Module - Handles all UI interactions and visual feedback.
 *
 * This module manages DOM elements, status messages, notifications, and provides
 * security utilities for handling user-generated content. It centralizes all
 * UI-related operations including element caching, XSS prevention, and status
 * message display with WordPress-style notifications.
 *
 * ## Features
 *
 * - Centralized DOM element caching for performance
 * - XSS prevention utilities (HTML escaping and sanitization)
 * - Global and local status message display
 * - API key validation UI
 * - Safe element creation methods
 *
 * ## Usage
 *
 * ```javascript
 * import ui from './ui.js';
 *
 * // Show global status message
 * ui.showGlobalStatus('Settings saved!', false, 3000);
 *
 * // Escape user input
 * const safeText = ui.escapeHtml(userInput);
 *
 * // Check API key notice
 * ui.checkApiKeyNotice();
 * ```
 *
 * @file Handles all UI interactions and visual feedback.
 * @author InfyPress
 * @since 1.0.0
 * @module ui
 * @requires jQuery
 */
const ui = (function($) {
    'use strict';

    /* =========================================================================
       ELEMENT SELECTORS
       ========================================================================= */

    /**
     * CSS selectors for all UI elements.
     *
     * Centralized selector management for easier maintenance and consistent
     * element access across the module.
     *
     * @type {Object}
     * @private
     */
    const selectors = {
        globalStatus: '#infyp-global-status',        // Global status messages (top of page)
        apiKeyNotice: '#infyp-api-key-notice',      // API key configuration notices
        promptSaveStatus: '#infyp-prompt-save-status', // Prompt settings save feedback
    };

    /* =========================================================================
       CACHED ELEMENTS
       ========================================================================= */

    /**
     * Cached jQuery elements for performance optimization.
     *
     * Prevents repeated DOM queries by storing frequently accessed elements.
     * Elements are cached on module load for faster subsequent access.
     *
     * @type {Object}
     * @private
     */
    const elements = {
        globalStatus: $(selectors.globalStatus),
        apiKeyNotice: $(selectors.apiKeyNotice),
        promptSaveStatus: $(selectors.promptSaveStatus),
    };

    /* =========================================================================
       SECURITY UTILITIES
       ========================================================================= */

    /**
     * Escapes HTML special characters to prevent XSS attacks.
     *
     * Converts potentially dangerous characters to HTML entities, making
     * user-supplied text safe for insertion into HTML context. This is
     * essential for preventing cross-site scripting vulnerabilities.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} text - Text to escape.
     *
     * @return {string} Escaped HTML-safe text.
     */
    function escapeHtml(text) {
        if (typeof text !== 'string') return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
            '/': '&#x2F;',
            '`': '&#x60;',
            '=': '&#x3D;'
        };
        return text.replace(/[&<>"'`=\/]/g, function(char) {
            return map[char];
        });
    }

    /**
     * Safely creates DOM elements with jQuery.
     *
     * Creates elements with proper escaping to prevent XSS vulnerabilities.
     * Uses jQuery's text() method instead of html() to ensure content is
     * treated as plain text rather than HTML.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} tag - HTML tag name (e.g., 'div', 'span').
     * @param {string} className - CSS class names to add.
     * @param {string} textContent - Text content (will be escaped).
     *
     * @return {jQuery} Created jQuery element.
     */
    function safeCreateElement(tag, className, textContent) {
        const $element = $('<' + tag + '>');
        if (className) {
            $element.addClass(className);
        }
        if (textContent) {
            $element.text(textContent);  // Uses text() for safety
        }
        return $element;
    }

    /**
     * Sanitizes HTML content by removing dangerous elements and attributes.
     *
     * Parses HTML and removes potentially harmful elements (script, style, iframe)
     * and attributes (event handlers, javascript: URLs). This provides defense
     * against XSS attacks when displaying user-generated HTML content.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} html - HTML string to sanitize.
     *
     * @return {string} Sanitized HTML safe for display.
     */
    function sanitizeHtml(html) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        
        // Remove dangerous tags completely
        const dangerousTags = doc.querySelectorAll('script,style,iframe,object,embed,form');
        dangerousTags.forEach(el => el.remove());
        
        // Remove dangerous attributes (event handlers, javascript: URLs)
        const allElements = doc.body.querySelectorAll('*');
        allElements.forEach(el => {
            Array.from(el.attributes).forEach(attr => {
                if (attr.name.startsWith('on') || attr.name === 'href' && attr.value.startsWith('javascript:')) {
                    el.removeAttribute(attr.name);
                }
            });
        });
        return doc.body.innerHTML;
    }

    /* =========================================================================
       STATUS MESSAGES
       ========================================================================= */

    /**
     * Displays global status message at the top of the admin page.
     *
     * Shows WordPress-style success or error messages with appropriate icons.
     * Messages can auto-hide after a specified duration or remain visible
     * until manually dismissed.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {string} message - Message to display.
     * @param {boolean} isError - Whether this is an error message.
     * @param {number} duration - How long to show message in milliseconds (0 = permanent).
     *
     * @return {void}
     */
    function showGlobalStatus(message, isError = false, duration = 3000) {
        const icon = isError ? 'no' : 'yes-alt';  // WordPress dashicon names
        const statusClass = isError ? 'infyp-status-error' : 'infyp-status-success';
        
        elements.globalStatus.empty();
        const iconSpan = $('<span>').addClass('dashicons dashicons-' + icon + ' ' + statusClass);
        const messageSpan = $('<span>').text(' ' + message);
        elements.globalStatus.append(iconSpan, messageSpan).show();
        
        // Auto-hide after duration (unless duration is 0)
        if (duration > 0) {
            setTimeout(() => elements.globalStatus.fadeOut(), duration);
        }
    }

    /**
     * Displays local status message next to a specific element.
     *
     * Shows inline feedback messages typically next to form elements or buttons.
     * Messages auto-hide after 3 seconds. Useful for contextual feedback without
     * disrupting the overall page layout.
     *
     * @since 1.0.0
     * @access public
     *
     * @param {jQuery} el - jQuery element to show status in.
     * @param {string} message - Status message to display.
     * @param {boolean} isSuccess - Whether operation was successful.
     *
     * @return {void}
     */
    function showLocalStatus(el, message, isSuccess) {
        const icon = isSuccess ? 'yes-alt' : 'no';
        const statusClass = isSuccess ? 'infyp-status-success' : 'infyp-status-error';
        
        el.empty();
        const iconSpan = $('<span>').addClass('dashicons dashicons-' + icon + ' infyp-status-icon ' + statusClass);
        const messageSpan = $('<span>').addClass('infyp-status-message').text(' ' + message);
        el.append(iconSpan, messageSpan);
        
        // Auto-hide and reset after 3 seconds
        setTimeout(() => {
            el.fadeOut(500, function() {
                $(this).empty().show();
            });
        }, 3000);
    }

    /* =========================================================================
       API KEY VALIDATION
       ========================================================================= */

    /**
     * Checks if selected AI model has corresponding API key configured.
     *
     * Validates that an API key exists for the currently selected AI model
     * provider (OpenAI, Anthropic, or Google Gemini). Shows a dismissible
     * warning notice with a link to the API keys page if the key is missing.
     * Called when user changes AI model selection.
     *
     * @since 1.0.0
     * @access public
     *
     * @return {void}
     */
    function checkApiKeyNotice() {
        const selectedValue = $('#infyp_ai_model_accordion').val();
        let provider = '';

        if (!selectedValue) return;

        // Determine provider from model name
        if (selectedValue.startsWith('gemini')) provider = 'google';
        else if (selectedValue.startsWith('gpt')) provider = 'openai';
        else if (selectedValue.startsWith('claude')) provider = 'anthropic';

        // WordPress AI Connectors mode: plugin keys are irrelevant, but the
        // selected model's provider may have no connector — then core will
        // silently route the request to another connected provider's model.
        // Surface that in real time instead of staying quiet (1.0.6).
        if (infyp_vars.connection_method === 'wp_ai') {
            const wpAi = infyp_vars.wp_ai || {};
            const connected = wpAi.connected_providers || [];
            const labels = { google: 'Google Gemini', anthropic: 'Anthropic Claude', openai: 'OpenAI' };

            if (provider && connected.length && connected.indexOf(provider) === -1) {
                const mismatchMsg = (infyp_vars.i18n.wp_ai_provider_missing ||
                    'No %s connector is configured in WordPress. Requests for this model will be answered by another connected provider\'s model instead.')
                    .replace('%s', labels[provider] || provider);
                const noticeContainer = $('<p>');
                const strong = $('<strong>').text(infyp_vars.i18n.heads_up || 'Heads-up:');
                const link = $('<a>').attr('href', wpAi.connectors_url || '#').text(infyp_vars.i18n.manage_connectors || 'Manage connectors');
                noticeContainer.append(strong, ' ', mismatchMsg + ' ', link, '.');

                const dismissText = infyp_vars.i18n.dismiss_notice || 'Dismiss this notice.';
                const dismissButton = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">' + dismissText + '</span></button>');
                elements.apiKeyNotice.empty().append(noticeContainer).append(dismissButton).slideDown('fast');
                dismissButton.on('click', function() {
                    elements.apiKeyNotice.slideUp('fast');
                });
            } else {
                elements.apiKeyNotice.slideUp('fast');
            }
            return;
        }

        // Show notice if API key is missing for selected provider
        if (provider && infyp_vars.api_key_status[provider] === false) {
            const noticeContainer = $('<p>');
            const strong = $('<strong>').text(infyp_vars.i18n.action_required);
            const link = $('<a>').attr('href', infyp_vars.api_keys_url).text(infyp_vars.i18n.set_api_key_link);
            noticeContainer.append(strong, ' ', infyp_vars.i18n.set_api_key_message + ' ', link, '.');
            
            // Add dismiss button for dismissible notices
            const dismissText = infyp_vars.i18n.dismiss_notice || 'Dismiss this notice.';
            const dismissButton = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">' + dismissText + '</span></button>');
            
            elements.apiKeyNotice.empty().append(noticeContainer).append(dismissButton).slideDown('fast');
            
            // Handle dismiss click
            dismissButton.on('click', function() {
                elements.apiKeyNotice.slideUp('fast');
            });
        } else {
            elements.apiKeyNotice.slideUp('fast');
        }
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the UI module.
     *
     * @return {Object} Public methods and properties.
     * @property {Object} selectors - DOM selectors for external access.
     * @property {Object} elements - Cached jQuery elements.
     * @property {Function} escapeHtml - XSS prevention utility.
     * @property {Function} sanitizeHtml - HTML sanitization utility.
     * @property {Function} safeCreateElement - Safe element creation.
     * @property {Function} showGlobalStatus - Display global notifications.
     * @property {Function} showLocalStatus - Display inline status messages.
     * @property {Function} checkApiKeyNotice - API key validation UI.
     */
    return {
        selectors,           // DOM selectors for external access
        elements,           // Cached jQuery elements
        escapeHtml,         // XSS prevention utility
        sanitizeHtml,       // HTML sanitization utility
        safeCreateElement,  // Safe element creation
        showGlobalStatus,   // Global notifications
        showLocalStatus,    // Inline status messages
        checkApiKeyNotice   // API key validation UI
    };
})(jQuery);

export default ui;