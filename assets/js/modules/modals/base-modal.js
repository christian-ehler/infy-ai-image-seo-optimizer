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
    'use strict';

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

export default baseModal;
