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
import baseModal from './base-modal.js';
import api from '../api.js';

const customPromptModal = (function($) {
    'use strict';

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

export default customPromptModal;
