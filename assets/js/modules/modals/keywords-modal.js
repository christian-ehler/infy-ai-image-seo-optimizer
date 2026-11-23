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
import api from '../api.js';
import scoringEngine from '../core/scoring-engine.js';
import baseModal from './base-modal.js';

const keywordsModal = (function($) {
    'use strict';

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
                const $tokensContainer = $('#infyp-keywords-tokens');

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
                bindKeywordsModalEvents(imageId);
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
        bindKeywordsModalEvents(imageId);
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
        const i18n = window.infyp_vars.i18n;

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

export default keywordsModal;
