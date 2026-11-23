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
import api from './api.js';
import ui from './ui.js';

const settings = (function($) {
    'use strict';

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
        const iconClass = type === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning';
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

export default settings;