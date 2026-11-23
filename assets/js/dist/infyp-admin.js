/* infyp-admin - Main admin bundle with all modules */
(function () {
    'use strict';

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

    /**
     * Configuration Module - SEO Scoring Parameters and Weights
     *
     * This module manages all configuration-related functions for SEO scoring,
     * including parameter caching and weight distribution for optimal performance.
     * Provides centralized access to scoring parameters used across the plugin.
     *
     * ## Features
     *
     * - SEO scoring parameter retrieval (alt, filename, title limits)
     * - Weight distribution configuration for score calculation
     * - Caching for performance optimization
     * - Fallback defaults when server data unavailable
     *
     * ## Dependencies
     *
     * - infyp_vars global (optional, provides server-side configuration)
     *
     * @file Configuration Module
     * @author InfyPress
     * @since 1.0.0
     * @module core/config
     */

    /**
     * Export as ES6 module with WordPress compatibility fallback
     * Note: This module does not require jQuery
     */
    const config = (function() {

        // Cache for performance optimization
        let cachedParams = null;  // SEO scoring parameters
        let cachedWeights = null; // SEO score weight distribution

        /**
         * Get SEO scoring parameters for field validation
         *
         * Defines min/max character and word counts for optimal SEO.
         * Uses server-provided values from infyp_vars when available,
         * falling back to sensible defaults.
         *
         * @since 1.0.0
         * @access public
         * @returns {Object} Scoring parameters for alt, filename, and title
         * @returns {Object} return.alt - Alt text parameters (min/max chars/words)
         * @returns {Object} return.filename - Filename parameters (min/max chars/words)
         * @returns {Object} return.title - Title parameters (min/max chars/words)
         */
        function getScoringParams() {
            if (!cachedParams) {
                if (typeof infyp_vars !== 'undefined' && infyp_vars.scoring_parameters) {
                    cachedParams = {
                        alt: infyp_vars.scoring_parameters.alt_text || infyp_vars.scoring_parameters.alt || {
                            min_chars: 50,
                            max_chars: 125,
                            min_words: 10,
                            max_words: 16
                        },
                        filename: infyp_vars.scoring_parameters.filename || {
                            min_chars: 40,
                            max_chars: 60,
                            min_words: 3,
                            max_words: 6
                        },
                        title: infyp_vars.scoring_parameters.title || {
                            min_chars: 40,
                            max_chars: 60,
                            min_words: 6,
                            max_words: 10
                        }
                    };
                } else {
                    // Fallback defaults if infyp_vars is not available
                    cachedParams = {
                        alt: {
                            min_chars: 50,
                            max_chars: 125,
                            min_words: 10,
                            max_words: 16
                        },
                        filename: {
                            min_chars: 40,
                            max_chars: 60,
                            min_words: 3,
                            max_words: 6
                        },
                        title: {
                            min_chars: 40,
                            max_chars: 60,
                            min_words: 6,
                            max_words: 10
                        }
                    };
                }
            }
            return cachedParams;
        }

        /**
         * Get alt text parameters
         *
         * Helper function to consistently retrieve alt text parameters.
         *
         * @since 1.0.0
         * @access public
         * @returns {Object} Alt text parameters with min/max values
         * @returns {number} return.min_chars - Minimum character count
         * @returns {number} return.max_chars - Maximum character count
         * @returns {number} return.min_words - Minimum word count
         * @returns {number} return.max_words - Maximum word count
         */
        function getAltParams() {
            const params = getScoringParams();
            return params.alt;
        }

        /**
         * Get filename parameters
         *
         * Helper function to consistently retrieve filename parameters.
         *
         * @since 1.0.0
         * @access public
         * @returns {Object} Filename parameters with min/max values
         * @returns {number} return.min_chars - Minimum character count
         * @returns {number} return.max_chars - Maximum character count
         * @returns {number} return.min_words - Minimum word count
         * @returns {number} return.max_words - Maximum word count
         */
        function getFilenameParams() {
            const params = getScoringParams();
            return params.filename;
        }

        /**
         * Get title parameters
         *
         * Helper function to consistently retrieve title parameters.
         *
         * @since 1.0.0
         * @access public
         * @returns {Object} Title parameters with min/max values
         * @returns {number} return.min_chars - Minimum character count
         * @returns {number} return.max_chars - Maximum character count
         * @returns {number} return.min_words - Minimum word count
         * @returns {number} return.max_words - Maximum word count
         */
        function getTitleParams() {
            const params = getScoringParams();
            return params.title;
        }

        /**
         * Get SEO score weight distribution
         *
         * Defines how much each factor contributes to the total SEO score.
         * Total of all weights should equal 100.
         *
         * @since 1.0.0
         * @access public
         * @returns {Object} Weight values for each scoring factor (total = 100)
         * @returns {number} return.alt_presence - Weight for alt text existence (20)
         * @returns {number} return.alt_quality - Weight for alt text quality (15)
         * @returns {number} return.title_presence - Weight for title existence (15)
         * @returns {number} return.title_quality - Weight for title quality (10)
         * @returns {number} return.filename_quality - Weight for filename SEO-friendliness (15)
         * @returns {number} return.keyword_density - Weight for keyword usage (15)
         * @returns {number} return.uniqueness - Weight for content uniqueness (10)
         */
        function getWeights() {
            if (!cachedWeights) {
                cachedWeights = (typeof infyp_vars !== 'undefined' && infyp_vars.seo_weights) || {
                    alt_presence: 20,      // Alt text exists
                    alt_quality: 15,       // Alt text meets quality criteria
                    title_presence: 15,    // Title exists
                    title_quality: 10,     // Title meets quality criteria
                    filename_quality: 15,  // Filename is SEO-friendly
                    keyword_density: 15,   // Appropriate keyword usage
                    uniqueness: 10         // Content is unique
                };
            }
            return cachedWeights;
        }

        /**
         * Clear cached parameters and weights
         *
         * Useful for testing or when configuration changes. Forces
         * parameters to be re-read from infyp_vars on next access.
         *
         * @since 1.0.0
         * @access public
         * @returns {void}
         */
        function clearCache() {
            cachedParams = null;
            cachedWeights = null;
        }

        // Public API
        return {
            getScoringParams,
            getAltParams,
            getFilenameParams,
            getTitleParams,
            getWeights,
            clearCache
        };

    })();

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = config;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.config = config;
    }

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


    /**
     * Export as ES6 module with WordPress compatibility fallback
     */
    const scoringEngine = (function($) {

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

    /**
     * Event Manager Module - Centralized Event Handling
     *
     * This module provides a centralized system for managing event listeners
     * with namespace support, preventing memory leaks and providing clean
     * cleanup mechanisms for dynamic content.
     *
     * Features:
     * - Event handler registry with namespaces
     * - Automatic cleanup on destroy
     * - Prevents duplicate event bindings
     * - Memory leak prevention
     *
     * @file Centralized event handling with namespace support and memory leak prevention.
     * @author InfyPress
     * @since 1.0.0
     * @module core/event-manager
     * @requires jQuery
     */

    /**
     * Export as ES6 module with WordPress compatibility fallback
     */
    const eventManager = (function($) {

        /**
         * Registry to track all bound event handlers
         * Structure: { namespace: [{ element, event, handler, selector }] }
         */
        const eventRegistry = {};

        /**
         * Bind an event with namespace tracking
         *
         * @param {jQuery|HTMLElement|string} element - Element or selector to bind event to
         * @param {string} event - Event name (e.g., 'click', 'input', 'change')
         * @param {string} [selector] - Optional selector for event delegation
         * @param {Function} handler - Event handler function
         * @param {string} [namespace='default'] - Namespace for event grouping
         * @returns {jQuery} jQuery object for chaining
         *
         * @example
         * // Direct binding
         * eventManager.on('.my-button', 'click', handleClick, 'myFeature');
         *
         * @example
         * // Delegated binding
         * eventManager.on(document, 'click', '.dynamic-button', handleClick, 'myFeature');
         */
        function on(element, event, selector, handler, namespace = 'default') {
            // Handle optional selector parameter
            if (typeof selector === 'function') {
                namespace = handler || 'default';
                handler = selector;
                selector = null;
            }

            const $element = $(element);

            // Bind the event
            if (selector) {
                $element.on(event, selector, handler);
            } else {
                $element.on(event, handler);
            }

            // Register the binding
            if (!eventRegistry[namespace]) {
                eventRegistry[namespace] = [];
            }

            eventRegistry[namespace].push({
                element: $element,
                event: event,
                handler: handler,
                selector: selector
            });

            return $element;
        }

        /**
         * Bind a one-time event with namespace tracking
         *
         * @param {jQuery|HTMLElement|string} element - Element or selector to bind event to
         * @param {string} event - Event name
         * @param {string} [selector] - Optional selector for event delegation
         * @param {Function} handler - Event handler function
         * @param {string} [namespace='default'] - Namespace for event grouping
         * @returns {jQuery} jQuery object for chaining
         */
        function one(element, event, selector, handler, namespace = 'default') {
            // Handle optional selector parameter
            if (typeof selector === 'function') {
                namespace = handler || 'default';
                handler = selector;
                selector = null;
            }

            const $element = $(element);

            // Create wrapped handler that removes from registry after execution
            const wrappedHandler = function(...args) {
                handler.apply(this, args);
                // Remove from registry after execution
                if (eventRegistry[namespace]) {
                    eventRegistry[namespace] = eventRegistry[namespace].filter(binding =>
                        binding.element[0] !== $element[0] ||
                        binding.event !== event ||
                        binding.handler !== wrappedHandler
                    );
                }
            };

            // Bind the event
            if (selector) {
                $element.one(event, selector, wrappedHandler);
            } else {
                $element.one(event, wrappedHandler);
            }

            // Register the binding
            if (!eventRegistry[namespace]) {
                eventRegistry[namespace] = [];
            }

            eventRegistry[namespace].push({
                element: $element,
                event: event,
                handler: wrappedHandler,
                selector: selector
            });

            return $element;
        }

        /**
         * Unbind events by namespace
         *
         * @param {string} namespace - Namespace to unbind (unbinds all events in this namespace)
         * @returns {number} Number of event bindings removed
         *
         * @example
         * eventManager.off('myFeature'); // Removes all events in 'myFeature' namespace
         */
        function off(namespace) {
            if (!eventRegistry[namespace]) {
                return 0;
            }

            let count = 0;
            eventRegistry[namespace].forEach(binding => {
                if (binding.selector) {
                    binding.element.off(binding.event, binding.selector, binding.handler);
                } else {
                    binding.element.off(binding.event, binding.handler);
                }
                count++;
            });

            delete eventRegistry[namespace];
            return count;
        }

        /**
         * Unbind a specific event handler
         *
         * @param {jQuery|HTMLElement|string} element - Element to unbind from
         * @param {string} event - Event name
         * @param {Function} handler - Handler function to remove
         * @param {string} [namespace='default'] - Namespace to search in
         * @returns {boolean} True if handler was found and removed
         */
        function offHandler(element, event, handler, namespace = 'default') {
            if (!eventRegistry[namespace]) {
                return false;
            }

            const $element = $(element);
            let removed = false;

            eventRegistry[namespace] = eventRegistry[namespace].filter(binding => {
                const matches = binding.element[0] === $element[0] &&
                              binding.event === event &&
                              binding.handler === handler;

                if (matches) {
                    if (binding.selector) {
                        binding.element.off(binding.event, binding.selector, binding.handler);
                    } else {
                        binding.element.off(binding.event, binding.handler);
                    }
                    removed = true;
                    return false; // Remove from registry
                }
                return true; // Keep in registry
            });

            return removed;
        }

        /**
         * Clean up all event handlers
         * Useful for preventing memory leaks when destroying components
         *
         * @returns {number} Total number of event bindings removed
         *
         * @example
         * eventManager.destroy(); // Removes all tracked events
         */
        function destroy() {
            let totalCount = 0;

            Object.keys(eventRegistry).forEach(namespace => {
                eventRegistry[namespace].forEach(binding => {
                    if (binding.selector) {
                        binding.element.off(binding.event, binding.selector, binding.handler);
                    } else {
                        binding.element.off(binding.event, binding.handler);
                    }
                    totalCount++;
                });
            });

            // Clear registry
            Object.keys(eventRegistry).forEach(key => delete eventRegistry[key]);

            return totalCount;
        }

        /**
         * Get information about registered events
         *
         * @param {string} [namespace] - Optional namespace to inspect
         * @returns {Object|Array} Registry info for namespace or all namespaces
         */
        function getRegistry(namespace) {
            if (namespace) {
                return eventRegistry[namespace] || [];
            }
            return { ...eventRegistry };
        }

        /**
         * Check if a namespace has any registered events
         *
         * @param {string} namespace - Namespace to check
         * @returns {boolean} True if namespace has registered events
         */
        function hasEvents(namespace) {
            return !!(eventRegistry[namespace] && eventRegistry[namespace].length > 0);
        }

        /**
         * Get count of registered events
         *
         * @param {string} [namespace] - Optional namespace to count
         * @returns {number} Count of registered events
         */
        function count(namespace) {
            if (namespace) {
                return eventRegistry[namespace] ? eventRegistry[namespace].length : 0;
            }

            return Object.values(eventRegistry).reduce((total, bindings) => total + bindings.length, 0);
        }

        // Public API
        return {
            on,
            one,
            off,
            offHandler,
            destroy,
            getRegistry,
            hasEvents,
            count
        };

    })(jQuery);

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = eventManager;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.eventManager = eventManager;
    }

    /**
     * Loading States Module - Centralized loading/spinner management
     *
     * This module provides a unified interface for managing loading spinners
     * and visual loading states throughout the plugin. It uses WordPress native
     * spinners and MutationObserver for automatic cleanup when modals appear.
     *
     * ## Features
     *
     * - Field-level loading spinners (input fields)
     * - Keywords wrapper loading state
     * - Button loading spinners (actions)
     * - Table overlay for bulk operations
     * - Auto-cleanup via MutationObserver
     * - Fallback timeout cleanup (5 seconds)
     *
     * ## CSS Classes Used
     *
     * | Class                    | Purpose                          |
     * |--------------------------|----------------------------------|
     * | infyp-wrapper-loading  | Hides children, shows spinner    |
     * | infyp-field-spinner    | The actual spinner element       |
     * | infyp-table-overlay    | Full table loading overlay       |
     *
     * ## Dependencies
     *
     * - jQuery
     * - WordPress admin CSS (for .spinner class)
     *
     * ## Usage
     *
     * ```javascript
     * import loadingStates from './ui/loading-states.js';
     *
     * // Show spinner on input, cleanup when modal appears
     * loadingStates.showFieldSpinner($input, '#infyp-alt-text-lightbox');
     *
     * // Show table overlay during AJAX
     * loadingStates.showTableOverlay($table);
     * loadingStates.hideTableOverlay();
     * ```
     *
     * @module ui/loading-states
     * @requires jQuery
     */

    const loadingStates = (function($) {

        /* =========================================================================
           CONFIGURATION
           ========================================================================= */

        /**
         * Fallback timeout duration for spinner cleanup
         * Ensures spinners don't persist indefinitely if modal fails to load
         * @constant {number}
         */
        const TIMEOUT_DURATION = 5000; // 5 seconds fallback timeout

        /* =========================================================================
           HELPER FUNCTIONS
           ========================================================================= */

        /**
         * Create a WordPress-style spinner element
         *
         * Creates an activated spinner using WordPress admin CSS classes.
         *
         * @returns {jQuery} The spinner jQuery element
         * @private
         */
        function createSpinner() {
            return $('<span class="spinner is-active infyp-field-spinner"></span>');
        }

        /**
         * Setup MutationObserver to watch for modal appearance
         *
         * Creates an observer that calls the cleanup function when the specified
         * modal element appears in the DOM. Includes a fallback timeout.
         *
         * @param {string} modalSelector - CSS selector for the modal to watch for
         * @param {Function} cleanup - Cleanup function to call when modal appears
         * @returns {MutationObserver} The observer instance (for manual disconnection if needed)
         * @private
         */
        function setupModalObserver(modalSelector, cleanup) {
            var timeoutId;

            const observer = new MutationObserver(function(mutations) {
                if ($(modalSelector).length > 0) {
                    clearTimeout(timeoutId);
                    cleanup();
                    observer.disconnect();
                }
            });

            observer.observe(document.body, { childList: true, subtree: true });

            // Fallback timeout cleanup
            timeoutId = setTimeout(function() {
                cleanup();
                observer.disconnect();
            }, TIMEOUT_DURATION);

            return observer;
        }

        /* =========================================================================
           FIELD SPINNERS
           ========================================================================= */

        /**
         * Show loading spinner on input field while modal is loading
         *
         * Adds loading class to the field wrapper (which hides children via CSS)
         * and appends a spinner. Automatically cleans up when the specified
         * modal appears or after the timeout duration.
         *
         * @param {jQuery} $input - The input field element
         * @param {string} modalSelector - CSS selector for the modal to watch for
         * @returns {void}
         */
        function showFieldSpinner($input, modalSelector) {
            const $wrapper = $input.closest('.infyp-field-wrapper');

            // Add loading class to wrapper - hides all children via CSS
            $wrapper.addClass('infyp-wrapper-loading');

            // Create and insert spinner
            const $spinner = createSpinner();
            $wrapper.append($spinner);

            // Cleanup function
            const cleanup = function() {
                $wrapper.removeClass('infyp-wrapper-loading');
                $spinner.remove();
            };

            setupModalObserver(modalSelector, cleanup);
        }

        /**
         * Show loading spinner on keywords wrapper while modal is loading
         *
         * Similar to showFieldSpinner but targets the keywords wrapper element.
         *
         * @param {jQuery} $trigger - The keywords trigger button
         * @param {string} modalSelector - CSS selector for the modal to watch for
         * @returns {void}
         */
        function showKeywordsSpinner($trigger, modalSelector) {
            const $wrapper = $trigger.closest('.infyp-keywords-wrapper');

            // Add loading class to wrapper
            $wrapper.addClass('infyp-wrapper-loading');

            // Create and insert spinner
            const $spinner = createSpinner();
            $wrapper.append($spinner);

            // Cleanup function
            const cleanup = function() {
                $wrapper.removeClass('infyp-wrapper-loading');
                $spinner.remove();
            };

            setupModalObserver(modalSelector, cleanup);
        }

        /**
         * Show loading spinner on action button while modal is loading
         *
         * Targets the actions wrapper containing the button and shows a spinner.
         *
         * @param {jQuery} $button - The button element
         * @param {string} modalSelector - CSS selector for the modal to watch for
         * @returns {void}
         */
        function showButtonSpinner($button, modalSelector) {
            const $wrapper = $button.closest('.infyp-actions-wrapper');

            // Add loading class to hide all content and show spinner
            $wrapper.addClass('infyp-wrapper-loading');
            const $spinner = createSpinner();
            $wrapper.append($spinner);

            // Cleanup function
            const cleanup = function() {
                $wrapper.removeClass('infyp-wrapper-loading');
                $spinner.remove();
            };

            setupModalObserver(modalSelector, cleanup);
        }

        /* =========================================================================
           TABLE OVERLAY
           ========================================================================= */

        /**
         * Show loading overlay on entire table
         *
         * Creates an absolutely positioned overlay with a centered spinner
         * that covers the entire table during bulk operations or AJAX updates.
         *
         * @param {jQuery} $table - The table element to overlay
         * @returns {void}
         */
        function showTableOverlay($table) {
            const tablePosition = $table.position() || { top: 0, left: 0 };
            const tableWidth = $table.outerWidth() || '100%';
            const tableHeight = $table.outerHeight() || 400;

            // Remove any existing overlay
            $('.infyp-table-overlay').remove();

            // Add loading overlay positioned over the table
            $table.parent().css('position', 'relative');
            $table.parent().append(
                '<div class="infyp-table-overlay" style="position: absolute; top: ' + tablePosition.top + 'px; left: ' + tablePosition.left + 'px; width: ' + tableWidth + 'px; height: ' + tableHeight + 'px; z-index: 100; display: flex; align-items: center; justify-content: center;">' +
                '<span class="spinner is-active" style="float: none;"></span>' +
                '</div>'
            );
        }

        /**
         * Hide and remove the table loading overlay
         *
         * Fades out the overlay then removes it from the DOM.
         *
         * @returns {void}
         */
        function hideTableOverlay() {
            $('.infyp-table-overlay').fadeOut(200, function() {
                $(this).remove();
            });
        }

        /* =========================================================================
           GENERIC WRAPPER SPINNER
           ========================================================================= */

        /**
         * Show loading spinner in a generic wrapper element
         *
         * Flexible spinner function that can be used with any wrapper.
         * Returns the cleanup function for manual control.
         *
         * @param {jQuery} $wrapper - The wrapper element
         * @param {string} [modalSelector] - Optional CSS selector for modal to watch for
         * @returns {Function} Cleanup function to manually remove spinner
         */
        function showWrapperSpinner($wrapper, modalSelector) {
            $wrapper.addClass('infyp-wrapper-loading');
            const $spinner = createSpinner();
            $wrapper.append($spinner);

            let cleaned = false;
            const cleanup = function() {
                if (cleaned) return;
                cleaned = true;
                $wrapper.removeClass('infyp-wrapper-loading');
                $spinner.remove();
            };

            if (modalSelector) {
                setupModalObserver(modalSelector, cleanup);
            }

            return cleanup;
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the loading states module
         *
         * @returns {Object} Public methods
         * @property {Function} showFieldSpinner - Show spinner on input field
         * @property {Function} showKeywordsSpinner - Show spinner on keywords wrapper
         * @property {Function} showButtonSpinner - Show spinner on action button
         * @property {Function} showTableOverlay - Show overlay on entire table
         * @property {Function} hideTableOverlay - Hide and remove table overlay
         * @property {Function} showWrapperSpinner - Show spinner on generic wrapper
         */
        return {
            showFieldSpinner: showFieldSpinner,
            showKeywordsSpinner: showKeywordsSpinner,
            showButtonSpinner: showButtonSpinner,
            showTableOverlay: showTableOverlay,
            hideTableOverlay: hideTableOverlay,
            showWrapperSpinner: showWrapperSpinner
        };

    })(jQuery);

    /**
     * Notifications Module - User feedback and alerts
     *
     * This module provides a toast notification system for displaying user feedback
     * messages. Notifications appear temporarily and automatically fade out.
     * Uses WordPress dashicons for visual type indicators.
     *
     * ## Features
     *
     * - Auto-dismissing toast notifications
     * - Four notification types (success, error, warning, info)
     * - XSS-safe message escaping
     * - Configurable display duration
     * - Fade in/out animations
     *
     * ## Notification Types
     *
     * | Type    | Icon      | Color   | Use Case                    |
     * |---------|-----------|---------|-----------------------------
     * | success | yes-alt   | Green   | Action completed successfully|
     * | error   | dismiss   | Red     | Action failed                |
     * | warning | warning   | Yellow  | Potential issue              |
     * | info    | info      | Blue    | General information          |
     *
     * ## Dependencies
     *
     * - jQuery
     * - WordPress dashicons CSS
     *
     * ## Usage
     *
     * ```javascript
     * import notifications from './ui/notifications.js';
     *
     * // Show notification by type
     * notifications.show('Settings saved!', 'success');
     * notifications.error('Failed to save');
     * notifications.info('Processing...', 5000); // Custom duration
     * ```
     *
     * @module ui/notifications
     * @requires jQuery
     */

    const notifications = (function($) {

        /* =========================================================================
           CONFIGURATION
           ========================================================================= */

        /**
         * Default notification display duration in milliseconds
         * @constant {number}
         */
        const NOTIFICATION_DURATION = 3000;

        /**
         * Fade animation duration in milliseconds
         * @constant {number}
         */
        const FADE_DURATION = 200;

        /* =========================================================================
           HELPER FUNCTIONS
           ========================================================================= */

        /**
         * Escape HTML entities to prevent XSS attacks
         *
         * @param {string} text - Text to escape
         * @returns {string} Escaped text safe for HTML insertion
         * @private
         */
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        /**
         * Get dashicon class name for notification type
         *
         * @param {string} type - Notification type
         * @returns {string} Dashicons class name (without 'dashicons-' prefix)
         * @private
         */
        function getIconClass(type) {
            const icons = {
                'success': 'yes-alt',
                'error': 'dismiss',
                'warning': 'warning',
                'info': 'info'
            };
            return icons[type] || 'info';
        }

        /* =========================================================================
           NOTIFICATION DISPLAY
           ========================================================================= */

        /**
         * Show a toast notification message
         *
         * Creates and displays a notification that automatically fades out.
         * Message is HTML-escaped for security.
         *
         * @param {string} message - Message to display (will be escaped)
         * @param {string} [type='info'] - Type: 'success', 'error', 'info', 'warning'
         * @param {number} [duration=3000] - Display duration in milliseconds
         * @returns {void}
         */
        function show(message, type = 'info', duration = NOTIFICATION_DURATION) {
            const iconClass = getIconClass(type);
            const $notification = $(`
            <div class="infyp-notification infyp-notification-${type}" style="display: flex; align-items: center; gap: 8px; opacity: 0;">
                <span class="dashicons dashicons-${iconClass}"></span>
                <span class="infyp-notification-text">${escapeHtml(message)}</span>
            </div>
        `);

            $('body').append($notification);
            $notification.animate({ opacity: 1 }, FADE_DURATION);

            setTimeout(() => {
                $notification.animate({ opacity: 0 }, FADE_DURATION, function() {
                    $(this).remove();
                });
            }, duration);
        }

        /* =========================================================================
           CONVENIENCE METHODS
           ========================================================================= */

        /**
         * Show success notification (convenience method)
         *
         * @param {string} message - Message to display
         * @param {number} [duration] - Optional display duration in ms
         * @returns {void}
         */
        function success(message, duration) {
            show(message, 'success', duration);
        }

        /**
         * Show error notification (convenience method)
         *
         * @param {string} message - Message to display
         * @param {number} [duration] - Optional display duration in ms
         * @returns {void}
         */
        function error(message, duration) {
            show(message, 'error', duration);
        }

        /**
         * Show info notification (convenience method)
         *
         * @param {string} message - Message to display
         * @param {number} [duration] - Optional display duration in ms
         * @returns {void}
         */
        function info(message, duration) {
            show(message, 'info', duration);
        }

        /**
         * Show warning notification (convenience method)
         *
         * @param {string} message - Message to display
         * @param {number} [duration] - Optional display duration in ms
         * @returns {void}
         */
        function warning(message, duration) {
            show(message, 'warning', duration);
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the notifications module
         *
         * @returns {Object} Public methods
         * @property {Function} show - Show notification with type parameter
         * @property {Function} success - Show success notification
         * @property {Function} error - Show error notification
         * @property {Function} info - Show info notification
         * @property {Function} warning - Show warning notification
         * @property {Function} escapeHtml - Escape HTML for XSS prevention
         */
        return {
            show: show,
            success: success,
            error: error,
            info: info,
            warning: warning,
            escapeHtml: escapeHtml
        };

    })(jQuery);

    /**
     * UI Helpers Module - Shared utility functions
     *
     * This module provides common utility functions used across the plugin's UI.
     * Includes XSS-safe element creation, string formatting, viewport detection,
     * and smooth scrolling utilities.
     *
     * ## Features
     *
     * - XSS-safe DOM element creation
     * - String formatting (sprintf)
     * - Table skeleton loader management
     * - Smooth scroll to element
     * - Debounce function
     * - Viewport detection
     *
     * ## Dependencies
     *
     * - jQuery
     *
     * ## Usage
     *
     * ```javascript
     * import helpers from './ui/helpers.js';
     *
     * // Safe element creation
     * const $el = helpers.safeCreateElement('span', 'badge', userInput);
     *
     * // Debounce for performance
     * const debouncedSearch = helpers.debounce(search, 300);
     *
     * // Show table after load
     * helpers.showTableWhenReady();
     * ```
     *
     * @module ui/helpers
     * @requires jQuery
     */

    const helpers = (function($) {

        /* =========================================================================
           DOM CREATION
           ========================================================================= */

        /**
         * Safely create DOM element with XSS prevention
         *
         * Uses jQuery's text() method to safely escape content,
         * preventing XSS attacks from user-generated input.
         *
         * @param {string} tag - HTML tag name (e.g., 'div', 'span')
         * @param {string} className - CSS class(es) to add
         * @param {string} [content] - Text content (will be escaped)
         * @returns {jQuery} The created jQuery element
         *
         * @example
         * const $badge = safeCreateElement('span', 'user-badge', userInput);
         */
        function safeCreateElement(tag, className, content) {
            const $element = $('<' + tag + '>').addClass(className);
            if (content) {
                $element.text(content);
            }
            return $element;
        }

        /* =========================================================================
           STRING FORMATTING
           ========================================================================= */

        /**
         * Simple sprintf implementation for number placeholders
         *
         * Replaces %d placeholders with provided arguments in order.
         * Useful for i18n strings with numeric values.
         *
         * @param {string} format - Format string with %d placeholders
         * @param {...number} args - Replacement values
         * @returns {string} Formatted string
         *
         * @example
         * sprintf('%d of %d images', 5, 10); // '5 of 10 images'
         */
        function sprintf(format, ...args) {
            let i = 0;
            return format.replace(/%d/g, () => args[i++]);
        }

        /* =========================================================================
           PAGE LOAD MANAGEMENT
           ========================================================================= */

        /**
         * Show table and hide skeleton loading when page is fully loaded
         *
         * The table is hidden via CSS initially with a skeleton placeholder shown.
         * This function transitions from skeleton to actual table on load,
         * with a 5-second fallback timeout.
         *
         * NOTE: If ajaxTableLoader has already rendered inline data and marked
         * the table as ready, this function will skip its logic to avoid
         * race conditions that cause table flicker.
         *
         * @returns {void}
         */
        function showTableWhenReady() {
            const $skeleton = $('.infyp-table-skeleton');
            const $wrapper = $('.infyp-table-wrapper');

            if (!$wrapper.length) return;

            // Check if table is already marked as ready (by ajaxTableLoader inline data)
            // This prevents race conditions where both try to manage visibility
            if ($wrapper.hasClass('infyp-table-ready')) {
                // Already ready - just ensure body class is set
                $('body').addClass('infyp-page-ready');
                return;
            }

            function showTable() {
                // Double-check in case ajaxTableLoader marked it ready while we were waiting
                if ($wrapper.hasClass('infyp-table-ready')) {
                    $('body').addClass('infyp-page-ready');
                    return;
                }

                $skeleton.fadeOut(200, function() {
                    $(this).addClass('infyp-skeleton-hidden');
                });
                $wrapper.addClass('infyp-table-ready');
                // Show search box and post type filter
                $('body').addClass('infyp-page-ready');
            }

            // Check if window is already fully loaded
            if (document.readyState === 'complete') {
                showTable();
            } else {
                // Show table when window is fully loaded
                $(window).on('load.infypInitial', function() {
                    showTable();
                    $(window).off('load.infypInitial');
                });

                // Fallback: show table after 5 seconds if load event doesn't fire
                setTimeout(function() {
                    if (!$wrapper.hasClass('infyp-table-ready')) {
                        showTable();
                        $(window).off('load.infypInitial');
                    }
                }, 5000);
            }
        }

        /* =========================================================================
           DOM QUERIES
           ========================================================================= */

        /**
         * Get image table row by image ID
         *
         * @param {number} imageId - WordPress attachment ID
         * @returns {jQuery} Table row element (may be empty if not found)
         */
        function getImageRow(imageId) {
            return $('tr[data-image-id="' + imageId + '"]');
        }

        /* =========================================================================
           SCROLLING
           ========================================================================= */

        /**
         * Scroll element into view with smooth animation
         *
         * @param {string|jQuery} selector - Element selector or jQuery object
         * @param {number} [offset=100] - Offset from top of viewport in pixels
         * @returns {void}
         */
        function scrollToElement(selector, offset = 100) {
            const $el = $(selector);
            if ($el.length) {
                $('html, body').animate({
                    scrollTop: $el.offset().top - offset
                }, 300);
            }
        }

        /* =========================================================================
           UTILITY FUNCTIONS
           ========================================================================= */

        /**
         * Debounce function execution
         *
         * Creates a debounced version of the function that delays execution
         * until after the specified wait time has elapsed since the last call.
         * Useful for expensive operations triggered by frequent events (e.g., input).
         *
         * @param {Function} func - Function to debounce
         * @param {number} wait - Wait time in milliseconds
         * @returns {Function} Debounced function
         *
         * @example
         * const debouncedSearch = debounce(search, 300);
         * $input.on('input', debouncedSearch);
         */
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        /**
         * Check if element is currently visible in viewport
         *
         * Returns true if the entire element is within the visible viewport.
         *
         * @param {jQuery} $el - Element to check
         * @returns {boolean} True if element is fully visible in viewport
         */
        function isInViewport($el) {
            if (!$el.length) return false;

            const rect = $el[0].getBoundingClientRect();
            return (
                rect.top >= 0 &&
                rect.left >= 0 &&
                rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
                rect.right <= (window.innerWidth || document.documentElement.clientWidth)
            );
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the helpers module
         *
         * @returns {Object} Public methods
         * @property {Function} safeCreateElement - Create XSS-safe DOM element
         * @property {Function} sprintf - Format string with placeholders
         * @property {Function} showTableWhenReady - Show table after page load
         * @property {Function} getImageRow - Get table row by image ID
         * @property {Function} scrollToElement - Smooth scroll to element
         * @property {Function} debounce - Debounce function execution
         * @property {Function} isInViewport - Check if element is visible
         */
        return {
            safeCreateElement: safeCreateElement,
            sprintf: sprintf,
            showTableWhenReady: showTableWhenReady,
            getImageRow: getImageRow,
            scrollToElement: scrollToElement,
            debounce: debounce,
            isInViewport: isInViewport
        };

    })(jQuery);

    /**
     * Optimize Status Utility - Shared status text and timer for AI optimization
     *
     * Provides reusable functions for displaying timed status messages and an
     * elapsed-time counter during AI optimization. Used in both table action
     * columns and modal optimize spinners.
     *
     * @file Shared optimize status utility
     * @author AI Image SEO
     * @since 1.3.0
     * @module ui/optimize-status
     */

    const optimizeStatus = (function() {

        /**
         * Returns HTML string for the status container (spinner + status text + timer).
         *
         * @since 1.3.0
         * @returns {string} HTML string
         */
        function createStatusHtml() {
            return (
                '<div class="infyp-optimize-status">' +
                    '<span class="spinner is-active infyp-field-spinner"></span>' +
                    '<span class="infyp-status-text"></span>' +
                    '<span class="infyp-status-timer">0.0s</span>' +
                '</div>'
            );
        }

        /**
         * Starts timed status messages and an elapsed-time counter.
         * Looks for `.infyp-status-text` and `.infyp-status-timer` inside
         * the given container.
         *
         * @since 1.3.0
         * @param {jQuery|HTMLElement} container - Element containing the status elements
         * @returns {Function} stopStatus - Call to clear the interval
         */
        function startStatusTimer(container) {
            const $container = jQuery(container);
            $container.find('.infyp-optimize-status');
            const $text  = $container.find('.infyp-status-text');
            const $timer = $container.find('.infyp-status-timer');

            const i18n       = window.infyp_vars?.i18n || {};
            const modelLabel = window.infyp_vars?.ai_model_label || 'AI';

            const stages = [
                { at: 0,  msg: i18n.status_preparing || 'Preparing image...' },
                { at: 2,  msg: (i18n.status_sending  || 'Sending to %s...').replace('%s', modelLabel) },
                { at: 5,  msg: i18n.status_analyzing  || 'AI is analyzing...' },
                { at: 15, msg: i18n.status_generating  || 'Generating metadata...' },
                { at: 30, msg: i18n.status_still       || 'Still processing...' },
            ];

            let currentStage = 0;
            const start = Date.now();

            $text.text(stages[0].msg);

            const intervalId = setInterval(() => {
                const elapsed = (Date.now() - start) / 1000;

                $timer.text(elapsed.toFixed(1) + 's');

                if (currentStage < stages.length - 1 && elapsed >= stages[currentStage + 1].at) {
                    currentStage++;
                    $text.text(stages[currentStage].msg);
                }
            }, 100);

            return function stopStatus() {
                clearInterval(intervalId);
            };
        }

        return {
            createStatusHtml,
            startStatusTimer,
        };

    })();

    // Expose globally for extension scripts (non-ES6 modules)
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.optimizeStatus = optimizeStatus;
    }

    /**
     * Base Modal Module - Shared modal utilities and helpers
     *
     * This module provides common utilities used across all modal components in the plugin.
     * It includes functions for modal lifecycle management, animations, HTML escaping,
     * notifications, and metric circle generation.
     *
     * ## Features
     *
     * - Modal open/close with CSS class-based state management (.is-open)
     * - Overlay creation and management
     * - XSS-safe HTML escaping
     * - Toast notification system
     * - SVG metric circle generator
     * - Integration with modal-manager.js for unified modal handling
     *
     * ## Usage
     *
     * ```javascript
     * import baseModal from './modals/base-modal.js';
     *
     * // Show/close modals (uses .is-open class for CSS Grid display)
     * const $overlay = baseModal.createOverlay();
     * baseModal.showModal($overlay, $modal);
     * baseModal.closeModal($overlay, $modal);
     *
     * // Display notifications
     * baseModal.showNotification('Saved successfully', 'success');
     *
     * // Create metric visualization
     * const html = baseModal.createMetricCircle(45, 40, 60, 'Characters', '45/60');
     * ```
     *
     * @module modals/base-modal
     * @requires jQuery
     */

    const baseModal = (function($) {

        /**
         * Animation duration in milliseconds
         * @const {number}
         */
        const ANIMATION_DURATION = 200;

        /* =========================================================================
           SCROLL LOCK UTILITIES
           ========================================================================= */

        /**
         * Lock body scroll and store the current scroll position.
         * This prevents background scrolling when a modal is open.
         *
         * @returns {void}
         */
        function lockBodyScroll() {
            const scrollY = window.scrollY;
            $('body')
                .addClass('infyp-lightbox-open')
                .css('top', `-${scrollY}px`)
                .data('scroll-position', scrollY);
        }

        /**
         * Unlock body scroll and restore the original scroll position.
         * This restores normal scrolling when a modal is closed.
         *
         * @returns {void}
         */
        function unlockBodyScroll() {
            const scrollY = $('body').data('scroll-position') || 0;
            $('body')
                .removeClass('infyp-lightbox-open')
                .css('top', '');
            window.scrollTo(0, scrollY);
        }

        /* =========================================================================
           MODAL LIFECYCLE
           ========================================================================= */

        /**
         * Close modal with animation
         *
         * Removes .is-open class from modal and .is-visible from overlay,
         * then removes them from the DOM after animation completes.
         * Also unlocks body scrolling and restores scroll position.
         *
         * @param {jQuery} $overlay - The overlay element to close
         * @param {jQuery} $modal - The modal element to close
         * @param {boolean} [removeFromDom=true] - Whether to remove elements from DOM
         * @returns {void}
         */
        function closeModal($overlay, $modal, removeFromDom = true) {
            // Unlock body scroll and restore scroll position
            unlockBodyScroll();

            // Remove visibility classes for CSS transition
            $overlay.removeClass('is-visible');
            $modal.removeClass('is-open');

            // After animation, hide and optionally remove from DOM
            setTimeout(function() {
                $overlay.css('display', 'none');
                $modal.css('display', 'none');

                if (removeFromDom) {
                    $overlay.remove();
                    $modal.remove();
                }
            }, ANIMATION_DURATION);
        }

        /**
         * Show modal with CSS class-based animation
         *
         * Adds .is-open class to modal and .is-visible to overlay,
         * triggering CSS Grid display and opacity transitions.
         * Also locks body scrolling and stores scroll position.
         *
         * @param {jQuery} $overlay - The overlay element to show
         * @param {jQuery} $modal - The modal element to show
         * @param {number} [duration=200] - Animation duration in milliseconds (for callbacks)
         * @returns {void}
         */
        function showModal($overlay, $modal, duration = ANIMATION_DURATION) {
            // Lock body scroll and store scroll position
            lockBodyScroll();

            // Show overlay first (display: block)
            $overlay.css('display', 'block');

            // Trigger reflow to ensure CSS transition works
            $overlay[0].offsetHeight;

            // Add visibility class for CSS transition
            $overlay.addClass('is-visible');

            // Add is-open class to modal (triggers CSS Grid display)
            $modal.addClass('is-open');

            // Focus first focusable element after animation
            setTimeout(function() {
                const $focusable = $modal.find('input, textarea, button, [tabindex]:not([tabindex="-1"])').first();
                if ($focusable.length) {
                    $focusable.focus();
                }
            }, duration);
        }

        /**
         * Create and append modal overlay to body
         *
         * Creates a semi-transparent overlay element that sits behind the modal
         * and typically captures clicks to close the modal.
         *
         * @param {string} [id='infyp-lightbox-overlay'] - The overlay element ID
         * @returns {jQuery} The created overlay element
         */
        function createOverlay(id = 'infyp-lightbox-overlay') {
            const $overlay = $(`<div class="infyp-lightbox-overlay" id="${id}"></div>`);
            $('body').append($overlay);
            return $overlay;
        }

        /**
         * Remove existing modal elements from DOM
         *
         * Useful for cleaning up before showing a new modal to prevent duplicates.
         *
         * @param {string} selector - CSS selector for modals to remove
         * @returns {void}
         */
        function removeExistingModals(selector) {
            $(selector).remove();
        }

        /* =========================================================================
           SECURITY UTILITIES
           ========================================================================= */

        /**
         * Escape HTML special characters to prevent XSS attacks
         *
         * Converts dangerous characters to their HTML entity equivalents.
         * Use this when inserting user-generated content into the DOM.
         *
         * @param {string} text - Text to escape
         * @returns {string} Escaped text safe for HTML insertion
         *
         * @example
         * const safe = escapeHtml('<script>alert("xss")</script>');
         * // Returns: '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'
         */
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        /**
         * Sanitize HTML by stripping dangerous tags and event-handler attributes.
         *
         * Intended as defense-in-depth for server-rendered HTML that has already
         * been escaped on the PHP side.
         *
         * @param {string} html - HTML string to sanitize.
         * @returns {string} Sanitized HTML.
         */
        function sanitizeHtml(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            doc.querySelectorAll('script,style,iframe,object,embed').forEach(function(el) { el.remove(); });
            doc.body.querySelectorAll('*').forEach(function(el) {
                Array.from(el.attributes).forEach(function(attr) {
                    if (attr.name.startsWith('on') || (attr.name === 'href' && attr.value.startsWith('javascript:'))) {
                        el.removeAttribute(attr.name);
                    }
                });
            });
            return doc.body.innerHTML;
        }

        /* =========================================================================
           NOTIFICATIONS
           ========================================================================= */

        /**
         * Display a toast notification message
         *
         * Shows a temporary notification that automatically fades out after 3 seconds.
         * Uses WordPress dashicons for visual indicators.
         *
         * @param {string} message - The notification message to display
         * @param {string} [type='info'] - Notification type: 'success', 'error', or 'info'
         * @returns {void}
         *
         * @example
         * showNotification('Settings saved!', 'success');
         * showNotification('Failed to save', 'error');
         */
        function showNotification(message, type = 'info') {
            const iconClass = type === 'success' ? 'yes-alt' : type === 'error' ? 'dismiss' : 'info';
            const $notification = $(`
            <div class="infyp-notification infyp-notification-${type}" style="display: flex; align-items: center; gap: 8px; opacity: 0;">
                <span class="dashicons dashicons-${iconClass}"></span>
                <span class="infyp-notification-text">${message}</span>
            </div>
        `);
            $('body').append($notification);
            $notification.animate({ opacity: 1 }, 200);
            setTimeout(() => {
                $notification.animate({ opacity: 0 }, 200, function() {
                    $(this).remove();
                });
            }, 3000);
        }

        /* =========================================================================
           METRIC VISUALIZATION
           ========================================================================= */

        /**
         * Create an SVG metric circle for modal score displays
         *
         * Generates a circular progress indicator with percentage fill,
         * label, and value text. Used for displaying SEO metrics like
         * character count, word count, and keyword usage.
         *
         * ## Progress Classes
         *
         * | Class   | Condition                    | Color  |
         * |---------|------------------------------|--------|
         * | success | Value within optimal range   | Green  |
         * | warning | Value exceeds maximum        | Yellow |
         * | error   | Value below minimum          | Red    |
         *
         * @param {number} current - Current value to display
         * @param {number} min - Minimum acceptable value
         * @param {number} max - Maximum acceptable value
         * @param {string} label - Metric label (e.g., 'Characters')
         * @param {string} valueText - Value text to display (e.g., '45/60')
         * @param {boolean} [isFormat=false] - If true, treat as binary valid/invalid
         * @returns {string} HTML string for the metric card
         *
         * @example
         * // Numeric metric
         * createMetricCircle(45, 40, 60, 'Characters', '45/60');
         *
         * // Binary format metric
         * createMetricCircle(true, 0, 0, 'Format', 'valid-filename', true);
         */
        function createMetricCircle(current, min, max, label, valueText, isFormat = false, zone) {
            let percentage, progressClass, statusText;

            if (isFormat) {
                // Format is binary: valid or invalid
                percentage = current ? 100 : 0;
                progressClass = current ? 'success' : 'error';
                statusText = current ?
                    (window.infyp_vars.i18n.valid_format || 'Valid') :
                    (window.infyp_vars.i18n.invalid_format || 'Invalid');
            } else {
                // Tolerance-aware: a metric inside the play room shows full/success,
                // matching the badge and the score (zone from scoring-engine).
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

            // Circle math: circumference = 2 * PI * radius (radius = 39 for 90px circle)
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
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the base modal module
         *
         * @returns {Object} Public methods
         * @property {Function} closeModal - Close modal with animation
         * @property {Function} showModal - Show modal with animation
         * @property {Function} createOverlay - Create overlay element
         * @property {Function} removeExistingModals - Remove modals by selector
         * @property {Function} escapeHtml - Escape HTML for XSS prevention
         * @property {Function} sanitizeHtml - Sanitize HTML (strip dangerous tags/attributes)
         * @property {Function} showNotification - Display toast notification
         * @property {Function} createMetricCircle - Generate metric circle SVG
         */
        return {
            closeModal,
            showModal,
            createOverlay,
            removeExistingModals,
            escapeHtml,
            sanitizeHtml,
            showNotification,
            createMetricCircle
        };
    })(jQuery);

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = baseModal;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.baseModal = baseModal;
    }

    /**
     * Custom Prompt Modal Module - Per-image AI optimization instructions
     *
     * Displays a lightweight modal before optimization starts, allowing users
     * to provide image-specific instructions that supplement the global prompt add-on.
     *
     * @module modals/custom-prompt-modal
     * @requires jQuery
     * @requires base-modal
     */

    const customPromptModal = (function($) {

        /**
         * Get the freshest image URL available.
         * Tries the server first (handles filename renames), falls back to table thumbnail.
         *
         * @param {number} imageId - The WordPress attachment ID
         * @returns {Promise<string>} The image URL
         */
        function getFreshImageUrl(imageId) {
            return new Promise(function(resolve) {
                api.post('infyp_get_fresh_image_url', {
                    image_id: imageId
                }).done(function(response) {
                    if (response.success && response.data) {
                        resolve(response.data.urls?.display || response.data.urls?.large || response.data.urls?.full || '');
                    } else {
                        resolve(fallbackImageUrl(imageId));
                    }
                }).fail(function() {
                    resolve(fallbackImageUrl(imageId));
                });
            });
        }

        /**
         * Fallback: read image URL from table thumbnail.
         */
        function fallbackImageUrl(imageId) {
            const $row = $(`tr[data-id="${imageId}"]`);
            let imageUrl = $row.find('.column-thumbnail img').attr('src') || '';
            if (imageUrl) {
                imageUrl = imageUrl.replace(/-\d+x\d+(\.[^.]+)$/, '$1');
            }
            return imageUrl;
        }

        /**
         * Show the custom prompt modal and return a Promise with the user's input
         *
         * @param {number} imageId - The WordPress attachment ID
         * @param {string} [savedPrompt=''] - Previously saved prompt for this image
         * @returns {Promise<{prompt: string}|null>} Resolves with prompt data or null if skipped
         */
        function show(imageId, savedPrompt) {
            return new Promise(function(resolve) {
                // Remove any existing custom prompt modal
                $('#infyp-custom-prompt-overlay').remove();
                $('#infyp-custom-prompt-modal').remove();

                const i18n = window.infyp_vars.i18n;

                // Fetch fresh image URL from server (handles filename renames)
                getFreshImageUrl(imageId).then(function(imageUrl) {

                const modalHtml = `
                <div class="infyp-lightbox-overlay" id="infyp-custom-prompt-overlay"></div>
                <div class="infyp-custom-prompt-lightbox" id="infyp-custom-prompt-modal" data-image-id="${imageId}">
                    <div class="infyp-custom-prompt-lightbox-header">
                        <h3>${i18n.custom_prompt_title || 'Custom Prompt'} <span class="infyp-image-id">#${imageId}</span></h3>
                        <button class="infyp-custom-prompt-lightbox-close">&times;</button>
                    </div>
                    <div class="infyp-custom-prompt-lightbox-content">
                        <div class="infyp-modal-image-preview">
                            <img src="${baseModal.escapeHtml(imageUrl)}" alt="${i18n.image_preview || 'Image preview'}" />
                        </div>
                        ${window.infyp_vars.enable_context_summary ? `
                        <div class="infyp-context-summary-section" data-image-id="${imageId}">
                            <div class="infyp-context-header">
                                <h4>${i18n.page_context_title || 'Page Context'}</h4>
                                <p>${i18n.page_context_description || 'Auto-generate context from the page where this image is used.'}</p>
                            </div>
                            <div class="infyp-context-page-selector" style="display:none;">
                                <label>${i18n.select_page_for_context || 'This image is used on multiple pages. Select one:'}</label>
                                <select class="infyp-context-page-dropdown"></select>
                            </div>
                            <div class="infyp-context-prompt-wrapper">
                                <textarea class="infyp-context-prompt-textarea" rows="3" placeholder="${i18n.context_placeholder || 'Context will appear here...'}">${baseModal.escapeHtml(savedPrompt || '')}</textarea>
                            </div>
                            <div class="infyp-context-actions">
                                <button type="button" class="button infyp-generate-context-btn">
                                    ${i18n.generate_context || 'Generate Context'}
                                </button>
                                <span class="infyp-context-status"></span>
                            </div>
                        </div>
                        ` : `
                        <h4 class="infyp-custom-prompt-description">${i18n.custom_prompt_description || 'Add specific instructions for optimizing this image.'}</h4>
                        <textarea id="infyp-custom-prompt-input" class="infyp-custom-prompt-textarea" rows="4" placeholder="${i18n.custom_prompt_placeholder || 'A mountain view in California during sunrise, focus on travel and nature keywords'}">${baseModal.escapeHtml(savedPrompt || '')}</textarea>
                        `}
                    </div>
                    <div class="infyp-custom-prompt-lightbox-footer">
                        <button class="button button-primary infyp-custom-prompt-submit">
                            <span class="infyp-optimize-icon"></span>
                            ${i18n.optimize_with_prompt || 'Optimize with AI'}
                        </button>
                    </div>
                </div>
            `;

                $('body').append(modalHtml);

                const $overlay = $('#infyp-custom-prompt-overlay');
                const $modal = $('#infyp-custom-prompt-modal');
                let resolved = false;

                baseModal.showModal($overlay, $modal);

                // Focus textarea
                setTimeout(function() {
                    $('#infyp-custom-prompt-input').focus();
                }, 250);

                function closeAndResolve(value) {
                    if (resolved) return;
                    resolved = true;
                    $(document).off('keydown.customPromptModal');
                    baseModal.closeModal($overlay, $modal);
                    resolve(value);
                }

                // Optimize button — pass the custom prompt
                $modal.find('.infyp-custom-prompt-submit').on('click', function(e) {
                    e.preventDefault();
                    // Read from context textarea or custom prompt textarea
                    const $contextTextarea = $modal.find('.infyp-context-prompt-textarea');
                    const $customTextarea = $('#infyp-custom-prompt-input');
                    const prompt = ($contextTextarea.length ? $contextTextarea.val() : $customTextarea.val()).trim();
                    closeAndResolve({ prompt: prompt });
                });

                // Close button (X) — same as skip
                $modal.find('.infyp-custom-prompt-lightbox-close').on('click', function(e) {
                    e.preventDefault();
                    closeAndResolve(null);
                });

                // Overlay click — same as skip
                $overlay.on('click', function(e) {
                    e.preventDefault();
                    closeAndResolve(null);
                });

                // Escape key
                $(document).on('keydown.customPromptModal', function(e) {
                    if (e.key === 'Escape' && $modal.hasClass('is-open')) {
                        closeAndResolve(null);
                    }
                });

                }); // end getFreshImageUrl().then()
            });
        }

        return {
            show
        };
    })(jQuery);

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


    const aiOptimizer = (function($) {

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

    /**
     * Usage Modal Module - Displays image usage information
     *
     * This module provides a modal dialog showing where an image is used throughout
     * the WordPress site. It displays posts, pages, and other content types where
     * the image appears as featured image, in content, or via page builders.
     *
     * ## Features
     *
     * - Shows image preview with high-quality URL
     * - Lists all posts/pages using the image
     * - Displays usage type badges (Featured, Content, Page Builder)
     * - Refresh button to update usage data
     * - PHP template loading with JS fallback
     *
     * ## Usage Types Displayed
     *
     * | Type     | Description                              |
     * |----------|------------------------------------------|
     * | featured | Image is set as featured/thumbnail       |
     * | content  | Image is embedded in post content        |
     * | builder  | Image is used in page builder elements   |
     *
     * ## Dependencies
     *
     * - jQuery
     * - api module (for AJAX requests)
     * - baseModal module (for common modal utilities)
     * - infyp_vars global (for AJAX URL, nonce, i18n)
     *
     * ## Usage
     *
     * ```javascript
     * import usageModal from './modals/usage-modal.js';
     *
     * // Show modal with usage data
     * usageModal.show(123, '<div class="usage-item">...</div>');
     * ```
     *
     * @module modals/usage-modal
     * @requires jQuery
     * @requires api
     * @requires base-modal
     */

    const usageModal = (function($) {

        /* =========================================================================
           MODAL INITIALIZATION
           ========================================================================= */

        /**
         * Show usage modal for an image
         *
         * Fetches fresh image URL for high-quality preview, then displays
         * the usage modal with the provided usage HTML content.
         *
         * @param {number} imageId - The WordPress attachment ID
         * @param {string} usageHtml - Pre-rendered HTML content showing usage locations
         * @returns {void}
         */
        function showUsageModal(imageId, usageHtml) {
            // Remove any existing modals
            $('#infyp-lightbox-overlay').remove();
            $('#infyp-usage-lightbox').remove();

            // Fetch fresh image data first for better quality image
            api.post('infyp_get_fresh_image_url', {
                image_id: imageId
            }).done(function(response) {
                let imageUrl = '';
                if (response.success && response.data) {
                    // Use fresh URL from server - prefer display or large size
                    imageUrl = response.data.urls?.display || response.data.urls?.large || response.data.urls?.full || '';
                } else {
                    // Fallback to thumbnail if fresh fetch fails
                    const $row = $(`tr[data-id="${imageId}"]`);
                    const $imageThumb = $row.find('.column-thumbnail img');
                    imageUrl = $imageThumb.attr('src') || '';

                    // Try to get larger version by replacing -150x150 pattern
                    if (imageUrl) {
                        imageUrl = imageUrl.replace(/-\d+x\d+(\.[^.]+)$/, '$1');
                    }
                }

                displayUsageModal(imageId, usageHtml, imageUrl);
            }).fail(function() {
                // Fallback if API call fails
                const $row = $(`tr[data-id="${imageId}"]`);
                const $imageThumb = $row.find('.column-thumbnail img');
                let imageUrl = $imageThumb.attr('src') || '';

                // Try to get larger version
                if (imageUrl) {
                    imageUrl = imageUrl.replace(/-\d+x\d+(\.[^.]+)$/, '$1');
                }

                displayUsageModal(imageId, usageHtml, imageUrl);
            });
        }

        /**
         * Display usage modal with image URL
         *
         * Wrapper function that delegates to PHP template loading.
         *
         * @param {number} imageId - The WordPress attachment ID
         * @param {string} usageHtml - Pre-rendered HTML content showing usage
         * @param {string} imageUrl - URL for image preview
         * @returns {void}
         * @private
         */
        function displayUsageModal(imageId, usageHtml, imageUrl) {
            // Use PHP/AJAX approach to load modal
            loadUsageModalFromPHP(imageId, usageHtml, imageUrl);
        }

        /* =========================================================================
           PHP TEMPLATE LOADING
           ========================================================================= */

        /**
         * Load usage modal from PHP template via AJAX
         *
         * Requests the modal HTML from the server, which provides consistent
         * styling and i18n support. Falls back to JS-generated HTML on failure.
         *
         * @param {number} imageId - The WordPress attachment ID
         * @param {string} usageHtml - Pre-rendered HTML content showing usage
         * @param {string} imageUrl - URL for image preview
         * @returns {void}
         * @private
         */
        function loadUsageModalFromPHP(imageId, usageHtml, imageUrl) {
            // Load modal HTML from PHP template
            $.post(window.infyp_vars.ajax_url, {
                action: 'infyp_load_usage_modal',
                nonce: window.infyp_vars.nonce,
                image_id: imageId,
                usage_html: usageHtml,
                image_url: imageUrl
            }).done(function(response) {
                if (response.success && response.data) {
                    // Append modal HTML to body
                    $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                    // Set image data
                    $('#infyp-usage-lightbox').attr('data-image-id', imageId);
                    $('#infyp-usage-lightbox .infyp-image-id').text('#' + imageId);
                    $('#infyp-usage-lightbox .infyp-modal-image-preview img').attr('src', imageUrl);
                    $('.infyp-usage-list').html(usageHtml);
                    $('.infyp-refresh-usage').attr('data-image-id', imageId);

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
                    $('#infyp-usage-lightbox').addClass('is-open');

                    // Bind usage modal events
                    bindUsageModalEvents(imageId);
                } else {
                    // Fallback to inline HTML if AJAX fails
                    displayUsageModalFallback(imageId, usageHtml, imageUrl);
                }
            }).fail(function() {
                // Fallback on AJAX error
                displayUsageModalFallback(imageId, usageHtml, imageUrl);
            });
        }

        /* =========================================================================
           FALLBACK RENDERING
           ========================================================================= */

        /**
         * Fallback function to display usage modal with inline JS-generated HTML
         *
         * Used when PHP template loading fails. Generates complete modal HTML
         * including header, image preview, usage list, legend, and footer.
         *
         * @param {number} imageId - The WordPress attachment ID
         * @param {string} usageHtml - Pre-rendered HTML content showing usage
         * @param {string} imageUrl - URL for image preview
         * @returns {void}
         * @private
         */
        function displayUsageModalFallback(imageId, usageHtml, imageUrl) {
            const i18n = window.infyp_vars.i18n;
            const modalHtml = `
            <div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
            <div class="infyp-usage-lightbox" id="infyp-usage-lightbox" data-image-id="${imageId}">
                <div class="infyp-usage-lightbox-header">
                    <h3>${i18n.image_usage || 'Image Usage'} <span class="infyp-image-id">#${imageId}</span></h3>
                    <button class="infyp-usage-lightbox-close">&times;</button>
                </div>
                <div class="infyp-usage-lightbox-content">
                    <div class="infyp-modal-image-preview">
                        <img src="${imageUrl}" alt="${i18n.image_preview || 'Image preview'}" />
                    </div>
                    <div class="infyp-usage-details-section">
                        <h4>${i18n.where_used || 'Where This Image Is Used'}</h4>
                        <div class="infyp-usage-list">${usageHtml}</div>
                    </div>
                    <div class="infyp-usage-info">
                        <h4>${i18n.usage_types || 'Usage Types'}</h4>
                        <ul class="infyp-usage-legend">
                            <li><span class="infyp-usage-badge featured">${i18n.featured_image || 'Featured Image'}</span> - ${i18n.featured_image_desc || 'Used as the main image for a post or page'}</li>
                            <li><span class="infyp-usage-badge content">${i18n.in_content || 'In Content'}</span> - ${i18n.in_content_desc || 'Embedded within the post or page content'}</li>
                            <li><span class="infyp-usage-badge builder">${i18n.page_builder || 'Page Builder'}</span> - ${i18n.page_builder_desc || 'Used in page builder elements (Elementor, etc.)'}</li>
                        </ul>
                    </div>
                </div>
                <div class="infyp-usage-lightbox-footer">
                    <button class="button infyp-refresh-usage" data-image-id="${imageId}">
                        <span class="dashicons dashicons-update"></span> ${i18n.refresh || 'Refresh'}
                    </button>
                    <button class="button button-primary infyp-close-usage">${i18n.close || 'Close'}</button>
                </div>
            </div>
        `;

            $('body').append(modalHtml);

            // Lock body scroll and store scroll position for restoration
            const scrollY = window.scrollY;
            $('body')
                .addClass('infyp-lightbox-open')
                .css('top', `-${scrollY}px`)
                .data('scroll-position', scrollY);

            // Show overlay with CSS transition
            const $overlayEl = $('#infyp-lightbox-overlay');
            $overlayEl.css('display', 'block');
            // Trigger reflow for CSS transition
            $overlayEl[0].offsetHeight;
            $overlayEl.addClass('is-visible');

            // Add is-open class to modal (triggers CSS Grid display)
            $('#infyp-usage-lightbox').addClass('is-open');

            bindUsageModalEvents(imageId);
        }

        /* =========================================================================
           EVENT HANDLERS
           ========================================================================= */

        /**
         * Bind event handlers for usage modal interactions
         *
         * Sets up handlers for:
         * - Close button and overlay clicks
         * - Refresh button to reload usage data
         * - Click propagation prevention on modal content
         *
         * @param {number} imageId - The WordPress attachment ID for refresh requests
         * @returns {void}
         * @private
         */
        function bindUsageModalEvents(imageId) {
            const i18n = window.infyp_vars.i18n;

            // Close events using .is-open class system
            $('#infyp-lightbox-overlay, .infyp-usage-lightbox-close, .infyp-close-usage').off('click').on('click', function(e) {
                e.preventDefault();

                const $overlay = $('#infyp-lightbox-overlay');
                const $modal = $('#infyp-usage-lightbox');

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
            });

            // Refresh button
            $('.infyp-refresh-usage').off('click').on('click', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const imgId = imageId || $btn.data('image-id');

                $btn.prop('disabled', true);
                $btn.html('<span class="spinner is-active" style="float: none; margin: 0;"></span> ' + (i18n.loading || 'Loading...'));

                // Clear cache and refetch
                api.post('infyp_get_image_usage', {
                    image_id: imgId,
                    refresh: true
                }).done(response => {
                    if (response.success && response.data.html) {
                        $('.infyp-usage-list').html(baseModal.sanitizeHtml(response.data.html));
                    }
                }).always(() => {
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-update"></span> ' + (i18n.refresh || 'Refresh'));
                });
            });

            // Prevent modal content from closing when clicked
            $('#infyp-usage-lightbox').off('click').on('click', function(e) {
                e.stopPropagation();
            });
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the usage modal module
         *
         * @returns {Object} Public methods
         * @property {Function} show - Show modal with image ID and usage HTML
         * @property {Function} display - Display modal with all parameters
         * @property {Function} loadFromPHP - Load modal from PHP template
         * @property {Function} displayFallback - Display modal with JS fallback
         * @property {Function} bindEvents - Bind modal event handlers
         */
        return {
            show: showUsageModal,
            display: displayUsageModal,
            loadFromPHP: loadUsageModalFromPHP,
            displayFallback: displayUsageModalFallback,
            bindEvents: bindUsageModalEvents
        };
    })(jQuery);

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = usageModal;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.usageModal = usageModal;
    }

    /**
     * Usage Checker Module - Image usage checking functionality
     *
     * This module handles checking where an image is used throughout the WordPress
     * site and displaying the results in a modal. It integrates with the usage
     * modal module to display posts, pages, and other content using the image.
     *
     * ## Features
     *
     * - AJAX-based usage checking
     * - Loading spinner during fetch
     * - Integration with usage modal display
     * - Error handling with user feedback
     * - Button state management (hide during load, restore after)
     * - Server-rendered HTML results
     *
     * ## Usage Flow
     *
     * 1. User clicks "Check Usage" button
     * 2. Button hides, loading spinner appears
     * 3. AJAX request fetches usage data from server
     * 4. Usage modal displays results
     * 5. Button restores to normal state
     *
     * ## Usage
     *
     * ```javascript
     * import usageChecker from './features/usage-checker.js';
     *
     * // Check usage programmatically
     * usageChecker.checkUsage(123, $button);
     *
     * // Use as click handler
     * $('.infyp-check-usage').on('click', usageChecker.handleClick);
     * ```
     *
     * @file Usage Checker Module - Image usage checking functionality
     * @author InfyPress
     * @since 1.0.0
     * @module features/usage-checker
     * @requires jQuery
     * @requires api
     * @requires modals/usage-modal
     */


    const usageChecker = (function($) {

        /* =========================================================================
           USAGE CHECKING
           ========================================================================= */

        /**
         * Checks image usage and displays results in modal.
         *
         * Makes an AJAX request to fetch all locations where the image is used
         * throughout the WordPress site, then displays the results in the usage modal.
         * Shows loading spinner during fetch and handles errors gracefully.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {number} imageId - WordPress attachment ID
         * @param {jQuery} $button - The trigger button element (for loading state)
         * @returns {void}
         *
         * @example
         * // Check usage programmatically
         * checkUsage(123, $('.infyp-check-usage'));
         */
        function checkUsage(imageId, $button) {
            const $wrapper = $button.closest('.infyp-usage-details');

            // Hide button and show spinner
            $button.hide();
            const $spinner = $('<span class="spinner is-active infyp-field-spinner"></span>');
            $wrapper.addClass('infyp-wrapper-loading').append($spinner);

            // Fetch usage data
            api.post('infyp_get_image_usage', {
                image_id: imageId
            }).done(response => {
                if (response.success && response.data.html) {
                    usageModal.show(imageId, response.data.html);
                } else {
                    alert(infyp_vars.i18n.error || 'Error loading usage data');
                }
            }).fail(() => {
                alert(infyp_vars.i18n.server_error || 'Server error occurred');
            }).always(() => {
                // Restore button
                $wrapper.removeClass('infyp-wrapper-loading');
                $spinner.remove();
                $button.show();
            });
        }

        /* =========================================================================
           EVENT HANDLERS
           ========================================================================= */

        /**
         * Handles usage check button click event.
         *
         * Extracts the image ID from the button's data attribute and
         * initiates the usage check workflow.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event
         * @returns {void}
         *
         * @example
         * // Attach to usage button
         * $('.infyp-check-usage').on('click', handleClick);
         */
        function handleClick(e) {
            e.preventDefault();
            const $button = $(e.target).closest('.infyp-check-usage');
            const imageId = $button.data('id');
            checkUsage(imageId, $button);
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the usage checker module.
         *
         * @since 1.0.0
         *
         * @returns {Object} Public methods
         * @property {Function} checkUsage - Check usage and display modal
         * @property {Function} handleClick - Handle button click event
         */
        return {
            checkUsage: checkUsage,
            handleClick: handleClick
        };

    })(jQuery);

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


    const characterCounter = (function($) {

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


    const fieldEditor = (function($) {

        /* =========================================================================
           CONFIGURATION
           ========================================================================= */

        /**
         * Reference to the scoring engine update function
         * @type {Function}
         * @private
         */
        const updateSEOScore = scoringEngine.updateSEOScore;

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

    /**
     * Pagination Module - Handles AJAX pagination for the image table
     *
     * This module manages pagination links in the WordPress media library table,
     * enabling seamless AJAX-based page navigation without full page reloads.
     * It updates the table content, navigation elements, and browser URL state.
     *
     * ## Features
     *
     * - AJAX-based pagination (no page reload)
     * - Loading overlay during page transitions
     * - Browser history state management
     * - Automatic table handler reinitialization
     * - Filter preservation across pages
     * - Smooth scroll to table top
     * - Graceful error handling
     *
     * ## Dependencies
     *
     * - jQuery
     * - api module (AJAX helper)
     * - WordPress list table structure
     *
     * ## Usage
     *
     * ```javascript
     * import pagination from './table/pagination.js';
     *
     * // Initialize with callbacks
     * pagination.init(
     *     reinitializeTableHandlers,  // Called after table updates
     *     reinitializeFilters         // Called after filter UI updates
     * );
     *
     * // Re-bind pagination handlers after AJAX updates
     * pagination.handlePaginationLinks();
     * ```
     *
     * @file Pagination Module - AJAX pagination for WordPress media library table.
     * @author InfyPress
     * @since 1.0.0
     * @module table/pagination
     * @requires jQuery
     * @requires module:api
     */


    const pagination = (function($) {

        /* =========================================================================
           CONFIGURATION
           ========================================================================= */

        /**
         * Reference to table reinitialization callback
         * @type {Function|null}
         * @private
         */
        let reinitCallback = null;

        /**
         * Reference to filter reinitialization callback
         * @type {Function|null}
         * @private
         */
        let filterInitCallback = null;

        /* =========================================================================
           EVENT HANDLERS
           ========================================================================= */

        /**
         * Handles pagination link clicks with AJAX.
         *
         * Intercepts pagination link clicks, performs AJAX request to load new page,
         * and updates table content without full page reload. Shows loading overlay
         * during the request and handles errors gracefully. Maintains filter state
         * and updates browser history.
         *
         * Workflow:
         * 1. Prevent default link behavior
         * 2. Show loading overlay on table
         * 3. Fetch new page HTML via AJAX
         * 4. Parse response and extract table/navigation elements
         * 5. Replace current table and navigation
         * 6. Update browser URL with pushState
         * 7. Scroll to top of table
         * 8. Reinitialize event handlers via callbacks
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function handlePaginationLinks() {
            // Remove existing handlers to prevent duplicates
            $(document).off('click.infypPagination', '.tablenav-pages a');

            // Add AJAX handler for pagination links
            $(document).on('click.infypPagination', '.tablenav-pages a', function(e) {
                e.preventDefault();

                const href = $(this).attr('href');
                if (!href || href === '#') return;

                // Get table reference
                const $table = $('.wp-list-table.widefat.fixed.striped.table-view-list.images');
                const tablePosition = $table.position();
                const tableWidth = $table.outerWidth();
                const tableHeight = $table.outerHeight();

                // Remove any existing overlay
                $('.infyp-table-overlay').remove();

                // Add loading overlay positioned over the table
                $table.parent().css('position', 'relative');
                $table.parent().append(
                    '<div class="infyp-table-overlay" style="position: absolute; top: ' + tablePosition.top + 'px; left: ' + tablePosition.left + 'px; width: ' + tableWidth + 'px; height: ' + tableHeight + 'px; z-index: 100; display: flex; align-items: center; justify-content: center;">' +
                    '<span class="spinner is-active" style="float: none;"></span>' +
                    '</div>'
                );

                // Perform AJAX request
                $.ajax({
                    url: href,
                    type: 'GET',
                    dataType: 'html',
                    success: function(response) {
                        try {
                            const $response = $(response);

                            // Find new table elements
                            const $newTable = $response.find('.wp-list-table').first();
                            const $newTopNav = $response.find('.tablenav.top').first();
                            const $newBottomNav = $response.find('.tablenav.bottom').first();

                            if ($newTable.length) {
                                // Replace table and navigation
                                $('.wp-list-table').replaceWith($newTable);

                                if ($newTopNav.length) {
                                    $('.tablenav.top').replaceWith($newTopNav);
                                }
                                if ($newBottomNav.length) {
                                    $('.tablenav.bottom').replaceWith($newBottomNav);
                                }

                                // Update browser URL
                                window.history.pushState({}, '', href);

                                // Scroll to top of table (safely check offset exists)
                                const $wrap = $('.wrap');
                                if ($wrap.length && $wrap.offset()) {
                                    $('html, body').animate({
                                        scrollTop: $wrap.offset().top - 32
                                    }, 300);
                                }

                                // Reinitialize handlers
                                if (reinitCallback && typeof reinitCallback === 'function') {
                                    reinitCallback();
                                }

                                // Reinitialize post type filter
                                if (filterInitCallback && typeof filterInitCallback === 'function') {
                                    filterInitCallback();
                                }
                            }
                        } catch (error) {
                            console.error('Error updating table:', error);
                        } finally {
                            // Remove loading overlay
                            $('.infyp-table-overlay').fadeOut(200, function() {
                                $(this).remove();
                            });
                        }
                    },
                    error: function() {
                        $('.infyp-table-overlay').remove();
                        alert('Failed to load page. Please try again.');
                    }
                });
            });
        }

        /* =========================================================================
           INITIALIZATION
           ========================================================================= */

        /**
         * Initializes pagination handlers.
         *
         * Sets up AJAX pagination for the table. Should be called on page load
         * and after table updates that might add new pagination links. Stores
         * callback references for use in the AJAX success handler.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Function} reinitializeFn - Callback to reinitialize table handlers after pagination.
         * @param {Function} filterInitFn - Callback to reinitialize filter handlers after pagination.
         * @returns {void}
         */
        function init(reinitializeFn, filterInitFn) {
            // Store callbacks for use in AJAX success handler
            if (typeof reinitializeFn === 'function') {
                reinitCallback = reinitializeFn;
            }
            if (typeof filterInitFn === 'function') {
                filterInitCallback = filterInitFn;
            }

            // Initialize pagination handlers
            handlePaginationLinks();
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the pagination module.
         *
         * @since 1.0.0
         *
         * @returns {Object} Public methods.
         * @property {Function} init - Initialize pagination with callbacks.
         * @property {Function} handlePaginationLinks - Bind pagination click handlers (for re-initialization).
         */
        return {
            init,
            handlePaginationLinks
        };

    })(jQuery);

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = pagination;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.pagination = pagination;
    }

    /**
     * Filtering Module - Handles post type filtering for the image table
     *
     * This module manages the post type filter UI and AJAX operations for filtering
     * the WordPress media library table by post types (posts, pages, attachments, etc.).
     * Features include: token-based multi-select, dropdown search, and max selection limits.
     *
     * ## Features
     *
     * - Token-based multi-select filter (visual badges)
     * - Dropdown with live search functionality
     * - Maximum 3 simultaneous selections
     * - AJAX table filtering without page reload
     * - Clean URL format (plus-separated values)

     * - Keyboard navigation (ESC to close)
     * - Filter state preservation across pagination
     *
     * ## URL Format
     *
     * - Single filter: `?post_type_filter=post`
     * - Multiple filters: `?post_type_filter=post+page+media`
     * - All types: `?post_type_filter=all`
     *
     * ## Dependencies
     *
     * - jQuery
     * - api module (AJAX helper)
     * - infyp_vars global (i18n strings, admin_url)
     *
     * ## Usage
     *
     * ```javascript
     * import filtering from './table/filtering.js';
     *
     * // Initialize with reinit callback
     * filtering.init(reinitializeTableHandlers);
     *
     * // Re-initialize filter UI after AJAX updates
     * filtering.handlePostTypeFilter();
     * ```
     *
     * @file Filtering Module - Post type filter for WordPress media library table.
     * @author InfyPress
     * @since 1.0.0
     * @module table/filtering
     * @requires jQuery
     * @requires module:api
     */


    const filtering = (function($) {

        /* =========================================================================
           CONFIGURATION
           ========================================================================= */

        /**
         * Reference to table reinitialization callback
         * @type {Function|null}
         * @private
         */
        let reinitCallback = null;

        /**
         * Session storage key for filter state persistence
         * @constant {string}
         */
        const FILTER_STATE_KEY = 'infyp_filter_state';

        /* =========================================================================
           FILTER STATE PERSISTENCE
           ========================================================================= */

        /**
         * Saves filter state to sessionStorage
         *
         * Persists the current filter selection so it can be restored
         * when the user navigates back to the page within the same session.
         *
         * @since 1.1.0
         * @access private
         * @param {string} filterValue - Current filter value
         * @returns {void}
         */
        function saveFilterState(filterValue) {
            try {
                sessionStorage.setItem(FILTER_STATE_KEY, filterValue);
            } catch (e) {
                // sessionStorage not available, ignore
            }
        }

        /**
         * Clears saved filter state
         *
         * @since 1.1.0
         * @access private
         * @returns {void}
         */
        function clearFilterState() {
            try {
                sessionStorage.removeItem(FILTER_STATE_KEY);
            } catch (e) {
                // sessionStorage not available, ignore
            }
        }

        /* =========================================================================
           HELPER FUNCTIONS
           ========================================================================= */

        /**
         * Escapes HTML special characters to prevent XSS.
         *
         * Converts HTML-sensitive characters to their entity equivalents
         * for safe insertion into the DOM.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {string} text - Text to escape.
         * @returns {string} Escaped text safe for HTML insertion.
         */
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }

        /* =========================================================================
           FILTER MANAGEMENT
           ========================================================================= */

        /**
         * Handles post type filter initialization and events.
         *
         * Sets up the token-based filter with search functionality.
         * Manages filter tokens, dropdown interactions, and AJAX filtering.
         * Supports maximum 3 simultaneous post type selections.
         *
         * Initializes nested functions for:
         * - Token management (add/remove)
         * - Dropdown state (show/hide/search)
         * - Selection validation (max limit)
         * - AJAX filtering with loading states
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function handlePostTypeFilter() {
            // Use more specific selector to exclude modal filter from main table operations
            const $filterWrapper = $('.infyp-post-type-filter-wrapper').not('.infyp-modal-post-type-filter');
            const $tokenList = $('#infyp-post-type-tokens');
            const $filterInput = $('#infyp-post-type-input');
            const $filterValue = $('#infyp-post-type-filter-value');
            const $dropdown = $('#infyp-post-type-dropdown');
            const $dropdownSearch = $('#infyp-dropdown-search');

            if (!$filterWrapper.length) return;

            // Store original placeholder text from server-rendered HTML
            const originalPlaceholder = $filterInput.attr('placeholder') || 'Add post type filter...';
            const maxFiltersText = infyp_vars.i18n.max_filters_allowed || 'Max 3 filters allowed';

            // Remove any existing event handlers to prevent duplicates
            $filterInput.off('.postTypeFilter');
            $dropdownSearch.off('.postTypeFilter');
            $(document).off('.postTypeFilter');

            let isFiltering = false;
            let selectedTypes = [];
            const MAX_SELECTIONS = 3; // Maximum number of post types that can be selected

            /**
             * Initializes selected types from existing tokens.
             *
             * Parses DOM tokens to rebuild selectedTypes array from current state.
             * Called on initialization to sync JavaScript state with server-rendered HTML.
             *
             * @since 1.0.0
             * @access private
             * @memberof handlePostTypeFilter
             *
             * @returns {void}
             */
            function initializeSelectedTypes() {
                selectedTypes = [];
                $tokenList.find('.infyp-filter-token').each(function() {
                    const type = $(this).data('type');
                    if (type) selectedTypes.push(type);
                });
            }

            /**
             * Shows dropdown with proper positioning.
             *
             * Updates option states before displaying and handles viewport overflow
             * by flipping dropdown to top if it would extend below viewport.
             *
             * @since 1.0.0
             * @access private
             * @memberof handlePostTypeFilter
             *
             * @returns {void}
             */
            function showDropdown() {
                // Update dropdown options based on current selections
                updateDropdownOptionsState();

                $dropdown.show();
                $dropdownSearch.focus();
                $filterInput.attr('aria-expanded', 'true');

                // Position dropdown if needed (flip to top if would overflow)
                $filterWrapper[0].getBoundingClientRect();
                const dropdownRect = $dropdown[0].getBoundingClientRect();
                if (dropdownRect.bottom > window.innerHeight) {
                    $dropdown.css('bottom', '100%').css('top', 'auto').css('margin-bottom', '4px').css('margin-top', '0');
                }
            }

            /**
             * Updates dropdown options based on selection state.
             *
             * Handles max selection limit, selected state, and disabled state.
             * Updates visual indicators (checkmarks) and tooltips for options.
             * Disables options when max selection limit is reached.
             *
             * @since 1.0.0
             * @access private
             * @memberof handlePostTypeFilter
             *
             * @returns {void}
             */
            function updateDropdownOptionsState() {
                const limitReached = selectedTypes.length >= MAX_SELECTIONS && !selectedTypes.includes('all');

                // Update input placeholder based on limit
                if (limitReached) {
                    $filterInput.addClass('limit-reached');
                    $filterInput.attr('placeholder', maxFiltersText);
                } else {
                    $filterInput.removeClass('limit-reached');
                    $filterInput.attr('placeholder', originalPlaceholder);
                }

                $dropdown.find('.infyp-dropdown-option').each(function() {
                    const $option = $(this);
                    const value = $option.data('value');
                    const isSelected = selectedTypes.includes(value);
                    // Update selected state and show/hide check icon
                    if (isSelected) {
                        $option.addClass('infyp-option-selected');
                        $option.find('.infyp-selected-check').show();
                    } else {
                        $option.removeClass('infyp-option-selected');
                        $option.find('.infyp-selected-check').hide();
                    }

                    // Don't modify already selected items or "All"
                    if (isSelected || value === 'all') {
                        if (!isSelected && value !== 'all' && limitReached) {
                            $option.addClass('infyp-option-limit-reached');
                            $option.attr('title', 'Maximum ' + MAX_SELECTIONS + ' selections allowed');
                        }
                        return;
                    }

                    if (limitReached) {
                        $option.addClass('infyp-option-limit-reached');
                        $option.attr('title', 'Maximum ' + MAX_SELECTIONS + ' selections allowed');
                    } else {
                        $option.removeClass('infyp-option-limit-reached');
                        $option.removeAttr('title');
                    }
                });
            }

            /**
             * Hide dropdown and reset search
             */
            function hideDropdown() {
                $dropdown.hide();
                $filterInput.attr('aria-expanded', 'false');
                $dropdownSearch.val('');
                filterDropdownOptions('');
            }

            /**
             * Filters dropdown options based on search term.
             *
             * Shows/hides dropdown options based on whether their label matches
             * the search term (case-insensitive).
             *
             * @since 1.0.0
             * @access private
             * @memberof handlePostTypeFilter
             *
             * @param {string} searchTerm - Search query to filter options.
             * @returns {void}
             */
            function filterDropdownOptions(searchTerm) {
                const term = searchTerm.toLowerCase();
                $dropdown.find('.infyp-dropdown-option').each(function() {
                    const $option = $(this);
                    const label = ($option.data('label') || $option.text()).toLowerCase();
                    if (term === '' || label.includes(term)) {
                        $option.show();
                    } else {
                        $option.hide();
                    }
                });
            }

            /**
             * Applies filter with AJAX.
             *
             * Uses the ajaxTableLoader to reload table with selected filters.
             * This integrates with the async table loading architecture.
             * Saves filter state to sessionStorage for persistence.
             *
             * @since 1.0.0
             * @access private
             * @memberof handlePostTypeFilter
             *
             * @returns {void}
             */
            function applyFilter() {
                if (isFiltering) return;
                isFiltering = true;

                // Build filter string
                let filterValue;
                if (selectedTypes.includes('all') || selectedTypes.length === 0) {
                    filterValue = 'all';
                    // Clear filter state when "all" is selected
                    clearFilterState();
                } else if (selectedTypes.length === 1) {
                    filterValue = selectedTypes[0];
                } else {
                    filterValue = selectedTypes.join('+');
                }

                // Save filter state for session persistence
                if (filterValue !== 'all') {
                    saveFilterState(filterValue);
                }

                // Update hidden input
                $filterValue.val(filterValue);

                // Clear page/post filter when post type changes (selected post may not match new type)
                $(document).trigger('infyp:postTypeFilterChanged');

                // Use ajaxTableLoader if available (preferred for performance)
                if (window.infyp && window.infyp.ajaxTableLoader) {
                    window.infyp.ajaxTableLoader.setFilter(filterValue)
                        .done(function() {
                            // Reinitialize handlers for the new table
                            if (reinitCallback && typeof reinitCallback === 'function') {
                                reinitCallback();
                            }
                        })
                        .always(function() {
                            isFiltering = false;
                        });
                } else {
                    // Fallback to page reload
                    const url = new URL(window.location.href);
                    url.searchParams.set('post_type_filter', filterValue);
                    url.searchParams.delete('paged');
                    window.location.href = url.toString();
                }
            }

            /**
             * Adds token to list.
             *
             * Creates and displays a filter token (badge) for the selected post type.
             * Validates max selections and removes "All" token if present.
             * Automatically triggers filter application after adding token.
             *
             * @since 1.0.0
             * @access private
             * @memberof handlePostTypeFilter
             *
             * @param {string} type - Post type value (e.g., 'post', 'page').
             * @param {string} label - Display label for token.
             * @returns {void}
             */
            function addToken(type, label) {
                // Check maximum selections limit (excluding 'all')
                if (selectedTypes.length >= MAX_SELECTIONS && !selectedTypes.includes('all')) {
                    console.warn('Maximum selection limit reached:', MAX_SELECTIONS);
                    return;
                }

                // Remove "All" token if it exists and update selectedTypes
                if ($tokenList.find('.infyp-all-token').length > 0) {
                    $tokenList.find('.infyp-all-token').remove();
                    // Remove 'all' from selectedTypes
                    selectedTypes = selectedTypes.filter(t => t !== 'all');
                }

                // Check if already added
                if (selectedTypes.includes(type)) return;

                // Create token HTML
                // Get aria-label from existing token or use default
                const removeLabel = $('.infyp-filter-token-remove').first().attr('aria-label') || 'Remove filter';
                let tokenHtml = '<span class="infyp-filter-token" data-type="' + type + '">';
                tokenHtml += label;
                tokenHtml += '<button type="button" class="infyp-filter-token-remove" aria-label="' + escapeHtml(removeLabel) + '">×</button>';
                tokenHtml += '</span>';

                // Insert before input
                $(tokenHtml).insertBefore($filterInput);

                // Update selected types
                selectedTypes.push(type);

                // Apply filter
                applyFilter();
            }

            /**
             * Removes token from list.
             *
             * Removes the filter token from the DOM and updates the selectedTypes array.
             * If no tokens remain after removal, adds an "All" token to represent no filtering.
             * Automatically triggers filter application after removing token.
             *
             * @since 1.0.0
             * @access private
             * @memberof handlePostTypeFilter
             *
             * @param {jQuery} $token - Token element to remove.
             * @returns {void}
             */
            function removeToken($token) {
                const type = $token.data('type');

                // Remove from DOM
                $token.remove();

                // Update selected types
                selectedTypes = selectedTypes.filter(t => t !== type);

                // If no tokens left, add "All" token
                if (selectedTypes.length === 0) {
                    // Get the "All" label from the dropdown option
                    const allLabel = $('.infyp-dropdown-option[data-value="all"] span').first().text() || 'All';
                    const allTokenHtml = '<span class="infyp-filter-token infyp-all-token" data-type="all">' +
                        escapeHtml(allLabel) +
                        '<button type="button" class="infyp-filter-token-remove" aria-label="Clear filter">×</button>' +
                        '</span>';
                    $(allTokenHtml).insertBefore($filterInput);
                    selectedTypes = ['all'];
                }

                // Apply filter
                applyFilter();
            }

            // Initialize
            initializeSelectedTypes();

            // Event: Click on input to show dropdown
            $filterInput.on('focus.postTypeFilter click.postTypeFilter', function(e) {
                e.stopPropagation();
                showDropdown();
            });

            // Event: Type in input to filter dropdown
            $filterInput.on('input.postTypeFilter', function() {
                const searchTerm = $(this).val();
                filterDropdownOptions(searchTerm);
            });

            // Event: Search in dropdown
            $dropdownSearch.on('input.postTypeFilter', function() {
                const searchTerm = $(this).val();
                filterDropdownOptions(searchTerm);
            });

            // Event: Click on dropdown option
            $(document).on('click.postTypeFilter', '.infyp-dropdown-option', function(e) {
                e.stopPropagation();

                const $option = $(this);
                const value = $option.data('value');
                const label = $option.data('label') || $option.find('span').first().text();

                // Handle "All" selection
                if (value === 'all') {
                    // Clear all tokens
                    $tokenList.find('.infyp-filter-token').remove();

                    // Add "All" token with proper label
                    const allTokenHtml = '<span class="infyp-filter-token infyp-all-token" data-type="all">' +
                        escapeHtml(label) +
                        '<button type="button" class="infyp-filter-token-remove" aria-label="Clear filter">×</button>' +
                        '</span>';
                    $(allTokenHtml).insertBefore($filterInput);
                    selectedTypes = ['all'];

                    // Apply filter
                    applyFilter();
                } else {
                    // Check if we've reached the maximum selections
                    if (selectedTypes.length >= MAX_SELECTIONS && !selectedTypes.includes('all')) {
                        alert(infyp_vars.i18n.max_filters_allowed || 'Max ' + MAX_SELECTIONS + ' filters allowed');
                        return;
                    }

                    // Add as token
                    addToken(value, label);
                }

                // Clear input and hide dropdown
                $filterInput.val('');
                hideDropdown();
            });

            // Event: Remove token
            $(document).on('click.postTypeFilter', '#infyp-post-type-tokens .infyp-filter-token-remove', function(e) {
                e.stopPropagation();
                const $token = $(this).closest('.infyp-filter-token');

                // If removing "All", don't do anything
                if ($token.hasClass('infyp-all-token')) {
                    return;
                }

                removeToken($token);
            });

            // Event: Click outside to close dropdown
            $(document).on('click.postTypeFilter', function(e) {
                if (!$(e.target).closest('.infyp-post-type-filter-wrapper').length) {
                    hideDropdown();
                }
            });

            // Event: ESC key to close dropdown
            $(document).on('keydown.postTypeFilter', function(e) {
                if (e.key === 'Escape' && $dropdown.is(':visible')) {
                    hideDropdown();
                    $filterInput.blur();
                }
            });
        }

        /* =========================================================================
           INITIALIZATION
           ========================================================================= */

        /**
         * Initializes post type filtering.
         *
         * Sets up the filter UI and event handlers. Should be called on page load
         * and after table updates that might replace the filter UI. Stores callback
         * references for use in AJAX success handlers.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Function} reinitializeFn - Callback to reinitialize table handlers after filtering.
         * @returns {void}
         */
        function init(reinitializeFn) {
            // Store callbacks for use in AJAX success handler
            if (typeof reinitializeFn === 'function') {
                reinitCallback = reinitializeFn;
            }

            // Initialize filter handlers
            handlePostTypeFilter();
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the filtering module.
         *
         * @since 1.0.0
         *
         * @returns {Object} Public methods.
         * @property {Function} init - Initialize filtering with reinit callback.
         * @property {Function} handlePostTypeFilter - Bind filter event handlers (for re-initialization).
         */
        return {
            init,
            handlePostTypeFilter
        };

    })(jQuery);

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = filtering;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.filtering = filtering;
    }

    /**
     * AJAX Table Loader Module - Handles async table data loading
     *
     * This module enables fast initial page load by:
     * 1. Showing skeleton loader immediately
     * 2. Fetching table data via AJAX after DOM ready
     * 3. Rendering table rows without full page reload
     * 4. Handling pagination via AJAX
     *
     * ## Performance Impact
     *
     * - Initial page load: Timeout (10k+ images) -> < 3 seconds
     * - Table loads asynchronously after page shell renders
     * - Pagination doesn't require full page reload
     *
     * @file AJAX Table Loader Module
     * @author InfyPress
     * @since 1.0.0
     * @module table/ajax-table-loader
     * @requires jQuery
     */

    const ajaxTableLoader = (function($) {

        /* =========================================================================
           CONFIGURATION
           ========================================================================= */

        /**
         * Current page state
         * @type {Object}
         * @private
         */
        let state = {
            currentPage: 1,
            perPage: 20,
            totalPages: 1,
            totalItems: 0,
            search: '',
            orderby: 'seo_score',
            order: 'asc',
            postTypeFilter: 'all',
            parentPost: 0,
            isLoading: false,
            cacheWarmed: false,
            isFirstLoad: true,
            loadAttempts: 0,
            maxLoadAttempts: 2,
            cursor: null // Keyset pagination cursor for deep pages {next: {id, value}, prev: {id, value}}
        };

        /**
         * Load timeout reference for fallback handling
         * @type {number|null}
         * @private
         */
        let loadTimeout = null;

        /**
         * Load timeout duration in milliseconds
         * @constant {number}
         */
        const LOAD_TIMEOUT_MS = 10000; // 10 seconds

        /**
         * Maximum number of entries in the prefetch cache.
         * Keeps memory bounded during long browsing sessions.
         * @constant {number}
         */
        const PREFETCH_CACHE_MAX = 5;

        /**
         * Prefetch cache for pagination
         * Stores prefetched page data for instant navigation.
         * Limited to PREFETCH_CACHE_MAX entries; oldest evicted first.
         * @type {Map}
         * @private
         */
        const prefetchCache = new Map();

        /**
         * Currently prefetching pages (to avoid duplicate requests)
         * @type {Set}
         * @private
         */
        const prefetchingPages = new Set();

        /**
         * Session storage key for tracking cache warm status
         * @type {string}
         * @private
         */
        const CACHE_WARM_KEY = 'infyp_cache_warmed';

        /**
         * Session storage key for tracking index build status
         * @type {string}
         * @private
         */
        const INDEX_BUILD_KEY = 'infyp_indexes_built';

        /**
         * Callback for reinitializing table handlers
         * @type {Function|null}
         * @private
         */
        let reinitCallback = null;

        /* =========================================================================
           SKELETON LOADER
           ========================================================================= */

        /**
         * Shows skeleton loader in table body
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function showSkeletonLoader() {
            const $tbody = $('.wp-list-table.images tbody');
            if (!$tbody.length) return;

            // Get column count from thead
            const columnCount = $('.wp-list-table.images thead th').length || 10;

            // Create skeleton rows
            let skeletonHtml = '';
            for (let i = 0; i < state.perPage; i++) {
                skeletonHtml += '<tr class="infyp-skeleton-row">';
                for (let j = 0; j < columnCount; j++) {
                    skeletonHtml += '<td class="infyp-skeleton-cell"><div class="infyp-skeleton-content"></div></td>';
                }
                skeletonHtml += '</tr>';
            }

            $tbody.html(skeletonHtml);

            // Show skeleton wrapper and hide real table content
            $('.infyp-table-skeleton').show();
            $('.infyp-table-wrapper').addClass('infyp-loading');
        }

        /**
         * Hides skeleton loader and shows real table
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function hideSkeletonLoader() {
            const $skeleton = $('.infyp-table-skeleton');
            const $wrapper = $('.infyp-table-wrapper');

            // Hide the skeleton loader
            $skeleton.hide().addClass('infyp-skeleton-hidden');

            // Show the table wrapper and mark as ready
            // Adding infyp-table-ready class signals to showTableWhenReady() that
            // we've already handled visibility, preventing race condition flicker
            $wrapper
                .show()
                .removeClass('infyp-loading')
                .addClass('infyp-table-ready');

            // Also add body class for search/filter visibility
            $('body').addClass('infyp-page-ready');
        }

        /**
         * Hides cache warming banner
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function hideCacheWarmingBanner() {
            $('#infyp-cache-warming').fadeOut(300, function() {
                $(this).remove();
            });
        }

        /**
         * Triggers background cache warming via AJAX
         * Warms cache for common filter combinations to speed up future loads
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function warmCache() {
            // Check if already warmed this session
            if (sessionStorage.getItem(CACHE_WARM_KEY)) {
                state.cacheWarmed = true;
                // Still hide the banner in case it was shown
                hideCacheWarmingBanner();
                return;
            }

            const nonce = window.infyp_vars?.nonce || window.infyp_data?.nonce || window.infyp_ajax?.nonce || '';

            // Fire and forget - don't block UI
            $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'infyp_warm_table_cache',
                    nonce: nonce
                },
                dataType: 'json'
            })
            .done(function(response) {
                if (response.success) {
                    state.cacheWarmed = true;
                    sessionStorage.setItem(CACHE_WARM_KEY, 'true');
                }
            })
            .always(function() {
                // Hide banner after warming completes (or fails)
                setTimeout(hideCacheWarmingBanner, 500);
            });
        }

        /**
         * Builds search and filter indexes for fast queries
         *
         * Creates lookup tables on first load for 10-100x faster filtering and searching.
         * This runs in the background and doesn't block the UI.
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function buildSearchIndexes() {
            // Check if already built this session
            if (sessionStorage.getItem(INDEX_BUILD_KEY)) {
                return;
            }

            const nonce = window.infyp_vars?.nonce || window.infyp_data?.nonce || window.infyp_ajax?.nonce || '';

            // Fire and forget - don't block UI
            $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'infyp_build_search_indexes',
                    nonce: nonce
                },
                dataType: 'json'
            })
            .done(function(response) {
                if (response.success) {
                    sessionStorage.setItem(INDEX_BUILD_KEY, 'true');
                    // Lookup stats available in response.data.lookup_stats if needed for debugging
                }
            });
        }

        /* =========================================================================
           AJAX DATA LOADING
           ========================================================================= */

        /**
         * Loads table data via AJAX
         *
         * Checks prefetch cache first for instant navigation. Falls back to
         * AJAX request if data not prefetched.
         *
         * @since 1.0.0
         * @access private
         * @param {number} page - Page number to load
         * @param {boolean} updateUrl - Whether to update browser URL (default: true, set false for initial load)
         * @returns {Promise} jQuery AJAX promise
         */
        function loadTableData(page = 1, updateUrl = true) {
            if (state.isLoading) {
                return $.Deferred().reject('Already loading').promise();
            }

            // Check prefetch cache first for instant navigation
            if (prefetchCache.has(page)) {
                const cachedData = prefetchCache.get(page);
                prefetchCache.delete(page); // Use once, then remove

                state.currentPage = page;
                updateTable(cachedData);
                updateState(cachedData);
                if (updateUrl) {
                    updateUrlState();
                }

                // Prefetch adjacent pages for next navigation
                prefetchPage(page - 1);
                prefetchPage(page + 1);

                // Reinitialize handlers
                bindPaginationHandlers();

                return $.Deferred().resolve(cachedData).promise();
            }

            state.isLoading = true;
            state.currentPage = page;

            // Show loading state
            showLoadingOverlay();

            // Get nonce from localized script data
            const nonce = window.infyp_vars?.nonce || window.infyp_data?.nonce || window.infyp_ajax?.nonce || '';

            // Build request data
            const requestData = {
                action: 'infyp_get_table_data',
                nonce: nonce,
                page: page,
                per_page: state.perPage,
                search: (state.search || '').trim(),
                orderby: state.orderby,
                order: state.order,
                post_type_filter: state.postTypeFilter,
                parent_post: state.parentPost
            };

            // For deep pages (>50), use keyset pagination cursor for constant-time access
            // Only for sequential next/prev navigation (cursor available from previous response)
            if (page > 50 && state.cursor) {
                const isNext = page > state.currentPage;
                const cursorDir = isNext ? 'next' : 'prev';
                const cursorData = state.cursor[cursorDir];

                if (cursorData && cursorData.id) {
                    requestData.cursor_id = cursorData.id;
                    requestData.cursor_value = cursorData.value;
                    requestData.cursor_direction = cursorDir;
                }
            }

            return $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: requestData,
                dataType: 'json'
            })
            .done(function(response) {
                if (response.success && response.data) {
                    updateTable(response.data);
                    updateState(response.data);
                    // Only update URL if requested (skip on initial load)
                    if (updateUrl) {
                        updateUrlState();
                    }

                    // Prefetch next page for smoother navigation
                    // Small delay to prioritize current page render
                    setTimeout(function() {
                        prefetchPage(state.currentPage + 1);
                    }, 200);
                } else {
                    showError(response.data?.message || 'Failed to load table data');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('AJAX error:', error);
                showError('Network error. Please try again.');
            })
            .always(function() {
                state.isLoading = false;
                hideLoadingOverlay();
                hideSkeletonLoader();
            });
        }

        /**
         * Prefetch a page in the background for instant navigation
         *
         * Called on mouseenter of pagination buttons to load data before click.
         * Stores result in prefetchCache for instant display when user clicks.
         *
         * @since 1.0.0
         * @access private
         * @param {number} page - Page number to prefetch
         * @returns {void}
         */
        function prefetchPage(page) {
            // Don't prefetch current page, invalid pages, or pages already cached/loading
            if (page === state.currentPage ||
                page < 1 ||
                page > state.totalPages ||
                prefetchCache.has(page) ||
                prefetchingPages.has(page) ||
                state.isLoading) {
                return;
            }

            // Mark as prefetching to avoid duplicate requests
            prefetchingPages.add(page);

            // Get nonce
            const nonce = window.infyp_vars?.nonce || window.infyp_data?.nonce || window.infyp_ajax?.nonce || '';

            // Build request data
            const requestData = {
                action: 'infyp_get_table_data',
                nonce: nonce,
                page: page,
                per_page: state.perPage,
                search: (state.search || '').trim(),
                orderby: state.orderby,
                order: state.order,
                post_type_filter: state.postTypeFilter,
                parent_post: state.parentPost
            };

            // For deep pages, use keyset cursor if available
            if (page > 50 && state.cursor) {
                const isNext = page > state.currentPage;
                const cursorDir = isNext ? 'next' : 'prev';
                const cursorData = state.cursor[cursorDir];

                if (cursorData && cursorData.id) {
                    requestData.cursor_id = cursorData.id;
                    requestData.cursor_value = cursorData.value;
                    requestData.cursor_direction = cursorDir;
                }
            }

            // Silent AJAX request (no loading overlay)
            $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: requestData,
                dataType: 'json'
            })
            .done(function(response) {
                if (response.success && response.data) {
                    // Evict oldest entry if cache is at capacity
                    if (prefetchCache.size >= PREFETCH_CACHE_MAX) {
                        const oldestKey = prefetchCache.keys().next().value;
                        prefetchCache.delete(oldestKey);
                    }
                    // Store in prefetch cache
                    prefetchCache.set(page, response.data);
                }
            })
            .always(function() {
                prefetchingPages.delete(page);
            });
        }

        /**
         * Clear prefetch cache when filters/search change
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function clearPrefetchCache() {
            prefetchCache.clear();
            prefetchingPages.clear();
            state.cursor = null; // Cursor invalid when filters/search change
        }

        /**
         * Shows loading overlay on table
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function showLoadingOverlay() {
            const $table = $('.wp-list-table.images');
            if (!$table.length) return;

            // Remove any existing overlay
            $('.infyp-table-loading-overlay').remove();

            // Add overlay
            $table.parent().css('position', 'relative');
            $table.parent().append(
                '<div class="infyp-table-loading-overlay">' +
                '<div class="infyp-loading-spinner">' +
                '<span class="spinner is-active"></span>' +
                '<span class="infyp-loading-text">' + (window.infyp_vars?.i18n?.loading || 'Loading...') + '</span>' +
                '</div>' +
                '</div>'
            );
        }

        /**
         * Hides loading overlay
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function hideLoadingOverlay() {
            $('.infyp-table-loading-overlay').fadeOut(200, function() {
                $(this).remove();
            });
        }

        /**
         * Updates table with new data
         *
         * @since 1.0.0
         * @access private
         * @param {Object} data - Response data from AJAX
         * @returns {void}
         */
        function updateTable(data) {
            const $tbody = $('.wp-list-table.images tbody');

            if (data.has_items && data.rows_html) {
                $tbody.html(data.rows_html);
            } else {
                // Show no items message
                const columnCount = $('.wp-list-table.images thead th').length || 10;
                $tbody.html(
                    '<tr class="no-items">' +
                    '<td class="colspanchange" colspan="' + columnCount + '">' +
                    (window.infyp_vars?.i18n?.no_images || 'No images found.') +
                    '</td>' +
                    '</tr>'
                );
            }

            // Update pagination
            if (data.pagination_html) {
                // Ensure pagination containers exist (they may be missing on initial load)
                $('.tablenav.top, .tablenav.bottom').each(function() {
                    if (!$(this).find('.tablenav-pages').length) {
                        // Add pagination container if it doesn't exist
                        $(this).append('<div class="tablenav-pages"></div>');
                    }
                });
                $('.tablenav.top .tablenav-pages').html(data.pagination_html);
                $('.tablenav.bottom .tablenav-pages').html(data.pagination_html);
            }

            // Reinitialize handlers
            if (reinitCallback && typeof reinitCallback === 'function') {
                reinitCallback();
            }

            // Reinitialize pagination click handlers
            bindPaginationHandlers();
        }

        /**
         * Updates internal state from response
         *
         * @since 1.0.0
         * @access private
         * @param {Object} data - Response data from AJAX
         * @returns {void}
         */
        function updateState(data) {
            state.currentPage = data.current_page || 1;
            state.totalPages = data.total_pages || 1;
            state.totalItems = data.total || 0;
            state.perPage = data.per_page || 20;
            // Store cursor data for keyset pagination on deep pages
            state.cursor = data.cursor || null;
        }

        /**
         * Renders inline initial data (Phase 3 Performance Optimization)
         *
         * Uses pre-rendered data from PHP to display the table immediately
         * without an AJAX call. Improves initial load by 200-400ms.
         *
         * @since 1.1.0
         * @access private
         * @param {Object} data - Inline data from infyp_vars.initial_table_data
         * @returns {void}
         */
        function renderInlineData(data) {
            const $tbody = $('.wp-list-table.images tbody');

            if (data.has_items && data.rows_html) {
                $tbody.html(data.rows_html);
            } else {
                // Show no items message
                const columnCount = $('.wp-list-table.images thead th').length || 10;
                $tbody.html(
                    '<tr class="no-items">' +
                    '<td class="colspanchange" colspan="' + columnCount + '">' +
                    (window.infyp_vars?.i18n?.no_images || 'No images found.') +
                    '</td>' +
                    '</tr>'
                );
            }

            // Update pagination
            if (data.pagination_html) {
                // Ensure pagination containers exist
                $('.tablenav.top, .tablenav.bottom').each(function() {
                    if (!$(this).find('.tablenav-pages').length) {
                        $(this).append('<div class="tablenav-pages"></div>');
                    }
                });
                $('.tablenav.top .tablenav-pages').html(data.pagination_html);
                $('.tablenav.bottom .tablenav-pages').html(data.pagination_html);
            }

            // Reinitialize handlers
            if (reinitCallback && typeof reinitCallback === 'function') {
                reinitCallback();
            }

            // Bind pagination handlers
            bindPaginationHandlers();
        }

        /**
         * Updates browser URL state
         *
         * Keeps URL clean by only adding parameters when they differ from defaults.
         * paged=1 is never added since page 1 is the default.
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function updateUrlState() {
            const url = new URL(window.location.href);

            // Only set paged if not page 1 (keep URL clean)
            if (state.currentPage > 1) {
                url.searchParams.set('paged', state.currentPage);
            } else {
                url.searchParams.delete('paged');
            }

            if (state.search) {
                url.searchParams.set('s', state.search);
            } else {
                url.searchParams.delete('s');
            }

            if (state.postTypeFilter !== 'all') {
                url.searchParams.set('post_type_filter', state.postTypeFilter);
            } else {
                url.searchParams.delete('post_type_filter');
            }

            if (state.parentPost > 0) {
                url.searchParams.set('parent_post', state.parentPost);
            } else {
                url.searchParams.delete('parent_post');
            }

            // Always include sort params so page reloads preserve sort state
            // and WP_List_Table column headers show the correct sorted indicator.
            url.searchParams.set('orderby', state.orderby);
            url.searchParams.set('order', state.order);

            window.history.replaceState({}, '', url.toString());
        }

        /**
         * Shows error message with retry button
         *
         * @since 1.0.0
         * @access private
         * @param {string} message - Error message
         * @returns {void}
         */
        function showError(message) {
            const $tbody = $('.wp-list-table.images tbody');
            const columnCount = $('.wp-list-table.images thead th').length || 10;

            $tbody.html(
                '<tr class="no-items infyp-error-row">' +
                '<td class="colspanchange" colspan="' + columnCount + '">' +
                '<div class="infyp-table-error">' +
                '<span class="dashicons dashicons-warning"></span> ' +
                message +
                '<button type="button" class="button button-small infyp-retry-load">' +
                (window.infyp_vars?.i18n?.retry || 'Retry') +
                '</button>' +
                '</div>' +
                '</td>' +
                '</tr>'
            );
        }

        /**
         * Shows fallback load button when table fails to load
         *
         * Displayed when initial load times out or fails multiple times.
         * Provides a manual way to trigger table loading.
         *
         * @since 1.1.0
         * @access private
         * @returns {void}
         */
        function showFallbackLoadButton() {
            const $tbody = $('.wp-list-table.images tbody');
            const columnCount = $('.wp-list-table.images thead th').length || 10;
            const i18n = window.infyp_vars?.i18n || {};

            // Clear any skeleton rows
            $tbody.empty();

            $tbody.html(
                '<tr class="no-items infyp-fallback-row">' +
                '<td class="colspanchange" colspan="' + columnCount + '">' +
                '<div class="infyp-table-fallback">' +
                '<span class="dashicons dashicons-images-alt2" style="font-size: 48px; width: 48px; height: 48px; color: #c3c4c7; display: block; margin: 0 auto 16px;"></span>' +
                '<p style="margin: 0 0 16px; color: #646970;">' + (i18n.table_load_failed || 'Table could not load automatically.') + '</p>' +
                '<button type="button" class="button button-primary button-hero infyp-manual-load">' +
                '<span class="dashicons dashicons-update" style="margin-right: 8px;"></span>' +
                (i18n.load_table || 'Load Image Table') +
                '</button>' +
                '</div>' +
                '</td>' +
                '</tr>'
            );

            // Hide skeleton if visible
            hideSkeletonLoader();
        }

        /**
         * Starts load timeout timer
         *
         * If table doesn't load within timeout, shows fallback button.
         *
         * @since 1.1.0
         * @access private
         * @returns {void}
         */
        function startLoadTimeout() {
            clearLoadTimeout();
            loadTimeout = setTimeout(function() {
                // Check if table still doesn't have data
                const $tbody = $('.wp-list-table.images tbody');
                const hasData = $tbody.find('tr:not(.infyp-skeleton-row):not(.no-items):not(.infyp-fallback-row)').length > 0;

                if (!hasData && !state.isLoading) {
                    console.warn('INFYP: Table load timeout, showing fallback button');
                    showFallbackLoadButton();
                }
            }, LOAD_TIMEOUT_MS);
        }

        /**
         * Clears load timeout timer
         *
         * @since 1.1.0
         * @access private
         * @returns {void}
         */
        function clearLoadTimeout() {
            if (loadTimeout) {
                clearTimeout(loadTimeout);
                loadTimeout = null;
            }
        }

        /* =========================================================================
           EVENT HANDLERS
           ========================================================================= */

        /**
         * Binds pagination click handlers for AJAX loading
         *
         * Also binds mouseenter handlers for prefetching adjacent pages.
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function bindPaginationHandlers() {
            // Remove existing handlers
            $(document).off('click.infypAjaxPage', '.infyp-ajax-page');
            $(document).off('keypress.infypAjaxPage', '.infyp-page-input');
            $(document).off('click.infypRetry', '.infyp-retry-load');
            $(document).off('mouseenter.infypPrefetch', '.infyp-ajax-page');

            // Handle pagination button clicks
            $(document).on('click.infypAjaxPage', '.infyp-ajax-page', function(e) {
                e.preventDefault();
                const page = parseInt($(this).data('page'), 10);
                if (page && page !== state.currentPage && !$(this).prop('disabled')) {
                    loadTableData(page).done(function() {
                        scrollToTable();
                    });
                }
            });

            // Handle page number input
            $(document).on('keypress.infypAjaxPage', '.infyp-page-input', function(e) {
                if (e.which === 13) { // Enter key
                    e.preventDefault();
                    const page = parseInt($(this).val(), 10);
                    if (page && page >= 1 && page <= state.totalPages && page !== state.currentPage) {
                        loadTableData(page).done(function() {
                            scrollToTable();
                        });
                    }
                }
            });

            // Handle retry button
            $(document).on('click.infypRetry', '.infyp-retry-load', function(e) {
                e.preventDefault();
                state.loadAttempts = 0; // Reset attempts on manual retry
                loadTableData(state.currentPage);
            });

            // Handle manual load button (fallback)
            $(document).on('click.infypManualLoad', '.infyp-manual-load', function(e) {
                e.preventDefault();
                const $btn = $(this);

                // Show loading state on button
                $btn.prop('disabled', true);
                $btn.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-update-alt spin');
                $btn.contents().filter(function() { return this.nodeType === 3; }).last()[0].textContent = ' Loading...';

                // Reset attempts and try loading
                state.loadAttempts = 0;
                showSkeletonLoader();

                loadTableData(1, false)
                    .always(function() {
                        // Button will be replaced by table content or error
                    });
            });

            // Prefetch on hover for instant navigation
            $(document).on('mouseenter.infypPrefetch', '.infyp-ajax-page', function() {
                const page = parseInt($(this).data('page'), 10);
                if (page && !$(this).prop('disabled')) {
                    prefetchPage(page);
                }
            });

            // Also intercept traditional pagination links (for backwards compatibility)
            $(document).off('click.infypPaginationLinks', '.tablenav-pages a:not(.infyp-ajax-page)');
            $(document).on('click.infypPaginationLinks', '.tablenav-pages a:not(.infyp-ajax-page)', function(e) {
                e.preventDefault();
                const href = $(this).attr('href');
                if (href) {
                    const url = new URL(href, window.location.origin);
                    const page = parseInt(url.searchParams.get('paged'), 10) || 1;
                    loadTableData(page).done(function() {
                        scrollToTable();
                    });
                }
            });
        }

        /**
         * Scrolls to table top
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function scrollToTable() {
            const $wrap = $('.wrap');
            // Check both element exists AND offset() returns a value
            // offset() can return undefined if element is hidden or not in DOM
            if ($wrap.length && $wrap.offset()) {
                $('html, body').animate({
                    scrollTop: $wrap.offset().top - 32
                }, 300);
            }
        }

        /**
         * Parses current URL for state
         *
         * @since 1.0.0
         * @access private
         * @returns {void}
         */
        function parseUrlState() {
            const url = new URL(window.location.href);
            state.currentPage = parseInt(url.searchParams.get('paged'), 10) || 1;
            state.search = url.searchParams.get('s') || '';
            state.postTypeFilter = url.searchParams.get('post_type_filter') || 'all';
            state.parentPost = parseInt(url.searchParams.get('parent_post'), 10) || 0;
            state.orderby = url.searchParams.get('orderby') || 'seo_score';
            state.order = url.searchParams.get('order') || 'asc';
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Initializes AJAX table loader
         *
         * @since 1.0.0
         * @access public
         * @param {Object} options - Configuration options
         * @param {Function} options.reinitCallback - Callback for table handler reinitialization
         * @param {Function} options.filterInitCallback - Callback for filter reinitialization
         * @param {boolean} options.loadOnInit - Whether to load data immediately (default: true)
         * @returns {boolean} True if inline data was rendered (reinitCallback was called), false otherwise
         */
        function init(options = {}) {
            // Track whether inline data was rendered (and reinitCallback was called)
            let inlineDataRendered = false;

            // Store callbacks
            if (typeof options.reinitCallback === 'function') {
                reinitCallback = options.reinitCallback;
            }
            if (typeof options.filterInitCallback === 'function') {
                options.filterInitCallback;
            }

            // Parse URL for initial state
            parseUrlState();

            // Get per_page from screen options if available
            const screenPerPage = parseInt($('#images_per_page').val(), 10);
            if (screenPerPage) {
                state.perPage = screenPerPage;
            }

            // Bind pagination handlers
            bindPaginationHandlers();

            // Clear prefetch cache when tab becomes hidden to free memory
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    clearPrefetchCache();
                }
            });

            // Check if this is first load (cache not warmed yet)
            const isFirstLoad = !sessionStorage.getItem(CACHE_WARM_KEY);

            // Note: Cache warming banner removed - skeleton loader already shows loading state

            // Load data if requested (default: true)
            if (options.loadOnInit !== false) {
                // Start load timeout - shows fallback button if load takes too long
                startLoadTimeout();

                // Phase 3 Optimization: Check for inline initial data first
                // This eliminates the first AJAX call, improving load by 200-400ms
                const inlineData = window.infyp_vars?.initial_table_data;

                if (inlineData && inlineData.has_items !== undefined) {
                    // Use inline data - no AJAX needed for initial load!
                    renderInlineData(inlineData);
                    hideSkeletonLoader();
                    clearLoadTimeout(); // Success - clear timeout

                    // Mark that inline data was rendered (reinitCallback was called)
                    inlineDataRendered = true;

                    // Update state from inline data
                    state.currentPage = inlineData.current_page || 1;
                    state.totalPages = inlineData.total_pages || 1;
                    state.totalItems = inlineData.total || 0;
                    state.perPage = inlineData.per_page || 20;

                    // Prefetch page 2 in background for instant navigation
                    setTimeout(function() {
                        prefetchPage(2);
                    }, 100);

                    // Trigger background optimizations
                    if (isFirstLoad) {
                        buildSearchIndexes();
                        warmCache();
                    }
                } else if (inlineData && inlineData.has_items === false) {
                    // Inline data says no items - show empty state
                    hideSkeletonLoader();
                    clearLoadTimeout();
                    const $tbody = $('.wp-list-table.images tbody');
                    const columnCount = $('.wp-list-table.images thead th').length || 10;
                    $tbody.html(
                        '<tr class="no-items">' +
                        '<td class="colspanchange" colspan="' + columnCount + '">' +
                        (window.infyp_vars?.i18n?.no_images || 'No images found.') +
                        '</td>' +
                        '</tr>'
                    );

                    // Mark as inline data handled (no items, but no AJAX needed)
                    inlineDataRendered = true;

                    // Still warm cache for future
                    if (isFirstLoad) {
                        buildSearchIndexes();
                        warmCache();
                    }
                } else {
                    // Fallback: Check if table already has data (non-AJAX initial load)
                    const $tbody = $('.wp-list-table.images tbody');
                    const hasExistingData = $tbody.find('tr:not(.infyp-skeleton-row):not(.no-items):not(.infyp-fallback-row)').length > 0;

                    if (!hasExistingData) {
                        // Show skeleton while loading
                        showSkeletonLoader();

                        // No data yet, load via AJAX (pass false to skip URL update on initial load)
                        loadTableData(state.currentPage, false)
                            .done(function() {
                                clearLoadTimeout(); // Success - clear timeout
                                // After first data load completes, trigger background optimizations
                                if (isFirstLoad) {
                                    // Build search indexes for fast filtering (10-100x faster)
                                    buildSearchIndexes();
                                    // Warm cache for other filter combinations
                                    warmCache();
                                }
                            })
                            .fail(function() {
                                // Increment attempts and show fallback if max reached
                                state.loadAttempts++;
                                if (state.loadAttempts >= state.maxLoadAttempts) {
                                    showFallbackLoadButton();
                                }
                                // Hide banner even if data load fails
                                hideCacheWarmingBanner();
                            });
                    } else {
                        clearLoadTimeout(); // Already have data
                        if (isFirstLoad) {
                            // Table has data but cache might be cold - optimize in background
                            buildSearchIndexes();
                            warmCache();
                        }
                    }
                }
            }

            state.isFirstLoad = false;

            // Return whether inline data was rendered (and reinitCallback was called)
            // This allows callers to avoid duplicate handler attachment
            return inlineDataRendered;
        }

        /**
         * Reloads current page
         *
         * @since 1.0.0
         * @access public
         * @returns {Promise} jQuery AJAX promise
         */
        function reload() {
            return loadTableData(state.currentPage);
        }

        /**
         * Loads specific page
         *
         * @since 1.0.0
         * @access public
         * @param {number} page - Page number
         * @returns {Promise} jQuery AJAX promise
         */
        function goToPage(page) {
            return loadTableData(page);
        }

        /**
         * Updates search term and reloads
         *
         * @since 1.0.0
         * @access public
         * @param {string} search - Search term
         * @returns {Promise} jQuery AJAX promise
         */
        function search(searchTerm) {
            state.search = searchTerm;
            state.currentPage = 1;
            clearPrefetchCache(); // Clear prefetch cache when search changes
            return loadTableData(1);
        }

        /**
         * Updates filter and reloads
         *
         * @since 1.0.0
         * @access public
         * @param {string} filter - Post type filter
         * @returns {Promise} jQuery AJAX promise
         */
        function setFilter(filter) {
            state.postTypeFilter = filter;
            state.currentPage = 1;
            clearPrefetchCache(); // Clear prefetch cache when filter changes
            return loadTableData(1);
        }

        /**
         * Updates parent post filter and reloads
         *
         * @since 1.2.0
         * @access public
         * @param {number} postId - Parent post ID (0 = all)
         * @returns {Promise} jQuery AJAX promise
         */
        function setParentPost(postId) {
            state.parentPost = parseInt(postId, 10) || 0;
            state.currentPage = 1;
            clearPrefetchCache();
            return loadTableData(1);
        }

        /**
         * Sets parent post in state without triggering a reload
         * Used for restoring session state before init
         *
         * @since 1.2.0
         * @access public
         * @param {number} postId - Parent post ID
         */
        function setParentPostSilent(postId) {
            state.parentPost = parseInt(postId, 10) || 0;
        }

        /**
         * Updates sort column and direction, then reloads
         *
         * @since 1.2.0
         * @access public
         * @param {string} orderby - Column name (e.g. 'seo_score', 'date')
         * @param {string} order - Direction ('asc' or 'desc')
         * @returns {Promise} jQuery AJAX promise
         */
        function setSort(orderby, order) {
            state.orderby = orderby;
            state.order = order;
            state.currentPage = 1;
            clearPrefetchCache();
            return loadTableData(1);
        }

        /**
         * Sets sort in state without triggering a reload
         * Used for restoring session state before init
         *
         * @since 1.2.0
         * @access public
         * @param {string} orderby
         * @param {string} order
         */
        function setSortSilent(orderby, order) {
            state.orderby = orderby;
            state.order = order;
        }

        /**
         * Gets current state
         *
         * @since 1.0.0
         * @access public
         * @returns {Object} Current state
         */
        function getState() {
            return { ...state };
        }

        return {
            init,
            reload,
            goToPage,
            search,
            setFilter,
            setParentPost,
            setParentPostSilent,
            setSort,
            setSortSilent,
            getState,
            showSkeletonLoader,
            hideSkeletonLoader,
            bindPaginationHandlers
        };

    })(jQuery);

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = ajaxTableLoader;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.ajaxTableLoader = ajaxTableLoader;
    }

    /**
     * Page/Post Filter Module
     *
     * AJAX-powered searchable dropdown to filter the image table by a specific
     * page or post. Shows image counts next to each result.
     *
     * @module table/page-filter
     * @since 1.2.0
     */

    const pageFilter = (function($) {

        /* =========================================================================
           CONSTANTS
           ========================================================================= */

        const DEBOUNCE_MS = 300;
        const SESSION_KEY = 'infyp_page_filter';

        /* =========================================================================
           STATE
           ========================================================================= */

        let debounceTimer = null;
        let currentRequest = null;

        /* =========================================================================
           DOM REFERENCES
           ========================================================================= */

        let $wrapper, $display, $input, $dropdown, $hidden, $active, $clearBtn;

        /* =========================================================================
           PRIVATE METHODS
           ========================================================================= */

        /**
         * Search posts/pages via AJAX
         * @param {string} term - Search term
         */
        function searchPosts(term) {
            // Cancel any pending request
            if (currentRequest && currentRequest.readyState !== 4) {
                currentRequest.abort();
            }

            const nonce = window.infyp_vars?.nonce || '';
            const loaderState = ajaxTableLoader.getState();

            currentRequest = $.ajax({
                url: window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'infyp_search_posts_with_images',
                    nonce: nonce,
                    search: term,
                    post_type_filter: loaderState.postTypeFilter || 'all'
                },
                dataType: 'json'
            })
            .done(function(response) {
                if (response.success && response.data && response.data.results) {
                    renderDropdown(response.data.results);
                } else {
                    renderDropdown([]);
                }
            })
            .fail(function(xhr, status) {
                if (status !== 'abort') {
                    renderDropdown([]);
                }
            });
        }

        /**
         * Render dropdown results
         * @param {Array} results - Array of {id, title, type, image_count}
         */
        function renderDropdown(results) {
            if (!results.length) {
                $dropdown.html(
                    '<div class="infyp-page-option-empty">' +
                        (window.infyp_vars?.i18n?.no_results || 'No pages or posts found') +
                    '</div>'
                );
                $dropdown.show();
                return;
            }

            let html = '';
            results.forEach(function(item) {
                const imageLabel = item.image_count === 1 ? 'image' : 'images';
                html += '<div class="infyp-page-option" data-post-id="' + item.id + '">' +
                    '<span class="infyp-page-option-title" title="' + escapeHtml(item.title) + '">' + escapeHtml(item.title) + '</span>' +
                    '<span class="infyp-page-option-meta">' +
                        '<span class="infyp-page-type-badge">' + escapeHtml(item.type) + '</span>' +
                        '<span class="infyp-page-image-count">' + item.image_count + ' ' + imageLabel + '</span>' +
                    '</span>' +
                '</div>';
            });

            $dropdown.html(html);
            $dropdown.show();
        }

        /**
         * Select a post from the dropdown
         * @param {number} postId - Post ID
         * @param {string} title - Post title
         */
        function selectPost(postId, title) {
            $hidden.val(postId);
            $dropdown.hide();
            $input.val('');

            // Show active token, hide input
            if ($active.length) {
                $active.find('.infyp-page-filter-active-title').text(title).attr('title', title);
                $active.show();
            } else {
                // Create active token
                const tokenHtml = '<div class="infyp-page-filter-active" id="infyp-page-filter-active">' +
                    '<span class="dashicons dashicons-admin-page"></span>' +
                    '<span class="infyp-page-filter-active-title" title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</span>' +
                    '<button type="button" class="infyp-page-filter-clear-btn" id="infyp-page-filter-clear">' +
                        '<span class="dashicons dashicons-no-alt"></span>' +
                    '</button>' +
                '</div>';
                $wrapper.prepend(tokenHtml);
                $active = $('#infyp-page-filter-active');
                $clearBtn = $('#infyp-page-filter-clear');
                $clearBtn.on('click', clearFilter);
            }
            $display.hide();

            // Store in session
            try {
                sessionStorage.setItem(SESSION_KEY, JSON.stringify({ id: postId, title: title }));
            } catch (e) { /* silent */ }

            // Trigger table reload
            ajaxTableLoader.setParentPost(postId);
        }

        /**
         * Clear the filter
         */
        function clearFilter() {
            $hidden.val(0);
            if ($active.length) {
                $active.hide();
            }
            $display.show();
            $input.val('');
            $dropdown.hide();

            // Clear session
            try {
                sessionStorage.removeItem(SESSION_KEY);
            } catch (e) { /* silent */ }

            // Trigger table reload
            ajaxTableLoader.setParentPost(0);
        }

        /**
         * Escape HTML entities
         * @param {string} str
         * @returns {string}
         */
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        /**
         * Restore filter state from session storage
         */
        function restoreFromSession() {
            try {
                const saved = sessionStorage.getItem(SESSION_KEY);
                if (!saved) return;

                // If the URL has no parent_post param, the user navigated here
                // without the filter — clear the stale session instead of restoring.
                const url = new URL(window.location.href);
                if (!url.searchParams.has('parent_post')) {
                    sessionStorage.removeItem(SESSION_KEY);
                    return;
                }

                const data = JSON.parse(saved);
                if (data && data.id && data.id > 0) {
                    // Only restore if not already set from URL/PHP
                    if (parseInt($hidden.val(), 10) === 0) {
                        $hidden.val(data.id);

                        // Create active token
                        const tokenHtml = '<div class="infyp-page-filter-active" id="infyp-page-filter-active">' +
                            '<span class="dashicons dashicons-admin-page"></span>' +
                            '<span class="infyp-page-filter-active-title" title="' + escapeHtml(data.title) + '">' + escapeHtml(data.title) + '</span>' +
                            '<button type="button" class="infyp-page-filter-clear-btn" id="infyp-page-filter-clear">' +
                                '<span class="dashicons dashicons-no-alt"></span>' +
                            '</button>' +
                        '</div>';
                        $wrapper.prepend(tokenHtml);
                        $active = $('#infyp-page-filter-active');
                        $clearBtn = $('#infyp-page-filter-clear');
                        $clearBtn.on('click', clearFilter);
                        $display.hide();

                        // Set in ajaxTableLoader state (without triggering reload — init will handle it)
                        if (typeof ajaxTableLoader.setParentPostSilent === 'function') {
                            ajaxTableLoader.setParentPostSilent(data.id);
                        }
                    }
                }
            } catch (e) { /* silent */ }
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Initialize the page filter module
         */
        function init() {
            $wrapper = $('#infyp-page-filter-wrapper');
            if (!$wrapper.length) return;

            $display = $('#infyp-page-filter-display');
            $input = $('#infyp-page-filter-input');
            $dropdown = $('#infyp-page-filter-dropdown');
            $hidden = $('#infyp-parent-post-value');
            $active = $('#infyp-page-filter-active');
            $clearBtn = $('#infyp-page-filter-clear');

            // Restore from session if no URL state
            restoreFromSession();

            // Clear page filter when post type filter changes (silent — no reload, post type filter handles it)
            $(document).on('infyp:postTypeFilterChanged', function() {
                if (parseInt($hidden.val(), 10) > 0) {
                    clearFilterSilent();
                }
            });

            // Input handler (debounced)
            $input.on('input', function() {
                const term = $(this).val().trim();
                clearTimeout(debounceTimer);

                if (term.length === 0) {
                    // Show recent posts with images
                    debounceTimer = setTimeout(function() {
                        searchPosts('');
                    }, 150);
                    return;
                }

                debounceTimer = setTimeout(function() {
                    searchPosts(term);
                }, DEBOUNCE_MS);
            });

            // Focus: show dropdown with recent results
            $input.on('focus', function() {
                if ($dropdown.children().length === 0) {
                    searchPosts('');
                } else {
                    $dropdown.show();
                }
            });

            // Select item from dropdown
            $dropdown.on('click', '.infyp-page-option', function() {
                const postId = $(this).data('post-id');
                const title = $(this).find('.infyp-page-option-title').text();
                selectPost(postId, title);
            });

            // Clear button
            if ($clearBtn.length) {
                $clearBtn.on('click', clearFilter);
            }

            // Close dropdown on click outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#infyp-page-filter-wrapper').length) {
                    $dropdown.hide();
                }
            });

            // Keyboard navigation
            $input.on('keydown', function(e) {
                const $options = $dropdown.find('.infyp-page-option');
                const $focused = $dropdown.find('.infyp-page-option.is-focused');

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if ($focused.length) {
                        const $next = $focused.removeClass('is-focused').next('.infyp-page-option');
                        if ($next.length) {
                            $next.addClass('is-focused');
                            scrollIntoViewIfNeeded($next[0], $dropdown[0]);
                        } else {
                            $options.first().addClass('is-focused');
                        }
                    } else {
                        $options.first().addClass('is-focused');
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if ($focused.length) {
                        const $prev = $focused.removeClass('is-focused').prev('.infyp-page-option');
                        if ($prev.length) {
                            $prev.addClass('is-focused');
                            scrollIntoViewIfNeeded($prev[0], $dropdown[0]);
                        } else {
                            $options.last().addClass('is-focused');
                        }
                    } else {
                        $options.last().addClass('is-focused');
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if ($focused.length) {
                        $focused.trigger('click');
                    }
                } else if (e.key === 'Escape') {
                    $dropdown.hide();
                    $input.blur();
                }
            });
        }

        /**
         * Scroll element into view within a scrollable container
         */
        function scrollIntoViewIfNeeded(el, container) {
            const rect = el.getBoundingClientRect();
            const containerRect = container.getBoundingClientRect();
            if (rect.bottom > containerRect.bottom) {
                el.scrollIntoView({ block: 'end', behavior: 'smooth' });
            } else if (rect.top < containerRect.top) {
                el.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        }

        /**
         * Clear the filter UI and state without triggering a table reload.
         * Used when another filter (e.g. post type) already triggers its own reload.
         */
        function clearFilterSilent() {
            $hidden.val(0);
            if ($active.length) {
                $active.hide();
            }
            $display.show();
            $input.val('');
            $dropdown.hide();

            // Clear session
            try {
                sessionStorage.removeItem(SESSION_KEY);
            } catch (e) { /* silent */ }

            // Update state without reload
            ajaxTableLoader.setParentPostSilent(0);
        }

        /**
         * Clear the filter externally (e.g., when post type filter changes)
         */
        function clear() {
            if (parseInt($hidden?.val() || '0', 10) > 0) {
                clearFilter();
            }
        }

        return {
            init,
            clear
        };

    })(jQuery);

    /**
     * SEO Score Sort Module
     *
     * Toggle button that cycles through SEO score sort directions:
     * asc → desc → inactive (upload date) → asc
     *
     * Default sort is seo_score ASC (low scores first).
     *
     * @module table/seo-sort
     * @since 1.2.0
     */

    const seoSort = (function($) {

        /* =========================================================================
           CONSTANTS
           ========================================================================= */

        const SESSION_KEY = 'infyp_seo_sort';
        // Cycle: asc (default) → desc → inactive → asc
        const CYCLE = ['asc', 'desc', ''];

        /* =========================================================================
           DOM REFERENCES
           ========================================================================= */

        let $btn, $arrow;

        /* =========================================================================
           PRIVATE METHODS
           ========================================================================= */

        /**
         * Update button UI for current direction
         * @param {string} direction - '', 'desc', or 'asc'
         */
        function updateUI(direction) {
            $btn.attr('data-direction', direction);

            if (direction === 'desc') {
                $btn.addClass('active');
                $arrow.html('&#9662;'); // ▾
            } else if (direction === 'asc') {
                $btn.addClass('active');
                $arrow.html('&#9652;'); // ▴
            } else {
                $btn.removeClass('active');
                $arrow.html('');
            }
        }

        /**
         * Deactivate the date sort button when SEO sort takes over
         */
        function deactivateDateSort() {
            const $dateBtn = $('#infyp-date-sort-btn');
            if ($dateBtn.length) {
                $dateBtn.attr('data-direction', '').removeClass('active');
                $dateBtn.find('.infyp-date-sort-arrow').html('');
            }
            try {
                sessionStorage.removeItem('infyp_date_sort');
            } catch (e) { /* silent */ }
        }

        /**
         * Activate the date sort button to desc when SEO sort turns off
         */
        function activateDateSort() {
            const $dateBtn = $('#infyp-date-sort-btn');
            if ($dateBtn.length) {
                $dateBtn.attr('data-direction', 'desc').addClass('active');
                $dateBtn.find('.infyp-date-sort-arrow').html('&#9662;'); // ▾
            }
            try {
                sessionStorage.setItem('infyp_date_sort', 'desc');
            } catch (e) { /* silent */ }
        }

        /**
         * Handle button click — cycle through directions
         */
        function handleClick() {
            const current = $btn.attr('data-direction') || '';
            const idx = CYCLE.indexOf(current);
            const next = CYCLE[(idx + 1) % CYCLE.length];

            updateUI(next);

            // Store in session
            try {
                if (next) {
                    sessionStorage.setItem(SESSION_KEY, next);
                } else {
                    sessionStorage.setItem(SESSION_KEY, 'off');
                }
            } catch (e) { /* silent */ }

            // Trigger table reload
            if (next) {
                deactivateDateSort();
                ajaxTableLoader.setSort('seo_score', next);
            } else {
                activateDateSort();
                ajaxTableLoader.setSort('date', 'desc');
            }
        }

        /**
         * Restore state from session storage
         */
        function restoreFromSession() {
            try {
                const saved = sessionStorage.getItem(SESSION_KEY);
                if (!saved) return; // No saved state — use default from URL/PHP

                // Only restore if not already set from URL
                const current = $btn.attr('data-direction') || '';
                const urlOrderby = new URL(window.location.href).searchParams.get('orderby');

                // If URL has explicit params, don't override
                if (urlOrderby) return;

                if (saved === 'asc' || saved === 'desc') {
                    if (current !== saved) {
                        updateUI(saved);
                        if (typeof ajaxTableLoader.setSortSilent === 'function') {
                            ajaxTableLoader.setSortSilent('seo_score', saved);
                        }
                    }
                } else if (saved === 'off') {
                    // User explicitly turned off SEO sort — use date sort
                    updateUI('');
                    if (typeof ajaxTableLoader.setSortSilent === 'function') {
                        ajaxTableLoader.setSortSilent('date', 'desc');
                    }
                }
            } catch (e) { /* silent */ }
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Initialize the SEO sort module
         */
        function init() {
            $btn = $('#infyp-seo-sort-btn');
            if (!$btn.length) return;

            $arrow = $btn.find('.infyp-seo-sort-arrow');

            // Restore from session if no URL state
            restoreFromSession();

            // Bind click handler
            $btn.on('click', handleClick);
        }

        return {
            init
        };

    })(jQuery);

    /**
     * Upload Date Sort Module
     *
     * Toggle button that cycles through upload date sort directions:
     * desc (newest first) → asc (oldest first) → inactive (back to default SEO sort)
     *
     * @module table/date-sort
     * @since 1.2.0
     */

    const dateSort = (function($) {

        /* =========================================================================
           CONSTANTS
           ========================================================================= */

        const SESSION_KEY = 'infyp_date_sort';
        // Cycle: inactive → desc (newest) → asc (oldest) → inactive
        const CYCLE = ['', 'desc', 'asc'];

        /* =========================================================================
           DOM REFERENCES
           ========================================================================= */

        let $btn, $arrow;

        /* =========================================================================
           PRIVATE METHODS
           ========================================================================= */

        /**
         * Update button UI for current direction
         * @param {string} direction - '', 'desc', or 'asc'
         */
        function updateUI(direction) {
            $btn.attr('data-direction', direction);

            if (direction === 'desc') {
                $btn.addClass('active');
                $arrow.html('&#9662;'); // ▾
            } else if (direction === 'asc') {
                $btn.addClass('active');
                $arrow.html('&#9652;'); // ▴
            } else {
                $btn.removeClass('active');
                $arrow.html('');
            }
        }

        /**
         * Handle button click — cycle through directions
         */
        function handleClick() {
            const current = $btn.attr('data-direction') || '';
            const idx = CYCLE.indexOf(current);
            const next = CYCLE[(idx + 1) % CYCLE.length];

            updateUI(next);

            // Store in session
            try {
                if (next) {
                    sessionStorage.setItem(SESSION_KEY, next);
                } else {
                    sessionStorage.removeItem(SESSION_KEY);
                }
            } catch (e) { /* silent */ }

            // Trigger table reload
            if (next) {
                // Deactivate SEO sort button when date sort is active
                deactivateSeoSort();
                ajaxTableLoader.setSort('date', next);
            } else {
                // Return to default: seo_score asc
                reactivateSeoSort();
                ajaxTableLoader.setSort('seo_score', 'asc');
            }
        }

        /**
         * Deactivate the SEO sort button visually when date sort takes over
         */
        function deactivateSeoSort() {
            const $seoBtn = $('#infyp-seo-sort-btn');
            if ($seoBtn.length) {
                $seoBtn.attr('data-direction', '').removeClass('active');
                $seoBtn.find('.infyp-seo-sort-arrow').html('');
            }
            try {
                sessionStorage.setItem('infyp_seo_sort', 'off');
            } catch (e) { /* silent */ }
        }

        /**
         * Reactivate the SEO sort button to default asc state
         */
        function reactivateSeoSort() {
            const $seoBtn = $('#infyp-seo-sort-btn');
            if ($seoBtn.length) {
                $seoBtn.attr('data-direction', 'asc').addClass('active');
                $seoBtn.find('.infyp-seo-sort-arrow').html('&#9652;'); // ▴
            }
            try {
                sessionStorage.setItem('infyp_seo_sort', 'asc');
            } catch (e) { /* silent */ }
        }

        /**
         * Restore state from session storage
         */
        function restoreFromSession() {
            try {
                const saved = sessionStorage.getItem(SESSION_KEY);
                if (!saved) return;

                const urlOrderby = new URL(window.location.href).searchParams.get('orderby');
                if (urlOrderby) return; // URL takes priority

                if (saved === 'desc' || saved === 'asc') {
                    updateUI(saved);
                    if (typeof ajaxTableLoader.setSortSilent === 'function') {
                        ajaxTableLoader.setSortSilent('date', saved);
                    }
                    deactivateSeoSort();
                }
            } catch (e) { /* silent */ }
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Initialize the upload date sort module
         */
        function init() {
            $btn = $('#infyp-date-sort-btn');
            if (!$btn.length) return;

            $arrow = $btn.find('.infyp-date-sort-arrow');

            // Restore from session if no URL state
            restoreFromSession();

            // Bind click handler
            $btn.on('click', handleClick);
        }

        /**
         * Deactivate button externally (when SEO sort takes over)
         */
        function deactivate() {
            updateUI('');
            try {
                sessionStorage.removeItem(SESSION_KEY);
            } catch (e) { /* silent */ }
        }

        return {
            init,
            deactivate
        };

    })(jQuery);

    /**
     * Live Search Module - AJAX table filtering for image search
     *
     * This module provides real-time search functionality for the WordPress
     * media library table. It implements debounced AJAX requests to filter
     * images by filename, alt text, title, or other metadata without page reloads.
     *
     * ## Features
     *
     * - Debounced input handling (500ms delay)
     * - Minimum 2 character search threshold
     * - Loading overlay during search
     * - Browser history state management
     * - Clear button for resetting search
     * - Enter key for immediate search
     *
     * ## Dependencies
     *
     * - jQuery
     * - WordPress media library page structure
     *
     * ## Usage
     *
     * ```javascript
     * import liveSearch from './table/live-search.js';
     *
     * // Initialize with table reinit callback
     * liveSearch.init(reinitializeTableHandlers);
     *
     * // Programmatic search
     * liveSearch.search('landscape');
     *
     * // Clear search
     * liveSearch.clear();
     * ```
     *
     * @file AJAX-based live search with debouncing for the WordPress media library table.
     * @author InfyPress
     * @since 1.0.0
     * @module table/live-search
     * @requires jQuery
     */

    const liveSearch = (function($) {

        /* =========================================================================
           CONFIGURATION
           ========================================================================= */

        /**
         * Debounce timer reference for search input
         * @type {number|null}
         * @private
         */
        let debounceTimer = null;

        /**
         * Flag to prevent concurrent search requests
         * @type {boolean}
         * @private
         */
        let isSearching = false;

        /**
         * Delay in milliseconds before executing search after input stops
         * @constant {number}
         */
        const DEBOUNCE_DELAY = 500;

        /**
         * Minimum characters required before triggering search
         * @constant {number}
         */
        const MIN_SEARCH_LENGTH = 2;

        /**
         * CSS selectors for DOM elements
         * @constant {Object}
         * @property {string} wrapper - Search wrapper container
         * @property {string} input - Search input field
         * @property {string} spinner - Loading spinner element
         * @property {string} clearBtn - Clear search button
         * @property {string} searchBox - Search box container
         * @property {string} table - WordPress list table
         * @property {string} tableWrapper - Table wrapper container
         */
        const selectors = {
            input: '#infyp-modern-search-input',
            spinner: '.infyp-search-spinner',
            clearBtn: '.infyp-search-clear'};

        /**
         * Callback function to reinitialize table handlers after AJAX update
         * @type {Function|null}
         * @private
         */
        let reinitCallback = null;

        /* =========================================================================
           INITIALIZATION
           ========================================================================= */

        /**
         * Initializes live search functionality.
         *
         * Sets up event handlers for search input, clear button, and keyboard shortcuts.
         * Stores the reinit callback for use after AJAX table updates. Should be called
         * once on page load with the reinitialization callback.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Function} reinitTableHandlers - Callback to reinitialize table event handlers
         *                                         after AJAX replaces table content.
         * @returns {void}
         */
        function init(reinitTableHandlers) {
            const $input = $(selectors.input);
            if (!$input.length) return;

            reinitCallback = reinitTableHandlers;
            bindEvents();
        }

        /* =========================================================================
           EVENT HANDLERS
           ========================================================================= */

        /**
         * Binds event handlers for search functionality.
         *
         * Sets up:
         * - Input event with debounce for real-time filtering
         * - Enter key for immediate search (bypasses debounce)
         * - Clear button click handler
         * - Show/hide clear button based on input value
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function bindEvents() {
            const $input = $(selectors.input);

            // Input event with debounce
            $input.on('input', function() {
                const query = $(this).val().trim();

                clearTimeout(debounceTimer);

                if (query.length > 0) {
                    showClearButton();
                } else {
                    hideClearButton();
                }

                // Only search if minimum length or clearing
                if (query.length >= MIN_SEARCH_LENGTH || query.length === 0) {
                    debounceTimer = setTimeout(function() {
                        performSearch(query);
                    }, DEBOUNCE_DELAY);
                }
            });

            // Enter key to search immediately
            $input.on('keydown', function(e) {
                if (e.keyCode === 13) {
                    e.preventDefault();
                    clearTimeout(debounceTimer);
                    performSearch($(this).val().trim());
                }
            });

            // Clear button click
            $(document).on('click', selectors.clearBtn, function(e) {
                e.preventDefault();
                clearSearch();
            });
        }

        /* =========================================================================
           SEARCH OPERATIONS
           ========================================================================= */

        /**
         * Performs AJAX search and updates table with results.
         *
         * Uses the ajaxTableLoader to search with the async table loading architecture.
         * This ensures the search works correctly with the performance-optimized
         * table loading system that skips prepare_items() on page load.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {string} query - Search query string (empty string clears search).
         * @returns {void}
         */
        function performSearch(query) {
            if (isSearching) return;
            isSearching = true;

            showSpinner();

            // Use ajaxTableLoader if available (preferred for performance)
            if (window.infyp && window.infyp.ajaxTableLoader) {
                window.infyp.ajaxTableLoader.search(query)
                    .done(function() {
                        // Reinitialize table handlers
                        if (typeof reinitCallback === 'function') {
                            reinitCallback();
                        }
                    })
                    .always(function() {
                        hideSpinner();
                        isSearching = false;
                    });
            } else {
                // Fallback to page reload
                const currentUrl = new URL(window.location.href);

                if (query.length >= MIN_SEARCH_LENGTH) {
                    currentUrl.searchParams.set('s', query);
                } else {
                    currentUrl.searchParams.delete('s');
                }

                // Reset to page 1 when searching
                currentUrl.searchParams.delete('paged');

                window.location.href = currentUrl.toString();
            }
        }

        /**
         * Clears search input and reloads table without filter.
         *
         * Resets the search input field, hides the clear button,
         * and performs an empty search to show all results.
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function clearSearch() {
            const $input = $(selectors.input);
            $input.val('');
            hideClearButton();
            performSearch('');
        }

        /**
         * Shows the spinner in the search input field.
         *
         * Activates the WordPress spinner to indicate search in progress.
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function showSpinner() {
            $(selectors.spinner).addClass('is-active');
        }

        /**
         * Hides the spinner in the search input field.
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function hideSpinner() {
            $(selectors.spinner).removeClass('is-active');
        }

        /**
         * Shows the clear (X) button in the search input.
         *
         * Displayed when there is text in the search input.
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function showClearButton() {
            $(selectors.clearBtn).show();
        }

        /**
         * Hides the clear (X) button in the search input.
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function hideClearButton() {
            $(selectors.clearBtn).hide();
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the live search module.
         *
         * @since 1.0.0
         *
         * @returns {Object} Public methods.
         * @property {Function} init - Initialize the module with reinit callback.
         * @property {Function} search - Perform a search programmatically.
         * @property {Function} clear - Clear the current search.
         */
        return {
            init: init,
            search: performSearch,
            clear: clearSearch
        };

    })(jQuery);

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


    const keywordManager = (function($) {

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

    /**
     * Alt Text Modal Module - Alt text editing and optimization
     *
     * This module provides a modal dialog for editing and optimizing image alt text.
     * It includes real-time character/word counting, SEO scoring, keyword usage tracking,
     * and AI-powered optimization capabilities.
     *
     * ## Features
     *
     * - Real-time alt text editing with character/word count validation
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
     * | infyp_load_alt_text_modal     | Load modal HTML from PHP template          |
     * | infyp_update_image_data       | Save alt text changes to database          |
     * | infyp_optimize_single_field   | Generate AI-optimized alt text             |
     *
     * @file Alt text modal module for image SEO optimization.
     * @author AI Image SEO
     * @since 1.0.0
     * @module modals/alt-text-modal
     * @requires jQuery
     * @requires api
     * @requires config
     * @requires scoring-engine
     * @requires base-modal
     * @requires notifications
     * @requires keyword-manager
     */

    const altTextModal = (function($) {

        /* =========================================================================
           MODULE DEPENDENCIES
           ========================================================================= */

        // Get config functions
        const getScoringParams = config.getScoringParams;
        config.getAltParams;
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
         * Shows alt text editor modal.
         *
         * Fetches fresh image URL and metadata from server before displaying the modal
         * to ensure high-quality image preview. Falls back to table thumbnail if fetch fails.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {number} imageId - The WordPress attachment ID.
         * @param {string} currentAltText - Current alt text value to display in editor.
         * @returns {void}
         */
        function showAltTextEditor(imageId, currentAltText) {
            $('#infyp-lightbox-overlay').remove();
            $('#infyp-alt-text-lightbox').remove();

            // Fetch fresh image data first
            api.post('infyp_get_fresh_image_url', {
                image_id: imageId
            }).done(function(response) {
                if (response.success && response.data) {
                    // Use fresh data from server
                    const imageUrl = response.data.urls?.display || response.data.urls?.large || response.data.urls?.full || '';
                    displayAltTextModal(imageId, currentAltText, imageUrl);
                } else {
                    // Fallback to thumbnail from table
                    const $row = $(`tr[data-id="${imageId}"]`);
                    const $imageThumb = $row.find('.column-thumbnail img');
                    let imageUrl = $imageThumb.attr('src') || '';

                    if (imageUrl) {
                        imageUrl = imageUrl.replace(/-\d+x\d+(\.\w+)$/, '$1');
                    }

                    displayAltTextModal(imageId, currentAltText, imageUrl);
                }
            }).fail(function() {
                // Fallback on error
                const $row = $(`tr[data-id="${imageId}"]`);
                const $imageThumb = $row.find('.column-thumbnail img');
                let imageUrl = $imageThumb.attr('src') || '';

                if (imageUrl) {
                    imageUrl = imageUrl.replace(/-\d+x\d+(\.\w+)$/, '$1');
                }

                displayAltTextModal(imageId, currentAltText, imageUrl);
            });
        }

        /**
         * Displays alt text modal.
         *
         * Wrapper function that delegates to PHP template loading method.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {number} imageId - The WordPress attachment ID.
         * @param {string} currentAltText - Current alt text value.
         * @param {string} imageUrl - Image URL for preview.
         * @returns {void}
         */
        function displayAltTextModal(imageId, currentAltText, imageUrl) {
            // Use PHP/AJAX approach to load modal
            loadAltTextModalFromPHP(imageId, currentAltText, imageUrl);
        }

        /* =========================================================================
           PHP TEMPLATE LOADING
           ========================================================================= */

        /**
         * Loads alt text modal from PHP template via AJAX.
         *
         * Requests modal HTML from server for consistent styling and i18n support.
         * Populates modal with dynamic content including keywords, counters, and
         * scoring displays.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {number} imageId - The WordPress attachment ID.
         * @param {string} currentAltText - Current alt text value.
         * @param {string} imageUrl - Image URL for preview.
         * @returns {void}
         */
        function loadAltTextModalFromPHP(imageId, currentAltText, imageUrl) {
            const i18n = window.infyp_vars.i18n;

            // Load modal HTML from PHP template
            $.post(window.infyp_vars.ajax_url, {
                action: 'infyp_load_alt_text_modal',
                nonce: window.infyp_vars.nonce,
                image_id: imageId,
                current_alt_text: currentAltText,
                image_url: imageUrl
            }).done(function(response) {
                if (response.success && response.data) {
                    // Remove any existing modal
                    $('#infyp-lightbox-overlay, #infyp-alt-text-lightbox').remove();

                    // Append the PHP-generated modal HTML
                    $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                    // Update modal with dynamic content
                    const $modal = $('#infyp-alt-text-lightbox');
                    const $imageIdSpan = $modal.find('.infyp-image-id');
                    const $imagePreview = $modal.find('.infyp-modal-image-preview img');
                    const $textarea = $modal.find('#infyp-alt-text-editor');
                    const $saveBtn = $modal.find('.infyp-save-alt-text');
                    const $optimizeBtn = $modal.find('#infyp-optimize-alt-text-btn');
                    const $keywordsContainer = $modal.find('.infyp-keywords-container');

                    // Set dynamic attributes and values
                    $modal.attr('data-image-id', imageId);
                    $imageIdSpan.text('#' + imageId);
                    $imagePreview.attr('src', imageUrl);
                    $textarea.val(currentAltText);
                    $saveBtn.attr('data-image-id', imageId);
                    $optimizeBtn.attr('data-id', imageId);

                    // Add keywords if they exist
                    if (response.data.keywords_html) {
                        $keywordsContainer.html(baseModal.sanitizeHtml(response.data.keywords_html)).show();
                    } else {
                        $keywordsContainer.hide();
                    }

                    // Initialize counter and show modal using .is-open class system
                    updateAltTextCounter();

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

                    // Focus textarea after animation
                    setTimeout(() => $textarea.get(0).focus(), 200);
                } else {
                    console.error('Failed to load alt text modal:', response);
                    alert(i18n.error_loading_modal || 'Error loading alt text modal. Please try again.');
                }
            }).fail(function() {
                console.error('AJAX request failed for alt text modal');
                alert(i18n.error_loading_modal || 'Error loading alt text modal. Please try again.');
            });
        }

        /* =========================================================================
           COUNTER AND SCORING
           ========================================================================= */

        /**
         * Updates alt text counter and scoring display.
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
        function updateAltTextCounter() {
            const $textarea = $('#infyp-alt-text-editor');
            const $counter = $('.infyp-alt-text-counter');
            const value = $textarea.val();
            const charCount = [...value].length;
            const wordCount = value.trim() ? value.trim().split(/\s+/).length : 0;
            const params = getScoringParams();
            const altParams = params.alt;
            const i18n = window.infyp_vars.i18n;

            let score = 0;
            const weights = getWeights();
            // Shared tolerance-aware status: green within the play room around the
            // optimal range; 'error' red is reserved for missing values.
            let status = {
                class: 'empty',
                icon: 'dismiss',
                text: i18n.missing_alt_text || 'Missing',
                ok: false,
                tooltip: ''
            };

            if (value.trim()) {
                score += weights.alt_presence;
                status = scoringEngine.lengthStatus(value, altParams);
                score += status.ok ? weights.alt_quality : weights.alt_quality * 0.5;
            }

            const tooltipAttr = status.tooltip ? ` title="${status.tooltip}"` : '';
            let counterHtml = `
            <div class="infyp-modal-counter-badges">
                <span class="infyp-field-feedback ${status.class}"${tooltipAttr}>
                    <span class="dashicons dashicons-${status.icon}"></span> ${status.text}
                </span>
                <span class="infyp-field-feedback char-count">${charCount} | ${altParams.max_chars} ${i18n.chars || 'chars'}</span>
                <span class="infyp-field-feedback word-count">${wordCount} | ${altParams.max_words} ${i18n.words || 'words'}</span>
            </div>
        `;
            $counter.html(counterHtml);

            const imageId = $('.infyp-save-alt-text').data('image-id');
            if (imageId) {
                const $row = $(`tr[data-id="${imageId}"]`);
                const $altInput = $row.find('.infyp-alt-text-trigger');
                const $badgeContainer = $altInput.closest('.infyp-field-wrapper').find('.infyp-badge-container');
                const tableStatusHtml = `<span class="dashicons dashicons-${status.icon}"></span> ${value.trim() ? status.text : (i18n.missing || 'Missing')}`;

                $badgeContainer.html(`
                <div class="infyp-field-feedback char-count">${charCount} | ${altParams.max_chars} ${i18n.chars || 'chars'}</div>
                <div class="infyp-field-feedback ${status.class}"${tooltipAttr}>${tableStatusHtml}</div>
            `);

                updateLiveSEOScore(imageId, 'alt_text', value);
            }

            const maxScore = weights.alt_presence + weights.alt_quality;
            const percentage = Math.round((score / maxScore) * 100);
            const progressClass = percentage >= 90 ? 'success' : percentage >= 60 ? 'warning' : 'error';

            // Circle SVG
            const circumference = 2 * Math.PI * 52;
            const dashOffset = circumference - (percentage / 100) * circumference;

            $('#infyp-alt-text-lightbox .infyp-score-percentage').html(`
            <div class="infyp-circle-container">
                <svg class="infyp-circle-progress infyp-circle-${progressClass}" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                    <circle class="infyp-circle-bg" cx="60" cy="60" r="52"></circle>
                    <circle class="infyp-circle-fg" cx="60" cy="60" r="52" style="stroke-dashoffset: ${dashOffset}"></circle>
                    <text class="infyp-circle-text" x="60" y="60" text-anchor="middle" dominant-baseline="central">${percentage}%</text>
                </svg>
                <h4>${i18n.alt_text_score || 'Alt Text Score'}</h4>
                <div class="infyp-score-points-breakdown">
                    <span class="infyp-points-badge infyp-points-earned infyp-badge-${progressClass}">${Math.round(score)}</span>
                    <span class="infyp-points-separator">|</span>
                    <span class="infyp-points-badge infyp-points-total infyp-badge-success">${maxScore}</span>
                </div>
            </div>
        `);

            // Helper function to create metric circle (same as filename modal)
            const createAltMetricCircle = (current, min, max, label, valueText, zone) => {
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
            const charValueText = `${charCount} / ${altParams.max_chars}`;
            breakdownHtml += createAltMetricCircle(
                charCount,
                altParams.min_chars,
                altParams.max_chars,
                i18n.characters_label || 'Characters',
                charValueText,
                scoringEngine.evaluateChars(charCount, altParams)
            );

            // Words metric
            const wordValueText = `${wordCount} / ${altParams.max_words}`;
            breakdownHtml += createAltMetricCircle(
                wordCount,
                altParams.min_words,
                altParams.max_words,
                i18n.words_label || 'Words',
                wordValueText,
                scoringEngine.evaluateWords(wordCount, altParams)
            );

            breakdownHtml += '</div>';

            $('#infyp-alt-text-lightbox .infyp-score-breakdown').html(breakdownHtml);

            // Update keyword usage display in real-time
            updateKeywordUsageDisplay(value, false);
        }

        /* =========================================================================
           AI OPTIMIZATION
           ========================================================================= */

        /**
         * Handles AI optimization for alt text within modal.
         *
         * Calls the single field optimization API to generate AI-optimized alt text
         * based on image content and keywords. Updates textarea and counter on success.
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
            const $textarea = $('#infyp-alt-text-editor');
            window.infyp_vars.i18n;

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
            const $contextPrompt = $('#infyp-alt-text-lightbox').find('.infyp-context-prompt-textarea');
            const contextPromptVal = $contextPrompt.length ? $contextPrompt.val().trim() : '';

            // Call the single field optimization API
            api.post('infyp_optimize_single_field', {
                image_id: imageId,
                field_type: 'alt_text',
                keywords: keywords,
                custom_prompt: contextPromptVal
            }).done(function(response) {
                if (response.success && response.data.optimized_value) {
                    // Update the textarea with the optimized alt text
                    $textarea.val(response.data.optimized_value);
                    updateAltTextCounter();

                    // Show success notification
                    notifications.show(infyp_vars.i18n.alt_text_optimized || 'Alt text optimized with AI', 'success');
                } else {
                    notifications.show(response.data?.message || infyp_vars.i18n.error_optimizing || 'Error optimizing alt text', 'error');
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
            const $modal = $('#infyp-alt-text-lightbox');

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
         * Binds body-level event handlers for alt text modal.
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
            $body.on('input', '#infyp-alt-text-editor', function() {
                updateAltTextCounter();
            });

            // Save button handler
            $body.on('click', '.infyp-save-alt-text', function() {
                saveAltText();
            });

            // AI optimization handler
            $body.on('click', '#infyp-alt-text-lightbox .infyp-optimize-ai', handleOptimizeAI);

            // Close/cancel handlers
            $body.on('click', '.infyp-cancel-alt-text,.infyp-alt-text-lightbox-close', handleClose);

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
         * Saves alt text to database.
         *
         * Sends updated alt text to server, updates table row display with new badges,
         * recalculates SEO score, and closes modal on success.
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function saveAltText() {
            const $saveBtn = $('.infyp-save-alt-text');
            const imageId = $saveBtn.data('image-id');
            const newAltText = $('#infyp-alt-text-editor').val();
            const $lightbox = $('#infyp-alt-text-lightbox');
            const $overlay = $('#infyp-lightbox-overlay');
            const $row = $(`tr[data-id="${imageId}"]`);
            const $altInput = $row.find('.infyp-alt-text-trigger');
            const i18n = window.infyp_vars.i18n;

            $saveBtn.prop('disabled', true).text(i18n.saving || 'Saving...');

            // Get scoring parameters
            const params = getScoringParams();
            const altParams = params.alt;

            api.post('infyp_update_image_data', {
                image_id: imageId,
                field: 'alt_text',
                value: newAltText
            }).done(function(response) {
                if (response.success) {
                    $altInput.val(newAltText).data('original-value', newAltText);
                    const charCount = [...newAltText].length;
                    // Shared tolerance-aware status; 'error' red is reserved for
                    // missing values.
                    const status = newAltText
                        ? scoringEngine.lengthStatus(newAltText, altParams)
                        : { class: 'empty', icon: 'dismiss', text: i18n.missing_alt_text || 'Missing alt text', tooltip: '' };
                    const tooltipAttr = status.tooltip ? ` title="${status.tooltip}"` : '';

                    const $badgeContainer = $altInput.closest('.infyp-field-wrapper').find('.infyp-badge-container');
                    $badgeContainer.html(`
                    <div class="infyp-field-feedback char-count">${charCount} | ${altParams.max_chars} ${i18n.chars || 'chars'}</div>
                    <div class="infyp-field-feedback ${status.class}"${tooltipAttr}><span class="dashicons dashicons-${status.icon}"></span> ${status.text}</div>
                `);

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
                    $overlay.removeClass('is-visible');
                    $lightbox.removeClass('is-open');

                    // After animation, remove from DOM
                    setTimeout(function() {
                        $overlay.remove();
                        $lightbox.remove();
                    }, 200);

                    // Show success notification
                    baseModal.showNotification(i18n.alt_text_updated || 'Alt text updated successfully', 'success');
                } else {
                    alert(response.data.message || i18n.error_saving || 'Error saving alt text');
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
         * Public interface for the alt text modal module.
         *
         * @since 1.0.0
         *
         * @return {Object} Public methods.
         * @property {Function} show - Show modal with image ID and current alt text.
         * @property {Function} display - Display modal with all parameters.
         * @property {Function} loadFromPHP - Load modal from PHP template.
         * @property {Function} updateCounter - Update character/word counter and scoring.
         * @property {Function} save - Save alt text changes to database.
         * @property {Function} bindEvents - Bind modal event handlers.
         * @property {Function} close - Close and remove modal from DOM.
         */
        return {
            show: showAltTextEditor,
            display: displayAltTextModal,
            loadFromPHP: loadAltTextModalFromPHP,
            updateCounter: updateAltTextCounter,
            save: saveAltText,
            bindEvents: bindEvents,
            close: handleClose
        };
    })(jQuery);

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = altTextModal;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.altTextModal = altTextModal;
    }

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

    const filenameModal = (function($) {

        /* =========================================================================
           MODULE DEPENDENCIES
           ========================================================================= */

        // Get config functions
        const getScoringParams = config.getScoringParams;
        config.getFilenameParams;
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

            let score = 0;
            const isGeneric = /^(DSC|IMG|DCIM|image)[\d_-]+$/i.test(value);
            // Shared tolerance-aware status: green within the play room around the
            // optimal range; 'error' red is reserved for missing values.
            let status = {
                class: 'empty',
                icon: 'dismiss',
                text: i18n.missing || 'Missing',
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
            window.infyp_vars.i18n;

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

    const titleModal = (function($) {

        /* =========================================================================
           MODULE DEPENDENCIES
           ========================================================================= */

        // Get config functions
        const getScoringParams = config.getScoringParams;
        config.getTitleParams;
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
            window.infyp_vars.i18n;

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

    /**
     * Keywords Modal Module - Keyword management and editing
     *
     * This module provides a modal dialog for managing image keywords (SEO focus keywords).
     * It supports manual keyword entry via token field interface, AI-powered keyword generation,
     * and keyword validation (min 3 chars, max 3 keywords).
     *
     * ## Features
     *
     * - Token field interface for keyword management
     * - Add keywords via Enter, comma, or paste
     * - Remove keywords with backspace or × button
     * - Maximum 3 keywords enforced
     * - Minimum 3 characters per keyword
     * - Duplicate detection and prevention
     * - AI-powered keyword generation from image content
     * - PHP template loading with JS fallback
     * - Real-time keyword counter
     *
     * ## AJAX Actions
     *
     * | Action                          | Purpose                                    |
     * |---------------------------------|--------------------------------------------|
     * | infyp_load_keywords_modal     | Load modal HTML from PHP template          |
     * | infyp_update_keywords         | Save keywords to database                  |
     * | infyp_generate_keywords       | Generate keywords with AI                  |
     * | infyp_load_clear_keywords_modal | Load clear confirmation modal            |
     *
     * @file Keywords modal module for image SEO optimization.
     * @author AI Image SEO
     * @since 1.0.0
     * @module modals/keywords-modal
     * @requires jQuery
     * @requires api
     * @requires scoring-engine
     * @requires base-modal
     */

    const keywordsModal = (function($) {

        /* =========================================================================
           MODULE DEPENDENCIES
           ========================================================================= */

        // Get scoring engine functions
        const updateSEOScore = scoringEngine.updateSEOScore;

        // Store context for clear keywords confirmation modal
        let clearKeywordsContext = null;

        /* =========================================================================
           MODAL INITIALIZATION
           ========================================================================= */

        /**
         * Shows keywords editor modal.
         *
         * Displays token field interface for managing up to 3 keywords.
         * Loads modal via PHP template with current keywords pre-populated.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {number} imageId - The WordPress attachment ID.
         * @param {Array} currentKeywords - Array of current keyword strings.
         * @returns {void}
         */
        function showKeywordsEditor(imageId, currentKeywords) {
            $('#infyp-lightbox-overlay').remove();
            $('#infyp-keywords-lightbox').remove();

            // Try to load modal from PHP first
            loadKeywordsModalFromPHP(imageId, currentKeywords);
        }

        /* =========================================================================
           PHP TEMPLATE LOADING
           ========================================================================= */

        /**
         * Loads keywords modal from PHP template via AJAX.
         *
         * Requests modal HTML from server, populates with existing keywords,
         * sets up token field interface, and displays modal with fade animation.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {number} imageId - The WordPress attachment ID.
         * @param {Array} currentKeywords - Array of current keyword strings.
         * @returns {void}
         */
        function loadKeywordsModalFromPHP(imageId, currentKeywords) {
            $.post(window.infyp_vars.ajax_url, {
                action: 'infyp_load_keywords_modal',
                nonce: window.infyp_vars.nonce,
                image_id: imageId,
                current_keywords: currentKeywords
            }).done(function(response) {
                if (response.success && response.data) {
                    // Append modal HTML to body
                    $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                    const $modal = $('#infyp-keywords-lightbox');
                    const $input = $('#infyp-keyword-input');
                    const $saveBtn = $modal.find('.infyp-save-keywords');
                    const $generateBtn = $modal.find('.infyp-generate-keywords');
                    $('#infyp-keywords-tokens');

                    // Set image ID on buttons
                    $saveBtn.attr('data-image-id', imageId);
                    $generateBtn.attr('data-image-id', imageId);
                    $modal.attr('data-image-id', imageId);

                    // Populate existing keywords
                    if (response.data.current_keywords && response.data.current_keywords.length > 0) {
                        let tokensHtml = '';
                        response.data.current_keywords.forEach(keyword => {
                            tokensHtml += `
                            <span class="infyp-keyword-token" data-keyword="${baseModal.escapeHtml(keyword)}">
                                ${baseModal.escapeHtml(keyword)}
                                <button type="button" class="infyp-token-remove" aria-label="${window.infyp_vars.i18n.remove_keyword || 'Remove keyword'}">&times;</button>
                            </span>
                        `;
                        });
                        // Insert tokens before input
                        $input.before(tokensHtml);

                        // Update counter
                        updateKeywordCounter(response.data.current_keywords.length);

                        // Disable input if at max
                        if (response.data.current_keywords.length >= 3) {
                            $input.prop('disabled', true).attr('placeholder', '');
                        }
                    }

                    // Setup keyword input functionality
                    setupKeywordInput();

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

                    // Focus input if not at max
                    if (!response.data.current_keywords || response.data.current_keywords.length < 3) {
                        setTimeout(() => $input.focus(), 200);
                    }

                    // Bind keywords modal events
                    bindKeywordsModalEvents();
                } else {
                    // Fallback to inline HTML if AJAX fails
                    displayKeywordsModalFallback(imageId, currentKeywords);
                }
            }).fail(function() {
                // Fallback on AJAX error
                displayKeywordsModalFallback(imageId, currentKeywords);
            });
        }

        /* =========================================================================
           FALLBACK RENDERING
           ========================================================================= */

        /**
         * Fallback function to display keywords modal with inline JS-generated HTML.
         *
         * Used when PHP template loading fails. Generates complete modal HTML
         * including header, token field, best practices list, and footer.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {number} imageId - The WordPress attachment ID.
         * @param {Array} currentKeywords - Array of current keyword strings.
         * @returns {void}
         */
        function displayKeywordsModalFallback(imageId, currentKeywords) {
            const i18n = window.infyp_vars.i18n;
            const lightboxHtml = `
            <div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
            <div class="infyp-keywords-lightbox" id="infyp-keywords-lightbox" data-image-id="${imageId}">
                <div class="infyp-keywords-lightbox-header">
                    <h3>${i18n.edit_keywords || 'Edit Keywords'}</h3>
                    <button class="infyp-keywords-lightbox-close">&times;</button>
                </div>
                <div class="infyp-keywords-lightbox-content">
                    <div class="infyp-form-token-field">
                        <div class="infyp-token-field-header">
                            <label for="infyp-keyword-input" class="infyp-token-field-label">
                                ${i18n.add_keywords || 'Add Keywords'}
                            </label>
                            <div class="infyp-keywords-counter">
                                <span class="keyword-count">${currentKeywords.length} | 3 Keywords</span>
                            </div>
                        </div>
                        <div class="infyp-token-field-input-container">
                            <div class="infyp-token-list" id="infyp-keywords-tokens">
                                ${currentKeywords.map(kw => `
                                    <span class="infyp-keyword-token" data-keyword="${baseModal.escapeHtml(kw)}">
                                        ${baseModal.escapeHtml(kw)}
                                        <button type="button" class="infyp-token-remove" aria-label="${i18n.remove_keyword || 'Remove keyword'}">&times;</button>
                                    </span>
                                `).join('')}
                                <input type="text"
                                       id="infyp-keyword-input"
                                       class="infyp-token-input"
                                       placeholder="${currentKeywords.length >= 3 ? '' : ''}"
                                       ${currentKeywords.length >= 3 ? 'disabled' : ''}
                                       maxlength="30"
                                       autocomplete="off"
                                       role="combobox"
                                       aria-autocomplete="list"
                                       aria-expanded="false">
                            </div>
                        </div>
                        <p class="infyp-token-field-help">
                            ${i18n.keywords_help || 'Separate Keywords (maximum 3 phrases) with commas or type enter.'}
                        </p>
                    </div>

                    <div class="infyp-keywords-recommendations">
                        <h4>${i18n.keywords_best_practices || 'Best Practices'}</h4>
                        <ul>
                            <li><span class="dashicons dashicons-info"></span> ${i18n.keywords_help_1 || 'Use 1-3 relevant keywords'}</li>
                            <li><span class="dashicons dashicons-info"></span> ${i18n.keywords_help_2 || 'Each keyword should be 1-3 words'}</li>
                            <li><span class="dashicons dashicons-info"></span> ${i18n.keywords_help_3 || 'Focus on search terms'}</li>
                            <li><span class="dashicons dashicons-info"></span> ${i18n.keywords_help_4 || 'Be specific and relevant'}</li>
                        </ul>
                    </div>

                    <div class="infyp-keywords-ai-generate">
                        <button class="button button-primary infyp-has-icon infyp-generate-keywords" data-image-id="${imageId}">
                            <span class="infyp-optimize-icon"></span>
                            ${i18n.generate_keywords || 'Generate with AI'}
                        </button>
                    </div>
                </div>
                <div class="infyp-keywords-lightbox-footer">
                    <button class="button infyp-cancel-keywords">${i18n.cancel || 'Cancel'}</button>
                    <button class="button button-primary infyp-save-keywords" data-image-id="${imageId}">
                        ${i18n.save_changes || 'Save Changes'}
                    </button>
                </div>
            </div>
        `;

            $('body').append(lightboxHtml);
            setupKeywordInput();

            // Lock body scroll and store scroll position for restoration
            const scrollY = window.scrollY;
            $('body')
                .addClass('infyp-lightbox-open')
                .css('top', `-${scrollY}px`)
                .data('scroll-position', scrollY);

            // Show overlay with CSS transition
            const $overlayEl = $('#infyp-lightbox-overlay');
            $overlayEl.css('display', 'block');
            // Trigger reflow for CSS transition
            $overlayEl[0].offsetHeight;
            $overlayEl.addClass('is-visible');

            // Add is-open class to modal (triggers CSS Grid display)
            $('#infyp-keywords-lightbox').addClass('is-open');

            // Focus input if not at max
            if (currentKeywords.length < 3) {
                setTimeout(() => $('#infyp-keyword-input').focus(), 200);
            }

            // Bind keywords modal events
            bindKeywordsModalEvents();
        }

        /* =========================================================================
           TOKEN FIELD MANAGEMENT
           ========================================================================= */

        /**
         * Sets up keyword input field handlers.
         *
         * Configures event handlers for:
         * - Enter/comma to add keywords
         * - Backspace on empty input to remove last keyword
         * - Click to remove individual keywords
         * - Container click to focus input
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function setupKeywordInput() {
            const $input = $('#infyp-keyword-input');
            const $container = $('#infyp-keywords-tokens');
            window.infyp_vars.i18n;

            // Handle Enter key and comma to add keyword
            if ($input.length) {
                $input.on('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        const value = $(this).val().trim();
                        if (value) {
                            addKeyword(value);
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
                                addKeyword(trimmed);
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
                            updateKeywordCounter();
                        }
                    }
                });
            }

            // Handle removing keywords
            $container.on('click', '.infyp-token-remove', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).closest('.infyp-keyword-token').remove();
                updateKeywordCounter();

                // Re-enable input if we're below max
                const count = $('.infyp-keyword-token').length;
                if (count < 3) {
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
         * Adds a keyword to the token list.
         *
         * Validates keyword (min 3 chars, max 3 total, no duplicates) before adding.
         * Creates keyword token with remove button and updates counter.
         * Disables input when maximum keywords reached.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {string} keyword - Keyword string to add.
         * @returns {void}
         */
        function addKeyword(keyword) {
            if (!keyword) return;

            const $container = $('#infyp-keywords-tokens');
            const $input = $('#infyp-keyword-input');
            const existingKeywords = [];
            const i18n = window.infyp_vars.i18n;

            // Get existing keywords
            $container.find('.infyp-keyword-token').each(function() {
                const kw = $(this).data('keyword') || $(this).text().trim().replace('×', '').trim();
                existingKeywords.push(kw.toLowerCase());
            });

            // Validation
            if (existingKeywords.length >= 3) {
                // Silently ignore if at max
                $input.val('');
                return;
            }

            if (keyword.length < 3) {
                // Silently ignore short keywords
                return;
            }

            if (existingKeywords.includes(keyword.toLowerCase())) {
                $input.val('');
                return; // Duplicate, ignore silently
            }

            // Add the keyword token before the input
            const tokenHtml = `
            <span class="infyp-keyword-token" data-keyword="${baseModal.escapeHtml(keyword)}">
                ${baseModal.escapeHtml(keyword)}
                <button type="button" class="infyp-token-remove" aria-label="Remove keyword">&times;</button>
            </span>
        `;

            $input.before(tokenHtml);
            $input.val('');

            // Update counter
            updateKeywordCounter();

            // Disable input if we've reached max keywords
            if (existingKeywords.length + 1 >= 3) {
                $input.prop('disabled', true).attr('placeholder', i18n.max_keywords_reached || 'Maximum 3 keywords');
            }
        }

        /**
         * Updates keyword counter display.
         *
         * Shows current count vs maximum (e.g., "2 | 3 Keywords").
         * Calculates count from DOM if not provided.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {number} [count] - Optional count to use (otherwise calculates from DOM).
         * @returns {void}
         */
        function updateKeywordCounter(count) {
            if (typeof count === 'undefined') {
                count = $('#infyp-keywords-tokens .infyp-keyword-token').length;
            }
            $('.infyp-keywords-counter .keyword-count').text(`${count} | 3 Keywords`);
        }

        /* =========================================================================
           EVENT BINDING
           ========================================================================= */

        /**
         * Binds event handlers for keywords modal interactions.
         *
         * Sets up handlers for:
         * - Close/cancel buttons
         * - Save keywords button
         * - Generate with AI button
         *
         * @since 1.0.0
         * @access private
         *
         * @param {number} imageId - The WordPress attachment ID for save/generate requests.
         * @returns {void}
         */
        function bindKeywordsModalEvents(imageId) {
            // Helper function for closing the modal
            const closeKeywordsModal = function() {
                const $overlay = $('#infyp-lightbox-overlay');
                const $modal = $('#infyp-keywords-lightbox');

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
            };

            // Close modal events using .is-open class system
            $('.infyp-cancel-keywords, .infyp-keywords-lightbox-close').off('click').on('click', closeKeywordsModal);

            // Overlay click to close (click outside modal)
            $('#infyp-lightbox-overlay').off('click.keywordsOverlay').on('click.keywordsOverlay', function(e) {
                // Only close if clicking directly on overlay, not on modal content
                if (e.target === this) {
                    closeKeywordsModal();
                }
            });

            // Save keywords
            $('.infyp-save-keywords').off('click').on('click', function() {
                saveKeywords.call(this);
            });

            // Generate keywords
            $('.infyp-generate-keywords').off('click').on('click', function() {
                generateKeywords.call(this);
            });
        }

        /* =========================================================================
           DATA PERSISTENCE
           ========================================================================= */

        /**
         * Saves keywords to database.
         *
         * Collects keywords from token list, sends to server, updates table row display
         * with keyword badges, recalculates SEO score, and closes modal on success.
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function saveKeywords() {
            const $saveBtn = $('.infyp-save-keywords');
            const imageId = $saveBtn.data('image-id');
            const keywords = [];
            const i18n = window.infyp_vars.i18n;

            // Check if there's text in the input field that hasn't been added as a token
            const $input = $('#infyp-keyword-input');
            if ($input.length && $input.val().trim()) {
                // Add the input value as a keyword
                addKeyword($input.val().trim());
            }

            $('#infyp-keywords-tokens .infyp-keyword-token').each(function() {
                const text = $(this).text().trim().replace('×', '').trim();
                if (text) keywords.push(text);
            });

            $saveBtn.prop('disabled', true).text(i18n.saving || 'Saving...');

            api.post('infyp_update_keywords', {
                image_id: imageId,
                keywords: keywords
            }).done(function(response) {
                if (response.success) {
                    // Update the table display
                    const $row = $(`tr[data-id="${imageId}"]`);
                    // Update both jQuery data and HTML attribute so modals can access it
                    $row.data('keywords', keywords);
                    $row.attr('data-keywords', JSON.stringify(keywords));

                    // Update the keywords column
                    const $keywordsWrapper = $row.find('.infyp-keywords-wrapper');
                    if (keywords.length > 0) {
                        let keywordsHtml = `
                        <div class="infyp-keywords-wrapper infyp-keywords-filled" data-image-id="${imageId}">
                            <div class="infyp-keywords-list">
                    `;
                        keywords.forEach(kw => {
                            keywordsHtml += `<span class="infyp-keyword-badge">${baseModal.escapeHtml(kw)}</span>`;
                        });
                        keywordsHtml += `
                                <button class="infyp-keyword-badge placeholder infyp-add-keywords-trigger" data-image-id="${imageId}" title="${i18n.edit_keywords || 'Edit keywords'}">
                                    <span class="dashicons dashicons-edit"></span> ${i18n.edit_keywords || 'Edit Keywords'}
                                </button>
                            </div>
                        </div>
                    `;
                        $keywordsWrapper.replaceWith(keywordsHtml);
                    } else {
                        $keywordsWrapper.replaceWith(`
                        <div class="infyp-keywords-wrapper infyp-keywords-empty" data-image-id="${imageId}">
                            <button class="infyp-keyword-badge placeholder infyp-add-keywords-trigger" data-image-id="${imageId}">
                                <span class="dashicons dashicons-plus"></span> ${i18n.add_keywords || 'Add Keywords'}
                            </button>
                            <div class="infyp-keywords-help">
                                <span class="dashicons dashicons-info"></span>
                                <span class="infyp-tooltip">${i18n.click_to_add_keywords || 'Click to add keywords'}</span>
                            </div>
                        </div>
                    `);
                    }

                    // Update SEO score if returned
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
                    const $lightbox = $('#infyp-keywords-lightbox');

                    $overlay.removeClass('is-visible');
                    $lightbox.removeClass('is-open');

                    // After animation, remove from DOM
                    setTimeout(function() {
                        $overlay.remove();
                        $lightbox.remove();
                    }, 200);

                    baseModal.showNotification(i18n.keywords_updated, 'success');
                } else {
                    console.error('[Keywords] Save error:', response);
                    alert(response.data.message || i18n.error_saving);
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                console.error('[Keywords] Save failed:', {
                    status: jqXHR.status,
                    statusText: jqXHR.statusText,
                    responseText: jqXHR.responseText,
                    textStatus: textStatus,
                    errorThrown: errorThrown
                });
                alert(i18n.server_error || 'Server error');
            }).always(function() {
                $saveBtn.prop('disabled', false).text(i18n.save_changes || 'Save Changes');
            });
        }

        /* =========================================================================
           AI GENERATION
           ========================================================================= */

        /**
         * Generates keywords with AI.
         *
         * Calls AI keyword generation API, clears existing keywords, and populates
         * token field with generated keywords (up to 3).
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function generateKeywords() {
            const $btn = $(this);
            const imageId = $btn.data('image-id');
            const i18n = window.infyp_vars.i18n;

            $btn.prop('disabled', true).html(
                i18n.generating_keywords || 'Generating keywords...'
            );

            // Read context prompt from the extension-injected textarea (if present)
            const $contextPrompt = $('#infyp-keywords-lightbox').find('.infyp-context-prompt-textarea');
            const contextPromptVal = $contextPrompt.length ? $contextPrompt.val().trim() : '';

            api.post('infyp_generate_keywords', {
                image_id: imageId,
                custom_prompt: contextPromptVal
            }).done(function(response) {
                if (response.success && response.data.keywords) {
                    // Clear existing keywords
                    $('#infyp-keywords-tokens .infyp-keyword-token').remove();

                    // Add generated keywords
                    response.data.keywords.forEach(keyword => {
                        const tokenHtml = `
                        <span class="infyp-keyword-token" data-keyword="${baseModal.escapeHtml(keyword)}">
                            ${baseModal.escapeHtml(keyword)}
                            <button type="button" class="infyp-token-remove" aria-label="Remove keyword">&times;</button>
                        </span>
                    `;
                        $('#infyp-keyword-input').before(tokenHtml);
                    });

                    updateKeywordCounter();
                    baseModal.showNotification(i18n.keywords_generated, 'success');
                } else {
                    console.error('[Keywords] AI generation error:', response);
                    alert(response.data.message || i18n.error_generating_keywords);
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                console.error('[Keywords] AI generation failed:', {
                    status: jqXHR.status,
                    statusText: jqXHR.statusText,
                    responseText: jqXHR.responseText,
                    textStatus: textStatus,
                    errorThrown: errorThrown
                });
                alert(i18n.server_error || 'Server error');
            }).always(function() {
                $btn.prop('disabled', false).html(`
                <span class="infyp-optimize-icon"></span>
                ${i18n.generate_keywords || 'Generate with AI'}
            `);
            });
        }

        /* =========================================================================
           CONFIRMATION MODAL
           ========================================================================= */

        /**
         * Shows clear keywords confirmation modal.
         *
         * Displays confirmation dialog before clearing all keywords for an image.
         * Loads modal HTML from server via AJAX.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {number} imageId - The WordPress attachment ID.
         * @param {jQuery} $row - The table row element for context.
         * @returns {void}
         */
        function showClearKeywordsConfirmModal(imageId, $row) {
            // Remove any existing clear keywords modal
            $('#infyp-clear-keywords-overlay, #infyp-clear-keywords-modal').remove();

            // Store context for document-level event handlers
            clearKeywordsContext = {
                imageId: imageId,
                $row: $row
            };

            // Load modal via AJAX (same pattern as keywords modal)
            $.post(window.infyp_vars.ajax_url, {
                action: 'infyp_load_clear_keywords_modal',
                nonce: window.infyp_vars.nonce,
                image_id: imageId
            }).done(function(response) {
                if (response.success && response.data.modal_html) {
                    $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                    const $overlay = $('#infyp-clear-keywords-overlay');
                    const $modal = $('#infyp-clear-keywords-modal');

                    // Set image ID on modal and confirm button
                    $modal.attr('data-image-id', imageId);
                    $modal.find('.infyp-clear-keywords-confirm').attr('data-image-id', imageId);

                    // Lock body scroll and store scroll position for restoration
                    const scrollY = window.scrollY;
                    $('body')
                        .addClass('infyp-lightbox-open')
                        .css('top', `-${scrollY}px`)
                        .data('scroll-position', scrollY);

                    // Show overlay with CSS transition
                    $overlay.css('display', 'block');
                    // Trigger reflow for CSS transition
                    $overlay[0].offsetHeight;
                    $overlay.addClass('is-visible');

                    // Add is-open class to modal
                    $modal.addClass('is-open');
                }
            });
        }

        /**
         * Closes clear keywords confirmation modal.
         *
         * Fades out and removes confirmation modal and overlay, clears stored context.
         *
         * @since 1.0.0
         * @access public
         *
         * @returns {void}
         */
        function closeClearKeywordsModal() {
            const $overlay = $('#infyp-clear-keywords-overlay');
            const $modal = $('#infyp-clear-keywords-modal');

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

            clearKeywordsContext = null;
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the keywords modal module.
         *
         * @since 1.0.0
         *
         * @return {Object} Public methods.
         * @property {Function} show - Show keywords editor modal.
         * @property {Function} loadFromPHP - Load modal from PHP template.
         * @property {Function} displayFallback - Display modal with JS fallback.
         * @property {Function} bindEvents - Bind modal event handlers.
         * @property {Function} save - Save keywords to database.
         * @property {Function} generate - Generate keywords with AI.
         * @property {Function} showClearConfirm - Show clear keywords confirmation modal.
         * @property {Function} closeClearConfirm - Close clear keywords confirmation modal.
         * @property {Function} getContext - Get clear keywords context.
         */
        return {
            show: showKeywordsEditor,
            loadFromPHP: loadKeywordsModalFromPHP,
            displayFallback: displayKeywordsModalFallback,
            bindEvents: bindKeywordsModalEvents,
            save: saveKeywords,
            generate: generateKeywords,
            showClearConfirm: showClearKeywordsConfirmModal,
            closeClearConfirm: closeClearKeywordsModal,
            getContext: () => clearKeywordsContext
        };
    })(jQuery);

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = keywordsModal;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.keywordsModal = keywordsModal;
    }

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

    const seoScoreModal = (function($) {

        /* =========================================================================
           MODULE DEPENDENCIES
           ========================================================================= */

        // Get config functions
        config.getAltParams;
        config.getTitleParams;
        config.getFilenameParams;

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
            serverData && serverData.params ? serverData.params : {};

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
            serverData && serverData.params ? serverData.params : {};
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

    /**
     * Image Preview Modal Module - Displays full-size image preview
     *
     * This module provides a lightbox modal for previewing images from the
     * dashboard table at full size, with a link to the WordPress Media Library
     * edit page and an Optimize with AI button.
     *
     * @module modals/image-preview-modal
     * @requires jQuery
     * @requires api
     * @requires base-modal
     */

    const imagePreviewModal = (function($) {

        /* =========================================================================
           MODAL INITIALIZATION
           ========================================================================= */

        /**
         * Show image preview modal for an image
         *
         * Fetches fresh image URL for high-quality preview, then displays
         * the image preview modal.
         *
         * @param {number} imageId - The WordPress attachment ID
         * @returns {void}
         */
        function show(imageId) {
            // Remove any existing modals
            $('#infyp-lightbox-overlay').remove();
            $('#infyp-image-preview-lightbox').remove();

            // Fetch fresh image data for better quality
            api.post('infyp_get_fresh_image_url', {
                image_id: imageId
            }).done(function(response) {
                let imageUrl = '';
                if (response.success && response.data) {
                    imageUrl = response.data.urls?.display || response.data.urls?.large || response.data.urls?.full || '';
                }

                if (!imageUrl) {
                    imageUrl = getFallbackUrl(imageId);
                }

                loadFromPHP(imageId, imageUrl);
            }).fail(function() {
                const imageUrl = getFallbackUrl(imageId);
                loadFromPHP(imageId, imageUrl);
            });
        }

        /**
         * Get fallback image URL from the table thumbnail
         *
         * @param {number} imageId - The WordPress attachment ID
         * @returns {string} The image URL
         * @private
         */
        function getFallbackUrl(imageId) {
            const $row = $(`tr[data-id="${imageId}"]`);
            const $imageThumb = $row.find('.column-thumbnail img');
            let imageUrl = $imageThumb.attr('src') || '';

            // Try to get larger version by removing size suffix
            if (imageUrl) {
                imageUrl = imageUrl.replace(/-\d+x\d+(\.[^.]+)$/, '$1');
            }

            return imageUrl;
        }

        /* =========================================================================
           PHP TEMPLATE LOADING
           ========================================================================= */

        /**
         * Load image preview modal from PHP template via AJAX
         *
         * @param {number} imageId - The WordPress attachment ID
         * @param {string} imageUrl - URL for image preview
         * @returns {void}
         * @private
         */
        function loadFromPHP(imageId, imageUrl) {
            $.post(window.infyp_vars.ajax_url, {
                action: 'infyp_load_image_preview_modal',
                nonce: window.infyp_vars.nonce,
                image_id: imageId,
                image_url: imageUrl
            }).done(function(response) {
                if (response.success && response.data) {
                    // Append modal HTML to body
                    $('body').append(baseModal.sanitizeHtml(response.data.modal_html));

                    const $overlay = $('#infyp-lightbox-overlay');
                    const $modal = $('#infyp-image-preview-lightbox');

                    // Set image data
                    $modal.attr('data-image-id', imageId);
                    $modal.find('.infyp-image-id').text('#' + imageId);
                    $modal.find('.infyp-modal-image-preview img').attr('src', imageUrl);

                    // Set edit link
                    if (response.data.edit_link) {
                        $modal.find('.infyp-edit-media-link').attr('href', response.data.edit_link);
                    }

                    // Set optimize button image ID
                    $modal.find('.infyp-optimize-from-modal').attr('data-id', imageId);

                    // Show modal using base modal utilities
                    baseModal.showModal($overlay, $modal);

                    // Bind events
                    bindEvents($overlay, $modal, imageId);
                } else {
                    displayFallback(imageId, imageUrl);
                }
            }).fail(function() {
                displayFallback(imageId, imageUrl);
            });
        }

        /* =========================================================================
           FALLBACK RENDERING
           ========================================================================= */

        /**
         * Fallback function to display modal with inline JS-generated HTML
         *
         * @param {number} imageId - The WordPress attachment ID
         * @param {string} imageUrl - URL for image preview
         * @returns {void}
         * @private
         */
        function displayFallback(imageId, imageUrl) {
            const i18n = window.infyp_vars.i18n;

            // Build edit URL from WordPress admin URL
            const editUrl = window.infyp_vars.admin_url + 'post.php?post=' + imageId + '&action=edit';

            const modalHtml = `
            <div class="infyp-lightbox-overlay" id="infyp-lightbox-overlay"></div>
            <div class="infyp-image-preview-lightbox" id="infyp-image-preview-lightbox" data-image-id="${imageId}">
                <div class="infyp-image-preview-lightbox-header">
                    <h3>${i18n.image_preview || 'Image Preview'} <span class="infyp-image-id">#${imageId}</span></h3>
                    <button class="infyp-image-preview-lightbox-close">&times;</button>
                </div>
                <div class="infyp-image-preview-lightbox-content">
                    <div class="infyp-modal-image-preview">
                        <img src="${baseModal.escapeHtml(imageUrl)}" alt="${i18n.image_preview || 'Image preview'}" />
                    </div>
                </div>
                <div class="infyp-image-preview-lightbox-footer">
                    <a href="${baseModal.escapeHtml(editUrl)}" class="button infyp-edit-media-link" target="_blank">
                        <span class="dashicons dashicons-edit"></span>
                        ${i18n.edit_in_media_library || 'Edit in Media Library'}
                    </a>
                    <button class="button button-primary infyp-optimize-from-modal" data-id="${imageId}">
                        <span class="infyp-optimize-icon"></span>
                        ${i18n.optimize_now || 'Optimize with AI'}
                    </button>
                </div>
            </div>
        `;

            $('body').append(modalHtml);

            const $overlay = $('#infyp-lightbox-overlay');
            const $modal = $('#infyp-image-preview-lightbox');

            baseModal.showModal($overlay, $modal);
            bindEvents($overlay, $modal, imageId);
        }

        /* =========================================================================
           EVENT HANDLERS
           ========================================================================= */

        /**
         * Bind event handlers for image preview modal
         *
         * @param {jQuery} $overlay - The overlay element
         * @param {jQuery} $modal - The modal element
         * @param {number} imageId - The WordPress attachment ID
         * @returns {void}
         * @private
         */
        function bindEvents($overlay, $modal, imageId) {
            // Close events
            $overlay.on('click', function(e) {
                e.preventDefault();
                baseModal.closeModal($overlay, $modal);
            });

            $modal.find('.infyp-image-preview-lightbox-close').on('click', function(e) {
                e.preventDefault();
                baseModal.closeModal($overlay, $modal);
            });

            // Optimize with AI button - trigger the table row's optimize button
            $modal.find('.infyp-optimize-from-modal').on('click', function(e) {
                e.preventDefault();

                // Close the modal first
                baseModal.closeModal($overlay, $modal);

                // Find the row's optimize button and trigger click
                const $row = $(`tr[data-id="${imageId}"]`);
                if ($row.length) {
                    const $optimizeBtn = $row.find('.infyp-optimize-ai');
                    if ($optimizeBtn.length) {
                        $optimizeBtn.trigger('click');
                    }
                }
            });

            // Escape key
            $(document).on('keydown.imagePreviewModal', function(e) {
                if (e.key === 'Escape' && $modal.hasClass('is-open')) {
                    baseModal.closeModal($overlay, $modal);
                    $(document).off('keydown.imagePreviewModal');
                }
            });

            // Prevent modal content clicks from closing
            $modal.on('click', function(e) {
                e.stopPropagation();
            });
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        return {
            show,
            loadFromPHP,
            displayFallback
        };
    })(jQuery);

    // Export for ES6 modules
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = imagePreviewModal;
    }

    // Export for WordPress/global scope
    if (typeof window !== 'undefined') {
        window.infyp = window.infyp || {};
        window.infyp.imagePreviewModal = imagePreviewModal;
    }

    /**
     * Image Table Module - Manages media library image optimization UI
     *
     * This module is the main orchestrator for the WordPress media library table enhancements.
     * It coordinates all table-related features including SEO scoring, inline editing,
     * AI optimization triggers, pagination, filtering, and modal interactions.
     *
     * ## Features
     *
     * - Table initialization and event handler coordination
     * - Modal trigger handlers (alt text, filename, title, keywords, SEO score)
     * - Integration with pagination, filtering, and live search modules
     * - Real-time character counting
     * - Field update handlers
     * - Usage checker integration
     * - Event cleanup and re-initialization after AJAX updates
     *
     * ## Architecture
     *
     * This module follows a modular architecture:
     * - Core: api, scoring-engine, event-manager
     * - UI: loading-states, notifications, helpers
     * - Features: ai-optimizer, usage-checker, character-counter
     * - Table: field-editor, pagination, filtering, live-search
     * - Modals: alt-text, filename, title, keywords, seo-score
     *
     * ## Dependencies
     *
     * - jQuery
     * - All imported modules listed in the imports section
     * - infyp_vars global (i18n strings)
     *
     * ## Usage
     *
     * ```javascript
     * import imageTable from './modules/image-table.js';
     *
     * // Initialize on page load
     * jQuery(document).ready(function() {
     *     imageTable.init();
     * });
     * ```
     *
     * @file Image Table Module - Main coordinator for WordPress media library enhancements.
     * @author InfyPress
     * @since 1.0.0
     * @module imageTable
     * @requires jQuery
     * @requires module:api
     * @requires module:core/scoring-engine
     * @requires module:core/event-manager
     * @requires module:ui/loading-states
     * @requires module:ui/notifications
     * @requires module:ui/helpers
     * @requires module:features/ai-optimizer
     * @requires module:features/usage-checker
     * @requires module:table/character-counter
     * @requires module:table/field-editor
     * @requires module:table/pagination
     * @requires module:table/filtering
     * @requires module:table/live-search
     * @requires module:modals/alt-text-modal
     * @requires module:modals/filename-modal
     * @requires module:modals/title-modal
     * @requires module:modals/keywords-modal
     * @requires module:modals/seo-score-modal
     */


    const imageTable = (function($) {

        /* =========================================================================
           CONFIGURATION
           ========================================================================= */

        /**
         * Reference to the WordPress media library table element
         * @type {jQuery}
         * @private
         */
        const table = $('.wp-list-table');

        /**
         * Scoring engine update function for SEO score recalculation
         * @type {Function}
         * @private
         */
        const updateSEOScore = scoringEngine.updateSEOScore;

        /* =========================================================================
           MODULE ALIASES
           ========================================================================= */

        // UI module function aliases
        const showFieldLoadingSpinner = loadingStates.showFieldSpinner;
        const showKeywordsLoadingSpinner = loadingStates.showKeywordsSpinner;
        const showButtonLoadingSpinner = loadingStates.showButtonSpinner;
        const showTableWhenReady = helpers.showTableWhenReady;
        const showNotification = notifications.show;

        // Feature module handler aliases
        const handleAiOptimize = aiOptimizer.handleOptimizeClick;
        const handleUsageCheck = usageChecker.handleClick;
        const handleUpdate = fieldEditor.handleUpdate;
        const updateCharCounter = characterCounter.update;

        /* =========================================================================
           ROW HIGHLIGHTING
           ========================================================================= */

        /**
         * Sets the active row highlight when a modal is opened.
         * Clears any previously active row and highlights the new one.
         * The highlight persists until the user clicks elsewhere in the document.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {jQuery} $row - The table row to highlight.
         * @returns {void}
         */
        function setActiveRow($row) {
            // Remove active class from any previously active row
            $('.wp-list-table tr.infyp-row-active').removeClass('infyp-row-active infyp-row-just-activated');

            // Add active class to the new row with animation
            $row.addClass('infyp-row-active infyp-row-just-activated');

            // Remove the animation class after it completes
            setTimeout(function() {
                $row.removeClass('infyp-row-just-activated');
            }, 400);
        }

        /**
         * Clears the active row highlight.
         * Called when user clicks outside the table or modal.
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function clearActiveRow() {
            $('.wp-list-table tr.infyp-row-active').removeClass('infyp-row-active infyp-row-just-activated');
        }

        /* =========================================================================
           GLOBAL EVENT HANDLERS
           ========================================================================= */

        // Document-level event handlers for clear keywords modal (survives AJAX refreshes)
        $(document).on('click', '#infyp-clear-keywords-overlay', function() { keywordsModal.closeClearConfirm(); });
        $(document).on('click', '.infyp-clear-keywords-close', function() { keywordsModal.closeClearConfirm(); });
        $(document).on('click', '.infyp-clear-keywords-cancel', function() { keywordsModal.closeClearConfirm(); });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#infyp-clear-keywords-modal').length) {
                keywordsModal.closeClearConfirm();
            }
        });

        // Clear keywords trigger - document level delegation to survive AJAX table refreshes
        $(document).on('click', '.infyp-clear-keywords-trigger', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $trigger = $(this);
            const imageId = $trigger.data('image-id');
            const $row = $trigger.closest('tr');

            // Highlight the source row
            setActiveRow($row);

            // Show confirmation modal
            keywordsModal.showClearConfirm(imageId, $row);
        });

        // Confirm clear handler
        $(document).on('click', '.infyp-clear-keywords-confirm', function() {
            // Get context from keywordsModal module (not local variable)
            const context = keywordsModal.getContext();
            if (!context) return;

            const $confirmBtn = $(this);
            const clearText = infyp_vars.i18n.clear || 'Clear';
            const imageId = context.imageId;
            const $row = context.$row;

            $confirmBtn.prop('disabled', true).text(infyp_vars.i18n.clearing || 'Clearing...');

            api.post('infyp_clear_keywords', {
                image_id: imageId
            }).done(function(response) {
                if (response.success) {
                    // Update the keywords cell to show empty state
                    const $keywordsCell = $row.find('.column-keywords');
                    const addKeywordsText = infyp_vars.i18n.add_keywords || 'Add Keywords';
                    const tooltipText = infyp_vars.i18n.keywords_tooltip || 'Click to add keywords manually or generate with AI';

                    $keywordsCell.html(`
                    <div class="infyp-keywords-wrapper infyp-keywords-empty" data-image-id="${imageId}">
                        <button class="infyp-keyword-badge placeholder infyp-add-keywords-trigger" data-image-id="${imageId}">
                            <span class="dashicons dashicons-plus"></span> ${addKeywordsText}
                        </button>
                        <div class="infyp-keywords-help">
                            <span class="dashicons dashicons-info" title="${tooltipText}"></span>
                            <span class="infyp-tooltip">${tooltipText}</span>
                        </div>
                    </div>
                `);

                    // Update row data attribute
                    $row.attr('data-keywords', '[]');

                    // Update SEO score if returned
                    if (response.data.seo_score) {
                        updateSEOScore(imageId, response.data.seo_score);
                    }

                    // Show success notification
                    showNotification(response.data.message || infyp_vars.i18n.keywords_cleared || 'Keywords cleared', 'success');

                    keywordsModal.closeClearConfirm();
                } else {
                    showNotification(response.data?.message || infyp_vars.i18n.error || 'Error', 'error');
                    $confirmBtn.prop('disabled', false).text(clearText);
                }
            }).fail(function() {
                showNotification(infyp_vars.i18n.error || 'Error', 'error');
                $confirmBtn.prop('disabled', false).text(clearText);
            });
        });

        /* =========================================================================
           TABLE REINITIALIZATION
           ========================================================================= */

        /**
         * Reinitializes table handlers after AJAX table updates.
         *
         * Called after pagination, filtering, or search operations that replace
         * the table HTML. Uses event manager for clean event cleanup and re-attachment.
         * Ensures all event handlers are properly bound to the new table elements.
         *
         * @since 1.0.0
         * @access private
         *
         * @returns {void}
         */
        function reinitializeTableHandlers() {
            const newTable = $('.wp-list-table');

            if (newTable.length) {
                // ============================================================================
                // PHASE 2C: Use event manager for clean event cleanup
                // ============================================================================

                // Clean up old table event handlers using event manager
                eventManager.off('table-handlers');

                // Reinitialize pagination handlers
                pagination.handlePaginationLinks();

                // Reinitialize field editor
                fieldEditor.init();

                // Re-attach all table handlers using event manager
                attachTableHandlers(newTable);
            }
        }

        /**
         * Attaches all table event handlers using event manager.
         *
         * Binds event handlers for field editing, action buttons, character counting,
         * and modal triggers. Uses the event manager with namespace 'table-handlers'
         * for easy cleanup and re-attachment.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {jQuery} $table - Table element to attach handlers to.
         * @returns {void}
         */
        function attachTableHandlers($table) {
            const ns = 'table-handlers';

            // Field editing handlers
            eventManager.on($table, 'change', '.infyp-editable-field:not(.infyp-alt-text-trigger):not(.infyp-filename-trigger):not(.infyp-title-trigger)', handleUpdate, ns);

            // Action button handlers
            eventManager.on($table, 'click', '.infyp-check-usage', handleUsageCheck, ns);
            eventManager.on($table, 'click', '.infyp-optimize-ai', handleAiOptimize, ns);

            // Real-time character counter
            eventManager.on($table, 'input', '.infyp-editable-field:not(.infyp-alt-text-trigger):not(.infyp-filename-trigger):not(.infyp-title-trigger)', function() {
                updateCharCounter(this);
            }, ns);

            // Modal trigger handlers
            eventManager.on($table, 'click', '.infyp-alt-text-trigger', function(e) {
                e.preventDefault();
                const $input = $(this);
                const $row = $input.closest('tr');
                const imageId = $input.data('image-id');
                const currentAltText = $input.val();
                setActiveRow($row);
                showFieldLoadingSpinner($input, '#infyp-alt-text-lightbox');
                altTextModal.show(imageId, currentAltText);
            }, ns);

            eventManager.on($table, 'click', '.infyp-filename-trigger', function(e) {
                e.preventDefault();
                const $input = $(this);
                const $row = $input.closest('tr');
                const imageId = $input.data('image-id');
                const currentFilename = $input.val();
                setActiveRow($row);
                showFieldLoadingSpinner($input, '#infyp-filename-lightbox');
                filenameModal.show(imageId, currentFilename);
            }, ns);

            eventManager.on($table, 'click', '.infyp-title-trigger', function(e) {
                e.preventDefault();
                const $input = $(this);
                const $row = $input.closest('tr');
                const imageId = $input.data('image-id');
                const currentTitle = $input.val();
                setActiveRow($row);
                showFieldLoadingSpinner($input, '#infyp-title-lightbox');
                titleModal.show(imageId, currentTitle);
            }, ns);

            // Keywords modal handler
            eventManager.on($table, 'click', '.infyp-add-keywords-trigger', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const $trigger = $(this);
                const imageId = $trigger.data('image-id') || $trigger.closest('[data-image-id]').data('image-id');
                const $row = $trigger.closest('tr');

                // Highlight the source row
                setActiveRow($row);

                // Show loading spinner
                showKeywordsLoadingSpinner($trigger, '#infyp-keywords-lightbox');

                // Get existing keywords from the badges
                const existingKeywords = [];
                $row.find('.infyp-keyword-badge').not('.placeholder').each(function() {
                    const keyword = $(this).text().trim();
                    if (keyword && !keyword.includes('Add keywords')) {
                        existingKeywords.push(keyword);
                    }
                });

                keywordsModal.show(imageId, existingKeywords);
            }, ns);

            // SEO score breakdown handler
            eventManager.on($table, 'click', '.infyp-check-seo-breakdown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const $button = $(this);
                const $row = $button.closest('tr');
                const imageId = $button.data('id');
                const currentScore = $button.data('score');
                setActiveRow($row);
                showButtonLoadingSpinner($button, '#infyp-score-modal');
                seoScoreModal.show(imageId, currentScore);
            }, ns);

            // Image thumbnail preview handler
            eventManager.on($table, 'click', '.infyp-thumbnail-preview', function(e) {
                e.preventDefault();
                const $link = $(this);
                const $row = $link.closest('tr');
                const imageId = $link.data('id');
                setActiveRow($row);

                // Show loading spinner on thumbnail cell
                const $cell = $link.closest('td');
                $cell.addClass('infyp-wrapper-loading');
                const $spinner = $('<span class="spinner is-active infyp-field-spinner"></span>');
                $cell.append($spinner);

                // Auto-cleanup when modal appears or after timeout
                const observer = new MutationObserver(function() {
                    if ($('#infyp-image-preview-lightbox').length) {
                        $cell.removeClass('infyp-wrapper-loading');
                        $spinner.remove();
                        observer.disconnect();
                    }
                });
                observer.observe(document.body, { childList: true, subtree: true });
                setTimeout(function() {
                    $cell.removeClass('infyp-wrapper-loading');
                    $spinner.remove();
                    observer.disconnect();
                }, 5000);

                imagePreviewModal.show(imageId);
            }, ns);
        }
        
        /* =========================================================================
           INITIALIZATION
           ========================================================================= */

        /**
         * Initializes the image table module.
         *
         * Main initialization function that coordinates all submodule initialization.
         * Performs the following setup:
         * 1. Cleans up any existing lightbox elements
         * 2. Shows table when page is fully loaded
         * 3. Initializes filtering, pagination, and field editor
         * 4. Initializes live search functionality
         * 5. Attaches table event handlers via event manager
         * 6. Binds modal events for alt text, filename, and title
         * 7. Sets up global overlay click handler
         *
         * Should be called once on page load after DOM is ready.
         *
         * @since 1.0.0
         * @access public
         *
         * @returns {void}
         */
        function init() {
            // Clean up any existing lightbox elements from previous page loads
            $('#infyp-lightbox-overlay').remove();
            $('#infyp-alt-text-lightbox').remove();

            // Show table when page is fully loaded
            showTableWhenReady();

            /* =========================================================================
               MODULE INITIALIZATION
               ========================================================================= */

            // Initialize post type filter (pass reinitializeTableHandlers as callback)
            filtering.init(reinitializeTableHandlers);

            // Initialize page/post filter and sort controls
            pageFilter.init();
            seoSort.init();
            dateSort.init();

            // Initialize pagination (pass reinit and filter callbacks)
            pagination.init(reinitializeTableHandlers, filtering.handlePostTypeFilter);

            // Initialize AJAX table loader for better performance with large media libraries
            // This enables async data loading and AJAX-based pagination
            // loadOnInit: true - Table data is loaded via AJAX for faster initial page render
            // Returns true if inline data was rendered (reinitCallback was already called)
            // Self-locate: only run on pages that actually have the AJAX-capable image list table.
            // Other plugin pages (Redirects, Changelog, Settings) ship a standard WP_List_Table
            // with 'ajax' => false and would otherwise have their pagination markup overwritten
            // by ajaxTableLoader, losing the WP-default spacing.
            const inlineDataRendered = $('.wp-list-table.images').length
                ? ajaxTableLoader.init({
                    reinitCallback: reinitializeTableHandlers,
                    filterInitCallback: filtering.handlePostTypeFilter,
                    loadOnInit: true
                })
                : false;

            // Initialize field editor
            fieldEditor.init();

            // Initialize live search (pass reinit callback for AJAX table updates)
            liveSearch.init(reinitializeTableHandlers);

            /* =========================================================================
               EVENT HANDLER ATTACHMENT
               ========================================================================= */

            // Only attach handlers if ajaxTableLoader didn't already do it via reinitCallback
            // This prevents duplicate handler attachment that causes the "No image ID" error
            if (table.length && !inlineDataRendered) {
                attachTableHandlers(table);
            }

            /* =========================================================================
               MODAL EVENT BINDINGS
               ========================================================================= */

            altTextModal.bindEvents();
            filenameModal.bindEvents();
            titleModal.bindEvents();

            // Global overlay click handler (closes any open modal)
            $('body').on('click', '#infyp-lightbox-overlay', function() {
                $(this).fadeOut(200, function() {
                    $(this).remove();
                });
                $('.infyp-alt-text-lightbox,.infyp-filename-lightbox,.infyp-title-lightbox,.infyp-keywords-lightbox').fadeOut(200, function() {
                    $(this).remove();
                });
                $(document).trigger('infyp:modals:closeAll');
            });
            
            // Keywords modal handlers are now bound via eventManager in attachTableHandlers()
            // Removed duplicate table.on() handler that was causing keywords to appear twice

            $(document).on('click', '.infyp-check-seo-breakdown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const $button = $(this);
                const $row = $button.closest('tr');
                const imageId = $button.data('id');
                const currentScore = $button.data('score');
                setActiveRow($row);
                showButtonLoadingSpinner($button, '#infyp-score-modal');
                seoScoreModal.show(imageId, currentScore);
            });

            /* =========================================================================
               CLICK-ELSEWHERE HANDLER FOR ROW HIGHLIGHTING
               ========================================================================= */

            /**
             * Clears the active row highlight when clicking outside the table and modals.
             * The highlight persists when:
             * - Clicking inside the table (to allow multiple field edits)
             * - Clicking inside any modal or overlay
             * - Clicking modal triggers or buttons
             */
            $(document).on('click', function(e) {
                const $target = $(e.target);

                // Don't clear if clicking inside the table
                if ($target.closest('.wp-list-table').length) {
                    return;
                }

                // Don't clear if clicking inside a modal or overlay
                if ($target.closest('.infyp-lightbox, .infyp-lightbox-overlay, .infyp-modal, [class*="-modal"], [class*="-lightbox"]').length) {
                    return;
                }

                // Don't clear if a modal is currently open
                if ($('body').hasClass('infyp-lightbox-open') || $('.infyp-lightbox.is-open').length) {
                    return;
                }

                // Clear the active row highlight
                clearActiveRow();
            });
        }






        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the image table module.
         *
         * @since 1.0.0
         *
         * @returns {Object} Public methods.
         * @property {Function} init - Initialize the image table module and all submodules.
         */
        return {
            init
        };

    })(jQuery);

    /**
     * Settings Module - Manages all plugin settings forms and interactions.
     *
     * This module provides a centralized interface for managing plugin settings
     * including API keys, AI prompt configuration, and post type settings. It handles
     * form validation, submission, and user feedback with comprehensive error handling.
     *
     * ## Features
     *
     * - API key management (OpenAI, Anthropic, Google Gemini)
     * - Individual and bulk API key saves
     * - AI prompt settings configuration
     * - Post type enablement settings
     * - Real-time validation and user feedback
     * - Automatic page refresh on successful key saves
     *
     * ## Usage
     *
     * ```javascript
     * import settings from './settings.js';
     *
     * // Initialize settings module
     * jQuery(document).ready(() => {
     *     settings.init();
     * });
     * ```
     *
     * @file Manages all plugin settings forms and interactions.
     * @author InfyPress
     * @since 1.0.0
     * @module settings
     * @requires jQuery
     * @requires api
     * @requires ui
     */

    const settings = (function($) {

        /* =========================================================================
           CACHED ELEMENTS
           ========================================================================= */

        /**
         * Cache form elements for performance.
         *
         * @type {jQuery}
         * @private
         */
        const apiKeyForm = $('#infyp-api-keys-form');          // API keys configuration form
        const promptSettingsForm = $('#infyp-prompt-settings-form');  // AI prompt customization form
        const postTypeSettingsForm = $('#infyp-post-type-settings-form'); // Post type enablement form

        /* =========================================================================
           INITIALIZATION
           ========================================================================= */

        /**
         * Initializes all settings forms and event handlers.
         *
         * Sets up event listeners for all settings forms including API keys,
         * prompt settings, and post type settings. Also initializes API key
         * status checking for prompt settings form.
         *
         * @since 1.0.0
         * @access public
         *
         * @return {void}
         */
        function init() {
            // API Keys Form Initialization
            if (apiKeyForm.length) {
                apiKeyForm.on('submit', handleApiKeysSave);  // Bulk save all keys
                $('.infyp-save-individual').on('click', handleIndividualKeySave); // Individual key save buttons
                $('.infyp-test-key').on('click', handleKeyTest);                  // Live key verification buttons
                $('#infyp_connection_method').on('change', handleConnectionMethodChange); // API keys vs WordPress AI
                
                // Hide notices when user starts typing new API key
                $('.infyp-api-key-input').on('input', function() {
                    $('.infyp-dynamic-notice').fadeOut();
                });
            }
            
            // Prompt Settings Form Initialization
            if (promptSettingsForm.length) {
                promptSettingsForm.on('submit', handlePromptSettingsSave);

                // Monitor AI model selection to show API key warnings and update model label
                $('#infyp_ai_model_accordion').on('change', function() {
                    ui.checkApiKeyNotice();
                    // Update the global model label so status messages reflect the new selection
                    if (window.infyp_vars) {
                        window.infyp_vars.ai_model_label = $(this).find('option:selected').text().replace(/\s*\(.*\)$/, '');
                    }
                });

                // Check API key status on page load
                ui.checkApiKeyNotice();

                // Toggle global prompt addon when per-image prompt checkbox changes
                $('#infyp_enable_per_image_prompt').on('change', function() {
                    $('#infyp_prompt_addon_accordion').prop('disabled', $(this).is(':checked'));
                });
            }
            
            // Post Type Settings Form Initialization
            if (postTypeSettingsForm.length) {
                postTypeSettingsForm.on('submit', handlePostTypeSettingsSave);
            }

            // Tools Section - Rebuild Search Index
            $('#infyp-rebuild-search-index').on('click', handleRebuildSearchIndex);
        }

        /* =========================================================================
           API KEY OPERATIONS
           ========================================================================= */

        /**
         * Handles individual API key save for a specific provider.
         *
         * Saves a single API key for OpenAI, Anthropic, or Google Gemini.
         * Validates that the key is not empty before submitting. Updates
         * UI with loading state and shows success/error notices.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Event} e - Click event from save button.
         *
         * @return {void}
         */
        function handleIndividualKeySave(e) {
            e.preventDefault();
            
            const $button = $(e.target);
            const provider = $button.data('provider');  // 'openai', 'anthropic', or 'google'
            const $input = $('#infyp_' + provider + '_api_key');
            const value = $input.val().trim();
            
            // Validate that key is not empty
            if (!value) {
                // Create provider-specific message
                const providerName = provider.charAt(0).toUpperCase() + provider.slice(1);
                const providerKey = 'enter_' + provider + '_api_key';
                const defaultMsg = 'Please enter ' + providerName + ' API key to save.';
                const message = infyp_vars.i18n[providerKey] || infyp_vars.i18n.enter_api_key || defaultMsg;
                showApiKeysNotice(message, 'error');
                return;
            }
            // Show loading state
            $button.prop('disabled', true).addClass('infyp-saving');
            $('.infyp-dynamic-notice').remove();
            
            // Prepare form data with WordPress nonces for security
            const formData = [{
                name: 'action',
                value: 'infyp_save_api_keys'
            }, {
                name: 'nonce',
                value: infyp_vars.nonce
            }, {
                name: 'infyp_api_keys[' + provider + ']',
                value: value
            }, {
                name: '_wpnonce',
                value: $('#_wpnonce').val()  // WordPress form nonce
            }, {
                name: '_wp_http_referer',
                value: $('input[name="_wp_http_referer"]').val()  // Referrer for security
            }];
            const processedData = $.param(formData);
            api.saveApiKeys(processedData).done(response => {
                const isSuccess = response.success;
                if (isSuccess) {
                    $input.val('');
                    updateApiKeyStatus();
                    // The save response carries a live verification result per
                    // key — surface it instead of a bare "saved" message.
                    showKeyTestResults(response.data && response.data.tests, response.data && response.data.message);
                } else {
                    showApiKeysNotice((response.data && response.data.message) || infyp_vars.i18n.unknown_error, 'error');
                }
            }).fail(() => {
                showApiKeysNotice(infyp_vars.i18n.server_error, 'error');
            }).always(() => {
                $button.prop('disabled', false).removeClass('infyp-saving');
            });
        }

        /**
         * Renders live key-verification results into the API-keys notice area.
         *
         * Three states from the server: 'valid' (green), 'invalid' (red, with
         * the provider's own rejection message), 'unverified' (info — the
         * provider could not be reached; never styled as an error).
         *
         * @since 1.0.6
         * @access private
         *
         * @param {Object} tests    Map of provider => {state, message}.
         * @param {string} fallback Message to show when no tests are present.
         *
         * @return {void}
         */
        function showKeyTestResults(tests, fallback) {
            const labels = { google: 'Google Gemini', anthropic: 'Anthropic Claude', openai: 'OpenAI' };

            if (!tests || $.isEmptyObject(tests)) {
                showApiKeysNotice(fallback || infyp_vars.i18n.settings_saved, 'success');
                return;
            }

            const parts = [];
            let worst = 'success';
            $.each(tests, (provider, result) => {
                const name = labels[provider] || provider;
                if (result.state === 'valid') {
                    parts.push(name + ': ' + (infyp_vars.i18n.key_verified || 'verified'));
                } else if (result.state === 'invalid') {
                    parts.push(name + ': ' + (infyp_vars.i18n.key_rejected || 'rejected') + ' — ' + result.message);
                    worst = 'error';
                } else {
                    parts.push(name + ': ' + (infyp_vars.i18n.key_unverified || 'saved, could not verify'));
                    if (worst !== 'error') {
                        worst = 'info';
                    }
                }
            });

            showApiKeysNotice(parts.join(' · '), worst === 'info' ? 'success' : worst);
        }

        /**
         * Handles the per-provider "Test Key" button.
         *
         * Tests the value currently typed into the field, or — when the field is
         * empty — the key already stored for the provider. The check is a live,
         * zero-token request to the provider (see
         * INFYP_API_Key_Manager::test_api_key()).
         *
         * @since 1.0.6
         * @access private
         *
         * @param {Event} e - Click event from a .infyp-test-key button.
         *
         * @return {void}
         */
        function handleKeyTest(e) {
            e.preventDefault();

            const $button = $(e.target);
            const provider = $button.data('provider');
            const typed = ($('#infyp_' + provider + '_api_key').val() || '').trim();

            $button.prop('disabled', true).addClass('infyp-saving');

            $.post(infyp_vars.ajax_url, {
                action: 'infyp_test_api_key',
                nonce: infyp_vars.nonce,
                provider: provider,
                api_key: typed
            }).done(response => {
                if (response.success && response.data) {
                    const tests = {};
                    tests[provider] = response.data;
                    showKeyTestResults(tests);
                } else {
                    showApiKeysNotice((response.data && response.data.message) || infyp_vars.i18n.unknown_error, 'error');
                }
            }).fail(() => {
                showApiKeysNotice(infyp_vars.i18n.server_error, 'error');
            }).always(() => {
                $button.prop('disabled', false).removeClass('infyp-saving');
            });
        }

        /**
         * Handles bulk API keys save for all providers at once.
         *
         * Filters out empty API keys before submission and validates that at least
         * one key is provided. Shows loading state and appropriate success/error
         * messages. Refreshes API key status on successful save.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Event} e - Form submit event.
         *
         * @return {void}
         */
        function handleApiKeysSave(e) {
            e.preventDefault();
            
            const form = $(e.target);
            const submitButton = form.find('input[type="submit"]');
            
            // Show loading state
            submitButton.prop('disabled', true).val(infyp_vars.i18n.saving);
            $('.infyp-dynamic-notice').remove();
            
            // Get form data and filter out empty API keys
            const formData = form.serializeArray();
            const filteredData = formData.filter(field => {
                // Only include non-empty API key fields
                if (field.name.startsWith('infyp_api_keys[')) {
                    return field.value.trim() !== '';
                }
                return true;  // Include all other fields (nonces, etc.)
            });
            
            // Validate at least one API key is provided
            const hasApiKeys = filteredData.some(field => field.name.startsWith('infyp_api_keys['));
            if (!hasApiKeys) {
                // No keys typed, but the form may still carry a connection-method
                // change — save that instead of rejecting the submit.
                const $method = form.find('#infyp_connection_method');
                if ($method.length) {
                    saveConnectionMethod($method.val()).always(() => {
                        submitButton.prop('disabled', false).val(infyp_vars.i18n.save_all_api_keys || 'Save All API Keys');
                    });
                    return;
                }
                showApiKeysNotice(infyp_vars.i18n.missing_api_key || 'Please enter at least one API key to save.', 'error');
                submitButton.prop('disabled', false).val(infyp_vars.i18n.save_all_api_keys || 'Save All API Keys');
                return;
            }
            const processedData = $.param(filteredData);
            api.saveApiKeys(processedData).done(response => {
                const isSuccess = response.success;
                if (isSuccess) {
                    updateApiKeyStatus();
                    showKeyTestResults(response.data && response.data.tests, response.data && response.data.message);
                } else {
                    showApiKeysNotice((response.data && response.data.message) || infyp_vars.i18n.unknown_error, 'error');
                }
            }).fail(() => {
                showApiKeysNotice(infyp_vars.i18n.server_error, 'error');
            }).always(() => {
                submitButton.prop('disabled', false).val(infyp_vars.i18n.save_all_api_keys || 'Save All API Keys');
            });
        }

        /* =========================================================================
           PROMPT SETTINGS OPERATIONS
           ========================================================================= */

        /**
         * Handles AI prompt settings save.
         *
         * Saves language preference, AI model selection, and custom prompt additions.
         * Preserves selected values after successful save and shows temporary
         * success/error notifications with auto-hide functionality.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Event} e - Form submit event.
         *
         * @return {void}
         */
        function handlePromptSettingsSave(e) {
            e.preventDefault();
            
            const form = $(e.target);
            const submitButton = form.find('#infyp-save-prompt-settings');
            const statusSpan = $('#infyp-prompt-save-status');
            
            // Store the current form values before submission
            const selectedLanguage = $('#infyp_language_accordion').val();
            const selectedModel = $('#infyp_ai_model_accordion').val();
            const $promptAddon = $('#infyp_prompt_addon_accordion');
            const promptAddonDisabled = $promptAddon.prop('disabled');
            const promptAddon = $promptAddon.val();

            // Temporarily enable disabled textarea so its value is included in form.serialize()
            if (promptAddonDisabled) {
                $promptAddon.prop('disabled', false);
            }

            // Clear previous status and add infyp-save-status class for consistent styling
            statusSpan.removeClass('success error').addClass('infyp-save-status').hide();
            submitButton.prop('disabled', true).text(infyp_vars.i18n.saving || 'Saving...');

            const serializedData = form.serialize();

            // Re-disable if it was disabled
            if (promptAddonDisabled) {
                $promptAddon.prop('disabled', true);
            }

            api.savePromptSettings(serializedData).done(response => {
                const isSuccess = response.success;
                const message = response.data.message || (isSuccess ? infyp_vars.i18n.settings_saved : infyp_vars.i18n.unknown_error);
                
                // Use same status display as SEO weights
                statusSpan.removeClass('error').addClass(isSuccess ? 'success' : 'error');
                const icon = isSuccess ? 'yes-alt' : 'warning';
                statusSpan.html('<span class="dashicons dashicons-' + icon + '"></span> ' + ui.escapeHtml(message));
                statusSpan.show();
                
                if (isSuccess) {
                    // Preserve the selected values after successful save
                    $('#infyp_language_accordion').val(selectedLanguage);
                    $('#infyp_ai_model_accordion').val(selectedModel);
                    $('#infyp_prompt_addon_accordion').val(promptAddon);

                    // Sync per-image prompt setting so it takes effect without reload
                    window.infyp_vars.enable_per_image_prompt = $('#infyp_enable_per_image_prompt').is(':checked');
                    
                    // Auto-hide after 3 seconds if successful
                    setTimeout(() => {
                        statusSpan.fadeOut();
                    }, 3000);
                } else {
                    // Keep error visible for 5 seconds
                    setTimeout(() => {
                        statusSpan.fadeOut();
                    }, 5000);
                }
            }).fail(() => {
                statusSpan.removeClass('success').addClass('error');
                statusSpan.html('<span class="dashicons dashicons-warning"></span> ' + infyp_vars.i18n.server_error);
                statusSpan.show();
                setTimeout(() => {
                    statusSpan.fadeOut();
                }, 5000);
            }).always(() => {
                submitButton.prop('disabled', false).text(infyp_vars.i18n.save_settings || 'Save Settings');
            });
        }

        /* =========================================================================
           POST TYPE SETTINGS OPERATIONS
           ========================================================================= */

        /**
         * Handles post type settings save.
         *
         * Determines which post types have image optimization enabled.
         * Shows loading state during save and displays success/error notices
         * with auto-hide functionality.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Event} e - Form submit event.
         *
         * @return {void}
         */
        function handlePostTypeSettingsSave(e) {
            e.preventDefault();
            
            const form = $(e.target);
            const submitButton = form.find('#infyp-save-settings');
            const noticeContainer = $('#infyp-settings-notice');
            
            // Show loading state
            submitButton.prop('disabled', true).val(infyp_vars.i18n.saving);
            noticeContainer.hide().removeClass('notice-success notice-error');
            
            // Convert form array to object for easier processing
            const formArray = form.serializeArray();
            const formData = {};
            formArray.forEach(item => {
                formData[item.name] = item.value;
            });
            api.savePostTypeSettings(formData).done(response => {
                const isSuccess = response.success;
                const message = response.data.message || (isSuccess ? infyp_vars.i18n.settings_saved : infyp_vars.i18n.unknown_error);
                noticeContainer.addClass(isSuccess ? 'notice notice-success' : 'notice notice-error').html('<p>' + ui.escapeHtml(message) + '</p>').show();
                if (isSuccess) {
                    setTimeout(() => {
                        noticeContainer.fadeOut();
                    }, 3000);
                }
            }).fail(() => {
                noticeContainer.addClass('notice notice-error').html('<p>' + ui.escapeHtml(infyp_vars.i18n.server_error) + '</p>').show();
            }).always(() => {
                submitButton.prop('disabled', false).val(infyp_vars.i18n.save_changes || 'Save Changes');
            });
        }

        /* =========================================================================
           USER FEEDBACK
           ========================================================================= */

        /**
         * Displays notice for API key operations.
         *
         * Shows success or error messages with WordPress-style dismissible notice.
         * Automatically scrolls to the notice for visibility and provides dismiss
         * button functionality.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {string} message - Message to display.
         * @param {string} type - Notice type ('success' or 'error').
         *
         * @return {void}
         */
        /**
         * Handles switching between plugin API keys and WordPress AI Connectors.
         *
         * Persists the choice immediately via a dedicated AJAX action (so the
         * key-presence validation of the bulk save is not triggered) and toggles
         * the visual de-emphasis of the provider key cards.
         *
         * @since 1.0.6
         * @access private
         *
         * @param {Event} e - Radio change event.
         *
         * @return {void}
         */
        function handleConnectionMethodChange(e) {
            saveConnectionMethod($(e.target).val());
        }

        /**
         * Persists the connection method and refreshes the dependent UI.
         *
         * Shared by the dropdown change handler and the bulk save fallback.
         *
         * @since 1.0.6
         * @access private
         *
         * @param {string} method - Either 'api_keys' or 'wp_ai'.
         *
         * @return {jqXHR} The AJAX promise, for chaining.
         */
        function saveConnectionMethod(method) {
            return $.post(infyp_vars.ajax_url, {
                action: 'infyp_save_connection_method',
                nonce: infyp_vars.nonce,
                infyp_connection_method: method
            }).done(response => {
                if (response.success) {
                    $('.infyp-provider-card').toggleClass('infyp-deemphasized', method === 'wp_ai');
                    $('.infyp-wp-ai-keys-note').toggle(method === 'wp_ai');
                    renderWpAiProviders(response.data.connected_providers || []);
                    if (window.infyp_vars) {
                        window.infyp_vars.connection_method = method;
                    }
                    showApiKeysNotice(response.data.message, 'success');
                } else {
                    showApiKeysNotice((response.data && response.data.message) || infyp_vars.i18n.unknown_error, 'error');
                }
            }).fail(() => {
                showApiKeysNotice(infyp_vars.i18n.server_error, 'error');
            });
        }

        /**
         * Renders the connected WordPress AI provider badges without a reload.
         *
         * One success badge per connected provider; a plain "no connector" text
         * when none are connected. Names are inserted via text() so provider
         * names are never treated as HTML.
         *
         * @since 1.0.6
         * @access private
         *
         * @param {Array<{id: string, name: string, connected: boolean}>} providers - Connected providers.
         *
         * @return {void}
         */
        function renderWpAiProviders(providers) {
            const $container = $('#infyp-wp-ai-providers');
            if (!$container.length) {
                return;
            }

            $container.empty();

            if (!providers.length) {
                $container.text(infyp_vars.i18n.wp_ai_no_connector || 'No connector configured.');
                return;
            }

            providers.forEach(provider => {
                $('<span>', {
                    class: 'infyp-badge infyp-badge-success infyp-wp-ai-provider-badge',
                    text: provider.name
                }).appendTo($container);
            });
        }

        function showApiKeysNotice(message, type = 'success') {
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const $notice = $(`<div class="notice ${noticeClass} is-dismissible infyp-dynamic-notice">
            <p>${ui.escapeHtml(message)}</p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">${infyp_vars.i18n.dismiss_notice}</span>
            </button>
        </div>`);
            if ($('#infyp-api-keys-notice').length) {
                $('#infyp-api-keys-notice').html($notice).show();
            } else {
                $('#infyp-api-keys-wrap h1').after($notice);
            }
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut();
            });
            $('html,body').animate({
                scrollTop: $notice.offset().top - 100
            }, 300);
        }

        /**
         * Updates API key status after successful save.
         *
         * Reloads the page after a short delay to refresh the UI and display
         * the updated API key status indicators. The delay allows the user to
         * see the success message before reload.
         *
         * @since 1.0.0
         * @access private
         *
         * @return {void}
         */
        function updateApiKeyStatus() {
            // Delay reload to allow user to see success message
            setTimeout(() => {
                location.reload();
            }, 1500);
        }

        /* =========================================================================
           TOOLS OPERATIONS
           ========================================================================= */

        /**
         * Handles rebuild search index button click.
         *
         * Triggers a rebuild of the search index for improved search accuracy.
         * Shows loading state during the operation and displays success/error
         * feedback to the user.
         *
         * @since 1.1.0
         * @access private
         *
         * @param {Event} e - Click event from rebuild button.
         *
         * @return {void}
         */
        function handleRebuildSearchIndex(e) {
            e.preventDefault();

            const $button = $(e.target).closest('button');
            const $spinner = $('#infyp-rebuild-spinner');
            const $result = $('#infyp-rebuild-result');

            // Show loading state
            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $result.hide().removeClass('success error');

            // Make AJAX request to rebuild indexes
            $.ajax({
                url: infyp_vars.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_build_search_indexes',
                    nonce: infyp_vars.nonce,
                    force: 'true'  // Force rebuild even if recently built
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        let message = data.message || (infyp_vars.i18n.index_rebuilt || 'Search index rebuilt successfully.');

                        // Add stats if available
                        if (data.search && data.search.indexed) {
                            message += ' ' + data.search.indexed + ' ' + (infyp_vars.i18n.images_indexed || 'images indexed.');
                        }

                        $result.addClass('success').html(
                            '<span class="dashicons dashicons-yes-alt"></span> ' + ui.escapeHtml(message)
                        ).show();

                        // Reload page after 2 seconds to show updated timestamp
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        const errorMsg = response.data.message || infyp_vars.i18n.unknown_error || 'An error occurred.';
                        $result.addClass('error').html(
                            '<span class="dashicons dashicons-warning"></span> ' + ui.escapeHtml(errorMsg)
                        ).show();
                    }
                },
                error: function() {
                    $result.addClass('error').html(
                        '<span class="dashicons dashicons-warning"></span> ' + ui.escapeHtml(infyp_vars.i18n.server_error || 'Server error. Please try again.')
                    ).show();
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        }

        /* =========================================================================
           PUBLIC API
           ========================================================================= */

        /**
         * Public interface for the settings module.
         *
         * @return {Object} Public methods.
         * @property {Function} init - Initialize settings module.
         * @property {Function} handleApiKeysSave - Handle bulk API keys save.
         * @property {Function} handleIndividualKeySave - Handle individual API key save.
         * @property {Function} handlePromptSettingsSave - Handle prompt settings save.
         * @property {Function} handlePostTypeSettingsSave - Handle post type settings save.
         */
        return {
            init,
            handleApiKeysSave,
            handleIndividualKeySave,
            handlePromptSettingsSave,
            handlePostTypeSettingsSave
        };
    })(jQuery);

    /**
     * SEO Configuration Module - Interactive SEO weight configuration interface.
     *
     * This module provides an interactive UI for configuring SEO scoring weights
     * with draggable visual bars, real-time validation, and circular progress
     * indicators. It manages the weight distribution across different SEO factors
     * including alt text, filename, title, keywords, and uniqueness.
     *
     * ## Features
     *
     * - Draggable visual bars for intuitive weight adjustment
     * - Mouse and touch event support for mobile compatibility
     * - Real-time weight calculation and validation (must total 100%)
     * - Circular progress indicator with color-coded feedback
     * - AJAX save/reset functionality with WordPress nonces
     * - Sub-weight management for alt text and title components
     * - Form data serialization for nested array notation
     *
     * ## Usage
     *
     * ```javascript
     * import seoConfig from './seo-config.js';
     *
     * // Initialize on document ready
     * jQuery(document).ready(() => {
     *     seoConfig.init(jQuery);
     * });
     * ```
     *
     * @file Interactive SEO weight configuration interface.
     * @author InfyPress
     * @since 1.0.0
     * @module seo-config
     * @requires jQuery
     */

    /**
     * Escapes a string for safe insertion into HTML.
     *
     * @param {string} str - The string to escape.
     * @return {string} The escaped string.
     */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    const seoConfig = {
        /* =========================================================================
           STATE MANAGEMENT
           ========================================================================= */

        /**
         * Default weight values for SEO factors.
         *
         * @type {Object}
         * @property {number} alt - Alt text weight (35%).
         * @property {number} filename - Filename weight (15%).
         * @property {number} title - Title weight (25%).
         * @property {number} keywords - Keywords weight (15%).
         * @property {number} uniqueness - Uniqueness weight (10%).
         */
        weights: {
            alt: 35,
            filename: 15,
            title: 25,
            keywords: 15,
            uniqueness: 10
        },

        /**
         * Indicates whether a weight bar is currently being dragged.
         *
         * @type {boolean}
         */
        isDragging: false,

        /**
         * Reference to the currently dragged weight bar element.
         *
         * @type {jQuery|null}
         */
        currentBar: null,

        /* =========================================================================
           INITIALIZATION
           ========================================================================= */

        /**
         * Initializes the SEO configuration interface.
         *
         * Sets up event handlers, calculates initial totals, and prepares
         * the interactive weight adjustment interface.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {jQuery} $ - jQuery instance.
         *
         * @return {void}
         */
        init($) {
            this.$ = $;
            this.bindEvents();
            this.calculateTotal();
        },

        /* =========================================================================
           EVENT BINDING
           ========================================================================= */

        /**
         * Binds all event handlers for the interface.
         *
         * Sets up mouse and touch event handlers for draggable bars,
         * input change handlers for sub-weights, and form submission handlers.
         *
         * @since 1.0.0
         * @access private
         *
         * @return {void}
         */
        bindEvents() {
            const $ = this.$;
            
            // Visual bar drag functionality
            $('.infyp-visual-bar').on('mousedown', (e) => this.startDrag(e));
            $(document).on('mousemove', (e) => this.handleDrag(e));
            $(document).on('mouseup', () => this.endDrag());
            
            // Touch support for mobile
            $('.infyp-visual-bar').on('touchstart', (e) => this.startDrag(e.originalEvent.touches[0]));
            $(document).on('touchmove', (e) => {
                if (this.isDragging) {
                    e.preventDefault();
                    this.handleDrag(e.originalEvent.touches[0]);
                }
            });
            $(document).on('touchend', () => this.endDrag());
            
            // Sub-weight input changes
            $('.infyp-sub-input').on('input', (e) => this.handleSubWeightChange(e));

            // Form submission
            $('#infyp-seo-config-form').on('submit', (e) => this.handleSave(e));

            // Reset button
            $('#infyp-reset-config').on('click', () => this.handleReset());

            // Auto-link chars↔words coupling
            this.bindAutoLink();
        },

        /* =========================================================================
           AUTO-LINK CHARS↔WORDS COUPLING
           ========================================================================= */

        /**
         * Set up the auto-link interaction for each field cluster.
         *
         * When a field's "Auto-link characters and words" checkbox is ON, editing
         * any one of the four numeric inputs (chars-min/max, words-min/max) updates
         * the linked counterpart proportionally using the cluster's data-avg-word-length.
         *
         * Toggling the checkbox also visually marks the linked inputs with a
         * `.infyp-linked` class so users can see the coupled state at a glance.
         *
         * @since 1.0.3
         * @access private
         * @return {void}
         */
        bindAutoLink() {
            const $ = this.$;
            const self = this;

            // Initial state — apply linked styling for clusters with auto-link ON.
            $('.infyp-auto-link-toggle').each(function() {
                self.refreshAutoLinkState($(this));
            });

            // Toggle handler: visual state on check/uncheck.
            $(document).on('change', '.infyp-auto-link-toggle', function() {
                self.refreshAutoLinkState($(this));
            });

            // Threshold input handler: when auto-link is ON, propagate the change to
            // the linked counterpart in the same field cluster.
            $(document).on('input', '.infyp-threshold-input', function() {
                const $input = $(this);
                const field = $input.data('field');
                const bound = $input.data('bound');
                const $cluster = $input.closest('.infyp-params-content');
                const $toggle = $cluster.find('.infyp-auto-link-toggle');

                if (!$toggle.is(':checked')) return; // unlinked — independent edits

                const avg = parseFloat($cluster.data('avg-word-length')) || 6;
                const value = parseInt($input.val(), 10);
                if (!Number.isFinite(value) || value < 1) return;

                // Propagate based on which input changed.
                // Chars → derive words; words → derive chars.
                let counterpartBound = null;
                let counterpartValue = null;
                if (bound === 'min_chars') {
                    counterpartBound = 'min_words';
                    counterpartValue = Math.max(1, Math.round(value / avg));
                } else if (bound === 'max_chars') {
                    counterpartBound = 'max_words';
                    counterpartValue = Math.max(1, Math.round(value / avg));
                } else if (bound === 'min_words') {
                    counterpartBound = 'min_chars';
                    counterpartValue = Math.max(1, value * avg);
                } else if (bound === 'max_words') {
                    counterpartBound = 'max_chars';
                    counterpartValue = Math.max(1, value * avg);
                }

                if (counterpartBound !== null) {
                    const $counterpart = $cluster.find(
                        `.infyp-threshold-input[data-field="${field}"][data-bound="${counterpartBound}"]`
                    );
                    if ($counterpart.length) {
                        $counterpart.val(counterpartValue);
                    }
                }
            });
        },

        /**
         * Update the visual state of inputs in a field cluster based on
         * its auto-link checkbox.
         *
         * @since 1.0.3
         * @access private
         * @param {jQuery} $toggle - The checkbox element.
         * @return {void}
         */
        refreshAutoLinkState($toggle) {
            const $cluster = $toggle.closest('.infyp-params-content');
            if ($toggle.is(':checked')) {
                $cluster.addClass('infyp-linked').removeClass('infyp-unlinked');
            } else {
                $cluster.addClass('infyp-unlinked').removeClass('infyp-linked');
            }
        },

        /* =========================================================================
           DRAG OPERATIONS
           ========================================================================= */

        /**
         * Starts dragging a weight bar.
         *
         * Initiates drag operation when user clicks or touches a weight bar.
         * Prevents text selection during drag and sets up drag state.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Event} e - Mouse or touch event.
         *
         * @return {void}
         */
        startDrag(e) {
            const $ = this.$;
            const $bar = $(e.target).closest('.infyp-visual-bar');
            
            if (!$bar.length) return;
            
            this.isDragging = true;
            this.currentBar = $bar;
            $bar.addClass('dragging');
            
            // Prevent text selection while dragging
            $('body').css('user-select', 'none');
        },

        /**
         * Handles drag movement to update weight values.
         *
         * Calculates new weight percentage based on mouse/touch position,
         * ensures total doesn't exceed 100%, and updates visual bar, display,
         * and hidden input values in real-time.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Event} e - Mouse or touch move event.
         *
         * @return {void}
         */
        handleDrag(e) {
            if (!this.isDragging || !this.currentBar) return;
            
            const $ = this.$;
            const bar = this.currentBar[0];
            const rect = bar.getBoundingClientRect();
            const x = e.clientX || e.pageX;
            const percentage = Math.max(0, Math.min(100, ((x - rect.left) / rect.width) * 100));
            let rounded = Math.round(percentage);
            
            // Prevent total from exceeding 100%
            const weightKey = this.currentBar.data('weight');
            let otherWeightsTotal = 0;
            
            // Calculate total of other weights
            $('[id$="-weight-input"]').each(function() {
                const key = $(this).attr('id').replace('-weight-input', '');
                if (key !== weightKey) {
                    otherWeightsTotal += parseInt($(this).val()) || 0;
                }
            });
            
            // Cap the new value if it would exceed 100% total
            if (otherWeightsTotal + rounded > 100) {
                rounded = Math.max(0, 100 - otherWeightsTotal);
            }
            
            // Update visual bar
            const $fill = this.currentBar.find('.infyp-bar-fill');
            $fill.css('width', rounded + '%');
            $fill.attr('data-value', rounded);
            $fill.find('.infyp-bar-value').text(rounded + '%');
            
            // Update display and hidden input
            $(`#${weightKey}-weight-display`).text(rounded + '%');
            $(`#${weightKey}-weight-input`).val(rounded);
            
            // Update sub-weight max values if applicable
            if (weightKey === 'alt' || weightKey === 'title') {
                this.currentBar.closest('.infyp-weight-card').find('.infyp-sub-input').attr('max', rounded);
            }
            
            // Calculate total
            this.calculateTotal();
        },

        /**
         * Ends the drag operation.
         *
         * Cleans up drag state, removes dragging class, and restores
         * normal text selection behavior.
         *
         * @since 1.0.0
         * @access private
         *
         * @return {void}
         */
        endDrag() {
            if (!this.isDragging) return;
            
            const $ = this.$;
            this.isDragging = false;
            
            if (this.currentBar) {
                this.currentBar.removeClass('dragging');
            }
            
            this.currentBar = null;
            $('body').css('user-select', '');
        },

        /* =========================================================================
           SUB-WEIGHT MANAGEMENT
           ========================================================================= */

        /**
         * Handles sub-weight input changes for alt text and title.
         *
         * Validates that sub-weights don't exceed their parent weight total.
         * Automatically adjusts the current input if the sum exceeds the maximum.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Event} e - Input change event.
         *
         * @return {void}
         */
        handleSubWeightChange(e) {
            const $ = this.$;
            const $input = $(e.target);
            const $card = $input.closest('.infyp-weight-card');
            const $subInputs = $card.find('.infyp-sub-input');
            
            let subTotal = 0;
            $subInputs.each(function() {
                subTotal += parseInt($(this).val()) || 0;
            });
            
            const weightKey = $card.data('weight-key');
            const maxWeight = parseInt($(`#${weightKey}-weight-input`).val()) || 0;
            
            // Validate sub-weights don't exceed total
            if (subTotal > maxWeight) {
                const excess = subTotal - maxWeight;
                const currentVal = parseInt($input.val()) || 0;
                $input.val(Math.max(0, currentVal - excess));
            }
        },

        /* =========================================================================
           WEIGHT CALCULATION
           ========================================================================= */

        /**
         * Calculates and updates the total weight percentage.
         *
         * Sums all weight inputs and updates the circular progress indicator
         * with color-coded feedback (green for 100%, yellow for other values).
         * Shows helpful notices when total is not 100%.
         *
         * @since 1.0.0
         * @access private
         *
         * @return {number} Total weight percentage.
         */
        calculateTotal() {
            const $ = this.$;
            let total = 0;
            
            // Sum all weight inputs
            $('[id$="-weight-input"]').each(function() {
                total += parseInt($(this).val()) || 0;
            });
            
            // Cap at 100 max for display
            const displayTotal = Math.min(100, total);
            
            // Update circular progress
            const $progressCircle = $('#infyp-progress-circle');
            const $totalValue = $('#infyp-total-value');
            const $totalNotice = $('#infyp-total-notice');
            const circumference = 2 * Math.PI * 36; // radius = 36 (smaller circle)
            const offset = circumference - (displayTotal / 100) * circumference;
            
            // Use jQuery prop() for SVG attributes to avoid jQuery Migrate warnings
            $progressCircle[0].setAttribute('stroke-dashoffset', offset);
            $totalValue.text(total + '%');
            
            // Update color and notice based on total
            // For SEO configuration, only 100% is green, everything else is warning
            if (total === 100) {
                $totalValue.removeClass('warning error').addClass('optimized');
                $progressCircle[0].setAttribute('stroke', '#46b450'); // var(--infyp-success) - green
                // Hide notice
                if ($totalNotice.length) {
                    $totalNotice.hide();
                }
            } else {
                $totalValue.removeClass('optimized error').addClass('warning');
                $progressCircle[0].setAttribute('stroke', '#ffc107'); // var(--infyp-warning) - yellow
                
                // Show notice with appropriate message
                if ($totalNotice.length) {
                    let message = '';
                    if (total > 100) {
                        const excess = total - 100;
                        message = (infyp_vars.i18n.reduce_weights_message || 'Reduce weights by %s% to reach 100%').replace('%s', excess);
                    } else {
                        const needed = 100 - total;
                        message = (infyp_vars.i18n.add_weights_message || 'Add %s% more to reach 100%').replace('%s', needed);
                    }
                    $totalNotice.html('<span class="dashicons dashicons-warning"></span> ' + message).show();
                }
            }
            
            return total;
        },

        /* =========================================================================
           FORM OPERATIONS
           ========================================================================= */

        /**
         * Handles form save via AJAX.
         *
         * Validates that total equals 100% before saving. Sends configuration
         * to server with WordPress nonces for security. Shows loading state
         * and success/error feedback.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Event} e - Form submit event.
         *
         * @return {void}
         */
        handleSave(e) {
            e.preventDefault();
            
            const $ = this.$;
            const $form = $(e.target);
            const $status = $('#infyp-save-status');
            const $button = $('#infyp-save-config');
            
            // Validate total equals 100
            const total = this.calculateTotal();
            if (total !== 100) {
                $status.removeClass('success').addClass('error');
                const errorMsg = infyp_vars.i18n.total_weight_error.replace('%d', total);
                $status.html('<span class="dashicons dashicons-warning"></span> ' + errorMsg);
                $status.show();
                setTimeout(() => $status.fadeOut(), 5000);
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true);
            $status.removeClass('error success');
            $status.html('<span class="spinner is-active" style="float:none;margin:0;"></span> ' + infyp_vars.i18n.saving_configuration);
            $status.show();
            
            // Send AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'infyp_save_seo_config',
                    nonce: $form.find('[name="infyp_seo_config_nonce"]').val(),
                    ...this.serializeFormData($form)
                },
                success: (response) => {
                    if (response.success) {
                        $status.removeClass('error').addClass('success');
                        $status.html('<span class="dashicons dashicons-yes-alt"></span> ' + infyp_vars.i18n.configuration_saved);
                    } else {
                        $status.removeClass('success').addClass('error');
                        $status.html('<span class="dashicons dashicons-warning"></span> ' + escapeHtml(response.data?.message || infyp_vars.i18n.failed_save_configuration));
                    }
                },
                error: () => {
                    $status.removeClass('success').addClass('error');
                    $status.html('<span class="dashicons dashicons-warning"></span> ' + infyp_vars.i18n.error_saving_configuration);
                },
                complete: () => {
                    $button.prop('disabled', false);
                    setTimeout(() => $status.fadeOut(), 5000);
                }
            });
        },

        /**
         * Serializes form data for AJAX submission.
         *
         * Converts jQuery serialized array into nested object structure,
         * handling both simple fields and nested array notation (e.g.,
         * weights[alt], params[alt_text][min_chars]).
         *
         * @since 1.0.0
         * @access private
         *
         * @param {jQuery} $form - Form element to serialize.
         *
         * @return {Object} Serialized form data as nested object.
         */
        serializeFormData($form) {
            const data = {};
            const formArray = $form.serializeArray();
            
            formArray.forEach(item => {
                // Handle nested array notation
                const matches = item.name.match(/^(\w+)\[(\w+)\](?:\[(\w+)\])?$/);
                if (matches) {
                    const [, key1, key2, key3] = matches;
                    if (!data[key1]) data[key1] = {};
                    if (key3) {
                        if (!data[key1][key2]) data[key1][key2] = {};
                        data[key1][key2][key3] = item.value;
                    } else {
                        data[key1][key2] = item.value;
                    }
                } else {
                    data[item.name] = item.value;
                }
            });
            
            return data;
        },

        /**
         * Handles reset to default configuration.
         *
         * Prompts user for confirmation, then resets all weights, sub-weights,
         * and parameters to their default values. Updates visual bars and
         * shows success feedback.
         *
         * @since 1.0.0
         * @access private
         *
         * @return {void}
         */
        handleReset() {
            const $ = this.$;
            
            if (!confirm(infyp_vars.i18n.confirm_reset_weights)) {
                return;
            }
            
            const defaults = {
                alt: 35,
                filename: 15,
                title: 25,
                keywords: 15,
                uniqueness: 10
            };
            
            const subDefaults = {
                alt_presence: 20,
                alt_quality: 15,
                title_presence: 15,
                title_quality: 10
            };
            
            // Reset visual bars and inputs
            Object.entries(defaults).forEach(([key, value]) => {
                const $bar = $(`.infyp-visual-bar[data-weight="${key}"]`);
                const $fill = $bar.find('.infyp-bar-fill');
                
                $fill.css('width', value + '%');
                $fill.attr('data-value', value);
                $fill.find('.infyp-bar-value').text(value + '%');
                
                $(`#${key}-weight-display`).text(value + '%');
                $(`#${key}-weight-input`).val(value);
            });
            
            // Reset sub-weights
            Object.entries(subDefaults).forEach(([key, value]) => {
                $(`input[name="weights[${key}]"]`).val(value);
            });
            
            // Reset parameters to defaults
            const paramDefaults = {
                'params[alt_text][min_chars]': 50,
                'params[alt_text][max_chars]': 125,
                'params[alt_text][min_words]': 10,
                'params[alt_text][max_words]': 16,
                'params[filename][min_chars]': 40,
                'params[filename][max_chars]': 60,
                'params[filename][min_words]': 3,
                'params[filename][max_words]': 6,
                'params[title][min_chars]': 40,
                'params[title][max_chars]': 60,
                'params[title][min_words]': 6,
                'params[title][max_words]': 10
            };
            
            Object.entries(paramDefaults).forEach(([name, value]) => {
                $(`input[name="${name}"]`).val(value);
            });

            // Reset auto-link toggles to ON (default state)
            $('.infyp-auto-link-toggle').each(function() {
                const $toggle = $(this);
                $toggle.prop('checked', true);
                $toggle.trigger('change');
            });

            // Update total
            this.calculateTotal();
            
            // Show success message
            const $status = $('#infyp-save-status');
            $status.removeClass('error').addClass('success');
            $status.html('<span class="dashicons dashicons-backup"></span> ' + infyp_vars.i18n.reset_to_default);
            $status.show();
            setTimeout(() => $status.fadeOut(), 3000);
        }
    };

    /**
     * Main Admin Script - Entry point for all plugin JavaScript.
     *
     * This is the primary entry point for the AI Image SEO plugin's JavaScript.
     * It initializes all modules and coordinates between different components
     * to ensure proper functionality in the WordPress admin area.
     *
     * ## Modules Initialized
     *
     * - `imageTable` - Image list table with inline editing and AI optimization
     * - `settings` - Settings forms and API key management
     * - `seoConfig` - SEO scoring configuration interface (conditional)
     *
     * ## Load Order
     *
     * 1. DOM ready event fires
     * 2. Image table module initializes (table interactions, modals, AJAX)
     * 3. Settings module initializes (form handlers, validation)
     * 4. SEO config initializes if on the configuration page
     * 5. WordPress postbox toggles enabled for collapsible sections
     *
     * @file Main entry point for plugin JavaScript.
     * @author AI Image SEO
     * @since 1.0.0
     * @requires jQuery
     * @requires ./modules/api.js
     * @requires ./modules/ui.js
     * @requires ./modules/image-table.js
     * @requires ./modules/settings.js
     * @requires ./modules/seo-config.js
     */

    /**
     * Initialize plugin functionality when DOM is ready.
     *
     * Sets up all module initializations and WordPress admin integrations.
     * Each module is initialized in sequence to ensure proper dependency order.
     *
     * @since 1.0.0
     * @listens jQuery#ready
     */
    jQuery(document).ready(function($) {

        // Initialize media library enhancements and image table
        imageTable.init($);

        // Initialize settings forms and handlers
        settings.init($);

        // Initialize SEO configuration interface if on that page
        if ($('#infyp-seo-config-form').length) {
            seoConfig.init($);
        }

        // Enable WordPress postbox toggles (for collapsible meta boxes)
        // This allows users to expand/collapse sections on admin pages
        if (typeof postboxes !== 'undefined' && typeof pagenow !== 'undefined') {
            postboxes.add_postbox_toggles(pagenow);
        }
    });

})();
