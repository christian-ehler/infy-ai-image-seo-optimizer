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

/* =========================================================================
   MODULE IMPORTS
   ========================================================================= */

// Core modules
import api from './api.js';
import scoringEngineModule from './core/scoring-engine.js';
import eventManager from './core/event-manager.js';
// UI modules
import loadingStates from './ui/loading-states.js';
import notifications from './ui/notifications.js';
import helpers from './ui/helpers.js';
// Feature modules
import aiOptimizer from './features/ai-optimizer.js';
import usageChecker from './features/usage-checker.js';
import characterCounter from './table/character-counter.js';
// Table operation modules
import fieldEditor from './table/field-editor.js';
import pagination from './table/pagination.js';
import filtering from './table/filtering.js';
import pageFilter from './table/page-filter.js';
import seoSort from './table/seo-sort.js';
import dateSort from './table/date-sort.js';
import liveSearch from './table/live-search.js';
import ajaxTableLoader from './table/ajax-table-loader.js';
// Modal modules
import altTextModal from './modals/alt-text-modal.js';
import filenameModal from './modals/filename-modal.js';
import titleModal from './modals/title-modal.js';
import keywordsModal from './modals/keywords-modal.js';
import seoScoreModal from './modals/seo-score-modal.js';
import imagePreviewModal from './modals/image-preview-modal.js';

const imageTable = (function($) {
    'use strict';

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
    const updateSEOScore = scoringEngineModule.updateSEOScore;

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

// Export for ES6 modules
export default imageTable;