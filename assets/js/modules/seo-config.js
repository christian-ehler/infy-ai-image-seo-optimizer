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

export default seoConfig;